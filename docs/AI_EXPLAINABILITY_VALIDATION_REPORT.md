# FacultyLens AI Explainability — Validation Report (STEP 45)

Date: 2026-09-13 · Branch: `feature/ai-explainability` · Explanation version `1.0.0`

## Components evaluated

| Component | `result_type` | Explanation | Evidence | Method disclosed | Confidence handling | Limitations | Review / override | Automated coverage |
|---|---|---|---|---|---|---|---|---|
| Question type | `question_type` | ✔ | cue words (literal) + heuristic confidence | RULE_BASED | value shown only when the rule provides one | ✔ | accept/reject/review/override | Feature + AI-service + Playwright |
| Difficulty | `difficulty` | ✔ | cue counts, word count, sub-clauses | RULE_BASED | Not available | ✔ | override | Feature + AI-service + bash E2E |
| Bloom level | `bloom` | ✔ | leading verb + Bloom verb table | RULE_BASED | Not available | ✔ | override | Feature + AI-service + Playwright + bash E2E |
| Topic | `topic` | ✔ | matched terms from question | EMBEDDING_BASED / RULE_BASED | Not available | ✔ | review | Feature |
| LO alignment | `lo_alignment` | ✔ | score, thresholds, LO text, question text | EMBEDDING_BASED | Not available (distance) | ✔ | override to another LO | Feature + bash E2E |
| CO/PO mapping | `co_po_mapping` | ✔ | similarity, CO text, faculty CO→PO links | EMBEDDING_BASED → HUMAN_CONFIRMED | Not available | ✔ (accreditation caveat) | confirm / reject (STEP 31) | Feature |
| Similarity | `similarity` | ✔ | score `/ 1.00`, thresholds, both texts | EMBEDDING_BASED | Not available | ✔ | accept / “Not a duplicate” | Feature + bash E2E |
| Assessment quality | `assessment_quality` | ✔ | 6 dimensions: score, configured & applied weight, status, finding | RULE_BASED | Not available (deterministic) | ✔ | review | Feature + bash E2E + Playwright |
| Recommendation | `recommendation` | ✔ | stored evidence lines, source, metric | RULE_BASED | Not available | ✔ | accept/dismiss/review (STEP 20) | Feature + bash E2E |
| Rubric | `rubric` | ✔ | criteria, marks, indicators, constraint PASS/FAIL | RULE_BASED / HYBRID | Not available | ✔ (DRAFT until approval) | review | Feature |
| Generated question | `generated_question` | ✔ | constraint checks, detected labels, similar items, source chunks | GENERATIVE / RULE_BASED | Not available | ✔ | review | Feature |
| RAG answer | `rag_answer` | ✔ | sources with page/section or “location unavailable”, relevance | EMBEDDING_BASED (+GENERATIVE) | Not available | ✔ | accept / reject | Feature |
| AI grading | `ai_grading` | ✔ | per-criterion coverage + answer sentences, faculty comparison | HYBRID | Not available | ✔ | review (marks via grading flow) | Feature |
| Inter-grader | `inter_grader` | ✔ (honest “not available”) | — | RULE_BASED | Not available | ✔ | — | Feature |

Explanation coverage: **14 / 14** result types. Evidence coverage: 13 / 14 (inter-grader has no data in this deployment and says so).

## Consistency tests (regression, known failure modes)

| Rule | Test |
|---|---|
| Score 72 explanation must not say 90/91 | `ExplanationValidatorTest::test_score_72_explanation_must_not_say_90`, `test_explainability.py::test_validation_rejects_wrong_score`, `AiExplainabilityTest::test_quality_explanation_states_the_real_score_weights_and_normalization` |
| Similarity 0.88 → “Potential Duplicate”, never “Exact Duplicate” | `test_similarity_0_88_must_be_potential_duplicate_not_exact_duplicate`, `test_similarity_explanation_never_claims_exact_duplicate…`, `explainability.test.tsx` (“never displays exact duplicate”), bash E2E §9 |
| No confidence → “Not available”, never fabricated | `test_bloom_explanation_uses_question_evidence_and_reports_no_fabricated_confidence`, `test_question_type_confidence_is_only_shown_when_the_rule_provides_it`, `ConfidenceIndicator` unit test |
| Competing label asserted → rejected; comparison allowed | `test_bloom_label_contradiction_is_rejected_but_comparisons_are_allowed`, Python `test_validation_allows_mentioning_other_labels_when_actual_is_stated` |
| Unknown LO code in text → rejected | PHP + Python validator tests |
| Contradictory stored `reasoning` is dropped, not displayed | `test_lo_alignment_explanation_shows_actual_score_threshold_band_and_drops_contradictory_reasoning` |
| Stale label (text changed) disclosed | `test_stale_label_is_disclosed…`, Python `test_stored_label_inconsistent_with_current_text_is_disclosed` |
| Evidence excerpts are literal substrings | Python `test_bloom_evidence_is_literal_excerpt_of_question`, bash E2E §5 |
| AI service unavailable → stored label + `evidence_status=unavailable` | `test_explanation_degrades_honestly_when_ai_service_is_unavailable` |
| Generated explanation contradicting draft metadata → replaced | `question_generator.py` validation hook (unit-covered by validator tests) |

