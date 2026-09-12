# FacultyLens — Human Expert Review Form (AI outputs)

Use one form per reviewed item. Reviewers must be qualified faculty in the course's subject.
Do **not** use an AI system to fill in this form. Save completed forms as JSON in
`evaluation/labels/human_reviews/<component>/<reviewer>_<item_id>.json`
(schema below) — the harness aggregates them automatically and reports
`NOT_EVALUATED` for any component with zero completed forms.

## Scale (all dimensions)

| Score | Meaning |
|---|---|
| 1 | Poor — unusable, misleading or wrong |
| 2 | Weak — major edits needed |
| 3 | Acceptable — usable with minor edits |
| 4 | Good — usable as-is with cosmetic edits |
| 5 | Excellent — faculty would adopt directly |

## Dimensions by component

| Component | Dimensions |
|---|---|
| Recommendations | relevance, evidence_based, actionable, clarity, non_contradictory, academic_appropriateness |
| Rubrics | criterion_relevance, criterion_clarity, marks_consistency, coverage, specificity, academic_appropriateness, question_alignment |
| Generated questions | clarity, relevance, difficulty_appropriateness, academic_validity, non_ambiguity, originality |
| RAG answers | relevance, correctness, clarity, grounding, usefulness |
| AI grading suggestions | mark_reasonableness, evidence_quality, feedback_usefulness, fairness |

## Decision

`ACCEPTED` (would use), `REVISED` (would use after edits), `REJECTED` (would not use).
A `major_error` flag marks factual errors, hallucinated content, wrong marks arithmetic,
unsupported claims or academically inappropriate content.

## JSON schema

```json
{
  "component": "RUBRIC_GENERATION",
  "item_id": "Q041",
  "run_id": "2026-09-13T10-00-00Z-abcdef",
  "reviewer": "R1",
  "reviewer_qualification": "Faculty, Data Structures",
  "reviewed_at": "2026-09-20",
  "scores": {"criterion_relevance": 4, "criterion_clarity": 3, "marks_consistency": 5, "coverage": 4, "specificity": 3, "academic_appropriateness": 4, "question_alignment": 4},
  "decision": "REVISED",
  "major_error": false,
  "comment": "Criterion 3 is too generic."
}
```

The harness reports per component: number of reviews, mean and median per dimension,
acceptance rate (`ACCEPTED + REVISED`), major-error rate and — when two or more reviewers rate
the same items — inter-rater agreement (Cohen's kappa on decision, mean absolute score
difference). **Human quality ratings are kept separate from AI accuracy metrics and from
faculty acceptance signals collected in production.**
