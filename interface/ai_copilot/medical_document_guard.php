<?php

function aiCopilotMedicalGuardEnv(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if (!is_string($value)) {
        return $default;
    }

    $value = trim($value);
    return $value !== '' ? $value : $default;
}

function aiCopilotMedicalGuardTruthValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function aiCopilotMedicalGuardEnabled(): bool
{
    return aiCopilotMedicalGuardTruthValue(aiCopilotMedicalGuardEnv('AWS_MEDICAL_DOCUMENT_GUARD_ENABLED', 'false'));
}

function aiCopilotMedicalGuardMinEntities(): int
{
    $value = aiCopilotMedicalGuardEnv('AWS_COMPREHEND_MEDICAL_MIN_ENTITIES', '2');
    return max(1, is_numeric($value) ? (int) $value : 2);
}

function aiCopilotMedicalGuardMinScore(): float
{
    $value = aiCopilotMedicalGuardEnv('AWS_COMPREHEND_MEDICAL_MIN_SCORE', '0.70');
    $score = is_numeric($value) ? (float) $value : 0.70;
    return max(0.05, min($score, 0.99));
}

function aiCopilotMedicalGuardNormalizeText(string $value): string
{
    $value = str_replace("\r", "\n", $value);
    $value = str_replace("\0", ' ', $value);
    $value = preg_replace("/[ \t]+/", ' ', $value);
    $value = preg_replace("/\n{3,}/", "\n\n", (string) $value);
    return trim((string) $value);
}

function aiCopilotMedicalGuardPreview(string $text, int $limit = 220): string
{
    $normalized = aiCopilotMedicalGuardNormalizeText($text);
    if (function_exists('mb_substr')) {
        return mb_substr($normalized, 0, $limit);
    }

    return substr($normalized, 0, $limit);
}

function aiCopilotMedicalGuardAcceptedClasses(): array
{
    return [
        'lab_results',
        'intake_form',
        'discharge_summary',
        'medication_list',
        'insurance_claim',
        'clinical_note',
        'visit_summary',
    ];
}

function aiCopilotMedicalGuardRejectedClues(): array
{
    return [
        'invoice',
        'vendor',
        'payment terms',
        'resume',
        'job application',
        'event checklist',
        'restaurant menu',
        'book manuscript',
        'marketing flyer',
        'school assignment',
        'unrelated business document',
    ];
}

function aiCopilotMedicalGuardDetectDocumentType(string $fileName, string $text): string
{
    $normalizedFileName = (string) $fileName;
    $normalizedText = (string) $text;
    $hints = [
        'lab_results' => [
            '/\blab\b/i',
            '/\blabs\b/i',
            '/\bresult\b/i',
            '/\bdiagnostic\b/i',
            '/\ba1c\b/i',
            '/\bglucose\b/i',
            '/\bldl\b/i',
            '/\bhdl\b/i',
            '/\bcreatinine\b/i',
            '/\begfr\b/i',
            '/\bmg\/dL\b/i',
        ],
        'intake_form' => [
            '/\bintake\b/i',
            '/\bquestionnaire\b/i',
            '/\bform\b/i',
            '/\breason for visit\b/i',
            '/\bcurrent concerns\b/i',
            '/\bmedication adherence\b/i',
            '/\ballergies\b/i',
            '/\binsurance update\b/i',
            '/\bcare preferences\b/i',
        ],
        'discharge_summary' => [
            '/\bdischarge\b/i',
            '/\bhospital course\b/i',
            '/\bdischarge diagnosis\b/i',
        ],
        'medication_list' => [
            '/\bmedication list\b/i',
            '/\bcurrent medications\b/i',
            '/\bdosage\b/i',
        ],
        'insurance_claim' => [
            '/\binsurance\b/i',
            '/\bclaim\b/i',
            '/\bmember id\b/i',
            '/\bpolicy\b/i',
            '/\bpayer\b/i',
        ],
        'clinical_note' => [
            '/\bprogress note\b/i',
            '/\bclinical note\b/i',
            '/\bhpi\b/i',
            '/\bassessment\b/i',
            '/\bplan\b/i',
        ],
        'visit_summary' => [
            '/\bvisit summary\b/i',
            '/\bfollow-up\b/i',
            '/\bnext steps\b/i',
        ],
    ];

    foreach ($hints as $documentType => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedFileName) === 1 || preg_match($pattern, $normalizedText) === 1) {
                return $documentType;
            }
        }
    }

    return 'unknown';
}

