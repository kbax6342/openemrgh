<?php

require_once(dirname(__DIR__) . '/validation/validate_extraction.php');

class IntakeExtractionWorker
{
    private function sourceCitation(string $sourceDocumentId, string $patientId, string $fieldKey, string $quoteOrValue, float $confidence, string $resourceType = 'QuestionnaireResponse', mixed $boundingBox = null): array
    {
        $pageOrSection = match (true) {
            str_contains($fieldKey, 'chief') => 'chief concern',
            str_contains($fieldKey, 'medication') => 'current medications',
            str_contains($fieldKey, 'allergy') => 'allergies',
            str_contains($fieldKey, 'family_history') || str_contains($fieldKey, 'family') => 'family history',
            str_contains($fieldKey, 'emergency_contact') => 'emergency contact',
            default => str_replace('_', ' ', $fieldKey),
        };
        $citation = [
            'source_type' => 'intake_form',
            'source_id' => $sourceDocumentId !== '' ? 'source_document_' . $sourceDocumentId : $fieldKey,
            'source_document_id' => $sourceDocumentId,
            'patient_id' => $patientId,
            'page_or_section' => $pageOrSection,
            'field_or_chunk_id' => $fieldKey,
            'quote_or_value' => $quoteOrValue,
            'confidence' => round($confidence, 4),
            'document_type' => 'intake_form',
            'resource_type' => $resourceType,
            'review_status' => 'pending_clinician_review',
        ];
        if (is_array($boundingBox ?? null)) {
            $citation['bounding_box'] = $boundingBox;
        }
        return $citation;
    }

    private function fieldValue(string $value, string $sourceDocumentId, string $patientId, string $fieldKey, string $quoteOrValue, float $confidence): array
    {
        return [
            'value' => $value,
            'source_citation' => $this->sourceCitation($sourceDocumentId, $patientId, $fieldKey, $quoteOrValue, $confidence, 'Patient'),
            'confidence' => round($confidence, 4),
        ];
    }

    private function placeholderFieldValue(string $sourceDocumentId, string $patientId, string $fieldKey): array
    {
        return $this->fieldValue('', $sourceDocumentId, $patientId, $fieldKey, 'Not clearly found in uploaded intake form.', 0.0);
    }

