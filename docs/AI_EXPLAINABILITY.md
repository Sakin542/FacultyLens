# FacultyLens AI Explainability

> **AI assists. Faculty decides.** Every AI-assisted result in FacultyLens can be inspected, questioned, verified, accepted, rejected or overridden without understanding the internals of the underlying model.

## Purpose

STEP 45 makes AI results understandable to faculty by answering, for every important AI output:

| Question | Answer surfaced by FacultyLens |
|---|---|
| What did the AI find? | `result` — label, score, display value |
| Why did it reach this result? | `explanation.summary` — one concise, evidence-based sentence |
| What evidence was used? | `evidence[]` — literal question excerpts, scores, LO text, criteria, retrieved passages |
| How strong is the evidence? | `confidence` (only when the method produces one), thresholds, `evidence_status` |
| What model/rules produced it? | `method.type` + `model` (name, version, prompt version, embedding model, rule version) |
| What are the limitations? | `limitations[]` per component |
| Can faculty review or override it? | `review` — actions, override options, latest decision, history |

The system explains **the result, not a hidden reasoning process**. It never exposes system prompts, chain-of-thought, model internals, API keys or secrets.

## Explainability principles

Every AI-assisted result is **understandable, evidence-based, traceable, reviewable, uncertain where appropriate, version-aware and faculty-controlled**. Concretely:

* Explanations are **derived from stored results + existing rules** (deterministic). No LLM is asked to explain or recalculate anything.
* Evidence is **literal**: cue words are substrings of the question; scores are the stored scores; citations point to real chunks.
* Wording is always “FacultyLens classified / estimated / measured …”, never “the model thought …” or “the AI knows …”.
* Confidence is shown only when the method produces one; otherwise **“Not available”** — never fabricated.
* Similarity is displayed as **`0.88 / 1.00`** (cosine similarity), never as a probability or an “exact duplicate”.
* Historical analyses keep their own context (`analysis_report_id`, `analysis_version`, thresholds recorded in `findings`).

## Supported AI components

| `result_type` | Underlying record (`id`) | Method | Overridable | Evaluation task (STEP 35/44) |
|---|---|---|---|---|
| `question_type` | `questions` | RULE_BASED | ✔ (faculty `question_type`) | QUESTION_CLASSIFICATION |
| `difficulty` | `questions` | RULE_BASED | ✔ (`difficulty_level`) | DIFFICULTY_CLASSIFICATION |
| `bloom` | `questions` | RULE_BASED | ✔ (`cognitive_level`) | BLOOM_CLASSIFICATION |
| `topic` | `questions` | EMBEDDING_BASED / RULE_BASED | review only | — |
| `lo_alignment` | `question_learning_outcome_alignments` | EMBEDDING_BASED | ✔ (`questions.learning_outcome_id`) | LO_ALIGNMENT |
| `co_po_mapping` | `question_co_mappings` | EMBEDDING_BASED → HUMAN_CONFIRMED | accept → CONFIRMED / reject → REJECTED (STEP 31 flow) | — |
| `similarity` | `question_similarity_matches` | EMBEDDING_BASED | accept / “Not a duplicate” | SIMILARITY |
| `assessment_quality` | `analysis_reports` | RULE_BASED | review only | — |
| `recommendation` | `recommendations` | RULE_BASED | accept / dismiss / review via STEP 20 feedback | — |
| `rubric` | `rubrics` | RULE_BASED / HYBRID / HUMAN_CONFIRMED | review only (approve in rubric editor) | RUBRIC_GENERATION |
| `generated_question` | `generated_questions` | GENERATIVE / RULE_BASED | review only (approve in generator) | QUESTION_GENERATION |
| `rag_answer` | `academic_chat_messages` (ASSISTANT) | EMBEDDING_BASED (+GENERATIVE) | accept / reject | DOCUMENT_CHAT |
| `ai_grading` | `ai_grading_results` | HYBRID | review only (marks via grading workflow) | GRADING_ASSISTANCE |
| `inter_grader` | `assessments` | RULE_BASED (descriptive) | — | — |

