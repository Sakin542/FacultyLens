# FacultyLens AI Accuracy & Evaluation Report

**STEP 44 — AI Accuracy & Evaluation Validation** · Report date 2026-09-13 · Branch `feature/ai-accuracy-evaluation`

> **AI assists. Faculty decides.** Every number in this report is a measurement of how well an AI component
> reproduces *expert labels* on a controlled benchmark. None of it is an academic decision, and none of it
> changes a faculty mark, an approved rubric or a published question. Figures come from persisted evaluation
> runs (`ai-service/evaluation/results/latest`, run `20260912T202925Z`, and the pre-fix baseline
> `20260912T201206Z`); nothing here was estimated or typed in by hand.

---

## 1. Evaluation Objective

Determine — with reproducible evidence rather than "plausible output" — how accurate, consistent and
reliable each FacultyLens AI component is, where it fails, how grounded its generated text is, how close
its grading suggestions are to faculty marks, and whether the latest change improved or regressed it.

The evaluation distinguishes three things that are easy to conflate:

| Concept | Question answered | Where it lives |
|---|---|---|
| **AI evaluation** (this report) | How well does the model/engine reproduce expert labels? | `ai-service/evaluation/`, STEP 35 tables |
| **Faculty feedback / acceptance** | Did faculty find the output useful? (accept / edit / dismiss) | STEP 35 `faculty_signals` on `/ai-evaluation` (production data) |
| **Academic decision** | What does the faculty member decide? | Course, assessment and grading workflows — never automated |

## 2. AI Components Evaluated

| # | Component | Engine actually evaluated | Harness component |
|---|---|---|---|
| 1 | Question type classification | `facultylens-question-analyzer` 1.0.0 (regex rules) | `QUESTION_CLASSIFICATION` |
| 2 | Difficulty classification | same (cue-count heuristic) | `DIFFICULTY_CLASSIFICATION` |
| 3 | Bloom / cognitive level | same (leading-verb + keyword rules) | `BLOOM_CLASSIFICATION` |
| 4 | Topic detection | MiniLM cosine vs syllabus topics (thr 0.25 / 0.20, top-3) | `TOPIC_DETECTION` |
| 5 | LO alignment | MiniLM cosine, bands 0.70 / 0.50 | `LO_ALIGNMENT` |
| 6 | Semantic similarity | MiniLM cosine, bands 0.85 / 0.70 / 0.50 | `SEMANTIC_SIMILARITY` |
| 7 | Question-bank retrieval (top-K) | same analyzer, top-5 | `QUESTION_BANK_RETRIEVAL` |
| 8 | Assessment quality | `assessment_quality_engine` via unified pipeline | `ASSESSMENT_QUALITY` |
| 9 | Recommendations | `recommendation_engine` / `problem_detector` | `RECOMMENDATIONS` |
| 10 | Rubric generation | `facultylens-rubric-template-engine` 1.0.0 | `RUBRIC_GENERATION` |
| 11 | Question generation | `facultylens-constrained-question-template-engine` 1.0.0 | `QUESTION_GENERATION` |
| 12 | RAG retrieval | MiniLM chunk ranking (`retrieval.rank_chunks`, min 0.35, top-5) | `RAG_RETRIEVAL` |
| 13 | RAG answer grounding & citations | `facultylens-extractive-answer-engine` 1.0.0 | `RAG_GROUNDING` |
| 14 | AI-assisted grading | `facultylens-grading-engine` 1.0.0 | `AI_GRADING` |
| 15 | Consistency (all of the above) | 3 repeats, embedding cache cleared | `CONSISTENCY` |

## 3. Evaluation Environment

| Item | Value (from `provenance.json`) |
|---|---|
| Harness | `python -m evaluation.run` (in-process against the AI service code; no HTTP) |
| Host | Windows 11 (10.0.26200), CPU only |
| Python / libs | 3.14.0 · sentence-transformers 6.0.1 · transformers 5.16.1 · torch 2.14.0+cpu · numpy 2.5.3 |
| Git commit (code under test) | `9945a64` + STEP 44 working tree |
| Random seed | 42 (bootstrap resampling only; all engines are deterministic) |
| Split | Headline = `TEST` (72 questions); pair/benchmark datasets are used whole |
| Laravel persistence | Docker stack (`app`, `ai-service`, `mysql`); `php artisan ai-evaluation:import … --run --sync` → datasets 26–34, runs 24–32 |

## 4. Models

| Role | Model / engine | Version | Notes |
|---|---|---|---|
| Embeddings (topics, LO, similarity, retrieval, grading, RAG ranking) | `sentence-transformers/all-MiniLM-L6-v2` | configured, dim 384 | Only neural model in the deployed configuration |
| Generation (RAG answers, question drafting) | **not configured** (`HF_GENERATION_MODEL` empty) | — | Extractive / template engines were evaluated; a generative path was *not* |
| Rubric text model | **not configured** (`RUBRIC_GENERATION_ENABLED=false`) | — | Template engine evaluated |
| Rule engines | question-analyzer, rubric-template, grading, constrained-question-template, extractive-answer | 1.0.0 each | Deterministic |

No result in this report should be read as a statement about a generative model — none was used.

## 5. Prompt Versions