    private function deriveMedicationItems(string $note, string $sourceDocumentId, string $patientId): array
    {
        $note = trim($note);
        if ($note === '') {
            return [];
        }

        $medicationName = '';
        if (preg_match('/\b(Metformin|Insulin|Lisinopril|Atorvastatin|Amlodipine|Losartan)\b/i', $note, $matches) === 1) {
            $medicationName = trim((string) ($matches[1] ?? ''));
        }

        if ($medicationName === '') {
            return [];
        }

        $dose = '';
        if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:mg|mcg|g|units|mL))\b/i', $note, $matches) === 1) {
            $dose = trim((string) ($matches[1] ?? ''));
        }

        $frequency = '';
        if (preg_match('/\b(morning|evening|nightly|daily|twice daily|three times daily|weekly|as needed|bid|tid|qid)\b/i', $note, $matches) === 1) {
            $frequency = strtolower(trim((string) ($matches[1] ?? '')));
        }

        $route = '';
        if (preg_match('/\b(by mouth|oral|po|subcutaneous|intravenous|iv|topical|inhaled)\b/i', $note, $matches) === 1) {
            $route = strtolower(trim((string) ($matches[1] ?? '')));
        }

        return [[
            'medication_name' => $medicationName,
            'dose' => $dose,
            'frequency' => $frequency,
            'route' => $route,
            'source_citation' => $this->sourceCitation($sourceDocumentId, $patientId, 'intake_medication_001', $note, 0.78, 'MedicationRequest'),
            'confidence' => 0.78,
            'review_status' => 'pending_clinician_review',
        ]];
    }

    private function deriveAllergyItems(string $value, string $sourceDocumentId, string $patientId): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $allergen = $value;
        $reaction = '';
        $severity = 'unknown';
        if (preg_match('/\b(no known drug allergies|nkda)\b/i', $value) === 1) {
            $allergen = 'No known drug allergies reported';
        }

        if (preg_match('/\breaction[:\s-]+(.+)$/i', $value, $matches) === 1) {
            $reaction = trim((string) ($matches[1] ?? ''));
        }

        if (preg_match('/\b(severe|moderate|mild|unknown)\b/i', $value, $matches) === 1) {
            $severity = strtolower(trim((string) ($matches[1] ?? 'unknown')));
        }

        return [[
            'allergen' => $allergen,
            'reaction' => $reaction,
            'severity' => $severity,
            'source_citation' => $this->sourceCitation($sourceDocumentId, $patientId, 'intake_allergy_001', $value, 0.84, 'AllergyIntolerance'),
            'confidence' => 0.84,
            'review_status' => 'pending_clinician_review',
        ]];
    }

    private function deriveFamilyHistoryItems(string $text, string $sourceDocumentId, string $patientId): array
    {
        $items = [];
        if (preg_match('/family history\s*:\s*([^\n]+)/i', $text, $matches) !== 1) {
            return [];
        }

        $raw = trim((string) ($matches[1] ?? ''));
        if ($raw === '') {
            return [];
        }

        $relation = '';
        $condition = '';
        if (preg_match('/^\s*([^,;:-]+)[,;:-]\s*(.+)$/', $raw, $parts) === 1) {
            $relation = trim((string) ($parts[1] ?? ''));
            $condition = trim((string) ($parts[2] ?? ''));
        }

        if ($relation === '' || $condition === '') {
            return [];
        }

        $ageOfOnset = '';
        if (preg_match('/age of onset[:\s-]+([^\n]+)/i', $raw, $ageMatch) === 1) {
            $ageOfOnset = trim((string) ($ageMatch[1] ?? ''));
        }

        $items[] = [
            'relation' => $relation,
            'condition' => $condition,
            'age_of_onset' => $ageOfOnset,
            'source_citation' => $this->sourceCitation($sourceDocumentId, $patientId, 'intake_family_history_001', $raw, 0.75, 'Condition'),
            'confidence' => 0.75,
            'review_status' => 'pending_clinician_review',
        ];

        return $items;
    }

    public function buildStrictExtraction(array $toolOutput, array $sourceDocument): array
    {
        $patientId = trim((string) ($sourceDocument['patient_id'] ?? ''));
        $sourceDocumentId = trim((string) ($sourceDocument['source_document_id'] ?? ''));
        $fields = is_array($toolOutput['intake_fields'] ?? null) ? $toolOutput['intake_fields'] : [];
        $textPreview = trim((string) ($toolOutput['extracted_text_preview'] ?? ''));
        $confidence = 0.91;
        $missing = array_values(array_filter(
            array_map(static fn($item) => is_string($item) ? trim($item) : '', $toolOutput['missing_data'] ?? []),
            static fn($item) => $item !== ''
        ));

        $demographics = [
            'first_name' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'first_name'),
            'last_name' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'last_name'),
            'date_of_birth' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'date_of_birth'),
            'phone' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'phone'),
            'email' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'email'),
            'address' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'address'),
            'emergency_contact' => $this->placeholderFieldValue($sourceDocumentId, $patientId, 'emergency_contact'),
        ];

        foreach ([
            'first_name' => ['firstName', 'first_name'],
            'last_name' => ['lastName', 'last_name'],
            'date_of_birth' => ['dateOfBirth', 'date_of_birth'],
            'phone' => ['phone'],
            'email' => ['email'],
            'address' => ['address'],
            'emergency_contact' => ['emergencyContact', 'emergency_contact'],
        ] as $canonicalField => $aliases) {
            foreach ($aliases as $alias) {
                $value = trim((string) ($fields[$alias] ?? ''));
                if ($value !== '') {
                    $demographics[$canonicalField] = $this->fieldValue($value, $sourceDocumentId, $patientId, $canonicalField, $value, 0.88);
                    break;
                }
            }
            if (trim((string) ($demographics[$canonicalField]['value'] ?? '')) === '') {
                $missing[] = ucfirst(str_replace('_', ' ', $canonicalField)) . ' was not clearly detected in the uploaded intake form.';
            }
        }

        $chiefConcernValue = trim((string) ($fields['reasonForVisit'] ?? $fields['currentConcerns'] ?? ''));
        $chiefConcern = [
            'value' => $chiefConcernValue,
            'duration' => '',
            'severity' => '',
            'source_citation' => $this->sourceCitation(
                $sourceDocumentId,
                $patientId,
                'intake_chief_concern_001',
                $chiefConcernValue !== '' ? $chiefConcernValue : 'Not clearly found in uploaded intake form.',
                $chiefConcernValue !== '' ? $confidence : 0.0,
                'QuestionnaireResponse'
            ),
            'confidence' => $chiefConcernValue !== '' ? $confidence : 0.0,
        ];
        if ($chiefConcernValue === '') {
            $missing[] = 'Chief concern was not clearly detected in the uploaded intake form.';
        }

        $currentMedications = $this->deriveMedicationItems(trim((string) ($fields['medicationAdherence'] ?? '')), $sourceDocumentId, $patientId);
        if ($currentMedications === []) {
            $missing[] = 'Current medications were not clearly detected in the uploaded intake form.';
        }

        $allergies = $this->deriveAllergyItems(trim((string) ($fields['allergies'] ?? '')), $sourceDocumentId, $patientId);
        if ($allergies === []) {
            $missing[] = 'Allergies were not clearly detected in the uploaded intake form.';
        }

        $familyHistory = $this->deriveFamilyHistoryItems($textPreview, $sourceDocumentId, $patientId);
        if ($familyHistory === []) {
            $missing[] = 'Family history was not clearly detected in the uploaded intake form.';
        }

        $sourceCitations = [];
        foreach ($demographics as $field) {
            if (is_array($field['source_citation'] ?? null)) {
                $sourceCitations[] = $field['source_citation'];
            }
        }
        $sourceCitations[] = $chiefConcern['source_citation'];
        foreach ([$currentMedications, $allergies, $familyHistory] as $group) {
            foreach ($group as $item) {
                if (is_array($item['source_citation'] ?? null)) {
                    $sourceCitations[] = $item['source_citation'];
                }
            }
        }

        $hasContent = $chiefConcernValue !== '' || $currentMedications !== [] || $allergies !== [] || $familyHistory !== [];
        $extractionStatus = ($toolOutput['status'] ?? '') === 'ok' && $hasContent
            ? 'ok'
            : (($toolOutput['status'] ?? '') === 'failed' ? 'failed' : 'review_required');

        return [
            'document_type' => 'intake_form',
            'patient_id' => $patientId,
            'source_document_id' => $sourceDocumentId,
            'extraction_status' => $extractionStatus,
            'confidence' => $hasContent ? 0.91 : 0.45,
            'extracted_at' => gmdate('c'),
            'demographics' => $demographics,
            'chief_concern' => $chiefConcern,
            'current_medications' => $currentMedications,
            'allergies' => $allergies,
            'family_history' => $familyHistory,
            'missing_or_ambiguous_data' => array_values(array_unique($missing)),
            'source_citations' => array_values($sourceCitations),
            'safety_warnings' => array_values(array_filter([
                'Draft-only extraction. Pending clinician review.',
                !empty($toolOutput['safety_metadata']['prompt_injection_detected']) ? 'Instruction-like text was detected and ignored as untrusted source content.' : '',
                'Approved for demo review — not written to chart automatically.',
            ], static fn($item) => $item !== '')),
            'review_status' => 'pending_clinician_review',
        ];
    }

    public function validate(array $extraction): array
    {
        return aiCopilotValidateStrictExtraction('intake_form', $extraction);
    }
}
