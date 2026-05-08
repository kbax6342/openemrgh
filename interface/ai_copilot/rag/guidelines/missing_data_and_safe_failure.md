---
source_type: "demo_guideline"
source_id: "guideline_missing_data_and_safe_failure_v1"
title: "Missing Data And Safe Failure Demo Guidance"
version: "v1"
review_status: "demo_only"
intended_use: "source-grounded demo support"
allowed_roles:
  - doctor
  - nurse
  - billing
  - front_desk
workflow_tags:
  - lab_pdf
  - intake_form
  - rag
  - safety
---

# Missing Data And Safe Failure Demo Guidance

## No Source No Answer

If no relevant evidence is retrieved, respond that there is not enough source-grounded information to answer safely. Do not answer from memory for clinical questions.

## Missing Citation Handling

No citation means no trusted clinical claim. If a finding lacks a required source citation, block it or move it into missing or ambiguous data for clinician review.

## Low Confidence Handling

If extraction confidence or retrieval confidence is low, keep the result draft-only and review_required. Do not present low-confidence findings as finalized data.

## Ambiguous Extraction

If OCR, extraction, or retrieval is ambiguous, require clinician review and make the ambiguity visible in the response rather than silently dropping it.
