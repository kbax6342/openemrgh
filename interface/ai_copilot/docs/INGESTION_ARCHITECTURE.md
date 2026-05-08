# OpenEMR AI Copilot Document Ingestion MVP

## Scope
This MVP supports exactly two upload document types inside the existing co-pilot prompt workflow:

- `lab_pdf`
- `intake_form`

All other document types are blocked with the user-safe message:

`Only lab PDFs and intake forms are supported in this MVP.`

## Safety model
- Original uploads are stored first as source documents in existing OpenEMR document storage.
- Extracted facts remain draft-only and pending clinician review.
- No direct chart writes, orders, diagnoses, claims, prescriptions, patient messages, or final clinical actions are triggered automatically.
- Every extracted fact carries machine-readable source metadata linked back to the uploaded document.

## Source persistence
The MVP stores and links:

- OpenEMR original document record
- local FHIR-style metadata identifiers for `DocumentReference` / `Binary`
- pending extracted facts
- clinician review decisions
- patient-scoped RAG chunk metadata
- safe, redacted agent workflow traces

Runtime table creation is handled by:

- `interface/ai_copilot/api/document_ingestion_store.php`

Tables created on demand:

- `ai_copilot_documents`
- `ai_copilot_extracted_facts`
- `ai_copilot_fact_reviews`
- `ai_copilot_rag_chunks`
- `ai_copilot_agent_traces`

## Agent workflow
Server-side PHP supervisor-worker flow:

1. `SupervisorAgent`
2. `DocumentIntakeWorker`
3. `LabExtractionWorker` or `IntakeExtractionWorker`
4. `RAGIndexWorker`
5. `RAGRetrievalWorker`
6. `EvidenceSafetyWorker`
7. `ClinicianReviewWorker`

The visible UI trace remains enabled through the existing co-pilot trace card. The PHP ingestion workflow also returns a safe `agent_trace` payload so attachment-review responses stay observable.

## Strict schema validation
JSON schema files:

- `interface/ai_copilot/schemas/lab_pdf.schema.json`
- `interface/ai_copilot/schemas/intake_form.schema.json`

Current validation approach:

- PHP worker validation enforces required fields and allowed enums
- invalid extractions downgrade to `review_required` or `failed`
- unsupported/non-medical documents fail closed

## Clinician review gate
UI review controls allow:

- approve selected facts
- reject selected facts
- leave facts pending

MVP behavior:

`Approved for demo review — not written to chart automatically.`

## RAG behavior
- only patient-scoped uploaded document chunks are indexed
- rejected facts are not promoted as trusted chart facts
- retrieval requires grounded sources
- responses include `Sources Used`

## Feature flags
LangGraph / LangChain / LangSmith adapters are present but not required for the PHP fallback path.

Default flags:

- `AI_COPILOT_DOCUMENT_INGESTION_ENABLED=true`
- `AI_COPILOT_EMBEDDINGS_ENABLED=true`
- `AI_COPILOT_VECTOR_STORE=local`
- `AI_COPILOT_LANGGRAPH_ENABLED=false`
- `LANGSMITH_TRACING=false`
- `LANGSMITH_PROJECT=openemr-ai-copilot`

LangSmith requirement:

- safe metadata only
- no raw PHI, full document text, patient names, DOB, phone, email, addresses, or raw file content

## Local MVP test path
Open:

- `OpenEMR -> AI Copilot -> interface/ai_copilot/index.php`

Then:

1. Select a demo patient.
2. Select `Lab PDF` or `Intake Form`.
3. Attach a PDF through the prompt composer.
4. Send an ingestion prompt.
5. Verify:
   - clinician-review banner
   - extracted facts
   - missing / ambiguous data
   - sources used
   - approve / reject / pending controls
   - agent workflow trace
   - no chart writes

## Known MVP limitations
- final chart write approval flow is intentionally blocked from automatic completion
- FHIR-style source ids are linked metadata placeholders unless broader write support is approved
- LangGraph nodes are feature-flagged adapters, not the default production runtime
- bounding-box citations are TODO; MVP uses page / section + snippet/value linking