function aiCopilotMedicalGuardRejectedMatches(string $fileName, string $text): array
{
    $haystack = strtolower(aiCopilotMedicalGuardNormalizeText($fileName . "\n" . $text));
    $matches = [];
    foreach (aiCopilotMedicalGuardRejectedClues() as $clue) {
        if (str_contains($haystack, strtolower($clue))) {
            $matches[] = $clue;
        }
    }

    return array_values(array_unique($matches));
}

function aiCopilotMedicalGuardLocalEntitySummary(string $text): array
{
    $hints = [
        'patient',
        'provider',
        'allergies',
        'medication',
        'medications',
        'diagnosis',
        'clinical',
        'visit',
        'encounter',
        'discharge',
        'assessment',
        'plan',
        'hemoglobin a1c',
        'a1c',
        'glucose',
        'ldl',
        'hdl',
        'creatinine',
        'egfr',
        'mg/dl',
        'member id',
        'claim',
        'payer',
        'insurance',
        'coverage',
        'reason for visit',
        'current concerns',
        'care preferences',
    ];

    $lower = strtolower(aiCopilotMedicalGuardNormalizeText($text));
    $matches = [];
    foreach ($hints as $hint) {
        if (str_contains($lower, strtolower($hint))) {
            $matches[] = $hint;
        }
    }

    $matches = array_values(array_unique($matches));
    $count = count($matches);
    $averageScore = $count > 0
        ? min(0.99, aiCopilotMedicalGuardMinScore() + (min($count, 6) * 0.03))
        : 0.0;

    return [
        'totalEntities' => $count,
        'highConfidenceEntityCount' => $count,
        'highConfidenceEntities' => array_map(static function (string $hint) use ($averageScore): array {
            return [
                'text' => $hint,
                'category' => 'MEDICAL_HINT',
                'score' => round($averageScore, 4),
            ];
        }, array_slice($matches, 0, 12)),
        'averageScore' => round($averageScore, 4),
        'minimumScore' => aiCopilotMedicalGuardMinScore(),
    ];
}

function aiCopilotMedicalGuardDecisionFromSignals(string $fileName, string $text, array $entitySummary): array
{
    $documentType = aiCopilotMedicalGuardDetectDocumentType($fileName, $text);
    $rejectedClues = aiCopilotMedicalGuardRejectedMatches($fileName, $text);
    $highConfidenceCount = (int) ($entitySummary['highConfidenceEntityCount'] ?? 0);
    $confidenceBase = max((float) ($entitySummary['averageScore'] ?? 0), $rejectedClues !== [] ? 0.25 : 0.45);
    $documentTypeRecognized = in_array($documentType, aiCopilotMedicalGuardAcceptedClasses(), true);
    $confidence = max(
        0.0,
        min(
            0.99,
            $confidenceBase
            + ($documentTypeRecognized ? 0.18 : 0.0)
            + (min($highConfidenceCount, 6) * 0.04)
            - ($rejectedClues !== [] ? 0.25 : 0.0)
        )
    );
    $confidence = round($confidence, 4);

    $detectedEntitySummary = array_merge($entitySummary, [
        'rejectedClues' => $rejectedClues,
        'medicalEntityCount' => $highConfidenceCount,
    ]);

    if ($text === '') {
        return [
            'decision' => 'review_required',
            'documentType' => $documentTypeRecognized ? $documentType : 'unknown',
            'confidence' => 0.0,
            'extractedTextPreview' => '',
            'detectedEntitySummary' => $detectedEntitySummary,
            'rejectionReason' => 'No reliable text was available for medical-document validation.',
        ];
    }

    if ($rejectedClues !== [] && count($rejectedClues) >= 2 && $highConfidenceCount < aiCopilotMedicalGuardMinEntities()) {
        return [
            'decision' => 'rejected',
            'documentType' => 'unknown',
            'confidence' => $confidence,
            'extractedTextPreview' => aiCopilotMedicalGuardPreview($text),
            'detectedEntitySummary' => $detectedEntitySummary,
            'rejectionReason' => 'The uploaded PDF appears to be a non-medical document based on business or unrelated document language.',
        ];
    }

    if ($documentTypeRecognized && $highConfidenceCount >= aiCopilotMedicalGuardMinEntities() && $confidence >= aiCopilotMedicalGuardMinScore()) {
        return [
            'decision' => 'allowed',
            'documentType' => $documentType,
            'confidence' => $confidence,
            'extractedTextPreview' => aiCopilotMedicalGuardPreview($text),
            'detectedEntitySummary' => $detectedEntitySummary,
        ];
    }

    if (!$documentTypeRecognized && $highConfidenceCount <= 0) {
        return [
            'decision' => 'rejected',
            'documentType' => 'unknown',
            'confidence' => $confidence,
            'extractedTextPreview' => aiCopilotMedicalGuardPreview($text),
            'detectedEntitySummary' => $detectedEntitySummary,
            'rejectionReason' => 'The uploaded PDF did not contain enough recognizable clinical or healthcare language to be ingested.',
        ];
    }

    return [
        'decision' => 'review_required',
        'documentType' => $documentTypeRecognized ? $documentType : 'unknown',
        'confidence' => $confidence,
        'extractedTextPreview' => aiCopilotMedicalGuardPreview($text),
        'detectedEntitySummary' => $detectedEntitySummary,
        'rejectionReason' => 'Document type could not be verified with high confidence.',
    ];
}

