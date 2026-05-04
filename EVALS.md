# Clinical Co-Pilot Evals

## Purpose

This project includes a lightweight eval package for the OpenEMR Clinical Co-Pilot under `interface/ai_copilot/`. The goal is to make the final submission easy to review without changing runtime behavior or requiring a live model call.

The eval package is intentionally:
- deterministic
- lightweight
- demo-ready
- synthetic-data-only
- focused on safety and retrieval grounding

## Eval Strategy

The current strategy uses a small golden set of labeled scenarios plus a deterministic runner.

Artifacts:
- `interface/ai_copilot/evals/clinical_copilot_golden_cases.json`
- `interface/ai_copilot/evals/run-clinical-copilot-evals.js`

Each case includes:
- user role
- patient context
- prompt/input
- category labels
- expected behavior
- must-contain assertions
- must-not-contain assertions
- expected source categories
- expected audit/observability events
- a mocked fixture response used by the deterministic runner

This is not intended to replace live end-to-end evaluation. It provides a stable submission-time harness for checking whether the documented Co-Pilot behavior matches the intended rubric.

## Labeled Scenario Coverage

The golden set covers:
- `clinical_rag`
- `billing_scope`
- `front_desk_phi_minimization`
- `nurse_scope`
- `doctor_scope`
- `ambient_capture`
- `prompt_injection`
- `missing_data`
- `role_denial`
- `observability`

## Rubric Scoring

Each case is scored pass/fail across a compact rule set:
- Behavior: expected allow/block/draft-only/clarify/safe-fallback posture is reflected in the fixture output.
- Content constraints: every string in `mustContain` is present.
- Forbidden output: no string in `mustNotContain` is present.
- Source grounding: every expected source category is present in the fixture’s `sourceCategories`.
- Observability: every expected event is present in the fixture’s `auditEvents`.

The runner prints:
- per-case pass/fail
- failure reasons
- category coverage counts
- overall summary totals

It exits non-zero if any case fails.

## Current Results

Current status:
- Deterministic golden-set runner available
- Fixture-based validation only
- No live-model dependency required
- Current fixture run target: 15/15 passing cases

When the current fixture set passes, that indicates:
- the documented behaviors are internally consistent
- the final submission has a concrete eval artifact
- safety/role/RAG expectations are explicit and inspectable

It does **not** prove:
- full production robustness
- live model compliance under all prompt variations
- browser-to-backend end-to-end execution fidelity

## Known Limitations

- Uses mocked fixture responses rather than live runtime calls.
- Does not replay browser interactions directly.
- Does not validate CSS or UI rendering.
- Does not validate persistence timing in a browser session.
- Does not benchmark latency or model quality.

## Next Steps

Recommended future extensions:
1. Add a browser-driven UI replay for key flows such as role switching, RAG review, and ambient encounter capture approval.
2. Add live response capture from the current Co-Pilot endpoint in a dedicated demo mode.
3. Compare actual responses against the same golden constraints used here.
4. Add source-order validation for the RAG workflow when approved ambient visit records exist.
5. Add a lightweight CI hook to run the deterministic golden set automatically.

## How To Run

From the repo root:

```bash
node interface/ai_copilot/evals/run-clinical-copilot-evals.js
```

The runner reads the golden cases JSON file, evaluates each fixture response, prints a pass/fail summary, and exits with code `1` if any case fails.
