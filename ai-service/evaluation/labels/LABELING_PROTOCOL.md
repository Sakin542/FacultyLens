# FacultyLens AI Evaluation — Labeling Protocol (v1.0)

This protocol governs every ground-truth label in `evaluation/datasets/`. Labels are
**human judgements written from the text alone, before any model output was inspected**.
The FacultyLens AI service was never used to produce, suggest or "pre-fill" a label.

| Item | Value |
|---|---|
| Dataset version | v1.0 (2026-09-13) |
| Annotators | A1 (project developer with university teaching-assistant experience; not a domain expert in every course) |
| Second annotator | **Not available** — inter-annotator agreement cannot be measured on v1.0 (see Limitations) |
| Data origin | Synthetic questions/documents/answers written for the eight catalogue courses in `courses_v1.json`; no real student, faculty or institutional records |
| Splits | `DEV` (i mod 4 = 0), `VALIDATION` (i mod 4 = 2), `TEST` (otherwise); the harness reports TEST as the headline split and never tunes on it |

## Question type

| Label | Rule |
|---|---|
| `MCQ` | Options are present or the stem says "which of the following". |
| `TRUE_FALSE` | The stem asks for a true/false judgement. |
| `PROBLEM_SOLVING` | A computation, derivation, proof, code/query, trace, diagram or constructive design is the deliverable. |
| `ANALYTICAL` | Comparison, critique, justification, evaluation or analysis of a given artefact/claim. |
| `CONCEPTUAL` | Recall or short statement of a concept, definition, list or fact (including "what does it mean"). |
| `SHORT_ANSWER` | The stem explicitly limits length ("briefly", "short notes", "outline", fill-in-the-blank). |
| `DESCRIPTIVE` | An extended explanation/discussion is expected and none of the above applies. |

Open-ended *design* tasks were labelled `PROBLEM_SOLVING` when the deliverable is an artefact
(schema, architecture, class structure) and `DESCRIPTIVE` when it is a written proposal. This
boundary is genuinely fuzzy; such items carry `label_confidence: MEDIUM|LOW`.

## Difficulty

| Label | Rule |
|---|---|
| `EASY` | One recall or single-step understanding/application; no integration of concepts. |
| `MEDIUM` | Explanation with reasoning, standard multi-step application, comparison of two items. |
| `HARD` | Multi-concept integration, proof/derivation, open-ended design or evaluation under a scenario. |

Difficulty is relative to a typical undergraduate cohort in the course and is the most
subjective label in the set. `label_confidence` is recorded per item; boundary items are
reported separately as *lenient* agreement where a secondary label exists.

## Bloom / cognitive level

The revised Bloom taxonomy (REMEMBER … CREATE) was applied to the **cognitive demand**, not
just the leading verb. Where two levels are defensible (e.g. "compare" → ANALYZE vs
UNDERSTAND; a proof → ANALYZE vs EVALUATE) the second is recorded as
`secondary_cognitive_level`. The harness reports strict accuracy (primary only) and lenient
accuracy (primary or secondary) so that annotator ambiguity is not counted as model error.

## Topic

`expected_topic` is one of the course's syllabus topics in `courses_v1.json`. When a question
sits between two topics, `acceptable_topics` lists the alternatives; either counts as correct.

## LO alignment

- Question-level `learning_outcome` (plus `acceptable_learning_outcomes`) is the outcome the
  question was written to assess; `null` means no catalogue outcome fits.
- Pair-level labels in `lo_alignment_pairs_v1.json` follow: `STRONG` = directly assesses the
  outcome; `WEAK` = same subject area, different skill/sub-topic; `NOT_ALIGNED` = unrelated.
  `WEAK` is the least reliable label (most items `MEDIUM|LOW` confidence).

## Semantic similarity

Pairs in `similarity_pairs_v1.json` are labelled by whether a student answer to one question
would answer the other (`POTENTIAL_DUPLICATE`), same concept/comparable task
(`HIGHLY_SIMILAR`), same topic area (`SOMEWHAT_SIMILAR`) or different topic (`NOT_SIMILAR`).
`expert_score` (0–1) is an ordinal closeness judgement used only for rank correlation.
Hard negatives share a verb, a format or a word on purpose.

## Retrieval

Graded relevance: `2` = paraphrase of the bank question, `1` = same concept, `0` = other.
Only positives are listed; unlisted bank items are assumed non-relevant.

## RAG

`relevant_chunks` are the chunks that contain the answer (grade 2) or supporting context
(grade 1). `answer_keywords` are minimal surface forms a correct grounded answer must contain
(used for the SUPPORTED / PARTIALLY_SUPPORTED / UNSUPPORTED classification together with a
citation-evidence check). `answer_present: false` items require a refusal; `injection: true`
items must not be complied with.

## Grading

Marks were assigned criterion-by-criterion against the rubric; each answer carries a
`rationale` so the marking can be audited. Faculty marks are the reference, never the AI's.

## Assessment quality / recommendations

Per-dimension `OK|FLAG` judgements and expected recommendation categories were written from the
composed question list and its faculty metadata. `rating_band` gives the set of overall ratings
the reviewer would accept; exact numeric scores are not labelled because they are not
meaningful to a human reviewer.

## Generation (rubrics, questions)

These datasets contain **requests and constraints**, not gold outputs. Automated checks verify
constraint satisfaction; *quality* requires the human review form in
`labels/HUMAN_REVIEW_FORM.md`. No human quality ratings exist for v1.0.

## Known limitations of v1.0 labels

- Single annotator → no inter-annotator agreement; disagreement is approximated by
  `label_confidence` and secondary labels only.
- Synthetic data written by the same person who wrote the protocol → topic and phrasing
  diversity is bounded by one author's style; the sample is not a random sample of real exams.
- 144 questions / 60 pairs / 24 queries / 24 graded answers are LIMITED–MODERATE sizes; all
  headline metrics carry confidence intervals for this reason.
- English only.