| Feature | Version | SHA-256 (system instructions) | Snapshot |
|---|---|---|---|
| document_chat | 1.0.0 | `b9d5725d…78499fdf` | `evaluation/prompts/document_chat_1.0.0.txt` |
| question_generation | 1.0.0 | `b3bd4686…a8dd73b2` | `evaluation/prompts/question_generation_1.0.0.txt` |
| rubric_generation / grading_assistance | 1.0.0 (engine version; no prompt) | — | — |

Only one prompt version exists per feature; see §24 for what a comparison therefore can and cannot say.

## 6. Dataset Description

All datasets are **v1.0 (2026-09-13)**, synthetic, English, written for eight catalogue courses
(`evaluation/datasets/courses_v1.json`: CS201, CS301, CS305, CS306, CS308, CS402, PHY101, ECO101).

| Dataset | File | Size | Split use |
|---|---|---|---|
| Labelled questions | `questions_v1.json` | 144 (DEV 36 / VALIDATION 36 / TEST 72) — 7 types, 3 difficulties, 6 Bloom levels, 41 topics, marks 1–10 | TEST for headline |
| LO alignment pairs | `lo_alignment_pairs_v1.json` | 86 (38 STRONG / 24 WEAK / 24 NOT_ALIGNED) | whole |
| Similarity pairs | `similarity_pairs_v1.json` | 60 (16/14/14/16 by band, 8 hard negatives) + 0–1 expert closeness score | whole |
| Question-bank queries | `retrieval_queries_v1.json` | 24 queries, graded relevance over the 144-question bank | whole |
| RAG corpus + queries | `rag_corpus_v1.json` | 4 documents / 16 chunks (1 injection payload); 24 queries (20 answerable, 2 unanswerable, 2 injection) | whole |
| Grading benchmark | `grading_benchmark_v1.json` | 8 questions × 3 answers = 24, rubrics + faculty marks + rationale | whole |
| Assessment review cases | `assessment_cases_v1.json` | 8 composed assessments with per-dimension OK/FLAG, expected recommendation categories, rating band, 6 pairwise orderings | whole |
| Generation requests | `generation_requests_v1.json` | 50 rubric requests; 26 question-generation constraint sets (4 document-grounded, 2 with similarity restriction, 1 blueprint) | whole |

Size categories (STEP 35 convention): questions = MODERATE; pairs/benchmarks = LIMITED; assessment cases = VERY_LIMITED.

## 7. Ground Truth Method

Labels follow `evaluation/labels/LABELING_PROTOCOL.md` and were written **from the text alone before any
model output was inspected**. The FacultyLens AI was never used to produce or pre-fill a label.

- **Annotators:** one (A1, project developer with TA experience). A second annotator was **not available**,
  so inter-annotator agreement is *not measurable* on v1.0. Ambiguity is approximated by `label_confidence`
  (HIGH 85 / MEDIUM 53 / LOW 6 of 144 questions), `secondary_cognitive_level` (47 questions),
  `acceptable_topics` and `acceptable_learning_outcomes`.
- **Difficulty** is acknowledged as the most subjective label; **WEAK** LO alignment is the least reliable band.
- **Grading** marks carry a written rationale per answer so a second grader can audit them.
- **Human quality ratings** (recommendations, rubrics, generated questions, RAG answers, grading feedback) require
  the faculty form `evaluation/labels/HUMAN_REVIEW_FORM.md`. **Zero forms exist for v1.0 → NOT_EVALUATED.**

## 8. Question Classification Results

| Metric (TEST, n = 72) | Value |
|---|---|
| Accuracy | 0.667 (Wilson 95 % CI 0.552–0.765) |
| Macro precision / recall / **macro F1** | 0.776 / 0.796 / **0.753** (bootstrap CI 0.658–0.830) |
| Weighted F1 | 0.657 |
| Accuracy on HIGH-confidence labels / MEDIUM-LOW labels | 0.769 / 0.545 |
| Confidence calibration (ECE) | 0.175 — the 0.6–0.8 confidence bin is 23 % accurate, the 0.8–1.0 bin 76 % |

Per class (P / R / F1 / support): MCQ 1.00/1.00/1.00/4 · TRUE_FALSE 1.00/1.00/1.00/5 · SHORT_ANSWER 0.83/0.83/0.83/6 ·
CONCEPTUAL 0.55/0.86/0.67/7 · ANALYTICAL 0.60/0.75/0.67/12 · DESCRIPTIVE 0.45/0.77/0.57/13 · **PROBLEM_SOLVING 1.00/0.36/0.53/25**.

Top confusions: PROBLEM_SOLVING→DESCRIPTIVE 10, PROBLEM_SOLVING→CONCEPTUAL 3, PROBLEM_SOLVING→ANALYTICAL 3.
Status: **ACCEPTABLE** (target macro F1 ≥ 0.80 not met).

## 9. Difficulty Results

| Metric (TEST, n = 72) | Value |
|---|---|
| Accuracy | 0.569 (CI 0.454–0.677) |
| **Macro F1** | **0.559** (CI 0.427–0.669) · weighted F1 0.574 |
| Per class F1 | EASY 0.61 (n 25) · MEDIUM 0.58 (n 33) · HARD 0.48 (n 14) |
| Direction | over-classified 19, under-classified 12; mean ordinal shift +0.125 |
| Confusions | EASY→MEDIUM 8 · MEDIUM→HARD 8 · MEDIUM→EASY 6 · HARD→MEDIUM 5 · EASY→HARD 3 · HARD→EASY 1 |
| Accuracy on HIGH vs MEDIUM-LOW confidence labels | 0.564 vs 0.576 — errors are *not* concentrated on ambiguous items |
| Confidence | Not available (engine emits none) |

