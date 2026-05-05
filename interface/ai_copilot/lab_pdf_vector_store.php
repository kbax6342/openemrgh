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

    return [
        'id' => (string) ($input['id'] ?? ('labpdf_' . $requestId . '_' . $chunkIndex)),
        'patientKey' => (string) ($input['patient_key'] ?? ''),
        'patientDisplayName' => (string) ($input['patient_display_name'] ?? ''),
        'fileName' => (string) ($input['file_name'] ?? 'attached-lab-report.pdf'),
        'chunkText' => $chunkText,
        'embedding' => is_array($input['embedding'] ?? null)
            ? array_map(static fn($value) => (float) $value, $input['embedding'])
            : aiCopilotLabPdfBuildDeterministicEmbedding($chunkText),
        'metadata' => [
            'sourceType' => 'lab_pdf',
            'sourceLabel' => 'Uploaded lab PDF',
            'chunkIndex' => $chunkIndex,
            'uploadedAt' => (string) ($input['uploaded_at'] ?? gmdate('c')),
            'sourcePage' => $input['source_page'] ?? null,
            'role' => (string) ($input['role'] ?? 'Doctor'),
            'extractionMethod' => (string) ($input['extraction_method'] ?? 'pdf_text'),
            'requestId' => $requestId,
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
