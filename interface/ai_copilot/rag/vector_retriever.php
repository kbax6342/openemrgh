<?php

require_once(__DIR__ . '/keyword_retriever.php');

function aiCopilotRagDenseRetrieve(array $chunks, string $query, array $options = []): array
{
    if (!aiCopilotRagEmbeddingsEnabled()) {
        aiCopilotRagSafeLog('dense_retrieval_disabled', [
            'request_id' => $options['request_id'] ?? '',
            'role' => $options['role'] ?? '',
            'mode' => $options['mode'] ?? '',
            'no_answer_reason' => 'Dense retrieval disabled because embeddings are not configured.',
        ]);
        return [];
    }

    $role = strtolower(trim((string) ($options['role'] ?? 'doctor')));
    $workflowTags = is_array($options['workflow_tags'] ?? null) ? $options['workflow_tags'] : [];
    $topK = isset($options['top_k']) && is_numeric($options['top_k'])
        ? max(1, (int) $options['top_k'])
        : max(1, aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_DENSE', 8));
    $queryEmbedding = aiCopilotLabPdfBuildDeterministicEmbedding($query);
    $ranked = [];

    foreach ($chunks as $chunk) {
        $chunk = aiCopilotRagNormalizeChunk(is_array($chunk) ? $chunk : []);
        if ($chunk['chunk_id'] === '') {
            continue;
        }
        if (!aiCopilotRagRoleAllowsChunk($chunk, $role) || !aiCopilotRagWorkflowAllowsChunk($chunk, $workflowTags)) {
            continue;
        }

        $embedding = is_array($chunk['embedding'] ?? null) && $chunk['embedding'] !== []
            ? array_map(static fn($value) => (float) $value, $chunk['embedding'])
            : aiCopilotLabPdfBuildDeterministicEmbedding($chunk['text']);
        $score = aiCopilotLabPdfCosineSimilarity($queryEmbedding, $embedding);
        if ($score <= 0.0) {
            continue;
        }

        $chunk['embedding'] = $embedding;
        $chunk['dense_score'] = round($score, 6);
        $ranked[] = $chunk;
    }

    usort($ranked, static function (array $left, array $right): int {
        return ($right['dense_score'] <=> $left['dense_score'])
            ?: strcmp((string) ($left['chunk_id'] ?? ''), (string) ($right['chunk_id'] ?? ''));
    });

    $results = array_slice($ranked, 0, $topK);
    aiCopilotRagSafeLog('dense_retrieval_completed', [
        'request_id' => $options['request_id'] ?? '',
        'role' => $role,
        'mode' => $options['mode'] ?? '',
        'retrieval_mode' => 'dense_only',
        'dense_result_count' => count($results),
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['source_id'] ?? ''), $results), static fn($item) => trim($item) !== ''))), 0, 5),
    ]);

    return $results;
}
