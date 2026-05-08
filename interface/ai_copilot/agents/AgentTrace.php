<?php

class AgentTrace
{
    private string $requestId;
    private array $steps = [];

    public function __construct(string $requestId)
    {
        $this->requestId = trim($requestId) !== '' ? trim($requestId) : 'request';
    }

    public function add(string $agent, string $status, string $detail, array $metadata = []): void
    {
        $safeMetadata = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['request_id', 'source_document_id', 'doc_type', 'status', 'review_status', 'citation_count', 'retrieval_hit_count', 'schema_valid', 'role', 'patient_id', 'user_id', 'latency_ms', 'error_code', 'claim_count', 'cited_claim_count', 'uncited_claim_count', 'invalid_citation_count', 'blocked_claim_count', 'source_count', 'citation_contract_status'], true)) {
                $safeMetadata[$key] = $value;
            }
        }

        $this->steps[] = [
            'agent' => $agent,
            'status' => $status,
            'detail' => $detail,
            'metadata' => $safeMetadata,
            'recorded_at' => gmdate('c'),
        ];
    }

    public function buildSafeTrace(array $context = []): array
    {
        return [
            'request_id' => $this->requestId,
            'patient_id' => isset($context['patient_id']) && is_numeric($context['patient_id']) ? (int) $context['patient_id'] : null,
            'user_id' => isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : null,
            'role' => trim((string) ($context['role'] ?? '')),
            'workflow_name' => trim((string) ($context['workflow_name'] ?? 'document_ingestion_mvp')),
            'steps' => $this->steps,
        ];
    }

    public function steps(): array
    {
        return $this->steps;
    }
}
