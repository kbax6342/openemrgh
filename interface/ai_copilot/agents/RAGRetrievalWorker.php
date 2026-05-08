<?php

class RAGRetrievalWorker
{
    public function summarize(array $toolOutput, AgentTrace $trace): array
    {
        $retrieval = is_array($toolOutput['retrieval'] ?? null) ? $toolOutput['retrieval'] : [];
        $chunkIds = array_values(array_filter(array_map('strval', $retrieval['chunk_ids'] ?? []), static fn($item) => trim($item) !== ''));
        $chunkCount = isset($retrieval['chunk_count']) && is_numeric($retrieval['chunk_count']) ? (int) $retrieval['chunk_count'] : count($chunkIds);

        $trace->add(
            'RAGRetrievalWorker',
            $chunkCount > 0 ? 'complete' : 'blocked',
            $chunkCount > 0
                ? 'Retrieved patient-scoped uploaded-document chunks for grounding.'
                : 'No grounded uploaded-document chunks were available for retrieval.',
            [
                'request_id' => $toolOutput['source_metadata']['request_id'] ?? '',
                'source_document_id' => $toolOutput['document_metadata']['source_document_id'] ?? null,
                'doc_type' => $toolOutput['document_metadata']['document_type'] ?? '',
                'retrieval_hit_count' => $chunkCount,
                'status' => $chunkCount > 0 ? 'rag_retrieval_completed' : 'rag_retrieval_missing',
            ]
        );

        return [
            'chunk_ids' => $chunkIds,
            'chunk_count' => $chunkCount,
            'confidence' => $chunkCount > 0 ? 0.9 : 0.0,
        ];
    }
}
