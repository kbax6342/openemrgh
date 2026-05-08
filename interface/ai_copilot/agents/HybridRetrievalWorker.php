<?php

require_once(dirname(__DIR__) . '/rag/hybrid_retriever.php');
require_once(__DIR__ . '/AgentTrace.php');

class HybridRetrievalWorker
{
    public function retrieve(string $query, array $options, AgentTrace $trace): array
    {
        $result = aiCopilotHybridRetrieve($query, $options);
        $trace->add('HybridRetrievalWorker', $result['retrieval_mode'] === 'no_grounded_evidence' ? 'blocked' : 'complete', 'Merged sparse and dense retrieval candidates into a role-filtered hybrid evidence set.', [
            'request_id' => $options['request_id'] ?? '',
            'role' => $options['role'] ?? '',
            'mode' => $options['mode'] ?? '',
            'status' => 'hybrid_retrieval_completed',
            'retrieval_hit_count' => count($result['candidates'] ?? []),
        ]);

        return $result;
    }
}

