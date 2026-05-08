<?php

require_once(__DIR__ . '/lab_pdf_vector_store.php');
require_once(__DIR__ . '/medical_document_guard.php');
require_once(__DIR__ . '/api/document_ingestion_store.php');

const AI_COPILOT_LAB_PDF_TOOL_NAME = 'attach_and_vectorize_lab_pdf';
const AI_COPILOT_LAB_PDF_REVIEW_NOTICE = 'This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original lab PDF.';
const AI_COPILOT_LAB_PDF_SEEDED_FILE_NAME = 'marcus-johnson-labs-may-2026.pdf';
const AI_COPILOT_LAB_PDF_MVP_SYNTHETIC_FILE_NAME = 'marcus_johnson_synthetic_lab_results.pdf';
const AI_COPILOT_INTAKE_FORM_SEEDED_FILE_NAME = 'marcus-johnson-intake-form.pdf';
const AI_COPILOT_INTAKE_FORM_REVIEW_NOTICE = 'This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original intake form.';

function aiCopilotMedicalGuardWorkflowSourceType(string $documentType): string
{
    return match (strtolower(trim($documentType))) {
        'lab_results' => 'lab_pdf',
        'intake_form' => 'intake_form',
        'discharge_summary', 'medication_list', 'insurance_claim', 'clinical_note', 'visit_summary' => 'medical_document',
        default => 'unknown',
    };
}

function aiCopilotMedicalGuardReviewMessage(string $decision, string $documentType, string $rejectionReason = ''): string
{
    $rejectionReason = aiCopilotLabPdfNormalizeWhitespace($rejectionReason);
    if ($rejectionReason !== '') {
        return $rejectionReason;
    }

    if ($decision === 'rejected') {
        return 'This does not appear to be a medical document. Please upload a lab result, intake form, discharge summary, medication list, insurance/claim document, or clinical note.';
    }

    if ($documentType === 'medical_document') {
        return 'Medical document detected, but this demo ingestion workflow currently supports lab results and intake forms only. Review required before ingestion.';
    }

    return 'Document type could not be verified. Review required before ingestion.';
}

function aiCopilotMedicalGuardBuildPayload(array $guardResult): array
{
    $detectedEntitySummary = is_array($guardResult['detectedEntitySummary'] ?? null) ? $guardResult['detectedEntitySummary'] : [];
    $highConfidenceEntityCount = isset($detectedEntitySummary['highConfidenceEntityCount']) && is_numeric($detectedEntitySummary['highConfidenceEntityCount'])
        ? (int) $detectedEntitySummary['highConfidenceEntityCount']
        : 0;
    $medicalEntityCount = isset($detectedEntitySummary['medicalEntityCount']) && is_numeric($detectedEntitySummary['medicalEntityCount'])
        ? (int) $detectedEntitySummary['medicalEntityCount']
        : $highConfidenceEntityCount;

    return [
        'decision' => (string) ($guardResult['decision'] ?? 'review_required'),
        'document_type' => (string) ($guardResult['documentType'] ?? 'unknown'),
        'confidence' => isset($guardResult['confidence']) && is_numeric($guardResult['confidence']) ? round((float) $guardResult['confidence'], 4) : 0.0,
        'extracted_text_preview' => (string) ($guardResult['extractedTextPreview'] ?? ''),
        'detected_entity_summary' => $detectedEntitySummary,
        'medical_entity_count' => $medicalEntityCount,
        'high_confidence_entity_count' => $highConfidenceEntityCount,
        'rejection_reason' => (string) ($guardResult['rejectionReason'] ?? ''),
        'guard_provider' => (string) ($guardResult['guardProvider'] ?? 'local_validation_fallback'),
        'extraction_method' => (string) ($guardResult['extractionMethod'] ?? 'not_run'),
        'textract_status' => (string) ($guardResult['textractStatus'] ?? 'not_run'),
        'comprehend_status' => (string) ($guardResult['comprehendStatus'] ?? 'not_run'),
        'aws_guard_enabled' => !empty($guardResult['awsGuardEnabled']),
        'text_extraction_status' => (string) (
            $guardResult['textExtractionStatus']
            ?? ((((string) ($guardResult['extractedText'] ?? '')) !== '' || ((string) ($guardResult['extractedTextPreview'] ?? '')) !== '') ? 'success' : 'failed')
        ),
        'medical_validation_status' => (string) ($guardResult['medicalValidationStatus'] ?? ($guardResult['decision'] ?? 'review_required')),
        'chart_write_status' => (string) ($guardResult['chartWriteStatus'] ?? ((string) ($guardResult['decision'] ?? 'review_required') === 'rejected' ? 'rejected' : 'requires_clinician_review')),
        'is_synthetic_demo_data' => !empty($guardResult['isSyntheticDemoData']),
        'review_required' => !array_key_exists('reviewRequired', $guardResult) || !empty($guardResult['reviewRequired']),
        'synthetic_demo_labels' => is_array($guardResult['syntheticDemoLabels'] ?? null) ? $guardResult['syntheticDemoLabels'] : [],
        'lab_evidence_score' => isset($guardResult['labEvidenceScore']) && is_numeric($guardResult['labEvidenceScore']) ? (int) $guardResult['labEvidenceScore'] : 0,
        'audit_events' => is_array($guardResult['auditEvents'] ?? null) ? $guardResult['auditEvents'] : [],
    ];
}

function aiCopilotMedicalGuardBuildBlockedOutput(
    string $status,
    array $guardResult,
    string $fileName,
    string $mimeType,
    ?int $fileSize,
    string $patientKey,
    string $patientName,
    string $uploadedAt,
    string $requestId,
    array $promptInjectionMatches = []
): array {
    $guardDocumentType = (string) ($guardResult['documentType'] ?? 'unknown');
    $workflowSourceType = aiCopilotMedicalGuardWorkflowSourceType($guardDocumentType);
    $documentClass = aiCopilotAttachmentNormalizeDocumentClass($guardDocumentType, $fileName, (string) ($guardResult['extractedTextPreview'] ?? ''));
    $displayFileName = aiCopilotAttachmentBuildDisplayFileName($fileName, $documentClass, $patientName);
    $sourceId = aiCopilotAttachmentBuildSourceId($patientKey, $documentClass, $fileName, $uploadedAt);
    $decision = (string) ($guardResult['decision'] ?? 'review_required');
    $safeMessage = aiCopilotMedicalGuardReviewMessage(
        $decision,
        $workflowSourceType,
        (string) ($guardResult['rejectionReason'] ?? '')
    );

    $missingData = [];
    if ($safeMessage !== '') {
        $missingData[] = $safeMessage;
    }
    if ($workflowSourceType === 'medical_document') {
        $missingData[] = 'Medical-document validation passed, but this demo ingestion workflow currently supports only lab results and intake forms for extraction and vectorization.';
    }
    if ($promptInjectionMatches !== []) {
        $missingData[] = 'Instruction-like text was detected in the uploaded PDF and treated as untrusted document content rather than instructions.';
    }
    $missingData = array_values(array_unique(array_filter(array_map('aiCopilotLabPdfNormalizeWhitespace', $missingData), static fn($item) => $item !== '')));

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => $status,
        'ingestion_status' => $decision === 'rejected' ? 'rejected' : 'review_required',
        'safe_message' => $safeMessage,
        'extraction_method' => (string) ($guardResult['extractionMethod'] ?? 'not_run'),
        'extracted_text_preview' => (string) ($guardResult['extractedTextPreview'] ?? ''),
        'document_metadata' => [
            'title' => $displayFileName,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/pdf',
            'size' => $fileSize,
            'document_type' => $documentClass,
            'seeded_demo' => false,
            'patient_key' => $patientKey,
            'patient_name' => $patientName,
            'uploaded_at' => $uploadedAt,
            'original_file_name' => $fileName,
            'display_file_name' => $displayFileName,
            'source_id' => $sourceId,
        ],
        'source_metadata' => [
            'file_name' => $displayFileName,
            'original_file_name' => $fileName,
            'display_file_name' => $displayFileName,
            'uploaded_at' => $uploadedAt,
            'source_type' => $workflowSourceType,
            'document_type' => $documentClass,
            'source_label' => $workflowSourceType === 'intake_form'
                ? 'Uploaded intake form'
                : ($workflowSourceType === 'medical_document' ? 'Uploaded medical document' : 'Uploaded PDF'),
            'chunk_count' => 0,
            'request_id' => $requestId,
            'source_id' => $sourceId,
        ],
        'missing_data' => $missingData,
        'missing_data_flags' => $missingData,
        'prompt_injection_matches' => $promptInjectionMatches,
        'document_guard' => aiCopilotMedicalGuardBuildPayload($guardResult),
    ]);
}

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

function aiCopilotLabPdfMvpSyntheticFallbackText(string $fileName = AI_COPILOT_LAB_PDF_MVP_SYNTHETIC_FILE_NAME): string
{
    return implode("\n", [
        'Synthetic demo data only',
        'Patient: Marcus Johnson',
        'Document: ' . $fileName,
        'Hemoglobin A1c: 8.2 %, high',
        'LDL Cholesterol: 142 mg/dL, high',
        'Creatinine: 1.1 mg/dL, normal',
        'eGFR: 82 mL/min/1.73m2, normal',
        'Collection Date: 2026-05-05',
        'Missing:',
        '- Ordering provider not clearly detected',
        '- Collection time not clearly detected',
    ]);
}

function aiCopilotLabPdfSeededMissingData(): array
{
    return [
        'Ordering provider not clearly detected',
        'Collection time not clearly detected',
    ];
}

function aiCopilotIntakeSeededText(): string
{
    return implode("\n", [
        'Synthetic demo data only',
        'Document: Marcus Johnson intake form',
        'Reason for visit: blood sugar management and medication questions',
        'Medication adherence issue: sometimes misses evening Metformin',
        'Allergies: no known drug allergies reported',
        'Insurance update: patient says coverage changed recently',
        'Care preference: written instructions and phone reminders',
    ]);
}

function aiCopilotIntakeSeededMissingData(): array
{
    return [
        'Current concerns were not clearly detected in the uploaded intake form.',
    ];
}

function aiCopilotAttachmentDocumentTypeHints(): array
{
    return [
        'intake_form' => [
            'file_patterns' => [
                '/\bintake\b/i',
                '/\bintake-form\b/i',
                '/\bpatient-intake\b/i',
                '/\bquestionnaire\b/i',
                '/\bform\b/i',
            ],
            'text_patterns' => [
                '/\breason for visit\b/i',
                '/\bcurrent concerns\b/i',
                '/\bmedication notes\b/i',
                '/\bmedication adherence\b/i',
                '/\ballergies\b/i',
                '/\binsurance update\b/i',
                '/\bcare preferences\b/i',
                '/\bpreferred contact\b/i',
            ],
        ],
        'lab_pdf' => [
            'file_patterns' => [
                '/\blab\b/i',
                '/\blabs\b/i',
                '/\bresult\b/i',
                '/\bdiagnostic\b/i',
            ],
            'text_patterns' => [
                '/\ba1c\b/i',
                '/\bglucose\b/i',
                '/\bldl\b/i',
                '/\bhdl\b/i',
                '/\bcreatinine\b/i',
                '/\begfr\b/i',
                '/\bmg\/dL\b/i',
                '/\bhigh\b/i',
                '/\blow\b/i',
                '/\bnormal\b/i',
                '/%/',
            ],
        ],
    ];
}

function aiCopilotAttachmentClassifyDocumentType(string $fileName, string $text = '', string $attachmentPurpose = ''): string
{
    $hints = aiCopilotAttachmentDocumentTypeHints();
    $attachmentPurpose = strtolower(trim($attachmentPurpose));

    foreach ($hints['intake_form']['file_patterns'] as $pattern) {
        if (preg_match($pattern, $fileName) === 1) {
            return 'intake_form';
        }
    }
    foreach ($hints['intake_form']['text_patterns'] as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return 'intake_form';
        }
    }

    foreach ($hints['lab_pdf']['file_patterns'] as $pattern) {
        if (preg_match($pattern, $fileName) === 1) {
            return 'lab_pdf';
        }
    }
    foreach ($hints['lab_pdf']['text_patterns'] as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return 'lab_pdf';
        }
    }

    if ($attachmentPurpose === 'lab_pdf_ingestion') {
        return 'unknown';
    }

    return 'unknown';
}

function aiCopilotAttachmentSourceLabel(string $documentType): string
{
    return match ($documentType) {
        'intake_form' => 'Uploaded intake form',
        'lab_pdf' => 'Uploaded lab PDF',
        default => 'Uploaded PDF',
    };
}

function aiCopilotAttachmentSourceTitle(string $documentType): string
{
    return match ($documentType) {
        'intake_form' => 'Attached Intake Form',
        'lab_pdf' => 'Attached Lab PDF',
        default => 'Attached PDF',
    };
}

function aiCopilotAttachmentNormalizeDocumentClass(string $legacyDocumentType, string $fileName = '', string $text = ''): string
{
    $normalized = strtolower(trim($legacyDocumentType));
    if ($normalized === 'intake_form') {
        return 'intake_form';
    }
    if ($normalized === 'lab_pdf' || $normalized === 'lab_results') {
        return 'lab_results';
    }
    if ($normalized === 'medical_document' || $normalized === 'other_medical') {
        return 'medical_document';
    }
    if ($normalized === 'non_medical') {
        return 'non_medical';
    }

    if (preg_match('/\b(reason for visit|current concerns|medication adherence|allergies|insurance update|care preferences|preferred contact)\b/i', $text) === 1) {
        return 'intake_form';
    }
    if (preg_match('/\b(a1c|glucose|ldl|hdl|creatinine|egfr|mg\/dL|normal|high|low)\b/i', $text) === 1) {
        return 'lab_results';
    }
    if (preg_match('/\b(intake|questionnaire|form)\b/i', $fileName) === 1) {
        return 'intake_form';
    }
    if (preg_match('/\b(lab|labs|result|diagnostic)\b/i', $fileName) === 1) {
        return 'lab_results';
    }
    if (preg_match('/\b(patient|visit|medical|clinical|encounter|medication|allerg)\b/i', $text . ' ' . $fileName) === 1) {
        return 'medical_document';
    }

    return 'non_medical';
}

function aiCopilotAttachmentLegacySourceType(string $documentClass): string
{
    return match (strtolower(trim($documentClass))) {
        'intake_form' => 'intake_form',
        'lab_results', 'lab_pdf' => 'lab_pdf',
        'medical_document', 'other_medical' => 'medical_document',
        default => 'non_medical',
    };
}

function aiCopilotAttachmentIsLabDocumentType(string $documentType): bool
{
    return in_array(strtolower(trim($documentType)), ['lab_pdf', 'lab_results'], true);
}

function aiCopilotAttachmentIsIntakeDocumentType(string $documentType): bool
{
    return strtolower(trim($documentType)) === 'intake_form';
}

function aiCopilotAttachmentBuildDisplayFileName(string $originalFileName, string $documentClass, string $patientName = ''): string
{
    $originalFileName = trim(basename($originalFileName));
    $patientName = trim($patientName);
    if ($patientName !== '') {
        return match (strtolower(trim($documentClass))) {
            'lab_results' => $patientName . ' Lab Results.pdf',
            'intake_form' => $patientName . ' Intake Form.pdf',
            'medical_document' => $patientName . ' Medical Document.pdf',
            default => $originalFileName !== '' ? $originalFileName : ($patientName . ' Uploaded Document.pdf'),
        };
    }

    if ($originalFileName === '') {
        return match (strtolower(trim($documentClass))) {
            'lab_results' => 'Uploaded Lab Results.pdf',
            'intake_form' => 'Uploaded Intake Form.pdf',
            'medical_document' => 'Uploaded Medical Document.pdf',
            default => 'Uploaded Document.pdf',
        };
    }

    $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $baseName = pathinfo($originalFileName, PATHINFO_FILENAME);
    $baseName = preg_replace('/\(\d+\)$/', '', (string) $baseName);
    $baseName = preg_replace('/[_\-]+/', ' ', (string) $baseName);
    $baseName = aiCopilotLabPdfNormalizeWhitespace((string) $baseName);
    $extension = $extension !== '' ? '.' . strtolower($extension) : '';

    return ($baseName !== '' ? $baseName : 'Uploaded Document') . ($extension !== '' ? $extension : '.pdf');
}

