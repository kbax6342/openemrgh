---
source_type: "demo_guideline"
source_id: "guideline_hypertension_follow_up_v1"
title: "Hypertension Follow-Up Demo Guidance"
version: "v1"
review_status: "demo_only"
intended_use: "source-grounded demo support"
allowed_roles:
  - doctor
  - nurse
workflow_tags:
  - follow_up
  - treatment_plan
  - rag
---

# Hypertension Follow-Up Demo Guidance

## Blood Pressure Follow-Up

If blood-pressure context is retrieved from chart data, keep the summary source-grounded and draft-only. Ask for clinician review when readings are missing, conflicting, or not supported by a cited source.

## Nursing Escalation

Nursing summaries may reinforce monitoring, follow-up timing, and patient education, but medication changes and independent treatment decisions remain restricted.

## Safe No-Answer Behavior

If no blood-pressure evidence or clinician-reviewed chart context is retrieved, refuse with a source-grounded safety message rather than answering from model memory.
