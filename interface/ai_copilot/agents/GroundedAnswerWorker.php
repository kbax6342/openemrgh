<?php

require_once(dirname(__DIR__) . '/rag/grounded_answer.php');
require_once(__DIR__ . '/AgentTrace.php');

class GroundedAnswerWorker
{
    public function draft(array $input, AgentTrace $trace): array
    {
        $draft = aiCopilotGroundedBuildDraft($input);
        $trace->add('GroundedAnswerWorker', !empty($draft['meta']['rag_grounded']) ? 'complete' : 'blocked', !empty($draft['meta']['rag_grounded'])
            ? 'Built a draft response using only retrieved grounded evidence snippets.'
            : 'Returned a safe no-answer because grounded evidence was not available.', [
            'request_id' => $input['request_id'] ?? '',
            'role' => $input['role'] ?? '',
            'mode' => $input['mode'] ?? '',
            'status' => !empty($draft['meta']['rag_grounded']) ? 'grounded_answer_generated' : 'no_grounded_evidence_found',
            'retrieval_hit_count' => count($draft['evidence_snippets'] ?? []),
        ]);

        return $draft;
    }
}
