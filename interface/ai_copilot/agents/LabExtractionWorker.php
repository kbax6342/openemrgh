<?php

require_once(dirname(__DIR__) . '/validation/validate_extraction.php');

class LabExtractionWorker
{
    private function normalizeFlag(mixed $value): string
    {
        $flag = strtolower(trim((string) $value));
        return match ($flag) {
            'normal', 'high', 'low', 'critical', 'abnormal', 'unknown' => $flag,
            'h' => 'high',
            'l' => 'low',
            default => $flag !== '' ? $flag : 'unknown',
        };
    }

    private function buildSourceCitation(string $sourceDocumentId, string $patientId, string $fieldOrChunkId, ?int $pageNumber, string $quoteOrValue, float $confidence, mixed $boundingBox = null): array
    {
        $citation = [
            'source_type' => 'lab_pdf',
            'source_id' => $sourceDocumentId !== '' ? 'source_document_' . $sourceDocumentId : $fieldOrChunkId,
            'source_document_id' => $sourceDocumentId,
            'patient_id' => $patientId,
            'page_number' => $pageNumber,
            'page_or_section' => $pageNumber !== null ? 'page ' . $pageNumber . ' / lab results table' : 'lab results table',
            'field_or_chunk_id' => $fieldOrChunkId,
            'quote_or_value' => $quoteOrValue,
            'confidence' => round($confidence, 4),
            'document_type' => 'lab_pdf',
            'resource_type' => 'Observation',
            'review_status' => 'pending_clinician_review',
        ];

        if (is_array($boundingBox ?? null)) {
            $citation['bounding_box'] = $boundingBox;
        }

        return $citation;
    }

    public function buildStrictExtraction(array $toolOutput, array $sourceDocument): array
    {
        $patientId = trim((string) ($sourceDocument['patient_id'] ?? ''));
        $sourceDocumentId = trim((string) ($sourceDocument['source_document_id'] ?? ''));
        $collectionDate = trim((string) ($toolOutput['document_metadata']['collected_date'] ?? $toolOutput['document_metadata']['collection_date'] ?? ''));
        $resultedDate = trim((string) ($toolOutput['document_metadata']['resulted_date'] ?? ''));
        $pageNumber = null;
        $pageNumbers = [];
        foreach (($toolOutput['retrieval']['chunks'] ?? []) as $chunk) {
            if (is_array($chunk) && isset($chunk['source_page']) && is_numeric($chunk['source_page'])) {
                $pageNumbers[] = (int) $chunk['source_page'];
            }
        }
        if ($pageNumbers !== []) {
            $pageNumber = min($pageNumbers);
        }

        $missing = array_values(array_filter(
            array_map(static fn($item) => is_string($item) ? trim($item) : '', $toolOutput['missing_data'] ?? []),
            static fn($item) => $item !== ''
        ));

        $labs = [];
        $sourceCitations = [];
        foreach (($toolOutput['extracted_facts'] ?? []) as $index => $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $testName = trim((string) ($fact['test_name'] ?? $fact['name'] ?? $fact['label'] ?? ''));
            $value = $fact['value'] ?? '';
            $valueText = is_numeric($value) ? (string) $value : trim((string) $value);
            $unit = trim((string) ($fact['unit'] ?? ''));
            $referenceRange = trim((string) ($fact['reference_range'] ?? ''));
            $flag = $this->normalizeFlag($fact['abnormal_flag'] ?? $fact['flag'] ?? $fact['interpretation'] ?? 'unknown');
            $confidence = isset($fact['confidence']) && is_numeric($fact['confidence']) ? (float) $fact['confidence'] : 0.9;
            $quote = trim((string) ($fact['source_quote_or_value'] ?? $valueText));
            $proposedType = trim((string) ($fact['proposed_fhir_resource_type'] ?? 'Observation'));
            if (!in_array($proposedType, ['Observation', 'DiagnosticReport', 'Specimen', 'ServiceRequest'], true)) {
                $proposedType = 'Observation';
            }

            if ($testName === '' || $valueText === '') {
                continue;
            }

            $fieldOrChunkId = trim((string) ($fact['field_or_chunk_id'] ?? ''));
            if ($fieldOrChunkId === '') {
                $fieldOrChunkId = 'lab_' . $index;
            }
            $citation = $this->buildSourceCitation(
                $sourceDocumentId,
                $patientId,
                $fieldOrChunkId,
                $pageNumber,
                $quote !== '' ? $quote : $valueText,
                $confidence,
                $fact['bounding_box'] ?? null
            );
            $labs[] = [
                'test_name' => $testName,
                'value' => $valueText,
                'unit' => $unit,
                'reference_range' => $referenceRange,
                'collection_date' => $collectionDate,
                'abnormal_flag' => $flag,
                'source_citation' => $citation,
                'resulted_date' => $resultedDate,
                'loinc_code' => trim((string) ($fact['loinc_code'] ?? '')),
                'proposed_fhir_resource_type' => $proposedType,
                'review_status' => 'pending_clinician_review',
                'source_document_id' => $sourceDocumentId,
                'page_number' => $pageNumber,
                'source_quote_or_value' => $quote !== '' ? $quote : $valueText,
                'confidence' => round($confidence, 4),
                'field_or_chunk_id' => $fieldOrChunkId,
            ];
            $sourceCitations[] = $citation;
        }

        $extractionStatus = ($toolOutput['status'] ?? '') === 'ok' && $labs !== [] ? 'ok' : (($toolOutput['status'] ?? '') === 'failed' ? 'failed' : 'review_required');
        if ($labs === []) {
            $extractionStatus = in_array((string) ($toolOutput['status'] ?? ''), ['failed', 'invalid_file_type', 'document_guard_rejected'], true)
                ? 'failed'
                : 'review_required';
        }

        return [
            'document_type' => 'lab_pdf',
            'patient_id' => $patientId,
            'source_document_id' => $sourceDocumentId,
            'extraction_status' => $extractionStatus,
            'confidence' => $labs !== [] ? 0.92 : 0.45,
            'extracted_at' => gmdate('c'),
            'labs' => $labs,
            'missing_or_ambiguous_data' => $missing,
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
        return aiCopilotValidateStrictExtraction('lab_pdf', $extraction);
    }
}
