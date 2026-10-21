# One-week pilot with a real course

The goal is to find the problems that only show up in real use. Use one real course for a week, write down every
problem or annoyance in the log at the bottom (even small ones), and fix them in a batch afterwards.

## Before you start (15 minutes)

- [ ] Take a backup: Admin → **Backups** → *Back up now*.
- [ ] Download the models: `docker compose exec ai-service python -m app.download_models`.
- [ ] Choose the AI provider in Admin → **Platform settings** (hosted needs OpenRouter credit; local keeps everything on your machine).
- [ ] Check Admin → **System health**: every service green or grey, no open alerts.

## The week

| Day | Do this with your real course | Watch for |
| --- | --- | --- |
| 1 | Create the course, add its learning outcomes, upload the syllabus and two or three lecture files | Upload or text-extraction errors, wrong outcome text |
| 2 | Add an assessment: import the questions from a real paper (typed or photo), then run the analysis | Questions split wrongly, wrong marks, analysis results you disagree with |
| 3 | Ask Document Chat ten real questions about your material | Wrong or invented answers, missing sources, answers refused that the documents do cover |
| 4 | Generate a few questions and a rubric; review and edit them | Off-topic drafts, rubric criteria that do not fit the question |
| 5 | Grade a set of real answers with grading assistance; try the copied-answer check | Suggestions far from your own mark, false copying flags |
| 6 | Use the AI Toolkit on real material: transcribe a lecture recording, translate, read aloud, OCR a handwritten page | Misheard terms, poor translations, slow jobs |
| 7 | Build a report and the OBE pack; look at Analytics | Numbers that look wrong, exports that do not open |

Every day: glance at Admin → **Alerts** (and the notification bell) and note anything raised.

## Problem log

Copy this table into a note and add a row for every problem, however small. Screenshots help.

| # | Date | Page / tool | What I did | What I expected | What happened | How bad (blocker / annoying / minor) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | | | | | | |

## After the week

Sort the log by how bad each problem is, fix the blockers first, then re-run the pilot steps that failed.
