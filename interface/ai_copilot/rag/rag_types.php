<?php

require_once(dirname(__DIR__) . '/lab_pdf_vector_store.php');
require_once(dirname(__DIR__) . '/citations/CitationContract.php');

function aiCopilotRagReadBoolEnv(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function aiCopilotRagReadIntEnv(string $name, int $default): int
{
    $value = getenv($name);
    return is_numeric($value) ? (int) $value : $default;
}

function aiCopilotRagReadFloatEnv(string $name, float $default): float
{
    $value = getenv($name);
    return is_numeric($value) ? (float) $value : $default;
}

function aiCopilotRagGuidelineEnabled(): bool
{
    return aiCopilotRagReadBoolEnv('AI_COPILOT_GUIDELINE_RAG_ENABLED', true)
        && aiCopilotRagReadBoolEnv('AI_COPILOT_RAG_ENABLED', true);
}

function aiCopilotRagEmbeddingsEnabled(): bool
{
    return aiCopilotRagReadBoolEnv('AI_COPILOT_EMBEDDINGS_ENABLED', true)
        && aiCopilotRagReadBoolEnv('AI_COPILOT_RAG_ENABLED', true);
}

function aiCopilotRagHybridEnabled(): bool
{
    return aiCopilotRagReadBoolEnv('AI_COPILOT_HYBRID_RAG_ENABLED', true)
        && aiCopilotRagReadBoolEnv('AI_COPILOT_RAG_ENABLED', true);
}

function aiCopilotRagDebugEnabled(): bool
{
    return aiCopilotRagReadBoolEnv('AI_COPILOT_RAG_DEBUG', false);
}

function aiCopilotRagSafeLog(string $event, array $payload = []): void
{
    if (!aiCopilotRagDebugEnabled()) {
        return;
    }

    $safe = [
        'event' => $event,
        'role' => trim((string) ($payload['role'] ?? '')),
        'mode' => trim((string) ($payload['mode'] ?? '')),
        'request_id' => trim((string) ($payload['request_id'] ?? '')),
        'retrieval_mode' => trim((string) ($payload['retrieval_mode'] ?? '')),
        'source_count' => isset($payload['source_count']) && is_numeric($payload['source_count']) ? (int) $payload['source_count'] : 0,
        'guideline_chunk_count' => isset($payload['guideline_chunk_count']) && is_numeric($payload['guideline_chunk_count']) ? (int) $payload['guideline_chunk_count'] : 0,
        'uploaded_chunk_count' => isset($payload['uploaded_chunk_count']) && is_numeric($payload['uploaded_chunk_count']) ? (int) $payload['uploaded_chunk_count'] : 0,
        'sparse_result_count' => isset($payload['sparse_result_count']) && is_numeric($payload['sparse_result_count']) ? (int) $payload['sparse_result_count'] : 0,
        'dense_result_count' => isset($payload['dense_result_count']) && is_numeric($payload['dense_result_count']) ? (int) $payload['dense_result_count'] : 0,
        'hybrid_candidate_count' => isset($payload['hybrid_candidate_count']) && is_numeric($payload['hybrid_candidate_count']) ? (int) $payload['hybrid_candidate_count'] : 0,
        'reranked_result_count' => isset($payload['reranked_result_count']) && is_numeric($payload['reranked_result_count']) ? (int) $payload['reranked_result_count'] : 0,
        'top_source_ids' => array_values(array_filter(array_map('strval', $payload['top_source_ids'] ?? []), static fn($item) => trim($item) !== '')),
        'citation_count' => isset($payload['citation_count']) && is_numeric($payload['citation_count']) ? (int) $payload['citation_count'] : 0,
        'claim_count' => isset($payload['claim_count']) && is_numeric($payload['claim_count']) ? (int) $payload['claim_count'] : 0,
        'no_answer_reason' => trim((string) ($payload['no_answer_reason'] ?? '')),
    ];

    error_log('[OpenEMR AI Copilot RAG] ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function aiCopilotRagNormalizeText(string $value): string
{
    $value = str_replace("\r", "\n", $value);
    $value = preg_replace("/[ \t]+/", ' ', $value);
    $value = preg_replace("/\n{3,}/", "\n\n", (string) $value);
    return trim((string) $value);
}

function aiCopilotRagPreviewText(string $value, int $limit = 220): string
{
    $value = aiCopilotRagNormalizeText($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }

    return substr($value, 0, $limit);
}

function aiCopilotRagRequestedWorkflowTags(string $prompt, string $mode): array
{
    $prompt = strtolower($prompt);
    $tags = [$mode];

    $map = [
        'lab_pdf' => '/\b(lab|labs|lab pdf|lab result|a1c|hba1c|ldl|cholesterol|creatinine|egfr|wbc|crp|esr|potassium)\b/i',
        'intake_form' => '/\b(intake|intake form|chief concern|reason for visit|medication reconciliation|allergy|family history)\b/i',
        'follow_up' => '/\b(follow up|follow-up|monitoring|recheck)\b/i',
        'treatment_plan' => '/\b(treatment plan|plan|next steps)\b/i',
        'medication_info' => '/\b(medication|medications|adherence|reconciliation)\b/i',
        'safety' => '/\b(missing data|citation|review required|safe failure|role boundary|nurse|doctor|billing|front desk)\b/i',
        'rag' => '/\b(retrieve|source|sources used|grounded|evidence)\b/i',
    ];

    foreach ($map as $tag => $pattern) {
        if (preg_match($pattern, $prompt) === 1) {
            $tags[] = $tag;
        }
    }

    return array_values(array_unique(array_filter(array_map('strval', $tags), static fn($item) => trim($item) !== '')));
}

function aiCopilotRagDetectRequestedDocumentTypes(string $prompt, string $mode = ''): array
{
    $prompt = strtolower($prompt . ' ' . $mode);
    $types = [];
    if (preg_match('/\b(lab|labs|lab pdf|lab result|lab results)\b/i', $prompt) === 1) {
        $types[] = 'lab_results';
    }
    if (preg_match('/\b(intake|intake form|questionnaire)\b/i', $prompt) === 1) {
        $types[] = 'intake_form';
    }

    return array_values(array_unique($types));
}

function aiCopilotRagRoleAllowsChunk(array $chunk, string $role): bool
{
    $allowedRoles = is_array($chunk['allowed_roles'] ?? null) ? $chunk['allowed_roles'] : [];
    if ($allowedRoles === []) {
        return true;
    }

    return in_array(strtolower($role), array_map(static fn($item) => strtolower((string) $item), $allowedRoles), true);
}

function aiCopilotRagWorkflowAllowsChunk(array $chunk, array $workflowTags): bool
{
    $chunkTags = is_array($chunk['workflow_tags'] ?? null) ? $chunk['workflow_tags'] : [];
    if ($chunkTags === [] || $workflowTags === []) {
        return true;
    }

    $normalizedChunkTags = array_map(static fn($item) => strtolower((string) $item), $chunkTags);
    foreach ($workflowTags as $tag) {
        if (in_array(strtolower((string) $tag), $normalizedChunkTags, true)) {
            return true;
        }
    }

    return false;
}

function aiCopilotRagNormalizeChunk(array $chunk): array
{
    $chunkId = trim((string) ($chunk['chunk_id'] ?? $chunk['field_or_chunk_id'] ?? ''));
    $sourceId = trim((string) ($chunk['source_id'] ?? ''));
    if ($sourceId === '') {
        $sourceId = $chunkId;
    }

    $sourceType = strtolower(trim((string) ($chunk['source_type'] ?? 'unknown')));
    $allowedSourceTypes = aiCopilotCitationAllowedSourceTypes();
    if ($sourceType === 'demo_guideline' && !in_array('demo_guideline', $allowedSourceTypes, true)) {
        $sourceType = 'rag_chunk';
    }
    if ($sourceType === '') {
        $sourceType = 'unknown';
    }

    $pageOrSection = trim((string) ($chunk['page_or_section'] ?? ''));
    if ($pageOrSection === '') {
        $pageOrSection = 'retrieved evidence';
    }

    $text = aiCopilotRagNormalizeText((string) ($chunk['text'] ?? $chunk['chunk_text'] ?? ''));
    $quote = aiCopilotRagNormalizeText((string) ($chunk['quote_or_value'] ?? ''));
    if ($quote === '') {
        $quote = aiCopilotRagPreviewText($text);
    }

    return [
        'chunk_id' => $chunkId,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'source_document_id' => isset($chunk['source_document_id']) && is_numeric($chunk['source_document_id']) ? (int) $chunk['source_document_id'] : null,
        'title' => trim((string) ($chunk['title'] ?? $chunk['source_label'] ?? 'Retrieved Evidence')),
        'document_type' => trim((string) ($chunk['document_type'] ?? '')),
        'page_or_section' => $pageOrSection,
        'field_or_chunk_id' => trim((string) ($chunk['field_or_chunk_id'] ?? $chunkId)),
        'text' => $text,
        'quote_or_value' => $quote,
        'confidence' => isset($chunk['confidence']) && is_numeric($chunk['confidence']) ? round((float) $chunk['confidence'], 4) : 0.8,
        'allowed_roles' => is_array($chunk['allowed_roles'] ?? null) ? array_values($chunk['allowed_roles']) : [],
        'workflow_tags' => is_array($chunk['workflow_tags'] ?? null) ? array_values($chunk['workflow_tags']) : [],
        'review_status' => trim((string) ($chunk['review_status'] ?? 'pending_clinician_review')) ?: 'pending_clinician_review',
        'resource_type' => trim((string) ($chunk['resource_type'] ?? '')),
        'patient_id' => isset($chunk['patient_id']) && is_numeric($chunk['patient_id']) ? (int) $chunk['patient_id'] : null,
        'patient_key' => trim((string) ($chunk['patient_key'] ?? '')),
        'source_page' => isset($chunk['source_page']) && is_numeric($chunk['source_page']) ? (int) $chunk['source_page'] : null,
        'source_label' => trim((string) ($chunk['source_label'] ?? '')),
        'embedding' => is_array($chunk['embedding'] ?? null) ? $chunk['embedding'] : [],
        'metadata' => is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : [],
        'sparse_score' => isset($chunk['sparse_score']) && is_numeric($chunk['sparse_score']) ? (float) $chunk['sparse_score'] : 0.0,
        'dense_score' => isset($chunk['dense_score']) && is_numeric($chunk['dense_score']) ? (float) $chunk['dense_score'] : 0.0,
        'hybrid_score' => isset($chunk['hybrid_score']) && is_numeric($chunk['hybrid_score']) ? (float) $chunk['hybrid_score'] : 0.0,
        'relevance_score' => isset($chunk['relevance_score']) && is_numeric($chunk['relevance_score']) ? (float) $chunk['relevance_score'] : 0.0,
        'bounding_box' => is_array($chunk['bounding_box'] ?? null) ? $chunk['bounding_box'] : null,
    ];
}

function aiCopilotRagChunkToCitation(array $chunk, float $confidence, array $overrides = []): array
{
    $normalized = aiCopilotRagNormalizeChunk($chunk);

    return aiCopilotBuildCitation([
        'source_type' => $normalized['source_type'],
        'source_id' => $normalized['source_id'],
        'source_document_id' => $normalized['source_document_id'],
        'page_or_section' => $normalized['page_or_section'],
        'field_or_chunk_id' => $normalized['field_or_chunk_id'],
        'quote_or_value' => trim((string) ($overrides['quote_or_value'] ?? $normalized['quote_or_value'])),
        'confidence' => round($confidence, 4),
        'document_type' => trim((string) ($overrides['document_type'] ?? $normalized['document_type'])),
        'resource_type' => trim((string) ($overrides['resource_type'] ?? $normalized['resource_type'])),
        'patient_id' => $normalized['patient_id'],
        'review_status' => trim((string) ($overrides['review_status'] ?? $normalized['review_status'])),
        'bounding_box' => $overrides['bounding_box'] ?? $normalized['bounding_box'],
    ]);
}

function aiCopilotRagBuildEvidenceSnippet(array $chunk, float $relevanceScore, string $retrievalMode, string $rerankProvider): array
{
    $normalized = aiCopilotRagNormalizeChunk($chunk);
    $citation = aiCopilotRagChunkToCitation($normalized, $relevanceScore);

    return [
        'text' => $normalized['text'],
        'relevance_score' => round($relevanceScore, 4),
        'retrieval_mode' => $retrievalMode,
        'rerank_provider' => $rerankProvider,
        'citation' => $citation,
        'source_title' => $normalized['title'],
        'review_status' => $normalized['review_status'],
    ];
}

function aiCopilotRagBuildSourceEntryFromCitation(array $citation, array $overrides = []): array
{
    return aiCopilotBuildSourceUsedEntry($citation, $overrides);
}
