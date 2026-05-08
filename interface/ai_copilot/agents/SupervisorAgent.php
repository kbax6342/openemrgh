<?php

require_once(__DIR__ . '/AgentTrace.php');
require_once(__DIR__ . '/DocumentIntakeWorker.php');
require_once(__DIR__ . '/LabExtractionWorker.php');
require_once(__DIR__ . '/IntakeExtractionWorker.php');
require_once(__DIR__ . '/SchemaValidationWorker.php');
require_once(__DIR__ . '/RAGIndexWorker.php');
require_once(__DIR__ . '/RAGRetrievalWorker.php');
require_once(__DIR__ . '/EvidenceSafetyWorker.php');
require_once(__DIR__ . '/CitationValidationWorker.php');
require_once(__DIR__ . '/ClinicianReviewWorker.php');
require_once(dirname(__DIR__) . '/api/document_ingestion_store.php');
require_once(dirname(__DIR__) . '/lab_pdf_ingestion.php');
require_once(dirname(__DIR__) . '/lab_pdf_vector_store.php');

class SupervisorAgent
{
    public function handleAttachment(array $request): array
    {
        $requestId = trim((string) ($request['request_id'] ?? 'request')) ?: 'request';
        $role = strtolower(trim((string) ($request['role'] ?? 'doctor')));
        $patientId = isset($request['patient_id']) && is_numeric($request['patient_id']) ? (int) $request['patient_id'] : 0;
        $docType = aiCopilotDocumentIngestionNormalizeDocType((string) ($request['doc_type'] ?? ''));
        $userId = aiCopilotDocumentIngestionCurrentUserId();
        $trace = new AgentTrace($requestId);

        $trace->add('SupervisorAgent', 'running', 'Validated patient selection, role, and requested document type.', [
            'request_id' => $requestId,
            'patient_id' => $patientId,
            'user_id' => $userId,
            'role' => $role,
            'doc_type' => $docType,
            'status' => 'preflight_auth',
        ]);

        if ($patientId <= 0) {
            $trace->add('SupervisorAgent', 'blocked', 'Document ingestion was blocked because no patient was selected.', [
                'request_id' => $requestId,
                'role' => $role,
                'doc_type' => $docType,
                'error_code' => 'patient_required',
            ]);
            return $this->blockedResult($trace, $role, $patientId, $userId, $docType, 'patient_required', 'Select a patient before uploading a document.');
        }

        if (!in_array($docType, ['lab_pdf', 'intake_form'], true)) {
            $trace->add('SupervisorAgent', 'blocked', 'Document ingestion was blocked because the document type is unsupported.', [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'role' => $role,
                'doc_type' => $docType,
                'error_code' => 'unsupported_doc_type',
            ]);
            return $this->blockedResult($trace, $role, $patientId, $userId, $docType, 'unsupported_doc_type', 'Only lab PDFs and intake forms are supported in this MVP.');
        }

        if (in_array($role, ['billing', 'front_desk'], true)) {
            $trace->add('SupervisorAgent', 'blocked', 'Document ingestion was blocked because this role is not allowed to review clinical extracted facts.', [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'role' => $role,
                'doc_type' => $docType,
                'error_code' => 'role_blocked',
            ]);
            return $this->blockedResult($trace, $role, $patientId, $userId, $docType, 'role_blocked', 'This role cannot ingest or review clinical extracted facts from uploaded documents in this MVP.');
        }

        $intakeWorker = new DocumentIntakeWorker();
        $intakeResult = $intakeWorker->process($request, $trace);
        if (($intakeResult['ok'] ?? false) !== true) {
            return $this->blockedResult(
                $trace,
                $role,
                $patientId,
                $userId,
                $docType,
                (string) ($intakeResult['error_code'] ?? 'document_intake_failed'),
                (string) ($intakeResult['error'] ?? 'Document ingestion could not continue.')
            );
        }

        $sourceDocument = is_array($intakeResult['source_document'] ?? null) ? $intakeResult['source_document'] : [];
        aiCopilotDocumentIngestionUpdateDocumentRecord((int) ($sourceDocument['source_document_id'] ?? 0), [
            'upload_status' => AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_PENDING,
            'extraction_status' => AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_PENDING,
            'review_status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
        ]);

        $trace->add('DocumentIntakeWorker', 'running', 'Routed the stored source document to the correct extraction worker.', [
            'request_id' => $requestId,
            'patient_id' => $patientId,
            'doc_type' => $docType,
            'source_document_id' => $sourceDocument['source_document_id'] ?? null,
            'status' => 'extract_document',
        ]);

        $toolOutput = attach_and_vectorize_lab_pdf([
            'request_id' => $requestId,
            'role' => $role,
            'mode' => 'lab_pdf_ingestion',
            'prompt' => trim((string) ($request['prompt'] ?? '')),
            'patient_key' => (string) ($request['patient_key'] ?? ''),
            'patient_name' => (string) ($request['patient_name'] ?? ''),
            'patient_id' => $patientId,
            'file' => $request['file'] ?? null,
            'file_path' => $request['file_path'] ?? '',
            'use_seeded_demo' => !empty($request['use_seeded_demo']),
            'attachment_purpose' => $docType === 'intake_form' ? 'intake_form' : 'lab_pdf_ingestion',
            'forced_document_type' => $docType,
            'source_document_id' => $sourceDocument['source_document_id'] ?? null,
            'openemr_document_id' => $sourceDocument['openemr_document_id'] ?? null,
            'fhir_document_reference_id' => $sourceDocument['fhir_document_reference_id'] ?? '',
            'fhir_binary_id' => $sourceDocument['fhir_binary_id'] ?? '',
            'review_status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
            'file_hash' => $sourceDocument['file_hash'] ?? '',
            'uploader_role' => $sourceDocument['uploader_role'] ?? $role,
            'uploader_user' => $sourceDocument['uploader_user'] ?? '',
        ]);
        $toolOutput = $this->hydrateToolOutputWithSourceDocument($toolOutput, $sourceDocument, $docType);

        if (!is_array($toolOutput) || $toolOutput === []) {
            aiCopilotDocumentIngestionUpdateDocumentRecord((int) ($sourceDocument['source_document_id'] ?? 0), [
                'extraction_status' => AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_FAILED,
            ]);
            return $this->blockedResult($trace, $role, $patientId, $userId, $docType, 'extraction_failed', 'The document could not be extracted for clinician review.');
        }

        $extractionWorker = $docType === 'intake_form' ? new IntakeExtractionWorker() : new LabExtractionWorker();
        $strictExtraction = $extractionWorker->buildStrictExtraction($toolOutput, $sourceDocument);
        $trace->add(
            $docType === 'intake_form' ? 'IntakeExtractionWorker' : 'LabExtractionWorker',
            'complete',
            $docType === 'intake_form'
                ? 'Built a structured intake-form extraction candidate before strict schema validation.'
                : 'Built a structured lab-PDF extraction candidate before strict schema validation.',
            [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'source_document_id' => $sourceDocument['source_document_id'] ?? null,
                'status' => 'extract_document',
            ]
        );

        $schemaWorker = new SchemaValidationWorker();
        $schemaValidation = $schemaWorker->validate($docType, $strictExtraction, [
            'request_id' => $requestId,
            'patient_id' => $patientId,
            'role' => $role,
            'source_document_id' => $sourceDocument['source_document_id'] ?? null,
        ], $trace);
        $strictExtraction = is_array($schemaValidation['normalized_payload'] ?? null)
            ? $schemaValidation['normalized_payload']
            : $strictExtraction;

        $trace->add(
            'SupervisorAgent',
            ($schemaValidation['schema_valid'] ?? false) ? 'complete' : 'blocked',
            ($schemaValidation['schema_valid'] ?? false)
                ? 'Schema validation completed before pending review persistence and RAG indexing.'
                : 'Schema validation blocked trusted persistence and trusted RAG indexing.',
            [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'source_document_id' => $sourceDocument['source_document_id'] ?? null,
                'schema_valid' => !empty($schemaValidation['schema_valid']),
                'status' => 'validate_schema',
                'error_code' => !empty($schemaValidation['schema_valid']) ? '' : 'schema_validation_failed',
            ]
        );

        if (($schemaValidation['schema_valid'] ?? false) !== true && ($strictExtraction['extraction_status'] ?? '') !== 'failed') {
            $strictExtraction['extraction_status'] = in_array((string) ($toolOutput['status'] ?? ''), ['failed', 'document_guard_rejected'], true)
                ? 'failed'
                : 'review_required';
        }
        if (($strictExtraction['extraction_status'] ?? '') === 'review_required' && (string) ($toolOutput['status'] ?? '') === 'ok') {
            $toolOutput['status'] = 'review_required';
            $toolOutput['ingestion_status'] = 'review_required';
        }
        if (($schemaValidation['schema_valid'] ?? false) !== true) {
            $toolOutput['safe_message'] = (string) ($schemaValidation['user_message'] ?? 'Extraction completed, but the result did not pass strict validation. Clinician review is required before this information can be used.');
        }

        $safetyWorker = new EvidenceSafetyWorker();
        $safety = $safetyWorker->validate($role, $docType, $strictExtraction, $toolOutput, $trace, $schemaValidation);

        $citationWorker = new CitationValidationWorker();
        $citationValidation = $citationWorker->validateExtraction($docType, $strictExtraction, $sourceDocument, $role, $trace);
        $toolOutput['citation_validation'] = $citationValidation;

        if (($citationValidation['valid'] ?? false) !== true || ($citationValidation['blocked'] ?? false) === true) {
            if (($strictExtraction['extraction_status'] ?? '') !== 'failed') {
                $strictExtraction['extraction_status'] = 'review_required';
            }
            if (($toolOutput['status'] ?? '') === 'ok') {
                $toolOutput['status'] = 'review_required';
                $toolOutput['ingestion_status'] = 'review_required';
            }
            $toolOutput['safe_message'] = (string) ($citationValidation['user_message'] ?? 'This response includes clinical information that could not be fully linked to source evidence. Clinician review is required before use.');
        }

        $trustedPersistenceAllowed = ($schemaValidation['trusted_persistence_allowed'] ?? false) === true
            && ($citationValidation['trusted_persistence_allowed'] ?? false) === true;
        $trustedRagIndexAllowed = ($schemaValidation['trusted_rag_index_allowed'] ?? false) === true
            && ($citationValidation['trusted_rag_index_allowed'] ?? false) === true;

        $pendingFacts = [];
        $persistedFacts = [];
        if ($trustedPersistenceAllowed) {
            $pendingFacts = $this->flattenPendingFacts($strictExtraction, $docType);
            $persistedFacts = aiCopilotDocumentIngestionPersistFacts(
                (int) ($sourceDocument['source_document_id'] ?? 0),
                $patientId,
                $docType,
                $pendingFacts
            );
            $trace->add('SupervisorAgent', 'complete', 'Persisted cited extracted facts as pending clinician review only.', [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'source_document_id' => $sourceDocument['source_document_id'] ?? null,
                'status' => 'persist_pending_facts',
            ]);
        } else {
            $trace->add('SupervisorAgent', 'blocked', 'Schema or citation validation prevented trusted fact persistence. Only the source document and review-required state were retained.', [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'source_document_id' => $sourceDocument['source_document_id'] ?? null,
                'status' => 'persist_pending_facts_blocked',
                'error_code' => (($citationValidation['valid'] ?? true) ? 'schema_validation_failed' : 'citation_contract_failed'),
            ]);
        }

        $indexWorker = new RAGIndexWorker();
        if ($trustedRagIndexAllowed) {
            $indexWorker->persist($toolOutput, $sourceDocument, $trace);
        } else {
            aiCopilotLabPdfClearVectorRecords([
                'patient_key' => (string) ($sourceDocument['patient_key'] ?? ''),
                'source_type' => $docType,
                'source_document_id' => isset($sourceDocument['source_document_id']) && is_numeric($sourceDocument['source_document_id'])
                    ? (int) $sourceDocument['source_document_id']
                    : null,
                'request_id' => $requestId,
            ]);
            $toolOutput['retrieval'] = [
                'chunks' => [],
                'chunk_ids' => [],
                'chunk_count' => 0,
            ];
            $toolOutput['vectorized_result'] = [];
            $toolOutput['source_metadata'] = is_array($toolOutput['source_metadata'] ?? null) ? $toolOutput['source_metadata'] : [];
            $toolOutput['source_metadata']['chunk_count'] = 0;
            $trace->add('RAGIndexWorker', 'blocked', 'Trusted RAG indexing was skipped because schema or citation validation did not pass.', [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'source_document_id' => $sourceDocument['source_document_id'] ?? null,
                'status' => 'rag_index_blocked',
                'error_code' => $trustedPersistenceAllowed ? 'citation_contract_failed' : 'schema_validation_failed',
            ]);
        }

        $retrievalWorker = new RAGRetrievalWorker();
        $retrievalSummary = $retrievalWorker->summarize($toolOutput, $trace);

        $reviewWorker = new ClinicianReviewWorker();
        $reviewPayload = $reviewWorker->buildReviewPayload($persistedFacts, $sourceDocument, $docType, $trace);

        aiCopilotDocumentIngestionUpdateDocumentRecord((int) ($sourceDocument['source_document_id'] ?? 0), [
            'extraction_status' => ($strictExtraction['extraction_status'] ?? 'review_required') === 'ok'
                ? 'pending_clinician_review'
                : (($strictExtraction['extraction_status'] ?? 'review_required') === 'failed'
                    ? AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_FAILED
                    : AI_COPILOT_DOCUMENT_UPLOAD_STATUS_EXTRACTION_REVIEW_REQUIRED),
            'review_status' => AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING,
            'metadata_json' => array_merge(
                is_array($sourceDocument['metadata'] ?? null) ? $sourceDocument['metadata'] : [],
                [
                    'strict_extraction_status' => $strictExtraction['extraction_status'] ?? 'review_required',
                    'schema_valid' => !empty($schemaValidation['schema_valid']),
                    'validation_error_count' => count($schemaValidation['validation_errors'] ?? []),
                    'citation_count' => count($strictExtraction['source_citations'] ?? []),
                    'citation_contract_status' => $citationValidation['citation_contract_status'] ?? '',
                    'retrieval_hit_count' => $retrievalSummary['chunk_count'] ?? 0,
                ]
            ),
        ]);

        $safeTrace = $trace->buildSafeTrace([
            'patient_id' => $patientId,
            'user_id' => $userId,
            'role' => $role,
            'workflow_name' => 'document_ingestion_mvp',
        ]);
        aiCopilotDocumentIngestionPersistTrace($safeTrace);

        return [
            'ok' => true,
            'request_id' => $requestId,
            'workflow_name' => 'document_ingestion_mvp',
            'document_type' => $docType,
            'source_document' => $sourceDocument,
            'strict_extraction' => $strictExtraction,
            'schema_validation' => $schemaValidation,
            'citation_validation' => $citationValidation,
            'tool_output' => $toolOutput,
            'pending_review_facts' => $persistedFacts,
            'review_payload' => $reviewPayload,
            'retrieval_summary' => $retrievalSummary,
            'safety' => $safety,
            'workflow_trace' => $safeTrace['steps'] ?? [],
        ];
    }

