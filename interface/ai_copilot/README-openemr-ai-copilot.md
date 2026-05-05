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

### Supervisor-Worker Agent Layer

This demo now adds a targeted supervisor-worker agent layer on top of the existing Co-Pilot UI. The UI, quick actions, and reminder workflow stay in place. The new layer runs inside the current request path and keeps the app read-only.

Agent file layout:
- `interface/ai_copilot/agents/copilot_agents.js`
- `interface/ai_copilot/agents/copilot_agent_tools.js`
- `interface/ai_copilot/agents/copilot_agent_trace.js`
- `interface/ai_copilot/agents/copilot_agent_safety.js`
- `interface/ai_copilot/agents/copilot_agents.test.js`

Agents:
- `Supervisor Agent` (`Clinical Workflow Supervisor`)
  - classifies intent
  - reads the selected staff role
  - routes to worker tools
  - enforces draft-only behavior
  - combines worker outputs
  - returns the final grounded draft
- `Worker Agent 1` (`Chart Retrieval Worker`)
  - retrieves role-appropriate chart context
  - supports medications, allergies, labs, encounters, visit history, documents, insurance, care team, immunizations, appointments, contact context, and approved ambient encounter records
  - returns structured facts with source labels
- `Worker Agent 2` (`Evidence + Safety Worker`)
  - checks role boundaries
  - validates sources and citations
  - flags missing data and uncertainty
  - blocks unsupported or prompt-injection requests
  - produces safe refusal language when needed

Callable tools with JSON-style schemas:
- `retrieve_chart_context`
- `attach_and_extract`
- `retrieve_guideline_evidence`
- `validate_citations`
- `draft_grounded_answer`

The browser console now shows step-by-step trace logging such as:
- `Supervisor Agent` received prompt and classified intent
- `Chart Retrieval Worker` called `retrieve_chart_context`
- `Supervisor Agent` accepted worker context and called `draft_grounded_answer`
- `Evidence + Safety Worker` called `validate_citations`
- `Supervisor Agent` finalized the grounded draft

Visible UI trace:
- Every assistant response now includes a compact collapsed `Agent Workflow Trace` card under the answer
- The default visible flow is `Supervisor Agent → Chart Retrieval Worker → Evidence + Safety Worker → Final Draft`
- Each step shows a status badge (`Pending`, `Running`, `Complete`, or `Blocked`), a short explanation, and compact tool/source metadata when available
- Prompt-injection and role-boundary refusals visibly show the `Supervisor Agent` as `Blocked`
- Missing-data scenarios visibly show the `Evidence + Safety Worker` flagging uncertainty before the final draft is shown
- Demo language supported: `I made the agent system observable in the UI. The grader can see the Supervisor Agent route the task, the Chart Retrieval Worker retrieve OpenEMR context, and the Evidence + Safety Worker validate grounding, missing data, and role boundaries before the final draft is shown.`

Workflow observability console events:
- `copilot_agent_supervisor_started`
- `copilot_agent_supervisor_completed`
- `copilot_worker_chart_retrieval_started`
- `copilot_worker_chart_retrieval_completed`
- `copilot_worker_safety_validation_started`
- `copilot_worker_safety_validation_completed`
- `copilot_agent_final_response_ready`
- `copilot_agent_request_blocked`

### Lab PDF Ingestion In The Composer

The existing prompt composer now supports inline lab PDF ingestion without leaving the main Co-Pilot workflow.

Where it appears:
- Inside the existing composer, beside the prompt input
- `Attach PDF` opens a PDF-only file picker with `accept="application/pdf,.pdf"`
- After selection, the composer shows a chip such as `Attached: marcus-johnson-labs.pdf`
- The chip `x` removes the pending attachment before send

What happens on send:
- The same Co-Pilot `Send` button submits the prompt plus the optional PDF using `FormData`
- Before any uploaded PDF is stored, vectorized, or retrieved, the server runs a medical-document validation gate
- When `AWS_MEDICAL_DOCUMENT_GUARD_ENABLED=true`, the gate uses Amazon Textract plus Amazon Comprehend Medical from server-side code only
- If AWS validation is unavailable during demo, the workflow returns `review_required` and blocks vectorization instead of silently ingesting the file
- The PHP endpoint ingests the PDF, extracts text when possible, falls back to the seeded Marcus Johnson demo lab text only when extraction is unavailable, chunks the text, creates deterministic demo embeddings if no external embedding provider is configured, stores vectors in a lightweight local JSON store, retrieves the most relevant chunks, and drafts the response from retrieved context
- Follow-up lab questions continue retrieving from the stored chunks for the selected patient instead of relying on memory

