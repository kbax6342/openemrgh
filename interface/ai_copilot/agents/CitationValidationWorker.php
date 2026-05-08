<?php

require_once(dirname(__DIR__) . '/citations/CitationContract.php');
require_once(dirname(__DIR__) . '/citations/CitationValidator.php');
require_once(dirname(__DIR__) . '/citations/ClaimCitationMapper.php');

class CitationValidationWorker
{
    public function validateExtraction(
        string $docType,
        array $strictExtraction,
        array $sourceDocument,
        string $role,
        AgentTrace $trace
    ): array {
        $patientId = isset($sourceDocument['patient_id']) && is_numeric($sourceDocument['patient_id']) ? (int) $sourceDocument['patient_id'] : 0;
        $citations = array_values(array_filter($strictExtraction['source_citations'] ?? [], 'is_array'));
        $validation = aiCopilotValidateCitationCollection($citations, [
            'patient_id' => $patientId,
            'role' => $role,
        ]);

        $status = $validation['valid'] && $validation['blocked_count'] === 0
            ? ($validation['review_required_count'] > 0 ? 'review_required' : 'passed')
            : 'blocked';

        $trace->add(
            'CitationValidationWorker',
            $status === 'blocked' ? 'blocked' : 'complete',
            $status === 'blocked'
                ? 'Blocked trusted clinical claims because one or more extracted facts were not fully linked to source evidence.'
                : 'Validated source citations for extracted clinical facts before persistence and review.',
            [
                'request_id' => $sourceDocument['metadata']['request_id'] ?? '',
                'source_document_id' => $sourceDocument['source_document_id'] ?? null,
                'doc_type' => $docType,
                'citation_count' => count($citations),
                'status' => 'citation_contract_validated',
                'error_code' => $status === 'blocked' ? 'invalid_citation_contract' : '',
            ]
        );

        return [
            'schema_name' => 'citation_contract_v1',
            'valid' => $validation['valid'],
            'blocked' => $validation['blocked_count'] > 0,
            'review_required' => $validation['review_required_count'] > 0 || !$validation['valid'],
            'citation_contract_status' => $status,
            'invalid_citation_count' => $validation['invalid_count'],
            'blocked_claim_count' => $validation['blocked_count'],
            'citation_count' => count($citations),
            'validation_errors' => array_values(array_filter(array_map(static function (array $result): ?array {
                if (($result['errors'] ?? []) === []) {
                    return null;
                }
                return [
                    'field' => 'citation',
                    'issue' => implode(' ', array_values(array_filter(array_map('strval', $result['errors'] ?? []), static fn($item) => trim($item) !== ''))),
                    'severity' => $result['blocked'] ? 'error' : 'warning',
                ];
            }, $validation['results']), static fn($item) => is_array($item))),
            'trusted_persistence_allowed' => $validation['valid'] && $validation['blocked_count'] === 0,
            'trusted_rag_index_allowed' => $validation['valid'] && $validation['blocked_count'] === 0,
            'user_message' => $validation['valid'] && $validation['blocked_count'] === 0
                ? 'Every extracted clinical fact was linked to source evidence and remains pending clinician review.'
                : 'This response includes clinical information that could not be fully linked to source evidence. Clinician review is required before use.',
            'audit_event' => [
                'claim_count' => count($citations),
                'cited_claim_count' => count($citations) - $validation['invalid_count'],
                'uncited_claim_count' => $validation['invalid_count'],
                'invalid_citation_count' => $validation['invalid_count'],
                'blocked_claim_count' => $validation['blocked_count'],
                'source_count' => count($citations),
                'citation_contract_status' => $status,
            ],
        ];
    }

    public function validateClaims(array $claims, array $options, AgentTrace $trace): array
    {
        $validatedClaims = [];
        $blockedClaims = [];
        $invalidCitationCount = 0;
        $citedClaimCount = 0;

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $citations = array_values(array_filter($claim['citations'] ?? [], 'is_array'));
            $claimErrors = [];
            $claimBlocked = false;
            if ($citations === []) {
                $claimErrors[] = 'Clinical claim is missing source evidence.';
                $claimBlocked = true;
            }

            $validatedCitations = [];
            foreach ($citations as $citation) {
                $result = aiCopilotCitationValidate($citation, $options);
                if (!$result['valid']) {
                    $invalidCitationCount++;
                    $claimErrors = array_merge($claimErrors, $result['errors']);
                }
                if ($result['blocked']) {
                    $claimBlocked = true;
                }
                if (!empty($result['citation'])) {
                    $validatedCitations[] = $result['citation'];
                }
            }

            if ($claimBlocked) {
                $blockedClaims[] = [
                    'claim_id' => trim((string) ($claim['claim_id'] ?? '')),
                    'text' => trim((string) ($claim['text'] ?? '')),
                    'issues' => array_values(array_unique(array_filter(array_map('strval', $claimErrors), static fn($item) => trim($item) !== ''))),
                ];
                continue;
            }

            if ($validatedCitations !== []) {
                $citedClaimCount++;
            }
            $claim['citations'] = $validatedCitations;
            $validatedClaims[] = $claim;
        }

        $status = $blockedClaims === []
            ? ($invalidCitationCount > 0 ? 'review_required' : 'passed')
            : 'blocked';

        $trace->add(
            'CitationValidationWorker',
            $status === 'blocked' ? 'blocked' : 'complete',
            $status === 'blocked'
                ? 'Removed one or more uncited or out-of-scope clinical claims from the final response.'
                : 'Validated machine-readable citations for final clinical claims before display.',
            [
                'request_id' => $options['request_id'] ?? '',
                'source_document_id' => $options['source_document_id'] ?? null,
                'doc_type' => $options['doc_type'] ?? '',
                'citation_count' => $citedClaimCount,
                'status' => 'citation_contract_validated',
                'error_code' => $status === 'blocked' ? 'uncited_claim_blocked' : '',
            ]
        );

        return [
            'claims' => $validatedClaims,
            'uncited_claims_blocked' => $blockedClaims,
            'claim_count' => count($claims),
            'cited_claim_count' => $citedClaimCount,
            'uncited_claim_count' => count($blockedClaims),
            'invalid_citation_count' => $invalidCitationCount,
            'blocked_claim_count' => count($blockedClaims),
            'citation_contract_status' => $status,
            'user_message' => $blockedClaims === []
                ? ''
                : 'This response includes clinical information that could not be fully linked to source evidence. Clinician review is required before use.',
        ];
    }
}
