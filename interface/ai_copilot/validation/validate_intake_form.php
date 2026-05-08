<?php

function aiCopilotValidateIntakeFormExtractionPayload(array $payload, array $context = []): array
{
    $schema = aiCopilotSchemaValidationLoadSchema('intake_form');
    $normalized = $payload;
    $normalized['review_status'] = 'pending_clinician_review';
    $hardErrors = [];
    $reviewIssues = [];

    $requiredKeys = [
        'document_type',
        'patient_id',
        'source_document_id',
        'extraction_status',
        'confidence',
        'extracted_at',
        'demographics',
        'chief_concern',
        'current_medications',
        'allergies',
        'family_history',
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

    if (($normalized['document_type'] ?? null) !== 'intake_form') {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'document_type',
            'document_type must equal intake_form.',
            'Route this upload through the Intake Form workflow only.',
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

    if (!is_array($normalized['demographics'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'demographics',
            'demographics must be an object.',
            'Return demographics as a structured object.',
            'invalid_type'
        );
        $normalized['demographics'] = [];
    }

    if (!is_array($normalized['chief_concern'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'chief_concern',
            'chief_concern must be an object.',
            'Return chief_concern as a structured object.',
            'invalid_type'
        );
        $normalized['chief_concern'] = [];
    }

    foreach (['current_medications', 'allergies', 'family_history', 'missing_or_ambiguous_data', 'source_citations', 'safety_warnings'] as $arrayField) {
        if (!is_array($normalized[$arrayField] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $arrayField,
                $arrayField . ' must be an array.',
                'Return ' . $arrayField . ' as a structured array.',
                'invalid_type'
            );
            $normalized[$arrayField] = [];
        }
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

    $demographicFields = ['first_name', 'last_name', 'date_of_birth', 'phone', 'email', 'address', 'emergency_contact'];
    foreach ($demographicFields as $fieldName) {
        $fieldBase = 'demographics.' . $fieldName;
        $field = $normalized['demographics'][$fieldName] ?? null;
        if (!is_array($field)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase,
                'Missing required field.',
                'Capture this demographic field from the intake form or leave it for clinician review.',
                'missing_required_field'
            );
            continue;
        }

        foreach (['value', 'source_citation', 'confidence'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $field)) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $requiredKey,
                    'Missing required field.',
                    'Add the extracted demographic value and citation before using it.',
                    'missing_required_field'
                );
            }
        }

        if (!is_string($field['value'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.value',
                'Demographic value must be a string.',
                'Return a string value for this demographic field.',
                'invalid_type'
            );
        } elseif (trim((string) $field['value']) === '') {
            $reviewIssues[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.value',
                'Missing required field: ' . $fieldName . '.',
                'Verify this demographic field from the uploaded intake form before using it.',
                'missing_required_field',
                'warning'
            );
        }

        if (!is_array($field['source_citation'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.source_citation',
                'Missing source_citation.',
                'Attach a source citation for this demographic field.',
                'missing_source_citation'
            );
        } else {
            $hardErrors = array_merge(
                $hardErrors,
                aiCopilotSchemaValidationValidateCitation($field['source_citation'], $fieldBase . '.source_citation')
            );
        }

        if (!aiCopilotSchemaValidationNumericUnitInterval($field['confidence'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.confidence',
                'confidence must be between 0 and 1.',
                'Re-run extraction or review this field manually.',
                'invalid_confidence'
            );
        }
    }

    foreach (['value', 'source_citation', 'confidence'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $normalized['chief_concern'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                'chief_concern.' . $requiredKey,
                'Missing required field.',
                'Return chief concern with a value, source citation, and confidence.',
                'missing_required_field'
            );
        }
    }

    if (!is_string($normalized['chief_concern']['value'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'chief_concern.value',
            'chief_concern.value must be a string.',
            'Return a string value for the chief concern.',
            'invalid_type'
        );
    } elseif (trim((string) $normalized['chief_concern']['value']) === '') {
        $reviewIssues[] = aiCopilotSchemaValidationIssue(
            'chief_concern.value',
            'Missing required field: chief_concern.',
            'Verify the chief concern from the intake form before using it.',
            'missing_required_field',
            'warning'
        );
    }

    if (!is_array($normalized['chief_concern']['source_citation'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'chief_concern.source_citation',
            'Missing source_citation.',
            'Attach a source citation for the chief concern.',
            'missing_source_citation'
        );
    } else {
        $hardErrors = array_merge(
            $hardErrors,
            aiCopilotSchemaValidationValidateCitation($normalized['chief_concern']['source_citation'], 'chief_concern.source_citation')
        );
    }

    if (!aiCopilotSchemaValidationNumericUnitInterval($normalized['chief_concern']['confidence'] ?? null)) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'chief_concern.confidence',
            'confidence must be between 0 and 1.',
            'Re-run extraction or review this field manually.',
            'invalid_confidence'
        );
    }

    foreach (($normalized['current_medications'] ?? []) as $index => $medication) {
        $fieldBase = 'current_medications[' . $index . ']';
        if (!is_array($medication)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase,
                'Each medication must be an object.',
                'Return structured medication entries.',
                'invalid_type'
            );
            continue;
        }

        foreach (['medication_name', 'dose', 'frequency', 'source_citation', 'confidence', 'review_status'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $medication)) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $requiredKey,
                    'Missing required field.',
                    'Complete the medication entry before using it.',
                    'missing_required_field'
                );
            }
        }

        if (!aiCopilotSchemaValidationNonEmptyString($medication['medication_name'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.medication_name',
                'Medication entry is missing medication_name.',
                'Verify the medication name from the intake form.',
                'missing_required_field'
            );
        }

        foreach (['dose', 'frequency'] as $fieldName) {
            if (!array_key_exists($fieldName, $medication)) {
                continue;
            }
            if (!is_string($medication[$fieldName])) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $fieldName,
                    $fieldName . ' must be a string.',
                    'Return the medication ' . $fieldName . ' as a string.',
                    'invalid_type'
                );
            } elseif (trim((string) $medication[$fieldName]) === '') {
                $reviewIssues[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $fieldName,
                    'Medication entry is missing ' . $fieldName . '.',
                    'Confirm the medication ' . $fieldName . ' before using it.',
                    'missing_required_field',
                    'warning'
                );
            }
        }

        if (!is_array($medication['source_citation'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.source_citation',
                'Missing source_citation.',
                'Attach a source citation for this medication.',
                'missing_source_citation'
            );
        } else {
            $hardErrors = array_merge(
                $hardErrors,
                aiCopilotSchemaValidationValidateCitation($medication['source_citation'], $fieldBase . '.source_citation')
            );
        }

        if (!aiCopilotSchemaValidationNumericUnitInterval($medication['confidence'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.confidence',
                'confidence must be between 0 and 1.',
                'Re-run extraction or review this medication manually.',
                'invalid_confidence'
            );
        }

        if (!aiCopilotSchemaValidationEnum($medication['review_status'] ?? null, ['pending_clinician_review'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.review_status',
                'review_status must remain pending_clinician_review.',
                'Keep extracted medications in pending clinician review status.',
                'invalid_review_status'
            );
        }
    }

    foreach (($normalized['allergies'] ?? []) as $index => $allergy) {
        $fieldBase = 'allergies[' . $index . ']';
        if (!is_array($allergy)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase,
                'Each allergy must be an object.',
                'Return structured allergy entries.',
                'invalid_type'
            );
            continue;
        }

        foreach (['allergen', 'reaction', 'severity', 'source_citation', 'confidence', 'review_status'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $allergy)) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $requiredKey,
                    'Missing required field.',
                    'Complete the allergy entry before using it.',
                    'missing_required_field'
                );
            }
        }

        if (!aiCopilotSchemaValidationNonEmptyString($allergy['allergen'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.allergen',
                'Allergy entry is missing allergen.',
                'Verify the allergen from the intake form.',
                'missing_required_field'
            );
        }

        foreach (['reaction', 'severity'] as $fieldName) {
            if (!array_key_exists($fieldName, $allergy)) {
                continue;
            }
            if (!is_string($allergy[$fieldName])) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $fieldName,
                    $fieldName . ' must be a string.',
                    'Return the allergy ' . $fieldName . ' as a string.',
                    'invalid_type'
                );
            } elseif (trim((string) $allergy[$fieldName]) === '') {
                $reviewIssues[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $fieldName,
                    'Allergy entry is missing ' . $fieldName . '.',
                    'Confirm the allergy ' . $fieldName . ' before using it.',
                    'missing_required_field',
                    'warning'
                );
            }
        }

        if (!is_array($allergy['source_citation'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.source_citation',
                'Missing source_citation.',
                'Attach a source citation for this allergy.',
                'missing_source_citation'
            );
        } else {
            $hardErrors = array_merge(
                $hardErrors,
                aiCopilotSchemaValidationValidateCitation($allergy['source_citation'], $fieldBase . '.source_citation')
            );
        }

        if (!aiCopilotSchemaValidationNumericUnitInterval($allergy['confidence'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.confidence',
                'confidence must be between 0 and 1.',
                'Re-run extraction or review this allergy manually.',
                'invalid_confidence'
            );
        }

        if (!aiCopilotSchemaValidationEnum($allergy['review_status'] ?? null, ['pending_clinician_review'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.review_status',
                'review_status must remain pending_clinician_review.',
                'Keep extracted allergies in pending clinician review status.',
                'invalid_review_status'
            );
        }
    }

    foreach (($normalized['family_history'] ?? []) as $index => $history) {
        $fieldBase = 'family_history[' . $index . ']';
        if (!is_array($history)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase,
                'Each family history item must be an object.',
                'Return structured family-history entries.',
                'invalid_type'
            );
            continue;
        }

        foreach (['relation', 'condition', 'source_citation', 'confidence', 'review_status'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $history)) {
                $hardErrors[] = aiCopilotSchemaValidationIssue(
                    $fieldBase . '.' . $requiredKey,
                    'Missing required field.',
                    'Complete the family-history entry before using it.',
                    'missing_required_field'
                );
            }
        }

        if (!aiCopilotSchemaValidationNonEmptyString($history['relation'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.relation',
                'Family history entry is missing relation.',
                'Verify the family relation from the intake form.',
                'missing_required_field'
            );
        }

        if (!aiCopilotSchemaValidationNonEmptyString($history['condition'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.condition',
                'Family history entry is missing condition.',
                'Verify the family-history condition from the intake form.',
                'missing_required_field'
            );
        }

        if (!is_array($history['source_citation'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.source_citation',
                'Missing source_citation.',
                'Attach a source citation for this family-history entry.',
                'missing_source_citation'
            );
        } else {
            $hardErrors = array_merge(
                $hardErrors,
                aiCopilotSchemaValidationValidateCitation($history['source_citation'], $fieldBase . '.source_citation')
            );
        }

        if (!aiCopilotSchemaValidationNumericUnitInterval($history['confidence'] ?? null)) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.confidence',
                'confidence must be between 0 and 1.',
                'Re-run extraction or review this family-history item manually.',
                'invalid_confidence'
            );
        }

        if (!aiCopilotSchemaValidationEnum($history['review_status'] ?? null, ['pending_clinician_review'])) {
            $hardErrors[] = aiCopilotSchemaValidationIssue(
                $fieldBase . '.review_status',
                'review_status must remain pending_clinician_review.',
                'Keep extracted family history in pending clinician review status.',
                'invalid_review_status'
            );
        }
    }

    if (count($normalized['source_citations'] ?? []) === 0 && (
        count($normalized['current_medications'] ?? []) > 0 ||
        count($normalized['allergies'] ?? []) > 0 ||
        count($normalized['family_history'] ?? []) > 0
    )) {
        $hardErrors[] = aiCopilotSchemaValidationIssue(
            'source_citations',
            'Missing source citation.',
            'Cite the uploaded intake form before using extracted facts.',
            'missing_source_citation'
        );
    }

    return aiCopilotSchemaValidationFinalize($schema, $normalized, $hardErrors, $reviewIssues);
}
