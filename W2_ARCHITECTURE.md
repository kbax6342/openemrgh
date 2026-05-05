# Week 2 AgentForge Clinical Co-Pilot Architecture

## Week 1 Baseline

- Role-scoped OpenEMR copilot
- Guardrails
- Draft-only responses
- Audit/console logging
- Marcus Johnson synthetic patient demo

## Week 2 Additions

- Lab PDF ingestion
- Intake form ingestion
- Structured extraction with schemas
- Source citations for every extracted fact
- Guideline RAG retrieval
- Supervisor + intake-extractor worker + evidence-retriever worker
- 50 eval cases
- CI gate that blocks regressions
- Observability: tool sequence, latency, token/cost estimates, retrieval hits, extraction confidence

## 7 Use Cases

1. Doctor asks what changed since Marcus Johnson’s last visit.
2. Doctor uploads/reviews a lab PDF and extracts abnormal results.
3. Front desk uploads an intake form and extracts structured patient updates.
4. Doctor asks for guideline-grounded evidence about abnormal labs.
5. Nurse asks for medication/allergy summary with citations.
6. Billing/front desk asks a question outside their role and gets a safe refusal.
7. Doctor asks a follow-up question using prior extracted document facts and chart context.

## 5 LLM-Callable Tools

1. attach_and_extract
2. retrieve_chart_context
3. retrieve_guideline_evidence
4. validate_citations
5. draft_grounded_answer

## Eval Strategy

- 50 synthetic/demo cases
- Boolean rubrics only
- Required categories:
    - schema_valid
    - citation_present
    - factually_consistent
    - safe_refusal
    - no_phi_in_logs
