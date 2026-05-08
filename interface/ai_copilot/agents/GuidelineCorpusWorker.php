<?php

require_once(dirname(__DIR__) . '/rag/chunk_guidelines.php');
require_once(__DIR__ . '/AgentTrace.php');

class GuidelineCorpusWorker
{
    public function loadChunks(string $requestId, string $role, string $mode, AgentTrace $trace): array
    {
        $chunks = aiCopilotGuidelineChunkCorpus();
        $trace->add('GuidelineCorpusWorker', 'complete', 'Loaded the local demo guideline corpus and created retrievable chunks.', [
            'request_id' => $requestId,
            'role' => $role,
            'mode' => $mode,
            'status' => 'guideline_corpus_loaded',
            'retrieval_hit_count' => count($chunks),
        ]);

        return $chunks;
    }
}

