export const DOCUMENT_INGESTION_GRAPH_NODES = [
  'preflight_auth',
  'validate_upload',
  'store_source_document',
  'extract_document',
  'validate_schema',
  'persist_pending_facts',
  'index_for_rag',
  'retrieve_context',
  'evidence_safety_check',
  'clinician_review_gate',
  'final_response'
] as const;
