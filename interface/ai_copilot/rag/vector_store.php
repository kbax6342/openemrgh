<?php

require_once(dirname(__DIR__) . '/lab_pdf_vector_store.php');

function aiCopilotRagVectorStoreUpsert(array $records): array
{
    return aiCopilotLabPdfUpsertVectorRecords($records);
}
