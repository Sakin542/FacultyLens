# AI Evaluation & Model Performance (STEP 35)

FacultyLens answers **"How well is FacultyLens AI actually performing?"** with a monitoring layer that is
kept strictly separate from production analysis.

```text
PRODUCTION AI → AI Output → Evaluation Dataset (faculty-validated ground truth) → Evaluation → Metrics → Report
```

Evaluation **never** retrains a model, changes production thresholds or prompts, or promotes a model. Comparison and
regression detection are informational; faculty/admin judgement remains authoritative.

## Architecture

| Layer | Component |
|---|---|
| Config | `backend/config/ai_evaluation.php` (tasks, labels, thresholds, size categories, quality gates, limitations) |
| Storage | `ai_models`, `ai_prompt_versions`, `ai_evaluation_datasets`, `ai_evaluation_examples`, `ai_evaluation_runs`, `ai_evaluation_results`, `ai_evaluation_predictions`, `ai_evaluation_ratings` |
| Coordination | `App\Services\AiEvaluationService` (datasets, validation, run lifecycle, overview, history) |
| Task evaluators | `App\Services\AiEvaluation\{Classification,Alignment,Similarity,Rubric,Grading,Rag,QuestionGeneration}Evaluator`, `EvaluationReportService` (gates, regression, comparison, limitations, CSV) |
| Async | `App\Jobs\RunAiEvaluationJob` (database queue; retried; idempotent upserts keyed by `run_id + example_id`) |
| Inference | Existing FastAPI endpoints (batch question analysis, embeddings, rubric, grading, alignment, chat, generation) plus `GET /api/v1/evaluation/models` inventory |
| API | `/api/ai/evaluation/*` (`AiEvaluationController`) |
| UI | `frontend/src/pages/AiEvaluation.tsx` at `/ai-evaluation`, components in `frontend/src/components/aiEvaluation/` |
| Report | `resources/views/reports/ai-evaluation-pdf.blade.php` (dompdf), CSV and JSON exports |

Laravel coordinates; FastAPI performs inference. No model-loading logic is duplicated — evaluators call the same
`AiService` methods the production features use, in batches of `AI_EVALUATION_BATCH_SIZE`.

## Model registry and prompt versions

`GET /api/ai/evaluation/models?sync=1` reads the AI-service inventory and upserts `ai_models` (name, provider, type, task,
version, non-secret configuration) and `ai_prompt_versions` (feature, version, prompt hash). The MiniLM model is registered
as an **embedding/similarity** model, never as a text generator. Registry changes emit `AI_MODEL_REGISTERED`.

## Datasets and ground truth

A dataset has a task, version, source (`FACULTY_VALIDATED`, `SYNTHETIC`, `IMPORTED`, `PRODUCTION_SAMPLE`), split
(`ALL | TRAIN | VALIDATION | TEST`) and examples with `input_data` and `expected_output`. Ground truth must be human/
institutionally validated; AI output is never used as ground truth for another AI task. The dashboard emphasises
held-out **TEST** data. Duplicate examples (by fingerprint of `input_data`) are skipped on import.

### Example shapes

| Task | `input_data` | `expected_output` |
|---|---|---|
| QUESTION_CLASSIFICATION | `{question}` | `{expected_type}` |
| DIFFICULTY_CLASSIFICATION | `{question}` | `{expected_difficulty}` |
| BLOOM_CLASSIFICATION | `{question}` | `{expected_cognitive_level}` |
| LO_ALIGNMENT | `{question, learning_outcome}` | `{expected_alignment: STRONG\|WEAK\|NOT_ALIGNED}` |
| SIMILARITY | `{question_a, question_b}` | `{expected_relationship}` |
| RUBRIC_GENERATION | `{question, total_marks, ...}` | `{ratings{dimension:1–5}, decision}` |
| GRADING_ASSISTANCE | `{ai_marks}` or `{answer, question, rubric}` + grouping fields | `{faculty_marks}` |
| ANSWER_RUBRIC_ALIGNMENT | `{ai_criterion_statuses}` or `{answer, question, rubric}` | `{criterion_statuses}` |
| DOCUMENT_CHAT | `{question, documents[{name,text,page}]}` | `{answer_present, expected_source, expected_keywords, injection}` |
| QUESTION_GENERATION | generation constraints | `{}` (constraints are validated by the STEP 33 pipeline) |

### Validation

`POST /datasets/{id}/validate` checks missing inputs/labels, invalid labels, duplicates, conflicting labels, malformed
structures and unsupported tasks, and stores a report (totals, label distribution, size category, small-dataset warning).
An invalid dataset **cannot** be run (`422`).

## Evaluation workflow

```text
create dataset → add examples → validate → POST /datasets/{id}/run (202, PENDING)
→ queue job → batch inference → per-example predictions → task metrics → gates + regression
→ persist results (transaction only around writes) → COMPLETED (or FAILED with failure_reason)
```

Runs are never marked completed when the AI service fails; the dataset returns to `READY` and `AI_EVALUATION_FAILED` is
audited. Retrying a job upserts predictions/results, so duplicate rows cannot appear.

## Metrics

