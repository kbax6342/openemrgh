<?php

require_once(__DIR__ . '/lab_pdf_vector_store.php');

const AI_COPILOT_LAB_PDF_TOOL_NAME = 'attach_and_vectorize_lab_pdf';
const AI_COPILOT_LAB_PDF_REVIEW_NOTICE = 'This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original lab PDF.';
const AI_COPILOT_LAB_PDF_SEEDED_FILE_NAME = 'marcus-johnson-labs-may-2026.pdf';

function aiCopilotLabPdfNormalizeWhitespace(string $value): string
{
    $value = str_replace("\r", "\n", $value);
    $value = str_replace("\0", ' ', $value);
    $value = preg_replace("/[ \t]+/", ' ', $value);
    $value = preg_replace("/\n{3,}/", "\n\n", (string) $value);
    return trim((string) $value);
}

function aiCopilotLabPdfPreview(string $text, int $limit = 240): string
{
    $text = aiCopilotLabPdfNormalizeWhitespace($text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit);
    }

    return substr($text, 0, $limit);
}

function aiCopilotLabPdfSeededText(): string
{
    return implode("\n", [
        'Patient: Marcus Johnson',
        'Document: marcus-johnson-labs-may-2026.pdf',
        'Hemoglobin A1c: 8.2 %, high',
        'LDL Cholesterol: 142 mg/dL, high',
        'Creatinine: 1.1 mg/dL, normal',
        'eGFR: 82 mL/min/1.73m2, normal',
        'Missing:',
        '- Ordering provider not clearly detected',
        '- Collection time not clearly detected',
    ]);
}

function aiCopilotLabPdfSeededMissingData(): array
{
    return [
        'Ordering provider not clearly detected.',
        'Collection time not clearly detected.',
    ];
}

function aiCopilotLabPdfPromptInjectionMatches(string $text): array
{
    $patterns = [
        '/\bignore (all|any|previous|prior) instructions\b/i',
        '/\breveal (the )?(system prompt|hidden prompt|hidden notes)\b/i',
        '/\bwrite directly to the chart\b/i',
        '/\bdiagnose this patient\b/i',
        '/\boverride (guardrails|safety|policy)\b/i',
    ];

    $matches = [];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $matches[] = $pattern;
        }
    }

    return array_values(array_unique($matches));
}

function aiCopilotLabPdfLooksLikePdf(array $file): bool
{
    $fileName = strtolower((string) ($file['name'] ?? ''));
    $mimeType = strtolower((string) ($file['type'] ?? ''));
    return $mimeType === 'application/pdf' || preg_match('/\.pdf$/i', $fileName) === 1;
}

function aiCopilotLabPdfDecodeLiteralString(string $value): string
{
    $value = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t', '\\\\'], ['(', ')', "\n", "\n", "\t", '\\'], $value);
    return preg_replace_callback('/\\\\([0-7]{3})/', static function (array $match): string {
        return chr(octdec($match[1]));
    }, $value) ?: $value;
}

function aiCopilotLabPdfExtractTextFromBinary(string $binary): array
{
    $literalMatches = [];
    $arrayMatches = [];
    $collected = [];

    preg_match_all('/\((?:\\\\.|[^()])+\)\s*Tj/', $binary, $literalMatches);
    foreach (($literalMatches[0] ?? []) as $match) {
        $value = preg_replace('/\)\s*Tj$/', '', $match);
        $value = substr((string) $value, 1);
        $decoded = aiCopilotLabPdfDecodeLiteralString((string) $value);
        if ($decoded !== '') {
            $collected[] = $decoded;
        }
    }

    preg_match_all('/\[((?:\((?:\\\\.|[^()])+\)\s*)+)\]\s*TJ/', $binary, $arrayMatches);
    foreach (($arrayMatches[1] ?? []) as $innerMatch) {
        $parts = [];
        preg_match_all('/\((?:\\\\.|[^()])+\)/', (string) $innerMatch, $innerParts);
        foreach (($innerParts[0] ?? []) as $part) {
            $decoded = aiCopilotLabPdfDecodeLiteralString(substr((string) $part, 1, -1));
            if ($decoded !== '') {
                $parts[] = $decoded;
            }
        }

        if ($parts !== []) {
            $collected[] = implode(' ', $parts);
        }
    }

    if ($collected === []) {
        $printableMatches = [];
        preg_match_all('/[A-Za-z0-9%\/\.,:_ \-\n]{6,}/', $binary, $printableMatches);
        $collected[] = implode("\n", $printableMatches[0] ?? []);
    }

    $text = aiCopilotLabPdfNormalizeWhitespace(implode("\n", $collected));
    $pageMatches = [];
    preg_match_all('/\/Type\s*\/Page\b/', $binary, $pageMatches);

    return [
        'text' => $text,
        'page_count' => count($pageMatches[0] ?? []),
        'extraction_method' => 'pdf_text',
    ];
}

