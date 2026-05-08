<?php

require_once(__DIR__ . '/guideline_corpus.php');

function aiCopilotGuidelineSplitSections(string $body): array
{
    $lines = preg_split('/\n/', $body) ?: [];
    $sections = [];
    $currentHeading = 'Overview';
    $currentLines = [];

    foreach ($lines as $line) {
        $line = rtrim((string) $line);
        if (preg_match('/^#{1,3}\s+(.+)$/', $line, $matches) === 1) {
            if ($currentLines !== []) {
                $sections[] = [
                    'heading' => $currentHeading,
                    'content' => aiCopilotRagNormalizeText(implode("\n", $currentLines)),
                ];
            }
            $currentHeading = trim((string) $matches[1]);
            $currentLines = [];
            continue;
        }

        $currentLines[] = $line;
    }

    if ($currentLines !== []) {
        $sections[] = [
            'heading' => $currentHeading,
            'content' => aiCopilotRagNormalizeText(implode("\n", $currentLines)),
        ];
    }

    return array_values(array_filter($sections, static fn($section) => trim((string) ($section['content'] ?? '')) !== ''));
}

function aiCopilotGuidelineChunkSection(string $sourceId, string $title, array $metadata, string $heading, string $content, int $startIndex): array
{
    $maxLength = 1800;
    $overlapLength = 320;
    $paragraphs = preg_split('/\n{2,}/', $content) ?: [];
    $chunks = [];
    $buffer = '';
    $chunkIndex = $startIndex;

    foreach ($paragraphs as $paragraph) {
        $paragraph = aiCopilotRagNormalizeText((string) $paragraph);
        if ($paragraph === '') {
            continue;
        }

        $candidate = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
        if ($buffer === '' || strlen($candidate) <= $maxLength) {
            $buffer = $candidate;
            continue;
        }

        $chunkId = sprintf('%s_chunk_%03d', $sourceId, $chunkIndex);
        $chunks[] = [
            'chunk_id' => $chunkId,
            'source_type' => trim((string) ($metadata['source_type'] ?? 'demo_guideline')),
            'source_id' => $sourceId,
            'title' => $title,
            'document_type' => 'rag',
            'page_or_section' => $heading,
            'field_or_chunk_id' => $chunkId,
            'text' => $buffer,
            'quote_or_value' => aiCopilotRagPreviewText($buffer, 240),
            'allowed_roles' => is_array($metadata['allowed_roles'] ?? null) ? array_values($metadata['allowed_roles']) : [],
            'workflow_tags' => is_array($metadata['workflow_tags'] ?? null) ? array_values($metadata['workflow_tags']) : [],
            'review_status' => trim((string) ($metadata['review_status'] ?? 'demo_only')),
            'confidence' => 0.86,
        ];
        $chunkIndex++;
        $tail = substr($buffer, max(0, strlen($buffer) - $overlapLength));
        $buffer = aiCopilotRagNormalizeText($tail . "\n\n" . $paragraph);
    }

    if ($buffer !== '') {
        $chunkId = sprintf('%s_chunk_%03d', $sourceId, $chunkIndex);
        $chunks[] = [
            'chunk_id' => $chunkId,
            'source_type' => trim((string) ($metadata['source_type'] ?? 'demo_guideline')),
            'source_id' => $sourceId,
            'title' => $title,
            'document_type' => 'rag',
            'page_or_section' => $heading,
            'field_or_chunk_id' => $chunkId,
            'text' => $buffer,
            'quote_or_value' => aiCopilotRagPreviewText($buffer, 240),
            'allowed_roles' => is_array($metadata['allowed_roles'] ?? null) ? array_values($metadata['allowed_roles']) : [],
            'workflow_tags' => is_array($metadata['workflow_tags'] ?? null) ? array_values($metadata['workflow_tags']) : [],
            'review_status' => trim((string) ($metadata['review_status'] ?? 'demo_only')),
            'confidence' => 0.86,
        ];
    }

    return $chunks;
}

function aiCopilotGuidelineChunkCorpus(): array
{
    $documents = aiCopilotGuidelineLoadCorpus();
    $chunks = [];

    foreach ($documents as $document) {
        $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
        $sourceId = trim((string) ($metadata['source_id'] ?? ''));
        $title = trim((string) ($metadata['title'] ?? 'Demo Guideline'));
        $sections = aiCopilotGuidelineSplitSections((string) ($document['body'] ?? ''));
        $chunkIndex = 1;

        foreach ($sections as $section) {
            $sectionChunks = aiCopilotGuidelineChunkSection(
                $sourceId,
                $title,
                $metadata,
                trim((string) ($section['heading'] ?? 'Overview')),
                trim((string) ($section['content'] ?? '')),
                $chunkIndex
            );
            $chunkIndex += count($sectionChunks);
            foreach ($sectionChunks as $chunk) {
                $chunks[] = aiCopilotRagNormalizeChunk($chunk);
            }
        }
    }

    aiCopilotRagSafeLog('guideline_chunks_created', [
        'request_id' => '',
        'role' => '',
        'mode' => 'guideline_corpus',
        'retrieval_mode' => 'guideline_corpus',
        'source_count' => count($documents),
        'guideline_chunk_count' => count($chunks),
        'hybrid_candidate_count' => count($chunks),
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($chunk) => (string) ($chunk['source_id'] ?? ''), $chunks), static fn($item) => trim($item) !== ''))), 0, 8),
    ]);

    return $chunks;
}

function aiCopilotGuidelineFindChunkByIdentifiers(string $sourceId, string $chunkId = ''): array
{
    foreach (aiCopilotGuidelineChunkCorpus() as $chunk) {
        if ($chunkId !== '' && trim((string) ($chunk['chunk_id'] ?? '')) === $chunkId) {
            return $chunk;
        }
        if ($chunkId === '' && trim((string) ($chunk['source_id'] ?? '')) === $sourceId) {
            return $chunk;
        }
    }

    return [];
}
