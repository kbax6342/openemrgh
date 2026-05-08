import type { DocumentIngestionGraphState } from './state';
import { DOCUMENT_INGESTION_GRAPH_NODES } from './tools';

export function buildDocumentIngestionGraphDefinition(enabled: boolean) {
  return {
    enabled,
    nodes: DOCUMENT_INGESTION_GRAPH_NODES.slice(),
    humanInTheLoopBeforeChartWrite: true,
    fallbackMode: enabled ? 'langgraph' : 'deterministic_php_fallback'
  } satisfies {
    enabled: boolean;
    nodes: readonly string[];
    humanInTheLoopBeforeChartWrite: boolean;
    fallbackMode: string;
  };
}

export function buildInitialDocumentIngestionState(): DocumentIngestionGraphState {
  return {
    patient_id: null,
    user_id: null,
    role: 'doctor',
    doc_type: 'unsupported',
    source_document_id: null,
    extraction_status: '',
    pending_facts: [],
    citations: [],
    retrieval_hits: [],
    safety_status: '',
    review_status: '',
    errors: []
  };
}