function aiCopilotMedicalGuardLog(string $eventName, array $payload = []): void
{
    $pairs = [];
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($value === null || $value === '') {
            continue;
        }

        $pairs[] = $key . '=' . str_replace('"', "'", aiCopilotMedicalGuardNormalizeText((string) $value));
    }

    error_log('[OpenEMR Clinical Co-Pilot] ' . $eventName . ' ' . implode(' ', $pairs));
}

function aiCopilotMedicalGuardFindNodeBinary(): string
{
    $candidates = [
        trim((string) aiCopilotMedicalGuardEnv('NODE_BINARY', '')),
        trim((string) @shell_exec('command -v node 2>/dev/null')),
        'node',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if ($candidate === 'node') {
            return $candidate;
        }

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function aiCopilotMedicalGuardRunAwsNode(array $input): array
{
    $nodeBinary = aiCopilotMedicalGuardFindNodeBinary();
    $scriptPath = __DIR__ . '/aws_medical_document_guard.js';
    if ($nodeBinary === '' || !is_file($scriptPath)) {
        return [
            'ok' => false,
            'error' => 'aws_guard_runtime_unavailable',
        ];
    }

    $inputPath = tempnam(sys_get_temp_dir(), 'copilot_guard_');
    if ($inputPath === false) {
        return [
            'ok' => false,
            'error' => 'aws_guard_tempfile_unavailable',
        ];
    }

    file_put_contents($inputPath, json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $command = escapeshellarg($nodeBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($inputPath);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stdout = '';
    $stderr = '';
    $exitCode = 1;
    $process = @proc_open($command, $descriptors, $pipes, __DIR__);
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    }

    @unlink($inputPath);

    if ($exitCode !== 0) {
        return [
            'ok' => false,
            'error' => 'aws_guard_process_failed',
            'stderr' => aiCopilotMedicalGuardNormalizeText($stderr),
        ];
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'aws_guard_invalid_json',
            'stderr' => aiCopilotMedicalGuardNormalizeText($stderr),
        ];
    }

    return $decoded;
}

function aiCopilotMedicalGuardLocalFallback(array $input): array
{
    $text = aiCopilotMedicalGuardNormalizeText((string) ($input['text'] ?? $input['fallback_text'] ?? ''));
    $entitySummary = aiCopilotMedicalGuardLocalEntitySummary($text);
    $decision = aiCopilotMedicalGuardDecisionFromSignals((string) ($input['file_name'] ?? ''), $text, $entitySummary);

    return array_merge($decision, [
        'guardProvider' => 'local_validation_fallback',
        'extractionMethod' => 'local_text_heuristics',
        'textractStatus' => 'not_run',
        'comprehendStatus' => 'not_run',
        'extractedText' => $text,
        'awsGuardEnabled' => false,
        'auditEvents' => [
            'copilot_document_guard_started',
            $decision['decision'] === 'allowed'
                ? 'copilot_document_guard_allowed'
                : ($decision['decision'] === 'rejected' ? 'copilot_document_guard_rejected' : 'copilot_document_guard_review_required'),
        ],
    ]);
}

function aiCopilotValidateMedicalDocumentGuard(array $input): array
{
    $fileName = (string) ($input['file_name'] ?? 'attached-document.pdf');
    $mimeType = strtolower((string) ($input['mime_type'] ?? 'application/pdf'));
    $requestId = (string) ($input['request_id'] ?? 'request');
    $patientKey = (string) ($input['patient_key'] ?? '');
    $role = (string) ($input['role'] ?? 'doctor');

    aiCopilotMedicalGuardLog('copilot_document_guard_started', [
        'request_id' => $requestId,
        'role' => $role,
        'patient_key' => $patientKey,
        'file_name' => basename($fileName),
        'aws_guard_enabled' => aiCopilotMedicalGuardEnabled() ? 'true' : 'false',
    ]);

    if ($mimeType !== 'application/pdf' && preg_match('/\.pdf$/i', $fileName) !== 1) {
        $result = [
            'decision' => 'rejected',
            'documentType' => 'unknown',
            'confidence' => 0.0,
            'extractedTextPreview' => '',
            'detectedEntitySummary' => [
                'totalEntities' => 0,
                'highConfidenceEntityCount' => 0,
                'highConfidenceEntities' => [],
                'categoryCounts' => [],
                'averageScore' => 0.0,
                'minimumScore' => aiCopilotMedicalGuardMinScore(),
                'rejectedClues' => [],
                'medicalEntityCount' => 0,
            ],
            'rejectionReason' => 'Only PDF uploads are supported by the medical document validation gate.',
            'guardProvider' => 'mime_validation',
            'extractionMethod' => 'not_run',
            'textractStatus' => 'not_run',
            'comprehendStatus' => 'not_run',
            'extractedText' => '',
            'awsGuardEnabled' => aiCopilotMedicalGuardEnabled(),
            'auditEvents' => [
                'copilot_document_guard_rejected',
                'copilot_vectorization_blocked',
            ],
        ];
        aiCopilotMedicalGuardLog('copilot_document_guard_rejected', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'reason' => $result['rejectionReason'],
        ]);
        return $result;
    }

    if (!aiCopilotMedicalGuardEnabled()) {
        $localResult = aiCopilotMedicalGuardLocalFallback($input);
        aiCopilotMedicalGuardLog(
            $localResult['decision'] === 'allowed'
                ? 'copilot_document_guard_allowed'
                : ($localResult['decision'] === 'rejected' ? 'copilot_document_guard_rejected' : 'copilot_document_guard_review_required'),
            [
                'request_id' => $requestId,
                'file_name' => basename($fileName),
                'document_type' => $localResult['documentType'] ?? 'unknown',
                'confidence' => $localResult['confidence'] ?? 0,
                'provider' => $localResult['guardProvider'] ?? 'local_validation_fallback',
            ]
        );
        if (($localResult['decision'] ?? '') !== 'allowed') {
            aiCopilotMedicalGuardLog('copilot_vectorization_blocked', [
                'request_id' => $requestId,
                'file_name' => basename($fileName),
                'decision' => $localResult['decision'] ?? 'review_required',
            ]);
        }
        return $localResult;
    }

    aiCopilotMedicalGuardLog('copilot_textract_started', [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
    ]);

    $awsResult = aiCopilotMedicalGuardRunAwsNode([
        'mode' => 'aws',
        'requestId' => $requestId,
        'filePath' => (string) ($input['tmp_name'] ?? ''),
        'fileName' => basename($fileName),
        'mimeType' => $mimeType,
        'patientKey' => $patientKey,
        'patientName' => (string) ($input['patient_name'] ?? ''),
        'role' => $role,
        'minimumEntities' => aiCopilotMedicalGuardMinEntities(),
        'fallbackText' => (string) ($input['fallback_text'] ?? ''),
        'extractedText' => (string) ($input['text'] ?? ''),
    ]);

    if (!is_array($awsResult) || empty($awsResult)) {
        $awsResult = [
            'ok' => false,
            'error' => 'aws_guard_unknown_failure',
        ];
    }

    if (($awsResult['ok'] ?? true) === false) {
        aiCopilotMedicalGuardLog('copilot_textract_failed', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'error' => (string) ($awsResult['error'] ?? 'unknown_failure'),
            'stderr' => (string) ($awsResult['stderr'] ?? ''),
        ]);
        aiCopilotMedicalGuardLog('copilot_document_guard_review_required', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'reason' => 'AWS medical document validation was unavailable. Review required before ingestion.',
        ]);
        aiCopilotMedicalGuardLog('copilot_vectorization_blocked', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'decision' => 'review_required',
        ]);

        return [
            'decision' => 'review_required',
            'documentType' => 'unknown',
            'confidence' => 0.0,
            'extractedTextPreview' => '',
            'detectedEntitySummary' => [
                'totalEntities' => 0,
                'highConfidenceEntityCount' => 0,
                'highConfidenceEntities' => [],
                'categoryCounts' => [],
                'averageScore' => 0.0,
                'minimumScore' => aiCopilotMedicalGuardMinScore(),
                'rejectedClues' => [],
                'medicalEntityCount' => 0,
            ],
            'rejectionReason' => 'AWS medical document validation was unavailable. Review required before ingestion.',
            'guardProvider' => 'aws_textract_comprehend_medical',
            'extractionMethod' => 'aws_guard_failure',
            'textractStatus' => 'failed',
            'comprehendStatus' => 'not_run',
            'extractedText' => '',
            'awsGuardEnabled' => true,
            'auditEvents' => [
                'copilot_document_guard_started',
                'copilot_textract_started',
                'copilot_textract_failed',
                'copilot_document_guard_review_required',
                'copilot_vectorization_blocked',
            ],
        ];
    }

    $textractStatus = (string) ($awsResult['textractStatus'] ?? 'not_run');
    $comprehendStatus = (string) ($awsResult['comprehendStatus'] ?? 'not_run');
    aiCopilotMedicalGuardLog(
        $textractStatus === 'succeeded' ? 'copilot_textract_succeeded' : 'copilot_textract_failed',
        [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'provider' => (string) ($awsResult['guardProvider'] ?? 'aws_textract_comprehend_medical'),
        ]
    );
    if ($comprehendStatus === 'started' || $comprehendStatus === 'succeeded') {
        aiCopilotMedicalGuardLog('copilot_comprehend_medical_started', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
        ]);
    }
    if ($comprehendStatus === 'succeeded') {
        aiCopilotMedicalGuardLog('copilot_comprehend_medical_succeeded', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'medical_entity_count' => (int) ($awsResult['detectedEntitySummary']['medicalEntityCount'] ?? $awsResult['detectedEntitySummary']['highConfidenceEntityCount'] ?? 0),
        ]);
    }

    $decision = (string) ($awsResult['decision'] ?? 'review_required');
    aiCopilotMedicalGuardLog(
        $decision === 'allowed'
            ? 'copilot_document_guard_allowed'
            : ($decision === 'rejected' ? 'copilot_document_guard_rejected' : 'copilot_document_guard_review_required'),
        [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'document_type' => (string) ($awsResult['documentType'] ?? 'unknown'),
            'confidence' => (string) ($awsResult['confidence'] ?? 0),
            'provider' => (string) ($awsResult['guardProvider'] ?? 'aws_textract_comprehend_medical'),
        ]
    );
    if ($decision !== 'allowed') {
        aiCopilotMedicalGuardLog('copilot_vectorization_blocked', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'decision' => $decision,
        ]);
    }

    return [
        'decision' => $decision,
        'documentType' => (string) ($awsResult['documentType'] ?? 'unknown'),
        'confidence' => isset($awsResult['confidence']) && is_numeric($awsResult['confidence']) ? (float) $awsResult['confidence'] : 0.0,
        'extractedTextPreview' => aiCopilotMedicalGuardNormalizeText((string) ($awsResult['extractedTextPreview'] ?? '')),
        'detectedEntitySummary' => is_array($awsResult['detectedEntitySummary'] ?? null) ? $awsResult['detectedEntitySummary'] : [],
        'rejectionReason' => aiCopilotMedicalGuardNormalizeText((string) ($awsResult['rejectionReason'] ?? '')),
        'guardProvider' => aiCopilotMedicalGuardNormalizeText((string) ($awsResult['guardProvider'] ?? 'aws_textract_comprehend_medical')),
        'extractionMethod' => aiCopilotMedicalGuardNormalizeText((string) ($awsResult['extractionMethod'] ?? 'aws_textract_comprehend_medical')),
        'textractStatus' => $textractStatus,
        'comprehendStatus' => $comprehendStatus,
        'extractedText' => aiCopilotMedicalGuardNormalizeText((string) ($awsResult['extractedText'] ?? '')),
        'awsGuardEnabled' => true,
        'auditEvents' => is_array($awsResult['auditEvents'] ?? null) ? $awsResult['auditEvents'] : [],
    ];
}