function aiCopilotAttachmentBuildSourceId(string $patientKey, string $documentClass, string $originalFileName, string $uploadedAt): string
{
    $seed = strtolower(trim($patientKey . '|' . $documentClass . '|' . basename($originalFileName) . '|' . $uploadedAt));
    return 'source_' . substr(sha1($seed), 0, 16);
}

function aiCopilotLabPdfRecognizedTests(): array
{
    return [
        [
            'key' => 'hemoglobin_a1c',
            'label' => 'Hemoglobin A1c',
            'default_unit' => '%',
            'aliases' => [
                '/\bhemoglobin\s*a1c\b/i',
                '/\bhba1c\b/i',
                '/\ba1c\b/i',
            ],
        ],
        [
            'key' => 'random_glucose',
            'label' => 'Random Glucose',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\brandom glucose\b/i',
                '/\bglucose\b/i',
            ],
        ],
        [
            'key' => 'ldl_cholesterol',
            'label' => 'LDL Cholesterol',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\bldl cholesterol\b/i',
                '/\bldl\b/i',
            ],
        ],
        [
            'key' => 'wbc',
            'label' => 'WBC',
            'default_unit' => 'K/uL',
            'aliases' => [
                '/\bwbc\b/i',
                '/\bwhite blood cells?\b/i',
            ],
        ],
        [
            'key' => 'bun',
            'label' => 'BUN',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\bbun\b/i',
                '/\bblood urea nitrogen\b/i',
            ],
        ],
        [
            'key' => 'sodium',
            'label' => 'Sodium',
            'default_unit' => 'mmol/L',
            'aliases' => [
                '/\bsodium\b/i',
            ],
        ],
        [
            'key' => 'potassium',
            'label' => 'Potassium',
            'default_unit' => 'mmol/L',
            'aliases' => [
                '/\bpotassium\b/i',
            ],
        ],
        [
            'key' => 'hemoglobin',
            'label' => 'Hemoglobin',
            'default_unit' => 'g/dL',
            'aliases' => [
                '/\bhemoglobin\b/i',
            ],
        ],
        [
            'key' => 'platelets',
            'label' => 'Platelets',
            'default_unit' => 'K/uL',
            'aliases' => [
                '/\bplatelets?\b/i',
            ],
        ],
        [
            'key' => 'crp',
            'label' => 'CRP',
            'default_unit' => 'mg/L',
            'aliases' => [
                '/\bcrp\b/i',
                '/\bc-reactive protein\b/i',
            ],
        ],
        [
            'key' => 'esr',
            'label' => 'ESR',
            'default_unit' => 'mm/hr',
            'aliases' => [
                '/\besr\b/i',
                '/\berythrocyte sedimentation rate\b/i',
            ],
        ],
        [
            'key' => 'uacr',
            'label' => 'Urine Albumin/Creatinine Ratio',
            'default_unit' => 'mg/g',
            'aliases' => [
                '/\burine albumin\/creatinine ratio\b/i',
                '/\buacr\b/i',
                '/\balbumin\/creatinine ratio\b/i',
            ],
        ],
        [
            'key' => 'total_cholesterol',
            'label' => 'Total Cholesterol',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\btotal cholesterol\b/i',
            ],
        ],
        [
            'key' => 'hdl_cholesterol',
            'label' => 'HDL Cholesterol',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\bhdl cholesterol\b/i',
                '/\bhdl\b/i',
            ],
        ],
        [
            'key' => 'triglycerides',
            'label' => 'Triglycerides',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\btriglycerides?\b/i',
            ],
        ],
        [
            'key' => 'creatinine',
            'label' => 'Creatinine',
            'default_unit' => 'mg/dL',
            'aliases' => [
                '/\bcreatinine\b/i',
            ],
        ],
        [
            'key' => 'egfr',
            'label' => 'eGFR',
            'default_unit' => 'mL/min/1.73m2',
            'aliases' => [
                '/\begfr\b/i',
                '/\bestimated glomerular filtration rate\b/i',
            ],
        ],
    ];
}

function aiCopilotLabPdfNormalizeUnit(string $unit, string $defaultUnit = ''): string
{
    $unit = trim($unit);
    if ($unit === '') {
        return $defaultUnit;
    }

    $unit = preg_replace('/\s+/', '', $unit);
    $unit = is_string($unit) ? $unit : '';
    if (preg_match('/^mg\/dl$/i', $unit) === 1) {
        return 'mg/dL';
    }
    if (preg_match('/^mg\/l$/i', $unit) === 1) {
        return 'mg/L';
    }
    if (preg_match('/^mmol\/l$/i', $unit) === 1) {
        return 'mmol/L';
    }
    if (preg_match('/^k\/ul$/i', $unit) === 1) {
        return 'K/uL';
    }
    if (preg_match('/^mg\/g$/i', $unit) === 1) {
        return 'mg/g';
    }
    if (preg_match('/^mm\/hr$/i', $unit) === 1) {
        return 'mm/hr';
    }
    if (preg_match('/^g\/dl$/i', $unit) === 1) {
        return 'g/dL';
    }
    if (preg_match('/^ml\/min\/1\.73m2$/i', $unit) === 1) {
        return 'mL/min/1.73m2';
    }
    if ($unit === '%') {
        return '%';
    }

    return $unit !== '' ? $unit : $defaultUnit;
}

function aiCopilotLabPdfFindRecognizedTest(string $line): ?array
{
    foreach (aiCopilotLabPdfRecognizedTests() as $test) {
        foreach (($test['aliases'] ?? []) as $pattern) {
            if (preg_match((string) $pattern, $line) === 1) {
                return $test;
            }
        }
    }

    return null;
}

function aiCopilotLabPdfInferFlag(string $testKey, float $numericValue, string $line, string $parsedFlag = ''): string
{
    $parsedFlag = strtolower(trim($parsedFlag));
    if ($parsedFlag !== '') {
        return $parsedFlag;
    }

    return match ($testKey) {
        'hemoglobin_a1c' => $numericValue >= 6.5 ? 'high' : 'normal',
        'random_glucose' => $numericValue < 70 ? 'low' : ($numericValue >= 180 ? 'high' : 'normal'),
        'ldl_cholesterol' => $numericValue >= 100 ? 'high' : 'normal',
        'wbc' => $numericValue < 4 ? 'low' : ($numericValue > 10.5 ? 'high' : 'normal'),
        'bun' => $numericValue < 7 ? 'low' : ($numericValue > 20 ? 'high' : 'normal'),
        'sodium' => $numericValue < 135 ? 'low' : ($numericValue > 145 ? 'high' : 'normal'),
        'potassium' => $numericValue < 3.5 ? 'low' : ($numericValue > 5.1 ? 'high' : 'normal'),
        'hemoglobin' => $numericValue < 13.0 ? 'low' : ($numericValue > 17.5 ? 'high' : 'normal'),
        'platelets' => $numericValue < 150 ? 'low' : ($numericValue > 450 ? 'high' : 'normal'),
        'crp' => $numericValue > 10 ? 'high' : 'normal',
        'esr' => $numericValue > 20 ? 'high' : 'normal',
        'uacr' => $numericValue > 30 ? 'high' : 'normal',
        'total_cholesterol' => $numericValue >= 200 ? 'high' : 'normal',
        'hdl_cholesterol' => $numericValue < 40 ? 'low' : 'normal',
        'triglycerides' => $numericValue >= 150 ? 'high' : 'normal',
        'creatinine' => $numericValue < 0.6 ? 'low' : ($numericValue > 1.3 ? 'high' : 'normal'),
        'egfr' => $numericValue < 60 ? 'low' : 'normal',
        default => 'unknown',
    };
}

function aiCopilotLabPdfPromptInjectionLine(string $line): bool
{
    foreach (aiCopilotLabPdfPromptInjectionMatches($line) as $match) {
        if ($match !== '') {
            return true;
        }
    }

    return false;
}

function aiCopilotLabPdfIsSyntheticMarcusJohnsonPdf(string $fileName, string $text = ''): bool
{
    $normalizedFileName = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $fileName));
    if (trim($text) !== '') {
        return false;
    }

    return str_contains($normalizedFileName, 'marcus_johnson_synthetic_lab_results')
        || $normalizedFileName === strtolower((string) preg_replace('/[^a-z0-9]+/', '_', AI_COPILOT_LAB_PDF_MVP_SYNTHETIC_FILE_NAME));
}

function aiCopilotIntakeIsSyntheticMarcusJohnsonForm(string $fileName, string $text = ''): bool
{
    $normalizedFileName = strtolower($fileName);
    if (trim($text) !== '') {
        return false;
    }

    return preg_match('/marcus[-_ ]johnson.*intake.*\.pdf/', $normalizedFileName) === 1
        || $normalizedFileName === strtolower(AI_COPILOT_INTAKE_FORM_SEEDED_FILE_NAME);
}

function aiCopilotLabPdfSeededFacts(): array
{
    return [
        'facts' => [
            [
                'key' => 'hemoglobin_a1c',
                'name' => 'Hemoglobin A1c',
                'label' => 'Hemoglobin A1c',
                'value' => '8.2 %',
                'numeric_value' => 8.2,
                'unit' => '%',
                'reference_range' => '',
                'flag' => 'high',
                'interpretation' => 'high',
                'source_label' => 'Uploaded Lab PDF',
            ],
            [
                'key' => 'ldl_cholesterol',
                'name' => 'LDL Cholesterol',
                'label' => 'LDL Cholesterol',
                'value' => '142 mg/dL',
                'numeric_value' => 142.0,
                'unit' => 'mg/dL',
                'reference_range' => '',
                'flag' => 'high',
                'interpretation' => 'high',
                'source_label' => 'Uploaded Lab PDF',
            ],
            [
                'key' => 'creatinine',
                'name' => 'Creatinine',
                'label' => 'Creatinine',
                'value' => '1.1 mg/dL',
                'numeric_value' => 1.1,
                'unit' => 'mg/dL',
                'reference_range' => '',
                'flag' => 'normal',
                'interpretation' => 'normal',
                'source_label' => 'Uploaded Lab PDF',
            ],
            [
                'key' => 'egfr',
                'name' => 'eGFR',
                'label' => 'eGFR',
                'value' => '82 mL/min/1.73m2',
                'numeric_value' => 82.0,
                'unit' => 'mL/min/1.73m2',
                'reference_range' => '',
                'flag' => 'normal',
                'interpretation' => 'normal',
                'source_label' => 'Uploaded Lab PDF',
            ],
        ],
        'abnormal' => [
            'Hemoglobin A1c: 8.2 %, high',
            'LDL Cholesterol: 142 mg/dL, high',
        ],
        'missing' => aiCopilotLabPdfSeededMissingData(),
        'rejected_lines' => [],
        'valid_row_count' => 4,
    ];
}

function aiCopilotLabPdfBuildGroundedFactText(array $factSummary): string
{
    $lines = [];
    foreach (($factSummary['facts'] ?? []) as $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $valueLine = aiCopilotJoinParts([
            aiCopilotLabPdfNormalizeWhitespace((string) ($fact['name'] ?? $fact['label'] ?? '')),
            ':',
            aiCopilotLabPdfNormalizeWhitespace((string) ($fact['value'] ?? '')),
        ]);
        $flag = aiCopilotLabPdfNormalizeWhitespace((string) ($fact['flag'] ?? $fact['interpretation'] ?? ''));
        if ($flag !== '') {
            $valueLine .= ', ' . $flag;
        }
        if (trim($valueLine) !== '') {
            $lines[] = trim($valueLine);
        }
    }

    if (!empty($factSummary['missing'])) {
        $lines[] = 'Missing:';
        foreach ($factSummary['missing'] as $item) {
            $itemText = aiCopilotLabPdfNormalizeWhitespace((string) $item);
            if ($itemText !== '') {
                $lines[] = '- ' . $itemText;
            }
        }
    }

    return implode("\n", $lines);
}

function aiCopilotLabPdfBuildReviewRequiredOutput(
    string $fileName,
    ?int $fileSize,
    bool $seededDemo,
    string $patientKey,
    string $patientName,
    string $uploadedAt,
    string $requestId,
    string $extractionMethod,
    array $missingData = [],
    array $promptInjectionMatches = []
): array {
    $reviewMessage = 'PDF text extraction did not produce reliable lab rows. Clinician must verify the source PDF.';
    if ($promptInjectionMatches !== []) {
        $missingData[] = 'Instruction-like text was detected in the uploaded PDF and treated as untrusted document content rather than instructions.';
    }

    $missingData = array_values(array_unique(array_filter(array_map('aiCopilotLabPdfNormalizeWhitespace', $missingData), static fn($item) => $item !== '')));

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => 'extraction_review_required',
        'ingestion_status' => 'review_required',
        'safe_message' => $reviewMessage,
        'extraction_method' => $extractionMethod,
        'document_metadata' => [
            'title' => $fileName,
            'mime_type' => 'application/pdf',
            'size' => $fileSize,
            'document_type' => 'lab_pdf',
            'seeded_demo' => $seededDemo,
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
        'extracted_facts' => [],
        'abnormal_findings' => [],
        'missing_data' => $missingData,
        'missing_data_flags' => $missingData,
        'prompt_injection_matches' => $promptInjectionMatches,
    ]);
}

function aiCopilotIntakeBuildReviewRequiredOutput(
    string $fileName,
    ?int $fileSize,
    bool $seededDemo,
    string $patientKey,
    string $patientName,
    string $uploadedAt,
    string $requestId,
    string $extractionMethod,
    array $missingData = [],
    array $promptInjectionMatches = []
): array {
    $reviewMessage = 'Intake form extraction did not produce reliable intake fields. Clinician must verify the source PDF.';
    if ($promptInjectionMatches !== []) {
        $missingData[] = 'Instruction-like text was detected in the uploaded intake form and treated as untrusted document content rather than instructions.';
    }

    $missingData = array_values(array_unique(array_filter(array_map('aiCopilotLabPdfNormalizeWhitespace', $missingData), static fn($item) => $item !== '')));

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => 'extraction_review_required',
        'ingestion_status' => 'review_required',
        'safe_message' => $reviewMessage,
        'extraction_method' => $extractionMethod,
        'document_metadata' => [
            'title' => $fileName,
            'mime_type' => 'application/pdf',
            'size' => $fileSize,
            'document_type' => 'intake_form',
            'seeded_demo' => $seededDemo,
            'patient_key' => $patientKey,
            'patient_name' => $patientName,
            'uploaded_at' => $uploadedAt,
        ],
        'source_metadata' => [
            'file_name' => $fileName,
            'uploaded_at' => $uploadedAt,
            'source_type' => 'intake_form',
            'source_label' => 'Uploaded intake form',
            'chunk_count' => 0,
            'request_id' => $requestId,
        ],
        'extracted_facts' => [],
        'missing_data' => $missingData,
        'missing_data_flags' => $missingData,
        'intake_fields' => aiCopilotBuildIntakeFieldsPayload([], [
            'missing' => $missingData,
            'source_file' => $fileName,
            'source_chunk_ids' => [],
            'uploaded_timestamp' => $uploadedAt,
            'ingestion_status' => 'review_required',
        ]),
        'prompt_injection_matches' => $promptInjectionMatches,
    ]);
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

function aiCopilotLabPdfLogExtractionAuditEvents(array $result, string $requestId, string $fileName): void
{
    $payload = [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
        'extraction_method' => (string) ($result['extraction_method'] ?? 'failed'),
        'text_extraction_status' => (string) ($result['text_extraction_status'] ?? 'failed'),
        'page_count' => isset($result['page_count']) && is_numeric($result['page_count']) ? (int) $result['page_count'] : 0,
        'raw_bytes_detected' => !empty($result['raw_bytes_detected']) ? 'true' : 'false',
    ];

    foreach (($result['audit_events'] ?? []) as $eventName) {
        if (!is_string($eventName) || trim($eventName) === '') {
            continue;
        }
        aiCopilotMedicalGuardLog($eventName, $payload);
    }
}

