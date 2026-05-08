<?php

require_once(dirname(__DIR__) . '/lab_pdf_ingestion.php');

function aiCopilotRagRetrieveContext(array $options): array
{
    return aiCopilotLabPdfRetrieveRelevantChunks($options);
}
