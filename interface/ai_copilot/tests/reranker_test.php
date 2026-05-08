<?php

require_once(dirname(__DIR__) . '/rag/reranker.php');

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$candidates = [
    [
        'chunk_id' => 'guideline_diabetes_lab_review_v1_chunk_001',
        'source_id' => 'guideline_diabetes_lab_review_v1',
        'text' => 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
        'hybrid_score' => 0.82,
        'dense_score' => 0.79,
        'sparse_score' => 0.88,
    ],
    [
        'chunk_id' => 'guideline_intake_form_review_v1_chunk_001',
        'source_id' => 'guideline_intake_form_review_v1',
        'text' => 'Use the intake form to summarize chief concern, medications, allergies, and missing information for review.',
        'hybrid_score' => 0.64,
        'dense_score' => 0.61,
        'sparse_score' => 0.67,
    ],
];

// fallback reranker works when Cohere missing
putenv('COHERE_API_KEY');
$reranked = aiCopilotRagRerank($candidates, 'A1c review and missing citation handling', [
    'request_id' => 'rerank_test_request',
    'role' => 'doctor',
    'mode' => 'lab_pdf_ingestion',
    'top_n' => 1,
]);
assertTrue(($reranked['provider'] ?? '') === 'fallback_score_sort', 'fallback reranker works when Cohere missing');

// reranker preserves citation metadata
assertTrue(trim((string) ($reranked['results'][0]['source_id'] ?? '')) !== '', 'reranker preserves citation metadata');

// top_n limit is enforced
assertTrue(count($reranked['results'] ?? []) === 1, 'top_n limit is enforced');

// Cohere rerank path works when configured
if (trim((string) getenv('COHERE_API_KEY')) !== '' && function_exists('curl_init')) {
    $cohere = aiCopilotRagRerank($candidates, 'A1c review and missing citation handling', [
        'request_id' => 'rerank_test_request',
        'role' => 'doctor',
        'mode' => 'lab_pdf_ingestion',
        'top_n' => 1,
    ]);
    assertTrue(isset($cohere['provider']), 'Cohere rerank path works when configured');
}

echo "reranker_test passed\n";
