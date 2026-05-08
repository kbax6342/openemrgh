<?php

require_once(dirname(__DIR__) . '/api/document_ingestion_store.php');

class ClinicianReviewWorker
{
    public function buildReviewPayload(array $persistedFacts, array $sourceDocument, string $docType, AgentTrace $trace): array
    {
        $facts = [];
        foreach ($persistedFacts as $persisted) {
            if (!is_array($persisted)) {
                continue;
            }

            $fact = is_array($persisted['fact'] ?? null) ? $persisted['fact'] : [];
            $citation = is_array($fact['source_citation'] ?? null)
                ? $fact['source_citation']
                : (is_array($fact['source_link'] ?? null) ? $fact['source_link'] : []);
            $label = trim((string) ($fact['test_name'] ?? $fact['medication_name'] ?? $fact['allergen'] ?? $fact['relation'] ?? $fact['field_name'] ?? 'Pending fact'));
            $value = trim((string) ($fact['value'] ?? $fact['condition'] ?? ''));
            if ($label !== '' && $value === '' && isset($fact['dose'], $fact['frequency'])) {
                $value = trim((string) (($fact['dose'] ?? '') . ' ' . ($fact['frequency'] ?? '')));
            }
            $facts[] = [
                'id' => isset($persisted['fact_id']) ? (int) $persisted['fact_id'] : 0,
                'label' => $label !== '' ? $label : 'Pending fact',
                'value' => $value,
                'proposedTarget' => trim((string) ($fact['proposed_fhir_resource_type'] ?? $fact['proposed_openemr_or_fhir_target'] ?? '')),
                'sourceDocumentId' => isset($sourceDocument['source_document_id']) ? (int) $sourceDocument['source_document_id'] : 0,
                'reviewStatus' => trim((string) ($persisted['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING)),
                'citation' => $citation,
            ];
        }

        $summary = aiCopilotDocumentIngestionBuildReviewSummary((int) ($sourceDocument['source_document_id'] ?? 0));
        $clientSummary = [
            'documentId' => (int) ($summary['document_id'] ?? 0),
            'pendingCount' => (int) ($summary['pending_count'] ?? 0),
            'approvedCount' => (int) ($summary['approved_count'] ?? 0),
            'rejectedCount' => (int) ($summary['rejected_count'] ?? 0),
            'reviewStatus' => ((int) ($summary['pending_count'] ?? 0) === 0)
                ? 'review_completed'
                : AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
        ];
        $trace->add('ClinicianReviewWorker', 'complete', 'Prepared approve / reject / pending review controls for extracted draft facts.', [
            'request_id' => $sourceDocument['metadata']['request_id'] ?? '',
            'source_document_id' => $sourceDocument['source_document_id'] ?? null,
            'doc_type' => $docType,
            'review_status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
            'status' => 'clinician_review_gate',
        ]);

        return [
            'sourceDocumentId' => isset($sourceDocument['source_document_id']) ? (int) $sourceDocument['source_document_id'] : 0,
            'docType' => $docType,
            'status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
            'banner' => 'Clinician Review Required',
            'helperText' => 'Approved for demo review — not written to chart automatically.',
            'facts' => $facts,
            'summary' => array_merge($summary, $clientSummary),
        ];
    }

    public function handleDecision(int $factId, string $decision, string $role, ?int $reviewerUserId = null, string $note = ''): array
    {
        return aiCopilotDocumentIngestionReviewFact($factId, $decision, $role, $reviewerUserId, $note);
    }
}
