"""AI toolkit: handwriting OCR, lecture transcription, Bangla <-> English translation and text to speech.

When the administrator selected the hosted provider, OCR and transcription go to hosted multimodal models through
LangChain (llm_chain.run_media; Gemini 3.8 Flash first by default) and fall back to the local models if every hosted
model fails. Local Hugging Face models load lazily on first use (memory is only spent on tools in use):
- OCR           : microsoft/trocr-base-handwritten. TrOCR reads one text line at a time, so pages are split into
                  lines with a horizontal ink-projection pass first. Output is a draft transcription for a person to
                  check; it is never treated as a student's final answer automatically.
- Transcription : openai/whisper-large-v3-turbo through the transformers ASR pipeline (30 s chunks, ffmpeg decoding).
- Translation   : facebook/nllb-200-distilled-600M, or the hosted generation model when the administrator selected
                  the hosted provider (falls back to NLLB if the hosted call fails).
- Speech        : Kokoro-82M for English (MMS-TTS if Kokoro is unavailable), MMS-TTS for Bangla.

Every public method returns plain dicts and raises ToolkitError with a safe, user-facing message on bad input.
"""

import base64
import io
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
    "Transcribe the handwritten text in this image exactly as written. Keep the original line breaks and the original "
    "language (English or Bangla). Do not correct spelling or grammar, and do not add explanations, headings or "
    "formatting. Write [illegible] for any word you cannot read. If the page has no text, reply with exactly: [blank page]"
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
TIMESTAMPED_LINE = re.compile(r"^\s*\[?(\d{1,2}):(\d{2})(?::(\d{2}))?\]?\s*[-–:]?\s*(.*)$")


class LectureTranscriber:
    _lock = threading.Lock()
    _pipe: Any = None

    @property
    def model_name(self) -> str:
        return get_settings().asr_model

    @property
    def loaded(self) -> bool:
        return self._pipe is not None

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
        proc = subprocess.run(
            ["ffmpeg", "-hide_banner", "-loglevel", "error", "-f", "f32le", "-ar", "16000", "-ac", "1", "-i", "pipe:0",
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
        size = max(60, get_settings().asr_hosted_chunk_seconds) * rate
        pieces = [(start / rate, min(len(audio), start + size) / rate, audio[start:start + size]) for start in range(0, len(audio), size)]
        hint = {"en": " (spoken in English)", "bn": " (spoken in Bangla)"}.get(language or "", "")

        def piece(item: Tuple[float, float, Any]) -> Tuple[str, List[Dict[str, Any]]]:
            offset, end, samples = item
            part = {"type": "input_audio", "input_audio": {"data": base64.b64encode(self._encode_mp3(samples)).decode(), "format": "mp3"}}
            model, text = llm_chain.run_media(models, [{"type": "text", "text": ASR_PROMPT.format(language=hint)}, part], 8192)
            return model, self.parse_segments(text, offset, end)

        # Pieces are independent, so a long lecture is sent a few pieces at a time
        with ThreadPoolExecutor(max_workers=min(4, len(pieces))) as pool:
            results = list(pool.map(piece, pieces))
        used: List[str] = []
        for model, _ in results:
            if model not in used:
                used.append(model)
        return [seg for _, segs in results for seg in segs], used

    def run(self, data: bytes, language: Optional[str] = None) -> Dict[str, Any]:
        if not self.dependencies()["ffmpeg"]:
            raise ToolkitError("Audio decoding (ffmpeg) is not installed in the AI service.")
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

        kwargs: Dict[str, Any] = {"task": "transcribe"}
        if language in ("en", "bn"):
            kwargs["language"] = {"en": "english", "bn": "bengali"}[language]
        with model_memory.use("transcription", self._unload, estimate_mb=_whisper_mb(self.model_name)):
            self._load()
            result = self._pipe({"raw": audio, "sampling_rate": 16000}, return_timestamps=True, generate_kwargs=kwargs)
        segments = [
            {"start": round(float(c["timestamp"][0] or 0), 1), "end": round(float(c["timestamp"][1] or c["timestamp"][0] or 0), 1), "text": str(c["text"]).strip()}
            for c in (result.get("chunks") or []) if str(c.get("text", "")).strip()
        ]
        return {
            "text": str(result.get("text", "")).strip(),
            "segments": segments,
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

    @property
    def max_chars(self) -> int:
        return get_settings().tts_max_chars

    def model_name(self, language: str) -> str:
        if language == "en" and self.kokoro_enabled:
            return f"{KOKORO_REPO} ({get_settings().tts_kokoro_voice})"
        return TTS_MODELS[language]

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
        if language == "en" and self.kokoro_enabled:
            try:
                return self._wav(self._speak_kokoro(text), KOKORO_RATE, self.model_name("en"))
            except ToolkitError:
                raise
            except Exception as e:  # missing voice download, phonemizer problems: fall back to MMS-TTS
                logger.warning(f"Kokoro TTS failed ({type(e).__name__}); using {TTS_MODELS['en']}")
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
