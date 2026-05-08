<?php

function aiCopilotSchemaValidationSchemaPath(string $docType): string
{
    $fileName = $docType === 'intake_form' ? 'intake_form.schema.json' : 'lab_pdf.schema.json';
    return dirname(__DIR__) . '/schemas/' . $fileName;
}

function aiCopilotSchemaValidationLoadSchema(string $docType): array
{
    $path = aiCopilotSchemaValidationSchemaPath($docType);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function aiCopilotSchemaValidationIssue(
    string $field,
    string $issue,
    string $suggestedReviewAction,
    string $code = 'validation_error',
    string $severity = 'error'
): array {
    return [
        'field' => $field,
        'issue' => $issue,
        'suggested_review_action' => $suggestedReviewAction,
        'code' => $code,
        'severity' => $severity,
    ];
}

function aiCopilotSchemaValidationSummarizeIssue(array $issue): string
{
    $field = trim((string) ($issue['field'] ?? 'field'));
    $message = trim((string) ($issue['issue'] ?? 'Validation issue detected.'));
    return $field !== '' ? $field . ': ' . $message : $message;
}

function aiCopilotSchemaValidationNonEmptyString(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

function aiCopilotSchemaValidationString(mixed $value): bool
{
    return is_string($value);
}

function aiCopilotSchemaValidationNumericUnitInterval(mixed $value): bool
{
    return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1;
}

function aiCopilotSchemaValidationIsoDateTime(mixed $value): bool
{
    if (!is_string($value) || trim($value) === '') {
        return false;
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return false;
    }

    return $date->format(DateTimeInterface::ATOM) !== '';
}

function aiCopilotSchemaValidationIsoDate(mixed $value): bool
{
    if (!is_string($value) || trim($value) === '') {
        return false;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return true;
    }

    return aiCopilotSchemaValidationIsoDateTime($value);
}

function aiCopilotSchemaValidationEnum(mixed $value, array $allowed): bool
{
    return is_string($value) && in_array($value, $allowed, true);
}

function aiCopilotSchemaValidationMissingMarkerPresent(array $missingData, string $field, array $keywords = []): bool
{
    $needles = array_values(array_filter(array_map('strtolower', array_merge([$field], $keywords)), static fn($item) => $item !== ''));
    if ($needles === []) {
        return false;
    }

    foreach ($missingData as $item) {
        $haystack = '';
        if (is_string($item)) {
            $haystack = strtolower($item);
        } elseif (is_array($item)) {
            $haystack = strtolower(trim((string) (($item['field'] ?? '') . ' ' . ($item['issue'] ?? ''))));
        }

        if ($haystack === '') {
            continue;
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
    }

    return false;
}

function aiCopilotSchemaValidationValidateCitation(array $citation, string $fieldPath): array
{
    $issues = [];

    if (!aiCopilotSchemaValidationNonEmptyString($citation['source_document_id'] ?? null)) {
        $issues[] = aiCopilotSchemaValidationIssue(
            $fieldPath . '.source_document_id',
            'Missing source_document_id.',
            'Confirm which uploaded document supports this fact before using it.',
            'missing_source_citation'
        );
    }

    $hasPageNumber = array_key_exists('page_number', $citation) && ($citation['page_number'] === null || is_numeric($citation['page_number']));
    $hasPageSection = aiCopilotSchemaValidationString($citation['page_or_section'] ?? null);
    if (!$hasPageNumber && !$hasPageSection) {
        $issues[] = aiCopilotSchemaValidationIssue(
            $fieldPath,
            'Source citation is missing a page number or page_or_section.',
            'Add a page number or source section before using this fact.',
            'missing_source_citation'
        );
    }

    if (!array_key_exists('quote_or_value', $citation) || !is_string($citation['quote_or_value'])) {
        $issues[] = aiCopilotSchemaValidationIssue(
            $fieldPath . '.quote_or_value',
            'Missing quote_or_value.',
            'Attach the exact quoted value or snippet from the uploaded document.',
            'missing_source_citation'
        );
    }

    if (!aiCopilotSchemaValidationNumericUnitInterval($citation['confidence'] ?? null)) {
        $issues[] = aiCopilotSchemaValidationIssue(
            $fieldPath . '.confidence',
            'Source citation confidence must be between 0 and 1.',
            'Re-run extraction or review the original document.',
            'invalid_confidence'
        );
    }

    return $issues;
}

function aiCopilotSchemaValidationFinalize(
    array $schema,
    array $normalizedPayload,
    array $hardErrors,
    array $reviewIssues
): array {
    $schemaName = (string) ($schema['title'] ?? 'ExtractionSchema');
    $schemaFile = (string) ($schema['$id'] ?? '');
    $schemaValid = $hardErrors === [];
    $normalizedPayload['review_status'] = 'pending_clinician_review';

    $missing = is_array($normalizedPayload['missing_or_ambiguous_data'] ?? null)
        ? $normalizedPayload['missing_or_ambiguous_data']
        : [];
    foreach ($reviewIssues as $issue) {
        $missing[] = $issue;
    }
    $normalizedPayload['missing_or_ambiguous_data'] = array_values($missing);

    $extractionStatus = (string) ($normalizedPayload['extraction_status'] ?? 'review_required');
    if (!$schemaValid) {
        $extractionStatus = $extractionStatus === 'failed' ? 'failed' : 'review_required';
    } elseif ($reviewIssues !== [] && $extractionStatus !== 'failed') {
        $extractionStatus = 'review_required';
    } elseif ($extractionStatus === '') {
        $extractionStatus = 'ok';
    }
    $normalizedPayload['extraction_status'] = $extractionStatus;

    $allIssues = array_merge($hardErrors, $reviewIssues);
    $statusLabel = 'Strict schema passed';
    if (!$schemaValid) {
        $codes = array_values(array_filter(array_map(static fn($issue) => (string) ($issue['code'] ?? ''), $hardErrors), static fn($item) => $item !== ''));
        if (in_array('unsupported_doc_type', $codes, true)) {
            $statusLabel = 'Unsupported document type';
        } elseif (in_array('missing_source_citation', $codes, true)) {
            $statusLabel = 'Missing source citation';
        } elseif (in_array('missing_required_field', $codes, true)) {
            $statusLabel = 'Missing required field';
        } else {
            $statusLabel = 'Schema validation failed';
        }
    } elseif ($extractionStatus === 'review_required') {
        $statusLabel = 'Clinician review required';
    }

    return [
        'schema_name' => $schemaName,
        'schema_file' => $schemaFile,
        'schema_valid' => $schemaValid,
        'valid' => $schemaValid,
        'review_safe' => $schemaValid,
        'trusted_persistence_allowed' => $schemaValid,
        'trusted_rag_index_allowed' => $schemaValid,
        'status_label' => $statusLabel,
        'user_message' => $schemaValid
            ? ''
            : 'Extraction completed, but the result did not pass strict validation. Clinician review is required before this information can be used.',
        'validation_errors' => array_values($allIssues),
        'validation_error_count' => count($allIssues),
        'errors' => array_values(array_map('aiCopilotSchemaValidationSummarizeIssue', $allIssues)),
        'missing_required_field_count' => count(array_filter($hardErrors, static fn($issue) => (string) ($issue['code'] ?? '') === 'missing_required_field')),
        'citation_count' => count(is_array($normalizedPayload['source_citations'] ?? null) ? $normalizedPayload['source_citations'] : []),
        'extraction_status' => $normalizedPayload['extraction_status'],
        'review_status' => 'pending_clinician_review',
        'normalized_payload' => $normalizedPayload,
    ];
}

require_once(__DIR__ . '/validate_lab_pdf.php');
require_once(__DIR__ . '/validate_intake_form.php');

function aiCopilotValidateStrictExtraction(string $docType, array $payload, array $context = []): array
{
    $normalizedDocType = strtolower(trim($docType));
    return match ($normalizedDocType) {
        'lab_pdf' => aiCopilotValidateLabPdfExtractionPayload($payload, $context),
        'intake_form' => aiCopilotValidateIntakeFormExtractionPayload($payload, $context),
        default => [
            'schema_name' => 'UnsupportedDocumentType',
            'schema_file' => '',
            'schema_valid' => false,
            'valid' => false,
            'review_safe' => false,
            'trusted_persistence_allowed' => false,
            'trusted_rag_index_allowed' => false,
            'status_label' => 'Unsupported document type',
            'user_message' => 'Extraction completed, but the result did not pass strict validation. Clinician review is required before this information can be used.',
            'validation_errors' => [
                aiCopilotSchemaValidationIssue(
                    'document_type',
                    'Unsupported document type.',
                    'Select Lab PDF or Intake Form before uploading.',
                    'unsupported_doc_type'
                ),
            ],
            'errors' => ['document_type: Unsupported document type.'],
            'missing_required_field_count' => 0,
            'citation_count' => 0,
            'extraction_status' => 'failed',
            'review_status' => 'pending_clinician_review',
            'normalized_payload' => $payload,
        ],
    };
}
