<?php

require_once(dirname(__DIR__) . '/citations/CitationContract.php');
require_once(dirname(__DIR__) . '/citations/CitationValidator.php');
require_once(dirname(__DIR__) . '/citations/ClaimCitationMapper.php');
require_once(dirname(__DIR__) . '/agents/CitationValidationWorker.php');
require_once(dirname(__DIR__) . '/agents/AgentTrace.php');

function assertTrueForCitationContract(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, $label . PHP_EOL);
        exit(1);
    }
}

function runCitationContractAssertion(string $label, callable $callback): void
{
    $callback();
}

$validCitation = [
    'source_type' => 'lab_pdf',
    'source_id' => 'doc_123',
    'source_document_id' => 123,
    'patient_id' => 17,
    'page_or_section' => 'page 1 / lab results table',
    'field_or_chunk_id' => 'lab_result_a1c_001',
    'quote_or_value' => 'Hemoglobin A1c 8.4%',
    'confidence' => 0.94,
    'document_type' => 'lab_pdf',
    'review_status' => 'pending_clinician_review',
];

runCitationContractAssertion('valid clinical claim with citation passes', static function () use ($validCitation): void {
    $result = aiCopilotCitationValidate($validCitation, [
        'patient_id' => 17,
        'role' => 'doctor',
    ]);
    assertTrueForCitationContract($result['valid'] === true, 'valid citation should pass');
    assertTrueForCitationContract($result['blocked'] === false, 'valid citation should not be blocked');
});

runCitationContractAssertion('citation missing source_type fails', static function () use ($validCitation): void {
    $citation = $validCitation;
    unset($citation['source_type']);
    $result = aiCopilotCitationValidate($citation, ['patient_id' => 17, 'role' => 'doctor']);
    assertTrueForCitationContract($result['valid'] === false, 'citation missing source_type fails');
});

runCitationContractAssertion('citation missing source_id fails', static function () use ($validCitation): void {
    $citation = $validCitation;
    unset($citation['source_id']);
    $result = aiCopilotCitationValidate($citation, ['patient_id' => 17, 'role' => 'doctor']);
    assertTrueForCitationContract($result['valid'] === false, 'citation missing source_id fails');
});

runCitationContractAssertion('citation missing page_or_section fails', static function () use ($validCitation): void {
    $citation = $validCitation;
    unset($citation['page_or_section']);
    $result = aiCopilotCitationValidate($citation, ['patient_id' => 17, 'role' => 'doctor']);
    assertTrueForCitationContract($result['valid'] === false, 'citation missing page_or_section fails');
});

runCitationContractAssertion('citation missing field_or_chunk_id fails', static function () use ($validCitation): void {
    $citation = $validCitation;
    unset($citation['field_or_chunk_id']);
    $result = aiCopilotCitationValidate($citation, ['patient_id' => 17, 'role' => 'doctor']);
    assertTrueForCitationContract($result['valid'] === false, 'citation missing field_or_chunk_id fails');
});

runCitationContractAssertion('citation missing quote_or_value fails', static function () use ($validCitation): void {
    $citation = $validCitation;
    unset($citation['quote_or_value']);
    $result = aiCopilotCitationValidate($citation, ['patient_id' => 17, 'role' => 'doctor']);
    assertTrueForCitationContract($result['valid'] === false, 'citation missing quote_or_value fails');
});

runCitationContractAssertion('citation pointing to rejected fact is blocked', static function () use ($validCitation): void {
    $citation = $validCitation;
    $citation['review_status'] = 'clinician_rejected';
    $result = aiCopilotCitationValidate($citation, ['patient_id' => 17, 'role' => 'doctor']);
    assertTrueForCitationContract($result['blocked'] === true, 'citation pointing to rejected fact is blocked');
});

runCitationContractAssertion('citation from wrong patient is blocked', static function () use ($validCitation): void {
    $result = aiCopilotCitationValidate($validCitation, ['patient_id' => 999, 'role' => 'doctor']);
    assertTrueForCitationContract($result['blocked'] === true, 'citation from wrong patient is blocked');
});

runCitationContractAssertion('citation outside role scope is blocked', static function () use ($validCitation): void {
    $result = aiCopilotCitationValidate($validCitation, ['patient_id' => 17, 'role' => 'billing']);
    assertTrueForCitationContract($result['blocked'] === true, 'citation outside role scope is blocked');
});

runCitationContractAssertion('clinical claim without citation is blocked or flagged', static function () use ($validCitation): void {
    $worker = new CitationValidationWorker();
    $trace = new AgentTrace('request_test');
    $validation = $worker->validateClaims([
        [
            'claim_id' => 'claim_1',
            'text' => 'Marcus has an elevated A1c of 8.4%',
            'claim_type' => 'lab_result',
            'citations' => [],
            'review_status' => 'pending_clinician_review',
        ],
        [
            'claim_id' => 'claim_2',
            'text' => 'Hemoglobin A1c 8.4%',
            'claim_type' => 'lab_result',
            'citations' => [$validCitation],
            'review_status' => 'pending_clinician_review',
        ],
    ], [
        'patient_id' => 17,
        'role' => 'doctor',
        'request_id' => 'request_test',
        'doc_type' => 'lab_pdf',
    ], $trace);

    assertTrueForCitationContract(count($validation['claims']) === 1, 'one cited claim should remain');
    assertTrueForCitationContract(count($validation['uncited_claims_blocked']) === 1, 'one uncited claim should be blocked');
});

echo "citation contract tests passed\n";