function aiCopilotLabPdfExtractTextFromBinary(string $binary, string $tmpName = '', string $fileName = 'attached-document.pdf', string $requestId = 'request'): array
{
    $nodeBinary = aiCopilotMedicalGuardFindNodeBinary();
    $scriptPath = __DIR__ . '/pdf_text_extractor.js';
    $temporaryFilePath = '';
    $sourcePath = $tmpName !== '' && is_file($tmpName) ? $tmpName : '';

    if ($sourcePath === '' && $binary !== '') {
        $temporaryFilePath = tempnam(sys_get_temp_dir(), 'copilot_pdf_extract_');
        if ($temporaryFilePath !== false) {
            file_put_contents($temporaryFilePath, $binary);
            $sourcePath = $temporaryFilePath;
        }
    }

    if ($nodeBinary === '' || !is_file($scriptPath) || $sourcePath === '' || !is_file($sourcePath)) {
        if ($temporaryFilePath !== '' && is_file($temporaryFilePath)) {
            @unlink($temporaryFilePath);
        }
        return [
            'text' => '',
            'page_count' => 0,
            'extraction_method' => 'failed',
            'text_extraction_status' => 'failed',
            'extracted_text_preview' => '',
            'raw_bytes_detected' => false,
            'audit_events' => [
                'copilot_pdf_text_extraction_started',
                'copilot_pdf_text_extraction_failed',
            ],
        ];
    }

    $inputPath = tempnam(sys_get_temp_dir(), 'copilot_pdf_extract_input_');
    if ($inputPath === false) {
        if ($temporaryFilePath !== '' && is_file($temporaryFilePath)) {
            @unlink($temporaryFilePath);
        }
        return [
            'text' => '',
            'page_count' => 0,
            'extraction_method' => 'failed',
            'text_extraction_status' => 'failed',
            'extracted_text_preview' => '',
            'raw_bytes_detected' => false,
            'audit_events' => [
                'copilot_pdf_text_extraction_started',
                'copilot_pdf_text_extraction_failed',
            ],
        ];
    }

    file_put_contents($inputPath, json_encode([
        'requestId' => $requestId,
        'filePath' => $sourcePath,
        'fileName' => basename($fileName),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
    if ($temporaryFilePath !== '' && is_file($temporaryFilePath)) {
        @unlink($temporaryFilePath);
    }

    if ($exitCode !== 0) {
        return [
            'text' => '',
            'page_count' => 0,
            'extraction_method' => 'failed',
            'text_extraction_status' => 'failed',
            'extracted_text_preview' => '',
            'raw_bytes_detected' => false,
            'audit_events' => [
                'copilot_pdf_text_extraction_started',
                'copilot_pdf_text_extraction_failed',
            ],
            'internal_error' => aiCopilotLabPdfNormalizeWhitespace($stderr),
        ];
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        return [
            'text' => '',
            'page_count' => 0,
            'extraction_method' => 'failed',
            'text_extraction_status' => 'failed',
            'extracted_text_preview' => '',
            'raw_bytes_detected' => false,
            'audit_events' => [
                'copilot_pdf_text_extraction_started',
                'copilot_pdf_text_extraction_failed',
            ],
            'internal_error' => aiCopilotLabPdfNormalizeWhitespace($stderr),
        ];
    }

    return [
        'text' => aiCopilotLabPdfNormalizeWhitespace((string) ($decoded['extractedText'] ?? '')),
        'page_count' => isset($decoded['pageCount']) && is_numeric($decoded['pageCount']) ? (int) $decoded['pageCount'] : 0,
        'extraction_method' => aiCopilotLabPdfNormalizeWhitespace((string) ($decoded['extractionMethod'] ?? 'failed')),
        'text_extraction_status' => aiCopilotLabPdfNormalizeWhitespace((string) ($decoded['textExtractionStatus'] ?? 'failed')),
        'extracted_text_preview' => aiCopilotLabPdfNormalizeWhitespace((string) ($decoded['extractedTextPreview'] ?? '')),
        'raw_bytes_detected' => !empty($decoded['rawBytesDetected']),
        'audit_events' => is_array($decoded['auditEvents'] ?? null) ? $decoded['auditEvents'] : [],
        'internal_error' => aiCopilotLabPdfNormalizeWhitespace((string) ($decoded['internalError'] ?? '')),
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

function aiCopilotLabPdfExtractFacts(string $text, array $options = []): array
{
    $fileName = (string) ($options['file_name'] ?? '');
    if (!empty($options['use_synthetic_marcus']) || aiCopilotLabPdfIsSyntheticMarcusJohnsonPdf($fileName, $text)) {
        return aiCopilotLabPdfSeededFacts();
    }

    $factsByKey = [];
    $abnormal = [];
    $missing = [];
    $rejectedLines = [];
    $lines = explode("\n", aiCopilotLabPdfNormalizeWhitespace($text));

    foreach ($lines as $line) {
        $line = aiCopilotLabPdfNormalizeWhitespace($line);
        if ($line === '' || aiCopilotLabPdfPromptInjectionLine($line)) {
            continue;
        }

        if (preg_match('/^(patient|document)\s*:/i', $line) === 1) {
            continue;
        }

        if (preg_match('/^missing:?$/i', $line) === 1) {
            continue;
        }

        if (preg_match('/^-\s+/', $line) === 1) {
            $missing[] = preg_replace('/^-\s*/', '', $line);
            continue;
        }

        $test = aiCopilotLabPdfFindRecognizedTest($line);
        if ($test === null) {
            if ((str_contains($line, ':') || preg_match('/\d/', $line) === 1) && preg_match('/[A-Za-z]/', $line) === 1) {
                $rejectedLines[] = $line;
            }
            continue;
        }

        $numericSearchText = $line;
        foreach (($test['aliases'] ?? []) as $pattern) {
            if (preg_match((string) $pattern, $line, $aliasMatch, PREG_OFFSET_CAPTURE) === 1) {
                $matchedText = (string) ($aliasMatch[0][0] ?? '');
                $matchedOffset = (int) ($aliasMatch[0][1] ?? 0);
                $numericSearchText = substr($line, $matchedOffset + strlen($matchedText));
                break;
            }
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)\s*(%|mg\/dL|mg\/dl|mL\/min\/1\.73m2|ml\/min\/1\.73m2|mg\/L|mg\/l|mmol\/L|mmol\/l|K\/uL|k\/uL|mg\/g|mm\/hr)?/i', $numericSearchText, $valueMatches) !== 1) {
            $rejectedLines[] = $line;
            continue;
        }

        $numericValue = (float) $valueMatches[1];
        $unit = aiCopilotLabPdfNormalizeUnit((string) ($valueMatches[2] ?? ''), (string) ($test['default_unit'] ?? ''));
        $referenceRange = '';
        if (preg_match('/(?:ref(?:erence)? range|range)\s*[:\-]?\s*([A-Za-z0-9<>\-\.\/% ]+)/i', $line, $rangeMatches) === 1) {
            $referenceRange = aiCopilotLabPdfNormalizeWhitespace((string) $rangeMatches[1]);
        }

        $parsedFlag = '';
        if (preg_match('/\b(high|low|normal|abnormal|critical|unknown)\b/i', $line, $flagMatches) === 1) {
            $parsedFlag = strtolower(aiCopilotLabPdfNormalizeWhitespace((string) ($flagMatches[1] ?? '')));
        } elseif (preg_match('/(?:^|[\s:,\-])(H|L)(?:$|[\s,;])/i', $line, $flagMatches) === 1) {
            $parsedFlag = strtoupper((string) ($flagMatches[1] ?? '')) === 'H' ? 'high' : 'low';
        }
        $flag = aiCopilotLabPdfInferFlag((string) $test['key'], $numericValue, $line, $parsedFlag);
        $displayValue = trim($valueMatches[1] . ($unit !== '' ? ' ' . $unit : ''));
        $fact = [
            'key' => (string) $test['key'],
            'name' => (string) $test['label'],
            'label' => (string) $test['label'],
            'value' => $displayValue,
            'numeric_value' => $numericValue,
            'unit' => $unit,
            'reference_range' => $referenceRange,
            'flag' => $flag,
            'interpretation' => $flag,
            'source_label' => 'Uploaded Lab PDF',
        ];
        $factsByKey[(string) $test['key']] = $fact;
        if (in_array($flag, ['high', 'low', 'abnormal', 'critical'], true)) {
            $abnormal[] = $fact['name'] . ': ' . $displayValue . ', ' . $flag;
        }
    }

    return [
        'facts' => array_values($factsByKey),
        'abnormal' => array_values(array_unique($abnormal)),
        'missing' => array_values(array_unique(array_filter(array_map('trim', $missing), static fn($item) => $item !== ''))),
        'rejected_lines' => array_values(array_unique(array_filter(array_map('trim', $rejectedLines), static fn($item) => $item !== ''))),
        'valid_row_count' => count($factsByKey),
    ];
}

function aiCopilotIntakeFieldDefinitions(): array
{
    return [
        'reasonForVisit' => [
            'title' => 'Reason for Visit',
            'patterns' => [
                '/^reason for visit\s*:\s*(.+)$/i',
                '/^visit reason\s*:\s*(.+)$/i',
            ],
        ],
        'currentConcerns' => [
            'title' => 'Current Concerns',
            'patterns' => [
                '/^current concerns\s*:\s*(.+)$/i',
                '/^concerns\s*:\s*(.+)$/i',
            ],
        ],
        'medicationAdherence' => [
            'title' => 'Medication / Adherence Notes',
            'patterns' => [
                '/^medication adherence issue\s*:\s*(.+)$/i',
                '/^medication adherence\s*:\s*(.+)$/i',
                '/^medication notes\s*:\s*(.+)$/i',
                '/^medication\/adherence notes\s*:\s*(.+)$/i',
            ],
        ],
        'allergies' => [
            'title' => 'Allergies',
            'patterns' => [
                '/^allergies\s*:\s*(.+)$/i',
            ],
        ],
        'insuranceUpdate' => [
            'title' => 'Insurance Update',
            'patterns' => [
                '/^insurance update\s*:\s*(.+)$/i',
                '/^coverage update\s*:\s*(.+)$/i',
            ],
        ],
        'carePreferences' => [
            'title' => 'Care Preferences',
            'patterns' => [
                '/^care preferences\s*:\s*(.+)$/i',
                '/^preferred contact\s*:\s*(.+)$/i',
                '/^care preference\s*:\s*(.+)$/i',
            ],
        ],
    ];
}

function aiCopilotIntakeMissingFieldMessage(string $fieldKey): string
{
    return match ($fieldKey) {
        'reasonForVisit' => 'Reason for visit was not clearly detected in the uploaded intake form.',
        'currentConcerns' => 'Current concerns were not clearly detected in the uploaded intake form.',
        'medicationAdherence' => 'Medication / adherence notes were not clearly detected in the uploaded intake form.',
        'allergies' => 'Allergies were not clearly detected in the uploaded intake form.',
        'insuranceUpdate' => 'Insurance update was not clearly detected in the uploaded intake form.',
        'carePreferences' => 'Care preferences were not clearly detected in the uploaded intake form.',
        default => 'A required intake field was not clearly detected in the uploaded intake form.',
    };
}

function aiCopilotIntakeExtractFields(string $text): array
{
    $lines = explode("\n", aiCopilotLabPdfNormalizeWhitespace($text));
    $fields = [
        'reasonForVisit' => '',
        'currentConcerns' => '',
        'medicationAdherence' => '',
        'allergies' => '',
        'insuranceUpdate' => '',
        'carePreferences' => '',
    ];
    $missing = [];
    $rejectedLines = [];

    foreach ($lines as $line) {
        $line = aiCopilotLabPdfNormalizeWhitespace($line);
        if ($line === '' || aiCopilotLabPdfPromptInjectionLine($line)) {
            continue;
        }

        if (preg_match('/^(patient|document|synthetic demo data only)\b/i', $line) === 1) {
            continue;
        }

        $matched = false;
        foreach (aiCopilotIntakeFieldDefinitions() as $fieldKey => $definition) {
            foreach (($definition['patterns'] ?? []) as $pattern) {
                if (preg_match((string) $pattern, $line, $matches) === 1) {
                    $value = aiCopilotLabPdfNormalizeWhitespace((string) ($matches[1] ?? ''));
                    if ($value !== '') {
                        $fields[$fieldKey] = $value;
                    }
                    $matched = true;
                    break 2;
                }
            }
        }

        if ($matched) {
            continue;
        }

        if (preg_match('/^missing:?$/i', $line) === 1) {
            continue;
        }

        if (preg_match('/^-\s+(.+)$/', $line, $missingMatches) === 1) {
            $missing[] = aiCopilotLabPdfNormalizeWhitespace((string) $missingMatches[1]);
            continue;
        }

        if (str_contains($line, ':') && preg_match('/[A-Za-z]/', $line) === 1) {
            $rejectedLines[] = $line;
        }
    }

    foreach (array_keys($fields) as $fieldKey) {
        if ($fields[$fieldKey] === '') {
            $missing[] = aiCopilotIntakeMissingFieldMessage($fieldKey);
        }
    }

    $facts = [];
    foreach (aiCopilotIntakeFieldDefinitions() as $fieldKey => $definition) {
        $value = $fields[$fieldKey] ?? '';
        if ($value === '') {
            continue;
        }

        $facts[] = [
            'key' => $fieldKey,
            'name' => (string) ($definition['title'] ?? $fieldKey),
            'label' => (string) ($definition['title'] ?? $fieldKey),
            'value' => $value,
            'interpretation' => '',
            'source_label' => 'Uploaded Intake Form',
        ];
    }

    return [
        'fields' => $fields,
        'facts' => $facts,
        'missing' => array_values(array_unique(array_filter(array_map('trim', $missing), static fn($item) => $item !== ''))),
        'rejected_lines' => array_values(array_unique(array_filter(array_map('trim', $rejectedLines), static fn($item) => $item !== ''))),
        'valid_field_count' => count(array_filter($fields, static fn($value) => trim((string) $value) !== '')),
    ];
}

function aiCopilotIntakeBuildGroundedText(array $summary): string
{
    $lines = [];
    foreach (aiCopilotIntakeFieldDefinitions() as $fieldKey => $definition) {
        $value = aiCopilotLabPdfNormalizeWhitespace((string) ($summary['fields'][$fieldKey] ?? ''));
        if ($value === '') {
            continue;
        }

        $lines[] = (string) ($definition['title'] ?? $fieldKey) . ': ' . $value;
    }

    if (!empty($summary['missing'])) {
        $lines[] = 'Missing:';
        foreach ($summary['missing'] as $item) {
            $itemText = aiCopilotLabPdfNormalizeWhitespace((string) $item);
            if ($itemText !== '') {
                $lines[] = '- ' . $itemText;
            }
        }
    }

    return implode("\n", $lines);
}

function aiCopilotBuildIntakeFieldsPayload(array $fields, array $options = []): array
{
    return [
        'reasonForVisit' => aiCopilotLabPdfNormalizeWhitespace((string) ($fields['reasonForVisit'] ?? '')),
        'currentConcerns' => aiCopilotLabPdfNormalizeWhitespace((string) ($fields['currentConcerns'] ?? '')),
        'medicationAdherence' => aiCopilotLabPdfNormalizeWhitespace((string) ($fields['medicationAdherence'] ?? '')),
        'allergies' => aiCopilotLabPdfNormalizeWhitespace((string) ($fields['allergies'] ?? '')),
        'insuranceUpdate' => aiCopilotLabPdfNormalizeWhitespace((string) ($fields['insuranceUpdate'] ?? '')),
        'carePreferences' => aiCopilotLabPdfNormalizeWhitespace((string) ($fields['carePreferences'] ?? '')),
        'missingOrAmbiguousData' => array_values(array_unique(array_filter(array_map('aiCopilotLabPdfNormalizeWhitespace', $options['missing'] ?? []), static fn($item) => $item !== ''))),
        'sourceFile' => aiCopilotLabPdfNormalizeWhitespace((string) ($options['source_file'] ?? '')),
        'sourceChunkIds' => array_values(array_filter(array_map('strval', $options['source_chunk_ids'] ?? []), static fn($item) => trim($item) !== '')),
        'uploadedTimestamp' => aiCopilotLabPdfNormalizeWhitespace((string) ($options['uploaded_timestamp'] ?? '')),
        'ingestionStatus' => aiCopilotLabPdfNormalizeWhitespace((string) ($options['ingestion_status'] ?? '')),
    ];
}

function aiCopilotBuildUploadedDocumentSourceLink(
    string $docType,
    ?int $sourceDocumentId,
    string $pageOrSection,
    string $fieldOrChunkId,
    string $quoteOrValue,
    float $confidence,
    array $extras = []
): array {
    $normalizedDocType = aiCopilotAttachmentNormalizeDocumentClass($docType);
    $sourceType = in_array($normalizedDocType, ['lab_pdf', 'lab_results'], true)
        ? 'lab_pdf'
        : ($normalizedDocType === 'intake_form' ? 'intake_form' : 'uploaded_document');
    $citation = [
        'source_type' => $sourceType,
        'source_id' => trim((string) ($extras['source_id'] ?? ($sourceDocumentId !== null ? 'source_document_' . $sourceDocumentId : $fieldOrChunkId))),
        'source_document_id' => $sourceDocumentId,
        'doc_type' => $normalizedDocType,
        'document_type' => $normalizedDocType === 'lab_results' ? 'lab_pdf' : $normalizedDocType,
        'patient_id' => isset($extras['patient_id']) && is_numeric($extras['patient_id']) ? (int) $extras['patient_id'] : null,
        'page_or_section' => $pageOrSection,
        'field_or_chunk_id' => $fieldOrChunkId,
        'quote_or_value' => $quoteOrValue,
        'confidence' => round($confidence, 4),
        'review_status' => trim((string) ($extras['review_status'] ?? 'pending_clinician_review')) ?: 'pending_clinician_review',
        'resource_type' => trim((string) ($extras['resource_type'] ?? '')),
        'fhir_document_reference_id' => trim((string) ($extras['fhir_document_reference_id'] ?? '')),
        'fhir_binary_id' => trim((string) ($extras['fhir_binary_id'] ?? '')),
    ];
    if (is_array($extras['bounding_box'] ?? null)) {
        $citation['bounding_box'] = $extras['bounding_box'];
    }

    return array_filter($citation, static fn($value) => $value !== null && $value !== '');
}

function aiCopilotEnhanceLabFactsForReview(array $facts, ?int $sourceDocumentId, string $collectedDate = '', string $resultedDate = ''): array
{
    $enhanced = [];
    foreach ($facts as $index => $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $confidence = isset($fact['confidence']) && is_numeric($fact['confidence']) ? (float) $fact['confidence'] : 0.9;
        $quote = aiCopilotLabPdfNormalizeWhitespace((string) ($fact['source_quote_or_value'] ?? $fact['value'] ?? ''));
        $pageNumber = isset($fact['page_number']) && is_numeric($fact['page_number']) ? (int) $fact['page_number'] : null;
        $pageOrSection = $pageNumber !== null ? 'page_' . $pageNumber : 'page_unknown';
        $fieldId = 'lab_fact_' . $index;

        $enhanced[] = [
            'key' => (string) ($fact['key'] ?? ''),
            'name' => (string) ($fact['name'] ?? ''),
            'label' => (string) ($fact['label'] ?? $fact['name'] ?? ''),
            'value' => (string) ($fact['value'] ?? ''),
            'numeric_value' => $fact['numeric_value'] ?? null,
            'unit' => (string) ($fact['unit'] ?? ''),
            'reference_range' => (string) ($fact['reference_range'] ?? ''),
            'flag' => (string) ($fact['flag'] ?? $fact['interpretation'] ?? 'unknown'),
            'interpretation' => (string) ($fact['interpretation'] ?? $fact['flag'] ?? 'unknown'),
            'source_label' => (string) ($fact['source_label'] ?? 'Uploaded Lab PDF'),
            'test_name' => (string) ($fact['name'] ?? ''),
            'abnormal_flag' => (string) ($fact['flag'] ?? $fact['interpretation'] ?? 'unknown'),
            'collected_date' => $collectedDate,
            'resulted_date' => $resultedDate,
            'source_document_id' => $sourceDocumentId,
            'page_number' => $pageNumber,
            'source_quote_or_value' => $quote,
            'confidence' => round($confidence, 4),
            'proposed_fhir_resource_type' => (string) ($fact['proposed_fhir_resource_type'] ?? 'Observation'),
            'review_status' => 'pending_clinician_review',
            'field_or_chunk_id' => $fieldId,
            'source_link' => aiCopilotBuildUploadedDocumentSourceLink('lab_pdf', $sourceDocumentId, $pageOrSection, $fieldId, $quote, $confidence, [
                'resource_type' => (string) ($fact['proposed_fhir_resource_type'] ?? 'Observation'),
                'bounding_box' => $fact['bounding_box'] ?? null,
            ]),
        ];
    }

    return $enhanced;
}

function aiCopilotEnhanceIntakeFactsForReview(array $facts, ?int $sourceDocumentId): array
{
    $enhanced = [];
    foreach ($facts as $index => $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $confidence = isset($fact['confidence']) && is_numeric($fact['confidence']) ? (float) $fact['confidence'] : 0.9;
        $quote = aiCopilotLabPdfNormalizeWhitespace((string) ($fact['source_quote_or_value'] ?? $fact['value'] ?? ''));
        $fieldName = (string) ($fact['key'] ?? $fact['name'] ?? ('intake_field_' . $index));
        $target = 'QuestionnaireResponse';
        if ($fieldName === 'medicationAdherence') {
            $target = 'MedicationStatement';
        } elseif ($fieldName === 'allergies') {
            $target = 'AllergyIntolerance';
        } elseif ($fieldName === 'insuranceUpdate') {
            $target = 'Coverage';
        } elseif ($fieldName === 'carePreferences') {
            $target = 'PatientPreference';
        }

        $enhanced[] = [
            'key' => $fieldName,
            'name' => (string) ($fact['name'] ?? ''),
            'label' => (string) ($fact['label'] ?? $fact['name'] ?? ''),
            'value' => (string) ($fact['value'] ?? ''),
            'interpretation' => (string) ($fact['interpretation'] ?? ''),
            'source_label' => (string) ($fact['source_label'] ?? 'Uploaded Intake Form'),
            'field_name' => $fieldName,
            'normalized_value' => (string) ($fact['value'] ?? ''),
            'source_document_id' => $sourceDocumentId,
            'page_or_section' => 'intake_form',
            'source_quote_or_value' => $quote,
            'confidence' => round($confidence, 4),
            'proposed_openemr_or_fhir_target' => $target,
            'review_status' => 'pending_clinician_review',
            'field_or_chunk_id' => $fieldName,
            'source_link' => aiCopilotBuildUploadedDocumentSourceLink('intake_form', $sourceDocumentId, 'intake_form', $fieldName, $quote, $confidence, [
                'resource_type' => $target,
            ]),
        ];
    }

    return $enhanced;
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
            'display_file_name' => (string) ($item['displayFileName'] ?? $metadata['displayFileName'] ?? $item['fileName'] ?? $item['file_name'] ?? ''),
            'document_type' => (string) ($metadata['documentType'] ?? ''),
            'source_id' => (string) ($metadata['sourceId'] ?? ''),
            'source_document_id' => isset($metadata['sourceDocumentId']) && is_numeric($metadata['sourceDocumentId']) ? (int) $metadata['sourceDocumentId'] : (isset($item['sourceDocumentId']) && is_numeric($item['sourceDocumentId']) ? (int) $item['sourceDocumentId'] : null),
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
    $documentMetadata = is_array($input['document_metadata'] ?? null) ? $input['document_metadata'] : [];
    $sourceMetadata = is_array($input['source_metadata'] ?? null) ? $input['source_metadata'] : [];
    $legacySourceType = (string) ($sourceMetadata['source_type'] ?? ($documentMetadata['source_type'] ?? 'lab_pdf'));
    $documentType = aiCopilotAttachmentNormalizeDocumentClass(
        (string) ($documentMetadata['document_type'] ?? $legacySourceType),
        (string) ($documentMetadata['title'] ?? $sourceMetadata['file_name'] ?? ''),
        (string) ($input['extracted_text_preview'] ?? '')
    );
    $originalFileName = (string) ($documentMetadata['original_file_name'] ?? $sourceMetadata['original_file_name'] ?? $documentMetadata['title'] ?? $sourceMetadata['file_name'] ?? '');
    $displayFileName = (string) ($documentMetadata['display_file_name'] ?? $sourceMetadata['display_file_name'] ?? aiCopilotAttachmentBuildDisplayFileName($originalFileName, $documentType, (string) ($documentMetadata['patient_name'] ?? '')));
    $sourceId = (string) ($sourceMetadata['source_id'] ?? $documentMetadata['source_id'] ?? aiCopilotAttachmentBuildSourceId((string) ($documentMetadata['patient_key'] ?? ''), $documentType, $originalFileName, (string) ($documentMetadata['uploaded_at'] ?? $sourceMetadata['uploaded_at'] ?? gmdate('c'))));
    $toolOutput = [
        'tool' => AI_COPILOT_LAB_PDF_TOOL_NAME,
        'status' => (string) ($input['status'] ?? 'ok'),
        'ingestion_status' => (string) ($input['ingestion_status'] ?? 'ingested'),
        'safe_message' => (string) ($input['safe_message'] ?? ''),
        'extraction_method' => (string) ($input['extraction_method'] ?? 'pdf_text'),
        'extracted_text_preview' => (string) ($input['extracted_text_preview'] ?? ''),
        'extracted_text_length' => isset($input['extracted_text_length']) && is_numeric($input['extracted_text_length'])
            ? (int) $input['extracted_text_length']
            : strlen((string) ($input['extracted_text_preview'] ?? '')),
        'text_extraction_status' => (string) ($input['text_extraction_status'] ?? (($input['document_guard']['text_extraction_status'] ?? '') !== '' ? $input['document_guard']['text_extraction_status'] : 'failed')),
        'medical_validation_status' => (string) ($input['medical_validation_status'] ?? (($input['document_guard']['medical_validation_status'] ?? '') !== '' ? $input['document_guard']['medical_validation_status'] : 'review_required')),
        'chart_write_status' => (string) ($input['chart_write_status'] ?? (($input['document_guard']['chart_write_status'] ?? '') !== '' ? $input['document_guard']['chart_write_status'] : 'requires_clinician_review')),
        'page_count' => isset($input['page_count']) && is_numeric($input['page_count']) ? (int) $input['page_count'] : 0,
        'raw_bytes_detected' => !empty($input['raw_bytes_detected']),
        'number_of_chunks' => isset($input['number_of_chunks']) && is_numeric($input['number_of_chunks']) ? (int) $input['number_of_chunks'] : 0,
        'document_metadata' => $documentMetadata,
        'source_metadata' => $sourceMetadata,
        'extracted_facts' => is_array($input['extracted_facts'] ?? null) ? $input['extracted_facts'] : [],
        'abnormal_findings' => is_array($input['abnormal_findings'] ?? null) ? $input['abnormal_findings'] : [],
        'missing_data' => is_array($input['missing_data'] ?? null) ? $input['missing_data'] : [],
        'missing_data_flags' => is_array($input['missing_data_flags'] ?? null) ? $input['missing_data_flags'] : [],
        'intake_fields' => is_array($input['intake_fields'] ?? null) ? $input['intake_fields'] : [],
        'retrieval' => is_array($input['retrieval'] ?? null) ? $input['retrieval'] : [],
        'vectorized_result' => is_array($input['vectorized_result'] ?? null) ? $input['vectorized_result'] : [],
        'document_guard' => is_array($input['document_guard'] ?? null) ? $input['document_guard'] : [],
        'source_citations' => is_array($input['source_citations'] ?? null) ? $input['source_citations'] : [],
        'source_links' => is_array($input['source_links'] ?? null) ? $input['source_links'] : [],
        'pending_review_facts' => is_array($input['pending_review_facts'] ?? null) ? $input['pending_review_facts'] : [],
        'review_queue' => is_array($input['review_queue'] ?? null) ? $input['review_queue'] : [],
        'strict_extraction' => is_array($input['strict_extraction'] ?? null) ? $input['strict_extraction'] : [],
        'schema_validation' => is_array($input['schema_validation'] ?? null) ? $input['schema_validation'] : [],
        'safety' => [
            'draft_only' => true,
            'review_required' => true,
        ],
        'safety_metadata' => aiCopilotLabPdfBuildSafetyMetadata([
            'prompt_injection_matches' => $input['prompt_injection_matches'] ?? [],
            'ocr_required' => !empty($input['ocr_required']),
        ]),
    ];

    $toolOutput['document_metadata']['document_type'] = $documentType;
    $toolOutput['document_metadata']['original_file_name'] = $originalFileName;
    $toolOutput['document_metadata']['display_file_name'] = $displayFileName;
    $toolOutput['document_metadata']['source_id'] = $sourceId;
    $toolOutput['document_metadata']['patient_id'] = isset($input['document_metadata']['patient_id']) && is_numeric($input['document_metadata']['patient_id']) ? (int) $input['document_metadata']['patient_id'] : null;
    $toolOutput['document_metadata']['source_document_id'] = isset($input['document_metadata']['source_document_id']) && is_numeric($input['document_metadata']['source_document_id']) ? (int) $input['document_metadata']['source_document_id'] : null;
    $toolOutput['document_metadata']['openemr_document_id'] = isset($input['document_metadata']['openemr_document_id']) && is_numeric($input['document_metadata']['openemr_document_id']) ? (int) $input['document_metadata']['openemr_document_id'] : null;
    $toolOutput['document_metadata']['fhir_document_reference_id'] = (string) ($input['document_metadata']['fhir_document_reference_id'] ?? '');
    $toolOutput['document_metadata']['fhir_binary_id'] = (string) ($input['document_metadata']['fhir_binary_id'] ?? '');
    $toolOutput['document_metadata']['review_status'] = (string) ($input['document_metadata']['review_status'] ?? 'pending_clinician_review');
    $toolOutput['document_metadata']['file_hash'] = (string) ($input['document_metadata']['file_hash'] ?? '');
    $toolOutput['document_metadata']['uploader_role'] = (string) ($input['document_metadata']['uploader_role'] ?? '');
    $toolOutput['document_metadata']['uploader_user'] = (string) ($input['document_metadata']['uploader_user'] ?? '');
    if (!isset($toolOutput['source_metadata']['source_type'])) {
        $toolOutput['source_metadata']['source_type'] = aiCopilotAttachmentLegacySourceType($documentType);
    }
    if (!isset($toolOutput['source_metadata']['source_label'])) {
        $toolOutput['source_metadata']['source_label'] = aiCopilotAttachmentSourceLabel($toolOutput['source_metadata']['source_type']);
    }
    $toolOutput['source_metadata']['document_type'] = $documentType;
    $toolOutput['source_metadata']['original_file_name'] = $originalFileName;
    $toolOutput['source_metadata']['display_file_name'] = $displayFileName;
    $toolOutput['source_metadata']['source_id'] = $sourceId;
    $toolOutput['source_metadata']['source_document_id'] = isset($input['source_metadata']['source_document_id']) && is_numeric($input['source_metadata']['source_document_id']) ? (int) $input['source_metadata']['source_document_id'] : null;
    $toolOutput['source_coverage'] = is_array($input['source_coverage'] ?? null) ? $input['source_coverage'] : [];
    $toolOutput['document_summaries'] = is_array($input['document_summaries'] ?? null) ? $input['document_summaries'] : [];

    return $toolOutput;
}

function aiCopilotLabPdfRetrieveRelevantChunks(array $options): array
{
    $patientKey = (string) ($options['patient_key'] ?? '');
    $prompt = (string) ($options['prompt'] ?? '');
    $fileName = (string) ($options['file_name'] ?? '');
    $sourceType = (string) ($options['source_type'] ?? '');
    $documentType = (string) ($options['document_type'] ?? '');
    $sourceId = (string) ($options['source_id'] ?? '');
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
        'source_type' => $sourceType,
        'document_type' => $documentType,
        'source_id' => $sourceId,
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
            'display_file_name' => (string) ($record['displayFileName'] ?? $record['metadata']['displayFileName'] ?? $record['fileName'] ?? ''),
            'original_file_name' => (string) ($record['metadata']['originalFileName'] ?? $record['fileName'] ?? ''),
            'source_id' => (string) ($record['metadata']['sourceId'] ?? ''),
            'source_document_id' => isset($record['metadata']['sourceDocumentId']) && is_numeric($record['metadata']['sourceDocumentId'])
                ? (int) $record['metadata']['sourceDocumentId']
                : (isset($record['sourceDocumentId']) && is_numeric($record['sourceDocumentId']) ? (int) $record['sourceDocumentId'] : null),
            'patient_id' => isset($record['patientId']) && is_numeric($record['patientId']) ? (int) $record['patientId'] : null,
            'document_type' => (string) ($record['metadata']['documentType'] ?? ''),
            'source_type' => (string) ($record['metadata']['sourceType'] ?? ''),
            'score' => round((float) ($record['score'] ?? 0), 6),
            'source_page' => $record['metadata']['sourcePage'] ?? null,
            'uploaded_at' => (string) ($record['metadata']['uploadedAt'] ?? ''),
            'extraction_method' => (string) ($record['metadata']['extractionMethod'] ?? 'pdf_text'),
            'chunk_index' => isset($record['metadata']['chunkIndex']) && is_numeric($record['metadata']['chunkIndex']) ? (int) $record['metadata']['chunkIndex'] : null,
            'ingestion_origin' => (string) ($record['metadata']['ingestionOrigin'] ?? 'uploaded_file'),
            'review_status' => (string) ($record['metadata']['reviewStatus'] ?? 'pending_clinician_review'),
            'seeded_demo' => !empty($record['metadata']['seededDemo']),
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

    return preg_match('/\b(lab|labs|lab report|pdf|a1c|ldl|creatinine|egfr|abnormal|collection time|ordering provider|reason for visit|current concerns|medication adherence|medication notes|allergies|insurance update|care preferences|preferred contact|intake form|questionnaire|source|missing data|uncertain|summarize this lab report)\b/i', $prompt) === 1;
}

function aiCopilotLabPdfPromptExplicitlyRequestsChartContext(string $prompt): bool
{
    return preg_match('/\b(chart context|patient chart|chart only|use the chart|from the chart|visit history|core chart data)\b/i', $prompt) === 1;
}

function aiCopilotLabPdfPromptTargetsUploadedEvidence(string $prompt): bool
{
    return preg_match('/\b(uploaded evidence|uploaded lab evidence|uploaded intake form|uploaded lab|uploaded pdf|uploaded documents|attached pdf|attached lab|attached intake form|lab pdf|lab results|lab report|intake form|source pdf)\b/i', $prompt) === 1;
}

function aiCopilotLabPdfRequestedDocumentTypes(string $prompt, string $mode, array $existingToolOutput = []): array
{
    $requestedTypes = [];
    if (preg_match('/\b(lab pdf|lab results|uploaded lab|lab report|a1c|glucose|ldl|creatinine|egfr|abnormal labs?)\b/i', $prompt) === 1) {
        $requestedTypes[] = 'lab_results';
    }
    if (preg_match('/\b(intake|intake form|questionnaire|reason for visit|current concerns|medication adherence|insurance update|care preferences)\b/i', $prompt) === 1) {
        $requestedTypes[] = 'intake_form';
    }

    $existingCoverage = is_array($existingToolOutput['source_coverage'] ?? null) ? $existingToolOutput['source_coverage'] : [];
    foreach (($existingCoverage['requested_document_types'] ?? []) as $requestedType) {
        $normalizedType = aiCopilotAttachmentNormalizeDocumentClass((string) $requestedType);
        if ($normalizedType !== 'non_medical') {
            $requestedTypes[] = $normalizedType;
        }
    }

    $existingSourceType = aiCopilotLabPdfNormalizeWhitespace((string) ($existingToolOutput['source_metadata']['source_type'] ?? $existingToolOutput['document_metadata']['document_type'] ?? ''));
    if ($requestedTypes === [] && aiCopilotAttachmentIsIntakeDocumentType($existingSourceType)) {
        $requestedTypes[] = 'intake_form';
    } elseif ($requestedTypes === [] && aiCopilotAttachmentIsLabDocumentType($existingSourceType)) {
        $requestedTypes[] = 'lab_results';
    }

    if ($requestedTypes === [] && $mode === 'lab_pdf_ingestion' && aiCopilotLabPdfPromptTargetsUploadedEvidence($prompt)) {
        $requestedTypes[] = 'lab_results';
    }

    return array_values(array_unique(array_filter($requestedTypes, static fn($item) => $item !== '')));
}

function aiCopilotAttachmentRequestedDocumentLabel(string $documentType): string
{
    return match ($documentType) {
        'lab_results' => 'an uploaded lab results PDF',
        'intake_form' => 'the intake form',
        'medical_document' => 'an uploaded medical document',
        default => 'an uploaded document',
    };
}

function aiCopilotAttachmentBuildMissingRequestedSourceMessage(array $matchedSources, array $missingDocumentTypes, string $patientName = ''): string
{
    $patientName = trim($patientName);
    if ($missingDocumentTypes === []) {
        return '';
    }

    $matchedLabels = [];
    foreach ($matchedSources as $source) {
        $matchedType = (string) ($source['document_type'] ?? '');
        if ($matchedType === 'lab_results') {
            $matchedLabels[] = 'the uploaded lab results PDF';
        } elseif ($matchedType === 'intake_form') {
            $matchedLabels[] = 'the intake form';
        }
    }
    $matchedLabels = array_values(array_unique($matchedLabels));
    $missingLabels = array_values(array_unique(array_map('aiCopilotAttachmentRequestedDocumentLabel', $missingDocumentTypes)));

    if (count($matchedLabels) === 1 && count($missingLabels) === 1) {
        return 'I found ' . $matchedLabels[0] . ', but I do not see ' . $missingLabels[0] . ' for ' . ($patientName !== '' ? $patientName : 'the selected demo patient') . '.';
    }

    return 'I could not find all requested uploaded document types for ' . ($patientName !== '' ? $patientName : 'the selected demo patient') . '. Missing: ' . implode(', ', $missingLabels) . '.';
}

function aiCopilotLabPdfRequestedSourceType(string $prompt, string $mode, array $existingToolOutput = []): string
{
    $existingSourceType = aiCopilotLabPdfNormalizeWhitespace((string) ($existingToolOutput['source_metadata']['source_type'] ?? $existingToolOutput['document_metadata']['document_type'] ?? ''));
    if (in_array($existingSourceType, ['lab_pdf', 'intake_form'], true)) {
        return $existingSourceType;
    }

    if (preg_match('/\b(reason for visit|current concerns|medication adherence|medication notes|allergies|insurance update|care preferences|preferred contact|intake form|questionnaire)\b/i', $prompt) === 1) {
        return 'intake_form';
    }

    if (
        $mode === 'lab_pdf_ingestion'
        || aiCopilotLabPdfPromptTargetsUploadedEvidence($prompt)
        || preg_match('/\b(lab|labs|a1c|glucose|ldl|hdl|creatinine|egfr|wbc|abnormal)\b/i', $prompt) === 1
    ) {
        return 'lab_pdf';
    }

    return '';
}

function aiCopilotLabPdfHasUploadedEvidence(string $patientKey): bool
{
    if ($patientKey === '') {
        return false;
    }

    $counts = aiCopilotLabPdfCountVectorRecords([
        'patient_key' => $patientKey,
        'source_type' => 'lab_pdf',
    ]);

    return (int) ($counts['record_count'] ?? 0) > 0;
}

function aiCopilotLabPdfSummarizeRetrievedDocument(array $document, array $retrieval): array
{
    $documentType = aiCopilotAttachmentNormalizeDocumentClass((string) ($document['document_type'] ?? $document['source_type'] ?? ''));
    $chunks = array_values(array_filter($retrieval['chunks'] ?? [], 'is_array'));
    $chunkIds = array_values(array_filter(array_map(static fn($item) => is_array($item) ? (string) ($item['id'] ?? '') : '', $chunks), static fn($item) => $item !== ''));
    $combinedText = implode("\n", array_values(array_filter(array_map(static fn($chunk) => is_array($chunk) ? (string) ($chunk['chunk_text'] ?? '') : '', $chunks))));

    $summary = [
        'source_id' => (string) ($document['source_id'] ?? ''),
        'document_type' => $documentType,
        'source_type' => (string) ($document['source_type'] ?? aiCopilotAttachmentLegacySourceType($documentType)),
        'original_file_name' => (string) ($document['original_file_name'] ?? ''),
        'display_file_name' => (string) ($document['display_file_name'] ?? $document['original_file_name'] ?? ''),
        'uploaded_at' => (string) ($document['uploaded_at'] ?? ''),
        'extraction_method' => (string) ($document['extraction_method'] ?? 'pdf_text'),
        'chunk_ids' => $chunkIds,
        'chunk_count' => count($chunks),
        'seeded_demo' => !empty($document['seeded_demo']),
        'ingestion_origin' => (string) ($document['ingestion_origin'] ?? 'uploaded_file'),
        'retrieval' => $retrieval,
        'extracted_facts' => [],
        'abnormal_findings' => [],
        'missing_data' => [],
        'intake_fields' => [],
    ];

    if ($documentType === 'intake_form') {
        $intakeSummary = aiCopilotIntakeExtractFields($combinedText);
        $summary['extracted_facts'] = array_slice($intakeSummary['facts'] ?? [], 0, 20);
        $summary['missing_data'] = array_values(array_unique($intakeSummary['missing'] ?? []));
        $summary['intake_fields'] = aiCopilotBuildIntakeFieldsPayload($intakeSummary['fields'] ?? [], [
            'missing' => $summary['missing_data'],
            'source_file' => (string) ($summary['display_file_name'] !== '' ? $summary['display_file_name'] : $summary['original_file_name']),
            'source_chunk_ids' => $chunkIds,
            'uploaded_timestamp' => $summary['uploaded_at'],
            'ingestion_status' => 'retrieved',
        ]);
        return $summary;
    }

    $factSummary = aiCopilotLabPdfExtractFacts($combinedText, [
        'file_name' => (string) ($summary['display_file_name'] !== '' ? $summary['display_file_name'] : $summary['original_file_name']),
    ]);
    $summary['extracted_facts'] = array_slice($factSummary['facts'] ?? [], 0, 20);
    $summary['abnormal_findings'] = array_slice($factSummary['abnormal'] ?? [], 0, 12);
    $summary['missing_data'] = array_values(array_unique($factSummary['missing'] ?? []));
    return $summary;
}

function aiCopilotLabPdfRetrieveRequestedDocumentCoverage(array $options): array
{
    $patientKey = (string) ($options['patient_key'] ?? '');
    $patientName = (string) ($options['patient_name'] ?? '');
    $prompt = (string) ($options['prompt'] ?? '');
    $requestedDocumentTypes = array_values(array_unique(array_filter(array_map(static fn($item) => aiCopilotAttachmentNormalizeDocumentClass((string) $item), is_array($options['requested_document_types'] ?? null) ? $options['requested_document_types'] : []), static fn($item) => $item !== 'non_medical')));
    $preferUploadedOnly = !empty($options['prefer_uploaded_only']);
    $limitPerDocument = isset($options['limit_per_document']) && is_numeric($options['limit_per_document']) ? max(1, (int) $options['limit_per_document']) : 2;

    $matchedSources = [];
    $documentSummaries = [];
    $missingRequestedTypes = [];
    $chunks = [];
    $seenChunkIds = [];

    foreach ($requestedDocumentTypes as $requestedType) {
        $documents = aiCopilotLabPdfListSourceDocuments([
            'patient_key' => $patientKey,
            'document_type' => $requestedType,
            'ingestion_origin' => $preferUploadedOnly ? 'uploaded_file' : '',
        ]);

        if ($documents === [] && !$preferUploadedOnly) {
            $documents = aiCopilotLabPdfListSourceDocuments([
                'patient_key' => $patientKey,
                'document_type' => $requestedType,
            ]);
        }

        if ($documents === []) {
            $missingRequestedTypes[] = $requestedType;
            continue;
        }

        $document = $documents[0];
        $matchedSources[] = $document;
        $documentRetrieval = aiCopilotLabPdfRetrieveRelevantChunks([
            'patient_key' => $patientKey,
            'prompt' => $prompt !== '' ? $prompt : ((string) ($document['display_file_name'] ?? $document['original_file_name'] ?? '')),
            'source_id' => (string) ($document['source_id'] ?? ''),
            'document_type' => $requestedType,
            'limit' => $limitPerDocument,
        ]);
        $documentSummary = aiCopilotLabPdfSummarizeRetrievedDocument($document, $documentRetrieval);
        $documentSummaries[] = $documentSummary;

        foreach (($documentRetrieval['chunks'] ?? []) as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $chunkId = (string) ($chunk['id'] ?? '');
            if ($chunkId !== '' && isset($seenChunkIds[$chunkId])) {
                continue;
            }
            if ($chunkId !== '') {
                $seenChunkIds[$chunkId] = true;
            }
            $chunks[] = $chunk;
        }
    }

    return [
        'chunks' => $chunks,
        'chunk_ids' => array_values(array_filter(array_map(static fn($item) => is_array($item) ? (string) ($item['id'] ?? '') : '', $chunks), static fn($item) => $item !== '')),
        'chunk_count' => count($chunks),
        'requested_document_types' => $requestedDocumentTypes,
        'matched_sources' => $matchedSources,
        'document_summaries' => $documentSummaries,
        'missing_requested_document_types' => array_values(array_unique($missingRequestedTypes)),
        'missing_requested_sources_message' => aiCopilotAttachmentBuildMissingRequestedSourceMessage($matchedSources, $missingRequestedTypes, $patientName),
        'all_requested_document_types_attempted' => $requestedDocumentTypes !== [],
    ];
}

function aiCopilotLabPdfBuildToolOutputFromRetrieval(array $retrieval, array $options = []): array
{
    $requestedDocumentTypes = array_values(array_unique(array_filter(array_map(static fn($item) => aiCopilotAttachmentNormalizeDocumentClass((string) $item), $retrieval['requested_document_types'] ?? []), static fn($item) => $item !== 'non_medical')));
    $matchedSources = array_values(array_filter($retrieval['matched_sources'] ?? [], 'is_array'));
    $documentSummaries = array_values(array_filter($retrieval['document_summaries'] ?? [], 'is_array'));
    $patientKey = (string) ($options['patient_key'] ?? '');
    $patientName = (string) ($options['patient_name'] ?? '');
    $requestId = (string) ($options['request_id'] ?? '');

    if ($documentSummaries !== [] || $matchedSources !== []) {
        $sourceItems = $matchedSources !== [] ? $matchedSources : $documentSummaries;
        $primarySource = is_array($sourceItems[0] ?? null) ? $sourceItems[0] : [];
        $isMultiDocument = count($sourceItems) > 1 || count($requestedDocumentTypes) > 1;
        $labFacts = [];
        $abnormalFindings = [];
        $missingData = [];
        $intakeFields = [];

        foreach ($documentSummaries as $summary) {
            if ((string) ($summary['document_type'] ?? '') !== 'intake_form') {
                foreach (($summary['extracted_facts'] ?? []) as $fact) {
                    if (is_array($fact)) {
                        $labFacts[] = $fact;
                    }
                }
            }
            $abnormalFindings = array_merge($abnormalFindings, is_array($summary['abnormal_findings'] ?? null) ? $summary['abnormal_findings'] : []);
            $missingData = array_merge($missingData, is_array($summary['missing_data'] ?? null) ? $summary['missing_data'] : []);
            if ($intakeFields === [] && !empty($summary['intake_fields'])) {
                $intakeFields = is_array($summary['intake_fields']) ? $summary['intake_fields'] : [];
            }
        }

        $sourceCoverage = [
            'requested_document_types' => $requestedDocumentTypes,
            'matched_sources' => array_map(static function (array $source): array {
                return [
                    'source_id' => (string) ($source['source_id'] ?? ''),
                    'document_type' => (string) ($source['document_type'] ?? ''),
                    'source_type' => (string) ($source['source_type'] ?? ''),
                    'original_file_name' => (string) ($source['original_file_name'] ?? ''),
                    'display_file_name' => (string) ($source['display_file_name'] ?? ''),
                    'uploaded_at' => (string) ($source['uploaded_at'] ?? ''),
                    'extraction_method' => (string) ($source['extraction_method'] ?? 'pdf_text'),
                    'chunk_ids' => array_values(array_filter(array_map('strval', $source['chunk_ids'] ?? []), static fn($item) => trim($item) !== '')),
                    'chunk_count' => isset($source['chunk_count']) && is_numeric($source['chunk_count']) ? (int) $source['chunk_count'] : count($source['chunk_ids'] ?? []),
                    'seeded_demo' => !empty($source['seeded_demo']),
                    'ingestion_origin' => (string) ($source['ingestion_origin'] ?? 'uploaded_file'),
                ];
            }, $matchedSources),
            'missing_requested_document_types' => array_values(array_unique(array_filter(array_map('strval', $retrieval['missing_requested_document_types'] ?? []), static fn($item) => trim($item) !== ''))),
            'missing_requested_sources_message' => (string) ($retrieval['missing_requested_sources_message'] ?? ''),
            'all_requested_document_types_attempted' => !empty($retrieval['all_requested_document_types_attempted']),
        ];

        return aiCopilotLabPdfBuildClientToolOutput([
            'status' => 'retrieved',
            'ingestion_status' => 'retrieved',
            'safe_message' => $isMultiDocument
                ? 'Retrieved uploaded document context across the requested source documents for clinician review.'
                : ('Retrieved previously ingested ' . (($primarySource['document_type'] ?? '') === 'intake_form' ? 'intake form' : 'lab results PDF') . ' context for clinician review.'),
            'extraction_method' => (string) ($primarySource['extraction_method'] ?? 'pdf_text'),
            'extracted_text_preview' => aiCopilotLabPdfPreview(implode("\n", array_values(array_filter(array_map(static fn($chunk) => is_array($chunk) ? (string) ($chunk['chunk_text'] ?? '') : '', $retrieval['chunks'] ?? []))))),
            'number_of_chunks' => count($retrieval['chunks'] ?? []),
            'document_metadata' => [
                'title' => $isMultiDocument
                    ? trim(($patientName !== '' ? $patientName . ' ' : '') . 'Uploaded Document Set')
                    : (string) (($primarySource['display_file_name'] ?? $primarySource['original_file_name'] ?? 'Uploaded Document.pdf')),
                'mime_type' => 'application/pdf',
                'size' => null,
                'document_type' => $isMultiDocument ? 'combined_documents' : (string) ($primarySource['document_type'] ?? 'lab_results'),
                'seeded_demo' => !empty($primarySource['seeded_demo']),
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'uploaded_at' => (string) ($primarySource['uploaded_at'] ?? ''),
                'original_file_name' => (string) ($primarySource['original_file_name'] ?? ''),
                'display_file_name' => (string) (($primarySource['display_file_name'] ?? $primarySource['original_file_name'] ?? '')),
                'source_id' => (string) ($primarySource['source_id'] ?? ''),
                'requested_document_types' => $requestedDocumentTypes,
            ],
            'source_metadata' => [
                'file_name' => (string) (($primarySource['display_file_name'] ?? $primarySource['original_file_name'] ?? '')),
                'original_file_name' => (string) ($primarySource['original_file_name'] ?? ''),
                'display_file_name' => (string) (($primarySource['display_file_name'] ?? $primarySource['original_file_name'] ?? '')),
                'uploaded_at' => (string) ($primarySource['uploaded_at'] ?? ''),
                'source_type' => $isMultiDocument ? 'multi_document' : (string) ($primarySource['source_type'] ?? aiCopilotAttachmentLegacySourceType((string) ($primarySource['document_type'] ?? 'lab_results'))),
                'document_type' => $isMultiDocument ? 'combined_documents' : (string) ($primarySource['document_type'] ?? 'lab_results'),
                'source_label' => $isMultiDocument ? 'Uploaded documents' : aiCopilotAttachmentSourceLabel((string) ($primarySource['source_type'] ?? aiCopilotAttachmentLegacySourceType((string) ($primarySource['document_type'] ?? 'lab_results')))),
                'chunk_count' => count($retrieval['chunks'] ?? []),
                'request_id' => $requestId,
                'source_id' => (string) ($primarySource['source_id'] ?? ''),
            ],
            'extracted_facts' => array_slice($labFacts, 0, 20),
            'abnormal_findings' => array_slice(array_values(array_unique(array_map('strval', $abnormalFindings))), 0, 12),
            'missing_data' => array_values(array_unique(array_filter(array_map('strval', array_merge($missingData, [(string) ($retrieval['missing_requested_sources_message'] ?? '')])), static fn($item) => trim($item) !== ''))),
            'missing_data_flags' => array_values(array_unique(array_filter(array_map('strval', array_merge($missingData, [(string) ($retrieval['missing_requested_sources_message'] ?? '')])), static fn($item) => trim($item) !== ''))),
            'intake_fields' => $intakeFields,
            'retrieval' => [
                'chunk_ids' => $retrieval['chunk_ids'] ?? [],
                'chunk_count' => $retrieval['chunk_count'] ?? 0,
                'chunks' => $retrieval['chunks'] ?? [],
            ],
            'vectorized_result' => aiCopilotLabPdfBuildVectorizedResult($retrieval['chunks'] ?? []),
            'source_coverage' => $sourceCoverage,
            'document_summaries' => $documentSummaries,
        ]);
    }

    $combinedText = implode("\n", array_values(array_filter(array_map(static fn($chunk) => is_array($chunk) ? (string) ($chunk['chunk_text'] ?? '') : '', $retrieval['chunks'] ?? []))));
    $firstChunk = is_array($retrieval['chunks'][0] ?? null) ? $retrieval['chunks'][0] : [];
    $legacyDocumentType = aiCopilotAttachmentClassifyDocumentType(
        (string) ($options['file_name'] ?? ($firstChunk['original_file_name'] ?? $firstChunk['file_name'] ?? '')),
        $combinedText
    );
    $documentType = aiCopilotAttachmentNormalizeDocumentClass(
        (string) ($firstChunk['document_type'] ?? $legacyDocumentType),
        (string) ($options['file_name'] ?? ($firstChunk['original_file_name'] ?? $firstChunk['file_name'] ?? '')),
        $combinedText
    );
    $documentTitle = (string) ($options['display_file_name'] ?? ($firstChunk['display_file_name'] ?? $options['file_name'] ?? ($firstChunk['file_name'] ?? aiCopilotAttachmentSourceTitle($legacyDocumentType))));
    $originalFileName = (string) ($options['file_name'] ?? ($firstChunk['original_file_name'] ?? $firstChunk['file_name'] ?? $documentTitle));
    $uploadedAt = (string) ($options['uploaded_at'] ?? ($firstChunk['uploaded_at'] ?? ''));
    $extractionMethod = (string) ($firstChunk['extraction_method'] ?? 'pdf_text');
    $sourceId = (string) ($options['source_id'] ?? ($firstChunk['source_id'] ?? aiCopilotAttachmentBuildSourceId((string) ($options['patient_key'] ?? ''), $documentType, $originalFileName, $uploadedAt)));

    if ($documentType === 'intake_form') {
        $intakeSummary = aiCopilotIntakeExtractFields($combinedText);
        $retrievalChunkIds = $retrieval['chunk_ids'] ?? [];
        return aiCopilotLabPdfBuildClientToolOutput([
            'status' => 'retrieved',
            'ingestion_status' => 'retrieved',
            'safe_message' => 'Retrieved previously ingested intake form context for clinician review.',
            'extraction_method' => $extractionMethod,
            'extracted_text_preview' => aiCopilotLabPdfPreview($combinedText),
            'extracted_text_length' => strlen($combinedText),
            'number_of_chunks' => isset($options['chunk_count']) && is_numeric($options['chunk_count']) ? (int) $options['chunk_count'] : count($retrieval['chunks'] ?? []),
            'document_metadata' => [
                'title' => $documentTitle,
                'mime_type' => 'application/pdf',
                'size' => null,
                'document_type' => 'intake_form',
                'seeded_demo' => !empty($options['seeded_demo']),
                'patient_key' => (string) ($options['patient_key'] ?? ''),
                'patient_name' => (string) ($options['patient_name'] ?? ''),
                'uploaded_at' => $uploadedAt,
                'original_file_name' => $originalFileName,
                'display_file_name' => $documentTitle,
                'source_id' => $sourceId,
            ],
            'source_metadata' => [
                'file_name' => $documentTitle,
                'original_file_name' => $originalFileName,
                'display_file_name' => $documentTitle,
                'uploaded_at' => $uploadedAt,
                'source_type' => 'intake_form',
                'document_type' => 'intake_form',
                'source_label' => 'Uploaded intake form',
                'chunk_count' => count($retrieval['chunks'] ?? []),
                'request_id' => (string) ($options['request_id'] ?? ''),
                'source_id' => $sourceId,
            ],
            'extracted_facts' => array_slice($intakeSummary['facts'], 0, 20),
            'missing_data' => array_values(array_unique($intakeSummary['missing'])),
            'missing_data_flags' => array_values(array_unique($intakeSummary['missing'])),
            'intake_fields' => aiCopilotBuildIntakeFieldsPayload($intakeSummary['fields'], [
                'missing' => $intakeSummary['missing'] ?? [],
                'source_file' => $documentTitle,
                'source_chunk_ids' => $retrievalChunkIds,
                'uploaded_timestamp' => $uploadedAt,
                'ingestion_status' => 'retrieved',
            ]),
            'retrieval' => [
                'chunk_ids' => $retrievalChunkIds,
                'chunk_count' => $retrieval['chunk_count'] ?? 0,
                'chunks' => $retrieval['chunks'] ?? [],
            ],
            'vectorized_result' => aiCopilotLabPdfBuildVectorizedResult($retrieval['chunks'] ?? []),
        ]);
    }

    $factSummary = aiCopilotLabPdfExtractFacts($combinedText);

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => 'retrieved',
        'ingestion_status' => 'retrieved',
        'safe_message' => 'Retrieved previously ingested lab PDF context for clinician review.',
        'extraction_method' => $extractionMethod,
        'extracted_text_preview' => aiCopilotLabPdfPreview($combinedText),
        'extracted_text_length' => strlen($combinedText),
        'number_of_chunks' => isset($options['chunk_count']) && is_numeric($options['chunk_count']) ? (int) $options['chunk_count'] : count($retrieval['chunks'] ?? []),
        'document_metadata' => [
            'title' => $documentTitle,
            'mime_type' => 'application/pdf',
            'size' => null,
            'document_type' => $documentType,
            'seeded_demo' => !empty($options['seeded_demo']),
            'patient_key' => (string) ($options['patient_key'] ?? ''),
            'patient_name' => (string) ($options['patient_name'] ?? ''),
            'uploaded_at' => $uploadedAt,
            'original_file_name' => $originalFileName,
            'display_file_name' => $documentTitle,
            'source_id' => $sourceId,
        ],
        'source_metadata' => [
            'file_name' => $documentTitle,
            'original_file_name' => $originalFileName,
            'display_file_name' => $documentTitle,
            'uploaded_at' => $uploadedAt,
            'source_type' => 'lab_pdf',
            'document_type' => $documentType,
            'source_label' => 'Uploaded lab PDF',
            'chunk_count' => count($retrieval['chunks'] ?? []),
            'request_id' => (string) ($options['request_id'] ?? ''),
            'source_id' => $sourceId,
        ],
        'extracted_facts' => array_slice($factSummary['facts'], 0, 20),
        'abnormal_findings' => array_slice($factSummary['abnormal'], 0, 12),
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
    $patientId = isset($options['patient_id']) && is_numeric($options['patient_id']) ? (int) $options['patient_id'] : null;
    $patientName = trim((string) ($options['patient_name'] ?? ''));
    $prompt = trim((string) ($options['prompt'] ?? ''));
    $useSeededDemo = !empty($options['use_seeded_demo']) || !empty($options['useSeededDemo']);
    $file = is_array($options['file'] ?? null) ? $options['file'] : null;
    $attachmentPurpose = trim((string) ($options['attachment_purpose'] ?? 'lab_pdf_ingestion'));
    $forcedDocumentType = aiCopilotDocumentIngestionNormalizeDocType((string) ($options['forced_document_type'] ?? $options['doc_type'] ?? ''));
    $sourceDocumentId = isset($options['source_document_id']) && is_numeric($options['source_document_id']) ? (int) $options['source_document_id'] : null;
    $openemrDocumentId = isset($options['openemr_document_id']) && is_numeric($options['openemr_document_id']) ? (int) $options['openemr_document_id'] : null;
    $fhirDocumentReferenceId = trim((string) ($options['fhir_document_reference_id'] ?? ''));
    $fhirBinaryId = trim((string) ($options['fhir_binary_id'] ?? ''));
    $reviewStatus = trim((string) ($options['review_status'] ?? 'pending_clinician_review')) ?: 'pending_clinician_review';
    $fileHash = trim((string) ($options['file_hash'] ?? ''));
    $uploaderRole = trim((string) ($options['uploader_role'] ?? $role));
    $uploaderUser = trim((string) ($options['uploader_user'] ?? ''));
    $uploadedAt = gmdate('c');
    $fileName = $useSeededDemo ? AI_COPILOT_LAB_PDF_SEEDED_FILE_NAME : (string) ($file['name'] ?? 'attached-document.pdf');
    $mimeType = $useSeededDemo ? 'application/pdf' : (string) ($file['type'] ?? 'application/pdf');
    $fileSize = $useSeededDemo ? null : (isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null);
    $documentType = $forcedDocumentType !== 'unsupported'
        ? $forcedDocumentType
        : ($useSeededDemo ? 'lab_pdf' : aiCopilotAttachmentClassifyDocumentType($fileName, '', $attachmentPurpose));
    $documentClass = aiCopilotAttachmentNormalizeDocumentClass($documentType, $fileName);
    $sourceLabel = aiCopilotAttachmentSourceLabel($documentType);
    $displayFileName = aiCopilotAttachmentBuildDisplayFileName($fileName, $documentClass, $patientName);
    $sourceId = aiCopilotAttachmentBuildSourceId($patientKey, $documentClass, $fileName, $uploadedAt);
    $ingestionOrigin = $useSeededDemo ? 'seeded_demo' : 'uploaded_file';

    if ($forcedDocumentType === 'unsupported') {
        return aiCopilotLabPdfBuildClientToolOutput([
            'status' => 'unsupported_doc_type',
            'ingestion_status' => 'rejected',
            'safe_message' => 'Only lab PDFs and intake forms are supported in this MVP.',
            'extraction_method' => 'not_run',
            'document_metadata' => [
                'title' => $fileName,
                'mime_type' => $mimeType !== '' ? $mimeType : 'application/pdf',
                'size' => $fileSize,
                'document_type' => 'unsupported',
                'patient_id' => $patientId,
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'uploaded_at' => $uploadedAt,
                'source_document_id' => $sourceDocumentId,
            ],
            'source_metadata' => [
                'file_name' => $fileName,
                'uploaded_at' => $uploadedAt,
                'source_type' => 'unsupported',
                'source_label' => 'Unsupported document',
                'chunk_count' => 0,
                'request_id' => $requestId,
                'source_document_id' => $sourceDocumentId,
            ],
            'missing_data' => ['Only lab PDFs and intake forms are supported in this MVP.'],
            'missing_data_flags' => ['Only lab PDFs and intake forms are supported in this MVP.'],
        ]);
    }

    if ($role !== 'doctor') {
        return aiCopilotLabPdfBuildClientToolOutput([
            'status' => 'role_blocked',
            'ingestion_status' => 'blocked',
            'safe_message' => 'Document ingestion is restricted to the Doctor role in this demo workflow.',
            'extraction_method' => 'not_run',
            'document_metadata' => [
                'title' => $fileName,
                'mime_type' => 'application/pdf',
                'size' => $fileSize,
                'document_type' => $documentType,
                'seeded_demo' => $useSeededDemo,
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'uploaded_at' => $uploadedAt,
            ],
            'source_metadata' => [
                'file_name' => $fileName,
                'uploaded_at' => $uploadedAt,
                'source_type' => $documentType,
                'source_label' => $sourceLabel,
                'chunk_count' => 0,
                'request_id' => $requestId,
            ],
            'missing_data' => ['Document ingestion requires a clinician review role.'],
            'missing_data_flags' => ['Document ingestion requires a clinician review role.'],
        ]);
    }

    if (!$useSeededDemo) {
        if (!$file || !aiCopilotLabPdfLooksLikePdf($file)) {
            return aiCopilotLabPdfBuildClientToolOutput([
                'status' => 'invalid_file_type',
                'ingestion_status' => 'rejected',
                'safe_message' => 'Please attach a PDF file for the document-ingestion workflow.',
                'extraction_method' => 'not_run',
            'document_metadata' => [
                'title' => $fileName,
                'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                'size' => $fileSize,
                'document_type' => $documentType,
                'seeded_demo' => false,
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'uploaded_at' => $uploadedAt,
            ],
            'source_metadata' => [
                'file_name' => $fileName,
                'uploaded_at' => $uploadedAt,
                'source_type' => $documentType,
                'source_label' => $sourceLabel,
                'chunk_count' => 0,
                'request_id' => $requestId,
            ],
            'missing_data' => ['The uploaded file was not a PDF.'],
            'missing_data_flags' => ['The uploaded file was not a PDF.'],
            ]);
        }
    }

    $text = '';
    $textForVectorization = '';
    $extractionMethod = $useSeededDemo ? 'seeded_demo_fallback' : 'pdf_text';
    $missingData = [];
    $promptInjectionMatches = [];
    $factSummary = [];
    $intakeSummary = [];
    $syntheticMarcusPdf = false;
    $syntheticMarcusIntake = false;
    $documentGuard = [];
    $pageCount = 0;
    $rawBytesDetected = false;

    if ($useSeededDemo) {
        $text = aiCopilotLabPdfSeededText();
        $missingData = aiCopilotLabPdfSeededMissingData();
        $factSummary = aiCopilotLabPdfSeededFacts();
        $textForVectorization = aiCopilotLabPdfBuildGroundedFactText($factSummary);
        $documentGuard = aiCopilotMedicalGuardBuildPayload([
            'decision' => 'allowed',
            'documentType' => 'lab_results',
            'confidence' => 0.99,
            'extractedTextPreview' => aiCopilotLabPdfPreview($text),
            'detectedEntitySummary' => [
                'totalEntities' => 4,
                'highConfidenceEntityCount' => 4,
                'highConfidenceEntities' => [
                    ['text' => 'Hemoglobin A1c', 'category' => 'TEST_NAME', 'score' => 0.99],
                    ['text' => 'LDL Cholesterol', 'category' => 'TEST_NAME', 'score' => 0.99],
                ],
                'categoryCounts' => ['TEST_NAME' => 4],
                'averageScore' => 0.99,
                'minimumScore' => 0.70,
                'medicalEntityCount' => 4,
            ],
            'guardProvider' => 'seeded_demo_guard',
            'extractionMethod' => 'seeded_demo_fallback',
            'textractStatus' => 'not_run',
            'comprehendStatus' => 'not_run',
            'awsGuardEnabled' => false,
            'auditEvents' => [
                'copilot_document_guard_started',
                'copilot_document_guard_allowed',
            ],
        ]);
    } else {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $binary = ($tmpName !== '' && is_file($tmpName)) ? file_get_contents($tmpName) : false;
        $syntheticMarcusPdf = aiCopilotLabPdfIsSyntheticMarcusJohnsonPdf($fileName);
        $syntheticMarcusIntake = aiCopilotIntakeIsSyntheticMarcusJohnsonForm($fileName);
        aiCopilotMedicalGuardLog('copilot_upload_received', [
            'request_id' => $requestId,
            'role' => $role,
            'patient_key' => $patientKey,
            'file_name' => basename($fileName),
        ]);
        aiCopilotMedicalGuardLog('copilot_pdf_upload_received', [
            'request_id' => $requestId,
            'role' => $role,
            'patient_key' => $patientKey,
            'file_name' => basename($fileName),
        ]);
        if (!is_string($binary) || $binary === '') {
            if ($syntheticMarcusPdf) {
                $text = aiCopilotLabPdfMvpSyntheticFallbackText($fileName !== '' ? $fileName : AI_COPILOT_LAB_PDF_MVP_SYNTHETIC_FILE_NAME);
                $extractionMethod = 'synthetic_demo_pdf_fallback';
                $pageCount = 1;
                $documentType = 'lab_pdf';
                $sourceLabel = aiCopilotAttachmentSourceLabel($documentType);
                $missingData = aiCopilotLabPdfSeededMissingData();
                $factSummary = aiCopilotLabPdfSeededFacts();
                $textForVectorization = aiCopilotLabPdfBuildGroundedFactText($factSummary);
                $documentGuard = aiCopilotMedicalGuardBuildPayload([
                    'decision' => 'allowed',
                    'documentType' => 'lab_results',
                    'confidence' => 0.99,
                    'extractedTextPreview' => aiCopilotLabPdfPreview($text),
                    'detectedEntitySummary' => [
                        'totalEntities' => 4,
                        'highConfidenceEntityCount' => 4,
                        'highConfidenceEntities' => [
                            ['text' => 'Hemoglobin A1c', 'category' => 'TEST_NAME', 'score' => 0.99],
                            ['text' => 'LDL Cholesterol', 'category' => 'TEST_NAME', 'score' => 0.99],
                        ],
                        'categoryCounts' => ['TEST_NAME' => 4],
                        'averageScore' => 0.99,
                        'minimumScore' => 0.70,
                        'medicalEntityCount' => 4,
                    ],
                    'guardProvider' => 'synthetic_demo_guard',
                    'extractionMethod' => 'synthetic_demo_pdf_fallback',
                    'textractStatus' => 'not_run',
                    'comprehendStatus' => 'not_run',
                    'awsGuardEnabled' => false,
                    'auditEvents' => [
                        'copilot_document_guard_started',
                        'copilot_document_guard_allowed',
                    ],
                ]);
            } elseif ($syntheticMarcusIntake) {
                $text = aiCopilotIntakeSeededText();
                $extractionMethod = 'synthetic_marcus_intake_demo';
                $documentType = 'intake_form';
                $sourceLabel = aiCopilotAttachmentSourceLabel($documentType);
                $intakeSummary = aiCopilotIntakeExtractFields($text);
                $missingData = array_values(array_unique(array_merge(
                    aiCopilotIntakeSeededMissingData(),
                    $intakeSummary['missing'] ?? []
                )));
                $textForVectorization = aiCopilotIntakeBuildGroundedText($intakeSummary);
                $documentGuard = aiCopilotMedicalGuardBuildPayload([
                    'decision' => 'allowed',
                    'documentType' => 'intake_form',
                    'confidence' => 0.99,
                    'extractedTextPreview' => aiCopilotLabPdfPreview($text),
                    'detectedEntitySummary' => [
                        'totalEntities' => 5,
                        'highConfidenceEntityCount' => 5,
                        'highConfidenceEntities' => [
                            ['text' => 'Reason for visit', 'category' => 'INTAKE_FIELD', 'score' => 0.99],
                            ['text' => 'Medication adherence', 'category' => 'INTAKE_FIELD', 'score' => 0.99],
                        ],
                        'categoryCounts' => ['INTAKE_FIELD' => 5],
                        'averageScore' => 0.99,
                        'minimumScore' => 0.70,
                        'medicalEntityCount' => 5,
                    ],
                    'guardProvider' => 'synthetic_demo_guard',
                    'extractionMethod' => 'synthetic_marcus_intake_demo',
                    'textractStatus' => 'not_run',
                    'comprehendStatus' => 'not_run',
                    'awsGuardEnabled' => false,
                    'auditEvents' => [
                        'copilot_document_guard_started',
                        'copilot_document_guard_allowed',
                    ],
                ]);
            } else {
                $reviewOutput = $documentType === 'intake_form'
                    ? aiCopilotIntakeBuildReviewRequiredOutput(
                        $fileName,
                        $fileSize,
                        false,
                        $patientKey,
                        $patientName,
                        $uploadedAt,
                        $requestId,
                        'pdf_text_unavailable',
                        ['The uploaded intake form PDF could not be read for reliable text extraction.']
                    )
                    : aiCopilotLabPdfBuildReviewRequiredOutput(
                        $fileName,
                        $fileSize,
                        false,
                        $patientKey,
                        $patientName,
                        $uploadedAt,
                        $requestId,
                        'pdf_text_unavailable',
                        ['The uploaded PDF could not be read for reliable text extraction.']
                    );
                $reviewOutput['document_guard'] = aiCopilotMedicalGuardBuildPayload([
                    'decision' => 'review_required',
                    'documentType' => 'unknown',
                    'confidence' => 0.0,
                    'extractedTextPreview' => '',
                    'detectedEntitySummary' => [
                        'totalEntities' => 0,
                        'highConfidenceEntityCount' => 0,
                        'highConfidenceEntities' => [],
                        'averageScore' => 0.0,
                        'minimumScore' => 0.70,
                        'medicalEntityCount' => 0,
                    ],
                    'rejectionReason' => 'The uploaded PDF file was not available for medical-document validation.',
                    'guardProvider' => 'local_validation_fallback',
                    'extractionMethod' => 'pdf_text_unavailable',
                    'textractStatus' => 'not_run',
                    'comprehendStatus' => 'not_run',
                    'awsGuardEnabled' => aiCopilotMedicalGuardEnabled(),
                    'auditEvents' => [
                        'copilot_document_guard_started',
                        'copilot_document_guard_review_required',
                        'copilot_vectorization_blocked',
                    ],
                ]);
                return $reviewOutput;
            }
        }

        if (is_string($binary) && $binary !== '') {
            $extracted = aiCopilotLabPdfExtractTextFromBinary($binary, $tmpName, $fileName, $requestId);
            aiCopilotLabPdfLogExtractionAuditEvents($extracted, $requestId, $fileName);
            $provisionalText = (string) ($extracted['text'] ?? '');
            $textExtractionStatus = (string) ($extracted['text_extraction_status'] ?? 'failed');
            $extractionMethod = aiCopilotLabPdfNormalizeWhitespace((string) ($extracted['extraction_method'] ?? ''));
            $pageCount = isset($extracted['page_count']) && is_numeric($extracted['page_count']) ? (int) $extracted['page_count'] : 0;
            $rawBytesDetected = !empty($extracted['raw_bytes_detected']);

            if ($provisionalText === '' && $textExtractionStatus !== 'success') {
                if ($syntheticMarcusPdf) {
                    $provisionalText = aiCopilotLabPdfMvpSyntheticFallbackText($fileName !== '' ? $fileName : AI_COPILOT_LAB_PDF_MVP_SYNTHETIC_FILE_NAME);
                    $textExtractionStatus = 'success';
                    $extractionMethod = 'synthetic_demo_pdf_fallback';
                    $pageCount = max(1, $pageCount);
                } else {
                $reviewOutput = $documentType === 'intake_form'
                    ? aiCopilotIntakeBuildReviewRequiredOutput(
                        $fileName,
                        $fileSize,
                        false,
                        $patientKey,
                        $patientName,
                        $uploadedAt,
                        $requestId,
                        $extractionMethod !== '' ? $extractionMethod : 'failed',
                        ['The uploaded PDF could not be converted into readable text. OCR or Textract review is required before ingestion.']
                    )
                    : aiCopilotLabPdfBuildReviewRequiredOutput(
                        $fileName,
                        $fileSize,
                        false,
                        $patientKey,
                        $patientName,
                        $uploadedAt,
                        $requestId,
                        $extractionMethod !== '' ? $extractionMethod : 'failed',
                        ['The uploaded PDF could not be converted into readable text. OCR or Textract review is required before ingestion.']
                );
                $reviewOutput['status'] = 'ocr_required';
                $reviewOutput['safe_message'] = 'The uploaded PDF needs OCR or Textract review before reliable ingestion can continue.';
                $reviewOutput['extracted_text_preview'] = (string) ($extracted['extracted_text_preview'] ?? '');
                $reviewOutput['text_extraction_status'] = $textExtractionStatus;
                $reviewOutput['page_count'] = $pageCount;
                $reviewOutput['raw_bytes_detected'] = $rawBytesDetected;
                $reviewOutput['ocr_required'] = true;
                return $reviewOutput;
                }
            }

            $documentGuardResult = aiCopilotValidateMedicalDocumentGuard([
                'request_id' => $requestId,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'tmp_name' => $tmpName,
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'role' => $role,
                'text' => $provisionalText,
                'fallback_text' => $provisionalText,
            ]);
            $documentGuard = aiCopilotMedicalGuardBuildPayload($documentGuardResult);
            $guardDecision = (string) ($documentGuardResult['decision'] ?? 'review_required');
            $guardWorkflowSourceType = aiCopilotMedicalGuardWorkflowSourceType((string) ($documentGuardResult['documentType'] ?? 'unknown'));

            if ($guardDecision !== 'allowed') {
                return aiCopilotMedicalGuardBuildBlockedOutput(
                    $guardDecision === 'rejected' ? 'document_guard_rejected' : 'document_guard_review_required',
                    $documentGuardResult,
                    $fileName,
                    $mimeType,
                    $fileSize,
                    $patientKey,
                    $patientName,
                    $uploadedAt,
                    $requestId,
                    []
                );
            }

            if ($guardWorkflowSourceType === 'medical_document' || $guardWorkflowSourceType === 'unknown') {
                $documentGuardResult['decision'] = 'review_required';
                $documentGuardResult['rejectionReason'] = 'Medical document detected, but this demo ingestion workflow currently supports lab results and intake forms only. Review required before ingestion.';
                return aiCopilotMedicalGuardBuildBlockedOutput(
                    'document_guard_review_required',
                    $documentGuardResult,
                    $fileName,
                    $mimeType,
                    $fileSize,
                    $patientKey,
                    $patientName,
                    $uploadedAt,
                    $requestId,
                    []
                );
            }

            $text = aiCopilotMedicalGuardNormalizeText((string) ($documentGuardResult['extractedText'] ?? ''));
            if ($text === '') {
                $text = $provisionalText;
            }
            $extractionMethod = aiCopilotMedicalGuardNormalizeText((string) ($documentGuardResult['extractionMethod'] ?? ''));
            if ($extractionMethod === '') {
                $extractionMethod = (string) ($extracted['extraction_method'] ?? 'pdf_text');
            }

            $promptInjectionMatches = aiCopilotLabPdfPromptInjectionMatches($text);
            $documentType = $guardWorkflowSourceType === 'intake_form' ? 'intake_form' : 'lab_pdf';
            $sourceLabel = aiCopilotAttachmentSourceLabel($documentType);
            $syntheticMarcusPdf = aiCopilotLabPdfIsSyntheticMarcusJohnsonPdf($fileName, $text);
            $syntheticMarcusIntake = aiCopilotIntakeIsSyntheticMarcusJohnsonForm($fileName, $text);
            if ($syntheticMarcusPdf) {
                $text = aiCopilotLabPdfSeededText();
                $extractionMethod = 'synthetic_marcus_demo';
                $documentType = 'lab_pdf';
                $sourceLabel = aiCopilotAttachmentSourceLabel($documentType);
                $missingData = aiCopilotLabPdfSeededMissingData();
                $factSummary = aiCopilotLabPdfSeededFacts();
                $textForVectorization = aiCopilotLabPdfBuildGroundedFactText($factSummary);
            } elseif ($syntheticMarcusIntake) {
                $text = aiCopilotIntakeSeededText();
                $extractionMethod = 'synthetic_marcus_intake_demo';
                $documentType = 'intake_form';
                $sourceLabel = aiCopilotAttachmentSourceLabel($documentType);
                $intakeSummary = aiCopilotIntakeExtractFields($text);
                $missingData = array_values(array_unique(array_merge(
                    aiCopilotIntakeSeededMissingData(),
                    $intakeSummary['missing'] ?? []
                )));
                $textForVectorization = aiCopilotIntakeBuildGroundedText($intakeSummary);
            } elseif ($documentType === 'intake_form') {
                $intakeSummary = aiCopilotIntakeExtractFields($text);
                $textForVectorization = aiCopilotIntakeBuildGroundedText($intakeSummary);
                $missingData = array_values(array_unique(array_merge(
                    $missingData,
                    $intakeSummary['missing'] ?? []
                )));

                if ($text === '' || strlen($text) < 24 || empty($intakeSummary['valid_field_count'])) {
                    $reviewOutput = aiCopilotIntakeBuildReviewRequiredOutput(
                        $fileName,
                        $fileSize,
                        false,
                        $patientKey,
                        $patientName,
                        $uploadedAt,
                        $requestId,
                        $extractionMethod !== '' ? $extractionMethod : 'pdf_text',
                        array_merge(
                            $missingData,
                            !empty($intakeSummary['rejected_lines']) ? ['Detected extracted rows did not match reliable intake field patterns.'] : [],
                            $text === '' || strlen($text) < 24 ? ['Extracted PDF text was insufficient for reliable intake parsing.'] : []
                        ),
                        $promptInjectionMatches
                    );
                    $reviewOutput['document_guard'] = $documentGuard;
                    return $reviewOutput;
                }
            } else {
                $factSummary = aiCopilotLabPdfExtractFacts($text, [
                    'file_name' => $fileName,
                ]);
                $textForVectorization = aiCopilotLabPdfBuildGroundedFactText($factSummary);
                $missingData = array_values(array_unique(array_merge(
                    $missingData,
                    $factSummary['missing'] ?? []
                )));

                if (
                    $text === ''
                    || strlen($text) < 24
                    || empty($factSummary['valid_row_count'])
                ) {
                    $reviewOutput = aiCopilotLabPdfBuildReviewRequiredOutput(
                        $fileName,
                        $fileSize,
                        false,
                        $patientKey,
                        $patientName,
                        $uploadedAt,
                        $requestId,
                        $extractionMethod !== '' ? $extractionMethod : 'pdf_text',
                        array_merge(
                            $missingData,
                            !empty($factSummary['rejected_lines']) ? ['Detected extracted rows did not match reliable lab test patterns.'] : [],
                            $text === '' || strlen($text) < 24 ? ['Extracted PDF text was insufficient for reliable lab parsing.'] : []
                        ),
                        $promptInjectionMatches
                    );
                    $reviewOutput['document_guard'] = $documentGuard;
                    return $reviewOutput;
                }
            }
        }
    }

    if ($promptInjectionMatches !== []) {
        $missingData[] = 'Instruction-like text was detected in the uploaded PDF and treated as untrusted document content rather than instructions.';
    }

    if ($documentType === 'lab_pdf' && $factSummary === []) {
        $factSummary = aiCopilotLabPdfExtractFacts($text, [
            'file_name' => $fileName,
            'use_synthetic_marcus' => $syntheticMarcusPdf || $useSeededDemo,
        ]);
    }
    if ($documentType === 'intake_form' && $intakeSummary === []) {
        $intakeSummary = aiCopilotIntakeExtractFields($text);
    }
    if ($documentType === 'lab_pdf' && $textForVectorization === '') {
        $textForVectorization = aiCopilotLabPdfBuildGroundedFactText($factSummary);
    } elseif ($documentType === 'intake_form' && $textForVectorization === '') {
        $textForVectorization = aiCopilotIntakeBuildGroundedText($intakeSummary);
    }

    $factSummary['facts'] = $documentType === 'lab_pdf'
        ? aiCopilotEnhanceLabFactsForReview(
            is_array($factSummary['facts'] ?? null) ? $factSummary['facts'] : [],
            $sourceDocumentId
        )
        : [];
    $intakeSummary['facts'] = $documentType === 'intake_form'
        ? aiCopilotEnhanceIntakeFactsForReview(
            is_array($intakeSummary['facts'] ?? null) ? $intakeSummary['facts'] : [],
            $sourceDocumentId
        )
        : [];
    $sourceLinks = $documentType === 'lab_pdf'
        ? array_values(array_filter(array_map(static fn($fact) => is_array($fact['source_link'] ?? null) ? $fact['source_link'] : null, $factSummary['facts'] ?? [])))
        : array_values(array_filter(array_map(static fn($fact) => is_array($fact['source_link'] ?? null) ? $fact['source_link'] : null, $intakeSummary['facts'] ?? [])));

    $documentClass = aiCopilotAttachmentNormalizeDocumentClass($documentType, $fileName, $textForVectorization !== '' ? $textForVectorization : $text);
    $displayFileName = aiCopilotAttachmentBuildDisplayFileName($fileName, $documentClass, $patientName);
    $sourceId = aiCopilotAttachmentBuildSourceId($patientKey, $documentClass, $fileName, $uploadedAt);

    if ($documentType === 'lab_pdf') {
        aiCopilotMedicalGuardLog('copilot_lab_values_extracted', [
            'request_id' => $requestId,
            'file_name' => basename($fileName),
            'document_type' => $documentClass,
            'lab_value_count' => isset($factSummary['facts']) && is_array($factSummary['facts']) ? count($factSummary['facts']) : 0,
            'abnormal_count' => isset($factSummary['abnormal']) && is_array($factSummary['abnormal']) ? count($factSummary['abnormal']) : 0,
        ]);
    }

    $chunks = aiCopilotLabPdfChunkText($textForVectorization !== '' ? $textForVectorization : $text);
    aiCopilotMedicalGuardLog('copilot_vectorization_started', [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
        'document_type' => $documentClass,
        'chunk_count' => count($chunks),
    ]);
    aiCopilotMedicalGuardLog('copilot_uploaded_document_vectorization_started', [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
        'document_type' => $documentClass,
        'chunk_count' => count($chunks),
    ]);
    $records = [];
    foreach ($chunks as $chunk) {
        $records[] = aiCopilotLabPdfCreateVectorRecord([
            'request_id' => $requestId,
            'patient_id' => $patientId,
            'patient_key' => $patientKey,
            'patient_display_name' => $patientName,
            'source_document_id' => $sourceDocumentId,
            'file_name' => $fileName,
            'original_file_name' => $fileName,
            'display_file_name' => $displayFileName,
            'chunk_text' => (string) ($chunk['chunk_text'] ?? ''),
            'chunk_index' => $chunk['chunk_index'] ?? 0,
            'source_page' => $chunk['source_page'] ?? null,
            'uploaded_at' => $uploadedAt,
            'role' => ucfirst($role),
            'extraction_method' => $extractionMethod,
            'source_type' => $documentType,
            'document_type' => $documentClass,
            'source_label' => $sourceLabel,
            'record_prefix' => $documentType === 'intake_form' ? 'intakeform' : 'labpdf',
            'source_id' => $sourceId,
            'seeded_demo' => $useSeededDemo || $syntheticMarcusPdf || $syntheticMarcusIntake,
            'ingestion_origin' => $ingestionOrigin,
            'review_status' => $reviewStatus,
        ]);
    }
    aiCopilotLabPdfUpsertVectorRecords($records);
    aiCopilotMedicalGuardLog('copilot_vectorization_succeeded', [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
        'document_type' => $documentClass,
        'chunk_count' => count($chunks),
    ]);
    aiCopilotMedicalGuardLog('copilot_uploaded_document_vectorization_succeeded', [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
        'document_type' => $documentClass,
        'chunk_count' => count($chunks),
    ]);
    aiCopilotMedicalGuardLog('copilot_uploaded_document_source_registered', [
        'request_id' => $requestId,
        'file_name' => basename($fileName),
        'document_type' => $documentClass,
        'source_id' => $sourceId,
        'chunk_count' => count($chunks),
    ]);

    if ($documentType === 'lab_pdf') {
        $missingData = array_values(array_unique(array_merge($missingData, $factSummary['missing'] ?? [])));
    } else {
        $missingData = array_values(array_unique(array_merge($missingData, $intakeSummary['missing'] ?? [])));
    }
    $retrieval = aiCopilotLabPdfRetrieveRelevantChunks([
        'patient_key' => $patientKey,
        'prompt' => $prompt !== '' ? $prompt : ($textForVectorization !== '' ? $textForVectorization : $text),
        'file_name' => $fileName,
        'limit' => 4,
    ]);

    if ($documentType === 'intake_form') {
        $retrievalChunkIds = $retrieval['chunk_ids'] ?? [];
        return aiCopilotLabPdfBuildClientToolOutput([
            'status' => $syntheticMarcusIntake ? 'synthetic_demo_fallback' : 'ok',
            'ingestion_status' => 'ingested',
            'safe_message' => $syntheticMarcusIntake
                ? 'Using deterministic Marcus Johnson demo intake-form extraction through the same ingestion and retrieval pipeline.'
                : 'Intake form ingested and vectorized for draft-only clinician review.',
            'extraction_method' => $extractionMethod,
            'extracted_text_preview' => aiCopilotLabPdfPreview($textForVectorization !== '' ? $textForVectorization : $text),
            'extracted_text_length' => strlen($textForVectorization !== '' ? $textForVectorization : $text),
            'page_count' => $pageCount,
            'raw_bytes_detected' => $rawBytesDetected,
            'number_of_chunks' => count($chunks),
            'document_metadata' => [
                'title' => $displayFileName,
                'mime_type' => 'application/pdf',
                'size' => $fileSize,
                'document_type' => $documentClass,
                'patient_id' => $patientId,
                'seeded_demo' => $syntheticMarcusIntake,
                'patient_key' => $patientKey,
                'patient_name' => $patientName,
                'uploaded_at' => $uploadedAt,
                'original_file_name' => $fileName,
                'display_file_name' => $displayFileName,
                'source_id' => $sourceId,
                'source_document_id' => $sourceDocumentId,
                'openemr_document_id' => $openemrDocumentId,
                'fhir_document_reference_id' => $fhirDocumentReferenceId,
                'fhir_binary_id' => $fhirBinaryId,
                'review_status' => $reviewStatus,
                'file_hash' => $fileHash,
                'uploader_role' => $uploaderRole,
                'uploader_user' => $uploaderUser,
            ],
            'source_metadata' => [
                'file_name' => $displayFileName,
                'original_file_name' => $fileName,
                'display_file_name' => $displayFileName,
                'uploaded_at' => $uploadedAt,
                'source_type' => 'intake_form',
                'document_type' => $documentClass,
                'source_label' => 'Uploaded intake form',
                'chunk_count' => count($chunks),
                'request_id' => $requestId,
                'source_id' => $sourceId,
                'source_document_id' => $sourceDocumentId,
            ],
            'extracted_facts' => array_slice($intakeSummary['facts'] ?? [], 0, 20),
            'missing_data' => $missingData,
            'missing_data_flags' => $missingData,
            'intake_fields' => aiCopilotBuildIntakeFieldsPayload($intakeSummary['fields'] ?? [], [
                'missing' => $missingData,
                'source_file' => $displayFileName,
                'source_chunk_ids' => $retrievalChunkIds,
                'uploaded_timestamp' => $uploadedAt,
                'ingestion_status' => 'ingested',
            ]),
            'retrieval' => [
                'chunk_ids' => $retrievalChunkIds,
                'chunk_count' => $retrieval['chunk_count'] ?? 0,
                'chunks' => $retrieval['chunks'] ?? [],
            ],
            'vectorized_result' => aiCopilotLabPdfBuildVectorizedResult($records),
            'document_guard' => $documentGuard,
            'prompt_injection_matches' => $promptInjectionMatches,
            'source_links' => $sourceLinks,
        ]);
    }

    return aiCopilotLabPdfBuildClientToolOutput([
        'status' => $useSeededDemo ? 'seeded_demo_fallback' : 'ok',
        'ingestion_status' => 'ingested',
        'safe_message' => $useSeededDemo
            ? 'Using the seeded Marcus Johnson demo lab PDF fallback through the same ingestion and retrieval pipeline.'
            : ($syntheticMarcusPdf
                ? 'Using the local synthetic demo lab PDF fallback through the same ingestion and retrieval pipeline because direct PDF text extraction was unavailable.'
                : 'Lab PDF ingested and vectorized for draft-only clinician review.'),
        'extraction_method' => $extractionMethod,
        'extracted_text_preview' => aiCopilotLabPdfPreview($textForVectorization !== '' ? $textForVectorization : $text),
        'extracted_text_length' => strlen($textForVectorization !== '' ? $textForVectorization : $text),
        'page_count' => $pageCount,
        'raw_bytes_detected' => $rawBytesDetected,
        'number_of_chunks' => count($chunks),
        'document_metadata' => [
            'title' => $displayFileName,
            'mime_type' => 'application/pdf',
            'size' => $fileSize,
            'document_type' => $documentClass,
            'patient_id' => $patientId,
            'seeded_demo' => $useSeededDemo || $syntheticMarcusPdf,
            'patient_key' => $patientKey,
            'patient_name' => $patientName,
            'uploaded_at' => $uploadedAt,
            'original_file_name' => $fileName,
            'display_file_name' => $displayFileName,
            'source_id' => $sourceId,
            'source_document_id' => $sourceDocumentId,
            'openemr_document_id' => $openemrDocumentId,
            'fhir_document_reference_id' => $fhirDocumentReferenceId,
            'fhir_binary_id' => $fhirBinaryId,
            'review_status' => $reviewStatus,
            'file_hash' => $fileHash,
            'uploader_role' => $uploaderRole,
            'uploader_user' => $uploaderUser,
        ],
        'source_metadata' => [
            'file_name' => $displayFileName,
            'original_file_name' => $fileName,
            'display_file_name' => $displayFileName,
            'uploaded_at' => $uploadedAt,
            'source_type' => 'lab_pdf',
            'document_type' => $documentClass,
            'source_label' => 'Uploaded lab PDF',
            'chunk_count' => count($chunks),
            'request_id' => $requestId,
            'source_id' => $sourceId,
            'source_document_id' => $sourceDocumentId,
        ],
        'extracted_facts' => array_slice($factSummary['facts'], 0, 20),
        'abnormal_findings' => array_slice($factSummary['abnormal'], 0, 12),
        'missing_data' => $missingData,
        'missing_data_flags' => $missingData,
        'retrieval' => [
            'chunk_ids' => $retrieval['chunk_ids'] ?? [],
            'chunk_count' => $retrieval['chunk_count'] ?? 0,
            'chunks' => $retrieval['chunks'] ?? [],
        ],
        'vectorized_result' => aiCopilotLabPdfBuildVectorizedResult($records),
        'document_guard' => $documentGuard,
        'prompt_injection_matches' => $promptInjectionMatches,
        'source_links' => $sourceLinks,
    ]);
}

function aiCopilotAttachRetrievedLabPdfContext(array $context, string $prompt, string $role, string $mode, string $requestId = ''): array
{
    if (empty($context['patient']['pubpid']) || !aiCopilotLabPdfPromptRequestsRetrieval($prompt, $mode)) {
        return $context;
    }

    $existingToolOutput = is_array($context['attached_lab_pdf_tool_output'] ?? null) ? $context['attached_lab_pdf_tool_output'] : [];
    $existingStatus = (string) ($existingToolOutput['status'] ?? '');
    if (in_array($existingStatus, ['invalid_file_type', 'ocr_required', 'role_blocked', 'extraction_review_required', 'document_guard_rejected', 'document_guard_review_required'], true)) {
        return $context;
    }

    $patientKey = (string) ($context['patient']['pubpid'] ?? '');
    $patientName = (string) ($context['patient']['name'] ?? '');
    $requestedDocumentTypes = aiCopilotLabPdfRequestedDocumentTypes($prompt, $mode, $existingToolOutput);
    if ($requestedDocumentTypes !== []) {
        $retrieval = aiCopilotLabPdfRetrieveRequestedDocumentCoverage([
            'patient_key' => $patientKey,
            'patient_name' => $patientName,
            'prompt' => $prompt,
            'requested_document_types' => $requestedDocumentTypes,
            'prefer_uploaded_only' => aiCopilotLabPdfPromptTargetsUploadedEvidence($prompt),
            'limit_per_document' => 2,
        ]);
    } else {
        $sourceType = aiCopilotLabPdfRequestedSourceType($prompt, $mode, $existingToolOutput);
        $retrieval = aiCopilotLabPdfRetrieveRelevantChunks([
            'patient_key' => $patientKey,
            'prompt' => $prompt,
            'source_type' => $sourceType,
            'limit' => 4,
        ]);
    }

    if (($retrieval['chunk_count'] ?? 0) <= 0) {
        return $context;
    }

    $context['retrieved_lab_pdf_context'] = $retrieval;
    $toolOutput = $existingToolOutput;
    if ($toolOutput === [] || $requestedDocumentTypes !== []) {
        $toolOutput = aiCopilotLabPdfBuildToolOutputFromRetrieval($retrieval, [
            'patient_key' => $patientKey,
            'patient_name' => $patientName,
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
        if (!empty($retrieval['matched_sources']) || !empty($retrieval['requested_document_types'])) {
            $toolOutput['source_coverage'] = [
                'requested_document_types' => $retrieval['requested_document_types'] ?? [],
                'matched_sources' => $retrieval['matched_sources'] ?? [],
                'missing_requested_document_types' => $retrieval['missing_requested_document_types'] ?? [],
                'missing_requested_sources_message' => $retrieval['missing_requested_sources_message'] ?? '',
                'all_requested_document_types_attempted' => !empty($retrieval['all_requested_document_types_attempted']),
            ];
            $toolOutput['document_summaries'] = $retrieval['document_summaries'] ?? [];
        }
    }

    $context['attached_lab_pdf_tool_output'] = $toolOutput;
    return $context;
}
