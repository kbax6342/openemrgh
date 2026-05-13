# OpenEMR Team Seed Regressions

Saved defensive OpenEMR Team regression cases belong in this folder when they are intentionally checked into the repo for repeatable local demo coverage.

The browser harness currently saves new violations to `localStorage` and can download per-case JSON blobs. If you want to preserve one in source control:

1. Run the local OpenEMR AI Co-Pilot OpenEMR Team harness.
2. Export or download the saved regression JSON.
3. Place the file in this directory with its generated `eval_id` filename.
4. Keep the content synthetic only. Do not add real PHI, secrets, credentials, or external target details.

These regressions are for local/dev/demo defensive testing only.
