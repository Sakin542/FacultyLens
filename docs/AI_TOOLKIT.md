# AI models and the AI Toolkit

FacultyLens uses open Hugging Face models running inside the `ai-service` container. An administrator can optionally switch text generation to a hosted API provider.

**Principle:** every AI output is a draft for a faculty member to review. Nothing here grades, approves or publishes on its own.

## Models

| Capability | Default model | Where it runs | Used by |
| --- | --- | --- | --- |
| Embeddings / similarity | `sentence-transformers/all-MiniLM-L6-v2` | local | analysis, similarity, retrieval |
| Text generation | `Qwen/Qwen2.5-0.5B-Instruct` (`HF_GENERATION_MODEL`) | local, or hosted when selected | document chat, question generator, rubric drafts, syllabus drafts, question variants |
| Handwriting OCR | `microsoft/trocr-base-handwritten` (`OCR_MODEL`) | local | AI Toolkit |
| Lecture transcription | `openai/whisper-base` (`ASR_MODEL`) | local | AI Toolkit |
| Bangla ↔ English translation | `facebook/nllb-200-distilled-600M` (`TRANSLATION_MODEL`) | local, or the hosted model when selected | AI Toolkit |
| Read aloud (text to speech) | `facebook/mms-tts-eng`, `facebook/mms-tts-ben` | local | AI Toolkit |
| Notes & quiz, question checker, simplify, Bloom rewriter | the text-generation model above + MiniLM | local, or hosted when selected | AI Toolkit |

Models download into the `ai_models_cache` volume the first time they are used and stay in memory afterwards. Approximate sizes: Qwen 0.5B ≈ 1 GB, NLLB ≈ 2.4 GB, TrOCR ≈ 1.3 GB, Whisper base ≈ 0.3 GB. With every tool in use the AI service needs roughly 6–7 GB of RAM.

Leaving `HF_GENERATION_MODEL` empty disables local generation; the features then use their template or extractive engines and say so.

## Local vs hosted generation

**Admin → Platform settings → AI generation provider**:

- **Local Hugging Face models (private)**: the default. Nothing leaves your server.
- **Hosted API provider**: calls an OpenAI-compatible `/chat/completions` API. Configure it in the AI service environment (the key is never sent to Laravel or the browser):

```bash
LLM_HOSTED_BASE_URL=https://router.huggingface.co/v1   # Hugging Face Inference Providers (HF token as key)
LLM_HOSTED_API_KEY=...
LLM_HOSTED_MODEL=meta-llama/Llama-3.1-8B-Instruct
```

Other compatible endpoints: Groq `https://api.groq.com/openai/v1`, Google Gemini `https://generativelanguage.googleapis.com/v1beta/openai`, OpenRouter `https://openrouter.ai/api/v1`.

When Hosted is selected, academic text (questions, document excerpts, answers) is sent to that provider. If the hosted call fails or isn't configured, FacultyLens uses the local model and records the reason.

**Admin → AI governance → Providers & tools** shows the selected provider, whether each one is configured, and each toolkit model's readiness.

## AI Toolkit (faculty: AI tools → AI Toolkit)

| Tool | Input | How it runs | Limits |
| --- | --- | --- | --- |
| Translate | text | immediately | 5,000 characters |
| Handwriting OCR | JPG/PNG/WEBP or PDF | queued job | 20 MB, 10 PDF pages |
| Lecture transcription | MP3/WAV/M4A/OGG/WEBM/MP4/AAC/FLAC | queued job | 100 MB, 90 minutes |

- OCR splits each page into text lines, then reads each line. Results are labelled as drafts to check against the original script.
- Transcription returns timestamped segments. Choose English or Bangla, or let Whisper detect the language.
- Queued jobs run one at a time on the `ai-tools` Horizon queue (`supervisor-ai-tools`, 1850 s timeout), because they are CPU-heavy.
- The uploaded file is deleted as soon as the job finishes; only the text is kept. Jobs are visible only to their owner.
- Each tool can be switched off in Platform settings (`ai_translation`, `ai_ocr`, `ai_transcription`). The server then refuses new work.

### Teaching tools added in round two

| Tool | What it does | Safeguards |
| --- | --- | --- |
| Read aloud | Speaks English or Bangla text (up to 1,500 characters); also a **Listen** button on translations, summaries and simplified text | none needed |
| Notes & quiz | Summary, key points and a draft quiz from pasted material or a finished transcript (queued job) | Every quiz answer must be supported by a 1–2 sentence span of your material that contains the answer and the question's key concept terms. Unsupported AI answers are removed and counted; each kept item shows its evidence sentence. Shortfalls are topped up with fill-in-the-blank items built from your text. |
| Save to question bank | Chosen (and edited) quiz items go into a course question bank | needs `edit_question` access to the course; nothing is saved without being selected |
| Question checker | Flags double negatives, vague verbs, multiple questions in one, unclear tasks and grammar; reading grade; Bloom level; AI rewrite suggestion | rewrites that answer the question or drift off topic are discarded |
| Simplify | Rewrites a passage for first-year students, undergraduates or English learners | readability is measured before and after; a rewrite that isn't easier is retried once, then flagged |
| Bloom rewriter | Rewrites a question to a target Bloom level | the result is re-classified by FacultyLens's Bloom analyzer and checked for topic drift; mismatches are flagged |
| OCR → grading | Attach reviewed OCR text to one student's answer for one question, so AI grading assistance can run on handwritten scripts | uses the existing submission rules (question must belong to the assessment, you must be able to edit the submission); replacing an existing answer needs explicit confirmation and keeps the original text |

