<?php

require_once(dirname(__DIR__) . '/rag/chunk_guidelines.php');

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

// guideline files load
$documents = aiCopilotGuidelineLoadCorpus();
assertTrue($documents !== [], 'guideline files load');

$chunks = aiCopilotGuidelineChunkCorpus();
assertTrue($chunks !== [], 'chunks are created');

foreach ($chunks as $chunk) {
    // every chunk has source metadata
    assertTrue(trim((string) ($chunk['source_type'] ?? '')) !== '', 'every chunk has source metadata');
    assertTrue(trim((string) ($chunk['source_id'] ?? '')) !== '', 'every chunk has source metadata');
    assertTrue(trim((string) ($chunk['title'] ?? '')) !== '', 'every chunk has source metadata');
    // every chunk has field_or_chunk_id
    assertTrue(trim((string) ($chunk['field_or_chunk_id'] ?? '')) !== '', 'every chunk has field_or_chunk_id');
    // every chunk has quote_or_value
    assertTrue(trim((string) ($chunk['quote_or_value'] ?? '')) !== '', 'every chunk has quote_or_value');
}

echo "guideline_chunking_test passed\n";
