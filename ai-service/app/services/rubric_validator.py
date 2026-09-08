"""Rubric validation logic for STEP 25.

Validates the structure and marks integrity of a rubric draft BEFORE it is
returned to Laravel. Laravel performs its own independent validation as well;
the AI service must never emit a rubric whose criterion marks do not sum
exactly to the question total.
"""

from typing import Any, Dict, List, Tuple


class RubricValidationError(ValueError):
    """Raised when a generated rubric fails structural or marks validation."""


class RubricValidator:
    REQUIRED_CRITERION_FIELDS = ("criterion", "description", "max_marks", "sort_order")
    MAX_CRITERIA = 12
    MARK_TOLERANCE = 0.005

    @classmethod
    def validate(cls, rubric: Dict[str, Any], expected_total: float) -> Tuple[bool, List[str]]:
        """Return (is_valid, errors) for the given rubric dictionary."""
        errors: List[str] = []

        if not isinstance(rubric, dict):
            return False, ["Rubric must be a JSON object."]

        title = rubric.get("title")
        if not isinstance(title, str) or not title.strip():
            errors.append("Rubric title is required.")

        criteria = rubric.get("criteria")
        if not isinstance(criteria, list) or len(criteria) == 0:
            errors.append("Rubric must contain at least one criterion.")
            return False, errors

        if len(criteria) > cls.MAX_CRITERIA:
            errors.append(f"Rubric contains too many criteria (max {cls.MAX_CRITERIA}).")

        seen_orders = set()
        running_total = 0.0
        for idx, criterion in enumerate(criteria, start=1):
            if not isinstance(criterion, dict):
                errors.append(f"Criterion {idx} is malformed.")
                continue

            for field in cls.REQUIRED_CRITERION_FIELDS:
                if field not in criterion:
                    errors.append(f"Criterion {idx} is missing required field '{field}'.")

            name = criterion.get("criterion")
            if not isinstance(name, str) or not name.strip():
                errors.append(f"Criterion {idx} must have a non-empty name.")

            description = criterion.get("description")
            if not isinstance(description, str) or not description.strip():
                errors.append(f"Criterion {idx} must have a non-empty description.")

            marks = criterion.get("max_marks")
            if isinstance(marks, bool) or not isinstance(marks, (int, float)):
                errors.append(f"Criterion {idx} has an invalid marks value.")
            elif marks < 0:
                errors.append(f"Criterion {idx} has negative marks.")
            else:
                running_total += float(marks)

            order = criterion.get("sort_order")
            if isinstance(order, bool) or not isinstance(order, int) or order < 1:
                errors.append(f"Criterion {idx} has an invalid sort_order.")
            elif order in seen_orders:
                errors.append(f"Criterion sort_order {order} is duplicated.")
            else:
                seen_orders.add(order)

            indicators = criterion.get("expected_indicators", [])
            if indicators is not None and (
                not isinstance(indicators, list)
                or any(not isinstance(i, str) for i in indicators)
            ):
                errors.append(f"Criterion {idx} has malformed expected_indicators.")

        rubric_total = rubric.get("total_marks")
        if isinstance(rubric_total, bool) or not isinstance(rubric_total, (int, float)):
            errors.append("Rubric total_marks is missing or invalid.")
        elif abs(float(rubric_total) - float(expected_total)) > cls.MARK_TOLERANCE:
            errors.append(
                f"Rubric total_marks ({rubric_total}) does not match the question total ({expected_total})."
            )

        if abs(round(running_total, 2) - round(float(expected_total), 2)) > cls.MARK_TOLERANCE:
            errors.append(
                f"Criterion marks sum to {round(running_total, 2)} but the question total is {expected_total}."
            )

        return len(errors) == 0, errors

    @classmethod
    def assert_valid(cls, rubric: Dict[str, Any], expected_total: float) -> None:
        is_valid, errors = cls.validate(rubric, expected_total)
        if not is_valid:
            raise RubricValidationError("; ".join(errors))
