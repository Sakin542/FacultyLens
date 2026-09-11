# Assessment Blueprint (STEP 37)

The blueprint is a **planning and validation layer** between an assessment and its questions:

```text
Course → Assessment → Blueprint (marks, sections, types, difficulty, Bloom, CO/PO, topics, plan rows)
       → Validate → Faculty review → Finalize (immutable version)
       → Generate (STEP 33) / Select (question bank) → Compare actual questions with blueprint → Faculty decision
```

It never publishes or finalizes the assessment and never changes questions, marks, mappings or Bloom levels.

## Data model

| Table | Purpose |
|---|---|
| `assessment_blueprints` | Versioned plan per assessment (`version`, `status` DRAFT/VALIDATED/FINALIZED/ARCHIVED, `is_current`, totals, `validation_status`, stored `validation_result`, `blueprint_completeness`, `finalized_at`) |
| `assessment_blueprint_sections` | Section A/B/C rows: type, question count, marks per question, total, optional per-section difficulty/Bloom % |
| `assessment_blueprint_constraints` | One row per target: `dimension` (DIFFICULTY, COGNITIVE_LEVEL, LEARNING_OUTCOME, PROGRAM_OUTCOME, TOPIC, QUESTION_TYPE), `target_key`, FK to outcome where relevant, `target_percentage` / `target_count` / `target_marks` |
| `assessment_blueprint_items` | Cross-dimension plan rows (section × CO × PO × topic × type × difficulty × Bloom × count × marks) |

Enums reuse the project's existing values (STEP 33 question types `mcq … analytical`, `easy|medium|hard`, Bloom levels).
Outcome/PO FKs must belong to the blueprint's course / program (checked in the service, 422 otherwise).

## API

| Route | Ability |
|---|---|
| `GET /api/assessments/{assessment}/blueprint` | `view` — current version + validation + coverage + version list + permissions |
| `POST /api/assessments/{assessment}/blueprint` | `edit_assessment` — create v1 (409 if a draft exists; creates a new version after a FINALIZED one) |
| `PUT /api/blueprints/{blueprint}` | `edit_assessment` — replace sections/constraints/items; FINALIZED → new version, ARCHIVED → 409 |
| `DELETE /api/blueprints/{blueprint}` | `edit_assessment` — drafts only (409 for finalized) |
| `POST /api/blueprints/{blueprint}/validate` | `edit_assessment` |
| `POST /api/blueprints/{blueprint}/finalize` | `edit_assessment` — 422 while INVALID |
| `GET /api/blueprints/{blueprint}/coverage` | `view` — target distributions + matrices |
| `GET /api/blueprints/{blueprint}/comparison?sync_recommendations=1` | `view` — targets vs actual questions; optional STEP 14 recommendation sync (editors) |
| `POST /api/blueprints/{blueprint}/generate-questions` | `generate_questions` — creates STEP 33 requests (finalized blueprints only) |
| `POST /api/blueprints/{blueprint}/validate-questions` | `view` — check `question_ids` / `previous_question_ids` against plan rows |

Response shape: `{ blueprint, validation: {status, errors, warnings, recommendations, completeness, totals, time_indicator}, coverage: {distributions, matrices}, versions, permissions }`.
Input is validated by `StoreAssessmentBlueprintRequest` (positive marks/counts, 0–100 percentages, known enums).

## Validation engine (`AssessmentBlueprintValidator`)

Deterministic, no AI:

1. Marks: Σ section marks = total (error: “Allocated marks … Required … Difference”), item marks ≤ total
2. Question count: Σ section counts = total questions; plan rows ≤ total (fewer → warning)
3. Difficulty / Bloom: percentages total 100 (±rounding tolerance) or counts total the question count; percentages → integer allocation by largest remainder; if not exact → warning with a **suggested allocation that requires faculty confirmation**
4. CO: percentages total 100 or marks total the blueprint total; outcomes must belong to the course; “COx has no planned questions” / “limited planned coverage”
5. PO: only when the course has a program (otherwise “PO blueprint is not configured for this course.”); totals 100
6. Topics: marks/counts must not exceed totals; low-coverage warning
7. Question types: count × marks each = total; counts/marks match the blueprint
8. Cross-dimension rows: row totals, course ownership, duplicate combinations, consistency with difficulty/Bloom/CO targets
9. Difficulty deviation from the STEP 13 target (30/50/20) beyond `BLUEPRINT_DIFFICULTY_WARNING_DEVIATION`; “No Analyze-level questions planned”
10. Time **planning indicator**: minutes per mark banded TIGHT / TYPICAL / GENEROUS (configurable), never an official duration claim
11. **Blueprint Completeness** = weighted share of configured planning dimensions (basics, sections, difficulty, cognitive, CO, topics, types, plan rows). It is not the STEP 13 assessment-quality score.

Status: `VALID`, `VALID_WITH_WARNINGS`, `INVALID`. Only non-INVALID blueprints can be finalized.

## Versioning & immutability

Finalized blueprints are never mutated. Updating one archives it (`is_current=false`, status ARCHIVED) and creates version n+1 as a draft (`BLUEPRINT_VERSION_CREATED`). Deleting a draft promotes the latest remaining version back to current.

## Comparison (blueprint ↔ actual questions)

`actualProfile()` reads the assessment's questions: difficulty/Bloom = faculty value with AI value as fallback, CO = `learning_outcome_id` + confirmed STEP 31 question–CO mappings, PO via `co_po_mappings`, topics via AI-detected `ai_topics`, type = `question_type`.
Each configured target is compared with the actual share (count-based for difficulty/Bloom/type, marks-based for CO/PO/topic):
`MATCH` (< 0.5 pt), `CLOSE` (≤ `BLUEPRINT_PERCENTAGE_TOLERANCE`), `MISMATCH`, `NOT_CONFIGURED`. `compliance_percent` = share of configured rows that are MATCH/CLOSE.
`validate-questions` scores individual candidate questions against the closest plan row and lists exactly which constraints failed.

## Integrations

* **STEP 33** — `generate-questions` groups plan rows by CO/PO/topic and creates one generation request per group with per-slot difficulty/Bloom/type/marks (`blueprint` slots). Generated questions stay `DRAFT` until approved; nothing is inserted into the assessment.
* **STEP 14** — `?sync_recommendations=1` creates `pending` recommendations (`source_metric = Assessment Blueprint`) for MISMATCH rows on the current analysis report, deduplicated by title.
* **STEP 36** — `blueprint_compliance` in the analytics overview and the “Assessment Blueprint Compliance” card linking to each blueprint.
* **STEP 18** — `assessment_blueprint` section (version, sections, distributions, comparison, warnings) in the assessment PDF report.

## Audit events

`BLUEPRINT_CREATED`, `BLUEPRINT_UPDATED`, `BLUEPRINT_DELETED`, `BLUEPRINT_VALIDATED`, `BLUEPRINT_FINALIZED`, `BLUEPRINT_VERSION_CREATED`, `BLUEPRINT_QUESTION_GENERATION_STARTED`, `BLUEPRINT_QUESTION_VALIDATED`. No document contents are logged.

## Tests

* Backend: `tests/Feature/AssessmentBlueprintTest.php` (creation/validation, marks/count/percentage errors, warnings, input & cross-course checks, PO, versioning + immutability, authorization/isolation, real-data comparison, question-bank validation, STEP 33 hand-off, analytics/report integration).
* Frontend: `src/tests/components/assessmentBlueprint.test.tsx`.
* E2E: `backend/tests/e2e_assessment_blueprint.sh`.
