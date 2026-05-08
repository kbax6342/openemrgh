---
source_type: "demo_guideline"
source_id: "guideline_intake_form_review_v1"
title: "Intake Form Review Demo Guidance"
version: "v1"
review_status: "demo_only"
intended_use: "source-grounded demo support"
allowed_roles:
  - doctor
  - nurse
workflow_tags:
  - intake_form
  - clinical_notes
  - rag
---

# Intake Form Review Demo Guidance

## Chief Concern Summary

Summarize the chief concern or reason for visit using only cited intake-form evidence. If the concern is missing or unclear, report that gap directly instead of inferring a reason.

## Demographics Verification

Demographic details from intake forms should be treated as draft-only and verified before any operational use. Missing contact or emergency-contact details should be called out for review.

## Family History Review

Family-history findings should remain source-grounded and pending clinician review. If relation or condition details are incomplete, keep them in the missing or ambiguous data list.

## Care Preferences

Care preferences and communication preferences from intake forms may support follow-up planning, but they still require human review before operational use.
