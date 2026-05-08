---
source_type: "demo_guideline"
source_id: "guideline_lipid_panel_review_v1"
title: "Lipid Panel Review Demo Guidance"
version: "v1"
review_status: "demo_only"
intended_use: "source-grounded demo support"
allowed_roles:
  - doctor
  - nurse
workflow_tags:
  - lab_pdf
  - follow_up
  - rag
---

# Lipid Panel Review Demo Guidance

## LDL And Cholesterol Review

When LDL, total cholesterol, HDL, or triglycerides are retrieved from an uploaded lab PDF, cite the uploaded document chunk and describe the values as draft-only findings for clinician review.

## Abnormal Flag Handling

If a lipid result is flagged high or low, preserve that flag in the clinician-review summary. Do not convert draft extraction findings into medication changes or autonomous treatment advice.

## Missing Data Handling

If the uploaded document does not include a clear reference range, result date, or source citation, keep the result pending clinician review and call out the missing data explicitly.
