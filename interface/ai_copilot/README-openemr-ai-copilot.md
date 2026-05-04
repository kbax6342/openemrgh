## OpenEMR Medical Co-Pilot UI Notes

### AI Visit Review / Ambient Encounter Capture

This demo adds a consent-based ambient encounter capture workflow for the Marcus Johnson demo patient (`DEMO-PCP-1001`).

Flow:
- User clicks the composer mic button to start ambient encounter capture
- Consent modal requires verbal consent confirmation before recording starts
- Listening state stays clearly marked as draft-only and not added to the chart
- Stopping listening generates a simulated visit draft
- `Review visit draft` opens a clinician review gate with grouped draft updates
- Only after `Approve Selected` does the demo render dashboard and visit-history updates

Safety:
- Demo-only static extraction, no microphone or transcription service
- Draft only
- Requires clinician review
- Not medical advice
- Not automatically written to the chart

Audit events:
- `copilot_ambient_capture_requested`
- `copilot_listening_consent_modal_opened`
- `copilot_listening_consent_confirmed`
- `copilot_listening_started`
- `copilot_listening_stopped`
- `copilot_visit_draft_generated`
- `copilot_visit_review_opened`
- `copilot_visit_draft_approved`
- `copilot_dashboard_updates_rendered`
- `copilot_visit_history_updated`
- `copilot_visit_draft_rejected`
- `copilot_visit_draft_edited`

Audit logging stays in the browser console and uses metadata only. No raw transcript text is logged.

### Demo Visit Persistence

Approved ambient encounter capture drafts for Marcus Johnson are also stored locally for demo navigation.

Behavior:
- `Approve Selected` keeps the approved summary visible in the Co-Pilot
- The same approval creates one demo visit-history record per ambient draft session
- Demo records are stored in browser `localStorage` under `openemr_ai_copilot_demo_visits_DEMO-PCP-1001`
- The patient summary dashboard reads the latest approved record and renders an `AI Reviewed Visit Updates` card
- Finder -> Marcus Johnson -> Visit History reads the saved records and renders both:
  - a normal Visit History table row for the completed AI-assisted visit
  - a blue-outlined `AI-Assisted Visit Review / Ambient Encounter Capture` detail card with approved notes
- Duplicate clicks on `Approve Selected` for the same draft do not create duplicate visit records

Additional audit events:
- `copilot_demo_visit_history_record_created`
- `copilot_demo_dashboard_updates_rendered`
- `copilot_demo_visit_history_rendered`

Safety:
- No raw transcript text is stored
- Records are demo/local only
- Approval is still required before anything is shown as a completed visit

### Doctor RAG Chart Context Review

The Doctor role also includes a Marcus-specific quick action:
- `RAG: Review Marcus's Chart Context`

Behavior:
- Available only when the selected patient is Marcus Johnson (`DEMO-PCP-1001`) and the staff role is Doctor
- Retrieves grounded demo chart context before drafting a response
- Includes the latest approved Ambient Encounter Capture visit from local browser storage when one exists
- Uses compact `Sources Used` output so the demo shows retrieval-first behavior instead of answering from memory

Retrieved source categories:
- Visit History / Ambient Encounter Capture
- Active Medications
- Lab Follow-up
- Recent Vitals
- Insurance Note
- Immunization Review
- Care Preferences
- Care Team
- Issues / Problem List

Additional audit events:
- `copilot_rag_quick_action_selected`
- `copilot_rag_retrieval_started`
- `copilot_rag_context_retrieved`
- `copilot_rag_visit_history_context_loaded`
- `copilot_rag_sources_rendered`

Audit logging stays metadata-only. No raw transcript text or full raw chart text is logged.

### Lightweight Eval Runner

The Clinical Co-Pilot also includes a lightweight deterministic eval package under:

- `interface/ai_copilot/evals/clinical_copilot_golden_cases.json`
- `interface/ai_copilot/evals/run-clinical-copilot-evals.js`
- repo-root `EVALS.md`

Purpose:
- demonstrate golden-set coverage for role safety, RAG grounding, prompt injection, missing data, ambient encounter capture, and observability
- keep the submission demo-ready without changing live OpenEMR behavior
- provide a local pass/fail harness based on synthetic fixture responses

Run locally from the repo root:

```bash
node interface/ai_copilot/evals/run-clinical-copilot-evals.js
```

Current scope:
- doctor, nurse, billing, and front desk scenarios
- Marcus Johnson medication info and treatment-plan drafts
- RAG chart-context review and latest Ambient Encounter Capture retrieval
- prompt-injection refusal
- missing-data safe fallback
- consent-gated ambient encounter capture and approval audit-chain checks
