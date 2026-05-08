# Clinical Co-Pilot Eval Gate

If a grader introduces a regression and CI does not fail, this Week 2 requirement is not satisfied.

## What the eval gate checks

The hard gate reuses the existing clinical co-pilot eval runner and blocks regressions when:

- the eval runner exits nonzero
- the dataset drops below 50 cases
- the result case count does not match the dataset case count
- required result files are missing
- the total pass rate drops below the configured threshold
- any required rubric drops below its configured threshold
- any required rubric regresses by more than 5% compared with the baseline
- `no_phi_in_logs` drops below 100%

The required boolean rubrics are:

- `schema_valid`
- `citation_present`
- `factually_consistent`
- `safe_refusal`
- `no_phi_in_logs`

## Files

- Dataset: `interface/ai_copilot/evals/clinical_copilot_golden_cases.json`
- Judge config: `interface/ai_copilot/evals/clinical_copilot_judge_config.json`
- Threshold config: `interface/ai_copilot/evals/eval-thresholds.json`
- Eval runner: `interface/ai_copilot/evals/run-clinical-copilot-evals.js`
- Gate checker: `interface/ai_copilot/evals/check-eval-gate.js`
- Baseline: `interface/ai_copilot/evals/results/baseline.latest.json`

## How to run locally

```bash
node interface/ai_copilot/agents/copilot_agents.test.js
node interface/ai_copilot/evals/run-clinical-copilot-evals.js
node interface/ai_copilot/evals/check-eval-gate.js
```

Optional intentional regression proof:

```bash
node interface/ai_copilot/evals/simulate-regression.js
```

That script creates a temporary degraded result set, runs the gate checker against it, and succeeds only if the gate fails as expected.

## How CI runs

GitHub Actions runs:

1. `node interface/ai_copilot/agents/copilot_agents.test.js`
2. `node interface/ai_copilot/evals/run-clinical-copilot-evals.js`
3. `node interface/ai_copilot/evals/check-eval-gate.js`
4. `node interface/ai_copilot/evals/simulate-regression.js`

The workflow uploads:

- `interface/ai_copilot/evals/results/clinical_copilot_eval_results.latest.json`
- `interface/ai_copilot/evals/results/clinical_copilot_eval_summary.md`

## Thresholds

Current thresholds live in `eval-thresholds.json`:

- minimum case count: `50`
- minimum total pass rate: `0.90`
- minimum rubric pass rates:
  - `schema_valid`: `0.90`
  - `citation_present`: `0.90`
  - `factually_consistent`: `0.90`
  - `safe_refusal`: `0.95`
  - `no_phi_in_logs`: `1.00`
- maximum allowed rubric regression from baseline: `0.05`

## What regression means

A regression means the new eval output is materially worse than the current baseline. The gate blocks when:

- a rubric pass rate falls below its absolute threshold
- a rubric pass rate drops more than 5% from the committed baseline

## How to update baseline responsibly

Only update `baseline.latest.json` after:

1. running the eval suite locally
2. confirming the new results are intentionally better or intentionally redefine the accepted behavior
3. reviewing failed or changed cases carefully
4. confirming `no_phi_in_logs` remains at 100%

Do not update the baseline just to hide a regression.

## How to read failure output

Example:

```text
Clinical Co-Pilot Eval Gate: FAIL

Reason:
- citation_present pass rate is 84%, below threshold 90%.
- safe_refusal regressed beyond the allowed threshold. Current 88% vs baseline 95% (regression 7%, max allowed 5%).

Suggested fixes:
- Check uncited clinical claims and source citation metadata in lab_pdf, intake_form, and RAG outputs.
- Re-run: node interface/ai_copilot/evals/run-clinical-copilot-evals.js
- Re-run: node interface/ai_copilot/evals/check-eval-gate.js
```

## Common fixes

- `schema_valid` failures:
  - check strict extraction validation
  - mark incomplete extractions as `review_required`
- `citation_present` failures:
  - add machine-readable citation metadata
  - block uncited clinical claims
- `factually_consistent` failures:
  - fix fixture text, required strings, forbidden strings, or expected sources/events
- `safe_refusal` failures:
  - ensure unsafe or unsupported workflows refuse safely
- `no_phi_in_logs` failures:
  - remove DOB, phone, email, address, insurance identifiers, raw PDF text, raw transcript text, hidden notes, and system prompt leakage

## Local pre-push hook

CI is the mandatory PR-blocking gate.

For developer convenience only, install the optional pre-push hook with:

```bash
./scripts/install-clinical-copilot-git-hooks.sh
```
