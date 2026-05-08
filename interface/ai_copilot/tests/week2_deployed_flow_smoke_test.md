# Week 2 Deployed Flow Smoke Test

Use this manual smoke checklist against the deployed OpenEMR co-pilot.

- Deployed URL: `https://ineloquent-unsaliently-alida.ngrok-free.dev`
- Demo account used during verification: `admin-openemr`
- Demo patient: `Marcus Johnson`
- Primary role: `Doctor`

## Lab PDF Flow

1. Open the deployed app URL.
2. Log in with the demo account.
3. Open the AI co-pilot drawer / page.
4. Select patient `Marcus Johnson`.
5. Select role `Doctor`.
6. Confirm the upload button is visible near the prompt.
7. Confirm the document type selector offers `Lab PDF` and `Intake Form`.
8. Choose `Lab PDF`.
9. Upload the synthetic lab PDF `marcus_johnson_synthetic_lab_results.pdf`.
10. Confirm the response shows `Lab PDF Ingestion — Clinician Review Required`.
11. Confirm the `Extraction Results` panel appears.
12. Confirm extracted lab facts appear with values and abnormal flags.
13. Confirm the `Schema Validation` panel appears.
14. Confirm the `Citation Contract` panel appears.
15. Confirm the clinician-review banner appears and says the facts are draft-only.
16. Confirm `Sources Used` appears.
17. Click a citation chip.
18. Confirm the source preview opens.
19. Confirm the preview shows source metadata, quote/value, and PDF preview or safe fallback copy.

## Evidence Retrieval Flow

20. Ask a grounded follow-up question about the uploaded lab facts.
21. Confirm the answer remains draft-only.
22. Confirm `Evidence Snippets` appears.
23. Confirm retrieval mode and evidence count are visible.
24. Confirm grounded citations remain clickable.

## Intake Form Flow

25. Choose `Intake Form`.
26. Upload the synthetic intake form `marcus_johnson_intake_form.pdf`.
27. Confirm the response shows `Intake Form Ingestion — Clinician Review Required`.
28. Confirm intake extraction fields appear in `Extraction Results`.
29. Confirm missing / ambiguous intake fields are visible.
30. Confirm `Sources Used` or grounded RAG snippet citations appear when available.
31. Click an intake / RAG citation.
32. Confirm a source snippet preview opens or a safe fallback message appears.

## Observability / Safety Flow

33. Expand `Agent Workflow Trace`.
34. Confirm `Supervisor`, `IntakeExtractorWorker`, `EvidenceRetrieverWorker`, and `FinalResponse` are visible when applicable.
35. Expand `Observability`.
36. Confirm tool sequence, latency, retrieval, extraction, token/cost, and PHI-redaction status appear.
37. Confirm raw document text, patient identifiers, DOB, phone, email, address, insurance IDs, screenshots, and hidden/system prompts are not shown.

## Safe Refusal Flow

38. Switch role to `Billing Staff`.
39. Ask for a treatment plan or medication change.
40. Confirm the request is refused safely and redirected to an allowed billing-safe workflow.

## Final Checks

41. Confirm `Like`, `Dislike`, and `Copy` actions still work.
42. Confirm quick actions still render.
43. Confirm ambient encounter capture controls still render.
44. Confirm browser console has no critical co-pilot JS errors.
