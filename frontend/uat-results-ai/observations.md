| Scenario | Check | Status | Note | Screenshot |
|---|---|---|---|---|
| S01 | wrong password shows a plain-language error and stays on /login | PASS |  | S01-login-error.png |
| S01 | faculty signs in through the form and reaches the dashboard | PASS |  | S01-dashboard.png |
| S01 | session belongs to the FACULTY role (no admin scopes) | PASS | role=FACULTY |  |
| S01 | navigation exposes the faculty workspace (Courses, Assessments, Analysis, Analytics, Reports, Feedback, Settings) | PASS |  |  |
| S01 | dashboard for a new account shows an empty/zero state instead of another user's data | PASS | panels visible: none of the expected panel headings; empty-state wording present=false |  |
| A11Y | axe /dashboard | FAIL | 1 violation type(s); serious/critical: color-contrast×10 |  |
| S02 | faculty creates a course from Courses → Add Course | PASS | course id 113 | S02-course-list.png |
| S02 | course is owned by the signed-in faculty | PASS |  |  |
| S02 | empty course form is rejected inline (no request sent) | PASS | NOTE: Escape does not close the course dialog | S02-course-validation.png |
| A11Y | axe /courses | FAIL | 3 violation type(s); serious/critical: button-name×1, color-contrast×1, select-name×2 |  |
| S03 | faculty adds three learning outcomes from the course page | PASS |  | S03-learning-outcomes.png |
| S03 | faculty edits an outcome and the change persists | PASS |  | S03-lo-edited.png |
| S03 | outcome form fields have programmatic labels (accessibility) | PASS |  |  |
| A11Y | axe /courses/:id | PASS | 0 violation type(s); serious/critical: none |  |
| AI-1 | faculty uploads lecture PDF "T6.1.pdf" from the course page | FAIL | ENOENT: no such file or directory, stat 'C:\Users\Sakin\Downloads\T6.1.pdf' |  |
| AI-1 | faculty uploads lecture PDF "T6.1 Demo.pdf" from the course page | FAIL | ENOENT: no such file or directory, stat 'C:\Users\Sakin\Downloads\T6.1 Demo.pdf' |  |
| AI-1 | faculty uploads lecture PDF "T6.2.pdf" from the course page | FAIL | ENOENT: no such file or directory, stat 'C:\Users\Sakin\Downloads\T6.2.pdf' |  |
| AI-1 | faculty uploads lecture PDF "T6.3.pdf" from the course page | FAIL | ENOENT: no such file or directory, stat 'C:\Users\Sakin\Downloads\T6.3.pdf' |  |
| AI-1 | faculty uploads lecture PDF "T6.3 Demo.pdf" from the course page | FAIL | ENOENT: no such file or directory, stat 'C:\Users\Sakin\Downloads\T6.3 Demo.pdf' |  |
| AI-1 | faculty uploads lecture PDF "T6.4.pdf" from the course page | FAIL | ENOENT: no such file or directory, stat 'C:\Users\Sakin\Downloads\T6.4.pdf' |  |
| AI-1 | all six PDFs reach Completed extraction and INDEXED for chat | FAIL | T6.1.pdf: undefined/undefined/? chunks; T6.1 Demo.pdf: undefined/undefined/? chunks; T6.2.pdf: undefined/undefined/? chunks; T6.3.pdf: undefined/undefined/? chunks; T6.3 Demo.pdf: undefined/undefined/? chunks; T6.4.pdf: undefined/undefined/? chunks  [2mexpect([22m[31mreceived[39m[2m).[22mtoBeTruthy[2m()[22m | AI-1-course-with-pdfs-FAIL.png |
| AI-1 | extracted text keeps the logic symbols and numbers from the slides | FAIL | Cannot read properties of undefined (reading 'id') | AI-1-document-T6.4-FAIL.png |
| AI-2 | faculty starts a whole-course chat from the course page | FAIL | [2mexpect([22m[31mlocator[39m[2m).[22mtoBeVisible[2m([22m[2m)[22m failed  Locator: getByText(/6\/6 indexed/) | AI-2-chat-started-FAIL.png |
| AI-2 | P(cavity | toothache) from the full joint distribution is answered as 0.6 with a T6.1 source | FAIL | locator.fill: Timeout 20000ms exceeded. Call log: [2m  - waiting for locator('textarea').first()[22m | AI-2-chat-joint-FAIL.png |
| AI-2 | Bayesian network specification is answered from T6.3 (DAG, nodes, arcs, CPT) | FAIL | locator.fill: Timeout 20000ms exceeded. Call log: [2m  - waiting for locator('textarea').first()[22m | AI-2-chat-bn-spec-FAIL.png |
| AI-2 | burglary network posterior P(B | a, j, m) is answered as 37% / 63% from T6.4 | FAIL | locator.fill: Timeout 20000ms exceeded. Call log: [2m  - waiting for locator('textarea').first()[22m | AI-2-chat-burglary-FAIL.png |
| AI-2 | Bayes rule + decision theory question pulls from T6.1/T6.2 | FAIL | locator.fill: Timeout 20000ms exceeded. Call log: [2m  - waiting for locator('textarea').first()[22m | AI-2-chat-bayes-FAIL.png |
| AI-2 | an off-topic question is refused as not supported by the documents | FAIL | locator.fill: Timeout 20000ms exceeded. Call log: [2m  - waiting for locator('textarea').first()[22m | AI-2-chat-offtopic-FAIL.png |
| AI-2 | faculty renames the chat and exports the transcript | FAIL | locator.click: Timeout 20000ms exceeded. Call log: [2m  - waiting for getByTestId('chat-rename')[22m | AI-2-chat-renamed-FAIL.png |
| AI-2 | single-document chat on T6.2.pdf only cites T6.2.pdf | FAIL | Cannot read properties of undefined (reading 'id') | AI-2-chat-single-doc-FAIL.png |
| S04 | faculty creates a midterm from Assessments → Create Assessment | PASS | assessment id 34 | S04-assessment-list.png |
| S04 | assessment detail page shows configuration and next-step links | PASS | NOTE: empty state points to "Question Bank" (archive of previous questions), no direct "Add question" affordance | S04-assessment-detail-empty.png |
| A11Y | axe /assessments/:id (empty) | FAIL | 1 violation type(s); serious/critical: color-contrast×2 |  |
| S04 | in-app links on the assessment page (Blueprint / Versions / sidebar) render the target page without a reload | PASS | Blueprint rendered after click; url=/assessments/34/blueprint |  |
| S05 | there is no manual add-question form; questions enter through AI generation + faculty approval | PASS | Design: official questions come only from Generate Questions → approve → add to assessment |  |
| S11 | faculty sets constraints and generates drafts (Generate Questions link from the assessment) | PASS | in-app navigation rendered. drafts=3; disclaimer wording present=true | S11-drafts.png |
| S11 | each draft shows constraint validation (type/difficulty/Bloom/LO) that faculty can inspect | PASS | validation panels=3, with warnings=2, similarity warnings=0 |  |
| S11 | faculty edits a draft before approval and the edit is kept (versioned) | PASS |  | S11-draft-edited.png |
| S11 | faculty approves drafts and adds them to the assessment (drafts stay drafts until approved) | PASS | official questions=3 | S11-approved-added.png |
| S11 | faculty can reject a draft with a reason (second small batch) | PASS | rejected draft never entered the assessment (rejected drafts are hidden behind "Show N rejected") | S11-rejected.png |
| A11Y | axe /question-generator | FAIL | 1 violation type(s); serious/critical: color-contrast×46 |  |
| S05 | assessment page lists each question with text, marks, difficulty; LO and faculty Bloom level visible? | PASS |  | S05-question-list.png |
| A11Y | axe /assessments/:id (questions) | FAIL | 1 violation type(s); serious/critical: color-contrast×2 |  |
| S06 | faculty uploads a syllabus (TXT) and sees it processed to Completed with extracted content | PASS | list badge reads "Extracted", detail page reads "COMPLETED" (UAT-011) | S06-document-details.png |
| A11Y | axe /documents/:id | PASS | 0 violation type(s); serious/critical: none |  |
| S06 | unsupported file type is rejected before upload with a clear message | PASS |  | S06-invalid-type.png |
| S06 | oversized file (11 MB) is rejected with a clear message | PASS |  | S06-oversized.png |
| S06 | a corrupt PDF ends in Failed with an error the faculty can read and a Reprocess action | PASS | status Failed + Reprocess Extraction visible in list | S06-corrupt-failed.png |
| S06 | the upload drop-zone is keyboard operable (not a div-only click target) | PASS |  |  |
| S07 | faculty runs the unified AI analysis from the Analysis page and sees all result sections | PASS |  | S07-analysis-results.png |
| S07 | results are labelled in faculty language (Excellent/Good/Needs Review…) and no raw JSON is shown by default | PASS | visible <pre> blocks=0 |  |
| S07 | analysis page states that AI output is decision support, not a decision | PASS |  |  |
| A11Y | axe /assessments/:id/analysis | FAIL | 1 violation type(s); serious/critical: color-contrast×2 |  |
| S08 | faculty opens "Why?" on an AI Bloom classification and sees result, reason, confidence, limitations | PASS | focus moved into dialog=false; confidence="Not available" | S08-explanation.png |
| S08 | evidence and method are available on demand (progressive disclosure, keyboard operable) | PASS | method=FacultyLens deterministic rules (RULE_BASED) | S08-evidence.png |
| S08 | faculty can override the AI Bloom level; the AI value stays visible for reference | PASS |  | S08-overridden.png |
| S08 | "How is the score calculated?" explains the overall quality score in plain terms | PASS |  | S08-score-explained.png |
| S09 | faculty opens a recommendation, inspects evidence and records an Accept decision with a rating/reason | PASS | NOTE: evidence is rendered as raw JSON in a <pre> block | S09-accepted.png |
| S09 | faculty can also Review (with note) and Dismiss a recommendation; decisions never change questions | PASS |  | S09-reviewed.png |
| S10 | faculty generates an AI rubric draft for Q1, edits it, saves and approves it | PASS |  | S10-rubric-approved.png |
| S12 | faculty creates a blueprint, configures targets and saves/validates it | PASS | validation panel shown=true; status text: Validated | S12-blueprint-saved.png |
| S12 | faculty reads validation warnings/recommendations and finalizes the blueprint | PASS | warnings shown | S12-blueprint-finalized.png |
| S12 | blueprint page uses faculty language (no internal STEP numbers or developer jargon) | PASS |  |  |
| S12 | "Compare with questions" tells faculty how the real paper deviates from the blueprint | PASS |  | S12-blueprint-compare.png |
| A11Y | axe /assessments/:id/blueprint | FAIL | 2 violation type(s); serious/critical: color-contrast×5, definition-list×2 |  |
| S13 | faculty snapshots v1.0 and finalizes it; the UI states it is immutable | PASS |  | S13-version-finalized.png |
| S13 | faculty creates a new version from the finalized one, edits the draft and compares | PASS |  | S13-version-compare.png |
| S13 | the old finalized version is unchanged after the new draft was edited | PASS | v1 title="Midterm Examination" status=FINALIZED; v2 id=5 |  |
| A11Y | axe /assessments/:id/versions/:v/compare | FAIL | 1 violation type(s); serious/critical: color-contrast×9 |  |
| S14 | faculty records a student submission and enters answers for every question through the UI | PASS |  | S14-answers-recorded.png |
| S14 | status workflow is explicit (Submitted → Under review) and marks read "Not graded" before faculty acts | PASS |  | S14-under-review.png |
| A11Y | axe /submissions/:id | PASS | 0 violation type(s); serious/critical: none |  |
| S15 | AI grading is only offered where an approved rubric exists, with a decision-support disclaimer | PASS |  | S15-grading-entry.png |
| S15 | faculty requests an AI suggestion; the mark stays "Not graded" until the faculty decides | PASS | suggested=0.5 / 10; criterion rows=5 | S15-ai-suggestion.png |
| S15 | faculty overrides the suggestion with their own final mark and it is recorded as the faculty decision | PASS | faculty decision label="Faculty modified the AI suggestion" | S15-faculty-final.png |
| S15 | remaining answers are graded manually; marks above the maximum are refused client-side | PASS |  | S15-graded.png |
| S15 | grading page never claims automatic grading | PASS |  |  |
| S16 | [fixture] five more graded submissions are created through the same API the UI uses | PASS | 6 graded submissions in total |  |
| S16 | faculty runs Student Performance analysis and sees averages, LO/topic analysis and learning gaps | PASS | sections LO=true topic=true gaps=true; avg=69.17% | S16-performance.png |
| S17 | analytics KPIs match the data the faculty just created | PASS |  | S17-analytics.png |
| S17 | course filter narrows the analytics and charts carry text alternatives | PASS | charts/graphics=43, svg without label=19; sections present: Difficulty Distribution, Cognitive Level, Learning Outcome Coverage, Student Performance, Learning Gaps, Grading | S17-analytics-filtered.png |
| A11Y | axe /analytics | FAIL | 1 violation type(s); serious/critical: color-contrast×62 |  |
| S18 | faculty configures, previews, generates and downloads a ASSESSMENT_QUALITY report as PDF | PASS | report 7, 882180 bytes, no student identifier leaked | S18-report-PDF.png |
| S18 | faculty configures, previews, generates and downloads a QUESTION_ANALYSIS report as CSV | PASS | report 8, 1490 bytes, no student identifier leaked | S18-report-CSV.png |
| S18 | faculty configures, previews, generates and downloads a STUDENT_PERFORMANCE report as XLSX | PASS | report 9, 18477 bytes, no student identifier leaked | S18-report-XLSX.png |
| S18 | report history lists the generated reports | PASS |  | S18-report-history.png |
| A11Y | axe /reports | FAIL | 1 violation type(s); serious/critical: color-contrast×5 |  |
| S19 | Feedback page lists the faculty decisions (accepted / reviewed) with filters | PASS |  | S19-feedback.png |
| S19 | faculty records an Accept-with-comment decision on an AI difficulty result via the explanation panel | PASS |  | S19-review-accepted.png |
| A11Y | axe /feedback | FAIL | 1 violation type(s); serious/critical: color-contrast×2 |  |
| SUP | Notifications centre shows workflow events (analysis completed, version finalized …) | PASS | unread text: n/a | SUP-notifications.png |
| SUP | Settings page exposes profile, password and notification preferences | PASS |  | SUP-settings.png |
| A11Y | axe /settings | PASS | 0 violation type(s); serious/critical: none |  |
| A11Y | keyboard-only: Tab reaches "Add Course" from the page top and Enter opens the dialog; Escape closes it | FAIL | Escape does not close the Add Course dialog (focus moved into dialog=false, role=dialog count=0, focus style="solid 3px shadow") | A11Y-keyboard-FAIL.png |
| A11Y | status indicators carry text (not colour only) on the submissions list | PASS |  |  |
| A11Y | responsive: dashboard and assessment pages are usable at 390×844 (mobile) | FAIL | horizontal overflow px  [2mexpect([22m[31mreceived[39m[2m).[22mtoBeLessThanOrEqual[2m([22m[32mexpected[39m[2m)[22m |  |
| S01 | another faculty member cannot open this course/assessment/submission (UI shows a safe error, API 403/404) | FAIL | [2mexpect([22m[31mlocator[39m[2m).[22mtoBeVisible[2m([22m[2m)[22m failed  Locator: getByText(/Unable to load course\|not found\|not authorized\|permission\|forbidden/i).first() |  |
| S20 | faculty signs out via the sidebar confirmation; protected routes redirect to /login afterwards | PASS |  | S20-logged-out.png |
| A11Y | axe /login | PASS | 0 violation type(s); serious/critical: none |  |
