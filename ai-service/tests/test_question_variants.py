import random

from fastapi.testclient import TestClient

from app.main import app
from app.services.question_variants import QuestionVariantGenerator, reword, vary_numbers

client = TestClient(app)


def test_numbers_change_consistently_and_labels_are_kept():
    q = "CO2: A disk has 4 platters and 512 bytes per sector. If 4 heads read 200 sectors in 2026, find the data read."
    out = vary_numbers(q, None, random.Random(3))
    text = out["question_text"]
    assert "CO2" in text and "2026" in text  # label and year untouched
    changed = {c["from"]: c["to"] for c in out["number_changes"]}
    assert set(changed) >= {"4", "512", "200"}
    # the same original value maps to the same new value everywhere
    assert text.count(changed["4"]) >= 2
    assert all(c["to"].isdigit() for c in out["number_changes"])


def test_derived_answer_numbers_flag_review():
    # A derived value with no worked expression cannot be recomputed: faculty must check it
    q = "A train travels 120 km in 2 hours. Calculate its average speed."
    out = vary_numbers(q, "The train covers 120 km, so its speed is 60 km/h.", random.Random(5))
    assert out["answer_needs_review"] is True
    assert "60" in out["derived_numbers"]
    # echoed inputs are rewritten in the answer too
    assert "120" not in out["expected_answer"]


def test_division_in_the_key_is_recomputed():
    out = vary_numbers("A train travels 120 km in 2 hours.", "Speed = 120 / 2 = 60 km/h", random.Random(5))
    c = {x["from"]: int(x["to"]) for x in out["number_changes"]}
    expected = c["120"] / c["2"]
    shown = f"{expected:.0f}" if expected.is_integer() else f"{expected:.2f}"
    assert out["expected_answer"] == f"Speed = {c['120']} / {c['2']} = {shown} km/h"
    assert out["answer_needs_review"] is False


def test_worked_arithmetic_in_the_key_is_recomputed():
    q = "A disk has 512 bytes per sector. Find the capacity of 200 sectors."
    out = vary_numbers(q, "200 x 512 = 102400 bytes", random.Random(5))
    changed = {c["from"]: int(c["to"]) for c in out["number_changes"]}
    assert out["expected_answer"] == f"{changed['200']} x {changed['512']} = {changed['200'] * changed['512']} bytes"
    assert out["answer_needs_review"] is False


def test_safe_eval_rejects_code():
    from app.services.question_variants import _safe_eval

    assert _safe_eval("(2 + 3) x 4") == 20
    assert _safe_eval("__import__('os')") is None
    assert _safe_eval("9 ^ 99") is None


def test_percentages_stay_within_range_and_decimals_keep_precision():
    out = vary_numbers("A loan of 1500.50 at 90% utilisation", None, random.Random(11))
    changed = {c["from"]: c["to"] for c in out["number_changes"]}
    assert float(changed["90"]) <= 95
    assert len(changed["1500.50"].split(".")[1]) == 2


def test_rule_based_rewording_changes_command_verb():
    text, changed = reword("Explain the working of a B-tree index with an example.", random.Random(1))
    assert changed
    assert not text.startswith("Explain")


def test_generator_without_models_is_deterministic_and_distinct():
    gen = QuestionVariantGenerator()
    a = gen.generate("Calculate the area of a rectangle 12 cm by 7 cm.", "12 x 7 = 84", 3, "both", 42)
    b = gen.generate("Calculate the area of a rectangle 12 cm by 7 cm.", "12 x 7 = 84", 3, "both", 42)
    assert a == b
    texts = [v["question_text"] for v in a["variants"]]
    assert len(texts) == 3 and len(set(texts)) == 3
    # "12 x 7 = 84" is recomputed with each variant's numbers, so the keys stay correct
    for v in a["variants"]:
        left, result = v["expected_answer"].split(" = ")
        x, y = (int(n) for n in left.split(" x "))
        assert int(result) == x * y
        assert v["answer_needs_review"] is False
    assert a["engine"] == "deterministic"


def test_wording_only_question_without_numbers_reports_honestly():
    out = QuestionVariantGenerator().generate("Why?", None, 2, "numbers", 1)
    assert out["variants"] == []
    assert out["message"]


def test_endpoint_returns_variants():
    res = client.post("/api/v1/question-variants", json={
        "question_text": "Find the determinant of a 3 x 3 matrix whose diagonal entries are 2, 5 and 7.",
        "count": 2, "mode": "numbers", "seed": 7,
    })
    assert res.status_code == 200
    body = res.json()
    assert len(body["variants"]) == 2
    assert body["variants"][0]["number_changes"][0].keys() == {"from", "to"}