## Explanation schema

`GET /api/ai-results/{type}/{id}/explanation` returns:

```json
{
  "result_type": "bloom",
  "result_id": 836,
  "result": { "label": "ANALYZE", "score": null, "display": "Analyze" },
  "explanation": {
    "summary": "FacultyLens classified this question at the ANALYZE level because it uses Analyze-level directive wording (\"Compare\", \"analyze\").",
    "details": [ { "label": "Faculty value", "value": "Understand" } ]
  },
  "evidence": [
    { "type": "question_text", "label": "Question wording: leading directive verb", "text": "\"Compare\"" }
  ],
  "evidence_status": "available",
  "method": { "type": "RULE_BASED", "label": "FacultyLens deterministic rules", "description": "...", "components": ["Leading directive verb", "Bloom-level verb table"] },
  "model": { "name": "facultylens-question-analyzer", "version": "1.0.0", "prompt_version": null, "embedding_model": null, "rule_version": "step10-rules-1.0.0" },
  "confidence": { "available": false, "value": null, "note": "Confidence is not available for this result." },
  "limitations": ["Bloom classification can involve expert judgment; ..."],
  "related": { "links": [{ "label": "View question", "type": "question", "id": 836 }], "analysis_report_id": 145, "analysis_version": 1, "is_current": true },
  "evaluation": { "status": "EVALUATED", "label": "Evaluated", "task": "BLOOM_CLASSIFICATION", "headline_metric": "macro_f1", "headline_value": 0.83 },
  "review": { "overridable": true, "can_review": true, "actions": ["ACCEPTED","REJECTED","REVIEWED","OVERRIDE"], "override_options": ["REMEMBER","UNDERSTAND","APPLY","ANALYZE","EVALUATE","CREATE"], "override_reasons": [...], "latest": null, "history_count": 0, "faculty_value": "Understand" },
  "version": { "explanation_version": "1.0.0", "generated_at": "..." },
  "disclaimer": "AI assists. Faculty decides. This result is AI-assisted and should be reviewed."
}
```

`evidence_status` values: `available`, `unavailable` (AI service unreachable — stored result shown unchanged), `stale` (underlying data changed since the result), `none`, `partial`.

Implementation: `backend/app/Services/Explainability/ExplanationBuilder.php` (structure), `ExplanationService.php` (entry point), `Explainers/*` (one per component), `config/ai_explainability.php` (types, limitations, evaluation-task map, override reasons, audit actions).

## Evidence model

Explanations are generated on demand from existing records, so **no duplicate `ai_explanations` table exists**. Evidence items carry `type`, `label`, `text`, `score` and optional source pointers (`source_type`, `source_id`, `document_id`, `document_page`, `chunk_id`, `meta`).

Faculty decisions are persisted in **`ai_result_reviews`** (`user_id`, `ai_result_type`, `ai_result_id`, `action` ∈ ACCEPTED/REJECTED/REVIEWED/OVERRIDDEN, `ai_value` snapshot, `override_value`, `override_reason`, `comment`, `analysis_report_id`, `course_id`, `explanation_version`). Each decision also emits an `ai_improvement_signals` row (`source = explainability`, STEP 20 architecture; FKs relaxed to nullable). Signals never retrain the model automatically.

Cue-word evidence for question labels is fetched lazily from the AI service (`POST /api/v1/explain-question`, rule tables only) and cached (`ai_explainability.cue_cache_ttl`) per question text + labels.

## Confidence handling

* Only the rule-based question-type classifier produces a heuristic confidence; it is shown as “Available: 87 %” with the note *“It does not guarantee that the result is correct.”*
* Difficulty, Bloom, similarity, alignment, quality, recommendations, rubrics, grading and RAG report **“Not available”** with a reason (e.g. “Similarity is a distance measure, not a confidence or probability.”).
* Confidence ≠ correctness is stated in the UI note every time a value is shown.

