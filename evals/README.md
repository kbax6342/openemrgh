# Clinical Co-Pilot Evals

This folder contains evaluation cases for the OpenEMR Clinical Co-Pilot demo under `interface/ai_copilot/`.

These eval artifacts are intentionally written around:
- clinical safety
- role-based access and least-privilege behavior
- RAG/source grounding
- prompt-injection handling
- missing data handling
- ambiguous patient requests
- ambient encounter capture consent, review, and approval flow

Contents:
- `clinical_copilot_eval_dataset.jsonl` — structured eval cases for manual or future automated runs
- `clinical_copilot_eval_results.md` — scenario-by-scenario tracking sheet
- `guardrail_eval_results.md` — guardrail-focused validation matrix

The evals are documentation-first and demo-safe. They do not modify runtime behavior or write to the clinical record.

