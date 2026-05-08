<?php

require_once(dirname(__DIR__) . '/rag/vector_retriever.php');
require_once(__DIR__ . '/AgentTrace.php');

class DenseRetrievalWorker
{
    public function retrieve(array $chunks, string $query, string $requestId, string $role, string $mode, AgentTrace $trace): array
    {
        $results = aiCopilotRagDenseRetrieve($chunks, $query, [
            'request_id' => $requestId,
            'role' => $role,
            'mode' => $mode,
            'workflow_tags' => aiCopilotRagRequestedWorkflowTags($query, $mode),
        ]);
        $trace->add('DenseRetrievalWorker', $results !== [] ? 'complete' : 'running', 'Completed dense retrieval or fell back safely when embeddings were unavailable.', [
            'request_id' => $requestId,
            'role' => $role,
            'mode' => $mode,
            'status' => 'dense_retrieval_completed',
            'retrieval_hit_count' => count($results),
        ]);

        return $results;
    }
}