## Model transparency

Every explanation exposes what applies: engine name/version, prompt version (chat, question generation), embedding model (`sentence-transformers/all-MiniLM-L6-v2` by default), rule version (`step10-rules-1.0.0`), the analysis version, and the STEP 35 evaluation status (`EVALUATED`, `EVALUATION_AVAILABLE`, `LIMITED_EVALUATION_DATA`, `NOT_EVALUATED`) with the headline metric only — no raw benchmark data. HF tokens, API keys and credentials are never part of the payload.

Method types: `RULE_BASED`, `MODEL_BASED`, `EMBEDDING_BASED`, `HYBRID`, `GENERATIVE`, `HUMAN_CONFIRMED`.

## Rule-based explanations

Deterministic components are explained from calculation metadata, never by an LLM:

* **Difficulty / Bloom / type** — the STEP 10 rule tables are re-run on the question text; the cue words that fired are returned as literal excerpts, together with the factors actually used (cue counts, word count, sub-clauses). If the current text no longer yields the stored label the explanation is marked `stale`.
* **LO alignment / similarity** — stored cosine score, the thresholds recorded with that analysis (`findings.alignment.thresholds`, `findings.similarity.thresholds`; config fallback), the band rule (`≥ 0.70 STRONG · 0.50–<0.70 WEAK · < 0.50 NOT ALIGNED`; `≥ 0.85 / 0.70 / 0.50` for similarity) and the compared texts.
* **Assessment quality** — overall score, per-dimension score/configured weight/applied weight/status, an explicit statement when unavailable dimensions were excluded and weights normalized, and the difficulty target-vs-actual table with percentage-point differences.
* **Recommendations** — issue, softened suggestion, source analysis, metric, stored evidence lines, “Generated by FacultyLens Recommendation Engine v1”, link to the underlying analysis.
* **Rubrics** — criteria, marks, expected indicators and the deterministic constraint check (Σ criterion marks = rubric total = question marks → PASS/FAIL).
* **Generated questions** — requested constraints, per-constraint PASS/FAIL/NOT_CHECKED, analyzer-detected labels, similar existing questions, source chunks (or “Source support not verified”).

## LLM-based explanations

Generative text is only surfaced where it already exists (RAG answers, generated-question rationales, optional rubric/grading refinements) and is always **validated against structured facts** before display:

