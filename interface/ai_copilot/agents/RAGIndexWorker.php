<?php

require_once(dirname(__DIR__) . '/api/document_ingestion_store.php');

class RAGIndexWorker
{
    public function persist(array $toolOutput, array $sourceDocument, AgentTrace $trace): array
    {
        $sourceDocumentId = isset($sourceDocument['source_document_id']) && is_numeric($sourceDocument['source_document_id']) ? (int) $sourceDocument['source_document_id'] : 0;
        $patientId = isset($sourceDocument['patient_id']) && is_numeric($sourceDocument['patient_id']) ? (int) $sourceDocument['patient_id'] : 0;
        $docType = aiCopilotDocumentIngestionNormalizeDocType((string) ($sourceDocument['doc_type'] ?? ''));
        $vectorized = is_array($toolOutput['vectorized_result'] ?? null) ? $toolOutput['vectorized_result'] : [];

        $chunkCount = aiCopilotDocumentIngestionPersistRagChunks(
            $sourceDocumentId,
            $patientId,
            $docType,
            $vectorized,
            AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING
        );

        $trace->add('RAGIndexWorker', 'complete', 'Stored minimum-necessary RAG chunk metadata for patient-scoped retrieval.', [
            'request_id' => $toolOutput['source_metadata']['request_id'] ?? '',
            'patient_id' => $patientId,
            'doc_type' => $docType,
            'source_document_id' => $sourceDocumentId,
            'retrieval_hit_count' => $chunkCount,
            'status' => 'rag_index_completed',
        ]);

        return [
            'ok' => true,
            'chunk_count' => $chunkCount,
        ];
    }
}
