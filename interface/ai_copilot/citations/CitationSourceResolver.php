<?php

require_once(dirname(__DIR__) . '/api/document_ingestion_store.php');
require_once(__DIR__ . '/CitationContract.php');
require_once(__DIR__ . '/CitationValidator.php');
require_once(dirname(__DIR__) . '/rag/chunk_guidelines.php');
require_once(dirname(__DIR__, 2) . '/globals.php');
require_once(dirname(__DIR__, 3) . '/src/Services/DocumentService.php');

use OpenEMR\Services\DocumentService;
use OpenEMR\Core\OEGlobalsBag;

function aiCopilotCitationFindDocumentRecord(int $sourceDocumentId): array
{
    aiCopilotDocumentIngestionRequireTables();
    if ($sourceDocumentId <= 0) {
        return [];
    }

    $row = sqlQuery(
        "SELECT `id`, `patient_id`, `patient_key`, `patient_name`, `openemr_document_id`, `fhir_document_reference_id`, `fhir_binary_id`, `doc_type`, `file_name`, `display_file_name`, `mime_type`, `review_status`, `metadata_json`
         FROM `ai_copilot_documents`
         WHERE `id` = ?
         LIMIT 1",
        [$sourceDocumentId]
    );
    if (!$row) {
        return [];
    }

    return [
        'source_document_id' => isset($row['id']) ? (int) $row['id'] : 0,
        'patient_id' => isset($row['patient_id']) ? (int) $row['patient_id'] : 0,
        'patient_key' => trim((string) ($row['patient_key'] ?? '')),
        'patient_name' => trim((string) ($row['patient_name'] ?? '')),
        'openemr_document_id' => isset($row['openemr_document_id']) && is_numeric($row['openemr_document_id']) ? (int) $row['openemr_document_id'] : null,
        'fhir_document_reference_id' => trim((string) ($row['fhir_document_reference_id'] ?? '')),
        'fhir_binary_id' => trim((string) ($row['fhir_binary_id'] ?? '')),
        'doc_type' => trim((string) ($row['doc_type'] ?? '')),
        'file_name' => trim((string) ($row['file_name'] ?? '')),
        'display_file_name' => trim((string) ($row['display_file_name'] ?? '')),
        'mime_type' => trim((string) ($row['mime_type'] ?? 'application/pdf')),
        'review_status' => trim((string) ($row['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING)),
        'metadata' => aiCopilotDocumentIngestionJsonDecode((string) ($row['metadata_json'] ?? '')),
    ];
}

function aiCopilotCitationFindChunkPreview(string $chunkId, int $patientId = 0): array
{
    aiCopilotDocumentIngestionRequireTables();
    $params = [$chunkId];
    $sql = "SELECT `document_id`, `patient_id`, `doc_type`, `chunk_id`, `chunk_text_redacted_or_minimum_necessary`, `metadata_json`, `review_status`
            FROM `ai_copilot_rag_chunks`
            WHERE `chunk_id` = ?";
    if ($patientId > 0) {
        $sql .= " AND `patient_id` = ?";
        $params[] = $patientId;
    }
    $sql .= " LIMIT 1";

    $row = sqlQuery($sql, $params);
    if (!$row) {
        return [];
    }

    return [
        'document_id' => isset($row['document_id']) ? (int) $row['document_id'] : 0,
        'patient_id' => isset($row['patient_id']) ? (int) $row['patient_id'] : 0,
        'doc_type' => trim((string) ($row['doc_type'] ?? '')),
        'chunk_id' => trim((string) ($row['chunk_id'] ?? '')),
        'chunk_text' => trim((string) ($row['chunk_text_redacted_or_minimum_necessary'] ?? '')),
        'metadata' => aiCopilotDocumentIngestionJsonDecode((string) ($row['metadata_json'] ?? '')),
        'review_status' => trim((string) ($row['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING)),
    ];
}

function aiCopilotCitationResolveSourcePreview(array $citation, array $options = []): array
{
    $normalized = aiCopilotBuildCitation($citation, $options);
    $validation = aiCopilotCitationValidate($normalized, $options);
    if ($validation['blocked']) {
        return [
            'ok' => false,
            'error' => 'This source cannot be opened in the current patient or role scope.',
            'error_code' => 'citation_access_blocked',
        ];
    }

    $patientId = isset($options['patient_id']) && is_numeric($options['patient_id']) ? (int) $options['patient_id'] : 0;
    $sourceDocumentId = isset($normalized['source_document_id']) && is_numeric($normalized['source_document_id']) ? (int) $normalized['source_document_id'] : 0;
    $documentRecord = $sourceDocumentId > 0 ? aiCopilotCitationFindDocumentRecord($sourceDocumentId) : [];
    if ($documentRecord !== [] && $patientId > 0 && (int) ($documentRecord['patient_id'] ?? 0) !== $patientId) {
        return [
            'ok' => false,
            'error' => 'This cited source belongs to a different patient and cannot be previewed here.',
            'error_code' => 'wrong_patient',
        ];
    }

    $previewMode = 'text';
    $previewUrl = '';
    $previewText = trim((string) ($normalized['quote_or_value'] ?? ''));
    $sourceLabel = $documentRecord !== []
        ? trim((string) ($documentRecord['display_file_name'] ?? $documentRecord['file_name'] ?? ''))
        : aiCopilotCitationSourceLabel($normalized);

    if (($normalized['source_type'] ?? '') === 'demo_guideline') {
        $guidelineChunk = aiCopilotGuidelineFindChunkByIdentifiers(
            trim((string) ($normalized['source_id'] ?? '')),
            trim((string) ($normalized['field_or_chunk_id'] ?? ''))
        );
        if ($guidelineChunk !== []) {
            $previewMode = 'rag_snippet';
            $previewText = trim((string) ($guidelineChunk['text'] ?? $previewText));
            $sourceLabel = trim((string) ($guidelineChunk['title'] ?? $sourceLabel));
        }
    }

    if ($documentRecord !== []) {
        $mimeType = trim((string) ($documentRecord['mime_type'] ?? 'application/pdf'));
        if ($mimeType === 'application/pdf' && !empty($documentRecord['openemr_document_id'])) {
            $previewMode = 'pdf';
            $roleQuery = trim((string) ($options['role'] ?? ''));
            $previewUrl = OEGlobalsBag::getInstance()->getWebRoot()
                . '/interface/ai_copilot/api/document_preview.php?source_document_id='
                . rawurlencode((string) $sourceDocumentId)
                . '&patient_id=' . rawurlencode((string) ($documentRecord['patient_id'] ?? 0));
            if ($roleQuery !== '') {
                $previewUrl .= '&role=' . rawurlencode($roleQuery);
            }
        }
    }

    if (($normalized['source_type'] ?? '') === 'rag_chunk' && trim((string) ($normalized['field_or_chunk_id'] ?? '')) !== '') {
        $chunkPreview = aiCopilotCitationFindChunkPreview((string) $normalized['field_or_chunk_id'], $patientId);
        if ($chunkPreview !== []) {
            $previewMode = 'rag_snippet';
            $previewText = trim((string) ($chunkPreview['chunk_text'] ?? $previewText));
            if ($documentRecord === [] && !empty($chunkPreview['document_id'])) {
                $documentRecord = aiCopilotCitationFindDocumentRecord((int) $chunkPreview['document_id']);
                if ($documentRecord !== []) {
                    $sourceLabel = trim((string) ($documentRecord['display_file_name'] ?? $documentRecord['file_name'] ?? $sourceLabel));
                }
            }
        }
    }

    return [
        'ok' => true,
        'citation' => $normalized,
        'source_label' => $sourceLabel !== '' ? $sourceLabel : aiCopilotCitationSourceLabel($normalized),
        'source_type' => trim((string) ($normalized['source_type'] ?? 'unknown')),
        'page_or_section' => trim((string) ($normalized['page_or_section'] ?? '')),
        'field_or_chunk_id' => trim((string) ($normalized['field_or_chunk_id'] ?? '')),
        'quote_or_value' => trim((string) ($normalized['quote_or_value'] ?? '')),
        'confidence' => isset($normalized['confidence']) && is_numeric($normalized['confidence']) ? round((float) $normalized['confidence'], 4) : 0.0,
        'review_status' => trim((string) ($normalized['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING)),
        'document_type' => trim((string) ($normalized['document_type'] ?? ($documentRecord['doc_type'] ?? ''))),
        'preview_mode' => $previewMode,
        'preview_url' => $previewUrl,
        'preview_text' => $previewText,
        'source_url' => $previewUrl,
        'bounding_box' => aiCopilotCitationNormalizeBoundingBox($normalized['bounding_box'] ?? null),
        'document' => $documentRecord,
    ];
}
