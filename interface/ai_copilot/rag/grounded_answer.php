<?php

require_once(__DIR__ . '/reranker.php');
require_once(dirname(__DIR__) . '/citations/ClaimCitationMapper.php');

function aiCopilotGroundedAnswerSafetyNote(string $role): string
{
    return match ($role) {
        'front_desk' => 'Administrative draft only. Human review required. Minimum necessary PHI only.',
        'billing' => 'Draft only. Human billing and compliance review required. No automatic claim actions occur.',
        'nurse' => 'Draft only. Human nursing and clinician review required. Medication changes and orders are restricted.',
        default => 'Draft only. Human clinician review required. No direct chart writes occur without clinician approval.',
    };
}

function aiCopilotGroundedBuildChartEvidence(array $chartContextResult): array
{
    $snippets = [];
    foreach (($chartContextResult['facts'] ?? []) as $index => $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $value = aiCopilotRagNormalizeText((string) ($fact['value'] ?? ''));
        if ($value === '') {
            continue;
        }

        $label = aiCopilotRagNormalizeText((string) ($fact['label'] ?? 'Chart fact'));
        $domain = aiCopilotRagNormalizeText((string) ($fact['domain'] ?? 'chart_context'));
        $sourceId = trim((string) ($fact['source_id'] ?? $domain ?: ('chart_' . $index)));
        $sourceLabel = aiCopilotRagNormalizeText((string) ($fact['source_label'] ?? 'OpenEMR Chart Context'));
        $sourceType = $sourceId === 'latest_approved_ambient_encounter' ? 'ambient_encounter' : 'openemr_chart';
        $text = trim($label . ': ' . $value);
        $citation = aiCopilotBuildCitation([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'page_or_section' => $sourceLabel !== '' ? $sourceLabel : $domain,
            'field_or_chunk_id' => $domain . '_' . $index,
            'quote_or_value' => $value,
            'confidence' => 0.9,
            'document_type' => 'chart',
            'review_status' => 'clinician_approved',
        ]);

        $snippets[] = [
            'text' => $text,
            'relevance_score' => 0.84,
            'retrieval_mode' => 'chart_context',
            'rerank_provider' => 'chart_context',
            'citation' => $citation,
            'source_title' => $sourceLabel !== '' ? $sourceLabel : 'OpenEMR Chart Context',
            'review_status' => 'clinician_approved',
        ];
    }

    return $snippets;
}

