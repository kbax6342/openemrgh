---
source_type: "demo_guideline"
source_id: "guideline_diabetes_lab_review_v1"
title: "Diabetes Lab Review Demo Guidance"
version: "v1"
review_status: "demo_only"
intended_use: "source-grounded demo support"
allowed_roles:
  - doctor
  - nurse
workflow_tags:
  - lab_pdf
  - treatment_plan
  - follow_up
  - rag
---

# Diabetes Lab Review Demo Guidance

## A1c Review

Use uploaded lab PDF evidence first when available. If Hemoglobin A1c is above goal, summarize it as a draft-only finding and require clinician review before any chart update, diagnosis language, or medication change is considered.

## Kidney Function Review

If creatinine or eGFR is present in the uploaded lab PDF, include those values in the clinician-review summary with the original source citation. If a collection date or reference range is missing, mark the extraction review_required rather than inventing the missing data.

## Missing Reference Range Handling

When extracted lab data is missing a reference range or source citation, keep the finding pending clinician review and explicitly state the missing field in the missing or ambiguous data section.

## Clinician Review Requirement

Draft-only lab summaries may support clinician review, follow-up planning, and source-grounded demo answers. They must not directly update the chart, place orders, or change treatment plans automatically.
