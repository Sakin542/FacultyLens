# AI Hallucination Policy

**Scope:** every AI-assisted feature in FacultyLens (question analysis, LO alignment, similarity,
quality engine, recommendations, rubric generator, AI grading, answer↔rubric alignment, RAG chat,
question generator, explainability).

**Core rule**

> FacultyLens AI must never present an unsupported AI-generated statement as an established
> academic fact. AI assists faculty; AI does not make final academic decisions.

---

## 1. What counts as a hallucination

Any AI output that asserts something the system has **no authorized evidence for**:

| Never invented | Where enforced |
|---|---|
| Course content, syllabus topics, learning outcomes | RAG chat answers only from retrieved chunks; alignment refuses when an LO has no description (`AlignmentEvidenceError` → HTTP 422 / `UNAVAILABLE`) |
| Previous questions / novelty | Similarity with an empty bank reports **"not evaluated"**, never "all questions are novel" |
| Citations, pages, sections | `[S#]` must resolve to a context block that was sent; fabricated citations reject the generated answer (`AI_CITATION_VALIDATION_FAILED`) and fall back to evidence-only quoting |
| Institutional policy, accreditation, legal compliance | Not in any prompt or template; certainty patterns (`violates accreditation`, `compliant with university policy`) are blocked in generated text |
| Student information, grades | Grading payload contains no student identity; AI marks are stored in `ai_grading_results.suggested_marks`, never `student_answers.awarded_marks` |
| Rubric marks / totals | `sum(criteria.max_marks) == question.marks` enforced by Pydantic on the AI side and `RubricService` on the Laravel side; question marks are never rewritten |
| Model accuracy / confidence | No `confidence` field exists on chat/grading/rubric responses; "99% accurate" style text is rejected; UI shows **Confidence unavailable** unless a validated value in [0,1] exists |

## 2. Required behaviour when evidence is missing

| Situation | Required response |
|---|---|
| No retrieved chunks / none above relevance threshold | `INSUFFICIENT_EVIDENCE_TEXT`, `grounded=false`, `evidence_status=INSUFFICIENT`, `sources=[]` |
| Chunks retrieved but no lexical/semantic support for the question | same as above (extractive engine declines) |
| LO code present, description missing | "Unable to evaluate alignment because the learning outcome description is unavailable." — reported as `NOT_EVALUATED`, never `NOT_COVERED`/`NOT_ALIGNED` |
| No previous questions | "No previous questions available for comparison. Similarity against historical questions was not evaluated." |
| Topic not in course material and grounding required | `status=insufficient_source_material`, zero drafts, Laravel marks request FAILED with honest message |
| Two documents disagree on a number the question asks about | `evidence_status=CONFLICTING`; both values and sources shown; no value chosen; **Faculty review required** |

## 3. Unsupported certainty

Generated text is scanned (`app/services/safety.py::UNSUPPORTED_CERTAINTY_PATTERNS`,
`App\Services\AiSafetyService::unsupportedCertainty`). A hit on a grounded chat answer:

* AI service: rejects the generative answer, logs `AI_HALLUCINATION_DETECTED`, falls back to
  quoting document sentences.
* Laravel: records `AI_HALLUCINATION_DETECTED` in `audit_logs` with a claim count (never the text).

Preferred wording exists for every deterministic result — e.g. *"Potential duplicate based on
semantic similarity"*, *"Review recommended based on the available evidence"*.

## 4. Deterministic-calculation protection

LLM text may **explain** but never **produce** these values:

| Metric | Authority |
|---|---|
| Quality score | `AssessmentQualityEngine` (STEP 13) |
| Similarity / duplicate status | MiniLM cosine + `threshold_bands.py` |
| LO alignment level | cosine + thresholds |
| Rubric / grading marks | `GradingEngine` arithmetic, validated by `GradeAnswerResponse` |
| Answer↔rubric alignment % | `RubricAlignmentAnalyzer` |
| Student performance | finalized faculty marks only (STEP 30) |

`validate_explanation()` (STEP 45) rejects explanation text whose numbers contradict the structured
facts and substitutes `deterministic_fallback()`.

## 5. Confidence

* `confidence ≠ correctness`. Any displayed confidence must come from a validated evaluator
  (STEP 44 `EvaluationStatusResolver`) and is labelled "model-reported, not accuracy".
* `safety.validate_confidence()` / `AiSafetyService::normalizeConfidence()` return `None` for
  non-numeric, out-of-range, NaN or ambiguous values → UI renders **Confidence unavailable**.

## 6. Persistence rules

* Invalid AI output → **not saved**, status `FAILED`, audit `AI_RESULT_REJECTED`.
* Incomplete AI output → never presented as complete (`generation_status`, `grading_status`).
* AI service failure → `AI_SERVICE_FAILURE` audit, user-facing "AI analysis unavailable. Your
  academic data has not been altered." No placeholder result is created.

## 7. Verification

Enforced by `ai-service/tests/safety/test_hallucination.py`, `test_unsupported_claims.py`,
`test_grounding.py`, the dataset cases `SAFE-HAL-*`, `SAFE-CON-*`, and Laravel
`tests/Feature/Safety/*`. See `docs/AI_SAFETY_TEST_MATRIX.md`.
