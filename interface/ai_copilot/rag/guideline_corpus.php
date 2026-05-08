<?php

require_once(__DIR__ . '/rag_types.php');

function aiCopilotGuidelineCorpusDir(): string
{
    return __DIR__ . '/guidelines';
}

function aiCopilotGuidelineCorpusFiles(): array
{
    $files = glob(aiCopilotGuidelineCorpusDir() . '/*.md') ?: [];
    sort($files);
    return array_values(array_filter($files, 'is_file'));
}

function aiCopilotGuidelineParseFrontmatter(string $markdown): array
{
    $metadata = [];
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $markdown, $matches) !== 1) {
        return [
            'metadata' => [],
            'body' => aiCopilotRagNormalizeText($markdown),
        ];
    }

    $frontmatter = trim((string) ($matches[1] ?? ''));
    $body = substr($markdown, strlen((string) ($matches[0] ?? '')));
    $currentListKey = '';

    foreach (preg_split('/\n/', $frontmatter) as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', $trimmed, $parts) === 1) {
            $key = trim((string) $parts[1]);
            $rawValue = trim((string) $parts[2]);
            if ($rawValue === '') {
                $metadata[$key] = [];
                $currentListKey = $key;
            } else {
                $metadata[$key] = trim($rawValue, "\"'");
                $currentListKey = '';
            }
            continue;
        }

        if ($currentListKey !== '' && preg_match('/^-\s+(.+)$/', $trimmed, $parts) === 1) {
            if (!is_array($metadata[$currentListKey] ?? null)) {
                $metadata[$currentListKey] = [];
            }
            $metadata[$currentListKey][] = trim((string) $parts[1], "\"'");
        }
    }

    return [
        'metadata' => $metadata,
        'body' => aiCopilotRagNormalizeText((string) $body),
    ];
}

function aiCopilotGuidelineValidateMetadata(array $metadata): bool
{
    $required = ['source_type', 'source_id', 'title', 'version', 'review_status', 'intended_use'];
    foreach ($required as $field) {
        if (trim((string) ($metadata[$field] ?? '')) === '') {
            return false;
        }
    }

    return true;
}

function aiCopilotGuidelineLoadCorpus(): array
{
    if (!aiCopilotRagGuidelineEnabled()) {
        return [];
    }

    $documents = [];
    foreach (aiCopilotGuidelineCorpusFiles() as $filePath) {
        $contents = file_get_contents($filePath);
        if (!is_string($contents) || trim($contents) === '') {
            continue;
        }

        $parsed = aiCopilotGuidelineParseFrontmatter($contents);
        $metadata = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [];
        if (!aiCopilotGuidelineValidateMetadata($metadata)) {
            continue;
        }

        $documents[] = [
            'file_path' => $filePath,
            'metadata' => [
                'source_type' => trim((string) ($metadata['source_type'] ?? 'demo_guideline')),
                'source_id' => trim((string) ($metadata['source_id'] ?? '')),
                'title' => trim((string) ($metadata['title'] ?? '')),
                'version' => trim((string) ($metadata['version'] ?? '')),
                'review_status' => trim((string) ($metadata['review_status'] ?? 'demo_only')),
                'intended_use' => trim((string) ($metadata['intended_use'] ?? 'source-grounded demo support')),
                'allowed_roles' => is_array($metadata['allowed_roles'] ?? null) ? array_values($metadata['allowed_roles']) : [],
                'workflow_tags' => is_array($metadata['workflow_tags'] ?? null) ? array_values($metadata['workflow_tags']) : [],
            ],
            'body' => aiCopilotRagNormalizeText((string) ($parsed['body'] ?? '')),
        ];
    }

    aiCopilotRagSafeLog('guideline_corpus_loaded', [
        'request_id' => '',
        'role' => '',
        'mode' => 'guideline_corpus',
        'retrieval_mode' => 'guideline_corpus',
        'source_count' => count($documents),
        'guideline_chunk_count' => 0,
        'top_source_ids' => array_slice(array_values(array_unique(array_filter(array_map(static fn($document) => (string) (($document['metadata']['source_id'] ?? '')), $documents), static fn($item) => trim($item) !== ''))), 0, 8),
    ]);

    return $documents;
}
