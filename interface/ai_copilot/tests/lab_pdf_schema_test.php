<?php

require_once(dirname(__DIR__) . '/validation/validate_extraction.php');

function aiCopilotLabSchemaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function aiCopilotValidLabPayload(): array
{
    $citation = [
        'source_document_id' => '42',
        'page_number' => 1,
        'page_or_section' => 'page_1',
        'quote_or_value' => 'Hemoglobin A1c 8.2 % High',
        'confidence' => 0.94,
    ];

    return [
        'document_type' => 'lab_pdf',
        'patient_id' => '1001',
        'source_document_id' => '42',
        'extraction_status' => 'ok',
        'confidence' => 0.94,
        'extracted_at' => '2026-05-07T12:00:00Z',
        'labs' => [[
            'test_name' => 'Hemoglobin A1c',
            'value' => '8.2 %',
            'unit' => '%',
            'reference_range' => '4.0 - 5.6 %',
            'collection_date' => '2026-05-05',
            'abnormal_flag' => 'high',
            'source_citation' => $citation,
            'resulted_date' => '2026-05-06',
            'proposed_fhir_resource_type' => 'Observation',
            'review_status' => 'pending_clinician_review',
            'source_document_id' => '42',
            'page_number' => 1,
            'source_quote_or_value' => 'Hemoglobin A1c 8.2 % High',
            'confidence' => 0.94,
        ]],
        'missing_or_ambiguous_data' => [],
        'source_citations' => [$citation],
        'safety_warnings' => ['Draft-only extraction. Pending clinician review.'],
        'review_status' => 'pending_clinician_review',
    ];
}

function runLabPdfSchemaTests(): void
{
    $valid = aiCopilotValidateStrictExtraction('lab_pdf', aiCopilotValidLabPayload());
    aiCopilotLabSchemaAssert($valid['schema_valid'] === true, 'valid lab_pdf passes');

    $missingTestName = aiCopilotValidLabPayload();
    unset($missingTestName['labs'][0]['test_name']);
    aiCopilotLabSchemaAssert(aiCopilotValidateStrictExtraction('lab_pdf', $missingTestName)['schema_valid'] === false, 'lab_pdf missing test_name fails');

    $missingValue = aiCopilotValidLabPayload();
    unset($missingValue['labs'][0]['value']);
    aiCopilotLabSchemaAssert(aiCopilotValidateStrictExtraction('lab_pdf', $missingValue)['schema_valid'] === false, 'lab_pdf missing value fails');

    $missingUnit = aiCopilotValidLabPayload();
    $missingUnit['labs'][0]['unit'] = '';
    $missingUnit['missing_or_ambiguous_data'][] = 'Hemoglobin A1c unit was not clearly detected in the uploaded lab PDF.';
    $missingUnitResult = aiCopilotValidateStrictExtraction('lab_pdf', $missingUnit);
    aiCopilotLabSchemaAssert($missingUnitResult['schema_valid'] === true && $missingUnitResult['extraction_status'] === 'review_required', 'lab_pdf missing unit is flagged');

    $missingReferenceRange = aiCopilotValidLabPayload();
    $missingReferenceRange['labs'][0]['reference_range'] = '';
    $missingReferenceRange['missing_or_ambiguous_data'][] = 'Hemoglobin A1c reference range was not clearly detected in the uploaded lab PDF.';
    $missingReferenceRangeResult = aiCopilotValidateStrictExtraction('lab_pdf', $missingReferenceRange);
    aiCopilotLabSchemaAssert($missingReferenceRangeResult['schema_valid'] === true && $missingReferenceRangeResult['extraction_status'] === 'review_required', 'lab_pdf missing reference_range is flagged');

    $missingCollectionDate = aiCopilotValidLabPayload();
    $missingCollectionDate['labs'][0]['collection_date'] = '';
    $missingCollectionResult = aiCopilotValidateStrictExtraction('lab_pdf', $missingCollectionDate);
    aiCopilotLabSchemaAssert($missingCollectionResult['schema_valid'] === true && $missingCollectionResult['extraction_status'] === 'review_required', 'lab_pdf missing collection_date becomes review_required');

    $missingFlag = aiCopilotValidLabPayload();
    unset($missingFlag['labs'][0]['abnormal_flag']);
    aiCopilotLabSchemaAssert(aiCopilotValidateStrictExtraction('lab_pdf', $missingFlag)['schema_valid'] === false, 'lab_pdf missing abnormal_flag fails');

    $missingCitation = aiCopilotValidLabPayload();
    unset($missingCitation['labs'][0]['source_citation']);
    aiCopilotLabSchemaAssert(aiCopilotValidateStrictExtraction('lab_pdf', $missingCitation)['schema_valid'] === false, 'lab_pdf missing source_citation fails');

    $unsupportedFlag = aiCopilotValidLabPayload();
    $unsupportedFlag['labs'][0]['abnormal_flag'] = 'elevated';
    aiCopilotLabSchemaAssert(aiCopilotValidateStrictExtraction('lab_pdf', $unsupportedFlag)['schema_valid'] === false, 'unsupported abnormal_flag fails');

    $wrongDocumentType = aiCopilotValidLabPayload();
    $wrongDocumentType['document_type'] = 'intake_form';
    aiCopilotLabSchemaAssert(aiCopilotValidateStrictExtraction('lab_pdf', $wrongDocumentType)['schema_valid'] === false, 'wrong document_type fails');
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    runLabPdfSchemaTests();
    echo "lab_pdf schema tests passed\n";
}
