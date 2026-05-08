<?php

require_once(dirname(__DIR__) . '/rag/reranker.php');
require_once(__DIR__ . '/AgentTrace.php');

class RerankWorker
{
    public function rerank(array $candidates, string $query, array $options, AgentTrace $trace): array
    {
        $result = aiCopilotRagRerank($candidates, $query, $options);
        $trace->add('RerankWorker', 'complete', 'Reranked hybrid candidates with Cohere when configured and otherwise with local score sorting.', [
            'request_id' => $options['request_id'] ?? '',
            'role' => $options['role'] ?? '',
            'mode' => $options['mode'] ?? '',
            'status' => 'rerank_completed',
            'retrieval_hit_count' => count($result['results'] ?? []),
        ]);

        return $result;
    }
}

