<?php

require_once(dirname(__DIR__) . '/agents/ClinicianReviewWorker.php');
require_once(dirname(__DIR__) . '/api/document_ingestion_store.php');
require_once(dirname(__DIR__, 2) . '/globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST for this endpoint.']);
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrfToken = $payload['csrf_token_form'] ?? '';
if (!is_string($csrfToken) || !CsrfUtils::verifyCsrfToken($csrfToken, session: $session)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => xla('Authentication Error')]);
    exit;
}

$factId = isset($payload['fact_id']) && is_numeric($payload['fact_id']) ? (int) $payload['fact_id'] : 0;
$decision = trim((string) ($payload['decision'] ?? 'pending'));
$role = strtolower(trim((string) ($payload['role'] ?? 'doctor')));
$note = trim((string) ($payload['note'] ?? ''));

$worker = new ClinicianReviewWorker();
$result = $worker->handleDecision(
    $factId,
    $decision,
    $role,
    aiCopilotDocumentIngestionCurrentUserId(),
    $note
);

if (($result['ok'] ?? false) !== true) {
    http_response_code(400);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$summary = aiCopilotDocumentIngestionBuildReviewSummary((int) ($result['document_id'] ?? 0));
$clientSummary = [
    'documentId' => (int) ($summary['document_id'] ?? 0),
    'pendingCount' => (int) ($summary['pending_count'] ?? 0),
    'approvedCount' => (int) ($summary['approved_count'] ?? 0),
    'rejectedCount' => (int) ($summary['rejected_count'] ?? 0),
    'reviewStatus' => ((int) ($summary['pending_count'] ?? 0) === 0)
        ? 'review_completed'
        : AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
];
echo json_encode([
    'ok' => true,
    'message' => $result['message'] ?? 'Review decision recorded.',
    'fact' => [
        'id' => $result['fact_id'] ?? 0,
        'decision' => $result['decision'] ?? 'pending',
        'review_status' => $result['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
    ],
    'summary' => array_merge($summary, $clientSummary),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
