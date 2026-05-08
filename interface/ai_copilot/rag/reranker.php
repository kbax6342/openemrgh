<?php

require_once(__DIR__ . '/hybrid_retriever.php');

function aiCopilotRagCohereRerank(array $candidates, string $query, array $options = []): array
{
    $apiKey = trim((string) getenv('COHERE_API_KEY'));
    if ($apiKey === '' || !function_exists('curl_init')) {
        return [];
    }

    $endpoint = 'https://api.cohere.ai/v2/rerank';
    $topN = isset($options['top_n']) && is_numeric($options['top_n'])
        ? max(1, (int) $options['top_n'])
        : max(1, aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_RERANKED', 5));
    $documents = array_map(static fn($item) => (string) ($item['text'] ?? ''), $candidates);
    if ($documents === []) {
        return [];
    }

    $payload = json_encode([
        'model' => trim((string) getenv('AI_COPILOT_RERANK_MODEL')) ?: 'rerank-v3.5',
        'query' => $query,
        'top_n' => $topN,
        'documents' => $documents,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        return [];
    }

    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if (!is_string($response) || $response === '' || $statusCode < 200 || $statusCode >= 300) {
        aiCopilotRagSafeLog('rerank_completed', [
            'request_id' => $options['request_id'] ?? '',
            'role' => $options['role'] ?? '',
            'mode' => $options['mode'] ?? '',
            'no_answer_reason' => $curlError !== '' ? $curlError : 'cohere_unavailable',
        ]);
        return [];
    }

    $decoded = json_decode($response, true);
    $results = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
    $reranked = [];
    foreach ($results as $result) {
        if (!is_array($result)) {
            continue;
        }
        $index = isset($result['index']) && is_numeric($result['index']) ? (int) $result['index'] : -1;
        if ($index < 0 || !isset($candidates[$index])) {
            continue;
        }
        $candidate = $candidates[$index];
        $candidate['relevance_score'] = isset($result['relevance_score']) && is_numeric($result['relevance_score'])
            ? round((float) $result['relevance_score'], 6)
            : (float) ($candidate['hybrid_score'] ?? 0.0);
        $reranked[] = $candidate;
    }

    return $reranked;
}

function aiCopilotRagRerank(array $candidates, string $query, array $options = []): array
{
    $topN = isset($options['top_n']) && is_numeric($options['top_n'])
        ? max(1, (int) $options['top_n'])
        : max(1, aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_RERANKED', 5));
    $provider = strtolower(trim((string) getenv('AI_COPILOT_RERANK_PROVIDER')));
    if ($provider === '') {
        $provider = 'cohere';
    }

    $reranked = [];
    $rerankProvider = 'fallback_score_sort';
    if ($provider === 'cohere') {
        $reranked = aiCopilotRagCohereRerank($candidates, $query, $options);
        if ($reranked !== []) {
            $rerankProvider = 'cohere';
        }
    }

    if ($reranked === []) {
        $reranked = $candidates;
        usort($reranked, static function (array $left, array $right): int {
            return (($right['hybrid_score'] ?? 0.0) <=> ($left['hybrid_score'] ?? 0.0))
                ?: (($right['dense_score'] ?? 0.0) <=> ($left['dense_score'] ?? 0.0))
                ?: (($right['sparse_score'] ?? 0.0) <=> ($left['sparse_score'] ?? 0.0));
        });
        foreach ($reranked as $index => $candidate) {
            $reranked[$index]['relevance_score'] = round((float) ($candidate['hybrid_score'] ?? $candidate['dense_score'] ?? $candidate['sparse_score'] ?? 0.0), 6);
        }
    }

    $reranked = array_slice($reranked, 0, $topN);
    aiCopilotRagSafeLog('rerank_completed', [
        'request_id' => $options['request_id'] ?? '',
        'role' => $options['role'] ?? '',
        'mode' => $options['mode'] ?? '',
        'reranked_result_count' => count($reranked),
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['source_id'] ?? ''), $reranked), static fn($item) => trim($item) !== ''))), 0, 5),
    ]);

    return [
        'provider' => $rerankProvider,
        'results' => $reranked,
    ];
}

