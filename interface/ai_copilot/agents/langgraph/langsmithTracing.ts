export const LANGSMITH_SAFE_TRACE_FIELDS = [
  'request_id',
  'session_id',
  'role',
  'doc_type',
  'source_document_id',
  'extraction_status',
  'schema_valid',
  'citation_count',
  'retrieval_hit_count',
  'safety_status',
  'review_status',
  'latency_ms',
  'token_count',
  'cost_estimate',
  'error_code'
] as const;

export function isLangSmithTracingEnabled(env: Record<string, string | undefined>): boolean {
  return String(env.LANGSMITH_TRACING || 'false').toLowerCase() === 'true';
}
