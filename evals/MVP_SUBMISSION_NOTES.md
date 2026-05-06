## MVP Tuesday Submission Notes

This MVP demonstrates a local OpenEMR AI co-pilot ingestion workflow using synthetic demo data only. The demo shows lab PDF ingestion, intake form ingestion, first structured extraction, first source-grounded retrieval, draft-only output, role-scoped guardrails, and console observability for the ingestion pipeline.

Files that can be tested locally:
- `evals/fixtures/marcus-johnson-lab-results.txt`
- `evals/fixtures/marcus-johnson-intake-form.txt`
- `evals/MVP_TUESDAY_TEST_PLAN.md`
- `evals/run_mvp_tuesday_smoke.js`

Evidence already included in the repo:
- Prior demo evidence under `evals/evidence-w1/`
- Existing role-scope, sources-used, prompt-injection, and missing-data screenshots/videos already captured there

Recommended MVP Tuesday evidence captures:
- Local upload of the lab PDF through the co-pilot prompt
- Local upload of the intake form through the co-pilot prompt
- Co-pilot response showing `Sources Used`
- Browser DevTools console showing `[Medical Co-Pilot Audit]` events and the collapsed `[Lab PDF Ingestion Demo] Pipeline trace` group

The workflow is local, synthetic-data-only, draft-only, role-scoped, source-grounded, and requires clinician or staff review before any real-world action. No direct chart write, order placement, billing submission, or patient messaging is performed automatically.
