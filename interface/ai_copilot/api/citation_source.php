<?php

require_once(dirname(__DIR__, 2) . '/globals.php');
require_once(__DIR__ . '/../citations/CitationSourceResolver.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST for this endpoint.']);
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$payload = $_POST;
if ($payload === []) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode((string) $rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$csrfToken = $payload['csrf_token_form'] ?? '';
if (!is_string($csrfToken) || !CsrfUtils::verifyCsrfToken($csrfToken, session: $session)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => xla('Authentication Error')]);
    exit;
}

$citationPayload = $payload['citation'] ?? [];
if (is_string($citationPayload)) {
    $citation = json_decode($citationPayload, true);
    if (!is_array($citation)) {
        $citation = [];
    }
} elseif (is_array($citationPayload)) {
    $citation = $citationPayload;
} else {
    $citation = [];
}

$patientId = isset($payload['patient_id']) && is_numeric($payload['patient_id']) ? (int) $payload['patient_id'] : 0;
$role = strtolower(trim((string) ($payload['role'] ?? 'doctor')));
$preview = aiCopilotCitationResolveSourcePreview($citation, [
    'patient_id' => $patientId,
    'role' => $role,
]);

if (($preview['ok'] ?? false) !== true) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => (string) ($preview['error'] ?? 'The cited source could not be loaded safely.'),
        'error_code' => (string) ($preview['error_code'] ?? 'citation_source_unavailable'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'preview' => $preview,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
