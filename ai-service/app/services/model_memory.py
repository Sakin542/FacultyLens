"""Keeps the AI service's local models within the container's memory.

Every large local model (generation, translation, OCR, transcription, speech, answer embeddings) loads lazily and
would otherwise stay in memory for good; with all of them loaded the service needs ~9-10 GB, more than Docker Desktop
gives the whole stack by default. Each model's work runs inside ``use(name, unload, estimate_mb)``, which:

- before a model that is not in memory loads, unloads the least recently used idle models until the process plus
  the new model fits within MODEL_MEMORY_BUDGET_MB;
- records when each model was last used, so a background sweep unloads models idle for MODEL_IDLE_UNLOAD_SECONDS.

A model is never unloaded while a request is using it. The shared MiniLM embedder is small and used by almost every
endpoint, so it is not managed here. After an unload, freed memory is handed back to the OS (gc + malloc_trim) so the
container's usage actually drops; the model reloads from the local cache on next use (seconds, no download).
"""

import ctypes
import gc
import logging
import os
import threading
import time
from contextlib import contextmanager
from dataclasses import dataclass
from typing import Callable, Dict, Iterator, List, Optional

from app.config import get_settings

logger = logging.getLogger("facultylens.ai")


@dataclass
class _Slot:
    name: str
    unload: Callable[[], None]
    estimate_mb: int
    loaded: bool = False
    in_use: int = 0
    last_used: float = 0.0


_lock = threading.Lock()
_slots: Dict[str, _Slot] = {}
_sweeper: Optional[threading.Thread] = None


def rss_mb() -> float:
    """Resident memory of this process in MB (0 where /proc is unavailable, e.g. on a Windows dev machine)."""
    try:
        with open("/proc/self/statm") as f:
            return int(f.read().split()[1]) * os.sysconf("SC_PAGE_SIZE") / 1048576
    except (OSError, ValueError, AttributeError):
        return 0.0


def _release_memory() -> None:
    gc.collect()
    try:  # glibc keeps freed heap pages unless asked; without this the container's usage does not drop
        ctypes.CDLL("libc.so.6").malloc_trim(0)
    except OSError:
        pass


def _unload(slot: _Slot, why: str) -> None:
    """Call with _lock held and slot idle."""
    try:
        slot.unload()
    except Exception as e:  # an unload problem must never break a request
        logger.warning(f"Unloading model '{slot.name}' failed ({type(e).__name__})")
    slot.loaded = False
    _release_memory()
    logger.info(f"Unloaded model '{slot.name}' ({why}); process now {rss_mb():.0f} MB")


class ModelTooLargeError(RuntimeError):
    """Raised in strict mode when a model cannot fit the memory budget even after unloading idle models."""


def _make_room(needed_mb: int, exclude: str) -> None:
    settings = get_settings()
    budget = settings.model_memory_budget_mb
    if budget <= 0:
        return
    with _lock:
        while rss_mb() + needed_mb > budget:
            idle = [s for s in _slots.values() if s.loaded and s.in_use == 0 and s.name != exclude]
            if not idle:
                if settings.model_memory_strict:
                    raise ModelTooLargeError(
                        f"The local '{exclude}' model (~{needed_mb} MB) does not fit this server's {budget} MB memory budget. "
                        "Use the hosted AI provider for this tool."
                    )
                logger.warning(f"Loading '{exclude}' (~{needed_mb} MB) may exceed the {budget} MB model budget: no idle model to unload")
                return
            _unload(min(idle, key=lambda s: s.last_used), f"making room for '{exclude}'")


@contextmanager
def use(name: str, unload: Callable[[], None], estimate_mb: int) -> Iterator[None]:
    """Wrap the code that loads (if needed) and runs a local model."""
    _start_sweeper()
    with _lock:
        slot = _slots.get(name)
        if slot is None:
            slot = _slots[name] = _Slot(name, unload, estimate_mb)
        slot.unload, slot.estimate_mb = unload, estimate_mb
        slot.in_use += 1
        needs_load = not slot.loaded
    try:
        if needs_load:
            _make_room(estimate_mb, exclude=name)
        yield
        slot.loaded = True
    finally:
        with _lock:
            slot.in_use -= 1
            slot.last_used = time.monotonic()


def sweep(now: Optional[float] = None) -> List[str]:
    """Unload models idle for longer than MODEL_IDLE_UNLOAD_SECONDS. Returns the names unloaded."""
    idle_after = get_settings().model_idle_unload_seconds
    if idle_after <= 0:
        return []
    now = time.monotonic() if now is None else now
    unloaded = []
    with _lock:
        for slot in _slots.values():
            if slot.loaded and slot.in_use == 0 and now - slot.last_used >= idle_after:
                _unload(slot, f"idle for {int(now - slot.last_used)} s")
                unloaded.append(slot.name)
    return unloaded


def _sweep_forever() -> None:
    while True:
        time.sleep(60)
        try:
            sweep()
        except Exception as e:  # pragma: no cover - keep the sweeper alive whatever happens
            logger.warning(f"Model sweep failed ({type(e).__name__})")


def _start_sweeper() -> None:
    global _sweeper
    if _sweeper is None:
        with _lock:
            if _sweeper is None:
                _sweeper = threading.Thread(target=_sweep_forever, name="model-idle-sweeper", daemon=True)
                _sweeper.start()


def status() -> dict:
    """For the toolkit status endpoint: memory use, budget and which managed models are in memory."""
    s = get_settings()
    now = time.monotonic()
    with _lock:
        loaded = [{"name": x.name, "idle_seconds": int(now - x.last_used), "in_use": x.in_use > 0}
                  for x in sorted(_slots.values(), key=lambda x: x.name) if x.loaded]
    return {"process_mb": round(rss_mb()), "budget_mb": s.model_memory_budget_mb, "idle_unload_seconds": s.model_idle_unload_seconds, "loaded": loaded}


def reset() -> None:
    """Forget every slot (tests)."""
    with _lock:
        _slots.clear()