function aiCopilotGroundedMergeEvidence(array $guidelineEvidenceResult, array $chartContextResult, array $attachmentResult): array
{
    $snippets = [];
    foreach (($guidelineEvidenceResult['evidence_snippets'] ?? []) as $snippet) {
        if (is_array($snippet)) {
            $snippets[] = $snippet;
        }
    }
    foreach (aiCopilotGroundedBuildChartEvidence($chartContextResult) as $snippet) {
        $snippets[] = $snippet;
    }

    $attachmentClaims = aiCopilotBuildClaimsFromToolOutput(is_array($attachmentResult['tool_output'] ?? null) ? $attachmentResult['tool_output'] : []);
    foreach ($attachmentClaims as $claim) {
        if (!is_array($claim)) {
            continue;
        }
        $citation = is_array($claim['citations'][0] ?? null) ? $claim['citations'][0] : [];
        if ($citation === []) {
            continue;
        }
        $snippets[] = [
            'text' => trim((string) ($claim['text'] ?? '')),
            'relevance_score' => 0.88,
            'retrieval_mode' => 'uploaded_document',
            'rerank_provider' => 'uploaded_document',
            'citation' => $citation,
            'source_title' => aiCopilotCitationSourceLabel($citation),
            'review_status' => trim((string) ($claim['review_status'] ?? 'pending_clinician_review')),
        ];
    }

    $deduped = [];
    $seen = [];
    foreach ($snippets as $snippet) {
        $citation = is_array($snippet['citation'] ?? null) ? $snippet['citation'] : [];
        $key = implode('|', [
            trim((string) ($citation['source_id'] ?? '')),
            trim((string) ($citation['field_or_chunk_id'] ?? '')),
            trim((string) ($snippet['text'] ?? '')),
        ]);
        if ($key === '||' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $snippet;
    }

    usort($deduped, static function (array $left, array $right): int {
        return (($right['relevance_score'] ?? 0.0) <=> ($left['relevance_score'] ?? 0.0));
    });

    return $deduped;
}

function aiCopilotGroundedBuildClaims(array $snippets): array
{
    $claims = [];
    foreach ($snippets as $index => $snippet) {
        if (!is_array($snippet)) {
            continue;
        }
        $citation = is_array($snippet['citation'] ?? null) ? $snippet['citation'] : [];
        if ($citation === [] || trim((string) ($snippet['text'] ?? '')) === '') {
            continue;
        }
        $claims[] = aiCopilotClaimBuild([
            'claim_id' => 'grounded_claim_' . ($index + 1),
            'text' => trim((string) ($snippet['text'] ?? '')),
            'claim_type' => trim((string) ($citation['document_type'] ?? 'grounded_claim')) ?: 'grounded_claim',
            'citations' => [$citation],
            'review_status' => trim((string) ($snippet['review_status'] ?? $citation['review_status'] ?? 'pending_clinician_review')),
        ]);
    }

    return $claims;
}

function aiCopilotGroundedBuildSourcesUsed(array $claims): array
{
    $sources = [];
    $seen = [];
    foreach ($claims as $claim) {
        foreach (($claim['citations'] ?? []) as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $entry = aiCopilotBuildSourceUsedEntry($citation, [
                'label' => aiCopilotCitationSourceLabel($citation),
            ]);
            $key = trim((string) ($entry['source_id'] ?? '')) . '|' . trim((string) ($entry['label'] ?? ''));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sources[] = $entry;
        }
    }

    return $sources;
}

function aiCopilotGroundedBuildDraft(array $input): array
{
    $prompt = trim((string) ($input['prompt'] ?? ''));
    $role = strtolower(trim((string) ($input['role'] ?? 'doctor')));
    $mode = trim((string) ($input['mode'] ?? 'general_assistant'));
    $chartContextResult = is_array($input['chart_context_result'] ?? null) ? $input['chart_context_result'] : [];
    $guidelineEvidenceResult = is_array($input['guideline_evidence_result'] ?? null) ? $input['guideline_evidence_result'] : [];
    $attachmentResult = is_array($input['attachment_result'] ?? null) ? $input['attachment_result'] : [];
    $missingData = array_values(array_unique(array_filter(array_merge(
        is_array($chartContextResult['missing_data'] ?? null) ? $chartContextResult['missing_data'] : [],
        is_array($guidelineEvidenceResult['missing_data'] ?? null) ? $guidelineEvidenceResult['missing_data'] : [],
        is_array($attachmentResult['missing_data'] ?? null) ? $attachmentResult['missing_data'] : []
    ), static fn($item) => is_string($item) && trim($item) !== '')));

    $snippets = aiCopilotGroundedMergeEvidence($guidelineEvidenceResult, $chartContextResult, $attachmentResult);
    if ($snippets === []) {
        aiCopilotRagSafeLog('no_grounded_evidence_found', [
            'request_id' => $input['request_id'] ?? '',
            'role' => $role,
            'mode' => $mode,
            'retrieval_mode' => 'no_grounded_evidence',
            'no_answer_reason' => 'no_grounded_evidence',
        ]);

        return [
            'answer' => 'I do not have enough source-grounded information to answer that safely.',
            'sections' => [
                aiCopilotBuildSection('Summary', ['I do not have enough source-grounded information to answer that safely.'], 'yellow'),
                aiCopilotBuildSection('Missing data / uncertainty', ['No relevant grounded evidence snippets were retrieved for this request.'], 'yellow'),
                aiCopilotBuildSection('Draft-only clinician review', [aiCopilotGroundedAnswerSafetyNote($role)]),
            ],
            'tags' => ['Review needed', 'No grounded evidence'],
            'sources' => [],
            'claims' => [],
            'sources_used' => [],
            'evidence_snippets' => [],
            'missing_data' => $missingData,
            'meta' => [
                'rag_grounded' => false,
                'retrieval_mode' => 'no_grounded_evidence',
                'rerank_provider' => 'fallback_score_sort',
            ],
        ];
    }

    $claims = aiCopilotGroundedBuildClaims($snippets);
    $sourcesUsed = aiCopilotGroundedBuildSourcesUsed($claims);
    $summaryItems = [];
    foreach (array_slice($snippets, 0, 3) as $snippet) {
        $summaryItems[] = trim((string) ($snippet['text'] ?? ''));
    }
    $summaryItems = array_values(array_unique(array_filter($summaryItems, static fn($item) => trim((string) $item) !== '')));
    $keyFindings = $summaryItems;

    if (preg_match('/\b(changed since|last visit|compare)\b/i', $prompt) === 1) {
        $keyFindings[] = 'Use the retrieved evidence above to compare findings with prior chart context. Keep any differences draft-only until clinician review.';
    }

    $sourcesList = [];
    foreach ($sourcesUsed as $source) {
        $sourcesList[] = trim((string) ($source['label'] ?? ''));
    }
    $sourcesList = array_values(array_unique(array_filter($sourcesList, static fn($item) => $item !== '')));

    aiCopilotRagSafeLog('grounded_answer_generated', [
        'request_id' => $input['request_id'] ?? '',
        'role' => $role,
        'mode' => $mode,
        'retrieval_mode' => trim((string) ($guidelineEvidenceResult['retrieval_mode'] ?? 'grounded_merge')),
        'reranked_result_count' => count($snippets),
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($item) => (string) ($item['citation']['source_id'] ?? ''), $snippets), static fn($item) => trim($item) !== ''))), 0, 5),
        'citation_count' => count($claims),
    ]);

    return [
        'answer' => $summaryItems[0] ?? 'Source-grounded draft prepared for review.',
        'sections' => [
            aiCopilotBuildSection('Summary', $summaryItems),
            aiCopilotBuildSection('Key findings', array_slice(array_values(array_unique($keyFindings)), 0, 8)),
            aiCopilotBuildSection('Missing data / uncertainty', $missingData !== [] ? array_slice($missingData, 0, 8) : ['No major source-grounding gaps were identified in the retrieved evidence used for this draft.'], $missingData !== [] ? 'yellow' : 'neutral'),
            aiCopilotBuildSection('Sources Used', $sourcesList !== [] ? $sourcesList : ['No source labels were returned.']),
            aiCopilotBuildSection('Draft-only clinician review', [
                aiCopilotGroundedAnswerSafetyNote($role),
                'No direct chart writes occur without clinician approval.',
            ]),
        ],
        'tags' => ['Grounded response', 'Review needed'],
        'sources' => array_map(static function (array $source): array {
            return [
                'id' => trim((string) ($source['source_id'] ?? '')),
                'title' => trim((string) ($source['label'] ?? '')),
                'label' => trim((string) ($source['label'] ?? '')),
                'category' => trim((string) ($source['document_type'] ?? $source['source_type'] ?? 'source')),
            ];
        }, $sourcesUsed),
        'claims' => $claims,
        'sources_used' => $sourcesUsed,
        'evidence_snippets' => array_slice($snippets, 0, max(1, aiCopilotRagReadIntEnv('AI_COPILOT_TOP_K_RERANKED', 5))),
        'missing_data' => $missingData,
        'meta' => [
            'rag_grounded' => true,
            'retrieval_mode' => trim((string) ($guidelineEvidenceResult['retrieval_mode'] ?? 'grounded_merge')),
            'rerank_provider' => trim((string) ($guidelineEvidenceResult['rerank_provider'] ?? 'fallback_score_sort')),
            'sparse_result_count' => (int) ($guidelineEvidenceResult['sparse_result_count'] ?? 0),
            'dense_result_count' => (int) ($guidelineEvidenceResult['dense_result_count'] ?? 0),
            'hybrid_candidate_count' => (int) ($guidelineEvidenceResult['hybrid_candidate_count'] ?? 0),
            'reranked_result_count' => (int) ($guidelineEvidenceResult['reranked_result_count'] ?? count($snippets)),
        ],
    ];
}

