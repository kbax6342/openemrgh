# OpenEMR AI Co-Pilot Cost and Latency Report

Generated: 2026-05-08T19:30:29.544Z

## Actual Dev Spend

- Actual dev spend must be manually entered because no billing export is connected.
- Notes: Fill this from billing dashboard, credits used, or manual project tracking.

## Projected Production Cost

- Average tokens per request: 916.67
- Average cost per request: $0.000426
- 100 requests/month: $0.042600 projected monthly cost (3.33 requests/day average)
- 1000 requests/month: $0.426000 projected monthly cost (33.33 requests/day average)
- 10000 requests/month: $4.260000 projected monthly cost (333.33 requests/day average)

## Latency

- p50 latency: 177 ms
- p95 latency: 320 ms
- Average latency: 220.67 ms
- Encounter count in latest snapshot: 3

## Bottleneck Analysis

- IntakeExtractorWorker: avg 103.5 ms, p95 118 ms. Document extraction gets slower when PDF parsing and clinician-review staging are involved.
- FinalResponse: avg 46 ms, p95 126 ms. Draft generation or final assembly remains the slowest stage in current local observability data.
- EvidenceRetrieverWorker: avg 22.43 ms, p95 35 ms. Retrieval latency may increase further if external reranking is enabled in production.
- Supervisor: avg 1 ms, p95 3 ms. Review this step first when optimizing p95 latency.

## Eval Context

- Case count: 50
- Passed: 50
- Failed: 0
- Pass rate: 100.0%

## Notes

- Actual dev spend must be manually entered because no billing export is connected.
