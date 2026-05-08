<?php

require_once(dirname(__DIR__) . '/rag/keyword_retriever.php');

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$chunks = aiCopilotGuidelineChunkCorpus();

// keyword search finds A1c guideline
$a1cResults = aiCopilotRagSparseRetrieve($chunks, 'A1c review and clinician review for uploaded lab pdf', [
    'role' => 'doctor',
    'workflow_tags' => ['lab_pdf'],
    'top_k' => 5,
]);
assertTrue($a1cResults !== [], 'keyword search finds A1c guideline');

// keyword search finds intake form guideline
$intakeResults = aiCopilotRagSparseRetrieve($chunks, 'intake form medication reconciliation and chief concern review', [
    'role' => 'doctor',
    'workflow_tags' => ['intake_form'],
    'top_k' => 5,
]);
assertTrue($intakeResults !== [], 'keyword search finds intake form guideline');

// keyword search finds role-boundary guideline
$roleResults = aiCopilotRagSparseRetrieve($chunks, 'role boundary for billing and front desk requests', [
    'role' => 'billing',
    'workflow_tags' => ['safety'],
    'top_k' => 5,
]);
assertTrue($roleResults !== [], 'keyword search finds role-boundary guideline');

// sparse retrieval preserves citation metadata
$citation = aiCopilotRagChunkToCitation($a1cResults[0], 0.9);
assertTrue(trim((string) ($citation['source_id'] ?? '')) !== '', 'sparse retrieval preserves citation metadata');
assertTrue(trim((string) ($citation['page_or_section'] ?? '')) !== '', 'sparse retrieval preserves citation metadata');

echo "sparse_retrieval_test passed\n";
