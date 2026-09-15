# FacultyLens — Faculty User Acceptance Test Plan

STEP 47 — Faculty User Acceptance Testing (UAT). Companion documents: [FACULTY_UAT_ISSUES.md](FACULTY_UAT_ISSUES.md) (issue register) and [FACULTY_UAT_REPORT.md](FACULTY_UAT_REPORT.md) (results and decision).

UAT asks a different question from the STEP 41 end-to-end suite. E2E asked *"does the system do what the specification says?"*; UAT asks *"can a faculty member, without technical help, get their academic work done with it — and do they understand and trust what the AI shows them?"* A technically green path is therefore **not** automatically accepted here.

---

## 1. Objective and acceptance philosophy

FacultyLens is accepted for faculty use only if the intended user can complete the normal academic workflow — course → outcomes → assessment → questions → AI analysis → review → grading → performance → analytics → reporting — through the UI alone, while:

* understanding the purpose of each screen and the terminology on it,
* being able to see **why** the AI said something (evidence, method, limitations),
* being able to **override or reject** every AI output,
* recovering from validation errors and service failures without losing work,
* never being led to believe that the AI grades, decides or guarantees anything.

Governing principle shown throughout the product and checked in every AI scenario:

> **AI assists. Faculty decides.**

## 2. Scope — what was inspected before writing the scenarios

The plan reuses the workflows that exist in FacultyLens as built. No UAT-only screens, seeds or shortcuts were created.

| Area | Where it lives in the UI (route) | Notes from inspection |
|---|---|---|
| Authentication / RBAC | `/login`, `/register`, `/forgot-password`, `/reset-password` | Sanctum cookie session; roles `FACULTY` (default) and `ADMIN`; per-course collaborator roles `OWNER / EDITOR / REVIEWER / VIEWER` (config `collaboration.php`) |
| Dashboard | `/dashboard` | KPI cards, Recent Assessments, Learning Outcome Profile, Collaboration Activity, AI Tools quick actions |
| Courses / Learning outcomes | `/courses`, `/courses/:id` | Add/Edit/Delete course; *Course Learning Outcomes* add/edit/delete; *Course Materials* upload; *Extracted Academic Documents* upload |
| CO/PO mapping | `/courses/:id/co-po-mapping` | AI similarity suggestions clearly labelled "suggestion only" |
| Assessments / questions | `/assessments`, `/assessments/:id` | Create/edit assessment; question cards with marks, difficulty and post-analysis AI badges; *Generate Rubric* per question; links to Blueprint, Versions, Generate Questions, Question Bank, Submissions, Analysis. **There is no manual "add question" form** — official questions enter through the constrained generator (approve → add to assessment). |
| Question Bank | `/courses/:id/question-bank` | Archive of *previous* questions (separate concept from assessment questions) |
| Document upload | Course page → *Upload Document* → `/documents/:id` | PDF/DOCX/TXT ≤ 10 MB, async extraction (`processing_status` pending/processing/completed/failed, Reprocess on failure) |
| AI analysis | `/assessments/:id/analysis`, `/analysis` | Unified run: Assessment Rigor (quality), Difficulty, Bloom, LO alignment, Similarity, Findings, AI Recommendations, Student Performance |
| Explainability | "Why?" badges on questions, "How is the score calculated?" on analysis | Modal with Result → Why → Evidence → Technical details → Limitations; Faculty decision (Accept / Reject / Mark as reviewed / Override) |
| Recommendations / feedback | Analysis page + `/feedback` | Review / Accept / Dismiss with rating + reason; Feedback History and Improvement Signals |
| Rubric generation | *Generate Rubric* on a question | Generate → Draft → Edit Rubric → Save Draft → Approve Rubric; versions kept |
| Question generation | `/courses/:id/question-generator` | Constraints (LO, PO, type, difficulty, Bloom, marks, count, document context) → drafts with constraint validation → Edit / Approve / Reject / Regenerate → Add to assessment |
| Blueprint | `/assessments/:id/blueprint` | Create → Save & validate → Validate → Finalize → Compare with questions → Generate Questions from Blueprint |
| Versioning | `/assessments/:id/versions[/:v[/compare]]` | Create v1.0 → Finalize (immutable) → Create new version (major/minor) → Edit draft → Compare |
| Student submissions | `/assessments/:id/submissions`, `/submissions/:id` | Add Submission (new/existing student), Add Answer, Import Answers (CSV), status transitions Submitted → Under review → Graded → Returned |
| AI-assisted grading | Answer card on `/submissions/:id` | *Get AI Grading Assistance* (needs approved rubric) → AI Suggested Marks + criterion breakdown → Accept / Enter Final Marks / Reject AI Suggestion; *Grade manually* always available |
| Inter-grader consistency | Analytics section "Inter-grader Consistency"; report type `INTER_GRADER` | Single-grader data model: section reports "unavailable"; treated as NOT_APPLICABLE (see STEP 41 report) |
| Performance analysis | *Analyze Student Performance* on the analysis page | Overall average, question/topic/LO analysis, learning gaps (needs ≥ 5 finalized responses) |
| Analytics | `/analytics` | KPI grid, filters (course, assessment, semester, year, type, dates), 20 sections, Recalculate, Export PDF/CSV, Compare assessments |
| Reports | `/reports`, `/reports/create`, `/reports/:id` | 15 report types × scopes; formats PDF / CSV / XLSX; preview → generate → download; history. `DEPARTMENT` / `INSTITUTION` scopes are ADMIN-only. |
| Collaboration | `/courses/:id/collaboration`, `/collaboration/invitations` | Invite by e-mail with role; activity feed incl. AI audit events |
| Notifications | `/notifications` (Inbox / Preferences) | In-app + e-mail per category |
| Settings | `/settings` | Profile, profile picture, password, notification preferences, theme |
| AI safety features | `AiOriginBadge` (AI Suggested / Faculty Approved / Faculty Final), `GradingDisclaimer`, evidence status on Document Chat, safety block on chat responses | Verified wording in every AI scenario below |

