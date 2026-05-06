<?php

/**
 * Demo vector store only. Replace with approved HIPAA-compliant vector storage before production.
 *
 * Lightweight local vector store for OpenEMR AI Copilot lab PDF demo retrieval.
 */

const AI_COPILOT_LAB_PDF_VECTOR_STORE_COMMENT = 'Demo vector store only. Replace with approved HIPAA-compliant vector storage before production.';

function aiCopilotLabPdfVectorStorePath(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'demo_lab_pdf_vectors.json';
}

function aiCopilotLabPdfTokenize(string $text): array
{
    $normalized = strtolower($text);
    $normalized = preg_replace('/[^a-z0-9%\.\/]+/', ' ', $normalized);
    if (!is_string($normalized)) {
        $normalized = '';
    }

    $parts = preg_split('/\s+/', trim($normalized)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn($item) => $item !== ''));
}

function aiCopilotLabPdfBuildDeterministicEmbedding(string $text, int $dimensions = 24): array
{
    $dimensions = max(8, $dimensions);
    $vector = array_fill(0, $dimensions, 0.0);
    $tokens = aiCopilotLabPdfTokenize($text);

    foreach ($tokens as $index => $token) {
        $hash = 0;
        $length = strlen($token);
        for ($charIndex = 0; $charIndex < $length; $charIndex++) {
            $hash = (($hash << 5) - $hash) + ord($token[$charIndex]);
            $hash &= 0x7fffffff;
        }

        $vectorIndex = abs($hash + $index) % $dimensions;
        $vector[$vectorIndex] += 1 + ($length / 12);
    }

    $magnitude = 0.0;
    foreach ($vector as $value) {
        $magnitude += $value * $value;
    }

    $magnitude = sqrt($magnitude);
    if ($magnitude <= 0.0) {
        return $vector;
    }

    return array_map(static fn($value) => round($value / $magnitude, 6), $vector);
}

function aiCopilotLabPdfCosineSimilarity(array $left, array $right): float
{
    $size = max(count($left), count($right));
    if ($size <= 0) {
        return 0.0;
    }

    $dot = 0.0;
    $leftMagnitude = 0.0;
    $rightMagnitude = 0.0;
    for ($index = 0; $index < $size; $index++) {
        $leftValue = isset($left[$index]) ? (float) $left[$index] : 0.0;
        $rightValue = isset($right[$index]) ? (float) $right[$index] : 0.0;
        $dot += $leftValue * $rightValue;
        $leftMagnitude += $leftValue * $leftValue;
        $rightMagnitude += $rightValue * $rightValue;
    }

    if ($leftMagnitude <= 0.0 || $rightMagnitude <= 0.0) {
        return 0.0;
    }

    return $dot / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
}

function aiCopilotLabPdfReadVectorStore(): array
{
    $path = aiCopilotLabPdfVectorStorePath();
    if (!is_file($path)) {
        return [
            'comment' => AI_COPILOT_LAB_PDF_VECTOR_STORE_COMMENT,
            'records' => [],
        ];
    }

    $contents = file_get_contents($path);
    $decoded = json_decode(is_string($contents) ? $contents : '', true);
    $records = [];
    if (is_array($decoded['records'] ?? null)) {
        $records = $decoded['records'];
    } elseif (is_array($decoded)) {
        $records = $decoded;
    }

    return [
        'comment' => AI_COPILOT_LAB_PDF_VECTOR_STORE_COMMENT,
        'records' => array_values(array_filter($records, 'is_array')),
    ];
}