Status: **NEEDS_IMPROVEMENT** (target macro F1 ≥ 0.70 not met). Evaluator disagreement caveat: difficulty
labels are single-annotator; the 4 EASY↔HARD confusions are unambiguous errors, the 27 adjacent-band ones partly reflect label subjectivity.

## 10. Bloom Classification Results

| Metric (TEST, n = 72) | Value |
|---|---|
| Strict accuracy | 0.583 (CI 0.468–0.690) |
| **Lenient accuracy** (primary *or* annotator's secondary level) | 0.653 |
| **Macro F1** | **0.636** (CI 0.504–0.736) · weighted F1 0.571 |
| Per class F1 | REMEMBER 0.38 · UNDERSTAND 0.56 · APPLY 0.46 · ANALYZE 0.78 · EVALUATE 0.73 · CREATE 0.91 |
| Direction | under-classified 19, over-classified 11; mean ordinal shift −0.22 (systematic *under*-classification toward UNDERSTAND) |
| Confusions | **APPLY→UNDERSTAND 11**, **REMEMBER→UNDERSTAND 9**, APPLY→REMEMBER 2 |

The 7-point gap between strict and lenient accuracy is the measured share of annotator ambiguity; the
remaining 35 % error is model error, concentrated in APPLY and REMEMBER. Status: **ACCEPTABLE** (target ≥ 0.70 not met).

## 11. Topic Detection Results

| Metric (TEST, n = 72) | Value |
|---|---|
| **Top-1 accuracy** (expected or acceptable topic) | **0.792** (CI 0.684–0.870) |
| Top-3 accuracy | 0.944 |
| Multi-label (top-3 vs acceptable set): micro P / R / F1 | 0.518 / 0.877 / 0.651 · macro F1 0.619 · Hamming loss 0.176 |
| Calibration of cosine as confidence (ECE) | 0.351 — cosine is *not* a calibrated probability |

Typical misses: "List three advantages of using views" → *Relational Model and Keys* (expected SQL Queries, 0.39);
"What is a context switch?" → *Threads and Concurrency* (0.21, fallback band). Status: **ACCEPTABLE**.

## 12. LO Alignment Results

Production path (question text enriched with AI-detected topics, as in the unified pipeline):

| Metric | Value |
|---|---|
| **Band agreement** STRONG/WEAK/NOT_ALIGNED (86 pairs) | **0.314** (CI 0.226–0.418) · macro F1 0.224 · κ 0.046 |
| Aligned-vs-not accuracy / F1 | 0.488 / 0.450 |
| Adjacent-band / two-band errors | 37 / 22 |
| Agreement on HIGH-confidence pairs / MEDIUM-LOW pairs | 0.418 / 0.129 |
| **Top-1 LO accuracy** (71 TEST questions vs all course LOs) | **0.761** (CI 0.650–0.845) · top-2 0.873 · MRR 0.854 |
| Bare-text variant | band agreement 0.279 · top-1 0.732 · MRR 0.835 |

Confusion (rows = expert): STRONG → STRONG 1 / WEAK 15 / NOT_ALIGNED 22; WEAK → WEAK 2 / NOT_ALIGNED 22; NOT_ALIGNED → NOT_ALIGNED 24.

Similarity distribution by expert band: STRONG median 0.484 (max 0.717), WEAK median 0.424 (max 0.520),
NOT_ALIGNED median 0.122 (max 0.440). **Only 1 of 38 expert-STRONG pairs reaches the 0.70 production
threshold.** The ranking signal is useful (the right LO is top-1 for 76 % of questions), the *band labels* are
not: at 0.70/0.50 almost everything faculty see is "NOT_ALIGNED".

Threshold sensitivity (informational, thresholds were **not** changed): strong/weak 0.45/0.30 → agreement 0.663,
macro F1 0.647; 0.50/0.35 → 0.616; production 0.70/0.50 → 0.314. Status: **NEEDS_IMPROVEMENT**.

## 13. Semantic Similarity Results

| Metric (60 pairs) | Value |
|---|---|
| **Spearman** (model cosine vs expert closeness) | **0.925** (bootstrap CI 0.871–0.952) · Pearson 0.887 |
| Band accuracy (4 bands) | 0.517 (CI 0.393–0.638) · macro F1 0.450 · adjacent-band accuracy 0.967 |
| Duplicate detection (≥ 0.85) P / R / F1 | 0.917 / 0.688 / 0.786 |
| Reuse flag (≥ 0.70: duplicate or highly similar) P / R / F1 | 1.000 / 0.700 / 0.824 |
| Hard negatives (8) | max score 0.237, false flags 0 |

Score distribution: POTENTIAL_DUPLICATE median 0.885 (min 0.770); HIGHLY_SIMILAR median 0.678; SOMEWHAT_SIMILAR
median 0.250 (max 0.465); NOT_SIMILAR median 0.129. Ranking quality is excellent and precision of flags is
perfect; the bands are conservative — 5/16 expert duplicates fall in 0.77–0.84 and *all 14* SOMEWHAT_SIMILAR
pairs score below 0.50. Threshold sweep (informational): duplicate threshold 0.70–0.75 → duplicate F1 0.865
(P 0.76, R 1.00); production 0.85 → 0.786. This is similarity ranking, **not exact-duplicate detection**.
Status: **EXCELLENT** on ranking; band calibration is a documented weakness.

### Top-K question-bank retrieval (24 queries, bank 144)

| K | Precision@K | Recall@K | Hit-rate@K |
|---|---|---|---|
| 1 | 0.958 | 0.639 | 0.958 |
| 3 | 0.514 | 0.910 | 1.000 |
| 5 | 0.325 | **0.944** | 1.000 |

MRR 0.979 · NDCG@5 0.935 · queries with full recall@5 87.5 % (Wilson CI 0.690–0.957). Precision@K is bounded
by 1–3 relevant items per query. Three paraphrase queries ranked second, never lower. Status: **GOOD**.

## 14. Assessment Quality Results

8 reviewer-labelled assessments; system "flag" = a recommendation exists in the mapped category.

| Metric | Pre-fix baseline | **Current** |
|---|---|---|
| Per-dimension flag agreement (48 decisions) | 0.750 | **0.833** (CI 0.704–0.913) · κ 0.667 |
| Overall rating inside reviewer band — pipeline / engine-only | 1.000 / 1.000 | 1.000 / 1.000 |
| Pairwise ordering agreement (6 pairs) | 1.000 | 1.000 |
| Mean component score where reviewer flagged / OK | 58.8 / 85.4 | 58.8 / 85.4 |

Per dimension agreement: marks_distribution 1.00 · topic_coverage 0.875 · cognitive_diversity 0.875 ·
learning_outcome_coverage 0.75 · difficulty_balance 0.75 · question_diversity 0.75. Remaining disagreements:
difficulty flagged on two balanced papers (4/4/2 and 3/2/1 splits deviate > 30 % from the 30/50/20 target in *marks*),
question-diversity not flagged when two formats share 100 % (rule needs ≥ 80 % in *one* format), LO gaps on
AQ7 masked by the same LO mis-ranking seen in §12. Status: **GOOD** on a VERY_LIMITED sample.

## 15. Recommendation Evaluation

| Metric | Pre-fix | **Current** |
|---|---|---|
| Category precision / recall / **F1** vs reviewer-expected categories (25) | 0.72 / 0.72 / 0.72 | **0.808 / 0.840 / 0.824** (CI 0.681–0.920) |
| Recommendations generated | 50 | 36 |
| Evidence attached / linked to a detected problem / actionable verb / readable length | 1.00 / 1.00 / 1.00 / 1.00 | 1.00 / 1.00 / 1.00 / 1.00 |
| Contradictions within a case | 0 | 0 |
| **Faculty 1–5 ratings (relevance, clarity, appropriateness)** | NOT_EVALUATED | **NOT_EVALUATED** (0 review forms) |

The pre-fix engine raised "No foundational (Easy) difficulty questions" on **every** assessment (§22, finding F1)
and could never raise marks or single-format problems; after the fix the false "difficulty" category
disappeared from balanced papers and the missing "marks_distribution" and "semantic_similarity" categories
appear. Status: **GOOD** (automated); human quality **NOT_EVALUATED**.

## 16. Rubric Evaluation

| Metric (50 requests) | Value |
|---|---|
| **Structural constraint satisfaction** (all 7 checks) | **1.000** (CI 0.929–1.000) |
| `SUM(criteria marks) = question marks` | 1.000 (50/50) |
| Criteria count in range, positive marks, named, unique, described, sequential | 1.000 each |
| Mean criteria per rubric | 3.82 (1–7) |
| Lexical question-alignment proxy | 0.761 (indicator only) |
| Generation method | template_based 50/50 |
| Laravel STEP 35 run #31 | marks_validity_rate = 1.0, gate PASSED |
| **Faculty quality ratings** (relevance, clarity, specificity, coverage, appropriateness) | **NOT_EVALUATED** |

Status: **EXCELLENT** for constraints; rubric *quality* is unmeasured and rubrics remain drafts.

## 17. Question Generation Evaluation

| Metric (26 requests → 55 drafts) | Value |
|---|---|
| **Independent constraint-check pass rate** (498 checks) | **0.982** (CI 0.967–0.990) |
| Fully satisfied drafts | 0.818 (45/55) |
| Generation completeness | 1.000 |
| Per constraint | type 1.00 · marks 1.00 · difficulty 1.00 · Bloom 1.00 · topic 1.00 · format 1.00 · language 1.00 · similarity restriction 1.00 · no-leak 1.00 · **LO alignment 0.818** |
| Document-grounded drafts (9): hallucination flags / unsupported domain-term rate | 0 / 0.000 |
| Injection chunk propagated into a draft | 0 (QG23 included chunk 404) |
| Generator self-validation | PASSED 10 · PASSED_WITH_WARNINGS 35 · FAILED 10 |
| Self-validator agreement with the requested slot | difficulty 0.564 · Bloom 0.964 · type 0.964 |
| Laravel STEP 35 run #30 (19 plain requests) | constraint_satisfaction_rate = **0.184**, gate FAILED |

**The two constraint-satisfaction numbers measure different things.** The Python harness counts *independently
verified* constraints (declared type/marks/difficulty/Bloom equal the request, topic words present, MCQ options,
English, cosine < 0.85 to existing questions). The STEP 35 evaluator counts a draft as satisfied only when the
generator's *own* validator reports zero warnings — and that validator re-classifies the draft's difficulty with the
§9 heuristic (agreement 56 %) and requires CO alignment ≥ 0.50 with §12's under-scored cosine. Both are real;
the 0.18 mostly re-measures the difficulty and LO weaknesses, not the template engine's constraint fidelity.
The 10 LO failures are all cosine < 0.50 between a template sentence and the outcome text.

Human quality (clarity, validity, non-ambiguity, originality): **NOT_EVALUATED**. Drafts stay `DRAFT`.
Status: **EXCELLENT** (constraints, template engine); generative path not evaluated.

## 18. RAG Retrieval Results

| Metric (20 answerable queries, 16 chunks) | Value |
|---|---|
| Recall@1 / @3 / **@5** | 0.900 / 1.000 / **1.000** (CI 0.839–1.000) |
| Precision@1 / @3 / @5 | 0.950 / 0.367 / 0.220 |
| MRR / NDCG@5 | 0.975 / 0.975 |
| Queries where the 0.35 relevance threshold dropped **all** relevant chunks | 1 (G009 "convoy effect") |

Two queries ranked the answer chunk second (BCNF-vs-3NF trade-off; exponential averaging). Status:
**EXCELLENT** on a small, clean corpus — see §26 for why this does not generalise.

## 19. RAG Grounding Results

| Metric (24 queries) | Value |
|---|---|
| **Grounded (SUPPORTED) answer rate** on 20 answerable | **0.900** (CI 0.699–0.972) |
| Partially supported / unsupported-claim rate | 0.050 / 0.000 |
| False refusal rate | 0.050 (G009 — retrieval threshold removed the chunk) |
| **Correct refusal rate** on 2 unanswerable | **0.000** (0/2) |
| Citation accuracy (cited source ∩ relevant chunk) | 1.000 |
| Citation completeness (every quoted claim carries `[S#]`) | 1.000 |
| Claim support rate (quoted sentence verbatim in cited chunk) | 1.000 |
| Injection leak rate (2 injection queries) | 0.000 |
| Generation method | extractive 23 · insufficient_evidence 1 |

Citations are exact because the extractive engine quotes sentences — a *generative* model would need this
test re-run. The measured failure mode is scope: for "Who teaches CS301?" and "pass mark for the **CS305**
final" the engine quoted the nearest CS301 sentence instead of declining (`UNSUPPORTED_CLAIM`). Status: **GOOD**
with a documented refusal weakness.

## 20. AI Grading Results

| Metric (24 answers, 8 questions) | Value |
|---|---|
| MAE / RMSE (marks) | 0.625 (bootstrap CI 0.417–0.854) / 0.829 |
| **MAE normalised by question total** | **0.166** (CI 0.109–0.232) — ≈ 1.7 marks on a 10-mark question |
| Mean signed error / median | −0.125 (slight under-scoring) / 0.00 |
| Pearson / Spearman with faculty marks | 0.815 / 0.822 |
| Exact agreement · within 0.5 · within 1.0 · within 2.0 marks | 0.208 · 0.583 · 0.875 · 1.000 |
| Within 10 % / 20 % of total | 0.375 / 0.708 |
| Large-error rate (≥ 25 % of total) | 0.292 (7/24) |
| Laravel STEP 35 run #27 | mae = 0.625, gate PASSED_WITH_WARNINGS (mae ≤ 1.0) |

Bias analysis (signed error, n ≥ 3): AI **under-scores faculty-STRONG answers by 0.67 marks** (n 9) and
medium-length answers (40–80 words) by 0.62 (n 8); **over-scores ANALYTICAL/ANALYZE answers by 0.67** (n 3).
The two largest errors are numerical: a fully correct SJF calculation received 1/3 (the engine cannot verify
arithmetic and the indicator strings "0", "1", "5" are too short to match), and a wrong FCFS answer received 1.5/3.
Faculty tolerance for this benchmark (≤ 1 mark on ≤ 5-mark questions) is met for 87.5 % of answers but the
normalised MAE misses the ACCEPTABLE band (0.15). Status: **NEEDS_IMPROVEMENT**; suggestions remain advisory.

## 21. Consistency Results

| Component | Items | Repeats | Label consistency | Max score range |
|---|---|---|---|---|
| Question analysis (type/difficulty/Bloom/topic) | 40 | 3 | 1.000 | — |
| LO alignment | 40 | 3 | 1.000 | 0.000 |
| Similarity | 40 | 3 | 1.000 | 0.000 |
| Grading marks | 24 | 3 | 1.000 | 0.000 |
| Rubric structure | 20 | 3 | 1.000 | — |
| Question generation (identical text, template engine) | 10 | 3 | 1.000 | — |
| **Overall** | 174 | 3 | **1.000** (CI 0.978–1.000) | 0.000 |

The embedding LRU cache was cleared between repeats, so MiniLM re-encoded every text. Status: **EXCELLENT**.
Acceptable-variation policy for a future generative model: type/marks/count must match; wording may differ.

## 22. Error Analysis

All example-level failures carry one code from the STEP 44 taxonomy (`evaluation/taxonomy.py`; STEP 35 codes are
mapped onto it). Breakdown for the current run (all components): WRONG_LO_ALIGNMENT 76 · WRONG_CLASSIFICATION 41 (24 question type,
8 quality-dimension flags, 9 recommendation categories) · WRONG_DIFFICULTY 31 · WRONG_BLOOM_LEVEL 30 · MISSED_SIMILARITY 30 (28 pairs, 2 RAG
rankings) · WRONG_TOPIC 15 · CONSTRAINT_VIOLATION 10 · GRADING_ERROR 7 · FALSE_SIMILARITY 4 · UNSUPPORTED_CLAIM 4 · HALLUCINATION 0 ·
WRONG_RUBRIC 0 · WRONG_CITATION 0 · INCONSISTENT_OUTPUT 0 · TIMEOUT 0 · MODEL_FAILURE 0.

| # | Example | Expected | Actual | Error type | Likely cause | Potential fix |
|---|---|---|---|---|---|---|
| 1 | "Write an SQL query to list the names of students…" | PROBLEM_SOLVING / APPLY / MEDIUM | CONCEPTUAL / REMEMBER / EASY | WRONG_CLASSIFICATION, WRONG_BLOOM_LEVEL, WRONG_DIFFICULTY | `list` matches the CONCEPTUAL/REMEMBER/EASY cue before `write…query` is considered | Order rules by deliverable (code/query/diagram) before recall verbs |
| 2 | "Draw an ER diagram for a library system…" | PROBLEM_SOLVING / CREATE | DESCRIPTIVE / UNDERSTAND | WRONG_CLASSIFICATION, WRONG_BLOOM_LEVEL | "draw", "diagram", "design" absent from PROBLEM_SOLVING/CREATE rules | Add constructive-deliverable cues |
| 3 | 11 APPLY questions (calculate/derive/trace) | APPLY | UNDERSTAND | WRONG_BLOOM_LEVEL | Leading-verb rule matches "explain/describe" appearing later in multi-part stems | Weight the main task verb, not the first matching verb |
| 4 | "Compare optimistic and pessimistic concurrency control…" | HARD | MEDIUM | WRONG_DIFFICULTY | Difficulty is a cue count; comparisons score 2 regardless of depth | Use marks and Bloom level as features |
| 5 | 37 expert-STRONG (question, LO) pairs | STRONG | WEAK / NOT_ALIGNED | WRONG_LO_ALIGNMENT | MiniLM cosine between a short question and an outcome sentence peaks ≈ 0.5–0.7 | Re-derive bands from labelled data (see §12 sweep) or rank-based labelling; **not changed here** |
| 6 | "List the seven layers of the OSI model" vs "Name the OSI model's seven layers…" | POTENTIAL_DUPLICATE | HIGHLY_SIMILAR (0.838) | MISSED_SIMILARITY | Paraphrases with different verbs land in 0.77–0.84 | Report as "likely duplicate" band 0.75–0.85 to faculty; keep flag precision 1.0 |
| 7 | SJF waiting-time answer, fully correct | 3/3 | 1/3 | GRADING_ERROR | Engine matches indicator *text*; numeric indicators "0", "1", "5" are stop-word length | Numeric-aware indicator matching; faculty review remains mandatory |
| 8 | "What is the pass mark for the CS305 final exam?" | decline | quoted CS301 pass mark | UNSUPPORTED_CLAIM | Extractive engine has no scope check; lexical overlap ("pass", "final", "exam") suffices | Require course/document scope match or entity overlap before answering |
| 9 | "What is the convoy effect?" | answer from chunk 301 | "couldn't find enough information" | UNSUPPORTED_CLAIM (false refusal) | Query–chunk cosine 0.33 < 0.35 relevance threshold | Sensitivity of `chat_min_relevance_score`; not changed |

### Findings that led to a code change (F1, F2)

- **F1 – Recommendation inputs silently zeroed.** `assessment_analysis_service.py` passed quality-engine dicts into
  `Recommendation*Input` schemas whose field names differ (`question_percentage` vs `percentage`,
  `assessment_expected_marks` vs `total_marks_expected`, …). Unmatched fields kept their defaults, so
  `easy_percentage = 0` made "No foundational (Easy) questions" fire on every assessment while marks-mismatch,
  mark-concentration and single-format problems could never fire. Fixed with `model_validator(mode="before")`
  adapters in `app/schemas/recommendation.py` (also covers Laravel's direct `/generate-recommendations` call) and a
  topic rule that trusts the engine's `coverage_status` (`problem_detector.py`).
- **F2 – Similarity never reached the quality/recommendation engines.** The pipeline read a `question_similarities`
  key the analyzer does not emit (`results` / `max_similarity_score`). Fixed in `assessment_analysis_service.py`.
- Regression tests: `tests/test_recommendation_input_mapping.py` (6 tests, one end-to-end through the unified pipeline).

## 23. Human Evaluation

| Component | Forms received | Status |
|---|---|---|
| Recommendations | 0 | NOT_EVALUATED |
| Rubric generation | 0 | NOT_EVALUATED |
| Generated questions | 0 | NOT_EVALUATED |
| RAG answers | 0 | NOT_EVALUATED |
| AI grading suggestions | 0 | NOT_EVALUATED |

The review form, scale (1 Poor – 5 Excellent), dimensions, decision codes and JSON schema are in
`evaluation/labels/HUMAN_REVIEW_FORM.md`; the harness aggregates completed forms automatically (mean/median per
dimension, acceptance rate, major-error rate, Cohen's κ when two reviewers rate the same item). No automated
proxy in this report is presented as a substitute for these ratings.

## 24. Model / Prompt Comparison

- **Models:** one embedding model (MiniLM-L6-v2) and no generation model are configured, so there is no second
  model to compare against. The harness supports it (run twice with different configuration, then
  `compare()` / STEP 35 `GET /api/ai/evaluation/compare?run_a&run_b`), but **no Model A vs Model B result exists**
  and none is claimed.
- **Prompts:** one version (1.0.0) per feature. Prompt hashes are recorded in provenance; a v2 would be compared
  by the same regression mechanism.
- **Variants actually compared:** LO alignment with vs without topic enrichment (§12: band agreement 0.314 vs 0.279,
  top-1 0.761 vs 0.732) and the quality pipeline vs engine-only (§14).

## 25. Regression Results

Baseline = pre-fix run `20260912T201206Z`; current = `20260912T202925Z`; tolerance 5 % relative drop (2 % rubric, 10 % grading, 1 % consistency).

| Component | Headline | Baseline | Current | Status |
|---|---|---|---|---|
| QUESTION_CLASSIFICATION | macro_f1 | 0.7525 | 0.7525 | STABLE |
| DIFFICULTY_CLASSIFICATION | macro_f1 | 0.5594 | 0.5594 | STABLE |
| BLOOM_CLASSIFICATION | macro_f1 | 0.6364 | 0.6364 | STABLE |
| TOPIC_DETECTION | top1_accuracy | 0.7917 | 0.7917 | STABLE |
| LO_ALIGNMENT | band_agreement | 0.3140 | 0.3140 | STABLE |
| SEMANTIC_SIMILARITY | spearman | 0.9252 | 0.9252 | STABLE |
| QUESTION_BANK_RETRIEVAL | recall_at_5 | 0.9444 | 0.9444 | STABLE |
| RAG_RETRIEVAL | recall_at_5 | 1.0000 | 1.0000 | STABLE |
| RAG_GROUNDING | grounded_answer_rate | 0.9000 | 0.9000 | STABLE |
| AI_GRADING | mae_normalized (lower better) | 0.1655 | 0.1655 | STABLE |
| RUBRIC_GENERATION | constraint_satisfaction_rate | 1.0000 | 1.0000 | STABLE |
| QUESTION_GENERATION | constraint_satisfaction_rate | 0.9819 | 0.9819 | STABLE |
| ASSESSMENT_QUALITY | flag_agreement | 0.7500 | **0.8333** | IMPROVED |
| RECOMMENDATIONS | category_f1 | 0.7200 | **0.8235** | IMPROVED |
| CONSISTENCY | label_consistency | 1.0000 | 1.0000 | STABLE |

Gate result: **PASSED** — no headline metric regressed; the two fixed components improved. The full AI test suite
(252 tests) and the STEP 35 backend tests (16) pass. Gates live in `evaluation/gates.json`; `--strict` makes
`python -m evaluation.run` exit 1 on a regression.

## 26. Limitations

1. **Dataset size** — 144 questions (72 in TEST), 60 pairs, 24 queries, 24 graded answers, 8 assessments. CIs are
   wide (e.g. difficulty F1 0.43–0.67); assessment-quality and recommendation numbers rest on 8 cases.
2. **Label quality** — single annotator, no inter-annotator agreement. Difficulty and WEAK alignment are
   subjective; `label_confidence` and secondary labels only approximate disagreement.
3. **Synthetic data** — questions, documents and student answers were written for this evaluation by one author.
   Real exam papers, real course materials and real student answers will be noisier, longer and more diverse.
4. **Domain** — mostly computer science plus one physics and one economics course; no humanities, no non-English text.
5. **Models** — only MiniLM embeddings and rule/template engines were evaluated. No claim is made about any
   generative model; configuring one invalidates §16–§19 until re-run.
6. **RAG corpus** — 16 hand-written chunks with distinct vocabulary; retrieval recall of 1.0 will not transfer to
   hundreds of overlapping lecture-slide chunks.
7. **Grading** — 24 answers, none image-based, none longer than 120 words; numeric-answer questions expose a
   structural weakness rather than a sampling artefact.
8. **Threshold sensitivity** — LO and similarity bands are shown to be mis-calibrated on this benchmark; sweeps are
   reported but thresholds were not changed, because retuning on the same data would overfit.
9. **Proxies** — hallucination, question-alignment and contradiction checks are lexical proxies, clearly labelled as such.
10. **Faculty acceptance** is not measured here at all; it is a separate signal on the STEP 35 dashboard.

## 27. Known Failure Modes

| Failure mode | Component | Evidence |
|---|---|---|
| Deliverable-type questions (write query, draw diagram, derive, trace) classified as DESCRIPTIVE/CONCEPTUAL | Type, Bloom | PROBLEM_SOLVING recall 0.36; APPLY recall 0.30 |
| Systematic Bloom under-classification toward UNDERSTAND | Bloom | 20 of 30 errors land on UNDERSTAND |
| Difficulty ≈ coin flip between adjacent bands | Difficulty | 27/31 errors adjacent; accuracy 0.57 |
| STRONG alignments reported as NOT_ALIGNED | LO alignment | 1/38 STRONG pairs ≥ 0.70 |
| Expert duplicates in the 0.77–0.85 gap shown as "highly similar" | Similarity | 5/16 duplicates |
| Extractive RAG answers out-of-scope questions with the nearest sentence instead of declining | RAG grounding | 0/2 correct refusals |
| Relevance threshold 0.35 drops a relevant chunk | RAG retrieval | 1/20 queries |
| Numeric answers cannot be verified; strong answers under-scored | Grading | −0.67 marks on STRONG tier; SJF example |
| Template drafts fail CO alignment with the outcome text | Question generation | 10/55 drafts, all cosine < 0.50 |
| Topic side-swaps between adjacent syllabus topics (SQL ↔ Keys; Transport ↔ OSI) | Topic | 15 top-1 misses, 11 recovered in top-3 |

## 28. Recommended Improvements

1. **Question analyzer** — order rules by deliverable, add constructive cues (draw/diagram/design/derive/trace), weight the main task verb; use marks + Bloom as difficulty features. Re-run and compare against this baseline.
2. **LO alignment presentation** — until bands are re-derived from labelled data, show the ranked LO with its
   score and reserve "NOT_ALIGNED" for cosine well below the WEAK maximum observed here (0.52); any change must be
   re-evaluated on a *new* labelled set, not this one.
3. **Similarity** — surface a "likely duplicate" band (0.75–0.85) in the UI; keep production thresholds until a second annotator confirms the duplicate labels.
4. **RAG** — add a scope/entity check before extractive answering; evaluate `chat_min_relevance_score` 0.30 on a larger corpus; re-run §18–§19 when a generation model is configured.
5. **Grading** — numeric-aware indicator matching; expose criterion-level coverage to faculty (already in the response) and keep suggestions advisory.
6. **Datasets** — recruit a second annotator (target κ ≥ 0.6 on difficulty and Bloom), add real anonymised exam papers under the data policy, extend to non-CS domains.
7. **Human review** — collect ≥ 30 forms per generative component before any quality claim.
8. **Governance** — run `bash scripts/run-ai-evaluation.sh --strict` (and `--laravel` when the stack is up) after every prompt, model, threshold or preprocessing change.

## 29. Final Evaluation Status

| Component | Headline metric | Value (95 % CI) | n | Status |
|---|---|---|---|---|
| Question classification | macro F1 | 0.753 (0.658–0.830) | 72 | ACCEPTABLE |
| Difficulty classification | macro F1 | 0.559 (0.427–0.669) | 72 | NEEDS_IMPROVEMENT |
| Bloom classification | macro F1 | 0.636 (0.504–0.736) | 72 | ACCEPTABLE |
| Topic detection | top-1 accuracy | 0.792 (0.684–0.870) | 72 | ACCEPTABLE |
| LO alignment | band agreement | 0.314 (0.226–0.418) · top-1 LO 0.761 | 86 / 71 | NEEDS_IMPROVEMENT |
| Semantic similarity | Spearman | 0.925 (0.871–0.952) | 60 | EXCELLENT |
| Question-bank retrieval | recall@5 | 0.944 (0.690–0.957) | 24 | GOOD |
| Assessment quality | flag agreement | 0.833 (0.704–0.913) | 8 cases / 48 decisions | GOOD (VERY_LIMITED) |
| Recommendations | category F1 | 0.824 (0.681–0.920) | 8 cases | GOOD (automated); human NOT_EVALUATED |
| Rubric generation | constraint satisfaction | 1.000 (0.929–1.000) | 50 | EXCELLENT (constraints); human NOT_EVALUATED |
| Question generation | constraint satisfaction | 0.982 (0.967–0.990) | 55 drafts | EXCELLENT (constraints); human NOT_EVALUATED |
| RAG retrieval | recall@5 | 1.000 (0.839–1.000) | 20 | EXCELLENT (small corpus) |
| RAG grounding | grounded answer rate | 0.900 (0.699–0.972); refusals 0/2 | 24 | GOOD |
| AI grading | normalised MAE | 0.166 (0.109–0.232) | 24 | NEEDS_IMPROVEMENT |
| Consistency | label consistency | 1.000 (0.978–1.000) | 174 | EXCELLENT |
| Model / prompt comparison | — | single model, single prompt version | — | NOT_EVALUATED |
| Human expert evaluation | — | 0 forms | — | NOT_EVALUATED |

**Overall:** FacultyLens now has measurable, reproducible evidence for every AI component. Similarity ranking,
retrieval, consistency and structural constraints are strong; rule-based difficulty/Bloom classification, LO
alignment *bands* and numeric grading are weak and are documented as such; two pipeline defects surfaced by the
evaluation were fixed and verified by regression. All results remain **assistive evidence** — faculty retain
final authority over classifications, alignments, recommendations, drafts and marks.

---

### Reproduce

```bash
cd ai-service
python -m evaluation.run                 # TEST split -> evaluation/results/latest (+ regression vs results/baseline)
python -m evaluation.run --split ALL --out evaluation/results/all
python -m evaluation.export_laravel      # STEP 35 payloads -> evaluation/results/laravel_import
# persist in Laravel (Docker stack up):
docker compose exec -T app php artisan ai-evaluation:import storage/app/ai-evaluation-benchmark --run --sync --replace
# or both at once:
bash scripts/run-ai-evaluation.sh --laravel
```

Artefacts: `evaluation/results/{baseline,latest}/{summary,provenance,regression,<COMPONENT>}.json`,
`evaluation/predictions/<run_id>/*.json`, `evaluation/prompts/*.txt`, STEP 35 runs on `/ai-evaluation`.
