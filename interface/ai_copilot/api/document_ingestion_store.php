<?php

/**
 * OpenEMR AI Copilot Document Ingestion MVP persistence helpers.
 *
 * Draft-only persistence for uploaded source documents, extracted pending facts,
 * review decisions, RAG chunk metadata, and safe workflow traces.
 */

require_once(dirname(__DIR__, 2) . '/globals.php');
require_once($GLOBALS['fileroot'] . '/library/classes/Document.class.php');

use OpenEMR\Common\Session\SessionWrapperFactory;

const AI_COPILOT_DOCUMENT_UPLOAD_STATUS_UPLOADED = 'uploaded';
const AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_PENDING = 'extraction_pending';
const AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_REVIEW_REQUIRED = 'extraction_review_required';
const AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_FAILED = 'extraction_failed';
const AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING = 'pending_clinician_review';
const AI_COPILOT_DOCUMENT_REVIEW_STATUS_APPROVED = 'clinician_approved';
const AI_COPILOT_DOCUMENT_REVIEW_STATUS_REJECTED = 'clinician_rejected';
const AI_COPILOT_DOCUMENT_CHART_STATUS_PENDING = 'chart_update_pending';
const AI_COPILOT_DOCUMENT_CHART_STATUS_COMPLETED = 'chart_update_completed';
const AI_COPILOT_DOCUMENT_CHART_STATUS_FAILED = 'chart_update_failed';

function aiCopilotDocumentIngestionJsonEncode(array $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function aiCopilotDocumentIngestionJsonDecode(string $value): array
{
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function aiCopilotDocumentIngestionPathDepth(string $path): int
{
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($segment) => $segment !== ''));
    return max(1, count($segments));
}

function aiCopilotDocumentIngestionNormalizeDocType(string $docType): string
{
    $normalized = strtolower(trim($docType));
    return in_array($normalized, ['lab_pdf', 'intake_form'], true) ? $normalized : 'unsupported';
}

