<?php

require_once(dirname(__DIR__) . '/validation/validate_extraction.php');

function aiCopilotIntakeSchemaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function aiCopilotIntakeCitation(string $section, string $quote, float $confidence = 0.9): array
{
    return [
        'source_document_id' => '55',
        'page_or_section' => $section,
        'quote_or_value' => $quote,
        'confidence' => $confidence,
    ];
}

function aiCopilotValidIntakePayload(): array
{
    $demographics = [];
    foreach ([
        'first_name' => 'Marcus',
        'last_name' => 'Johnson',
        'date_of_birth' => '1980-04-19',
        'phone' => '555-0100',
        'email' => 'marcus.synthetic@example.org',
        'address' => '10 Demo Lane',
        'emergency_contact' => 'Lisa Johnson 555-0101',
    ] as $field => $value) {
        $demographics[$field] = [
            'value' => $value,
            'source_citation' => aiCopilotIntakeCitation('intake_form:' . $field, $value, 0.92),
            'confidence' => 0.92,
        ];
    }

    return [
        'document_type' => 'intake_form',
        'patient_id' => '1001',
        'source_document_id' => '55',
        'extraction_status' => 'ok',
        'confidence' => 0.91,
        'extracted_at' => '2026-05-07T12:00:00Z',
        'demographics' => $demographics,
        'chief_concern' => [
            'value' => 'blood sugar management and medication questions',
            'duration' => '2 weeks',
            'severity' => 'moderate',
            'source_citation' => aiCopilotIntakeCitation('intake_form:chief_concern', 'Reason for visit: blood sugar management and medication questions', 0.93),
            'confidence' => 0.93,
        ],
        'current_medications' => [[
            'medication_name' => 'Metformin',
            'dose' => '500 mg',
            'frequency' => 'evening',
            'route' => 'oral',
            'source_citation' => aiCopilotIntakeCitation('intake_form:current_medications', 'Medication adherence issue: sometimes misses evening Metformin', 0.84),
            'confidence' => 0.84,
            'review_status' => 'pending_clinician_review',
        ]],
        'allergies' => [[
            'allergen' => 'No known drug allergies reported',
            'reaction' => '',
            'severity' => 'unknown',
            'source_citation' => aiCopilotIntakeCitation('intake_form:allergies', 'Allergies: no known drug allergies reported', 0.82),
            'confidence' => 0.82,
            'review_status' => 'pending_clinician_review',
        ]],
        'family_history' => [[
            'relation' => 'Father',
            'condition' => 'Type 2 diabetes',
            'age_of_onset' => 'unknown',
            'source_citation' => aiCopilotIntakeCitation('intake_form:family_history', 'Family history: Father, Type 2 diabetes', 0.8),
            'confidence' => 0.8,
            'review_status' => 'pending_clinician_review',
        ]],
        'missing_or_ambiguous_data' => [],
        'source_citations' => [
            aiCopilotIntakeCitation('intake_form:chief_concern', 'Reason for visit: blood sugar management and medication questions', 0.93)
        ],
        'safety_warnings' => ['Draft-only extraction. Pending clinician review.'],
        'review_status' => 'pending_clinician_review',
    ];
}

function runIntakeFormSchemaTests(): void
{
    $valid = aiCopilotValidateStrictExtraction('intake_form', aiCopilotValidIntakePayload());
    aiCopilotIntakeSchemaAssert($valid['schema_valid'] === true, 'valid intake_form passes');

    $missingDemographics = aiCopilotValidIntakePayload();
    unset($missingDemographics['demographics']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingDemographics)['schema_valid'] === false, 'intake_form missing demographics fails');

    $missingChiefConcern = aiCopilotValidIntakePayload();
    unset($missingChiefConcern['chief_concern']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingChiefConcern)['schema_valid'] === false, 'intake_form missing chief_concern fails');

    $missingCurrentMeds = aiCopilotValidIntakePayload();
    unset($missingCurrentMeds['current_medications']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingCurrentMeds)['schema_valid'] === false, 'intake_form missing current_medications fails');

    $missingAllergies = aiCopilotValidIntakePayload();
    unset($missingAllergies['allergies']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingAllergies)['schema_valid'] === false, 'intake_form missing allergies fails');

    $missingFamilyHistory = aiCopilotValidIntakePayload();
    unset($missingFamilyHistory['family_history']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingFamilyHistory)['schema_valid'] === false, 'intake_form missing family_history fails');

    $missingCitation = aiCopilotValidIntakePayload();
    unset($missingCitation['allergies'][0]['source_citation']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingCitation)['schema_valid'] === false, 'intake_form missing source_citation fails');

    $missingMedicationName = aiCopilotValidIntakePayload();
    unset($missingMedicationName['current_medications'][0]['medication_name']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingMedicationName)['schema_valid'] === false, 'medication without medication_name fails');

    $missingAllergen = aiCopilotValidIntakePayload();
    unset($missingAllergen['allergies'][0]['allergen']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingAllergen)['schema_valid'] === false, 'allergy without allergen fails');

    $missingFamilyRelation = aiCopilotValidIntakePayload();
    unset($missingFamilyRelation['family_history'][0]['relation']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingFamilyRelation)['schema_valid'] === false, 'family history without relation fails');

    $missingFamilyCondition = aiCopilotValidIntakePayload();
    unset($missingFamilyCondition['family_history'][0]['condition']);
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $missingFamilyCondition)['schema_valid'] === false, 'family history without condition fails');

    $wrongDocumentType = aiCopilotValidIntakePayload();
    $wrongDocumentType['document_type'] = 'lab_pdf';
    aiCopilotIntakeSchemaAssert(aiCopilotValidateStrictExtraction('intake_form', $wrongDocumentType)['schema_valid'] === false, 'wrong document_type fails');
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    runIntakeFormSchemaTests();
    echo "intake_form schema tests passed\n";
}