function aiCopilotLabPdfChunkSourcePage(string $text): ?int
{
    if (preg_match('/\bpage\s+(\d+)\b/i', $text, $matches) === 1) {
        return (int) $matches[1];
    }

    return null;
}

function aiCopilotLabPdfChunkText(string $text, int $chunkSize = 360, int $overlap = 70): array
{
    $text = aiCopilotLabPdfNormalizeWhitespace($text);
    if ($text === '') {
        return [];
    }

    $chunkSize = max(140, $chunkSize);
    $overlap = max(20, $overlap);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn($item) => $item !== ''));
    $chunks = [];
    $current = '';
    $chunkIndex = 0;

    foreach ($lines as $line) {
        $candidate = $current !== '' ? $current . "\n" . $line : $line;
        if ($candidate === '' || strlen($candidate) <= $chunkSize || $current === '') {
            $current = $candidate;
            continue;
        }

        $chunks[] = [
            'chunk_index' => $chunkIndex,
            'chunk_text' => $current,
            'source_page' => aiCopilotLabPdfChunkSourcePage($current),
        ];
        $chunkIndex++;
        $current = trim(substr($current, max(0, strlen($current) - $overlap)) . "\n" . $line);
    }

    if ($current !== '') {
        $chunks[] = [
            'chunk_index' => $chunkIndex,
            'chunk_text' => $current,
            'source_page' => aiCopilotLabPdfChunkSourcePage($current),
        ];
    }

    return $chunks;
}

function aiCopilotLabPdfExtractFacts(string $text): array
{
    $facts = [];
    $abnormal = [];
    $missing = [];
    $lines = explode("\n", aiCopilotLabPdfNormalizeWhitespace($text));

    foreach ($lines as $line) {
        $line = aiCopilotLabPdfNormalizeWhitespace($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^missing:?$/i', $line) === 1 || preg_match('/^-\s+/', $line) === 1) {
            $missing[] = preg_replace('/^-\s*/', '', $line);
            continue;
        }

        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $rawValue] = array_pad(explode(':', $line, 2), 2, '');
        $name = aiCopilotLabPdfNormalizeWhitespace($name);
        $rawValue = aiCopilotLabPdfNormalizeWhitespace($rawValue);
        if ($name === '' || $rawValue === '') {
            continue;
        }

        $value = $rawValue;
        $interpretation = '';
        if (preg_match('/^(.*?)(?:,\s*|\s+-\s*)(high|low|normal|abnormal|critical|attention needed)$/i', $rawValue, $matches) === 1) {
            $value = aiCopilotLabPdfNormalizeWhitespace($matches[1]);
            $interpretation = strtolower(aiCopilotLabPdfNormalizeWhitespace($matches[2]));
        } elseif (preg_match('/\b(high|low|normal|abnormal|critical|attention needed)\b/i', $rawValue, $matches) === 1) {
            $interpretation = strtolower(aiCopilotLabPdfNormalizeWhitespace($matches[1]));
        }

        $fact = [
            'name' => $name,
            'value' => $value,
            'interpretation' => $interpretation,
            'source_label' => 'Uploaded Lab PDF',
        ];
        $facts[] = $fact;
        if (preg_match('/\b(high|low|abnormal|critical|attention needed)\b/i', $interpretation !== '' ? $interpretation : $rawValue) === 1) {
            $abnormal[] = $name . ': ' . $rawValue;
        }
    }

    return [
        'facts' => $facts,
        'abnormal' => array_values(array_unique($abnormal)),
        'missing' => array_values(array_unique(array_filter(array_map('trim', $missing), static fn($item) => $item !== ''))),
    ];
}

