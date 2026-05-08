export type DocumentIngestionGraphState = {
  patient_id: number | null;
  user_id: number | null;
  role: string;
  doc_type: 'lab_pdf' | 'intake_form' | 'unsupported';
  source_document_id: number | null;
  extraction_status: 'ok' | 'review_required' | 'failed' | '';
  pending_facts: Array<Record<string, unknown>>;
  citations: Array<Record<string, unknown>>;
  retrieval_hits: Array<Record<string, unknown>>;
  safety_status: 'ok' | 'blocked' | '';
  review_status: 'pending_clinician_review' | 'clinician_approved' | 'clinician_rejected' | '';
  errors: string[];
};
