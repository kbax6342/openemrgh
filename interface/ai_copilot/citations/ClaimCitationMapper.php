<?php

require_once(__DIR__ . '/CitationContract.php');

function aiCopilotClaimBuild(array $input): array
{
    return [
        'claim_id' => trim((string) ($input['claim_id'] ?? '')),
        'text' => trim((string) ($input['text'] ?? '')),
        'claim_type' => trim((string) ($input['claim_type'] ?? 'clinical_claim')),
        'citations' => array_values(array_filter(array_map(static function ($citation) use ($input): array {
            return is_array($citation) ? aiCopilotBuildCitation($citation, $input['citation_defaults'] ?? []) : [];
        }, is_array($input['citations'] ?? null) ? $input['citations'] : []), static fn($item) => $item !== [])),
        'review_status' => trim((string) ($input['review_status'] ?? 'pending_clinician_review')) ?: 'pending_clinician_review',
    ];
}

function aiCopilotClaimTextFromFact(array $fact): string
{
    $parts = [
        trim((string) ($fact['test_name'] ?? $fact['name'] ?? $fact['field_name'] ?? $fact['label'] ?? '')),
        trim((string) ($fact['value'] ?? '')),
        trim((string) ($fact['unit'] ?? '')),
        trim((string) ($fact['interpretation'] ?? $fact['abnormal_flag'] ?? '')),
    ];

    $parts = array_values(array_filter($parts, static fn($item) => $item !== ''));
    return trim(implode(' ', $parts));
}

function aiCopilotBuildClaimsFromToolOutput(array $toolOutput): array
{
    $claims = [];
    $documentMetadata = is_array($toolOutput['document_metadata'] ?? null) ? $toolOutput['document_metadata'] : [];
    $defaultCitation = [
        'patient_id' => isset($documentMetadata['patient_id']) && is_numeric($documentMetadata['patient_id']) ? (int) $documentMetadata['patient_id'] : null,
        'document_type' => trim((string) ($documentMetadata['document_type'] ?? '')),
        'review_status' => trim((string) ($documentMetadata['review_status'] ?? 'pending_clinician_review')),
        'fhir_document_reference_id' => trim((string) ($documentMetadata['fhir_document_reference_id'] ?? '')),
        'fhir_binary_id' => trim((string) ($documentMetadata['fhir_binary_id'] ?? '')),
    ];

    foreach (($toolOutput['extracted_facts'] ?? []) as $index => $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $citation = is_array($fact['source_citation'] ?? null)
            ? $fact['source_citation']
            : (is_array($fact['source_link'] ?? null) ? $fact['source_link'] : []);
        $text = aiCopilotClaimTextFromFact($fact);
        if ($text === '') {
            continue;
        }

        $claimType = 'clinical_claim';
        $documentType = strtolower(trim((string) ($documentMetadata['document_type'] ?? '')));
        if ($documentType === 'intake_form') {
            $claimType = 'intake_fact';
        } elseif (in_array($documentType, ['lab_pdf', 'lab_results'], true)) {
            $claimType = 'lab_result';
        }

        $claims[] = aiCopilotClaimBuild([
            'claim_id' => 'claim_' . ($index + 1),
            'text' => $text,
            'claim_type' => $claimType,
            'citations' => [$citation],
            'review_status' => trim((string) ($fact['review_status'] ?? $documentMetadata['review_status'] ?? 'pending_clinician_review')),
            'citation_defaults' => $defaultCitation,
        ]);
    }

    foreach (($toolOutput['retrieval']['chunks'] ?? []) as $index => $chunk) {
        if (!is_array($chunk)) {
            continue;
        }
        $chunkText = trim((string) ($chunk['chunk_text'] ?? ''));
        if ($chunkText === '') {
            continue;
        }
        $chunkId = trim((string) ($chunk['id'] ?? ('chunk_' . $index)));
        $sourceType = trim((string) ($chunk['source_type'] ?? 'rag_chunk'));
        $quote = function_exists('mb_substr') ? mb_substr($chunkText, 0, 180) : substr($chunkText, 0, 180);
        $citation = aiCopilotBuildCitation([
            'source_type' => 'rag_chunk',
            'source_id' => trim((string) ($chunk['source_id'] ?? $chunkId)),
            'source_document_id' => isset($chunk['source_document_id']) && is_numeric($chunk['source_document_id']) ? (int) $chunk['source_document_id'] : null,
            'page_or_section' => isset($chunk['source_page']) && is_numeric($chunk['source_page']) ? 'page ' . (int) $chunk['source_page'] : 'retrieved chunk',
            'field_or_chunk_id' => $chunkId,
            'quote_or_value' => $quote,
            'confidence' => isset($chunk['score']) && is_numeric($chunk['score']) ? round((float) $chunk['score'], 4) : 0.8,
            'document_type' => trim((string) ($chunk['document_type'] ?? $documentMetadata['document_type'] ?? '')),
            'review_status' => trim((string) ($chunk['review_status'] ?? $documentMetadata['review_status'] ?? 'pending_clinician_review')),
            'patient_id' => $defaultCitation['patient_id'],
        ]);

        $claims[] = aiCopilotClaimBuild([
            'claim_id' => 'rag_claim_' . ($index + 1),
            'text' => $quote,
            'claim_type' => $sourceType === 'intake_form' ? 'rag_intake_snippet' : 'rag_snippet',
            'citations' => [$citation],
            'review_status' => trim((string) ($chunk['review_status'] ?? $documentMetadata['review_status'] ?? 'pending_clinician_review')),
            'citation_defaults' => $defaultCitation,
        ]);
    }

    return $claims;
}

function aiCopilotBuildSourcesUsedFromClaims(array $claims, array $toolOutput = []): array
{
    $sources = [];
    $seen = [];
    $documentMetadata = is_array($toolOutput['document_metadata'] ?? null) ? $toolOutput['document_metadata'] : [];

    foreach ($claims as $claim) {
        if (!is_array($claim)) {
            continue;
        }
        foreach (($claim['citations'] ?? []) as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $entry = aiCopilotBuildSourceUsedEntry($citation, [
                'display_file_name' => trim((string) ($documentMetadata['display_file_name'] ?? $documentMetadata['title'] ?? '')),
                'original_file_name' => trim((string) ($documentMetadata['original_file_name'] ?? '')),
            ]);
            $key = ($entry['source_id'] ?? '') . '|' . ($entry['label'] ?? '');
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sources[] = $entry;
        }
    }

    return $sources;
}

