"""Validation for STEP 27 AI grading suggestions.

Any suggestion that violates the rubric bounds is rejected before it leaves the
service. Laravel re-validates independently; neither side trusts the model.
"""

from typing import Any, Dict, List, Tuple

MARK_TOLERANCE = 0.005


class GradingValidationError(ValueError):
    """Raised when a grading suggestion is structurally or numerically inconsistent."""


class GradingValidator:
    REQUIRED_CRITERION_FIELDS = ("rubric_criterion_id", "suggested_marks", "maximum_marks", "evaluation")

    @classmethod
    def validate(cls, result: Dict[str, Any], rubric_criteria: List[Dict[str, Any]], maximum_marks: float) -> Tuple[bool, List[str]]:
        errors: List[str] = []

        if not isinstance(result, dict):
            return False, ["Grading result must be an object."]

        suggested = result.get("suggested_marks")
        if not cls._is_number(suggested):
            errors.append("suggested_marks must be numeric.")
            suggested = 0.0
        if suggested < -MARK_TOLERANCE:
            errors.append("suggested_marks cannot be negative.")
        if suggested > maximum_marks + MARK_TOLERANCE:
            errors.append(f"suggested_marks ({suggested}) exceed the maximum ({maximum_marks}).")

        result_max = result.get("maximum_marks")
        if not cls._is_number(result_max) or abs(result_max - maximum_marks) > MARK_TOLERANCE:
            errors.append("maximum_marks does not match the rubric total.")

        criterion_results = result.get("criterion_results")
        if not isinstance(criterion_results, list) or not criterion_results:
            errors.append("criterion_results must be a non-empty list.")
            return False, errors

        expected = {int(c["id"]): float(c["max_marks"]) for c in rubric_criteria}
        seen = set()
        criterion_total = 0.0

        for idx, item in enumerate(criterion_results, start=1):
            if not isinstance(item, dict):
                errors.append(f"Criterion result {idx} must be an object.")
                continue
            for field in cls.REQUIRED_CRITERION_FIELDS:
                if field not in item:
                    errors.append(f"Criterion result {idx} is missing '{field}'.")
            cid = item.get("rubric_criterion_id")
            try:
                cid = int(cid)
            except (TypeError, ValueError):
                errors.append(f"Criterion result {idx} has an invalid rubric_criterion_id.")
                continue
            if cid not in expected:
                errors.append(f"Criterion result {idx} references an unknown rubric criterion ({cid}).")
                continue
            if cid in seen:
                errors.append(f"Criterion {cid} appears more than once.")
            seen.add(cid)

            marks = item.get("suggested_marks")
            cap = item.get("maximum_marks")
            if not cls._is_number(marks):
                errors.append(f"Criterion {cid} suggested_marks must be numeric.")
                continue
            if not cls._is_number(cap) or abs(cap - expected[cid]) > MARK_TOLERANCE:
                errors.append(f"Criterion {cid} maximum_marks does not match the rubric ({expected[cid]}).")
            if marks < -MARK_TOLERANCE:
                errors.append(f"Criterion {cid} suggested_marks cannot be negative.")
            if marks > expected[cid] + MARK_TOLERANCE:
                errors.append(f"Criterion {cid} suggested_marks ({marks}) exceed its maximum ({expected[cid]}).")
            evaluation = item.get("evaluation")
            if not isinstance(evaluation, str) or not evaluation.strip():
                errors.append(f"Criterion {cid} evaluation must be a non-empty string.")
            for list_field in ("evidence", "missing_elements"):
                value = item.get(list_field, [])
                if not isinstance(value, list) or not all(isinstance(v, str) for v in value):
                    errors.append(f"Criterion {cid} {list_field} must be a list of strings.")
            criterion_total += float(marks)

        missing = set(expected) - seen
        if missing:
            errors.append("Criterion results are missing for rubric criteria: " + ", ".join(str(m) for m in sorted(missing)) + ".")

        if cls._is_number(suggested) and abs(round(criterion_total, 2) - round(float(suggested), 2)) > MARK_TOLERANCE:
            errors.append(
                f"Criterion marks ({round(criterion_total, 2)}) do not sum to the suggested total ({suggested})."
            )

        for text_field in ("overall_feedback", "evaluation_summary"):
            value = result.get(text_field)
            if not isinstance(value, str) or not value.strip():
                errors.append(f"{text_field} must be a non-empty string.")

        return (len(errors) == 0), errors

    @classmethod
    def assert_valid(cls, result: Dict[str, Any], rubric_criteria: List[Dict[str, Any]], maximum_marks: float) -> None:
        ok, errors = cls.validate(result, rubric_criteria, maximum_marks)
        if not ok:
            raise GradingValidationError("; ".join(errors))

    @staticmethod
    def _is_number(value: Any) -> bool:
        return isinstance(value, (int, float)) and not isinstance(value, bool)
