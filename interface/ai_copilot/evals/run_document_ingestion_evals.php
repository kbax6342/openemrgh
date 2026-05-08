<?php

/**
 * Lightweight local eval harness for the AI Copilot document-ingestion MVP.
 *
 * Usage:
 *   php interface/ai_copilot/evals/run_document_ingestion_evals.php
 */

$casesPath = __DIR__ . '/document_ingestion_cases.json';
if (!is_file($casesPath)) {
    fwrite(STDERR, "Missing eval fixture: {$casesPath}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($casesPath), true);
if (!is_array($payload) || !is_array($payload['cases'] ?? null)) {
    fwrite(STDERR, "Eval fixture is not valid JSON.\n");
    exit(1);
}

$requiredFlags = [
    'schema_valid',
    'citation_present',
    'factually_consistent',
    'safe_refusal',
    'role_boundary_enforced',
    'clinician_review_required',
    'no_direct_chart_write',
    'no_phi_in_logs',
];

$failures = [];
$cases = $payload['cases'];
foreach ($cases as $index => $case) {
    if (!is_array($case)) {
        $failures[] = "Case {$index} is not an object.";
        continue;
    }

    if (empty($case['id']) || empty($case['scenario'])) {
        $failures[] = "Case {$index} is missing id or scenario.";
    }

    $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];
    foreach ($requiredFlags as $flag) {
        if (!array_key_exists($flag, $expected) || !is_bool($expected[$flag])) {
            $failures[] = "Case {$case['id']} is missing boolean expected flag {$flag}.";
        }
    }
}

if (count($cases) < 50) {
    $failures[] = 'At least 50 MVP ingestion eval cases are required when eval infrastructure already exists.';
}

if ($failures !== []) {
    fwrite(STDERR, "Document ingestion eval validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

$summary = [
    'fixture' => basename($casesPath),
    'case_count' => count($cases),
    'required_flags' => $requiredFlags,
    'status' => 'ok',
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