## Citation validation

RAG explanations only cite stored `academic_chat_sources`; page/section are shown when present, otherwise “Source location unavailable” (`test_rag_explanation_is_private_to_the_session_owner_and_reports_grounding_state`, `SourceCitation` unit test). Generated-question sources are resolved to real `document_chunks` and filtered by `DocumentPolicy::view`; unresolvable chunks yield “Source support not verified” (`test_generated_question_explanation_lists_constraints_and_flags_unverified_sources`).

## Human review

The STEP 45 explanations were reviewed against the checklist in `docs/AI_EXPLAINABILITY.md` (§Explainability quality check) on the live Docker stack (`backend/tests/e2e_ai_explainability.sh`): factual consistency ✔, evidence relevance ✔, method correctly described ✔, uncertainty disclosed ✔, limitations shown ✔, citations valid ✔, evidence inspectable ✔, override possible ✔, version-aware ✔. The Bloom cue “Compare/analyze” and difficulty factors matched the rule tables; the quality summary matched the stored score to two decimals.

## Defect found during validation

**BUG-013** (P1): alignment `similarity_score` persisted as 0.0000 and similarity matches never persisted because Laravel read AI payload keys that the service does not send (`alignment_score`, `matches`). Fixed in `AnalyzeAssessmentJob` and `AiAnalysisController`; regression test `AnalysisPersistenceFieldMappingRegressionTest`. The transparency rule “do not hide the actual score” exposed the discrepancy immediately (explanation showed `0.00 / 1.00` while findings said 0.60).

## Security tests

* Outsider → 403 on explanation, evidence events, review, override; unknown type → 404 (`test_outsider_cannot_view_explanations…`, bash E2E §11).
* Whitelisted view events only; unsafe metadata keys dropped; `password` never stored (`test_view_events_are_audited_only_for_whitelisted_actions`).
* Prompt-injection resistance: instruction-like question text is flagged, not executed; delimiter spoofing neutralised; validators reject “system prompt”/“ignore previous instructions”/`<<<` (Python `test_instruction_like_question_text_is_flagged_not_executed`, `test_sanitize_untrusted_text_neutralises_delimiters_and_clamps`, `test_validation_rejects_injection_and_secret_wording`).
* No chain-of-thought / secrets: payload assertions for “system prompt”, “model thought” in feature tests, bash E2E and Playwright.

## Privacy tests

* Student data: reviewer/outsider → 403 on `ai_grading` and `inter_grader` (`test_ai_grading_explanation_requires_view_student_data…`, Playwright viewer journey).
* Chat: collaborator → 403 on another user's `rag_answer`.
* Previous questions outside the course → “Not available in your authorization scope”; foreign LO override → 422.
* Historical isolation: v1 alignment explained with v1 thresholds while v2 is current (`test_historical_alignment_keeps_its_own_version_context`).

## Accessibility

Explanation panel: semantic headings, `role="dialog"`/`aria-modal`, labelled close/reload buttons, `aria-expanded`/`aria-controls` disclosures operable with Enter, `role="meter"` for confidence, `role="status"`/`role="alert"` notices, textual labels for every band (no colour-only meaning), `abbr`/`title` explanations for similarity scores. Verified by `explainability.test.tsx` (“accessible structure”) and the Playwright keyboard flow. The pre-existing axe-core check on `/assessments` currently fails on `text-amber-700` contrast introduced by an uncommitted palette change (`sage-100` → `#F1F2EE`), unrelated to STEP 45.

## Performance impact

* Explanations are lazy: zero explanation requests until faculty clicks “Why?” (asserted in Playwright); one request per opened result; in-memory cache per (type, id) invalidated on review/override.
* Cue evidence is a single rule-based AI-service call (≈ 15 ms) cached for 6 h per question text + labels; no generative calls are made for explanations.
* Backend explanation endpoint executes a bounded set of eager-loaded queries (≤ 8 for the heaviest type, `ai_grading`); no N+1 since one explanation is built per request.
* Evaluation status cached 5 min per task.

## Test totals

| Suite | Result |
|---|---|
| Laravel `php artisan test` | 538 passed (4391 assertions) — includes `AiExplainabilityTest` (23), `ExplanationValidatorTest` (5), `AnalysisPersistenceFieldMappingRegressionTest` (1) |
| AI service `pytest` | 271 passed — includes `test_explainability.py` (19); `ruff` clean |
| Frontend `vitest` | 279 passed — includes `explainability.test.tsx` (12); `tsc --noEmit` clean |
| Live bash E2E `backend/tests/e2e_ai_explainability.sh` | passed (12 sections) |
| Playwright `tests/e2e/10-ai-explainability.spec.ts` | 2 passed |
