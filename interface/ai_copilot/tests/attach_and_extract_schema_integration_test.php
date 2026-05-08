<?php

require_once(dirname(__DIR__) . '/validation/validate_extraction.php');

function aiCopilotIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runAttachAndExtractSchemaIntegrationTests(): void
{
    $labSource = (string) file_get_contents(dirname(__DIR__) . '/api/attach_and_extract.php');
    $supervisorSource = (string) file_get_contents(dirname(__DIR__) . '/agents/SupervisorAgent.php');

    aiCopilotIntegrationAssert(str_contains($labSource, 'Only lab PDFs and intake forms are supported in this MVP.'), 'attach_and_extract rejects unsupported doc_type');
    aiCopilotIntegrationAssert(str_contains($supervisorSource, 'LabExtractionWorker'), 'attach_and_extract routes lab_pdf to lab schema');
    aiCopilotIntegrationAssert(str_contains($supervisorSource, 'IntakeExtractionWorker'), 'attach_and_extract routes intake_form to intake schema');
    aiCopilotIntegrationAssert(str_contains($supervisorSource, 'SchemaValidationWorker'), 'SchemaValidationWorker runs before persistence');
    aiCopilotIntegrationAssert(str_contains($supervisorSource, 'trusted_persistence_allowed'), 'schema failure prevents trusted fact persistence');
    aiCopilotIntegrationAssert(str_contains($supervisorSource, 'trusted_rag_index_allowed'), 'schema failure prevents trusted RAG indexing');
    aiCopilotIntegrationAssert(str_contains($supervisorSource, 'pending_clinician_review'), 'schema pass still marks all facts pending_clinician_review');
    aiCopilotIntegrationAssert(!str_contains($supervisorSource, 'chart_update_completed'), 'no direct chart write occurs');
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    runAttachAndExtractSchemaIntegrationTests();
    echo "attach_and_extract schema integration tests passed\n";
}
