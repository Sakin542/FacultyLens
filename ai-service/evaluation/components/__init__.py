"""Component evaluators. Each module exposes ``evaluate(ctx) -> list[ComponentResult]``."""

from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List

from evaluation.common import Catalogue, dump_json


@dataclass
class Context:
    hf: Any
    generation: Any
    text_generation: Any
    catalogue: Catalogue
    gates: Dict[str, Any]
    split: str = "TEST"
    seed: int = 42
    repeats: int = 3
    predictions_dir: Path = Path(".")
    settings: Any = None
    verbose: bool = False
    _prediction_rows: Dict[str, List[Dict[str, Any]]] = field(default_factory=dict)

    def record(self, component: str, row: Dict[str, Any]) -> None:
        self._prediction_rows.setdefault(component, []).append(row)

    def flush_predictions(self) -> Dict[str, int]:
        counts = {}
        for component, rows in self._prediction_rows.items():
            dump_json(self.predictions_dir / f"{component}.json", rows)
            counts[component] = len(rows)
        return counts

    def log(self, msg: str) -> None:
        if self.verbose:
            print(msg, flush=True)
