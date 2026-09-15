# FacultyLens — Faculty User Acceptance Test Report

STEP 47 — Faculty User Acceptance Testing. Plan: [FACULTY_UAT_PLAN.md](FACULTY_UAT_PLAN.md) · Issues: [FACULTY_UAT_ISSUES.md](FACULTY_UAT_ISSUES.md).
Executed 2026-09-15 against the committed `main` build (`431b4b8`). All statements below come from the recorded walkthrough (`frontend/uat-results/observations.json`, 83 checks, 60 PASS / 23 FAIL before triage) and the screenshots it produced; nothing is estimated.

---

## 1. Executive summary

A faculty persona was able to go **from course creation to assessment analysis, review, grading, analytics and reporting through the FacultyLens UI**, and at every AI touch-point the product told her — in words she could act on — that the AI output is a suggestion she must confirm, change or reject. Explainability, override, grading safety, report privacy and cross-faculty isolation all behaved as a faculty member would expect.

She could **not**, however, do it without technical assistance: leaving the Assessment Details page by clicking any link (Blueprint, Versions, Generate Questions, Submissions, or the sidebar) changes the URL but leaves the old page on screen until the browser is reloaded (**UAT-001, BLOCKER**). The assessment page is the hub of the whole workflow, so this defect was hit in four scenarios. Its root cause was isolated and a one-line fix verified on a diagnostic build (then reverted so this report describes the delivered system). A second gap — there is no way to type in a question, while three signposts imply there is (**UAT-002, MAJOR**) — means faculty who already have a paper cannot enter it directly. Two accessibility defects in the first two workflows (course and outcome dialogs; document upload) are MAJOR for keyboard and screen-reader users.

**Final UAT decision: `CONDITIONAL_ACCEPTANCE`** — accepted for faculty use once UAT-001 is fixed and re-verified by clicking (not by URL), UAT-002's signposting is corrected, and UAT-006/UAT-007 are resolved. The AI-assisted parts of the product are the strongest part of the experience and need no functional change for acceptance.

## 2. Participants and roles

| Persona | Role in FacultyLens | What they did |
|---|---|---|
| P1 — Dr. Ayesha Rahman, Associate Professor, CSE | `FACULTY`, course OWNER | Executed Scenarios 01–20 end to end through the UI (registered during S01) |
| P2 — Dr. Other Faculty | `FACULTY`, unrelated | Tried to open P1's course, assessment and submission by URL and API |
| P3 — collaborator `VIEWER` | `FACULTY` + course role VIEWER | Read-only explainability check reused from STEP 45 Playwright journey `10-ai-explainability.spec.ts` (can read explanation, cannot override, cannot see student data) |
| Department / institution user | `ADMIN` only | Not a faculty role; out of scope (UAT-015) |

The walkthrough was executed by the evaluator driving a scripted browser as P1 (Playwright, real clicks, real AI service). **No panel of human faculty participants was convened**; the ratings in §6 are the evaluator's judgement from the evidence and are not presented as survey statistics.

## 3. Environment