Small local models need careful prompting: the few-shot prompts (worked examples) for the quiz run in plain completion mode, without the chat template. The study pack takes about 30–60 s on CPU, so it runs as a queued job like OCR and transcription.

Admin switches: `ai_study_pack`, `ai_speech`, `ai_writing` (checker, simplify, Bloom).

### Grading helpers

| Tool | Where | What it does | Safeguards |
| --- | --- | --- | --- |
| Feedback drafter | AI Toolkit tab (paste question, criteria, answer) and **Draft feedback (AI)** in the grading form (uses the question's approved rubric) | Short feedback addressed to the student: what they did well (quoting their own sentence), what is missing, one next step. Tones: encouraging, neutral, concise. | Never produces or changes marks. Built from the grading engine's per-criterion evidence, then re-checked word by word, so it does not praise a point the student did not make. In the grading form it only fills the feedback box; nothing is saved until you submit. Needs an approved rubric and edit access to the answer. |
| Compare answers | AI Toolkit tab | Similarity check for answers that are not stored in FacultyLens (pasted text, OCR of handwritten scripts): flagged pairs, scores and the longest shared passage | Same scoring as the assessment plagiarism check (wording 3-grams with question text removed, plus MiniLM meaning similarity against the group baseline). Nothing is stored. A flag means "look closer", never an automatic penalty. |

With the hosted provider selected, the feedback drafter also rewords its draft into a natural paragraph. The rewrite is discarded if it mentions marks or scores, drops a missing point, adds numbers or copies the prompt's example. The small local model failed these checks in testing, so with local models you get the rubric-built draft (about 1 s).

Stored submissions are still checked from an assessment's **Integrity** page, where flagged pairs can be reviewed and recorded.

Admin switches: `ai_feedback`, `ai_answer_compare`.

### Paper review and class summary

| Tool | Where | What it does | How it works |
| --- | --- | --- | --- |
| Paper review | AI Toolkit tab: paste questions (marks as `[10]` at the end of a line) or pick a stored assessment | Findings list, Bloom balance by share of marks against a target range, topic or course-outcome coverage, duplicate or overlapping questions, wording problems | Bloom level from your own label, otherwise detected from the question verb (a stored level of "Understand" is treated as unset, because it is the database default). Duplicates and coverage use MiniLM, with thresholds calibrated on short exam questions and a word check, so "Explain BFS" and "Explain DFS" are not called duplicates. Default targets: Remember/Understand 20–50%, Apply/Analyze 30–60%, Evaluate/Create 10–30% (the API accepts other ranges). Changes nothing. |
| Class summary | Analysis → performance section of an assessment → **Write class summary** | Plain-language note: class average against the expected level, weakest questions, the rubric points the class most often missed, what it handled well, what to revisit; plus a table of missed rubric points | Uses finalized faculty marks only, plus the per-criterion evidence that AI grading assistance recorded for those answers. AI results you rejected are ignored. The wording is assembled from templates, so it states only what the data shows. It names no student and is not stored. |

Rubric detail appears only for answers that had AI grading assistance; the note says how many.

Admin switches: `ai_paper_review`, `ai_class_summary`.

## Upload limits

- Dev (docker-compose): `backend/docker/php-uploads.ini` sets `upload_max_filesize=110M`.
- Production: `backend/Dockerfile.prod` (110M) and `deploy/nginx/facultylens.conf` (`client_max_body_size 112m`).
- Laravel: `AI_TOOLKIT_MAX_UPLOAD_MB` (default 100) and `AI_TOOLKIT_TIMEOUT` (default 1800 s).

## Known limitations

- TrOCR reads handwriting line by line; messy layouts, diagrams and maths notation are not transcribed reliably.
- Whisper base is fast on CPU but makes more mistakes than larger Whisper models, especially for Bangla. Set `ASR_MODEL=openai/whisper-small` for better accuracy at roughly 3× the time.
- Qwen2.5-0.5B is small: fine for short drafts, weaker at long reasoning. Use the hosted provider for higher quality.
- Hosted mode covers text generation and translation; OCR, transcription and read-aloud always run locally.
- The quiz grounding check is lexical: it reliably rejects swapped or invented facts, but it can also reject a correct answer that paraphrases the material heavily. Fill-in-the-blank items fill the gap.
- The Bloom rewriter's level check uses verb patterns, so a well-phrased rewrite can still be classified at a neighbouring level. Treat the label as a hint.
- Read-aloud voices are clear but robotic; numbers and symbols are spoken as written.
- The feedback drafter is only as good as the rubric: criteria with specific indicators ("mentions that BFS uses a queue") give specific feedback; vague criteria give generic feedback.
- Paper review: MiniLM does not know course abbreviations (it rates "BFS" and "breadth-first search" as only loosely related), so reworded duplicates show as "may test the same thing" rather than "duplicate". Coverage lists the closest questions per topic; a broad topic such as "Graph traversal" can also match a general question such as "Define a graph".
- Answer comparison reliably catches copied or lightly edited text. Heavily reworded copies often score low, because every on-topic answer is semantically similar and only unusually close pairs stand out. With fewer than three answers there is no baseline, so only wording counts.
