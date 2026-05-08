<?php

require_once(dirname(__DIR__) . '/rag/hybrid_retriever.php');

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

putenv('AI_COPILOT_RAG_ENABLED=true');
putenv('AI_COPILOT_GUIDELINE_RAG_ENABLED=true');
putenv('AI_COPILOT_HYBRID_RAG_ENABLED=true');
putenv('AI_COPILOT_EMBEDDINGS_ENABLED=true');

// combines sparse + dense results
$result = aiCopilotHybridRetrieve('summarize intake form medication reconciliation and A1c review', [
    'request_id' => 'hybrid_test_request',
    'role' => 'doctor',
    'mode' => 'lab_pdf_ingestion',
    'patient_key' => 'NONEXISTENT-PATIENT',
]);
assertTrue(($result['candidates'] ?? []) !== [], 'combines sparse + dense results');

// deduplicates chunk IDs
$chunkIds = array_map(static fn($item) => (string) ($item['chunk_id'] ?? ''), $result['candidates']);
assertTrue(count($chunkIds) === count(array_unique($chunkIds)), 'deduplicates chunk IDs');

// preserves source metadata
assertTrue(trim((string) ($result['candidates'][0]['source_id'] ?? '')) !== '', 'preserves source metadata');

// filters by role
$billingResult = aiCopilotHybridRetrieve('medication reconciliation and allergies from intake form', [
    'request_id' => 'hybrid_test_billing',
    'role' => 'billing',
    'mode' => 'billing',
    'patient_key' => 'NONEXISTENT-PATIENT',
]);
foreach (($billingResult['candidates'] ?? []) as $candidate) {
    $allowedRoles = is_array($candidate['allowed_roles'] ?? null) ? $candidate['allowed_roles'] : [];
    if ($allowedRoles !== []) {
        assertTrue(in_array('billing', $allowedRoles, true), 'filters by role');
    }
}

// returns no answer when no evidence exists
putenv('AI_COPILOT_EMBEDDINGS_ENABLED=false');
$noEvidence = aiCopilotHybridRetrieve('zqxjv glyphorium kaleidoscopic nonclinical nonsense tokens', [
    'request_id' => 'hybrid_test_none',
    'role' => 'doctor',
    'mode' => 'general_assistant',
    'patient_key' => 'NONEXISTENT-PATIENT',
]);
assertTrue(($noEvidence['retrieval_mode'] ?? '') === 'no_grounded_evidence', 'returns no answer when no evidence exists');

echo "hybrid_retrieval_test passed\n";
