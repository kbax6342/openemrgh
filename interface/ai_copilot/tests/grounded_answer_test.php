<?php

require_once(dirname(__DIR__) . '/rag/grounded_answer.php');

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$guidelineEvidenceResult = [
    'retrieval_mode' => 'hybrid',
    'rerank_provider' => 'fallback_score_sort',
    'sparse_result_count' => 3,
    'dense_result_count' => 2,
    'hybrid_candidate_count' => 4,
    'reranked_result_count' => 2,
    'evidence_snippets' => [
        [
            'text' => 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
            'relevance_score' => 0.91,
            'retrieval_mode' => 'hybrid',
            'rerank_provider' => 'fallback_score_sort',
            'citation' => [
                'source_type' => 'demo_guideline',
                'source_id' => 'guideline_missing_data_and_safe_failure_v1',
                'page_or_section' => 'Missing Data Handling',
                'field_or_chunk_id' => 'guideline_missing_data_and_safe_failure_v1_chunk_002',
                'quote_or_value' => 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
                'confidence' => 0.91,
                'review_status' => 'demo_only',
            ],
            'source_title' => 'Missing Data and Safe Failure Demo Guidance',
            'review_status' => 'demo_only',
        ],
    ],
    'missing_data' => [],
];

$attachmentResult = [
    'tool_output' => [
        'document_metadata' => [
            'patient_id' => 1001,
            'document_type' => 'lab_pdf',
            'review_status' => 'pending_clinician_review',
            'display_file_name' => 'Marcus Johnson Synthetic Lab Results.pdf',
            'title' => 'Marcus Johnson Synthetic Lab Results.pdf',
        ],
        'extracted_facts' => [
            [
                'name' => 'Hemoglobin A1c',
                'value' => '8.2%',
                'unit' => '%',
                'interpretation' => 'high',
                'review_status' => 'pending_clinician_review',
                'source_citation' => [
                    'source_type' => 'lab_pdf',
                    'source_id' => 'doc_123',
                    'source_document_id' => 123,
                    'page_or_section' => 'page 1 / lab results table',
                    'field_or_chunk_id' => 'lab_result_a1c_001',
                    'quote_or_value' => 'Hemoglobin A1c 8.2%',
                    'confidence' => 0.94,
                    'document_type' => 'lab_pdf',
                    'review_status' => 'pending_clinician_review',
                    'patient_id' => 1001,
                ],
            ],
        ],
        'retrieval' => [
            'chunks' => [],
        ],
    ],
    'missing_data' => [],
];

// final answer only uses retrieved snippets
$draft = aiCopilotGroundedBuildDraft([
    'request_id' => 'grounded_answer_test',
    'role' => 'doctor',
    'mode' => 'lab_pdf_ingestion',
    'prompt' => 'Summarize the uploaded lab PDF and safe failure guidance.',
    'chart_context_result' => [],
    'guideline_evidence_result' => $guidelineEvidenceResult,
    'attachment_result' => $attachmentResult,
]);
assertTrue(trim((string) ($draft['answer'] ?? '')) !== '', 'final answer only uses retrieved snippets');

// Sources Used includes guideline chunks
$sourceTypes = array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['source_type'] ?? ''), $draft['sources_used'] ?? []), static fn($item) => trim($item) !== ''))));
assertTrue(in_array('demo_guideline', $sourceTypes, true), 'Sources Used includes guideline chunks');

// Sources Used includes uploaded document chunks when available
assertTrue(in_array('lab_pdf', $sourceTypes, true), 'Sources Used includes uploaded document chunks when available');

// no retrieved evidence returns safe no-answer
$noAnswer = aiCopilotGroundedBuildDraft([
    'request_id' => 'grounded_answer_none',
    'role' => 'doctor',
    'mode' => 'general_assistant',
    'prompt' => 'Answer without evidence.',
    'chart_context_result' => [],
    'guideline_evidence_result' => [
        'retrieval_mode' => 'no_grounded_evidence',
        'rerank_provider' => 'fallback_score_sort',
        'evidence_snippets' => [],
        'missing_data' => [],
    ],
    'attachment_result' => [],
]);
assertTrue(($noAnswer['answer'] ?? '') === 'I do not have enough source-grounded information to answer that safely.', 'no retrieved evidence returns safe no-answer');

echo "grounded_answer_test passed\n";