function aiCopilotLabPdfWriteVectorStore(array $records): void
{
    $payload = [
        'comment' => AI_COPILOT_LAB_PDF_VECTOR_STORE_COMMENT,
        'records' => array_values(array_filter($records, 'is_array')),
    ];

    file_put_contents(
        aiCopilotLabPdfVectorStorePath(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function aiCopilotLabPdfCreateVectorRecord(array $input): array
{
    $requestId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($input['request_id'] ?? 'request')) ?: 'request';
    $chunkIndex = isset($input['chunk_index']) && is_numeric($input['chunk_index']) ? (int) $input['chunk_index'] : 0;
    $chunkText = (string) ($input['chunk_text'] ?? '');
    $sourceType = preg_replace('/[^a-z_]/', '', strtolower((string) ($input['source_type'] ?? 'lab_pdf'))) ?: 'lab_pdf';
    $recordPrefix = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['record_prefix'] ?? ($sourceType === 'intake_form' ? 'intakeform' : 'labpdf')))) ?: 'labpdf';
    $sourceLabel = trim((string) ($input['source_label'] ?? ($sourceType === 'intake_form' ? 'Uploaded intake form' : 'Uploaded lab PDF')));
    $documentType = preg_replace('/[^a-z_]/', '', strtolower((string) ($input['document_type'] ?? ($sourceType === 'intake_form' ? 'intake_form' : 'lab_results')))) ?: ($sourceType === 'intake_form' ? 'intake_form' : 'lab_results');
    $originalFileName = trim((string) ($input['original_file_name'] ?? $input['file_name'] ?? 'attached-lab-report.pdf'));
    $displayFileName = trim((string) ($input['display_file_name'] ?? $originalFileName));
    $sourceId = trim((string) ($input['source_id'] ?? ($recordPrefix . '_source_' . $requestId)));
    $ingestionOrigin = trim((string) ($input['ingestion_origin'] ?? 'uploaded_file')) ?: 'uploaded_file';

    return [
        'id' => (string) ($input['id'] ?? ($recordPrefix . '_' . $requestId . '_' . $chunkIndex)),
        'patientKey' => (string) ($input['patient_key'] ?? ''),
        'patientDisplayName' => (string) ($input['patient_display_name'] ?? ''),
        'fileName' => $originalFileName,
        'displayFileName' => $displayFileName,
        'chunkText' => $chunkText,
        'embedding' => is_array($input['embedding'] ?? null)
            ? array_map(static fn($value) => (float) $value, $input['embedding'])
            : aiCopilotLabPdfBuildDeterministicEmbedding($chunkText),
        'metadata' => [
            'sourceType' => $sourceType,
            'documentType' => $documentType,
            'sourceLabel' => $sourceLabel,
            'originalFileName' => $originalFileName,
            'displayFileName' => $displayFileName,
            'sourceId' => $sourceId,
            'chunkIndex' => $chunkIndex,
            'uploadedAt' => (string) ($input['uploaded_at'] ?? gmdate('c')),
            'sourcePage' => $input['source_page'] ?? null,
            'role' => (string) ($input['role'] ?? 'Doctor'),
            'extractionMethod' => (string) ($input['extraction_method'] ?? 'pdf_text'),
            'requestId' => $requestId,
            'seededDemo' => !empty($input['seeded_demo']),
            'ingestionOrigin' => $ingestionOrigin,
        ],
    ];
}

