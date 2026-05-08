<?php

require_once(dirname(__DIR__) . '/validation/validate_extraction.php');

class SchemaValidationWorker
{
    public function validate(string $docType, array $extraction, array $context, AgentTrace $trace): array
    {
        $validation = aiCopilotValidateStrictExtraction($docType, $extraction, $context);
        $schemaValid = !empty($validation['schema_valid']);
        $payload = [
            'request_id' => $context['request_id'] ?? '',
            'patient_id' => $context['patient_id'] ?? null,
            'doc_type' => $docType,
            'schema_name' => $validation['schema_name'] ?? '',
            'schema_valid' => $schemaValid,
            'validation_error_count' => count($validation['validation_errors'] ?? []),
            'missing_required_field_count' => (int) ($validation['missing_required_field_count'] ?? 0),
            'citation_count' => (int) ($validation['citation_count'] ?? 0),
            'extraction_status' => $validation['extraction_status'] ?? 'review_required',
            'review_status' => $validation['review_status'] ?? 'pending_clinician_review',
            'status' => 'extraction_schema_validated',
        ];

        $trace->add(
            'SchemaValidationWorker',
            $schemaValid ? 'complete' : 'blocked',
            $schemaValid
                ? (($validation['extraction_status'] ?? 'ok') === 'review_required'
                    ? 'Strict schema validation passed structurally, but clinician review is still required for incomplete or ambiguous fields.'
                    : 'Strict schema validation passed for the extracted document payload.')
                : 'Strict schema validation failed, so the extraction was blocked from trusted persistence and trusted RAG indexing.',
            $payload + [
                'error_code' => $schemaValid ? '' : 'schema_validation_failed',
            ]
        );

        $validation['audit_payload'] = $payload;
        return $validation;
    }
}
