# Assessment Versioning (STEP 38)

FacultyLens preserves assessment history through immutable assessment versions. The parent `assessment` is the academic identity;
each `assessment_version` is one concrete state of it (metadata + question snapshot + blueprint snapshot + rubric references).

```text
Assessment
   ├── v1.0  FINALIZED → ARCHIVED (superseded)
   ├── v2.0  FINALIZED (current)
   ├── v2.1  DRAFT (minor: wording)
   └── v3.0  DRAFT (restored from v1.0)
```

**Never modify history to represent the latest assessment. Create a new version instead.**

## Lifecycle

| Status | Editable | Notes |
| --- | --- | --- |
| `DRAFT` | yes | created by `POST /assessments/{a}/versions`, `restore`, or by editing an `IN_REVIEW` version |
| `IN_REVIEW` | yes (returns to DRAFT) | `POST /assessment-versions/{v}/submit-review` — validation stored |
| `APPROVED` | no | `POST …/approve` — blocked when validation is `INVALID` |
| `FINALIZED` | no | `POST …/finalize` — strict validation; the previously finalized version becomes `ARCHIVED` |
| `ARCHIVED` | no | `POST …/archive` or automatic; kept forever, restorable |

A version that any `student_submissions` row references is immutable regardless of status (409).

## Numbering and labels (deterministic, server-side)

- `version_number` = `max(version_number) + 1` per assessment (unique index; archived numbers are never reused).
- `version_type = MAJOR` → `v{max major + 1}.0`; `MINOR` → `v{source major}.{max minor + 1}`.
- Client-supplied numbers/labels are ignored. A `change_summary` is required once a version exists (never generated).
- The comparison endpoint additionally reports a `detected_change_type` (`MAJOR` when questions were added/removed or
  `structural_fields` changed, `MINOR` for wording/instructions, `NONE`).

## Snapshots

- **Questions** (`assessment_version_questions`): text, type, marks, difficulty, Bloom, topic, CO/LO, PO, expected answer,
  `original_question_id` (identity for change tracking) and a `rubric_snapshot` (`rubric_id`, `rubric_version`, criteria).
  v1.0 snapshots the live `questions` table; later versions clone their source. Editing a draft replaces the rows wholesale;
  `sync_from_assessment: true` re-snapshots the live questions.
- **Blueprint** (`assessment_version_blueprints`): blueprint id/version/status, totals and percentage distributions
  (difficulty, Bloom, CO, PO, topic, question type), sections and constraints. Attach a specific STEP 37 blueprint version with
  `blueprint_id` on `PUT`. Later blueprint edits never rewrite the snapshot.
- **Content hash** (`content_hash`): SHA-256 of the normalized question rows, used to attach analyses to the right version.

Finalizing never writes to the live `questions` table (student answers cascade from it); versions are a protected parallel layer.

## Integrations

| Step | Behaviour |
| --- | --- |
| 13/19 Analysis & history | `analysis_reports.assessment_version_id` + `version_content_hash` are set when a report completes (version whose snapshot equals the live question set, else the working version). `GET /assessment-versions/{v}/analysis` and the history API expose `CURRENT` / `STALE`. |
| 18 Reports | `assessment.version_label` / `version_status` and an `assessment_version` block (blueprint version, analysis version, finalized at) appear in the JSON and PDF; `assessment_reports.assessment_version_id` is stored. |
| 25 Rubrics | Approved rubric reference + criteria are snapshotted per question. |
| 26–30 Submissions, grading, performance | New submissions store `assessment_version_id` (current = latest FINALIZED, else working version); answers store `assessment_version_question_id`. `GET /submissions/{id}` renders the historical question wording (`current_question_text` shows the live one). Grades are never recalculated. |
| 33 Generator | Generated questions never touch a finalized version; add them to the live assessment or a draft version. |
| 36 Analytics | Assessment rows carry `current_version`, `version_count`, `historical_version_ids`. |
| 37 Blueprint | Blueprint snapshot + finalization compliance checks (`BLUEPRINT_QUESTION_COUNT`, `BLUEPRINT_TOTAL_MARKS`, deviation warnings). |

## API

```text
GET    /api/assessments/{assessment}/versions
POST   /api/assessments/{assessment}/versions            {based_on_version_id?, version_type?, change_summary}
GET    /api/assessments/{assessment}/versions/{version}
PUT    /api/assessment-versions/{version}                 {title?, instructions?, total_marks?, questions?[], sync_from_assessment?, blueprint_id?}
POST   /api/assessment-versions/{version}/submit-review | approve | finalize | archive | validate
POST   /api/assessment-versions/{version}/restore         {change_summary?, version_type?}
GET    /api/assessment-versions/{version}/compare/{otherVersion}
GET    /api/assessment-versions/{version}/analysis
GET    /api/assessment-versions/{version}/blueprint
```

Authorization uses the STEP 34 course matrix: members (`view`) may read and compare; OWNER/EDITOR (`edit_assessment`) may
create, edit drafts, review, approve, finalize, archive and restore. Cross-course LO/PO/question references are rejected (422).

## Comparison output

`compare` returns `metadata`, `questions.items[] {status, replaced, changes[] {field, from, to}}`, `marks` (totals +
per-question), `blueprint.profile` (actual distributions per version, differences in percentage points), `blueprint.planned`
(snapshot targets), `mappings` (CO/PO coverage added/removed) and `analysis` (STEP 13 metrics of each version's latest analysis).

## Audit events

`ASSESSMENT_VERSION_CREATED`, `_UPDATED`, `_SUBMITTED`, `_APPROVED`, `_FINALIZED`, `_ARCHIVED`, `_RESTORED`, `_COMPARED` —
with `user_id`, `assessment_id`, `assessment_version_id`, timestamps and non-sensitive metadata (no question text).

## Configuration

`config/assessment_versioning.php`: enums, `structural_fields`, `distribution_change_threshold`,
`blueprint_tolerance` (`VERSION_BLUEPRINT_TOLERANCE`, defaults to the STEP 37 tolerance), `require_learning_outcome_mapping`
(`VERSION_REQUIRE_LO_MAPPING`), `require_change_summary`.

## Frontend

`/assessments/:assessmentId/versions` (history + timeline + create/compare), `/assessments/:assessmentId/versions/:versionId`
(snapshot, draft editor, workflow actions, blueprint/analysis summaries) and
`/assessments/:assessmentId/versions/:versionId/compare?with=:otherId`.

## Tests

- Backend: `php artisan test --filter=AssessmentVersionTest`
- Frontend: `npm test -- assessmentVersion`
- E2E (Docker stack): `bash backend/tests/e2e_assessment_versioning.sh`
