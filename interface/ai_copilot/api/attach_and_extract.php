<?php

require_once(dirname(__DIR__) . '/agents/SupervisorAgent.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

function attach_and_extract(int|string $patientId, string $filePath, string $docType, array $options = []): array
{
    $request = $options;
    $request['patient_id'] = is_numeric($patientId) ? (int) $patientId : 0;
    $request['file_path'] = $filePath;
    $request['doc_type'] = $docType;
    $request['request_id'] = trim((string) ($options['request_id'] ?? 'request')) ?: 'request';
    $supervisor = new SupervisorAgent();
    return $supervisor->handleAttachment($request);
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    require_once(dirname(__DIR__, 2) . '/globals.php');
    header('Content-Type: application/json');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use POST for this endpoint.']);
        exit;
    }

    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $csrfToken = $_POST['csrf_token_form'] ?? '';
    if (!is_string($csrfToken) || !CsrfUtils::verifyCsrfToken($csrfToken, session: $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => xla('Authentication Error')]);
        exit;
    }

    $patientId = isset($_POST['patient_id']) && is_numeric($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
    $docType = trim((string) ($_POST['doc_type'] ?? ''));
    $file = $_FILES['document_attachment'] ?? $_FILES['lab_pdf_attachment'] ?? null;
    $result = attach_and_extract(
        $patientId,
        is_array($file) ? (string) ($file['tmp_name'] ?? '') : '',
        $docType,
        [
            'request_id' => trim((string) ($_POST['request_id'] ?? 'request')),
            'role' => trim((string) ($_POST['role'] ?? 'doctor')),
            'patient_key' => trim((string) ($_POST['patient_key'] ?? '')),
            'patient_name' => trim((string) ($_POST['patient_name'] ?? '')),
            'prompt' => trim((string) ($_POST['message'] ?? '')),
            'file' => is_array($file) ? $file : null,
            'file_name' => is_array($file) ? (string) ($file['name'] ?? 'attached-document.pdf') : 'attached-document.pdf',
            'mime_type' => is_array($file) ? (string) ($file['type'] ?? 'application/pdf') : 'application/pdf',
        ]
    );

    http_response_code(($result['ok'] ?? false) ? 200 : 400);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
