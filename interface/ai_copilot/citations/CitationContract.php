<?php

final class CitationContract
{
    public const SOURCE_TYPES = [
        'demo_guideline',
        'uploaded_document',
        'lab_pdf',
        'intake_form',
        'rag_chunk',
        'openemr_chart',
        'fhir_resource',
        'clinician_reviewed_fact',
        'ambient_encounter',
        'unknown',
    ];
}

function aiCopilotCitationAllowedSourceTypes(): array
{
    return CitationContract::SOURCE_TYPES;
}

function aiCopilotCitationNormalizeBoundingBox(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $coordinateSystem = trim((string) ($value['coordinate_system'] ?? $value['coordinateSystem'] ?? 'normalized_0_1'));
    if (!in_array($coordinateSystem, ['normalized_0_1', 'pdf_points'], true)) {
        $coordinateSystem = 'normalized_0_1';
    }

    $page = isset($value['page']) && is_numeric($value['page']) ? (int) $value['page'] : null;
    $x = isset($value['x']) && is_numeric($value['x']) ? (float) $value['x'] : null;
    $y = isset($value['y']) && is_numeric($value['y']) ? (float) $value['y'] : null;
    $width = isset($value['width']) && is_numeric($value['width']) ? (float) $value['width'] : null;
    $height = isset($value['height']) && is_numeric($value['height']) ? (float) $value['height'] : null;

    if ($page === null || $x === null || $y === null || $width === null || $height === null) {
        return null;
    }

    return [
        'page' => $page,
        'x' => $x,
        'y' => $y,
        'width' => $width,
        'height' => $height,
        'coordinate_system' => $coordinateSystem,
    ];
}

function aiCopilotBuildCitation(array $input, array $defaults = []): array
{
    $sourceType = strtolower(trim((string) ($input['source_type'] ?? $defaults['source_type'] ?? 'unknown')));
    if (!in_array($sourceType, aiCopilotCitationAllowedSourceTypes(), true)) {
        $sourceType = 'unknown';
    }

    $sourceId = trim((string) ($input['source_id'] ?? $defaults['source_id'] ?? ''));
    $sourceDocumentId = isset($input['source_document_id']) && is_numeric($input['source_document_id'])
        ? (int) $input['source_document_id']
        : (isset($defaults['source_document_id']) && is_numeric($defaults['source_document_id']) ? (int) $defaults['source_document_id'] : null);
    if ($sourceId === '' && $sourceDocumentId !== null) {
        $sourceId = 'source_document_' . $sourceDocumentId;
    }

    $pageOrSection = trim((string) ($input['page_or_section'] ?? $defaults['page_or_section'] ?? ''));
    if ($pageOrSection === '') {
        $pageNumber = isset($input['page_number']) && is_numeric($input['page_number'])
            ? (int) $input['page_number']
            : (isset($defaults['page_number']) && is_numeric($defaults['page_number']) ? (int) $defaults['page_number'] : null);
        $pageOrSection = $pageNumber !== null ? 'page ' . $pageNumber : 'unknown_section';
    }

    $fieldOrChunkId = trim((string) ($input['field_or_chunk_id'] ?? $defaults['field_or_chunk_id'] ?? ''));
    if ($fieldOrChunkId === '') {
        $fieldOrChunkId = trim((string) ($input['field_name'] ?? $input['chunk_id'] ?? $defaults['field_name'] ?? ''));
    }

    $quoteOrValue = trim((string) ($input['quote_or_value'] ?? $input['source_quote_or_value'] ?? $defaults['quote_or_value'] ?? ''));
    $confidence = isset($input['confidence']) && is_numeric($input['confidence'])
        ? round((float) $input['confidence'], 4)
        : (isset($defaults['confidence']) && is_numeric($defaults['confidence']) ? round((float) $defaults['confidence'], 4) : 0.0);
    $reviewStatus = trim((string) ($input['review_status'] ?? $defaults['review_status'] ?? 'pending_clinician_review'));

    return array_filter([
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'page_or_section' => $pageOrSection,
        'field_or_chunk_id' => $fieldOrChunkId,
        'quote_or_value' => $quoteOrValue,
        'confidence' => $confidence,
        'document_type' => trim((string) ($input['document_type'] ?? $defaults['document_type'] ?? '')),
        'resource_type' => trim((string) ($input['resource_type'] ?? $defaults['resource_type'] ?? '')),
        'patient_id' => isset($input['patient_id']) && is_numeric($input['patient_id'])
            ? (int) $input['patient_id']
            : (isset($defaults['patient_id']) && is_numeric($defaults['patient_id']) ? (int) $defaults['patient_id'] : null),
        'uploaded_document_id' => $sourceDocumentId,
        'source_document_id' => $sourceDocumentId,
        'fhir_document_reference_id' => trim((string) ($input['fhir_document_reference_id'] ?? $defaults['fhir_document_reference_id'] ?? '')),
        'fhir_binary_id' => trim((string) ($input['fhir_binary_id'] ?? $defaults['fhir_binary_id'] ?? '')),
        'bounding_box' => aiCopilotCitationNormalizeBoundingBox($input['bounding_box'] ?? $input['boundingBox'] ?? $defaults['bounding_box'] ?? null),
        'source_url' => trim((string) ($input['source_url'] ?? $defaults['source_url'] ?? '')),
        'review_status' => $reviewStatus !== '' ? $reviewStatus : 'pending_clinician_review',
    ], static fn($value) => $value !== null && $value !== '');
}

function aiCopilotCitationSourceLabel(array $citation): string
{
    $documentType = strtolower(trim((string) ($citation['document_type'] ?? '')));
    $sourceType = strtolower(trim((string) ($citation['source_type'] ?? 'unknown')));

    if ($sourceType === 'demo_guideline') {
        return 'Demo Guideline';
    }
    if ($documentType === 'intake_form' || $sourceType === 'intake_form') {
        return 'Uploaded Intake Form';
    }
    if ($documentType === 'lab_pdf' || $documentType === 'lab_results' || $sourceType === 'lab_pdf') {
        return 'Uploaded Lab PDF';
    }
    if ($sourceType === 'rag_chunk') {
        return 'RAG Evidence Snippet';
    }
    if ($sourceType === 'openemr_chart') {
        return 'OpenEMR Chart Context';
    }
    if ($sourceType === 'fhir_resource') {
        return 'FHIR Resource';
    }
    if ($sourceType === 'clinician_reviewed_fact') {
        return 'Clinician-reviewed Fact';
    }
    if ($sourceType === 'ambient_encounter') {
        return 'Ambient Encounter';
    }

    return 'Uploaded Document';
}

function aiCopilotBuildSourceUsedEntry(array $citation, array $overrides = []): array
{
    $label = trim((string) ($overrides['label'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($overrides['display_file_name'] ?? $overrides['original_file_name'] ?? ''));
    }
    if ($label === '') {
        $label = aiCopilotCitationSourceLabel($citation);
    }

    return [
        'source_id' => trim((string) ($citation['source_id'] ?? '')),
        'source_type' => trim((string) ($citation['source_type'] ?? 'unknown')),
        'label' => $label,
        'document_type' => trim((string) ($citation['document_type'] ?? '')),
        'source_document_id' => isset($citation['source_document_id']) && is_numeric($citation['source_document_id']) ? (int) $citation['source_document_id'] : null,
    ];
}