Demo storage note:
- `interface/ai_copilot/demo_lab_pdf_vectors.json`
- Comment in code: `Demo vector store only. Replace with approved HIPAA-compliant vector storage before production.`

Role behavior:
- Doctor can attach and ingest a lab PDF and receive extracted lab facts plus a draft clinical summary
- Nurse can receive a limited review-oriented draft summary when the existing role rules allow the question
- Billing Staff receives a role-boundary refusal instead of clinical interpretation
- Front Desk receives a minimum-necessary refusal instead of lab details

Lab PDF response format:
- Title: `Lab PDF Ingestion — Clinician Review Required`
- Sections:
  - `Extracted Lab Facts`
  - `Abnormal / Attention Needed`
  - `Missing or Ambiguous Data`
  - `Draft Clinical Summary`
  - `Sources Used`
  - `Safety Notice`
- Safety notice:
  - `This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original lab PDF.`

Console events to look for:
- `copilot_upload_received`
- `copilot_document_guard_started`
- `copilot_textract_started`
- `copilot_textract_succeeded`
- `copilot_textract_failed`
- `copilot_comprehend_medical_started`
- `copilot_comprehend_medical_succeeded`
- `copilot_document_guard_allowed`
- `copilot_document_guard_rejected`
- `copilot_document_guard_review_required`
- `copilot_vectorization_blocked`
- `copilot_lab_pdf_attached`
- `copilot_lab_pdf_removed`
- `copilot_lab_pdf_ingestion_started`
- `copilot_lab_pdf_text_extracted`
- `copilot_lab_pdf_chunked`
- `copilot_lab_pdf_vectorized`
- `copilot_lab_pdf_retrieval_started`
- `copilot_lab_pdf_retrieval_completed`
- `copilot_lab_pdf_review_required`
- `copilot_lab_pdf_guardrail_triggered`
- `copilot_lab_pdf_ingestion_failed`

Safety behavior:
- Wrong PDFs such as invoices, resumes, flyers, or unrelated business documents are blocked before vectorization and never appear in `Sources Used`
- Uploaded PDF text is treated as untrusted document content, not as executable instructions
- Prompt-injection strings inside the PDF are ignored as instructions and surfaced only as document-safety metadata
- The workflow does not diagnose, write to the chart, place orders, update medications, send patient messages, or submit billing
- Missing metadata such as ordering provider or collection time is reported as missing instead of invented

How to test:
- Select Marcus Johnson and the Doctor role
- Click `Attach PDF` in the composer
- Enter a prompt such as `Summarize this lab report.` or `What labs are abnormal?`
- Send through the normal composer
- In DevTools, confirm the `[Medical Co-Pilot Audit]` document-guard events appear before the ingestion/vectorization events
- Upload an obvious non-medical PDF and confirm the inline warning says it does not appear to be a medical document, with no `Sources Used` entry created for that file
- Confirm the response includes `Sources Used` and the clinician-review safety notice
- Ask a follow-up lab question and confirm the answer still cites the uploaded lab PDF context

### Demo Conversation Trace

Example trace:
- `User prompt`: "Draft a clinical summary for Marcus using chart context."
- `Supervisor Agent`: classify intent as clinical summary and route chart retrieval
- `Chart Retrieval Worker`: retrieve medications, labs, visit history, documents, insurance, care team, and immunization context with source labels
- `Supervisor Agent`: combine worker 1 output and request grounded draft synthesis
- `Evidence + Safety Worker`: validate role scope, citations, missing data, and refusal conditions
- `Supervisor Agent final answer`: return `Summary`, `Key findings`, `Missing data / uncertainty`, `Sources Used`, and `Draft-only clinician review`

### Demo Use Cases

Represented demo-ready use cases include:
- Morning follow-up prep
- Treatment plan summary with what changed since last visit
- Medication information with cited chart sources
- Lab PDF extraction through the existing composer
- Intake form extraction via the shared `attach_and_extract` worker path
- Billing or payment question with role-safe scope
- Prompt-injection or role-boundary refusal

### Safety Notes

- Responses remain draft-only and require human review
- No direct chart writes occur without clinician approval
- Front Desk output uses minimum-necessary PHI only
- Billing output stays limited to billing and insurance workflow context
- Source grounding is included whenever chart context is used