## 3. Personas

Only roles that FacultyLens actually implements are used. No permissions were invented.

| Persona | System role | Used for |
|---|---|---|
| **P1 — Dr. Ayesha Rahman**, Associate Professor, CSE | `FACULTY` (course OWNER) | Primary user of every scenario (01–20) |
| **P2 — Dr. Other Faculty** | `FACULTY` (no relation to P1's course) | Negative check: cannot see P1's course, assessment, submissions or reports (Scenario 01 "no unauthorized data") |
| **P3 — Course collaborator (VIEWER)** | `FACULTY` + collaborator role `VIEWER` on P1's course | Read-only transparency: can open explanations, cannot override, cannot see student data (already exercised by `10-ai-explainability.spec.ts`; results reused) |
| Department / programme / institutional user | `ADMIN` | Only role with `DEPARTMENT` / `INSTITUTION` report scope. Not a faculty workflow; **out of faculty UAT scope** and recorded as such — no faculty persona was given admin rights. |

## 4. Environment

| Item | Value |
|---|---|
| Stack | Docker dev stack (`docker-compose.yml`): `app` (Laravel 12, :8080), `mysql` 8 (:3307), `ai-service` (FastAPI + embeddings, :8001), `redis`, `horizon` (queues: default, emails), `mailpit` (:8025) |
| Frontend | Production build served by `vite preview` on `http://127.0.0.1:4173` (`playwright.config.ts` webServer) |
| Browser | Chromium (Playwright 1.63), 1280×720 desktop; 390×844 for the responsive check |
| Data | Created by the persona during the session (unique e-mail `uat.faculty.<ts>@university.edu`, course code `UAT-xxxxxx`). No production or other users' data. Bulk fixture: 5 extra graded submissions for the performance engine, created through the same public API the UI calls and labelled `[fixture]` in the observations. |
| Instrument | `frontend/tests/e2e/14-faculty-uat.spec.ts` — drives the 20 scenarios through the real UI as P1, records every check as PASS / FAIL / PARTIAL / NOT_TESTED with a note, takes a full-page screenshot per step (`frontend/uat-results/S??-*.png`, git-ignored), runs axe-core (WCAG 2.1 A/AA) on 16 pages, and writes `uat-results/observations.json` / `observations.md`. A failed check is recorded and the walkthrough continues; only a broken login/course path fails the Playwright test itself. Because the walkthrough clicks real links (not `page.goto`), it exercises client-side navigation that the STEP 41 journeys bypassed. |
| Run | `cd frontend && npx playwright test tests/e2e/14-faculty-uat.spec.ts` (stack must be up) |

## 5. Acceptance questions asked for every scenario

```text
Could the faculty complete the task?
Was the purpose clear?
Was the terminology understandable?
Was the result understandable?
Could the faculty recover from errors?
Could the faculty verify AI output?
Could the faculty override AI?
Was the workflow efficient?
Was anything confusing?
```

## 6. Rating scale

```text
1 = Very Poor   2 = Poor   3 = Acceptable   4 = Good   5 = Excellent
```

Dimensions rated per scenario: usability, clarity, usefulness, trust, explainability, efficiency, accessibility, satisfaction. Ratings are the evaluator's judgement from the walkthrough evidence; they are **not** averaged into statistics and no participant-count claims are made (see §9 of the report).

## 7. Issue severity

```text
BLOCKER      faculty cannot complete a core academic task
CRITICAL     wrong/misleading result, data exposure, AI presented as decision
MAJOR        task completable only with workaround or significant confusion
MINOR        cosmetic or low-impact defect
USABILITY    task completable, but purpose/terminology/flow unclear
ENHANCEMENT  improvement suggestion, not a defect
```

Issues are recorded in [FACULTY_UAT_ISSUES.md](FACULTY_UAT_ISSUES.md) with ID, scenario, severity, steps, expected, actual, impact, reference and status.

---

## 8. Scenarios

Each scenario lists the faculty path, the checks performed, and what "accepted" means. "Ref" names the screenshot / observation key produced by the walkthrough.

### Scenario 01 — Login and role

```text
Open /login → enter credentials → authenticate → reach dashboard
```

Checks: wrong password gives a plain-language message and stays on `/login`; correct credentials land on `/dashboard` with the personal greeting; `/auth/user` role is `FACULTY`; sidebar shows the faculty workspace (Dashboard, Courses, Assessments, Analysis, Analytics, Reports, Feedback, Notifications, Settings); a brand-new account sees an empty dashboard, not another user's data; a second faculty (P2) opening P1's course/assessment/submission URLs sees a safe error and the API answers 403/404.
Accepted when: all of the above hold. Ref `S01-*`.

### Scenario 02 — Create course

```text
Dashboard → Courses → Add Course → code, title, academic year → Create Course
```

Checks: course appears in the list; `courses.user_id` is P1; empty form is rejected inline without a request; Escape/Cancel behaviour of the dialog. Ref `S02-*`.

### Scenario 03 — Learning outcomes

```text
Course → Course Learning Outcomes → Add Outcome ×3 → Edit outcome → Update Outcome
```

Checks: outcomes persist under the right course; edit is reflected in list and API; form controls have programmatic labels. Ref `S03-*`.

### Scenario 04 — Create assessment

```text
Assessments → Create Assessment → choose course, title, total marks, duration → Create
```

Checks: assessment appears; detail page shows Total Marks / Questions Count and the Blueprint, Versions, Generate Questions links; the empty-questions state tells the faculty what to do next. Ref `S04-*`.

### Scenario 05 — Add questions (metadata visibility)

Because there is no manual question form, questions are produced by Scenario 11 and then inspected here.
Checks on `/assessments/:id`: question text, marks, difficulty visible; **question type, faculty Bloom level and mapped LO/CO visible**; CO/PO where applicable. Ref `S05-question-list`.

### Scenario 06 — Upload academic document

```text
Course → Extracted Academic Documents → Upload Document → category + file → Upload & Extract → Completed → open document
```

Checks: TXT syllabus reaches `Completed` with readable extracted content on `/documents/:id`; `.png` rejected with "Unsupported file type…"; 11 MB file rejected with "must not exceed 10 MB"; corrupt `.pdf` ends `Failed` with a readable error and a *Reprocess* action; drop-zone keyboard operability. Ref `S06-*`.

### Scenario 07 — Run AI analysis

```text
Assessment → Analysis → Run AI Analysis → "Analyzing Complete Assessment…" → results
```

Checks: sections Assessment Rigor, Difficulty, Bloom/Cognitive, Learning Outcome Alignment, Similarity, Findings, AI Recommendations present; qualitative labels (Excellent / Good / Needs Review …); decision-support wording present; no raw JSON by default. Ref `S07-*`.

### Scenario 08 — Inspect explainability

```text
Question badge "Why? Explain Bloom level" → Result → Why → Confidence → Limitations → Technical details → Detailed evidence → Override
"How is the score calculated?" on the overall quality score
```

Checks: explanation is understandable; evidence relevant; confidence honestly "Not available" where the method is rule-based; limitations visible; no system prompt / chain-of-thought text; keyboard-operable disclosures; override stores the faculty value and keeps the AI value; Escape closes. Ref `S08-*`.

### Scenario 09 — Review recommendation

```text
AI Recommendations → Evidence Details → Accept → rating/reason → Confirm & Accept ; Review → Mark Reviewed
```

Checks: decision persisted and shown; evidence readable; questions unchanged by decisions ("decisions do not automatically alter assessment questions"). Ref `S09-*`.

### Scenario 10 — Generate rubric

```text
Question → Generate Rubric → Generate AI Rubric → draft → Edit Rubric → Save Draft → Approve Rubric
```

Checks: draft labelled as draft with "review and adjust before approving"; edit saved; approved rubric totals the question marks. Ref `S10-*`.

### Scenario 11 — Generate questions

```text
Generate Questions → constraints (topic, CO, type, difficulty, Bloom, marks, count) → Generate → drafts → constraint validation → Edit → Approve → Add to assessment ; second batch → Reject
```

Checks: all drafts start as `Draft`; validation per draft; edit is versioned; only approved drafts reach the assessment; rejected draft never does. Ref `S11-*`.

### Scenario 12 — Assessment blueprint

```text
Blueprint → Create blueprint → total marks, questions, duration, section, question plan → Save & validate → Validate → warnings → Finalize → Compare with questions
```

Checks: validation and warnings readable; Finalize protected by confirmation; no developer jargon in faculty-facing text. Ref `S12-*`.

### Scenario 13 — Assessment version

```text
Versions → Create v1.0 → Finalize → Create new version (minor) → Edit draft → Save draft → Compare
```

Checks: finalized version declared immutable and refuses edits (409); new draft edited; comparison rendered; v1.0 unchanged afterwards. Ref `S13-*`.

### Scenario 14 — Student submission

```text
Assessment → Submissions → Add Submission (new student) → Create Submission → open → Add Answer ×3 → Save Answer → Under review
```

Checks: submission listed; answers saved; marks read "Not graded" until faculty acts. Ref `S14-*`.

### Scenario 15 — AI-assisted grading

```text
Answer with approved rubric → Get AI Grading Assistance → AI Suggested Marks + criteria → Enter Final Marks → Finalize ; other answers → Grade manually ; → Graded
```

Checks: AI button only enabled with approved rubric (blocked message otherwise); disclaimer "decision-support… faculty review and final judgment are required"; suggestion never becomes the mark by itself; faculty final mark recorded; marks > maximum refused client-side; no "auto-grade" wording. Ref `S15-*`.

### Scenario 16 — Performance analysis

```text
Finalized grades (6 submissions) → Analyze Student Performance → Overall average → question/topic/LO → learning gaps
```

Ref `S16-performance`.

### Scenario 17 — Analytics

Checks: KPI values equal the API and the data just created (1 course, 1 assessment, 3 questions, 1 analysed); course filter works; charts have text alternatives; sections present. Ref `S17-*`.

### Scenario 18 — Reports

```text
Reports → Create report → type → scope → course/assessment → format → Preview Report → Generate Report → Download
```

Run three times: `ASSESSMENT_QUALITY` as **PDF**, `QUESTION_ANALYSIS` as **CSV**, `STUDENT_PERFORMANCE` as **XLSX**. Checks: magic bytes, size, no student identifier in the bytes, history lists all three. Ref `S18-*`.

### Scenario 19 — Feedback

Checks: `/feedback` lists decisions with course/decision/rating filters; Accept-with-comment recorded on an AI difficulty result via the explanation panel. Ref `S19-*`.

### Scenario 20 — Logout

```text
Sidebar → Sign out → Yes, sign out → /login ; visit protected routes → redirected ; browser Back → still /login ; /api/auth/user → 401
```

Ref `S20-*`.

### Supporting checks

* Notifications centre and Settings reachable and populated (`SUP-*`).
* Accessibility: keyboard-only reach/open/close of the Add Course dialog and focus-visible style; badges carry text; mobile 390×844 layout without horizontal overflow; axe-core on `/dashboard`, `/courses`, `/courses/:id`, `/assessments/:id` (empty and with questions), `/question-generator`, `/documents/:id`, `/assessments/:id/analysis`, `/assessments/:id/blueprint`, versions compare, `/submissions/:id`, `/analytics`, `/reports`, `/feedback`, `/settings`, `/login`.
* AI safety messaging: presence of "AI assists · Faculty decides" (footer), `AI Suggested` / `Faculty Final` badges, grading disclaimer, recommendation footer, rubric "You review and approve the final version", generator "review aid, not a quality verdict"; absence of "auto-grade", "automatically graded", "guaranteed", "100% accurate".

## 9. Acceptance criteria checklist

- [ ] Faculty login works
- [ ] Course workflow accepted
- [ ] LO/CO/PO workflow accepted
- [ ] Assessment workflow accepted
- [ ] Question workflow accepted
- [ ] Document workflow accepted
- [ ] AI analysis accepted
- [ ] Explainability accepted
- [ ] Recommendations understandable
- [ ] Rubric workflow accepted
- [ ] Question generation accepted
- [ ] Blueprint workflow accepted
- [ ] Versioning accepted
- [ ] Student submission workflow accepted
- [ ] AI-assisted grading accepted
- [ ] Performance analysis accepted
- [ ] Analytics accepted
- [ ] Reporting accepted
- [ ] Feedback accepted
- [ ] Accessibility checked
- [ ] Error recovery checked
- [ ] AI safety messaging understood
- [ ] UAT issues documented
- [ ] UAT report created

The filled-in checklist and the final decision (`ACCEPTED` / `ACCEPTED_WITH_MINOR_ISSUES` / `CONDITIONAL_ACCEPTANCE` / `NOT_ACCEPTED`) are in [FACULTY_UAT_REPORT.md](FACULTY_UAT_REPORT.md).