function aiCopilotLabPdfBuildSafetyMetadata(array $options = []): array
{
    $matches = is_array($options['prompt_injection_matches'] ?? null) ? $options['prompt_injection_matches'] : [];
    return [
        'draft_only' => true,
        'review_required' => true,
        'prompt_injection_detected' => $matches !== [],
        'prompt_injection_matches' => $matches,
        'untrusted_document_text' => true,
        'no_chart_write' => true,
        'ocr_required' => !empty($options['ocr_required']),
    ];
}

function aiCopilotLabPdfBuildVectorizedResult(array $items, int $limit = 6): array
{
    $result = [];
    foreach (array_slice($items, 0, $limit) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $embedding = is_array($item['embedding'] ?? null)
            ? array_values(array_map(static fn($value) => (float) $value, $item['embedding']))
            : [];
        $chunkText = (string) ($item['chunkText'] ?? $item['chunk_text'] ?? '');
        $chunkIndex = $metadata['chunkIndex'] ?? $item['chunk_index'] ?? null;
        $sourcePage = $metadata['sourcePage'] ?? $item['source_page'] ?? null;

        $result[] = [
            'id' => (string) ($item['id'] ?? ''),
            'file_name' => (string) ($item['fileName'] ?? $item['file_name'] ?? ''),
            'chunk_index' => is_numeric($chunkIndex) ? (int) $chunkIndex : null,
            'source_page' => is_numeric($sourcePage) ? (int) $sourcePage : null,
            'text_preview' => aiCopilotLabPdfPreview($chunkText, 160),
            'embedding' => $embedding,
            'score' => isset($item['score']) && is_numeric($item['score']) ? round((float) $item['score'], 6) : null,
        ];
    }

    return $result;
}

function aiCopilotLabPdfBuildClientToolOutput(array $input): array
{
    $toolOutput = [
        'tool' => AI_COPILOT_LAB_PDF_TOOL_NAME,
        'status' => (string) ($input['status'] ?? 'ok'),
        'ingestion_status' => (string) ($input['ingestion_status'] ?? 'ingested'),
        'safe_message' => (string) ($input['safe_message'] ?? ''),
        'extraction_method' => (string) ($input['extraction_method'] ?? 'pdf_text'),
        'extracted_text_preview' => (string) ($input['extracted_text_preview'] ?? ''),
        'number_of_chunks' => isset($input['number_of_chunks']) && is_numeric($input['number_of_chunks']) ? (int) $input['number_of_chunks'] : 0,
        'document_metadata' => is_array($input['document_metadata'] ?? null) ? $input['document_metadata'] : [],
        'source_metadata' => is_array($input['source_metadata'] ?? null) ? $input['source_metadata'] : [],
        'extracted_facts' => is_array($input['extracted_facts'] ?? null) ? $input['extracted_facts'] : [],
        'abnormal_findings' => is_array($input['abnormal_findings'] ?? null) ? $input['abnormal_findings'] : [],
        'missing_data' => is_array($input['missing_data'] ?? null) ? $input['missing_data'] : [],
        'missing_data_flags' => is_array($input['missing_data_flags'] ?? null) ? $input['missing_data_flags'] : [],
        'retrieval' => is_array($input['retrieval'] ?? null) ? $input['retrieval'] : [],
        'vectorized_result' => is_array($input['vectorized_result'] ?? null) ? $input['vectorized_result'] : [],
        'safety' => [
            'draft_only' => true,
            'review_required' => true,
        ],
        'safety_metadata' => aiCopilotLabPdfBuildSafetyMetadata([
            'prompt_injection_matches' => $input['prompt_injection_matches'] ?? [],
            'ocr_required' => !empty($input['ocr_required']),
        ]),
    ];

    return $toolOutput;
}

