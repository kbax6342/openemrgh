<?php

require_once(dirname(__DIR__) . '/citations/CitationContract.php');
require_once(dirname(__DIR__) . '/citations/CitationValidator.php');
require_once(dirname(__DIR__) . '/citations/CitationSourceResolver.php');

function assertTrueForCitationPreview(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, $label . PHP_EOL);
        exit(1);
    }
}

function runCitationPreviewAssertion(string $label, callable $callback): void
{
    $callback();
}

$baseCitation = [
    'source_type' => 'rag_chunk',
    'source_id' => 'chunk_source_1',
    'patient_id' => 17,
    'page_or_section' => 'retrieved chunk',
    'field_or_chunk_id' => 'chunk_abc123',
    'quote_or_value' => 'Metformin 500mg twice daily',
    'confidence' => 0.88,
    'document_type' => 'intake_form',
    'review_status' => 'pending_clinician_review',
];

runCitationPreviewAssertion('click-to-source preview returns safe snippet data', static function () use ($baseCitation): void {
    $preview = aiCopilotCitationResolveSourcePreview($baseCitation, [
        'patient_id' => 17,
        'role' => 'doctor',
    ]);

    assertTrueForCitationPreview($preview['ok'] === true, 'preview should resolve successfully');
    assertTrueForCitationPreview($preview['preview_mode'] === 'rag_snippet' || $preview['preview_mode'] === 'text', 'preview should resolve to snippet or text mode');
    assertTrueForCitationPreview(trim((string) ($preview['quote_or_value'] ?? '')) !== '', 'preview must include quote/value');
});

runCitationPreviewAssertion('billing role preview is blocked for clinical citations', static function () use ($baseCitation): void {
    $preview = aiCopilotCitationResolveSourcePreview($baseCitation, [
        'patient_id' => 17,
        'role' => 'billing',
    ]);

    assertTrueForCitationPreview($preview['ok'] === false, 'billing role preview is blocked for clinical citations');
});

runCitationPreviewAssertion('PDF overlay renders when bounding_box exists', static function () use ($baseCitation): void {
    $citation = $baseCitation;
    $citation['source_type'] = 'lab_pdf';
    $citation['document_type'] = 'lab_pdf';
    $citation['bounding_box'] = [
        'page' => 1,
        'x' => 0.22,
        'y' => 0.34,
        'width' => 0.31,
        'height' => 0.04,
        'coordinate_system' => 'normalized_0_1',
    ];
    $preview = aiCopilotCitationResolveSourcePreview($citation, [
        'patient_id' => 17,
        'role' => 'doctor',
    ]);

    assertTrueForCitationPreview($preview['ok'] === true, 'pdf preview should resolve');
    assertTrueForCitationPreview(is_array($preview['bounding_box']), 'preview should preserve bounding box metadata');
});

runCitationPreviewAssertion('PDF overlay safely falls back when bounding_box missing', static function () use ($baseCitation): void {
    $citation = $baseCitation;
    $citation['source_type'] = 'lab_pdf';
    $citation['document_type'] = 'lab_pdf';
    unset($citation['bounding_box']);
    $preview = aiCopilotCitationResolveSourcePreview($citation, [
        'patient_id' => 17,
        'role' => 'doctor',
    ]);

    assertTrueForCitationPreview($preview['ok'] === true, 'pdf preview without bounding box should still resolve');
    assertTrueForCitationPreview(!isset($preview['bounding_box']) || $preview['bounding_box'] === null, 'preview should safely fall back when bounding box missing');
});

echo "citation source preview tests passed\n";
