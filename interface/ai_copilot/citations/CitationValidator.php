<?php

require_once(__DIR__ . '/CitationContract.php');

function aiCopilotCitationValidate(array $citation, array $options = []): array
{
    $normalized = aiCopilotBuildCitation($citation, $options);
    $errors = [];
    $blocked = false;
    $requiresReview = false;

    if (trim((string) ($normalized['source_type'] ?? '')) === '' || (string) ($normalized['source_type'] ?? 'unknown') === 'unknown') {
        $errors[] = 'Citation source_type is missing or unsupported.';
    }
    if (trim((string) ($normalized['source_id'] ?? '')) === '') {
        $errors[] = 'Citation source_id is required.';
    }
    if (trim((string) ($normalized['page_or_section'] ?? '')) === '') {
        $errors[] = 'Citation page_or_section is required.';
    }
    if (trim((string) ($normalized['field_or_chunk_id'] ?? '')) === '') {
        $errors[] = 'Citation field_or_chunk_id is required.';
    }
    if (trim((string) ($normalized['quote_or_value'] ?? '')) === '') {
        $errors[] = 'Citation quote_or_value is required.';
    }
    if (!isset($normalized['confidence']) || !is_numeric($normalized['confidence'])) {
        $errors[] = 'Citation confidence is required.';
    }

    $expectedPatientId = isset($options['patient_id']) && is_numeric($options['patient_id']) ? (int) $options['patient_id'] : null;
    $citationPatientId = isset($normalized['patient_id']) && is_numeric($normalized['patient_id']) ? (int) $normalized['patient_id'] : null;
    if ($expectedPatientId !== null && $citationPatientId !== null && $citationPatientId !== $expectedPatientId) {
        $errors[] = 'Citation belongs to the wrong patient.';
        $blocked = true;
    }

    $reviewStatus = trim((string) ($normalized['review_status'] ?? 'pending_clinician_review'));
    if ($reviewStatus === 'clinician_rejected') {
        $errors[] = 'Citation points to a rejected fact.';
        $blocked = true;
    }

    $role = strtolower(trim((string) ($options['role'] ?? 'doctor')));
    $clinicalOnlySourceTypes = ['lab_pdf', 'intake_form', 'rag_chunk', 'openemr_chart', 'fhir_resource', 'clinician_reviewed_fact', 'ambient_encounter'];
    if (in_array($role, ['billing', 'front_desk'], true) && in_array((string) ($normalized['source_type'] ?? 'unknown'), $clinicalOnlySourceTypes, true)) {
        $errors[] = 'Citation is outside the current role scope.';
        $blocked = true;
    }

    $minimumConfidence = isset($options['minimum_confidence']) && is_numeric($options['minimum_confidence'])
        ? (float) $options['minimum_confidence']
        : 0.65;
    if ((float) ($normalized['confidence'] ?? 0.0) < $minimumConfidence) {
        $requiresReview = true;
    }

    if ($errors !== []) {
        $requiresReview = true;
    }

    return [
        'valid' => $errors === [],
        'blocked' => $blocked,
        'review_required' => $requiresReview,
        'errors' => $errors,
        'citation' => $normalized,
    ];
}

function aiCopilotValidateCitationCollection(array $citations, array $options = []): array
{
    $results = [];
    $invalidCount = 0;
    $blockedCount = 0;
    $reviewRequiredCount = 0;

    foreach ($citations as $citation) {
        if (!is_array($citation)) {
            $citation = [];
        }
        $result = aiCopilotCitationValidate($citation, $options);
        $results[] = $result;
        if (!$result['valid']) {
            $invalidCount++;
        }
        if ($result['blocked']) {
            $blockedCount++;
        }
        if ($result['review_required']) {
            $reviewRequiredCount++;
        }
    }

    return [
        'valid' => $invalidCount === 0,
        'invalid_count' => $invalidCount,
        'blocked_count' => $blockedCount,
        'review_required_count' => $reviewRequiredCount,
        'results' => $results,
    ];
}

