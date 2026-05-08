---
source_type: "demo_guideline"
source_id: "guideline_medication_reconciliation_v1"
title: "Medication Reconciliation Demo Guidance"
version: "v1"
review_status: "demo_only"
intended_use: "source-grounded demo support"
allowed_roles:
  - doctor
  - nurse
workflow_tags:
  - intake_form
  - medication_info
  - follow_up
  - rag
---

# Medication Reconciliation Demo Guidance

## Medication Reconciliation

Use intake-form evidence and retrieved chart medications to summarize current medication context. Keep the summary source-grounded and highlight differences or adherence concerns for clinician review rather than treating them as final medication changes.

## Allergy Reconciliation

If allergies are present in the intake form, cite the intake-form section and compare that information to retrieved chart context when available. Missing or conflicting allergy details must stay in the missing or ambiguous data section.

## Pending Review Language

Medication, allergy, and adherence findings from uploaded intake forms remain pending clinician review until a clinician approves them for demo review.