* `ai-service/app/services/explainability.py::validate_explanation` and `backend/app/Services/Explainability/ExplanationValidator.php` reject text that states a number not matching a known score, asserts a competing label (Bloom, difficulty, similarity band, alignment band), references an outcome code outside the result, or uses forbidden black-box wording (“exact duplicate”, “100 % certain”, “the model thought”, “system prompt”, …).
* On failure the text is **not displayed**; a deterministic fallback is used (`deterministic_fallback`, or the explainer's own summary). Generated question explanations that contradict the draft's metadata are replaced in `question_generator.py` with a warning.
* Free-text `reasoning` stored by earlier analyses is passed through the same validator before it is shown as an “Analysis note”.

## RAG citations

`rag_answer` explanations list every stored source (`academic_chat_sources`): document name, `Page n · Section: …` when known, otherwise **“Source location unavailable”** (never an invented page), the retrieval relevance score, and the excerpt. Grounding state is reported as `WELL_SUPPORTED` (≥ 2 cited passages), `PARTIALLY_SUPPORTED` (1) or `INSUFFICIENT_EVIDENCE`, reusing the STEP 44 grounding terminology. Chat explanations are private to the session owner.

## AI grading explainability

`ai_grading` shows question → rubric → **AI suggested `7 / 10`** → per-criterion coverage (`STRONG / PARTIAL / LIMITED / NOT_ADDRESSED`) with the best-matching answer sentences and missing elements → **Faculty final `8 / 10`** → **Difference `+1 mark`**. The suggestion is never finalized here; marks change only in the grading workflow. Access requires `view_student_data`. Stale results (answer changed) are flagged. Inter-grader explanations describe the FacultyLens Agreement Indicator honestly as “not available in this deployment” rather than fabricating values.

## Faculty override

`POST /api/ai-results/{type}/{id}/review` `{action: ACCEPTED|REJECTED|REVIEWED, comment?}` and `POST /api/ai-results/{type}/{id}/override` `{value, reason, comment?}`:

* Overrides write **only faculty-controlled fields** (`questions.question_type|difficulty_level|cognitive_level|learning_outcome_id`); `ai_*` columns and alignment rows are never modified, so the AI value stays visible for reference.
* Override reasons: `AI_CLASSIFICATION_INCORRECT`, `INSUFFICIENT_CONTEXT`, `COURSE_SPECIFIC_INTERPRETATION`, `ACADEMIC_JUDGMENT`, `OTHER`.
* Recommendation reviews delegate to `RecommendationFeedbackService` (STEP 20); CO mapping decisions delegate to `CoPoMappingValidatorService::decideQuestionMapping` (STEP 31).
* Review requires the component's ability (`edit_question`, `approve_recommendation`, `approve_rubric`, `edit_course`, `view_student_data`, …); viewing requires `view_analysis` (or `view`, `generate_questions`, `view_student_data` as appropriate). Reviewers/viewers see explanations but receive `review.actions = []`.

## Audit logging

Recorded through `AuditLogService` with `user`, `entity`, `action`, timestamp, `ai_result_type`, `ai_result_id`, `analysis_report_id`, `course_id`, `explanation_version`:

`AI_EXPLANATION_VIEWED` (server-side on every GET), `AI_RESULT_ACCEPTED`, `AI_RESULT_REJECTED`, `AI_RESULT_REVIEWED`, `AI_RESULT_OVERRIDDEN`; client-reported `AI_RESULT_VIEWED`, `AI_EVIDENCE_VIEWED`, `AI_SOURCE_OPENED` via `POST /api/ai-results/{type}/{id}/events` (whitelisted actions and metadata keys only). Events appear in the course activity feed. `AI_FEEDBACK_SUBMITTED` is covered by the existing STEP 20 `RECOMMENDATION_REVIEWED` entry.

## Privacy

* Authorization is resolved per explainer through `CourseAccessService` (STEP 34 matrix) or session ownership (chat).
* Student answers and grading evidence require `view_student_data`; reviewers/viewers get 403.
* Previous questions and document chunks are only quoted when they belong to the viewer's authorized course/documents (`DocumentPolicy::view`); otherwise “Not available in your authorization scope” / “Source support not verified”.
* Audit metadata is sanitized by `AuditLogService` (question text, answer text, e-mail, tokens are redacted).

## Security

* Retrieved documents, previous questions and student answers are **untrusted data**: excerpts are delimiter-neutralised (`<<<`/`>>>`), clamped, and instruction-like text is flagged (`untrusted_content_detected`) and never executed.
* No prompts, chain-of-thought, model credentials or environment values are included in any payload; forbidden wording is rejected by the validators.
* Unknown result types → 404; IDs are resolved through the owning course, so cross-course IDOR returns 403.
* Client events are whitelisted; arbitrary actions/metadata are rejected (422) and never stored.

## Known limitations

* Difficulty, Bloom and question type are rule-based estimates; evidence shows which cue words fired, not pedagogical intent.
* Cosine similarity is a distance measure; STRONG/WEAK/duplicate bands are configured thresholds, not probabilities.
* Cue-word evidence requires the AI service; when unreachable the explanation degrades to the stored label with `evidence_status = unavailable`.
* Historical reports created before STEP 45 may lack per-dimension quality details (`evidence_status = partial`).
* Inter-grader consistency (STEP 29) is not deployed; its explanation states this rather than computing agreement.
* Evaluation status summarises the latest STEP 35 run per task; components without a task show “Not evaluated yet”.