    private function hydrateToolOutputWithSourceDocument(array $toolOutput, array $sourceDocument, string $docType): array
    {
        if ($toolOutput === []) {
            return $toolOutput;
        }

        $toolOutput['document_metadata'] = is_array($toolOutput['document_metadata'] ?? null) ? $toolOutput['document_metadata'] : [];
        $toolOutput['source_metadata'] = is_array($toolOutput['source_metadata'] ?? null) ? $toolOutput['source_metadata'] : [];
        $toolOutput['document_metadata']['patient_id'] = $sourceDocument['patient_id'] ?? null;
        $toolOutput['document_metadata']['source_document_id'] = $sourceDocument['source_document_id'] ?? null;
        $toolOutput['document_metadata']['openemr_document_id'] = $sourceDocument['openemr_document_id'] ?? null;
        $toolOutput['document_metadata']['fhir_document_reference_id'] = $sourceDocument['fhir_document_reference_id'] ?? '';
        $toolOutput['document_metadata']['fhir_binary_id'] = $sourceDocument['fhir_binary_id'] ?? '';
        $toolOutput['document_metadata']['review_status'] = $sourceDocument['review_status'] ?? AI_COPILOT_DOCUMENT_REVIEW_STATUS_PENDING;
        $toolOutput['document_metadata']['file_hash'] = $sourceDocument['file_hash'] ?? '';
        $toolOutput['document_metadata']['uploader_role'] = $sourceDocument['uploader_role'] ?? '';
        $toolOutput['document_metadata']['uploader_user'] = $sourceDocument['uploader_user'] ?? '';
        $toolOutput['source_metadata']['source_document_id'] = $sourceDocument['source_document_id'] ?? null;
        $toolOutput['source_metadata']['document_type'] = $docType === 'intake_form'
            ? 'intake_form'
            : ($toolOutput['source_metadata']['document_type'] ?? 'lab_results');

        return $toolOutput;
    }

