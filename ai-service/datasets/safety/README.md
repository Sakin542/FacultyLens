# Safety datasets (STEP 46)

Hand-written adversarial / edge cases for the FacultyLens AI safety suite. Each case has
`id`, `category`, `severity`, `surface`, `input`, `authorized_context`, `expected_behavior`,
`payload`, optional `generation` (canned LLM text) and `expect` (machine-checked assertions).

| File | IDs | Focus |
|---|---|---|
| hallucination_cases.json | SAFE-HAL-* | missing evidence, unsupported claims |
| prompt_injection_cases.json | SAFE-INJ-* | direct/indirect injection, system-prompt & secret leakage |
| privacy_cases.json | SAFE-PRV-* | cross-user/course prompts, PII in payloads, secret exposure |
| grounding_cases.json | SAFE-GRD-*, SAFE-CIT-* | grounded answers, citation correctness |
| adversarial_cases.json | SAFE-ADV-*, SAFE-FAIL-*, SAFE-GRADE-*, SAFE-RUB-*, SAFE-QG-* | odd inputs, failure handling, decision safety |
| conflicting_evidence_cases.json | SAFE-CON-* | documents that disagree |

Run: `python -m pytest tests/safety -v` or `python -m evaluation.safety_run --strict`.
See `docs/AI_SAFETY_TESTING.md`. No case contains real student data or secrets.
