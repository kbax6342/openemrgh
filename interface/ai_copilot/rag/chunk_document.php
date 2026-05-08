<?php

require_once(dirname(__DIR__) . '/lab_pdf_ingestion.php');

function aiCopilotRagChunkDocument(string $text): array
{
    return aiCopilotLabPdfChunkText($text);
}
