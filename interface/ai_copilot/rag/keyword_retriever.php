<?php

require_once(__DIR__ . '/chunk_guidelines.php');

function aiCopilotRagKeywordWeights(): array
{
    return [
        'a1c' => 2.4,
        'hba1c' => 2.2,
        'ldl' => 2.1,
        'cholesterol' => 1.6,
        'creatinine' => 2.0,
        'egfr' => 2.0,
        'blood' => 1.1,
        'pressure' => 1.1,
        'medication' => 1.8,
        'allergy' => 1.7,
        'chief' => 1.5,
        'concern' => 1.5,
        'family' => 1.4,
        'history' => 1.2,
        'intake' => 1.8,
        'form' => 1.3,
        'missing' => 1.4,
        'data' => 1.1,
        'citation' => 1.6,
        'clinician' => 1.4,
        'review' => 1.4,
        'role' => 1.3,
        'boundary' => 1.3,
        'nurse' => 1.2,
        'doctor' => 1.2,
        'billing' => 1.2,
        'front' => 1.1,
        'desk' => 1.1,
    ];
}

function aiCopilotRagSparseRetrieve(array $chunks, string $query, array $options = []): array
{
    $role = strtolower(trim((string) ($options['role'] ?? 'doctor')));
    $workflowTags = is_array($options['workflow_tags'] ?? null) ? $options['workflow_tags'] : [];
    $topK = isset($options['top_k']) && is_numeric($options['top_k'])
        ? max(1, (int) $options['top_k'])
        : max(1, aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_SPARSE', 8));
    $tokens = aiCopilotLabPdfTokenize($query);
    $weights = aiCopilotRagKeywordWeights();
    $ranked = [];

    foreach ($chunks as $chunk) {
        $chunk = aiCopilotRagNormalizeChunk(is_array($chunk) ? $chunk : []);
        if ($chunk['chunk_id'] === '') {
            continue;
        }
        if (!aiCopilotRagRoleAllowsChunk($chunk, $role) || !aiCopilotRagWorkflowAllowsChunk($chunk, $workflowTags)) {
            continue;
        }

        $haystack = strtolower(implode("\n", [
            $chunk['title'],
            $chunk['page_or_section'],
            $chunk['text'],
            implode(' ', $chunk['workflow_tags']),
        ]));
        $score = 0.0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $weight = $weights[$token] ?? 1.0;
            $occurrences = substr_count($haystack, strtolower($token));
            if ($occurrences > 0) {
                $score += $weight * $occurrences;
            }
            if (strtolower($chunk['page_or_section']) === strtolower($token)) {
                $score += 1.4;
            }
        }

        if ($score <= 0.0) {
            continue;
        }

        $chunk['sparse_score'] = round($score, 4);
        $ranked[] = $chunk;
    }

    usort($ranked, static function (array $left, array $right): int {
        return ($right['sparse_score'] <=> $left['sparse_score'])
            ?: strcmp((string) ($left['chunk_id'] ?? ''), (string) ($right['chunk_id'] ?? ''));
    });

    $results = array_slice($ranked, 0, $topK);
    aiCopilotRagSafeLog('sparse_retrieval_completed', [
        'request_id' => $options['request_id'] ?? '',
        'role' => $role,
        'mode' => $options['mode'] ?? '',
        'retrieval_mode' => 'sparse_only',
        'sparse_result_count' => count($results),
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['source_id'] ?? ''), $results), static fn($item) => trim($item) !== ''))), 0, 5),
    ]);

    return $results;
}