function aiCopilotDocumentIngestionRequireTables(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    sqlStatementThrowException(
        "CREATE TABLE IF NOT EXISTS `ai_copilot_documents` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `patient_id` BIGINT NOT NULL,
            `patient_key` VARCHAR(191) NOT NULL DEFAULT '',
            `patient_name` VARCHAR(191) NOT NULL DEFAULT '',
            `openemr_document_id` BIGINT DEFAULT NULL,
            `fhir_document_reference_id` VARCHAR(191) NOT NULL DEFAULT '',
            `fhir_binary_id` VARCHAR(191) NOT NULL DEFAULT '',
            `doc_type` VARCHAR(64) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL DEFAULT '',
            `display_file_name` VARCHAR(255) NOT NULL DEFAULT '',
            `file_hash` VARCHAR(128) NOT NULL DEFAULT '',
            `mime_type` VARCHAR(191) NOT NULL DEFAULT '',
            `upload_status` VARCHAR(64) NOT NULL DEFAULT 'uploaded',
            `extraction_status` VARCHAR(64) NOT NULL DEFAULT 'extraction_pending',
            `review_status` VARCHAR(64) NOT NULL DEFAULT 'pending_clinician_review',
            `created_by` BIGINT DEFAULT NULL,
            `uploader_role` VARCHAR(64) NOT NULL DEFAULT '',
            `metadata_json` LONGTEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_copilot_documents_patient_doc_type` (`patient_id`, `doc_type`),
            KEY `idx_ai_copilot_documents_openemr_document_id` (`openemr_document_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatementThrowException(
        "CREATE TABLE IF NOT EXISTS `ai_copilot_extracted_facts` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `patient_id` BIGINT NOT NULL,
            `document_id` BIGINT NOT NULL,
            `doc_type` VARCHAR(64) NOT NULL,
            `fact_type` VARCHAR(128) NOT NULL DEFAULT '',
            `fact_json` LONGTEXT,
            `source_json` LONGTEXT,
            `confidence` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            `proposed_target` VARCHAR(191) NOT NULL DEFAULT '',
            `review_status` VARCHAR(64) NOT NULL DEFAULT 'pending_clinician_review',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_copilot_extracted_facts_document` (`document_id`),
            KEY `idx_ai_copilot_extracted_facts_patient` (`patient_id`, `doc_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatementThrowException(
        "CREATE TABLE IF NOT EXISTS `ai_copilot_fact_reviews` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `fact_id` BIGINT NOT NULL,
            `reviewer_user_id` BIGINT DEFAULT NULL,
            `decision` VARCHAR(64) NOT NULL,
            `note` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_copilot_fact_reviews_fact` (`fact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatementThrowException(
        "CREATE TABLE IF NOT EXISTS `ai_copilot_rag_chunks` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `patient_id` BIGINT NOT NULL,
            `document_id` BIGINT NOT NULL,
            `doc_type` VARCHAR(64) NOT NULL,
            `chunk_id` VARCHAR(191) NOT NULL,
            `chunk_text_redacted_or_minimum_necessary` LONGTEXT,
            `embedding_reference` VARCHAR(191) NOT NULL DEFAULT '',
            `metadata_json` LONGTEXT,
            `review_status` VARCHAR(64) NOT NULL DEFAULT 'pending_clinician_review',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_copilot_rag_chunks_patient_doc` (`patient_id`, `document_id`),
            KEY `idx_ai_copilot_rag_chunks_chunk_id` (`chunk_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatementThrowException(
        "CREATE TABLE IF NOT EXISTS `ai_copilot_agent_traces` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `request_id` VARCHAR(191) NOT NULL,
            `patient_id` BIGINT DEFAULT NULL,
            `user_id` BIGINT DEFAULT NULL,
            `role` VARCHAR(64) NOT NULL DEFAULT '',
            `workflow_name` VARCHAR(191) NOT NULL DEFAULT '',
            `safe_trace_json` LONGTEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_copilot_agent_traces_request` (`request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $initialized = true;
}

function aiCopilotDocumentIngestionCurrentUserId(): ?int
{
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $userId = $session->get('authUserID');
    return is_numeric($userId) ? (int) $userId : null;
}

function aiCopilotDocumentIngestionCurrentUsername(): string
{
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $username = $session->get('authUser');
    return is_string($username) ? trim($username) : '';
}

function aiCopilotDocumentIngestionReadBinary(array $options): array
{
    $file = is_array($options['file'] ?? null) ? $options['file'] : null;
    $filePath = is_string($options['file_path'] ?? null) ? trim((string) $options['file_path']) : '';
    $binary = $options['binary'] ?? null;

    if (is_string($binary) && $binary !== '') {
        return [
            'ok' => true,
            'binary' => $binary,
            'tmp_name' => $file && is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : $filePath,
        ];
    }

    $candidatePath = '';
    if ($file && is_string($file['tmp_name'] ?? null) && is_file((string) $file['tmp_name'])) {
        $candidatePath = (string) $file['tmp_name'];
    } elseif ($filePath !== '' && is_file($filePath)) {
        $candidatePath = $filePath;
    }

    if ($candidatePath === '') {
        return [
            'ok' => false,
            'error' => 'Uploaded file content was not available.',
            'binary' => '',
            'tmp_name' => '',
        ];
    }

    $contents = file_get_contents($candidatePath);
    if (!is_string($contents) || $contents === '') {
        return [
            'ok' => false,
            'error' => 'Uploaded file content was empty or unreadable.',
            'binary' => '',
            'tmp_name' => $candidatePath,
        ];
    }

    return [
        'ok' => true,
        'binary' => $contents,
        'tmp_name' => $candidatePath,
    ];
}

function aiCopilotDocumentIngestionStoreOriginalDocument(array $options): array
{
    aiCopilotDocumentIngestionRequireTables();

    $patientId = isset($options['patient_id']) && is_numeric($options['patient_id']) ? (int) $options['patient_id'] : 0;
    $docType = aiCopilotDocumentIngestionNormalizeDocType((string) ($options['doc_type'] ?? ''));
    $fileName = trim((string) ($options['file_name'] ?? ($options['file']['name'] ?? 'attached-document.pdf')));
    $displayFileName = trim((string) ($options['display_file_name'] ?? $fileName));
    $mimeType = trim((string) ($options['mime_type'] ?? ($options['file']['type'] ?? 'application/pdf')));
    $patientKey = trim((string) ($options['patient_key'] ?? ''));
    $patientName = trim((string) ($options['patient_name'] ?? ''));
    $role = trim((string) ($options['role'] ?? 'doctor'));
    $requestId = trim((string) ($options['request_id'] ?? 'request'));

    if ($patientId <= 0) {
        return [
            'ok' => false,
            'error' => 'A patient must be selected before document ingestion can continue.',
            'error_code' => 'patient_required',
        ];
    }

    if (!in_array($docType, ['lab_pdf', 'intake_form'], true)) {
        return [
            'ok' => false,
            'error' => 'Only lab PDFs and intake forms are supported in this MVP.',
            'error_code' => 'unsupported_doc_type',
        ];
    }

    $looksLikePdf = $mimeType === 'application/pdf' || preg_match('/\.pdf$/i', $fileName) === 1;
    if (!$looksLikePdf) {
        return [
            'ok' => false,
            'error' => 'Only PDF uploads are supported for this MVP workflow.',
            'error_code' => 'unsupported_mime_type',
        ];
    }

    $binaryResult = aiCopilotDocumentIngestionReadBinary($options);
    if (($binaryResult['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string) ($binaryResult['error'] ?? 'Uploaded file could not be read.'),
            'error_code' => 'binary_unavailable',
        ];
    }

    $binary = (string) ($binaryResult['binary'] ?? '');
    $tmpName = (string) ($binaryResult['tmp_name'] ?? '');
    $userId = aiCopilotDocumentIngestionCurrentUserId();
    $username = aiCopilotDocumentIngestionCurrentUsername();
    $higherLevelPath = 'ai_copilot/ingestion/' . $docType . '/' . $patientId;
    $pathDepth = aiCopilotDocumentIngestionPathDepth($higherLevelPath);
    $document = new \Document();
    $result = $document->createDocument(
        $patientId,
        null,
        $fileName,
        $mimeType !== '' ? $mimeType : 'application/pdf',
        $binary,
        $higherLevelPath,
        $pathDepth,
        $userId ?: 0,
        $tmpName !== '' ? $tmpName : null
    );

    if ($result !== '') {
        return [
            'ok' => false,
            'error' => 'The original source document could not be stored in OpenEMR document storage.',
            'error_code' => 'document_store_failed',
            'internal_message' => $result,
        ];
    }

    $openemrDocumentId = method_exists($document, 'get_id') ? (int) $document->get_id() : 0;
    $fileHash = hash('sha256', $binary);
    $fhirDocumentReferenceId = 'DocumentReference/openemr-document-' . $openemrDocumentId;
    $fhirBinaryId = 'Binary/openemr-document-' . $openemrDocumentId;
    $now = gmdate('Y-m-d H:i:s');

    $metadata = [
        'request_id' => $requestId,
        'patient_key' => $patientKey,
        'patient_name' => $patientName,
        'doc_type' => $docType,
        'file_name' => $fileName,
        'display_file_name' => $displayFileName,
        'mime_type' => $mimeType,
        'uploader_role' => $role,
        'uploader_user' => $username,
        'storage_path' => $higherLevelPath,
        'review_gate' => 'draft_only_pending_clinician_review',
        'fhir_equivalent' => [
            'document_reference_id' => $fhirDocumentReferenceId,
            'binary_id' => $fhirBinaryId,
            'write_mode' => 'equivalent_metadata_only',
        ],
    ];

    $sourceDocumentId = (int) sqlInsert(
        "INSERT INTO `ai_copilot_documents`
            (`patient_id`, `patient_key`, `patient_name`, `openemr_document_id`, `fhir_document_reference_id`, `fhir_binary_id`, `doc_type`, `file_name`, `display_file_name`, `file_hash`, `mime_type`, `upload_status`, `extraction_status`, `review_status`, `created_by`, `uploader_role`, `metadata_json`, `created_at`, `updated_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $patientId,
            $patientKey,
            $patientName,
            $openemrDocumentId > 0 ? $openemrDocumentId : null,
            $fhirDocumentReferenceId,
            $fhirBinaryId,
            $docType,
            $fileName,
            $displayFileName,
            $fileHash,
            $mimeType !== '' ? $mimeType : 'application/pdf',
            AI_COPILOT_DOCUMENT_UPLOAD_STATUS_UPLOADED,
            AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_PENDING,
            AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
            $userId,
            $role,
            aiCopilotDocumentIngestionJsonEncode($metadata),
            $now,
            $now,
        ]
    );

    return [
        'ok' => true,
        'source_document_id' => $sourceDocumentId,
        'patient_id' => $patientId,
        'patient_key' => $patientKey,
        'patient_name' => $patientName,
        'openemr_document_id' => $openemrDocumentId,
        'fhir_document_reference_id' => $fhirDocumentReferenceId,
        'fhir_binary_id' => $fhirBinaryId,
        'doc_type' => $docType,
        'file_name' => $fileName,
        'display_file_name' => $displayFileName,
        'file_hash' => $fileHash,
        'mime_type' => $mimeType !== '' ? $mimeType : 'application/pdf',
        'upload_status' => AI_COPILOT_DOCUMENT_UPLOAD_STATUS_UPLOADED,
        'extraction_status' => AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_PENDING,
        'review_status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
        'created_by' => $userId,
        'uploader_role' => $role,
        'uploader_user' => $username,
        'uploaded_at' => gmdate('c'),
        'metadata' => $metadata,
    ];
}

function aiCopilotDocumentIngestionUpdateDocumentRecord(int $sourceDocumentId, array $fields): void
{
    aiCopilotDocumentIngestionRequireTables();
    if ($sourceDocumentId <= 0 || $fields === []) {
        return;
    }

    $assignments = [];
    $bind = [];
    foreach ($fields as $column => $value) {
        $assignments[] = "`" . preg_replace('/[^a-z0-9_]/i', '', (string) $column) . "` = ?";
        $bind[] = is_array($value) ? aiCopilotDocumentIngestionJsonEncode($value) : $value;
    }
    $assignments[] = "`updated_at` = ?";
    $bind[] = gmdate('Y-m-d H:i:s');
    $bind[] = $sourceDocumentId;

    sqlStatementThrowException(
        "UPDATE `ai_copilot_documents` SET " . implode(', ', $assignments) . " WHERE `id` = ?",
        $bind
    );
}

function aiCopilotDocumentIngestionPersistFacts(int $sourceDocumentId, int $patientId, string $docType, array $facts): array
{
    aiCopilotDocumentIngestionRequireTables();

    $inserted = [];
    foreach ($facts as $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $sourceLink = is_array($fact['source_link'] ?? null)
            ? $fact['source_link']
            : (is_array($fact['source_citation'] ?? null) ? $fact['source_citation'] : []);
        $factType = trim((string) ($fact['fact_type'] ?? $fact['field_name'] ?? $fact['test_name'] ?? 'fact'));
        $confidence = isset($fact['confidence']) && is_numeric($fact['confidence']) ? round((float) $fact['confidence'], 4) : 0.0;
        $proposedTarget = trim((string) ($fact['proposed_openemr_or_fhir_target'] ?? $fact['proposed_fhir_resource_type'] ?? ''));

        $factId = (int) sqlInsert(
            "INSERT INTO `ai_copilot_extracted_facts`
                (`patient_id`, `document_id`, `doc_type`, `fact_type`, `fact_json`, `source_json`, `confidence`, `proposed_target`, `review_status`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $patientId,
                $sourceDocumentId,
                $docType,
                $factType,
                aiCopilotDocumentIngestionJsonEncode($fact),
                aiCopilotDocumentIngestionJsonEncode($sourceLink),
                $confidence,
                $proposedTarget,
                AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s'),
            ]
        );

        $inserted[] = [
            'fact_id' => $factId,
            'review_status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
            'fact' => $fact,
        ];
    }

    return $inserted;
}

function aiCopilotDocumentIngestionPersistRagChunks(int $sourceDocumentId, int $patientId, string $docType, array $vectorizedResult, string $reviewStatus = AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING): int
{
    aiCopilotDocumentIngestionRequireTables();
    $count = 0;
    foreach ($vectorizedResult as $chunk) {
        if (!is_array($chunk)) {
            continue;
        }

        sqlInsert(
            "INSERT INTO `ai_copilot_rag_chunks`
                (`patient_id`, `document_id`, `doc_type`, `chunk_id`, `chunk_text_redacted_or_minimum_necessary`, `embedding_reference`, `metadata_json`, `review_status`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $patientId,
                $sourceDocumentId,
                $docType,
                trim((string) ($chunk['id'] ?? '')),
                trim((string) ($chunk['text_preview'] ?? $chunk['chunk_text'] ?? '')),
                trim((string) ($chunk['id'] ?? '')),
                aiCopilotDocumentIngestionJsonEncode($chunk),
                $reviewStatus,
                gmdate('Y-m-d H:i:s'),
            ]
        );
        $count++;
    }

    return $count;
}

function aiCopilotDocumentIngestionPersistTrace(array $trace): void
{
    aiCopilotDocumentIngestionRequireTables();
    $requestId = trim((string) ($trace['request_id'] ?? ''));
    if ($requestId === '') {
        return;
    }

    sqlInsert(
        "INSERT INTO `ai_copilot_agent_traces`
            (`request_id`, `patient_id`, `user_id`, `role`, `workflow_name`, `safe_trace_json`, `created_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            $requestId,
            isset($trace['patient_id']) && is_numeric($trace['patient_id']) ? (int) $trace['patient_id'] : null,
            isset($trace['user_id']) && is_numeric($trace['user_id']) ? (int) $trace['user_id'] : null,
            trim((string) ($trace['role'] ?? '')),
            trim((string) ($trace['workflow_name'] ?? 'document_ingestion_mvp')),
            aiCopilotDocumentIngestionJsonEncode($trace['safe_trace_json'] ?? []),
            gmdate('Y-m-d H:i:s'),
        ]
    );
}

function aiCopilotDocumentIngestionListFactsByDocument(int $sourceDocumentId): array
{
    aiCopilotDocumentIngestionRequireTables();
    $rows = [];
    $statement = sqlStatementThrowException(
        "SELECT `id`, `fact_json`, `source_json`, `confidence`, `proposed_target`, `review_status`
         FROM `ai_copilot_extracted_facts`
         WHERE `document_id` = ?
         ORDER BY `id` ASC",
        [$sourceDocumentId]
    );

    while ($row = sqlFetchArray($statement)) {
        $rows[] = [
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'fact' => aiCopilotDocumentIngestionJsonDecode((string) ($row['fact_json'] ?? '')),
            'source' => aiCopilotDocumentIngestionJsonDecode((string) ($row['source_json'] ?? '')),
            'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : 0.0,
            'proposed_target' => trim((string) ($row['proposed_target'] ?? '')),
            'review_status' => trim((string) ($row['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING)),
        ];
    }

    return $rows;
}

function aiCopilotDocumentIngestionReviewFact(int $factId, string $decision, string $role, ?int $reviewerUserId = null, string $note = ''): array
{
    aiCopilotDocumentIngestionRequireTables();
    $decision = strtolower(trim($decision));
    $role = strtolower(trim($role));
    $reviewerUserId = $reviewerUserId ?: aiCopilotDocumentIngestionCurrentUserId();

    $factRow = sqlQuery(
        "SELECT `id`, `document_id`, `fact_json`, `proposed_target`, `review_status`
         FROM `ai_copilot_extracted_facts`
         WHERE `id` = ?
         LIMIT 1",
        [$factId]
    );
    if (!$factRow) {
        return [
            'ok' => false,
            'error' => 'The selected pending fact could not be found.',
            'error_code' => 'fact_not_found',
        ];
    }

    $factPayload = aiCopilotDocumentIngestionJsonDecode((string) ($factRow['fact_json'] ?? ''));
    $proposedTarget = strtolower(trim((string) ($factRow['proposed_target'] ?? '')));
    $allowedDecisions = ['approve', 'reject', 'pending'];
    if (!in_array($decision, $allowedDecisions, true)) {
        return [
            'ok' => false,
            'error' => 'Unsupported review decision.',
            'error_code' => 'unsupported_review_decision',
        ];
    }

    if (in_array($role, ['billing', 'front_desk'], true)) {
        return [
            'ok' => false,
            'error' => 'This role cannot review clinical extracted facts.',
            'error_code' => 'role_review_blocked',
        ];
    }

    if ($decision === 'approve' && $role === 'nurse') {
        $nurseSafeTargets = ['questionnaireresponse', 'coverage', 'relatedperson', 'patientpreference', 'care_preferences'];
        if (!in_array(str_replace([' ', '\\', '/'], '', $proposedTarget), $nurseSafeTargets, true)) {
            return [
                'ok' => false,
                'error' => 'Nurse review can leave this fact pending or reject it, but approval requires a clinician for this fact type.',
                'error_code' => 'nurse_approval_blocked',
            ];
        }
    }

    $reviewStatus = match ($decision) {
        'approve' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_APPROVED,
        'reject' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_REJECTED,
        default => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
    };

    sqlStatementThrowException(
        "UPDATE `ai_copilot_extracted_facts`
         SET `review_status` = ?, `updated_at` = ?
         WHERE `id` = ?",
        [$reviewStatus, gmdate('Y-m-d H:i:s'), $factId]
    );
    sqlInsert(
        "INSERT INTO `ai_copilot_fact_reviews`
            (`fact_id`, `reviewer_user_id`, `decision`, `note`, `created_at`)
         VALUES (?, ?, ?, ?, ?)",
        [$factId, $reviewerUserId, $decision, $note, gmdate('Y-m-d H:i:s')]
    );

    return [
        'ok' => true,
        'fact_id' => $factId,
        'document_id' => isset($factRow['document_id']) ? (int) $factRow['document_id'] : 0,
        'decision' => $decision,
        'review_status' => $reviewStatus,
        'fact' => $factPayload,
        'message' => $decision === 'approve'
            ? 'Approved for demo review — not written to chart automatically.'
            : ($decision === 'reject'
                ? 'Fact rejected for demo review. No chart write occurred.'
                : 'Fact left pending clinician review. No chart write occurred.'),
    ];
}

function aiCopilotDocumentIngestionBuildReviewSummary(int $sourceDocumentId): array
{
    $facts = aiCopilotDocumentIngestionListFactsByDocument($sourceDocumentId);
    $summary = [
        'document_id' => $sourceDocumentId,
        'pending_count' => 0,
        'approved_count' => 0,
        'rejected_count' => 0,
    ];

    foreach ($facts as $fact) {
        $status = (string) ($fact['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING);
        if ($status === AI_COPILOT_DOCUMENT_REVIEW_STATUS_APPROVED) {
            $summary['approved_count']++;
        } elseif ($status === AI_COPILOT_DOCUMENT_REVIEW_STATUS_REJECTED) {
            $summary['rejected_count']++;
        } else {
            $summary['pending_count']++;
        }
    }

    return $summary;
}