| Item | Value |
|---|---|
| Build | `main @ 431b4b8` (merge of PR #47), frontend production build (`vite build`) served by `vite preview` on `http://127.0.0.1:4173` |
| Backend | Docker dev stack: `app` (Laravel 12 `artisan serve`, :8080), `mysql` 8 (:3307), `ai-service` (FastAPI, embeddings loaded, :8001), `redis`, `horizon` (queues), `mailpit` |
| Browser | Chromium (Playwright 1.63), 1280×720; 390×844 for the mobile check; axe-core 4.13 (WCAG 2.1 A/AA) |
| Data | Created live by P1 (`uat.faculty.<ts>@university.edu`, course `UAT-642575 — Database Systems`, assessment #20). Five extra graded submissions for the performance engine were created through the public API and are labelled `[fixture]`. No production data. |
| Duration | 11.9 min for the 20 scenarios (AI generation ×2, analysis, rubric, AI grading, performance, three reports included) |
| Instrument | `frontend/tests/e2e/14-faculty-uat.spec.ts` — three runs: run #1 aborted on a script defect; run #2 surfaced UAT-001 (cascading failures S07–S20); run #3 (this report) completed all scenarios by falling back to typed URLs where a click did not render, and recorded each fallback. |

### Triage of the 23 recorded FAILs

| Category | Count | Checks |
|---|---|---|
| Genuine product findings → issue register | 12 | S04 navigation (UAT-001) · S03 labels (UAT-006) · A11Y keyboard/Escape (UAT-006) · S06 drop-zone (UAT-007) · A11Y mobile overflow (UAT-010) · axe `/courses` critical (UAT-006) · axe contrast/dl on 8 pages (UAT-009) |
| Walkthrough-script artefacts (feature verified from screenshot/API) | 8 | S05 `10 Marks` vs rendered `10.00 Marks` · S06 list badge reads **Extracted** not "Completed" (flow completed; UAT-011) · S11 rejected draft is hidden behind **Show 1 rejected** (rejection worked, 0/1 approved) · S13 "immutable historical record" appears twice (strict-mode; page shows *Finalized · v1.0* and the sentence) · S15 short disclaimer variant ("AI-generated grading assistance. Faculty review is required before finalizing marks.") · S17 filter id is generated by `useId` (filters exist, not exercised) · SUP Settings sampled during session splash · S12 Finalize sampled while validation in flight (UAT-014) |
| Partially exercised because of an artefact | 3 | S05 LO/Bloom visibility (true finding UAT-003, but same check also had the marks-format artefact) · S12 finalize not exercised in run #3 · S17 course filter not exercised |

## 4. Scenario results

Status: **ACCEPTED** (faculty completed it and understood it) · **ACCEPTED_WITH_NOTES** · **PARTIAL** (completed only with a workaround or not fully exercised) · **REJECTED**.

| # | Scenario | Result | Evidence / notes |
|---|---|---|---|
| 01 | Login and role | **ACCEPTED** | Wrong password → "Authentication failed" stays on `/login`; correct → dashboard greeting "Welcome back, Ayesha"; role `FACULTY`; sidebar shows faculty workspace; new account sees loading skeleton then empty panels; P2 gets "Unable to load course / assessment" and 403/404 from the API, empty course list |
| 02 | Create course | **ACCEPTED_WITH_NOTES** | Course listed, owned by P1, empty form blocked with no request. Escape does not close the dialog; axe critical on the dialog (UAT-006) |
| 03 | Learning outcomes | **ACCEPTED_WITH_NOTES** | Three CLOs added, CLO1 edited and persisted. Select/textarea unlabeled (UAT-006); Bloom defaulted to Understand for all three (UAT-012) |
| 04 | Create assessment | **PARTIAL** | Midterm created (30 marks / 90 min), detail page shows totals and Blueprint/Versions/Generate links. **Every in-app link off this page freezes the view (UAT-001)**; empty state points to Question Bank (UAT-002) |
| 05 | Add questions | **PARTIAL** | Three approved questions listed with text, `10.00 Marks`, `Medium`. Mapped CLO2 not shown; type as raw enum `AI: PROBLEM_SOLVING`; faculty Bloom/override not on card (UAT-003). No manual entry (UAT-002) |
| 06 | Upload academic document | **ACCEPTED_WITH_NOTES** | TXT syllabus → **Extracted** → detail page COMPLETED with readable content; `.png` → "Unsupported file type…"; 11 MB → "File size must not exceed 10MB."; corrupt `.pdf` → **Failed** with *Reprocess Extraction*. Drop-zone not keyboard operable (UAT-007); status wording inconsistent (UAT-011) |
| 07 | Run AI analysis | **ACCEPTED** | "Analyzing Complete Assessment…" → Assessment Rigor, Difficulty, Bloom, LO Alignment, Similarity, Findings, AI Recommendations all present; labels Excellent/Good/Needs Review; footer "Faculty decision support — decisions do not automatically alter assessment questions"; no raw JSON by default |
| 08 | Inspect explainability | **ACCEPTED** | Why? → *AI result: Apply* / *Why this result?* in one sentence / *Confidence: Not available — Confidence is not available for this result* / *Limitations* / "AI assists. Faculty decides. This result is AI-assisted and should be reviewed." Technical details (method **FacultyLens deterministic rules**) and Detailed evidence open on Enter; no system-prompt or chain-of-thought text; override to *Analyze* stored in the faculty column, AI value preserved, audit event written; Escape closes. "How is the score calculated?" shows `/ 100` and the weighted combination. Focus not moved into dialog (UAT-008) |
| 09 | Review recommendation | **ACCEPTED_WITH_NOTES** | Accept → dialog with rating + reason → **Confirm & Accept** → "Accepted"; Review → **Mark Reviewed**; questions byte-identical before/after. Evidence Details is raw JSON (UAT-004) |
| 10 | Generate rubric | **ACCEPTED** | "FacultyLens can draft a marking rubric… You review and approve the final version" → Generate → "Draft rubric generated. Review and adjust before approving." → Edit Rubric → notes saved → Approve → "Rubric approved by faculty"; criteria total 10.0 |
| 11 | Generate questions | **ACCEPTED** | Constraints (topic, CLO2, descriptive, medium, Apply, 10 marks ×3) → *Completed* → 3 **Draft** cards each with constraint validation (3 with warnings) → edit saved as "Edited v2" → Approve → Add to assessment → "Added to assessment" ×3. Second batch (CLO3, Evaluate ×1) rejected with reason → "0/1 approved · Show 1 rejected", assessment still 3 questions. Marks-budget warning shown ("Requested marks (10) exceed the remaining assessment allocation (0)"). Reached only by typed URL (UAT-001) |
| 12 | Assessment blueprint | **PARTIAL** | Create → 30 marks / 3 questions / 90 min / Section A / plan row → Save & validate → **Validated · v1**, *Valid with warnings*, four readable warnings ("Plan has 0 Easy question(s) but the difficulty target is 1", suggested allocation), Blueprint Completeness 60% labelled "not an assessment quality score", Compare with questions works. Finalize not exercised in this run (UAT-014); "STEP 33" wording in generate confirm (UAT-005). Reached only by typed URL (UAT-001) |
| 13 | Assessment version | **ACCEPTED_WITH_NOTES** | Create v1.0 → Finalize (confirm) → "Version finalized. It is now an immutable historical record."; Create new version (Minor, change summary required) → Edit draft → title "Midterm Examination (Set B)" + instructions saved → Compare renders; v1.0 still FINALIZED with original title, `PUT` → 409. "STEP 13 metrics" wording (UAT-005). Reached only by typed URL (UAT-001) |
| 14 | Student submission | **ACCEPTED** | Add Submission (new student) → MID-001 listed → Add Answer ×3 via UI → Submitted → Under review; every mark reads **Not graded** |
| 15 | AI-assisted grading | **ACCEPTED** | Q1 (approved rubric): "AI-generated grading assistance. Faculty review is required before finalizing marks." + **Get AI Grading Assistance**; Q2/Q3: button disabled with "An approved rubric is required before AI grading assistance can be requested." and **Grade without AI assistance** link. Suggestion arrived (**1.75 / 10**, 5 criterion rows) while the mark stayed *Not graded*; faculty entered 8 → recorded as "Faculty modified the AI suggestion"; 11/10 refused client-side ("cannot exceed 10"); submission → Graded (21.5 / 30). No "auto-grade" wording anywhere |
| 16 | Performance analysis | **ACCEPTED** | With 6 graded submissions: Overall Average 69.17 %, "6 submissions", LO, topic and learning-gap sections present; API `finalized_answer_count` = 18 |
| 17 | Analytics | **ACCEPTED_WITH_NOTES** | KPIs 1 course / 1 assessment / 3 questions / 1 analysed match the API after **Recalculate**. Course filter exists but was not exercised (script id); axe contrast ×60 (UAT-009) |
| 18 | Reports | **ACCEPTED** | ASSESSMENT_QUALITY → PDF (882 KB, `%PDF-`), QUESTION_ANALYSIS → CSV (1.5 KB), STUDENT_PERFORMANCE → XLSX (18 KB, `PK`); each previewed, generated, COMPLETED and downloaded from the UI; none contains the student identifier `UAT-STU-001`; history lists all three |
| 19 | Feedback | **ACCEPTED** | `/feedback` lists Accepted/Reviewed decisions with course/decision/rating filters; Accept-with-comment on an AI difficulty result recorded as ACCEPTED via the explanation panel |
| 20 | Logout | **ACCEPTED** | Sign out → confirmation → `/login`; `/dashboard`, `/assessments/20`, `/reports` all redirect; Back stays on login; `/api/auth/user` → 401 |

## 5. Acceptance questions — answered across the workflow

| Question | Answer |
|---|---|
| Could the faculty complete the task? | Yes for 18/20 scenarios; S04/S05 only with a reload workaround (UAT-001) and by using the generator instead of typing questions (UAT-002). |
| Was the purpose clear? | Yes on every AI screen (disclaimers, "review aid, not a quality verdict", "Planning indicator, not assessment quality"). Not clear for "Question Bank" vs assessment questions, or Materials vs Documents. |
| Was the terminology understandable? | Mostly. Exceptions: raw enums (`PROBLEM_SOLVING`, `VALID_WITH_WARNINGS` in API-derived badges), "STEP 13/33", "Extracted" vs "COMPLETED". |
| Was the result understandable? | Yes — quality labels, one-sentence "Why", criterion breakdowns, readable warnings. Exception: recommendation evidence JSON. |
| Could the faculty recover from errors? | Yes — inline validation (course form, marks > max), friendly 4xx/5xx messages, Failed document with Reprocess, "no questions to analyze" explained. The one unrecoverable-without-knowledge state is UAT-001 (no message at all). |
| Could the faculty verify AI output? | Yes — Why?/evidence/method/limitations on every AI badge, "How is the score calculated?", criterion evidence on grading, constraint validation on drafts. |
| Could the faculty override AI? | Yes, everywhere it matters: Bloom/difficulty/type override, recommendation accept/dismiss/review, rubric edit, draft edit/reject, AI mark → own mark. |
| Was the workflow efficient? | Acceptable once inside a page; the reload workaround and the generator-only question path cost the most time. |
| Was anything confusing? | Question entry (UAT-002), where faculty decisions show up (UAT-003), two upload areas (UAT-013). |

## 6. Acceptance ratings (evaluator judgement, 1–5)

| Scenario | Usability | Clarity | Usefulness | Trust | Explainability | Efficiency | Accessibility | Satisfaction |
|---|---|---|---|---|---|---|---|---|
| 01 Login | 5 | 5 | 5 | 5 | – | 5 | 4 | 5 |
| 02 Course | 4 | 4 | 4 | 5 | – | 4 | 2 | 4 |
| 03 Outcomes | 4 | 3 | 4 | 5 | – | 4 | 2 | 3 |
| 04 Assessment | 2 | 3 | 4 | 4 | – | 2 | 3 | 2 |
| 05 Questions | 2 | 2 | 3 | 4 | 4 | 2 | 3 | 2 |
| 06 Documents | 4 | 3 | 4 | 4 | – | 4 | 2 | 4 |
| 07 AI analysis | 5 | 4 | 5 | 5 | 5 | 4 | 4 | 5 |
| 08 Explainability | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 5 |
| 09 Recommendations | 4 | 3 | 4 | 5 | 3 | 4 | 4 | 4 |
| 10 Rubric | 5 | 5 | 5 | 5 | 4 | 4 | 4 | 5 |
| 11 Question generation | 4 | 4 | 5 | 5 | 5 | 4 | 3 | 4 |
| 12 Blueprint | 3 | 3 | 4 | 4 | 4 | 3 | 3 | 3 |
| 13 Versions | 4 | 4 | 4 | 5 | 4 | 4 | 3 | 4 |
| 14 Submission | 5 | 5 | 4 | 5 | – | 4 | 4 | 4 |
| 15 AI grading | 5 | 5 | 4 | 5 | 5 | 4 | 4 | 5 |
| 16 Performance | 4 | 4 | 5 | 4 | 4 | 5 | 3 | 4 |
| 17 Analytics | 4 | 4 | 5 | 4 | 4 | 4 | 2 | 4 |
| 18 Reports | 5 | 5 | 5 | 5 | – | 4 | 3 | 5 |
| 19 Feedback | 4 | 4 | 4 | 5 | 4 | 4 | 4 | 4 |
| 20 Logout | 5 | 5 | 5 | 5 | – | 5 | 5 | 5 |

These are single-evaluator ratings from one session and are deliberately not averaged or reported as percentages.

## 7. Critical issues

| ID | Severity | Summary |
|---|---|---|
| UAT-001 | **BLOCKER** | In-app navigation away from Assessment Details never renders the target page; reload required. Root cause verified (`v7_startTransition` + this page's pending effects); fix verified on a diagnostic build and reverted. Missed by STEP 41 because journeys used `page.goto`. |
| UAT-002 | MAJOR | No manual question entry; empty state, Question Bank link and "Upload Question Paper" / "extract … assessment questions" wording all imply otherwise. |
| UAT-006 | MAJOR (a11y) | Course, outcome, upload and feedback dialogs lack dialog semantics, focus handling, Escape, labels and named close buttons (axe **critical** on `/courses`). |
| UAT-007 | MAJOR (a11y) | Document upload drop-zone unreachable by keyboard. |

No CRITICAL data-exposure or AI-safety issue was found.

## 8. Usability findings

* Once on a page, tasks are short and well-labelled; progress and outcome messages are explicit ("Draft rubric generated. Review and adjust before approving.", "Version finalized. It is now an immutable historical record.").
* The assessment page is the workflow hub but is also where navigation breaks (UAT-001) and where the "how do I add questions?" question goes unanswered (UAT-002).
* Faculty-owned metadata is under-represented on question cards while AI badges are prominent (UAT-003); the version snapshot page shows the right model (`Descriptive · Medium · Analyze · CLO2`).
* Raw machine strings leak in a few places: `PROBLEM_SOLVING`, JSON evidence (UAT-004), "STEP 13/33" (UAT-005), `COMPLETED` (UAT-011).
* Defaults that matter academically are applied silently (Bloom = Understand on new outcomes, UAT-012; Section A type = MCQ on new blueprint sections).
* Two upload concepts on the course page (UAT-013). Rejected drafts are hidden behind "Show 1 rejected" — a reasonable design, but the first-time user looks for the card they just rejected.

## 9. AI trust findings

* **"AI assists. Faculty decides."** appears in the footer, inside every explanation ("This result is AI-assisted and should be reviewed"), on recommendation cards ("decisions do not automatically alter assessment questions"), on the rubric generator ("You review and approve the final version"), on drafts ("review aid, not a quality verdict"), on the blueprint ("never publishes or finalizes the assessment, and it never changes questions automatically") and on grading ("Faculty review is required before finalizing marks"; "decision-support feature. Faculty review and final judgment are required").
* No text implies automatic grading, automatic decisions or guaranteed correctness/difficulty/mapping. Confidence is shown as **Not available** where the method is rule-based instead of an invented percentage; Blueprint Completeness is explicitly "not an assessment quality score"; the time estimate is "planning indicator only".
* Grading: the AI suggestion never became a mark. In the one answer graded with assistance, the AI suggested **1.75 / 10** for an answer the faculty judged worth 8; the UI recorded this as "Faculty modified the AI suggestion" and kept both values visible. This is a single observation, not a measure of grading accuracy (see `AI_ACCURACY_EVALUATION_REPORT.md`), but it shows the safety design working as intended.
* Generated questions stayed `Draft` until approval; a rejected draft never entered the assessment; approved questions carry "Added to assessment".
* Recommendations require a rating and reason before Accept/Dismiss; decisions survive re-analysis (verified in STEP 41) and do not change questions (verified here).

## 10. Explainability findings

* Every AI badge has a **Why?** that opens a consistent Result → Why → Confidence → Limitations panel with *Technical details* (method, model/rule set, evaluation status) and *Detailed evidence* on demand; disclosures are keyboard-operable (`aria-expanded`).
* Explanations are one or two plain sentences ("classified this question at the APPLY level because it uses Apply-level directive wording ('Apply')"), evidence quotes the question text, limitations are honest ("the detected verb may not reflect the full task demand"). No hidden reasoning is exposed.
* Overrides write only faculty columns and keep the AI value for reference; the audit trail (`AI_EXPLANATION_VIEWED`, `AI_EVIDENCE_VIEWED`, `AI_RESULT_OVERRIDDEN`) is visible in course activity (STEP 45 journey).
* Weak spots: recommendation evidence outside the Why? panel is JSON (UAT-004); the override is not reflected on the originating card (UAT-003); dialog does not take focus (UAT-008).

## 11. Accessibility findings

| Check | Result |
|---|---|
| Keyboard reach | **Add Course** reachable by Tab, visible focus (`outline solid 3px` + shadow), Enter opens the dialog |
| Dialogs | Add Course / Learning Outcome / Upload dialogs: no `role="dialog"`, focus not moved, **Escape ignored**, unlabeled `select`/`textarea`, icon-only close (UAT-006). Version, Explanation, Rubric, Submission dialogs are correct (Escape works, labelled) |
| Upload | Drop-zone `div` not tabbable (UAT-007) |
| Status indicators | Badges carry text (Under Review, In Progress, Not Reviewed, Extracted, Failed, Finalized…) — not colour-only |
| Charts | Analytics SVG charts carry `role="img"` + `aria-label` (STEP 36) |
| Responsive | Dashboard fine at 390 px; Assessment Details overflows horizontally (UAT-010) |
| axe (WCAG 2.1 A/AA, serious+critical) | 0 violations: `/dashboard`, `/courses/:id`, `/documents/:id`, `/submissions/:id`, `/settings`, `/login`, versions compare (run #2). Violations: `/courses` (button-name ×1, select-name ×2 **critical**, contrast ×1), contrast on `/analytics` ×60, `/question-generator` ×25, compare ×9, blueprint ×7 (+ definition-list ×2), `/reports` ×5, `/assessments/:id` ×2, `/feedback` ×2, analysis ×1 (UAT-009) |

## 12. Security findings

* P2 (another faculty) opening P1's course/assessment by URL sees "Unable to load…" with no data; API returns 403/404; P2's own course list is empty.
* After sign-out every protected route redirects to `/login`, Back does not restore the session, `/api/auth/user` → 401.
* Generated PDF/CSV/XLSX contain no student identifier; report history is per-user (cross-user download denied — STEP 41 Journey 8).
* Faculty role has no admin scopes in the report builder (UAT-015, by design). Explanation panels contain no system prompts or model internals.
* No security issue raised.

## 13. Accepted features

Login/logout and session handling · Course CRUD · Learning outcomes · Assessment creation · Document upload with all three failure paths · Unified AI analysis and its presentation · Explainability (Why?, evidence, method, limitations, override) · Recommendation review/accept/dismiss with feedback · Rubric generate → edit → approve · Constrained question generation (draft → validate → edit → approve/reject → add) · Version create → finalize → new version → compare with immutability · Student submissions and answers · AI-assisted grading with faculty-final marks · Performance analysis · Analytics KPIs · Institutional reports (PDF/CSV/XLSX) with privacy · Feedback history · Notifications · Cross-faculty isolation.

## 14. Rejected features (as delivered)

* **Navigation from Assessment Details** (UAT-001) — rejected until fixed; blocks normal flow between the hub page and Blueprint/Versions/Generator/Submissions.
* **Question entry path** (UAT-002) — the "add my existing questions" task is rejected: no manual form, misleading signposts. The generator path itself is accepted.
* **Course / outcome / upload dialogs for keyboard and screen-reader users** (UAT-006, UAT-007) — rejected for accessibility.

## 15. Required fixes before full acceptance

1. **UAT-001** — remove or make safe `v7_startTransition` for `AssessmentDetails`; add a click-based Playwright regression (from `/assessments/:id` click `blueprint-link` and a sidebar item, assert render).
2. **UAT-002** — either a manual question form / paper extraction, or corrected empty-state and upload wording that says questions come from Generate Questions.
3. **UAT-006** — `role="dialog"`, `aria-modal`, initial focus, Escape, `htmlFor` labels and named close buttons on `CourseModal`, `LearningOutcomeModal`, `DocumentUploadModal`, `QuestionPaperUploadModal`, `FeedbackModal` (reuse the pattern already in `VersionCreateDialog`).
4. **UAT-007** — keyboard-operable file chooser in both upload modals.

Recommended for the next iteration: UAT-003, UAT-004, UAT-005, UAT-008–UAT-014.

## 16. Final UAT decision

```text
CONDITIONAL_ACCEPTANCE
```

Rationale: every faculty workflow was completed and understood, AI output was verifiable and overridable at every step, and the "AI assists, faculty decides" message was consistently present — but one BLOCKER (UAT-001) forces a browser reload at the centre of the workflow, one MAJOR gap (UAT-002) misdirects the most basic authoring task, and two MAJOR accessibility defects affect the first screens a faculty member uses. `ACCEPTED` is therefore not appropriate; `NOT_ACCEPTED` would overstate the problem given that all 20 scenarios were ultimately completed and the AI-safety posture passed without exception.

## 17. Acceptance criteria checklist

- [x] Faculty login works
- [x] Course workflow accepted (with accessibility notes)
- [x] LO/CO/PO workflow accepted (LO through UI; CO/PO mapping covered by STEP 41 `CoPoMappingTest`/Golden Path, not re-driven here)
- [ ] Assessment workflow accepted — **conditional on UAT-001**
- [ ] Question workflow accepted — **conditional on UAT-002** (generator path accepted)
- [x] Document workflow accepted (keyboard access outstanding, UAT-007)
- [x] AI analysis accepted
- [x] Explainability accepted
- [x] Recommendations understandable (evidence formatting noted, UAT-004)
- [x] Rubric workflow accepted
- [x] Question generation accepted
- [x] Blueprint workflow accepted with notes (finalize verified by STEP 41; jargon UAT-005)
- [x] Versioning accepted
- [x] Student submission workflow accepted
- [x] AI-assisted grading accepted
- [x] Performance analysis accepted
- [x] Analytics accepted (contrast outstanding)
- [x] Reporting accepted
- [x] Feedback accepted
- [x] Accessibility checked — findings UAT-006…UAT-010
- [x] Error recovery checked
- [x] AI safety messaging understood
- [x] UAT issues documented — [FACULTY_UAT_ISSUES.md](FACULTY_UAT_ISSUES.md)
- [x] UAT report created

### Re-verification

Re-run `cd frontend && npx playwright test tests/e2e/14-faculty-uat.spec.ts` after the fixes; the test fails while any S01/S02/S04 check (including the in-app navigation check) fails and passes once those are green, and `uat-results/observations.md` gives the per-check evidence for the next report revision.
