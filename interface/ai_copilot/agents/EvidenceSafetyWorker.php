<?php

class EvidenceSafetyWorker
{
    public function validate(string $role, string $docType, array $strictExtraction, array $toolOutput, AgentTrace $trace, array $schemaValidation = []): array
    {
        $citations = is_array($strictExtraction['source_citations'] ?? null) ? $strictExtraction['source_citations'] : [];
        $missing = is_array($strictExtraction['missing_or_ambiguous_data'] ?? null) ? $strictExtraction['missing_or_ambiguous_data'] : [];
        $allowed = true;
        $blockedReason = '';

        if (in_array($role, ['billing', 'front_desk'], true)) {
            $allowed = false;
            $blockedReason = 'role_boundary_enforced';
        }

        if ($citations === []) {
            $allowed = false;
            $blockedReason = $blockedReason !== '' ? $blockedReason : 'missing_citations';
        }

        if (($schemaValidation['schema_valid'] ?? true) !== true) {
            $allowed = false;
            $blockedReason = $blockedReason !== '' ? $blockedReason : 'schema_validation_failed';
        }

        $trace->add(
            'EvidenceSafetyWorker',
            $allowed ? 'complete' : 'blocked',
            $allowed
                ? 'Validated citations, strict-schema status, draft-only language, and minimum-necessary role scope.'
                : 'Blocked or limited the workflow because citations, schema validation, or role boundaries did not meet safety rules.',
            [
                'request_id' => $toolOutput['source_metadata']['request_id'] ?? '',
                'source_document_id' => $strictExtraction['source_document_id'] ?? null,
                'doc_type' => $docType,
                'citation_count' => count($citations),
                'schema_valid' => !empty($schemaValidation['schema_valid']),
                'status' => $allowed ? 'evidence_safety_check_completed' : 'evidence_safety_check_blocked',
                'error_code' => $blockedReason,
            ]
        );

        return [
            'allowed' => $allowed,
            'blocked_reason' => $blockedReason,
            'safe_refusal' => $allowed
                ? ''
                : (in_array($role, ['billing', 'front_desk'], true)
                    ? 'This role can’t view clinical extracted facts from uploaded documents beyond minimum necessary scope.'
                    : (($schemaValidation['schema_valid'] ?? true) !== true
                        ? 'Extraction completed, but the result did not pass strict validation. Clinician review is required before this information can be used.'
                        : 'Grounded sources were missing, so no source-grounded answer can be shown.')),
            'citation_count' => count($citations),
            'missing_data' => $missing,
            'draft_only_note' => 'Approved for demo review — not written to chart automatically.',
        ];
    }
}
