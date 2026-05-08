<?php

require_once(dirname(__DIR__) . '/rag/vector_retriever.php');

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$chunks = aiCopilotGuidelineChunkCorpus();

// vector retrieval returns semantically relevant chunks
putenv('AI_COPILOT_RAG_ENABLED=true');
putenv('AI_COPILOT_EMBEDDINGS_ENABLED=true');
$results = aiCopilotRagDenseRetrieve($chunks, 'review elevated cholesterol and LDL findings from an uploaded lab pdf', [
    'role' => 'doctor',
    'workflow_tags' => ['lab_pdf'],
    'top_k' => 5,
]);
assertTrue($results !== [], 'vector retrieval returns semantically relevant chunks');

// dense retrieval preserves citation metadata
$citation = aiCopilotRagChunkToCitation($results[0], (float) ($results[0]['dense_score'] ?? 0.8));
assertTrue(trim((string) ($citation['field_or_chunk_id'] ?? '')) !== '', 'dense retrieval preserves citation metadata');

// dense retrieval fails gracefully when embeddings disabled
putenv('AI_COPILOT_EMBEDDINGS_ENABLED=false');
$disabledResults = aiCopilotRagDenseRetrieve($chunks, 'review elevated cholesterol and LDL findings from an uploaded lab pdf', [
    'role' => 'doctor',
    'workflow_tags' => ['lab_pdf'],
    'top_k' => 5,
]);
assertTrue($disabledResults === [], 'dense retrieval fails gracefully when embeddings disabled');

echo "dense_retrieval_test passed\n";
