<?php

require_once(__DIR__ . '/vector_retriever.php');

function aiCopilotRagLoadUploadedDocumentChunks(array $options = []): array
{
    $role = strtolower(trim((string) ($options['role'] ?? 'doctor')));
    if (in_array($role, ['billing', 'front_desk'], true)) {
        return [];
    }

    $patientKey = trim((string) ($options['patient_key'] ?? ''));
    $requestedDocumentTypes = array_values(array_filter(array_map('strval', $options['requested_document_types'] ?? []), static fn($item) => trim($item) !== ''));
    $records = aiCopilotLabPdfReadVectorStore()['records'] ?? [];
    $chunks = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        if ($patientKey !== '' && trim((string) ($record['patientKey'] ?? '')) !== $patientKey) {
            continue;
        }

        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $sourceType = strtolower(trim((string) ($metadata['sourceType'] ?? '')));
        if (!in_array($sourceType, ['lab_pdf', 'intake_form'], true)) {
            continue;
        }

        $documentType = strtolower(trim((string) ($metadata['documentType'] ?? ($sourceType === 'intake_form' ? 'intake_form' : 'lab_results'))));
        if ($requestedDocumentTypes !== [] && !in_array($documentType, $requestedDocumentTypes, true)) {
            continue;
        }

        $chunkId = trim((string) ($record['id'] ?? ''));
        $chunkText = aiCopilotRagNormalizeText((string) ($record['chunkText'] ?? ''));
        if ($chunkId === '' || $chunkText === '') {
            continue;
        }

        $sourcePage = isset($metadata['sourcePage']) && is_numeric($metadata['sourcePage']) ? (int) $metadata['sourcePage'] : null;
        $pageOrSection = $sourcePage !== null ? 'page ' . $sourcePage : ($sourceType === 'intake_form' ? 'uploaded intake form chunk' : 'uploaded lab pdf chunk');
        $chunks[] = aiCopilotRagNormalizeChunk([
            'chunk_id' => $chunkId,
            'source_type' => $sourceType,
            'source_id' => trim((string) ($metadata['sourceId'] ?? $chunkId)),
            'source_document_id' => isset($metadata['sourceDocumentId']) && is_numeric($metadata['sourceDocumentId']) ? (int) $metadata['sourceDocumentId'] : null,
            'title' => trim((string) ($record['displayFileName'] ?? $record['fileName'] ?? aiCopilotAttachmentSourceTitle($sourceType))),
            'document_type' => $documentType,
            'page_or_section' => $pageOrSection,
            'field_or_chunk_id' => $chunkId,
            'text' => $chunkText,
            'quote_or_value' => aiCopilotRagPreviewText($chunkText),
            'allowed_roles' => ['doctor', 'nurse'],
            'workflow_tags' => [$sourceType, 'rag'],
            'review_status' => trim((string) ($metadata['reviewStatus'] ?? 'pending_clinician_review')),
            'patient_id' => isset($record['patientId']) && is_numeric($record['patientId']) ? (int) $record['patientId'] : null,
            'patient_key' => trim((string) ($record['patientKey'] ?? '')),
            'source_page' => $sourcePage,
            'source_label' => trim((string) ($metadata['sourceLabel'] ?? aiCopilotAttachmentSourceLabel($sourceType))),
            'embedding' => is_array($record['embedding'] ?? null) ? $record['embedding'] : [],
            'confidence' => 0.88,
            'metadata' => $metadata,
        ]);
    }

    return $chunks;
}

function aiCopilotRagNormalizeScores(array $items, string $scoreKey): array
{
    $maxScore = 0.0;
    foreach ($items as $item) {
        $score = isset($item[$scoreKey]) && is_numeric($item[$scoreKey]) ? (float) $item[$scoreKey] : 0.0;
        if ($score > $maxScore) {
            $maxScore = $score;
        }
    }

    if ($maxScore <= 0.0) {
        return $items;
    }

    foreach ($items as $index => $item) {
        $score = isset($item[$scoreKey]) && is_numeric($item[$scoreKey]) ? (float) $item[$scoreKey] : 0.0;
        $items[$index][$scoreKey . '_normalized'] = round($score / $maxScore, 6);
    }

    return $items;
}