function aiCopilotLabPdfRetrieveRelevantChunks(array $options): array
{
    $patientKey = (string) ($options['patient_key'] ?? '');
    $prompt = (string) ($options['prompt'] ?? '');
    $fileName = (string) ($options['file_name'] ?? '');
    $limit = isset($options['limit']) && is_numeric($options['limit']) ? max(1, (int) $options['limit']) : 4;
    if ($patientKey === '') {
        return [
            'chunks' => [],
            'chunk_ids' => [],
            'chunk_count' => 0,
        ];
    }

    $ranked = aiCopilotLabPdfQueryVectorStore($prompt, [
        'patient_key' => $patientKey,
        'file_name' => $fileName,
        'limit' => $limit,
    ]);

    $chunks = [];
    foreach ($ranked as $record) {
        if (!is_array($record)) {
            continue;
        }

        $chunks[] = [
            'id' => (string) ($record['id'] ?? ''),
            'chunk_text' => (string) ($record['chunkText'] ?? ''),
            'file_name' => (string) ($record['fileName'] ?? ''),
            'score' => round((float) ($record['score'] ?? 0), 6),
            'source_page' => $record['metadata']['sourcePage'] ?? null,
            'uploaded_at' => (string) ($record['metadata']['uploadedAt'] ?? ''),
            'extraction_method' => (string) ($record['metadata']['extractionMethod'] ?? 'pdf_text'),
            'chunk_index' => isset($record['metadata']['chunkIndex']) && is_numeric($record['metadata']['chunkIndex']) ? (int) $record['metadata']['chunkIndex'] : null,
            'embedding' => is_array($record['embedding'] ?? null)
                ? array_values(array_map(static fn($value) => (float) $value, $record['embedding']))
                : [],
        ];
    }

    return [
        'chunks' => $chunks,
        'chunk_ids' => array_values(array_filter(array_map(static fn($item) => (string) ($item['id'] ?? ''), $chunks), static fn($item) => $item !== '')),
        'chunk_count' => count($chunks),
    ];
}

function aiCopilotLabPdfPromptRequestsRetrieval(string $prompt, string $mode): bool
{
    if ($mode === 'lab_pdf_ingestion') {
        return true;
    }

    return preg_match('/\b(lab|labs|lab report|pdf|a1c|ldl|creatinine|egfr|abnormal|collection time|ordering provider|source|missing data|uncertain|summarize this lab report)\b/i', $prompt) === 1;
}

function aiCopilotLabPdfBuildToolOutputFromRetrieval(array $retrieval, array $options = []): array
{
    $combinedText = implode("\n", array_values(array_filter(array_map(static fn($chunk) => is_array($chunk) ? (string) ($chunk['chunk_text'] ?? '') : '', $retrieval['chunks'] ?? []))));
    $factSummary = aiCopilotLabPdfExtractFacts($combinedText);
    $firstChunk = is_array($retrieval['chunks'][0] ?? null) ? $retrieval['chunks'][0] : [];
    $documentTitle = (string) ($options['file_name'] ?? ($firstChunk['file_name'] ?? 'Uploaded lab PDF'));
    $uploadedAt = (string) ($options['uploaded_at'] ?? ($firstChunk['uploaded_at'] ?? ''));

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => 'retrieved',
        'ingestion_status' => 'retrieved',
        'safe_message' => 'Retrieved previously ingested lab PDF context for clinician review.',
        'extraction_method' => (string) ($firstChunk['extraction_method'] ?? 'pdf_text'),
        'extracted_text_preview' => aiCopilotLabPdfPreview($combinedText),
        'number_of_chunks' => isset($options['chunk_count']) && is_numeric($options['chunk_count']) ? (int) $options['chunk_count'] : count($retrieval['chunks'] ?? []),
        'document_metadata' => [
            'title' => $documentTitle,
            'mime_type' => 'application/pdf',
            'size' => null,
            'seeded_demo' => !empty($options['seeded_demo']),
            'patient_key' => (string) ($options['patient_key'] ?? ''),
            'patient_name' => (string) ($options['patient_name'] ?? ''),
            'uploaded_at' => $uploadedAt,
        ],
        'source_metadata' => [
            'file_name' => $documentTitle,
            'uploaded_at' => $uploadedAt,
            'source_type' => 'lab_pdf',
            'source_label' => 'Uploaded lab PDF',
            'chunk_count' => count($retrieval['chunks'] ?? []),
            'request_id' => (string) ($options['request_id'] ?? ''),
        ],
        'extracted_facts' => array_slice($factSummary['facts'], 0, 12),
        'abnormal_findings' => array_slice($factSummary['abnormal'], 0, 8),
        'missing_data' => array_values(array_unique($factSummary['missing'])),
        'missing_data_flags' => array_values(array_unique($factSummary['missing'])),
        'retrieval' => [
            'chunk_ids' => $retrieval['chunk_ids'] ?? [],
            'chunk_count' => $retrieval['chunk_count'] ?? 0,
            'chunks' => $retrieval['chunks'] ?? [],
        ],
        'vectorized_result' => aiCopilotLabPdfBuildVectorizedResult($retrieval['chunks'] ?? []),
    ]);
}