    private function flattenPendingFacts(array $strictExtraction, string $docType): array
    {
        if ($docType === 'lab_pdf') {
            return array_values(array_filter($strictExtraction['labs'] ?? [], 'is_array'));
        }

        $facts = [];

        foreach (($strictExtraction['demographics'] ?? []) as $fieldName => $field) {
            if (!is_array($field) || trim((string) ($field['value'] ?? '')) === '') {
                continue;
            }
            $citation = is_array($field['source_citation'] ?? null) ? $field['source_citation'] : [];
            $facts[] = [
                'field_name' => (string) $fieldName,
                'value' => (string) ($field['value'] ?? ''),
                'normalized_value' => (string) ($field['value'] ?? ''),
                'source_document_id' => (string) ($strictExtraction['source_document_id'] ?? ''),
                'page_or_section' => (string) ($citation['page_or_section'] ?? ('intake_form:' . $fieldName)),
                'source_quote_or_value' => (string) ($citation['quote_or_value'] ?? ''),
                'confidence' => isset($field['confidence']) && is_numeric($field['confidence']) ? (float) $field['confidence'] : 0.0,
                'proposed_openemr_or_fhir_target' => 'Patient',
                'review_status' => 'pending_clinician_review',
                'source_link' => $citation,
            ];
        }

        $chiefConcern = is_array($strictExtraction['chief_concern'] ?? null) ? $strictExtraction['chief_concern'] : [];
        if (trim((string) ($chiefConcern['value'] ?? '')) !== '') {
            $citation = is_array($chiefConcern['source_citation'] ?? null) ? $chiefConcern['source_citation'] : [];
            $facts[] = [
                'field_name' => 'chief_concern',
                'value' => (string) ($chiefConcern['value'] ?? ''),
                'normalized_value' => (string) ($chiefConcern['value'] ?? ''),
                'source_document_id' => (string) ($strictExtraction['source_document_id'] ?? ''),
                'page_or_section' => (string) ($citation['page_or_section'] ?? 'intake_form:chief_concern'),
                'source_quote_or_value' => (string) ($citation['quote_or_value'] ?? ''),
                'confidence' => isset($chiefConcern['confidence']) && is_numeric($chiefConcern['confidence']) ? (float) $chiefConcern['confidence'] : 0.0,
                'proposed_openemr_or_fhir_target' => 'QuestionnaireResponse',
                'review_status' => 'pending_clinician_review',
                'source_link' => $citation,
            ];
        }

        foreach (($strictExtraction['current_medications'] ?? []) as $fact) {
            if (!is_array($fact) || trim((string) ($fact['medication_name'] ?? '')) === '') {
                continue;
            }
            $citation = is_array($fact['source_citation'] ?? null) ? $fact['source_citation'] : [];
            $dose = trim((string) ($fact['dose'] ?? ''));
            $frequency = trim((string) ($fact['frequency'] ?? ''));
            $facts[] = [
                'field_name' => 'current_medication',
                'value' => trim((string) (($fact['medication_name'] ?? '') . ($dose !== '' ? ' ' . $dose : '') . ($frequency !== '' ? ' ' . $frequency : ''))),
                'normalized_value' => $fact,
                'source_document_id' => (string) ($strictExtraction['source_document_id'] ?? ''),
                'page_or_section' => (string) ($citation['page_or_section'] ?? 'intake_form:current_medications'),
                'source_quote_or_value' => (string) ($citation['quote_or_value'] ?? ''),
                'confidence' => isset($fact['confidence']) && is_numeric($fact['confidence']) ? (float) $fact['confidence'] : 0.0,
                'proposed_openemr_or_fhir_target' => 'MedicationStatement',
                'review_status' => 'pending_clinician_review',
                'source_link' => $citation,
            ];
        }

        foreach (($strictExtraction['allergies'] ?? []) as $fact) {
            if (!is_array($fact) || trim((string) ($fact['allergen'] ?? '')) === '') {
                continue;
            }
            $citation = is_array($fact['source_citation'] ?? null) ? $fact['source_citation'] : [];
            $facts[] = [
                'field_name' => 'allergy',
                'value' => trim((string) ($fact['allergen'] ?? '')),
                'normalized_value' => $fact,
                'source_document_id' => (string) ($strictExtraction['source_document_id'] ?? ''),
                'page_or_section' => (string) ($citation['page_or_section'] ?? 'intake_form:allergies'),
                'source_quote_or_value' => (string) ($citation['quote_or_value'] ?? ''),
                'confidence' => isset($fact['confidence']) && is_numeric($fact['confidence']) ? (float) $fact['confidence'] : 0.0,
                'proposed_openemr_or_fhir_target' => 'AllergyIntolerance',
                'review_status' => 'pending_clinician_review',
                'source_link' => $citation,
            ];
        }

        foreach (($strictExtraction['family_history'] ?? []) as $fact) {
            if (!is_array($fact) || trim((string) ($fact['relation'] ?? '')) === '' || trim((string) ($fact['condition'] ?? '')) === '') {
                continue;
            }
            $citation = is_array($fact['source_citation'] ?? null) ? $fact['source_citation'] : [];
            $facts[] = [
                'field_name' => 'family_history',
                'value' => trim((string) (($fact['relation'] ?? '') . ': ' . ($fact['condition'] ?? ''))),
                'normalized_value' => $fact,
                'source_document_id' => (string) ($strictExtraction['source_document_id'] ?? ''),
                'page_or_section' => (string) ($citation['page_or_section'] ?? 'intake_form:family_history'),
                'source_quote_or_value' => (string) ($citation['quote_or_value'] ?? ''),
                'confidence' => isset($fact['confidence']) && is_numeric($fact['confidence']) ? (float) $fact['confidence'] : 0.0,
                'proposed_openemr_or_fhir_target' => 'FamilyMemberHistory',
                'review_status' => 'pending_clinician_review',
                'source_link' => $citation,
            ];
        }

        return $facts;
    }

