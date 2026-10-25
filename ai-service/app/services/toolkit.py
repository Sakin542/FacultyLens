"""AI toolkit: handwriting OCR, lecture transcription, Bangla <-> English translation and text to speech.

When the administrator selected the hosted provider, OCR and transcription go to hosted multimodal models through
LangChain (llm_chain.run_media; Gemini 3.8 Flash first by default) and fall back to the local models if every hosted
model fails. Local Hugging Face models load lazily on first use (memory is only spent on tools in use):
- OCR           : microsoft/trocr-base-handwritten. TrOCR reads one text line at a time, so pages are split into
                  lines with a horizontal ink-projection pass first. Output is a draft transcription for a person to
                  check; it is never treated as a student's final answer automatically.
- Transcription : Whisper large-v3-turbo through faster-whisper (8-bit, silence skipped), or the transformers ASR
                  pipeline (openai/whisper-large-v3-turbo, 30 s chunks) when faster-whisper is unavailable.
- Translation   : facebook/nllb-200-distilled-600M, or the hosted generation model when the administrator selected
                  the hosted provider (falls back to NLLB if the hosted call fails).
- Speech        : Kokoro-82M for English; Microsoft's neural Bangla voice (edge-tts, online) for Bangla; MMS-TTS is
                  the local fallback for both.

Every public method returns plain dicts and raises ToolkitError with a safe, user-facing message on bad input.
"""

import base64
import difflib
import hashlib
import io
import json
import os
import time
import logging
import re
import shutil
import subprocess
import threading
from concurrent.futures import ThreadPoolExecutor
from typing import Any, Dict, List, Optional, Tuple

from app.config import get_settings
from app.services import llm_chain, model_memory, provider_context

logger = logging.getLogger("facultylens.ai")


class ToolkitError(Exception):
    """Input or environment problem with a message that is safe to show to the user."""


def _module_available(name: str) -> bool:
    import importlib.util

    return importlib.util.find_spec(name) is not None


def _hosted_media(models: List[str]) -> Tuple[bool, Optional[str]]:
    """(use hosted models?, why not) for an image/audio tool, following the administrator's provider choice."""
    if provider_context.requested_provider() != "hosted" or not models:
        return False, None
    if not llm_chain.connection_configured():
        return False, "hosted provider is not configured in the AI service"
    return True, None


def _strip_fences(text: str) -> str:
    text = text.strip()
    match = re.match(r"^```[a-zA-Z]*\n(.*?)\n?```$", text, re.S)
    return (match.group(1) if match else text).strip()


def _chain_label(hosted: List[str], local: str) -> str:
    return " → ".join([*hosted, local])


def _whisper_mb(name: str) -> int:
    """Rough RAM for a Whisper checkpoint on CPU (float32), for model_memory's budget."""
    for size, mb in (("tiny", 250), ("base", 450), ("small", 1100), ("medium", 3200), ("large", 3400)):
        if size in name:
            return mb
    return 3400


# ============================================================================================== OCR

OCR_PROMPT = (
    "Transcribe the text in this image (handwritten or printed) exactly as written. Keep the original line breaks, "
    "question numbers and marks, and the original language (English or Bangla). Do not correct spelling or grammar, "
    "and do not add explanations, headings or formatting. Write [illegible] for any word you cannot read. If the page "
    "has no text, reply with exactly: [blank page]"
)