function attach_and_vectorize_lab_pdf(array $options): array
{
    $role = strtolower(trim((string) ($options['role'] ?? 'doctor')));
    $requestId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($options['request_id'] ?? 'request')) ?: 'request';
    $patientKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) ($options['patient_key'] ?? '')) ?: '';
    $patientName = trim((string) ($options['patient_name'] ?? ''));
    $prompt = trim((string) ($options['prompt'] ?? ''));
    $useSeededDemo = !empty($options['use_seeded_demo']) || !empty($options['useSeededDemo']);
    $file = is_array($options['file'] ?? null) ? $options['file'] : null;
    $uploadedAt = gmdate('c');
    $fileName = $useSeededDemo ? AI_COPILOT_LAB_PDF_SEEDED_FILE_NAME : (string) ($file['name'] ?? 'attached-lab-report.pdf');
    $mimeType = $useSeededDemo ? 'application/pdf' : (string) ($file['type'] ?? 'application/pdf');
    $fileSize = $useSeededDemo ? null : (isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null);

    if ($role !== 'doctor') {
        return aiCopilotLabPdfBuildClientToolOutput([
            'status' => 'role_blocked',
            'ingestion_status' => 'blocked',
            'safe_message' => 'Lab PDF ingestion is restricted to the Doctor role in this demo workflow.',
            'extraction_method' => 'not_run',
            'document_metadata' => [
                'title' => $fileName,
                'mime_type' => 'application/pdf',
                'size' => $fileSize,
                'seeded_demo' => $useSeededDemo,
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'uploaded_at' => $uploadedAt,
            ],
            'source_metadata' => [
                'file_name' => $fileName,
                'uploaded_at' => $uploadedAt,
                'source_type' => 'lab_pdf',
                'source_label' => 'Uploaded lab PDF',
                'chunk_count' => 0,
                'request_id' => $requestId,
            ],
            'missing_data' => ['Lab PDF ingestion requires a clinician review role.'],
            'missing_data_flags' => ['Lab PDF ingestion requires a clinician review role.'],
        ]);
    }

    if (!$useSeededDemo) {
        if (!$file || !aiCopilotLabPdfLooksLikePdf($file)) {
            return aiCopilotLabPdfBuildClientToolOutput([
                'status' => 'invalid_file_type',
                'ingestion_status' => 'rejected',
                'safe_message' => 'Please attach a PDF file for the lab-ingestion workflow.',
                'extraction_method' => 'not_run',
                'document_metadata' => [
                    'title' => $fileName,
                    'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                    'size' => $fileSize,
                    'seeded_demo' => false,
                    'patient_key' => $patientKey,
                    'patient_name' => $patientName,
                    'uploaded_at' => $uploadedAt,
                ],
                'source_metadata' => [
                    'file_name' => $fileName,
                    'uploaded_at' => $uploadedAt,
                    'source_type' => 'lab_pdf',
                    'source_label' => 'Uploaded lab PDF',
                    'chunk_count' => 0,
                    'request_id' => $requestId,
                ],
                'missing_data' => ['The uploaded file was not a PDF.'],
                'missing_data_flags' => ['The uploaded file was not a PDF.'],
            ]);
        }
    }

    $text = '';
    $extractionMethod = $useSeededDemo ? 'seeded_demo_fallback' : 'pdf_text';
    $missingData = [];
    $promptInjectionMatches = [];

    if ($useSeededDemo) {
        $text = aiCopilotLabPdfSeededText();
        $missingData = aiCopilotLabPdfSeededMissingData();
        $promptInjectionMatches = aiCopilotLabPdfPromptInjectionMatches($text);
    } else {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $binary = ($tmpName !== '' && is_file($tmpName)) ? file_get_contents($tmpName) : false;
        if (!is_string($binary) || $binary === '') {
            return aiCopilotLabPdfBuildClientToolOutput([
                'status' => 'ocr_required',
                'ingestion_status' => 'ocr_required',
                'safe_message' => 'The PDF was attached, but text extraction did not succeed. OCR or manual review of the original PDF is required.',
                'extraction_method' => 'ocr_needed',
                'document_metadata' => [
                    'title' => $fileName,
                    'mime_type' => 'application/pdf',
                    'size' => $fileSize,
                    'seeded_demo' => false,
                    'patient_key' => $patientKey,
                    'patient_name' => $patientName,
                    'uploaded_at' => $uploadedAt,
                ],
                'source_metadata' => [
                    'file_name' => $fileName,
                    'uploaded_at' => $uploadedAt,
                    'source_type' => 'lab_pdf',
                    'source_label' => 'Uploaded lab PDF',
                    'chunk_count' => 0,
                    'request_id' => $requestId,
                ],
                'missing_data' => ['Text extraction failed. OCR or manual verification of the original PDF is required.'],
                'missing_data_flags' => ['Text extraction failed. OCR or manual verification of the original PDF is required.'],
                'ocr_required' => true,
            ]);
        }

        $extracted = aiCopilotLabPdfExtractTextFromBinary($binary);
        $text = (string) ($extracted['text'] ?? '');
        $extractionMethod = (string) ($extracted['extraction_method'] ?? 'pdf_text');
        if ($text === '' || strlen($text) < 24) {
            return aiCopilotLabPdfBuildClientToolOutput([
                'status' => 'ocr_required',
                'ingestion_status' => 'ocr_required',
                'safe_message' => 'The PDF was attached, but it appears to be scanned or text-light. OCR or manual review of the original PDF is required.',
                'extraction_method' => 'ocr_needed',
                'document_metadata' => [
                    'title' => $fileName,
                    'mime_type' => 'application/pdf',
                    'size' => $fileSize,
                    'seeded_demo' => false,
                    'patient_key' => $patientKey,
                    'patient_name' => $patientName,
                    'uploaded_at' => $uploadedAt,
                ],
                'source_metadata' => [
                    'file_name' => $fileName,
                    'uploaded_at' => $uploadedAt,
                    'source_type' => 'lab_pdf',
                    'source_label' => 'Uploaded lab PDF',
                    'chunk_count' => 0,
                    'request_id' => $requestId,
                ],
                'missing_data' => ['Extracted PDF text was insufficient. OCR or manual verification of the original PDF is required.'],
                'missing_data_flags' => ['Extracted PDF text was insufficient. OCR or manual verification of the original PDF is required.'],
                'ocr_required' => true,
            ]);
        }

        $promptInjectionMatches = aiCopilotLabPdfPromptInjectionMatches($text);
    }

    if ($promptInjectionMatches !== []) {
        $missingData[] = 'Instruction-like text was detected in the uploaded PDF and treated as untrusted document content rather than instructions.';
    }

    $chunks = aiCopilotLabPdfChunkText($text);
    $records = [];
    foreach ($chunks as $chunk) {
        $records[] = aiCopilotLabPdfCreateVectorRecord([
            'request_id' => $requestId,
            'patient_key' => $patientKey,
            'patient_display_name' => $patientName,
            'file_name' => $fileName,
            'chunk_text' => (string) ($chunk['chunk_text'] ?? ''),
            'chunk_index' => $chunk['chunk_index'] ?? 0,
            'source_page' => $chunk['source_page'] ?? null,
            'uploaded_at' => $uploadedAt,
            'role' => ucfirst($role),
            'extraction_method' => $extractionMethod,
        ]);
    }
    aiCopilotLabPdfUpsertVectorRecords($records);

    $factSummary = aiCopilotLabPdfExtractFacts($text);
    $missingData = array_values(array_unique(array_merge($missingData, $factSummary['missing'])));
    $retrieval = aiCopilotLabPdfRetrieveRelevantChunks([
        'patient_key' => $patientKey,
        'prompt' => $prompt !== '' ? $prompt : $text,
        'file_name' => $fileName,
        'limit' => 4,
    ]);

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => $useSeededDemo ? 'seeded_demo_fallback' : 'ok',
        'ingestion_status' => 'ingested',
        'safe_message' => $useSeededDemo
            ? 'Using the seeded Marcus Johnson demo lab PDF fallback through the same ingestion and retrieval pipeline.'
            : 'Lab PDF ingested and vectorized for draft-only clinician review.',
        'extraction_method' => $extractionMethod,
        'extracted_text_preview' => aiCopilotLabPdfPreview($text),
        'number_of_chunks' => count($chunks),
        'document_metadata' => [
            'title' => $fileName,
            'mime_type' => 'application/pdf',
            'size' => $fileSize,
            'seeded_demo' => $useSeededDemo,
            'patient_key' => $patientKey,
            'patient_name' => $patientName,
            'uploaded_at' => $uploadedAt,
        ],
        'source_metadata' => [
            'file_name' => $fileName,
            'uploaded_at' => $uploadedAt,
            'source_type' => 'lab_pdf',
            'source_label' => 'Uploaded lab PDF',
            'chunk_count' => count($chunks),
            'request_id' => $requestId,
        ],
        'extracted_facts' => array_slice($factSummary['facts'], 0, 12),
        'abnormal_findings' => array_slice($factSummary['abnormal'], 0, 8),
        'missing_data' => $missingData,
        'missing_data_flags' => $missingData,
        'retrieval' => [
            'chunk_ids' => $retrieval['chunk_ids'] ?? [],
            'chunk_count' => $retrieval['chunk_count'] ?? 0,
            'chunks' => $retrieval['chunks'] ?? [],
        ],
        'vectorized_result' => aiCopilotLabPdfBuildVectorizedResult($records),
        'prompt_injection_matches' => $promptInjectionMatches,
    ]);
}