* **Classification** (question type, difficulty, Bloom): accuracy, per-class precision/recall/F1/support, macro & weighted F1, confusion matrix. Macro F1 is the headline metric and averages over classes that occur in the data.
* **LO alignment**: same, plus STRONG/WEAK/NOT_ALIGNED precision/recall/F1 at production STEP 11 thresholds.
* **Similarity**: precision/recall/F1 for duplicates and similar pairs at the production STEP 12 thresholds (0.85/0.70/0.50) plus an evaluation-only threshold sweep. Production thresholds are not modified.
* **Rubric generation**: marks-validity rate (Σ criteria = total), faculty 1–5 ratings (mean/median/SD per dimension), acceptance/revision/rejection rates.
* **Grading assistance**: MAE, RMSE, MAPE, mean signed error, exact/±0.5/±1/±2 agreement, error grouped by question type/difficulty/Bloom (no sensitive personal attributes are inferred).
* **Answer–rubric alignment**: criterion-level precision/recall/F1, false positives/negatives, overall agreement.
* **Document chat (RAG)**: answer-supported rate, citation coverage/accuracy, correct-refusal and false-refusal rates, unsupported-answer rate, prompt-injection leak rate.
* **Question generation**: constraint satisfaction rate, generation completeness, per-constraint satisfaction.

Structured error types: `WRONG_CLASS`, `WRONG_DIFFICULTY`, `WRONG_BLOOM_LEVEL`, `WRONG_ALIGNMENT`, `FALSE_DUPLICATE`,
`MISSED_DUPLICATE`, `LARGE_ERROR`, `MARKS_MISMATCH`, `UNSUPPORTED_CLAIM`, `WRONG_CITATION`, `INJECTION_LEAK`,
`CONSTRAINT_VIOLATION`, `INFERENCE_ERROR`, `OTHER`.

## Quality gates, regression, comparison

Gates are configured per task (`AI_EVAL_GATE_*` env vars) and yield `PASSED`, `PASSED_WITH_WARNINGS` (e.g. small dataset,
missing gate metric) or `FAILED`. A run whose headline metric is worse than the previous completed run on the same task
(same owner) is flagged **Performance regression detected**. `GET /compare?run_a&run_b` returns per-metric deltas with
improved/degraded/unchanged directions. None of these actions deploy, roll back or select a model.

Dataset size categories are reporting labels only: `< 30 VERY_LIMITED`, `30–99 LIMITED`, `100–499 MODERATE`, `500+ LARGE`.

## Faculty interaction signals

The overview aggregates real review decisions (recommendation feedback, generated-question approvals/rejections/edits/
regenerations, rubric statuses, AI-grading decisions) for courses the user can access. These are presented as
*interaction signals*, not accuracy metrics.

## Reports and export

`GET /runs/{id}/report` (JSON) and `GET /runs/{id}/export?format=pdf|csv|json` include executive summary, model and
dataset information, methodology, metrics, gates, error analysis, known limitations and recommendations. Exports are
audited (`AI_EVALUATION_EXPORTED`) and never include raw student answers.

## Security and privacy

* All routes require Sanctum auth. Datasets and runs are visible to their creator (and admins per existing RBAC); other
  faculty receive `403`/`404`.
* Course-scoped datasets are authorised through `CourseAccessService`.
* Example inputs shown in error analysis are truncated; student identity is never stored in evaluation tables; logs
  contain no raw inputs or secrets.
* Adversarial documents in RAG datasets test that document text is treated as untrusted content.

## Known limitations

* Ground-truth labels are faculty-validated and may contain human disagreement.
* Evaluation dataset sizes may be limited; results may not generalise across disciplines.
* Generative quality (rubrics, questions, chat) includes subjective dimensions.
* Faculty grading disagreement does not establish a single correct grade.
* Metrics are engineering monitoring signals, not institutional or accreditation validity claims.

## Testing

* Laravel: `tests/Unit/ClassificationEvaluatorTest.php`, `tests/Feature/AiEvaluationTest.php` (validation blocking,
  manually verified classification/similarity/grading/RAG/QGen metrics, regression + comparison, failure handling,
  privacy, exports + audits).
* FastAPI: `tests/test_evaluation_inventory.py`.
* Frontend: `src/tests/components/aiEvaluation.test.tsx`.
* E2E: `backend/tests/e2e_ai_evaluation.sh` (login → dataset → validate → run → metrics/confusion/errors → compare → export).

## STEP 44 — benchmark datasets and measured accuracy

STEP 44 adds curated ground-truth datasets, a Python harness (`ai-service/evaluation/`, `python -m evaluation.run`) that
evaluates every component in-process — including topic detection, top-K retrieval, RAG grounding/citations,
assessment quality, recommendations and consistency, which have no STEP 35 evaluator — and an artisan command
(`php artisan ai-evaluation:import <dir> --run --sync`) that persists the same benchmarks through this pipeline so
they appear on `/ai-evaluation`. Measured results, error analysis, limitations and regression gates are in
[AI_ACCURACY_EVALUATION_REPORT.md](AI_ACCURACY_EVALUATION_REPORT.md).