function aiCopilotLabPdfUpsertVectorRecords(array $records): array
{
    $store = aiCopilotLabPdfReadVectorStore();
    $map = [];
    foreach ($store['records'] as $record) {
        $map[(string) ($record['id'] ?? '')] = $record;
    }

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $id = (string) ($record['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $map[$id] = $record;
    }

    $upserted = array_values($map);
    aiCopilotLabPdfWriteVectorStore($upserted);
    return $upserted;
}

function aiCopilotLabPdfQueryVectorStore(string $prompt, array $options = []): array
{
    $store = aiCopilotLabPdfReadVectorStore();
    $patientKey = (string) ($options['patient_key'] ?? '');
    $fileName = (string) ($options['file_name'] ?? '');
    $sourceType = preg_replace('/[^a-z_]/', '', strtolower((string) ($options['source_type'] ?? '')));
    $documentType = preg_replace('/[^a-z_]/', '', strtolower((string) ($options['document_type'] ?? '')));
    $sourceId = trim((string) ($options['source_id'] ?? ''));
    $limit = isset($options['limit']) && is_numeric($options['limit']) ? max(1, (int) $options['limit']) : 4;
    $queryEmbedding = aiCopilotLabPdfBuildDeterministicEmbedding($prompt);
    $ranked = [];

    foreach ($store['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        if ($patientKey !== '' && (string) ($record['patientKey'] ?? '') !== $patientKey) {
            continue;
        }

        if ($fileName !== '' && (string) ($record['fileName'] ?? '') !== $fileName) {
            continue;
        }

        if ($sourceType !== '' && strtolower((string) ($record['metadata']['sourceType'] ?? '')) !== $sourceType) {
            continue;
        }

        $recordDocumentType = strtolower((string) ($record['metadata']['documentType'] ?? ''));
        if ($recordDocumentType === '') {
            $recordDocumentType = strtolower((string) (($record['metadata']['sourceType'] ?? '') === 'intake_form' ? 'intake_form' : 'lab_results'));
        }

        if ($documentType !== '' && $recordDocumentType !== $documentType) {
            continue;
        }

        if ($sourceId !== '' && (string) ($record['metadata']['sourceId'] ?? '') !== $sourceId) {
            continue;
        }

        $embedding = is_array($record['embedding'] ?? null)
            ? array_map(static fn($value) => (float) $value, $record['embedding'])
            : [];
        $ranked[] = array_merge($record, [
            'score' => aiCopilotLabPdfCosineSimilarity($queryEmbedding, $embedding),
        ]);
    }

    usort($ranked, static function (array $left, array $right): int {
        return ($right['score'] <=> $left['score'])
            ?: strcmp((string) ($right['metadata']['uploadedAt'] ?? ''), (string) ($left['metadata']['uploadedAt'] ?? ''));
    });

    return array_slice($ranked, 0, $limit);
}

function aiCopilotLabPdfListSourceDocuments(array $options = []): array
{
    $store = aiCopilotLabPdfReadVectorStore();
    $patientKey = (string) ($options['patient_key'] ?? '');
    $sourceType = preg_replace('/[^a-z_]/', '', strtolower((string) ($options['source_type'] ?? '')));
    $documentType = preg_replace('/[^a-z_]/', '', strtolower((string) ($options['document_type'] ?? '')));
    $documentTypes = array_values(array_filter(array_map(static fn($item) => preg_replace('/[^a-z_]/', '', strtolower((string) $item)), is_array($options['document_types'] ?? null) ? $options['document_types'] : []), static fn($item) => $item !== ''));
    $sourceId = trim((string) ($options['source_id'] ?? ''));
    $ingestionOrigin = trim((string) ($options['ingestion_origin'] ?? ''));
    $documents = [];

    foreach ($store['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $recordSourceType = strtolower((string) ($metadata['sourceType'] ?? ''));
        $recordDocumentType = strtolower((string) ($metadata['documentType'] ?? ''));
        if ($recordDocumentType === '') {
            $recordDocumentType = $recordSourceType === 'intake_form' ? 'intake_form' : 'lab_results';
        }
        $recordSourceId = (string) ($metadata['sourceId'] ?? '');
        $recordIngestionOrigin = trim((string) ($metadata['ingestionOrigin'] ?? ''));

        if ($patientKey !== '' && (string) ($record['patientKey'] ?? '') !== $patientKey) {
            continue;
        }
        if ($sourceType !== '' && $recordSourceType !== $sourceType) {
            continue;
        }
        if ($documentType !== '' && $recordDocumentType !== $documentType) {
            continue;
        }
        if ($documentTypes !== [] && !in_array($recordDocumentType, $documentTypes, true)) {
            continue;
        }
        if ($sourceId !== '' && $recordSourceId !== $sourceId) {
            continue;
        }
        if ($ingestionOrigin !== '' && $recordIngestionOrigin !== $ingestionOrigin) {
            continue;
        }

        $groupKey = $recordSourceId !== '' ? $recordSourceId : ((string) ($record['patientKey'] ?? '') . '::' . $recordDocumentType . '::' . (string) ($record['fileName'] ?? ''));
        if (!isset($documents[$groupKey])) {
            $documents[$groupKey] = [
                'source_id' => $recordSourceId !== '' ? $recordSourceId : $groupKey,
                'patient_key' => (string) ($record['patientKey'] ?? ''),
                'patient_name' => (string) ($record['patientDisplayName'] ?? ''),
                'document_type' => $recordDocumentType !== '' ? $recordDocumentType : ($recordSourceType === 'intake_form' ? 'intake_form' : 'lab_results'),
                'source_type' => $recordSourceType !== '' ? $recordSourceType : 'lab_pdf',
                'original_file_name' => trim((string) ($metadata['originalFileName'] ?? $record['fileName'] ?? '')),
                'display_file_name' => trim((string) ($metadata['displayFileName'] ?? $record['displayFileName'] ?? $record['fileName'] ?? '')),
                'uploaded_at' => (string) ($metadata['uploadedAt'] ?? ''),
                'extraction_method' => (string) ($metadata['extractionMethod'] ?? 'pdf_text'),
                'seeded_demo' => !empty($metadata['seededDemo']),
                'ingestion_origin' => $recordIngestionOrigin !== '' ? $recordIngestionOrigin : 'uploaded_file',
                'chunk_ids' => [],
                'chunk_count' => 0,
            ];
        }

        $chunkId = trim((string) ($record['id'] ?? ''));
        if ($chunkId !== '') {
            $documents[$groupKey]['chunk_ids'][] = $chunkId;
        }
        $documents[$groupKey]['chunk_count'] += 1;
    }

    $documents = array_map(static function (array $document): array {
        $document['chunk_ids'] = array_values(array_unique(array_filter(array_map('strval', $document['chunk_ids'] ?? []), static fn($item) => trim($item) !== '')));
        return $document;
    }, array_values($documents));

    usort($documents, static function (array $left, array $right): int {
        return strcmp((string) ($right['uploaded_at'] ?? ''), (string) ($left['uploaded_at'] ?? ''))
            ?: strcmp((string) ($left['display_file_name'] ?? ''), (string) ($right['display_file_name'] ?? ''));
    });

    return $documents;
}

function aiCopilotLabPdfCountVectorRecords(array $options = []): array
{
    $store = aiCopilotLabPdfReadVectorStore();
    $patientKey = (string) ($options['patient_key'] ?? '');
    $sourceType = preg_replace('/[^a-z_]/', '', strtolower((string) ($options['source_type'] ?? '')));
    $records = [];

    foreach ($store['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }
        if ($patientKey !== '' && (string) ($record['patientKey'] ?? '') !== $patientKey) {
            continue;
        }
        if ($sourceType !== '' && strtolower((string) ($record['metadata']['sourceType'] ?? '')) !== $sourceType) {
            continue;
        }
        $records[] = $record;
    }

    $fileNames = array_values(array_unique(array_filter(array_map(static fn($record) => trim((string) ($record['fileName'] ?? '')), $records), static fn($item) => $item !== '')));

    return [
        'record_count' => count($records),
        'file_count' => count($fileNames),
        'file_names' => $fileNames,
    ];
}

function aiCopilotLabPdfClearVectorRecords(array $options = []): array
{
    $store = aiCopilotLabPdfReadVectorStore();
    $patientKey = (string) ($options['patient_key'] ?? '');
    $sourceType = preg_replace('/[^a-z_]/', '', strtolower((string) ($options['source_type'] ?? '')));
    $kept = [];
    $removed = [];

    foreach ($store['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $matchesPatient = $patientKey !== '' && (string) ($record['patientKey'] ?? '') === $patientKey;
        $matchesSourceType = $sourceType !== '' && strtolower((string) ($record['metadata']['sourceType'] ?? '')) === $sourceType;

        if ($matchesPatient && $matchesSourceType) {
            $removed[] = $record;
            continue;
        }

        $kept[] = $record;
    }

    aiCopilotLabPdfWriteVectorStore($kept);
    $removedFiles = array_values(array_unique(array_filter(array_map(static fn($record) => trim((string) ($record['fileName'] ?? '')), $removed), static fn($item) => $item !== '')));
    $remainingIntake = aiCopilotLabPdfCountVectorRecords([
        'patient_key' => $patientKey,
        'source_type' => 'intake_form',
    ]);

    return [
        'removedLabFiles' => count($removedFiles),
        'removedLabChunks' => count($removed),
        'removedLabExtractions' => count($removedFiles),
        'remainingIntakeForms' => $remainingIntake['file_count'] ?? 0,
        'removedFileNames' => $removedFiles,
    ];
}