class HandwritingOcr:
    _lock = threading.Lock()
    _processor: Any = None
    _model: Any = None

    @property
    def model_name(self) -> str:
        return get_settings().ocr_model

    @property
    def loaded(self) -> bool:
        return self._model is not None

    def dependencies(self) -> Dict[str, bool]:
        return {"pillow": _module_available("PIL"), "pymupdf": _module_available("pymupdf")}

    def _load(self) -> None:
        with self._lock:
            if HandwritingOcr._model is not None:
                return
            from transformers import TrOCRProcessor, VisionEncoderDecoderModel

            token = get_settings().hf_token or None
            HandwritingOcr._processor = TrOCRProcessor.from_pretrained(self.model_name, token=token)
            HandwritingOcr._model = VisionEncoderDecoderModel.from_pretrained(self.model_name, token=token)
            HandwritingOcr._model.eval()
            logger.info(f"OCR model '{self.model_name}' loaded.")

    def _pages(self, data: bytes, filename: str) -> List[Any]:
        if not self.dependencies()["pillow"]:
            raise ToolkitError("Image support (Pillow) is not installed in the AI service.")
        from PIL import Image

        if filename.lower().endswith(".pdf") or data[:4] == b"%PDF":
            if not self.dependencies()["pymupdf"]:
                raise ToolkitError("PDF support (PyMuPDF) is not installed in the AI service.")
            import pymupdf as fitz

            doc = fitz.open(stream=data, filetype="pdf")
            limit = get_settings().ocr_max_pages
            if doc.page_count > limit:
                raise ToolkitError(f"The PDF has {doc.page_count} pages; the limit is {limit}.")
            pages = []
            for page in doc:
                pix = page.get_pixmap(dpi=150)
                pages.append(Image.frombytes("RGB", (pix.width, pix.height), pix.samples))
            return pages
        try:
            image = Image.open(io.BytesIO(data))
            image.load()
        except Exception:
            raise ToolkitError("The file is not a readable image or PDF.")
        return [image.convert("RGB")]

    @staticmethod
    def _lines(image: Any) -> List[Any]:
        """Split a page into text-line crops using row ink density (no extra models)."""
        import numpy as np

        gray = np.asarray(image.convert("L"), dtype=np.float32)
        if gray.size == 0:
            return []
        threshold = min(gray.mean() - 25, 200)
        ink = (gray < threshold).sum(axis=1)
        width = gray.shape[1]
        active = ink > max(2, width * 0.004)

        bands, start = [], None
        for y, on in enumerate(active):
            if on and start is None:
                start = y
            elif not on and start is not None:
                bands.append((start, y))
                start = None
        if start is not None:
            bands.append((start, len(active)))

        # Merge bands separated by tiny gaps (ascenders/descenders) and drop specks
        merged: List[List[int]] = []
        for top, bottom in bands:
            if merged and top - merged[-1][1] < 6:
                merged[-1][1] = bottom
            else:
                merged.append([top, bottom])
        crops = []
        height = gray.shape[0]
        for top, bottom in merged:
            if bottom - top < 10:
                continue
            pad = max(4, (bottom - top) // 4)
            cols = np.where((gray[top:bottom] < threshold).sum(axis=0) > 0)[0]
            left, right = (int(cols.min()), int(cols.max())) if cols.size else (0, width)
            crops.append(image.crop((max(0, left - pad), max(0, top - pad), min(width, right + pad), min(height, bottom + pad))))
        return crops or [image]

    def display_model(self) -> str:
        use, _ = _hosted_media(llm_chain.vision_models())
        return _chain_label(llm_chain.vision_models(), self.model_name) if use else self.model_name

    @staticmethod
    def _image_part(page: Any) -> Dict[str, Any]:
        image = page.copy()
        image.thumbnail((2000, 2000))  # enough for handwriting; keeps each request well under provider limits
        buf = io.BytesIO()
        image.save(buf, "JPEG", quality=85)
        return {"type": "image_url", "image_url": {"url": "data:image/jpeg;base64," + base64.b64encode(buf.getvalue()).decode()}}

    def _run_hosted(self, pages: List[Any]) -> Tuple[List[Dict[str, Any]], List[str]]:
        models = llm_chain.vision_models()
        out_pages: List[Dict[str, Any]] = []
        used: List[str] = []
        for number, page in enumerate(pages, start=1):
            model, text = llm_chain.run_media(models, [{"type": "text", "text": OCR_PROMPT}, self._image_part(page)], 4096)
            text = _strip_fences(text)
            if text.lower() == "[blank page]":
                text = ""
            lines = [line.rstrip() for line in text.splitlines() if line.strip()]
            out_pages.append({"page": number, "lines": lines, "text": "\n".join(lines)})
            if model not in used:
                used.append(model)
        return out_pages, used

    def run(self, data: bytes, filename: str) -> Dict[str, Any]:
        pages = self._pages(data, filename)
        use_hosted, fallback = _hosted_media(llm_chain.vision_models())
        if use_hosted:
            try:
                out_pages, used = self._run_hosted(pages)
                return self._result(out_pages, ", ".join(f"hosted:{m}" for m in used), "hosted", None)
            except llm_chain.HostedCallError as e:
                fallback = e.reason
                logger.warning(f"Hosted OCR failed, using {self.model_name}: {e.reason}")
        return self._result(self._run_local(pages), self.model_name, "local", fallback)

    @staticmethod
    def _result(out_pages: List[Dict[str, Any]], model: str, provider: str, fallback: Optional[str]) -> Dict[str, Any]:
        return {
            "text": "\n\n".join(p["text"] for p in out_pages).strip(),
            "pages": out_pages,
            "line_count": sum(len(p["lines"]) for p in out_pages),
            "model": model,
            "provider": provider,
            "fallback_reason": fallback,
            "note": "Draft transcription of handwriting. Check it against the original before using it for grading.",
        }

    @staticmethod
    def _unload() -> None:
        HandwritingOcr._processor = HandwritingOcr._model = None

    def _run_local(self, pages: List[Any]) -> List[Dict[str, Any]]:
        with model_memory.use("ocr", self._unload, estimate_mb=1400):
            return self._read_local(pages)

    def _read_local(self, pages: List[Any]) -> List[Dict[str, Any]]:
        self._load()
        import torch

        out_pages = []
        for number, page in enumerate(pages, start=1):
            texts = []
            for crop in self._lines(page)[:80]:
                pixel_values = self._processor(images=crop, return_tensors="pt").pixel_values
                with torch.no_grad():
                    ids = self._model.generate(pixel_values, max_new_tokens=96)
                text = self._processor.batch_decode(ids, skip_special_tokens=True)[0].strip()
                if text:
                    texts.append(text)
            out_pages.append({"page": number, "lines": texts, "text": "\n".join(texts)})
        return out_pages


# ============================================================================================== transcription

ASR_PROMPT = (
    "Transcribe this lecture recording{language}. Write exactly what is said, in the language spoken, without "
    "summarising, translating or adding notes. Start a new line every 20-40 seconds of speech, at a sentence boundary, "
    "and begin every line with its start time in this recording as [mm:ss]. If nobody speaks, reply with exactly: [no speech]"
)
PROOFREAD_PROMPT = (
    "These lines are an automatic transcript of a university lecture{subject}. Fix ONLY speech-recognition mistakes: "
    "words that sound alike but are wrong in context (for example \"cue\" for \"queue\", \"there\" for \"their\"), "
    "misheard technical terms, names and numbers. Do not rephrase, shorten, summarise, translate, add or remove "
    "content, and do not merge or split lines. Reply with JSON only: {{\"lines\": [...]}} containing exactly {count} "
    "strings, in the same order.\n\n{lines}"
)
PROOFREAD_BATCH = 40
PROOFREAD_MIN_SIMILARITY = 0.6  # a "corrected" line further from the original than this is a rewrite: keep the original
TIMESTAMPED_LINE = re.compile(r"^\s*\[?(\d{1,2}):(\d{2})(?::(\d{2}))?\]?\s*[-–:]?\s*(.*)$")


class LectureTranscriber:
    _lock = threading.Lock()
    _pipe: Any = None
    _fast: Any = None
    _fast_failed: bool = False

    @property
    def fast_enabled(self) -> bool:
        return (get_settings().asr_engine.strip().lower() == "faster-whisper" and not LectureTranscriber._fast_failed
                and _module_available("faster_whisper"))

    @property
    def model_name(self) -> str:
        s = get_settings()
        if self.fast_enabled:
            return f"faster-whisper {s.asr_fast_model} ({s.asr_fast_compute_type})"
        return s.asr_model

    @property
    def loaded(self) -> bool:
        return self._pipe is not None or self._fast is not None

    @staticmethod
    def _unload_fast() -> None:
        LectureTranscriber._fast = None

    def _load_fast(self) -> bool:
        with self._lock:
            if LectureTranscriber._fast is not None:
                return True
            s = get_settings()
            try:
                from faster_whisper import WhisperModel

                LectureTranscriber._fast = WhisperModel(
                    s.asr_fast_model, device="cpu", compute_type=s.asr_fast_compute_type,
                    cpu_threads=s.asr_fast_threads or max(1, (os.cpu_count() or 4) // 2),
                    download_root=os.path.join(s.hf_home or "/app/cache/huggingface", "faster-whisper"))
                logger.info(f"ASR model faster-whisper '{s.asr_fast_model}' loaded.")
                return True
            except Exception as e:  # download or CTranslate2 problems: the transformers pipeline still works
                LectureTranscriber._fast_failed = True
                logger.warning(f"faster-whisper unavailable ({type(e).__name__}); using {s.asr_model}")
                return False

    def _transcribe_fast(self, audio: Any, language: Optional[str], terms: str) -> Optional[Tuple[str, List[Dict[str, Any]]]]:
        """(text, segments) from faster-whisper, or None when it cannot run (the caller uses the pipeline)."""
        with model_memory.use("transcription-fast", self._unload_fast, estimate_mb=1200):
            if not self._load_fast():
                return None
            parts, _ = LectureTranscriber._fast.transcribe(
                audio, language=language if language in ("en", "bn") else None, task="transcribe",
                beam_size=max(1, get_settings().asr_fast_beam_size), vad_filter=True,
                # Whisper's "initial prompt": spelling hint that biases it toward the course vocabulary (queue, not cue)
                initial_prompt=f"Lecture vocabulary: {terms}." if terms else None)
            segments = [{"start": round(float(p.start), 1), "end": round(float(p.end), 1), "text": p.text.strip()} for p in parts if p.text.strip()]
        return " ".join(s["text"] for s in segments), segments

    def dependencies(self) -> Dict[str, bool]:
        return {"ffmpeg": shutil.which("ffmpeg") is not None}

    @staticmethod
    def _unload() -> None:
        LectureTranscriber._pipe = None

    def _load(self) -> None:
        with self._lock:
            if LectureTranscriber._pipe is not None:
                return
            from transformers import pipeline

            LectureTranscriber._pipe = pipeline("automatic-speech-recognition", model=self.model_name, device=-1, chunk_length_s=30,
                                                token=get_settings().hf_token or None)
            logger.info(f"ASR model '{self.model_name}' loaded.")

    def _transcribe_pipeline(self, audio: Any, language: Optional[str], terms: str) -> Tuple[str, List[Dict[str, Any]]]:
        kwargs: Dict[str, Any] = {"task": "transcribe"}
        if language in ("en", "bn"):
            kwargs["language"] = {"en": "english", "bn": "bengali"}[language]
        name = get_settings().asr_model
        with model_memory.use("transcription", self._unload, estimate_mb=_whisper_mb(name)):
            self._load()
            if terms and getattr(self._pipe, "tokenizer", None) is not None and hasattr(self._pipe.tokenizer, "get_prompt_ids"):
                # Whisper's "initial prompt": spelling hint that biases it toward the course vocabulary (queue, not cue)
                kwargs["prompt_ids"] = self._pipe.tokenizer.get_prompt_ids(f"Lecture vocabulary: {terms}.", return_tensors="pt")
            result = self._pipe({"raw": audio, "sampling_rate": 16000}, return_timestamps=True, generate_kwargs=kwargs)
        segments = [
            {"start": round(float(c["timestamp"][0] or 0), 1), "end": round(float(c["timestamp"][1] or c["timestamp"][0] or 0), 1), "text": str(c["text"]).strip()}
            for c in (result.get("chunks") or []) if str(c.get("text", "")).strip()
        ]
        return str(result.get("text", "")).strip(), segments

    @staticmethod
    def split_points(audio: Any, rate: int, target_s: int) -> List[int]:
        """Sample offsets that cut a recording into pieces of about target_s seconds, each cut at the quietest half
        second within +-25% of the target, so no word is split and each piece's start time is exact."""
        import numpy as np

        size, window = max(10, target_s) * rate, rate // 2
        cuts, pos = [0], 0
        while len(audio) - pos > size * 1.25:
            lo, hi = pos + int(size * 0.75), min(len(audio) - window, pos + int(size * 1.25))
            frames = [(float(np.sqrt(np.mean(np.square(audio[i:i + window], dtype=np.float64)))), i) for i in range(lo, hi, window // 2)]
            pos = min(frames)[1] + window // 2 if frames else pos + size
            cuts.append(pos)
        return cuts + [len(audio)]

    def display_model(self) -> str:
        use, _ = _hosted_media(llm_chain.audio_models())
        return _chain_label(llm_chain.audio_models(), self.model_name) if use else self.model_name

    @staticmethod
    def _decode(data: bytes) -> Any:
        from transformers.pipelines.audio_utils import ffmpeg_read

        try:
            return ffmpeg_read(data, 16000)
        except Exception:
            raise ToolkitError("The file is not a readable audio or video recording.")

    @staticmethod
    def _encode_mp3(samples: Any) -> bytes:
        """16 kHz float samples -> 48 kbit/s mono MP3 (a 10-minute piece is about 3.6 MB)."""
        proc = subprocess.run(  # noqa: S603 - fixed ffmpeg arguments, audio on stdin, no shell
            ["ffmpeg", "-hide_banner", "-loglevel", "error", "-f", "f32le", "-ar", "16000", "-ac", "1", "-i", "pipe:0",  # noqa: S607
             "-c:a", "libmp3lame", "-b:a", "48k", "-f", "mp3", "pipe:1"],
            input=samples.astype("<f4").tobytes(), capture_output=True, timeout=300, check=True,
        )
        return proc.stdout

    @staticmethod
    def parse_segments(text: str, offset: float, end: float) -> List[Dict[str, Any]]:
        """'[mm:ss] words' lines (times within one piece) -> segments on the recording's clock, kept in order."""
        segments: List[Dict[str, Any]] = []
        for line in _strip_fences(text).splitlines():
            line = line.strip()
            if not line or line.lower() == "[no speech]":
                continue
            match = TIMESTAMPED_LINE.match(line)
            if match and match.group(4).strip():
                a, b, c = match.group(1), match.group(2), match.group(3)
                at = int(a) * 3600 + int(b) * 60 + int(c) if c else int(a) * 60 + int(b)
                start = min(max(offset + at, segments[-1]["start"] if segments else offset), end)
                segments.append({"start": round(start, 1), "text": match.group(4).strip()})
            elif segments:
                segments[-1]["text"] += " " + line
            else:
                segments.append({"start": round(offset, 1), "text": line})
        for i, seg in enumerate(segments):
            seg["end"] = segments[i + 1]["start"] if i + 1 < len(segments) else round(end, 1)
        return [{"start": s["start"], "end": s["end"], "text": s["text"]} for s in segments]

    def _run_hosted(self, audio: Any, language: Optional[str]) -> Tuple[List[Dict[str, Any]], List[str]]:
        models = llm_chain.audio_models()
        rate = 16000
        cuts = self.split_points(audio, rate, get_settings().asr_hosted_chunk_seconds)
        pieces = [(a / rate, b / rate, audio[a:b]) for a, b in zip(cuts, cuts[1:])]
        hint = {"en": " (spoken in English)", "bn": " (spoken in Bangla)"}.get(language or "", "")

        def piece(item: Tuple[float, float, Any]) -> Tuple[str, List[Dict[str, Any]]]:
            offset, end, samples = item
            part = {"type": "input_audio", "input_audio": {"data": base64.b64encode(self._encode_mp3(samples)).decode(), "format": "mp3"}}
            model, text = llm_chain.run_media(models, [{"type": "text", "text": ASR_PROMPT.format(language=hint)}, part], 8192)
            return model, self.parse_segments(text, offset, end)

        # Pieces are independent, so a long lecture is sent a few pieces at a time
        with ThreadPoolExecutor(max_workers=min(6, len(pieces))) as pool:
            results = list(pool.map(piece, pieces))
        used: List[str] = []
        for model, _ in results:
            if model not in used:
                used.append(model)
        return [seg for _, segs in results for seg in segs], used

    @staticmethod
    def clean_terms(terms: Optional[str]) -> str:
        """Key terms the speaker uses (course vocabulary), comma-separated, bounded so they stay a hint."""
        parts = [re.sub(r"\s+", " ", p).strip() for p in re.split(r"[,;\n]", terms or "")]
        return ", ".join(dict.fromkeys(p for p in parts if p))[:400]

    @staticmethod
    def proofread(segments: List[Dict[str, Any]], terms: str, generation: Any) -> Tuple[List[Dict[str, Any]], int, Optional[str]]:
        """Fix misheard words with the hosted text model, line by line. Returns (segments, lines changed, model).

        Only used when the hosted provider is selected; the small local model is not trusted with this. A batch whose
        reply is not the same number of lines is skipped, and a line rewritten beyond small corrections keeps the
        original, so the transcript cannot drift from what was said.
        """
        if generation is None or provider_context.requested_provider() != "hosted" or not generation.hosted_configured or not segments:
            return segments, 0, None
        subject = f" (key terms: {terms})" if terms else ""
        out = [dict(s) for s in segments]
        changed, model = 0, None
        for start in range(0, len(out), PROOFREAD_BATCH):
            batch = out[start:start + PROOFREAD_BATCH]
            numbered = "\n".join(json.dumps(s["text"], ensure_ascii=False) for s in batch)
            text = generation.generate(PROOFREAD_PROMPT.format(subject=subject, count=len(batch), lines=numbered),
                                       max_new_tokens=min(8000, 120 + sum(len(s["text"]) for s in batch)))
            used = provider_context.model_used() or ""
            if not text or not used.startswith("hosted:"):
                continue  # hosted models failed: keep Whisper's lines rather than the small local model's
            match = re.search(r"\{.*\}", text, re.S)
            try:
                lines = json.loads(match.group(0)).get("lines") if match else None
            except ValueError:
                lines = None
            if not isinstance(lines, list) or len(lines) != len(batch):
                continue
            for seg, new in zip(batch, lines):
                new = re.sub(r"\s+", " ", str(new)).strip()
                old = seg["text"]
                if new and new != old and difflib.SequenceMatcher(None, old.lower().split(), new.lower().split()).ratio() >= PROOFREAD_MIN_SIMILARITY:
                    seg["text"] = new
                    changed += 1
            model = used
        return out, changed, model

    def run(self, data: bytes, language: Optional[str] = None, terms: Optional[str] = None, generation: Any = None) -> Dict[str, Any]:
        if not self.dependencies()["ffmpeg"]:
            raise ToolkitError("Audio decoding (ffmpeg) is not installed in the AI service.")
        terms = self.clean_terms(terms)
        audio = self._decode(data)
        seconds = len(audio) / 16000
        limit = get_settings().asr_max_seconds
        if seconds < 1:
            raise ToolkitError("The recording is empty.")
        if seconds > limit:
            raise ToolkitError(f"The recording is {int(seconds // 60)} minutes long; the limit is {limit // 60} minutes.")

        use_hosted, fallback = _hosted_media(llm_chain.audio_models())
        if use_hosted:
            try:
                segments, used = self._run_hosted(audio, language)
                return {
                    "text": " ".join(s["text"] for s in segments).strip(), "segments": segments, "duration_seconds": round(seconds, 1),
                    "language": language or "auto", "model": ", ".join(f"hosted:{m}" for m in used), "provider": "hosted",
                    "fallback_reason": None,
                }
            except (llm_chain.HostedCallError, subprocess.SubprocessError) as e:
                fallback = getattr(e, "reason", None) or f"audio could not be prepared for the hosted model ({type(e).__name__})"
                logger.warning(f"Hosted transcription failed, using {self.model_name}: {fallback}")

        fast = self._transcribe_fast(audio, language, terms) if self.fast_enabled else None
        if fast is not None:
            text, segments = fast
        else:
            text, segments = self._transcribe_pipeline(audio, language, terms)
        segments, corrected, proofread_by = self.proofread(segments, terms, generation)
        if corrected:
            text = " ".join(s["text"] for s in segments)
        return {
            "text": text,
            "segments": segments,
            "corrected_lines": corrected,
            "proofread_model": proofread_by,
            "duration_seconds": round(seconds, 1),
            "language": language or "auto",
            "model": self.model_name,
            "provider": "local",
            "fallback_reason": fallback,
        }


# ============================================================================================== translation

NLLB_CODES = {"en": "eng_Latn", "bn": "ben_Beng"}
LANG_NAMES = {"en": "English", "bn": "Bangla (Bengali)"}


class Translator:
    _lock = threading.Lock()
    _tokenizer: Any = None
    _model: Any = None

    @property
    def model_name(self) -> str:
        return get_settings().translation_model

    @property
    def loaded(self) -> bool:
        return self._model is not None

    def dependencies(self) -> Dict[str, bool]:
        return {"sentencepiece": _module_available("sentencepiece")}

    def _load(self) -> None:
        with self._lock:
            if Translator._model is not None:
                return
            from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

            token = get_settings().hf_token or None
            Translator._tokenizer = AutoTokenizer.from_pretrained(self.model_name, token=token)
            Translator._model = AutoModelForSeq2SeqLM.from_pretrained(self.model_name, token=token)
            Translator._model.eval()
            logger.info(f"Translation model '{self.model_name}' loaded.")

    @staticmethod
    def _chunks(text: str, limit: int = 400) -> List[str]:
        """Split on paragraph and sentence boundaries so each piece fits the model comfortably."""
        pieces: List[str] = []
        for para in re.split(r"\n\s*\n", text):
            para = para.strip()
            if not para:
                continue
            current = ""
            for sentence in re.split(r"(?<=[.!?।])\s+", para):
                if len(current) + len(sentence) + 1 > limit and current:
                    pieces.append(current)
                    current = sentence
                else:
                    current = f"{current} {sentence}".strip()
            if current:
                pieces.append(current)
            pieces.append("\n\n")
        return pieces[:-1] if pieces and pieces[-1] == "\n\n" else pieces

    @staticmethod
    def _unload() -> None:
        Translator._tokenizer = Translator._model = None

    def _local(self, text: str, source: str, target: str) -> str:
        if not self.dependencies()["sentencepiece"]:
            raise ToolkitError("Translation support (sentencepiece) is not installed in the AI service.")
        with model_memory.use("translation", self._unload, estimate_mb=2600):
            return self._translate_local(text, source, target)

    def _translate_local(self, text: str, source: str, target: str) -> str:
        self._load()
        import torch

        self._tokenizer.src_lang = NLLB_CODES[source]
        target_id = self._tokenizer.convert_tokens_to_ids(NLLB_CODES[target])
        out: List[str] = []
        for piece in self._chunks(text):
            if piece == "\n\n":
                out.append("\n\n")
                continue
            inputs = self._tokenizer(piece, return_tensors="pt", truncation=True, max_length=512)
            with torch.no_grad():
                ids = self._model.generate(**inputs, forced_bos_token_id=target_id, max_new_tokens=512, num_beams=2)
            out.append(self._tokenizer.batch_decode(ids, skip_special_tokens=True)[0].strip())
        return re.sub(r" *\n\n *", "\n\n", " ".join(out)).strip()

    def run(self, text: str, source: str, target: str, generation_service: Any = None) -> Dict[str, Any]:
        text = (text or "").strip()
        if source not in NLLB_CODES or target not in NLLB_CODES or source == target:
            raise ToolkitError("Choose English → Bangla or Bangla → English.")
        if not text:
            raise ToolkitError("Enter some text to translate.")
        limit = get_settings().translation_max_chars
        if len(text) > limit:
            raise ToolkitError(f"The text is {len(text)} characters; the limit is {limit}.")

        if generation_service is not None and provider_context.requested_provider() == "hosted" and generation_service.hosted_configured:
            prompt = (f"Translate the following text from {LANG_NAMES[source]} to {LANG_NAMES[target]}. Keep the meaning, academic terms, "
                      f"numbers and formatting. Reply with the translation only.\n\n{text}")
            translated = generation_service.generate(prompt, max_new_tokens=min(2048, len(text) * 2 + 64))
            used = provider_context.model_used() or ""
            if translated and used.startswith("hosted:"):
                return {"text": translated.strip(), "source": source, "target": target, "model": used, "provider": "hosted"}
            provider_context.record_fallback(provider_context.fallback_reason() or "hosted translation failed")

        return {"text": self._local(text, source, target), "source": source, "target": target, "model": self.model_name, "provider": "local",
                "fallback_reason": provider_context.fallback_reason()}


# ============================================================================================== text to speech

TTS_MODELS = {"en": "facebook/mms-tts-eng", "bn": "facebook/mms-tts-ben"}
KOKORO_REPO = "hexgrad/Kokoro-82M"
KOKORO_RATE = 24000


class Speaker:
    """Read text aloud: Kokoro-82M for English (natural voice), Meta MMS-TTS (VITS) for Bangla and as the English
    fallback. Each model loads on first use."""

    _lock = threading.Lock()
    _models: Dict[str, Any] = {}
    _tokenizers: Dict[str, Any] = {}
    _kokoro: Any = None
    # Health of the online Bangla voice, for the admin status page
    edge_last_ok: Optional[float] = None
    edge_last_error: Optional[str] = None
    edge_last_error_at: Optional[float] = None

    @property
    def max_chars(self) -> int:
        return get_settings().tts_max_chars

    def model_name(self, language: str) -> str:
        if language == "en" and self.english_edge_enabled:
            return f"edge-tts ({get_settings().tts_english_voice})"
        if language == "en" and self.kokoro_enabled:
            return f"{KOKORO_REPO} ({get_settings().tts_kokoro_voice})"
        if language == "bn" and self.edge_enabled:
            return f"edge-tts ({get_settings().tts_bangla_voice})"
        return TTS_MODELS[language]

    @staticmethod
    def _edge_available() -> bool:
        return _module_available("edge_tts") and shutil.which("ffmpeg") is not None

    @property
    def edge_enabled(self) -> bool:
        return get_settings().tts_bangla_engine.strip().lower() == "edge" and self._edge_available()

    @property
    def english_edge_enabled(self) -> bool:
        return get_settings().tts_english_engine.strip().lower() == "edge" and self._edge_available()

    @staticmethod
    def _edge_mp3(text: str, voice: str) -> bytes:
        """Microsoft neural voice through edge-tts (online). Returns MP3 bytes."""
        import asyncio

        import edge_tts

        async def synth() -> bytes:
            audio = bytearray()
            async for chunk in edge_tts.Communicate(text, voice).stream():
                if chunk.get("type") == "audio":
                    audio.extend(chunk["data"])
            return bytes(audio)

        # The speak endpoint runs in a worker thread, so a fresh event loop is safe here
        return asyncio.run(asyncio.wait_for(synth(), timeout=60))

    @staticmethod
    def _cache_path(voice: str, text: str) -> Optional[str]:
        s = get_settings()
        if s.tts_cache_max_files <= 0:
            return None
        folder = os.path.join(s.hf_home or "/app/cache/huggingface", "tts-cache")
        return os.path.join(folder, hashlib.sha1(f"{voice}\x00{text}".encode("utf-8"), usedforsecurity=False).hexdigest() + ".mp3")

    @staticmethod
    def _cache_put(path: str, mp3: bytes) -> None:
        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, "wb") as f:
                f.write(mp3)
            files = sorted((os.path.join(os.path.dirname(path), n) for n in os.listdir(os.path.dirname(path))), key=os.path.getmtime)
            for old in files[: max(0, len(files) - get_settings().tts_cache_max_files)]:
                os.remove(old)
        except OSError:
            pass  # a cache problem must never break read-aloud

    def _online_mp3(self, text: str, voice: str) -> bytes:
        """Cached clip, else the online voice (one retry). Records the voice's health for the status page."""
        path = self._cache_path(voice, text)
        if path and os.path.exists(path):
            os.utime(path)  # recently used: kept longest
            with open(path, "rb") as f:
                return f.read()
        last: Optional[Exception] = None
        for attempt in range(2):
            try:
                mp3 = self._edge_mp3(text, voice)
                if not mp3:
                    raise RuntimeError("the online voice returned no audio")
                Speaker.edge_last_ok = time.time()
                if path:
                    self._cache_put(path, mp3)
                return mp3
            except Exception as e:
                last = e
                if attempt == 0:
                    time.sleep(1)
        Speaker.edge_last_error, Speaker.edge_last_error_at = type(last).__name__, time.time()
        raise last  # type: ignore[misc]

    def online_voice_status(self) -> Optional[Dict[str, Any]]:
        if not self.edge_enabled:
            return None
        failing = Speaker.edge_last_error_at is not None and (Speaker.edge_last_ok is None or Speaker.edge_last_error_at > Speaker.edge_last_ok)
        return {"voice": get_settings().tts_bangla_voice, "state": "failing" if failing else ("ok" if Speaker.edge_last_ok else "unused"),
                "last_error": Speaker.edge_last_error if failing else None}

    def _speak_edge(self, text: str, language: str = "bn") -> Dict[str, Any]:
        import numpy as np

        s = get_settings()
        mp3 = self._online_mp3(text, s.tts_english_voice if language == "en" else s.tts_bangla_voice)
        rate = 24000
        pcm = subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-i", "pipe:0", "-f", "f32le", "-ac", "1", "-ar", str(rate), "pipe:1"],  # noqa: S603,S607 - fixed ffmpeg arguments, audio on stdin, no shell
                             input=mp3, capture_output=True, timeout=120, check=True).stdout
        return self._wav(np.frombuffer(pcm, dtype="<f4"), rate, self.model_name(language))

    @property
    def kokoro_enabled(self) -> bool:
        return get_settings().tts_english_engine.strip().lower() == "kokoro" and _module_available("kokoro")

    @property
    def loaded_languages(self) -> List[str]:
        return sorted(set(self._models) | ({"en"} if Speaker._kokoro is not None else set()))

    def _load_kokoro(self) -> Any:
        with self._lock:
            if Speaker._kokoro is None:
                from kokoro import KPipeline

                Speaker._kokoro = KPipeline(lang_code="a", repo_id=KOKORO_REPO)  # "a" = American English
                logger.info(f"TTS model '{KOKORO_REPO}' loaded.")
        return Speaker._kokoro

    @staticmethod
    def _unload_kokoro() -> None:
        Speaker._kokoro = None

    @staticmethod
    def _unloader(language: str) -> Any:
        def unload() -> None:
            Speaker._models.pop(language, None)
            Speaker._tokenizers.pop(language, None)
        return unload

    def _speak_kokoro(self, text: str) -> Any:
        with model_memory.use("speech-kokoro", self._unload_kokoro, estimate_mb=500):
            return self._kokoro_audio(text)

    def _kokoro_audio(self, text: str) -> Any:
        import numpy as np

        pipeline = self._load_kokoro()
        pieces = []
        for result in pipeline(text, voice=get_settings().tts_kokoro_voice, speed=1.0):
            audio = getattr(result, "audio", None)
            if audio is None and isinstance(result, tuple):
                audio = result[2]
            if audio is not None:
                pieces.append(np.asarray(audio.cpu() if hasattr(audio, "cpu") else audio, dtype=np.float32).reshape(-1))
        if not pieces:
            raise ToolkitError("Nothing in the text could be spoken.")
        return np.concatenate(pieces)

    def _load(self, language: str) -> None:
        with self._lock:
            if language in Speaker._models:
                return
            from transformers import AutoTokenizer, VitsModel

            token = get_settings().hf_token or None
            Speaker._tokenizers[language] = AutoTokenizer.from_pretrained(TTS_MODELS[language], token=token)
            model = VitsModel.from_pretrained(TTS_MODELS[language], token=token)
            model.eval()
            Speaker._models[language] = model
            logger.info(f"TTS model '{TTS_MODELS[language]}' loaded.")

    def run(self, text: str, language: str) -> Dict[str, Any]:
        text = re.sub(r"\s+", " ", (text or "")).strip()
        if language not in TTS_MODELS:
            raise ToolkitError("Choose English or Bangla.")
        if not text:
            raise ToolkitError("Enter some text to read aloud.")
        if len(text) > self.max_chars:
            raise ToolkitError(f"The text is {len(text)} characters; the limit is {self.max_chars}.")
        if language == "en" and self.english_edge_enabled:
            try:
                return self._speak_edge(text, "en")
            except Exception as e:  # offline, service change or rate limit: fall back to the local voice
                logger.warning(f"Online English voice failed ({type(e).__name__}); using {TTS_MODELS['en']}")
        elif language == "en" and self.kokoro_enabled:
            try:
                return self._wav(self._speak_kokoro(text), KOKORO_RATE, self.model_name("en"))
            except ToolkitError:
                raise
            except Exception as e:  # missing voice download, phonemizer problems: fall back to MMS-TTS
                logger.warning(f"Kokoro TTS failed ({type(e).__name__}); using {TTS_MODELS['en']}")
        if language == "bn" and self.edge_enabled:
            try:
                return self._speak_edge(text)
            except Exception as e:  # offline, service change or rate limit: the local voice still works
                logger.warning(f"Online Bangla voice failed ({type(e).__name__}); using {TTS_MODELS['bn']}")
        return self._speak_mms(text, language)

    def _speak_mms(self, text: str, language: str) -> Dict[str, Any]:
        with model_memory.use(f"speech-mms-{language}", self._unloader(language), estimate_mb=250):
            return self._mms_audio(text, language)

    def _mms_audio(self, text: str, language: str) -> Dict[str, Any]:
        self._load(language)
        import numpy as np
        import torch

        tokenizer, model = self._tokenizers[language], self._models[language]
        rate = int(model.config.sampling_rate)
        pause = np.zeros(int(rate * 0.25), dtype=np.float32)
        pieces: List[Any] = []
        # VITS handles a sentence at a time best; join sentences with a short pause
        for sentence in [s for s in re.split(r"(?<=[.!?।])\s+", text) if s.strip()]:
            inputs = tokenizer(sentence[:600], return_tensors="pt")
            if inputs["input_ids"].shape[-1] == 0:
                continue
            with torch.no_grad():
                wave = model(**inputs).waveform[0].cpu().numpy().astype(np.float32)
            pieces.extend([wave, pause])
        if not pieces:
            raise ToolkitError("Nothing in the text could be spoken.")
        return self._wav(np.concatenate(pieces), rate, TTS_MODELS[language])

    @staticmethod
    def _wav(audio: Any, rate: int, model: str) -> Dict[str, Any]:
        import numpy as np

        pcm = (np.clip(audio, -1.0, 1.0) * 32767).astype("<i2").tobytes()

        import wave as wavmod

        buf = io.BytesIO()
        with wavmod.open(buf, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(rate)
            w.writeframes(pcm)
        return {"audio": buf.getvalue(), "sampling_rate": rate, "seconds": round(len(audio) / rate, 1), "model": model}


_ocr = HandwritingOcr()
_speaker = Speaker()
_asr = LectureTranscriber()
_translator = Translator()


def get_ocr() -> HandwritingOcr:
    return _ocr


def get_transcriber() -> LectureTranscriber:
    return _asr


def get_translator() -> Translator:
    return _translator


def get_speaker() -> Speaker:
    return _speaker