function aiCopilotHybridRetrieve(string $query, array $options = []): array
{
    $role = strtolower(trim((string) ($options['role'] ?? 'doctor')));
    $mode = trim((string) ($options['mode'] ?? 'general_assistant'));
    $workflowTags = aiCopilotRagRequestedWorkflowTags($query, $mode);
    $requestedDocumentTypes = aiCopilotRagDetectRequestedDocumentTypes($query, $mode);
    $guidelineChunks = aiCopilotGuidelineChunkCorpus();
    $uploadedChunks = aiCopilotRagLoadUploadedDocumentChunks([
        'role' => $role,
        'patient_key' => (string) ($options['patient_key'] ?? ''),
        'requested_document_types' => $requestedDocumentTypes,
    ]);
    $corpus = array_merge($guidelineChunks, $uploadedChunks);

    $sparse = aiCopilotRagSparseRetrieve($corpus, $query, [
        'role' => $role,
        'workflow_tags' => $workflowTags,
        'top_k' => aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_SPARSE', 8),
    ]);
    $dense = aiCopilotRagDenseRetrieve($corpus, $query, [
        'role' => $role,
        'workflow_tags' => $workflowTags,
        'top_k' => aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_DENSE', 8),
        'request_id' => $options['request_id'] ?? '',
        'mode' => $mode,
    ]);

    $sparse = aiCopilotRagNormalizeScores($sparse, 'sparse_score');
    $dense = aiCopilotRagNormalizeScores($dense, 'dense_score');
    $sparseMap = [];
    foreach ($sparse as $item) {
        $sparseMap[$item['chunk_id']] = $item;
    }
    $denseMap = [];
    foreach ($dense as $item) {
        $denseMap[$item['chunk_id']] = $item;
    }

    $keys = array_values(array_unique(array_merge(array_keys($sparseMap), array_keys($denseMap))));
    $sparseWeight = aiCopilotRagReadFloatEnv('AI_COPILOT_SPARSE_WEIGHT', 0.45);
    $denseWeight = aiCopilotRagReadFloatEnv('AI_COPILOT_DENSE_WEIGHT', 0.55);
    $merged = [];

    foreach ($keys as $chunkId) {
        $base = $denseMap[$chunkId] ?? $sparseMap[$chunkId] ?? [];
        if ($base === []) {
            continue;
        }
        $sparseScore = (float) ($sparseMap[$chunkId]['sparse_score_normalized'] ?? 0.0);
        $denseScore = (float) ($denseMap[$chunkId]['dense_score_normalized'] ?? 0.0);
        $base['hybrid_score'] = round(($sparseScore * $sparseWeight) + ($denseScore * $denseWeight), 6);
        $base['sparse_score'] = (float) ($sparseMap[$chunkId]['sparse_score'] ?? $base['sparse_score'] ?? 0.0);
        $base['dense_score'] = (float) ($denseMap[$chunkId]['dense_score'] ?? $base['dense_score'] ?? 0.0);
        $merged[] = $base;
    }

    usort($merged, static function (array $left, array $right): int {
        return ($right['hybrid_score'] <=> $left['hybrid_score'])
            ?: ($right['dense_score'] <=> $left['dense_score'])
            ?: ($right['sparse_score'] <=> $left['sparse_score']);
    });

    $retrievalMode = 'no_grounded_evidence';
    if ($sparse !== [] && $dense !== []) {
        $retrievalMode = 'hybrid';
    } elseif ($sparse !== []) {
        $retrievalMode = 'sparse_only';
    } elseif ($dense !== []) {
        $retrievalMode = 'dense_only';
    }

    $merged = array_slice($merged, 0, max(1, aiCopilotRagReadIntEnv('AI_COPILOT_MAX_HYBRID_CANDIDATES', 20)));
    aiCopilotRagSafeLog('hybrid_retrieval_completed', [
        'request_id' => $options['request_id'] ?? '',
        'role' => $role,
        'mode' => $mode,
        'retrieval_mode' => $retrievalMode,
        'sparse_result_count' => count($sparse),
        'dense_result_count' => count($dense),
        'hybrid_candidate_count' => count($merged),
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['source_id'] ?? ''), $merged), static fn($item) => trim($item) !== ''))), 0, 5),
    ]);

    return [
        'retrieval_mode' => $retrievalMode,
        'requested_document_types' => $requestedDocumentTypes,
        'workflow_tags' => $workflowTags,
        'guideline_chunks' => $guidelineChunks,
        'uploaded_chunks' => $uploadedChunks,
        'sparse_results' => $sparse,
        'dense_results' => $dense,
        'candidates' => $merged,
    ];
}