    private function blockedResult(AgentTrace $trace, string $role, int $patientId, ?int $userId, string $docType, string $errorCode, string $error): array
    {
        $safeTrace = $trace->buildSafeTrace([
            'patient_id' => $patientId,
            'user_id' => $userId,
            'role' => $role,
            'workflow_name' => 'document_ingestion_mvp',
        ]);
        aiCopilotDocumentIngestionPersistTrace($safeTrace);

        return [
            'ok' => false,
            'request_id' => $safeTrace['request_id'] ?? 'request',
            'workflow_name' => 'document_ingestion_mvp',
            'document_type' => $docType,
            'error' => $error,
            'error_code' => $errorCode,
            'tool_output' => aiCopilotLabPdfBuildClientToolOutput([
                'status' => match ($errorCode) {
                    'unsupported_doc_type' => 'unsupported_doc_type',
                    'unsupported_mime_type' => 'invalid_file_type',
                    'role_blocked' => 'role_blocked',
                    default => 'extraction_failed',
                },
                'ingestion_status' => 'failed',
                'safe_message' => $error,
                'extraction_method' => 'not_run',
                'document_metadata' => [
                    'patient_id' => $patientId,
                    'document_type' => $docType,
                ],
                'source_metadata' => [
                    'source_type' => $docType,
                    'request_id' => $safeTrace['request_id'] ?? 'request',
                ],
                'missing_data' => [$error],
                'missing_data_flags' => [$error],
                'schema_validation' => [
                    'schema_valid' => false,
                    'valid' => false,
                    'status_label' => match ($errorCode) {
                        'unsupported_doc_type' => 'Unsupported document type',
                        default => 'Schema validation failed',
                    },
                    'validation_errors' => [[
                        'field' => 'document_type',
                        'issue' => $error,
                        'suggested_review_action' => 'Select a supported document type and retry.',
                        'code' => $errorCode,
                        'severity' => 'error',
                    ]],
                    'errors' => [$error],
                    'review_status' => 'pending_clinician_review',
                    'extraction_status' => 'failed',
                ],
            ]),
            'workflow_trace' => $safeTrace['steps'] ?? [],
        ];
    }
}
