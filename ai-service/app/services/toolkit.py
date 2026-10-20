"""AI toolkit: handwriting OCR, lecture transcription and Bangla <-> English translation.

All three run local Hugging Face models that load lazily on first use (memory is only spent on tools in use):
- OCR           : microsoft/trocr-base-handwritten. TrOCR reads one text line at a time, so pages are split into
                  lines with a horizontal ink-projection pass first. Output is a draft transcription for a person to
                  check; it is never treated as a student's final answer automatically.
- Transcription : openai/whisper-base through the transformers ASR pipeline (30 s chunks, ffmpeg decoding).
- Translation   : facebook/nllb-200-distilled-600M, or the hosted generation model when the administrator selected
                  the hosted provider (falls back to NLLB if the hosted call fails).

Every public method returns plain dicts and raises ToolkitError with a safe, user-facing message on bad input.
"""

import io
import logging
import re
import shutil
import threading
from typing import Any, Dict, List, Optional

from app.config import get_settings
from app.services import provider_context

logger = logging.getLogger("facultylens.ai")


class ToolkitError(Exception):
    """Input or environment problem with a message that is safe to show to the user."""


def _module_available(name: str) -> bool:
    import importlib.util

    return importlib.util.find_spec(name) is not None


# ============================================================================================== OCR

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

    def run(self, data: bytes, filename: str) -> Dict[str, Any]:
        pages = self._pages(data, filename)
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
        return {
            "text": "\n\n".join(p["text"] for p in out_pages).strip(),
            "pages": out_pages,
            "line_count": sum(len(p["lines"]) for p in out_pages),
            "model": self.model_name,
            "provider": "local",
            "note": "Draft transcription of handwriting. Check it against the original before using it for grading.",
        }


# ============================================================================================== transcription

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

    def _load(self) -> None:
        with self._lock:
            if LectureTranscriber._pipe is not None:
                return
            from transformers import pipeline

            LectureTranscriber._pipe = pipeline("automatic-speech-recognition", model=self.model_name, device=-1, chunk_length_s=30,
                                                token=get_settings().hf_token or None)
            logger.info(f"ASR model '{self.model_name}' loaded.")

    def run(self, data: bytes, language: Optional[str] = None) -> Dict[str, Any]:
        if not self.dependencies()["ffmpeg"]:
            raise ToolkitError("Audio decoding (ffmpeg) is not installed in the AI service.")
        from transformers.pipelines.audio_utils import ffmpeg_read

        try:
            audio = ffmpeg_read(data, 16000)
        except Exception:
            raise ToolkitError("The file is not a readable audio or video recording.")
        seconds = len(audio) / 16000
        limit = get_settings().asr_max_seconds
        if seconds < 1:
            raise ToolkitError("The recording is empty.")
        if seconds > limit:
            raise ToolkitError(f"The recording is {int(seconds // 60)} minutes long; the limit is {limit // 60} minutes.")

        self._load()
        kwargs: Dict[str, Any] = {"task": "transcribe"}
        if language in ("en", "bn"):
            kwargs["language"] = {"en": "english", "bn": "bengali"}[language]
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

    def _local(self, text: str, source: str, target: str) -> str:
        if not self.dependencies()["sentencepiece"]:
            raise ToolkitError("Translation support (sentencepiece) is not installed in the AI service.")
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


class Speaker:
    """Read text aloud with Meta MMS-TTS (VITS). One small model per language, loaded on first use."""

    _lock = threading.Lock()
    _models: Dict[str, Any] = {}
    _tokenizers: Dict[str, Any] = {}

    @property
    def max_chars(self) -> int:
        return get_settings().tts_max_chars

    def model_name(self, language: str) -> str:
        return TTS_MODELS[language]

    @property
    def loaded_languages(self) -> List[str]:
        return sorted(self._models)

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
        audio = np.concatenate(pieces)
        pcm = (np.clip(audio, -1.0, 1.0) * 32767).astype("<i2").tobytes()

        import wave as wavmod

        buf = io.BytesIO()
        with wavmod.open(buf, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(rate)
            w.writeframes(pcm)
        return {"audio": buf.getvalue(), "sampling_rate": rate, "seconds": round(len(audio) / rate, 1), "model": TTS_MODELS[language]}


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
