<?php

function aiCopilotValidateLabPdfExtractionPayload(array $payload, array $context = []): array
{
    $schema = aiCopilotSchemaValidationLoadSchema('lab_pdf');
    $normalized = $payload;
    $normalized['review_status'] = 'pending_clinician_review';
    $hardErrors = [];
    $reviewIssues = [];
    $missing = is_array($normalized['missing_or_ambiguous_data'] ?? null) ? $normalized['missing_or_ambiguous_data'] : [];

    $requiredKeys = [
        'document_type',
        'patient_id',
        'source_document_id',
        'extraction_status',
        'confidence',
        'extracted_at',
        'labs',
        'missing_or_ambiguous_data',
        'source_citations',
        'safety_warnings',
        'review_status',
    ];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $normalized)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $key,
                'Missing required field.',
                'Review the extraction output and re-run ingestion if needed.',
                'missing_required_field'
            );
        }
    }

    if (($normalized['document_type'] ?? null) !== 'lab_pdf') {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'document_type',
            'document_type must equal lab_pdf.',
            'Route this upload through the Lab PDF workflow only.',
            'unsupported_doc_type'
        );
    }

    if (!aiCopilotSchemaValidationNonEmptyString($normalized['patient_id'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'patient_id',
            'patient_id is required.',
            'Select a patient before using the extracted output.',
            'missing_required_field'
        );
    }

    if (!aiCopilotSchemaValidationNonEmptyString($normalized['source_document_id'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'source_document_id',
            'source_document_id is required.',
            'Confirm the uploaded document was stored before using extracted data.',
            'missing_required_field'
        );
    }

    if (!aiCopilotSchemaValidationEnum($normalized['extraction_status'] ?? null, ['ok', 'review_required', 'failed'])) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'extraction_status',
            'Invalid extraction_status.',
            'Use ok, review_required, or failed.',
            'invalid_enum'
        );
    }

    if (!aiCopilotSchemaValidationNumericUnitInterval($normalized['confidence'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'confidence',
            'confidence must be between 0 and 1.',
            'Re-run extraction or lower trust in this result until reviewed.',
            'invalid_confidence'
        );
    }

    if (!aiCopilotSchemaValidationIsoDateTime($normalized['extracted_at'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'extracted_at',
            'extracted_at must be an ISO datetime string.',
            'Record the extraction timestamp before using this output.',
            'invalid_datetime'
        );
    }

    if (!is_array($normalized['labs'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'labs',
            'labs must be an array.',
            'Return lab extraction rows as an array.',
            'invalid_type'
        );
        $normalized['labs'] = [];
    }

    if (!is_array($normalized['source_citations'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'source_citations',
            'source_citations must be an array.',
            'Attach citations for each extracted lab fact.',
            'invalid_type'
        );
        $normalized['source_citations'] = [];
    }

    if (!is_array($normalized['safety_warnings'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'safety_warnings',
            'safety_warnings must be an array.',
            'Include draft-only clinician-review safety language.',
            'invalid_type'
        );
    }

    foreach (($normalized['source_citations'] ?? []) as $index => $citation) {
        if (!is_array($citation)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                'source_citations[' . $index . ']',
                'Each source citation must be an object.',
                'Re-run extraction and include structured source citations.',
                'invalid_type'
            );
            continue;
        }

        $hardErrors = array_merge(
            $hardErrors,
            aiCopilotSchemaValidationValidateCitation($citation, 'source_citations[' . $index . ']')
        );
    }

    foreach (($normalized['labs'] ?? []) as $index => $lab) {
        $fieldBase = 'labs[' . $index . ']';
        if (!is_array($lab)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase,
                'Each lab item must be an object.',
                'Re-run extraction and return structured lab items.',
                'invalid_type'
            );
            continue;
        }

        foreach (['test_name', 'value', 'unit', 'reference_range', 'collection_date', 'abnormal_flag', 'source_citation', 'review_status'] as $fieldName) {
            if (!array_key_exists($fieldName, $lab)) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $fieldName,
                    'Missing required field.',
                    'Complete the extracted lab row before using it.',
                    'missing_required_field'
                );
            }
        }

        if (!aiCopilotSchemaValidationNonEmptyString($lab['test_name'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.test_name',
                'Lab result is missing test_name.',
                'Verify the lab test name from the original PDF.',
                'missing_required_field'
            );
        }

        $value = $lab['value'] ?? null;
        $hasValue = (is_string($value) && trim($value) !== '') || is_numeric($value);
        if (!$hasValue) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.value',
                'Lab result is missing value.',
                'Verify the reported lab value from the original PDF.',
                'missing_required_field'
            );
        }

        if (!array_key_exists('unit', $lab)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.unit',
                'Lab result is missing unit.',
                'Add the reported unit or flag the missing unit for review.',
                'missing_required_field'
            );
        } elseif (trim((string) $lab['unit']) === '' && !aiCopilotSchemaValidationMissingMarkerPresent($missing, (string) ($lab['test_name'] ?? 'unit'), ['unit'])) {
            $reviewIssues[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.unit',
                'Missing required field: unit.',
                'Confirm the unit from the source PDF before using this result.',
                'missing_required_field',
                'warning'
            );
        }

        if (!array_key_exists('reference_range', $lab)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.reference_range',
                'Lab result is missing reference_range.',
                'Add the reported reference range or flag it for clinician review.',
                'missing_required_field'
            );
        } elseif (trim((string) $lab['reference_range']) === '' && !aiCopilotSchemaValidationMissingMarkerPresent($missing, (string) ($lab['test_name'] ?? 'reference range'), ['reference range'])) {
            $reviewIssues[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.reference_range',
                'Lab result "' . trim((string) ($lab['test_name'] ?? 'Unknown test')) . '" is missing a reference range. This result requires clinician review before it can be used.',
                'Confirm the reference range from the original PDF before using this result.',
                'missing_required_field',
                'warning'
            );
        }

        if (!array_key_exists('collection_date', $lab)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.collection_date',
                'Missing required field.',
                'Add the collection date or mark the result for review.',
                'missing_required_field'
            );
        } elseif (trim((string) $lab['collection_date']) === '' || !aiCopilotSchemaValidationIsoDate((string) $lab['collection_date'])) {
            $reviewIssues[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.collection_date',
                'Collection date is missing or not clearly structured. Clinician review is required before this information can be used.',
                'Confirm the collection date from the original PDF.',
                'missing_required_field',
                'warning'
            );
        }

        if (!aiCopilotSchemaValidationEnum($lab['abnormal_flag'] ?? null, ['normal', 'high', 'low', 'critical', 'abnormal', 'unknown'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.abnormal_flag',
                'abnormal_flag is unsupported.',
                'Use normal, high, low, critical, abnormal, or unknown.',
                'invalid_enum'
            );
        }

        if (!is_array($lab['source_citation'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.source_citation',
                'Lab result is missing source_citation.',
                'Attach a source citation for this lab fact before using it.',
                'missing_source_citation'
            );
        } else {
            $hardErrors = array_merge(
                $hardErrors,
                aiCopilotSchemaValidationValidateCitation($lab['source_citation'], $fieldBase . '.source_citation')
            );
        }

        if (!aiCopilotSchemaValidationEnum($lab['review_status'] ?? null, ['pending_clinician_review'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.review_status',
                'review_status must remain pending_clinician_review.',
                'Keep extracted lab facts in pending clinician review status.',
                'invalid_review_status'
            );
        }

        if (array_key_exists('proposed_fhir_resource_type', $lab) && trim((string) $lab['proposed_fhir_resource_type']) !== '' && !aiCopilotSchemaValidationEnum($lab['proposed_fhir_resource_type'], ['Observation', 'DiagnosticReport', 'Specimen', 'ServiceRequest'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.proposed_fhir_resource_type',
                'proposed_fhir_resource_type is unsupported.',
                'Use Observation, DiagnosticReport, Specimen, or ServiceRequest.',
                'invalid_enum'
            );
        }
    }

    if (($normalized['labs'] ?? []) !== [] && count($normalized['source_citations'] ?? []) === 0) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'source_citations',
            'Missing source citation.',
            'Cite the uploaded lab PDF before using extracted facts.',
            'missing_source_citation'
        );
    }

    return aiCopilotSchemaValidationFinalize($schema, $normalized, $hardErrors, $reviewIssues);
}
