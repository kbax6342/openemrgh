<?php

require_once(dirname(__DIR__) . '/rag/keyword_retriever.php');
require_once(__DIR__ . '/AgentTrace.php');

class SparseRetrievalWorker
{
    public function retrieve(array $chunks, string $query, string $requestId, string $role, string $mode, AgentTrace $trace): array
    {
        $results = aiCopilotRagSparseRetrieve($chunks, $query, [
            'request_id' => $requestId,
            'role' => $role,
            'workflow_tags' => aiCopilotRagRequestedWorkflowTags($query, $mode),
            'mode' => $mode,
        ]);
        $trace->add('SparseRetrievalWorker', 'complete', 'Completed keyword retrieval over the demo guideline and uploaded-document corpus.', [
            'request_id' => $requestId,
            'role' => $role,
            'mode' => $mode,
            'status' => 'sparse_retrieval_completed',
            'retrieval_hit_count' => count($results),
        ]);

        return $results;
    }
}