function aiCopilotAttachRetrievedLabPdfContext(array $context, string $prompt, string $role, string $mode, string $requestId = ''): array
{
    if (empty($context['patient']['pubpid']) || !aiCopilotLabPdfPromptRequestsRetrieval($prompt, $mode)) {
        return $context;
    }

    $existingToolOutput = is_array($context['attached_lab_pdf_tool_output'] ?? null) ? $context['attached_lab_pdf_tool_output'] : [];
    $existingStatus = (string) ($existingToolOutput['status'] ?? '');
    if (in_array($existingStatus, ['invalid_file_type', 'ocr_required', 'role_blocked'], true)) {
        return $context;
    }

    $patientKey = (string) ($context['patient']['pubpid'] ?? '');
    $retrieval = aiCopilotLabPdfRetrieveRelevantChunks([
        'patient_key' => $patientKey,
        'prompt' => $prompt,
        'limit' => 4,
    ]);
    if (($retrieval['chunk_count'] ?? 0) <= 0) {
        return $context;
    }

    $context['retrieved_lab_pdf_context'] = $retrieval;
    $toolOutput = $existingToolOutput;
    if ($toolOutput === []) {
        $toolOutput = aiCopilotLabPdfBuildToolOutputFromRetrieval($retrieval, [
            'patient_key' => $patientKey,
            'patient_name' => (string) ($context['patient']['name'] ?? ''),
            'request_id' => $requestId,
        ]);
    } else {
        $toolOutput['retrieval'] = [
            'chunk_ids' => $retrieval['chunk_ids'] ?? [],
            'chunk_count' => $retrieval['chunk_count'] ?? 0,
            'chunks' => $retrieval['chunks'] ?? [],
        ];
        if (!isset($toolOutput['source_metadata']['chunk_count'])) {
            $toolOutput['source_metadata']['chunk_count'] = count($retrieval['chunks'] ?? []);
        }
    }

    $context['attached_lab_pdf_tool_output'] = $toolOutput;
    return $context;
}
