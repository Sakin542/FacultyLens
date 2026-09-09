"""Validation for STEP 28 alignment results (deterministic; nothing here trusts model output)."""

from typing import Any, Dict, List, Tuple

from app.schemas.rubric_alignment import ALIGNMENT_STATUSES, ALIGNMENT_WEIGHTS, SCORE_TOLERANCE


class RubricAlignmentValidationError(ValueError):
    """Raised when an alignment result is structurally or numerically inconsistent."""


class RubricAlignmentValidator:
    REQUIRED_FIELDS = ("rubric_criterion_id", "alignment_status", "alignment_score", "explanation")

    @classmethod
    def validate(cls, result: Dict[str, Any], rubric_criteria: List[Dict[str, Any]]) -> Tuple[bool, List[str]]:
        errors: List[str] = []
        if not isinstance(result, dict):
            return False, ["Alignment result must be an object."]

        items = result.get("criterion_alignments")
        if not isinstance(items, list) or not items:
            return False, ["criterion_alignments must be a non-empty list."]

        expected = {int(c["id"]): float(c["max_marks"]) for c in rubric_criteria}
        seen = set()
        weighted_num = 0.0
        unweighted_sum = 0.0

        for idx, item in enumerate(items, start=1):
            if not isinstance(item, dict):
                errors.append(f"Criterion alignment {idx} must be an object.")
                continue
            for field in cls.REQUIRED_FIELDS:
                if field not in item:
                    errors.append(f"Criterion alignment {idx} is missing '{field}'.")
            try:
                cid = int(item.get("rubric_criterion_id"))
            except (TypeError, ValueError):
                errors.append(f"Criterion alignment {idx} has an invalid rubric_criterion_id.")
                continue
            if cid not in expected:
                errors.append(f"Criterion alignment {idx} references an unknown rubric criterion ({cid}).")
                continue
            if cid in seen:
                errors.append(f"Criterion {cid} appears more than once.")
            seen.add(cid)

            status = item.get("alignment_status")
            if status not in ALIGNMENT_STATUSES:
                errors.append(f"Criterion {cid} has an unknown alignment_status '{status}'.")
                continue
            score = item.get("alignment_score")
            if not cls._is_number(score) or abs(float(score) - ALIGNMENT_WEIGHTS[status]) > 1e-6:
                errors.append(f"Criterion {cid} alignment_score does not match its status weight.")
                continue
            if not isinstance(item.get("explanation"), str) or not item["explanation"].strip():
                errors.append(f"Criterion {cid} explanation must be a non-empty string.")
            for list_field in ("evidence", "missing_elements"):
                value = item.get(list_field, [])
                if not isinstance(value, list) or not all(isinstance(v, str) for v in value):
                    errors.append(f"Criterion {cid} {list_field} must be a list of strings.")
            weighted_num += float(score) * expected[cid]
            unweighted_sum += float(score)

        missing = set(expected) - seen
        if missing:
            errors.append("Criterion alignments are missing for rubric criteria: " + ", ".join(str(m) for m in sorted(missing)) + ".")

        if not errors:
            total_marks = sum(expected.values())
            weighted = round(weighted_num / total_marks * 100, 2) if total_marks else 0.0
            unweighted = round(unweighted_sum / len(expected) * 100, 2)
            overall = result.get("overall_alignment_score")
            if not cls._is_number(overall) or abs(float(overall) - weighted) > SCORE_TOLERANCE:
                errors.append(f"overall_alignment_score ({overall}) does not match the mark-weighted score ({weighted}).")
            unw = result.get("unweighted_alignment_score")
            if not cls._is_number(unw) or abs(float(unw) - unweighted) > SCORE_TOLERANCE:
                errors.append(f"unweighted_alignment_score ({unw}) does not match the criterion scores ({unweighted}).")
            if result.get("overall_alignment_status") not in ALIGNMENT_STATUSES:
                errors.append("overall_alignment_status is unknown.")

        return (len(errors) == 0), errors

    @classmethod
    def assert_valid(cls, result: Dict[str, Any], rubric_criteria: List[Dict[str, Any]]) -> None:
        ok, errors = cls.validate(result, rubric_criteria)
        if not ok:
            raise RubricAlignmentValidationError("; ".join(errors))

    @staticmethod
    def _is_number(value: Any) -> bool:
        return isinstance(value, (int, float)) and not isinstance(value, bool)
