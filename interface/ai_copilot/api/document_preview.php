<?php

require_once(dirname(__DIR__, 2) . '/globals.php');
require_once(__DIR__ . '/../citations/CitationSourceResolver.php');
require_once(dirname(__DIR__, 3) . '/src/Services/DocumentService.php');

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\DocumentService;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (empty($session->get('authUser'))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication required.';
    exit;
}

$sourceDocumentId = isset($_GET['source_document_id']) && is_numeric($_GET['source_document_id']) ? (int) $_GET['source_document_id'] : 0;
$patientId = isset($_GET['patient_id']) && is_numeric($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($sourceDocumentId <= 0 || $patientId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'A valid source document is required.';
    exit;
}

$document = aiCopilotCitationFindDocumentRecord($sourceDocumentId);
if ($document === [] || (int) ($document['patient_id'] ?? 0) !== $patientId) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The requested document preview is not available for this patient.';
    exit;
}

$role = strtolower(trim((string) ($_GET['role'] ?? '')));
if ($role === '') {
    $role = strtolower(trim((string) ($session->get('authProvider') ? '' : ($session->get('authUserRole') ?? 'doctor'))));
}
if ($role === '') {
    $role = 'doctor';
}
if (in_array($role, ['billing', 'front_desk'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The current role does not have access to this clinical document preview.';
    exit;
}

$openemrDocumentId = isset($document['openemr_document_id']) && is_numeric($document['openemr_document_id']) ? (int) $document['openemr_document_id'] : 0;
if ($openemrDocumentId <= 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The original source document is not available.';
    exit;
}

$service = new DocumentService();
$downloadLink = $service->getDownloadLink($openemrDocumentId, $patientId);
header('Location: ' . $downloadLink, true, 302);
exit;
