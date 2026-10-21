"""Download every local model the AI service uses, in one go, so no teacher waits for a multi-GB download on first use.

    docker compose exec ai-service python -m app.download_models            # the models the current settings use
    docker compose exec ai-service python -m app.download_models --all      # also the fallback models
    docker compose exec ai-service python -m app.download_models --list     # only show what would be downloaded

Each model is loaded once through the same code the tools use (so exactly the files they need are fetched into the
model cache volume) and unloaded again before the next one, so memory never holds more than one model. Models already
in the cache load from disk in seconds. A failed model is reported and the others still download; the exit code is 1
if anything failed.
"""

import argparse
import gc
import sys
import time
from typing import Callable, List, Tuple

from app.config import get_settings


def _steps(include_fallbacks: bool) -> List[Tuple[str, str, Callable[[], None]]]:
    """(name, model, load-and-unload) for every model in use with the current settings."""
    from app.services import toolkit
    from app.services.answer_embedder import get_answer_embedder
    from app.services.claim_check import get_claim_verifier
    from app.services.generation_service import get_generation_service
    from app.services.huggingface_service import get_hf_service

    s = get_settings()
    steps: List[Tuple[str, str, Callable[[], None]]] = []

    def add(name: str, model: str, load: Callable[[], object], unload: Callable[[], None]) -> None:
        def run() -> None:
            if load() is False:
                raise RuntimeError("the model did not load (see the log above)")
            unload()

        steps.append((name, model, run))

    add("Embeddings (search, alignment)", s.hf_model_name, lambda: get_hf_service().load_model(), lambda: None)

    embedder = get_answer_embedder()
    if embedder.model_name:
        add("Answer similarity", embedder.model_name, embedder._load, embedder._unload)

    verifier = get_claim_verifier()
    if verifier.model_name:
        add("Claim check (NLI)", verifier.model_name, verifier._load, verifier._unload)

    gen = get_generation_service()
    if gen.local_configured:
        add("Local text generation", gen.local_model_name or "", gen.load_model, gen._unload)

    translator = toolkit.get_translator()
    add("Translation", translator.model_name, translator._load, translator._unload)

    ocr = toolkit.get_ocr()
    if include_fallbacks or not toolkit.llm_chain.vision_models():
        add("Handwriting OCR (local)", ocr.model_name, ocr._load, ocr._unload)

    asr = toolkit.get_transcriber()
    if asr.fast_enabled:
        add("Transcription", f"faster-whisper {s.asr_fast_model}", asr._load_fast, asr._unload_fast)
    if include_fallbacks or not asr.fast_enabled:
        add("Transcription (pipeline)", s.asr_model, asr._load, asr._unload)

    speaker = toolkit.get_speaker()
    if speaker.kokoro_enabled:
        # Synthesising one word also fetches the voice file, which loads separately from the model
        add("Read aloud, English", toolkit.KOKORO_REPO, lambda: speaker._speak_kokoro("Ready."), speaker._unload_kokoro)
    for lang in ("en", "bn"):
        if lang == "en" and speaker.kokoro_enabled and not include_fallbacks:
            continue
        if lang == "bn" and speaker.edge_enabled and not include_fallbacks:
            continue  # the online Bangla voice needs no download; MMS is only its fallback
        add(f"Read aloud ({lang}, local)", toolkit.TTS_MODELS[lang], (lambda lg=lang: speaker._load(lg)), speaker._unloader(lang))
    return steps


def main(argv: List[str]) -> int:
    parser = argparse.ArgumentParser(description="Download the AI service's local models into the model cache.")
    parser.add_argument("--all", action="store_true", help="also download fallback models (local OCR, pipeline Whisper, MMS voices)")
    parser.add_argument("--list", action="store_true", help="only list the models")
    args = parser.parse_args(argv)

    steps = _steps(args.all)
    print(f"{len(steps)} model(s){' including fallbacks' if args.all else ''}:")
    for name, model, _ in steps:
        print(f"  - {name}: {model}")
    if args.list:
        return 0

    failed = 0
    started = time.time()
    for i, (name, model, run) in enumerate(steps, start=1):
        print(f"\n[{i}/{len(steps)}] {name}: {model}", flush=True)
        t = time.time()
        try:
            run()
            print(f"    ready ({time.time() - t:.0f} s)", flush=True)
        except Exception as e:  # keep going: one failed model must not block the others
            failed += 1
            print(f"    FAILED: {type(e).__name__}: {str(e)[:200]}", flush=True)
        gc.collect()
    print(f"\nDone in {(time.time() - started) / 60:.1f} min: {len(steps) - failed} ready, {failed} failed.")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
