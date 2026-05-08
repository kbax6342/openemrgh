<?php

require_once(dirname(__DIR__) . '/api/document_ingestion_store.php');

class DocumentIntakeWorker
{
    public function process(array $request, AgentTrace $trace): array
    {
        $docType = aiCopilotDocumentIngestionNormalizeDocType((string) ($request['doc_type'] ?? ''));
        $file = is_array($request['file'] ?? null) ? $request['file'] : null;
        $patientId = isset($request['patient_id']) && is_numeric($request['patient_id']) ? (int) $request['patient_id'] : 0;

        if ($patientId <= 0) {
            $trace->add('DocumentIntakeWorker', 'blocked', 'No patient was selected for document ingestion.', [
                'request_id' => $request['request_id'] ?? '',
                'patient_id' => null,
                'doc_type' => $docType,
                'error_code' => 'patient_required',
            ]);
            return [
                'ok' => false,
                'error' => 'Select a patient before uploading a document.',
                'error_code' => 'patient_required',
            ];
        }

        if (!in_array($docType, ['lab_pdf', 'intake_form'], true)) {
            $trace->add('DocumentIntakeWorker', 'blocked', 'Unsupported document type requested.', [
                'request_id' => $request['request_id'] ?? '',
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'error_code' => 'unsupported_doc_type',
            ]);
            return [
                'ok' => false,
                'error' => 'Only lab PDFs and intake forms are supported in this MVP.',
                'error_code' => 'unsupported_doc_type',
            ];
        }

        $stored = aiCopilotDocumentIngestionStoreOriginalDocument([
            'patient_id' => $patientId,
            'patient_key' => $request['patient_key'] ?? '',
            'patient_name' => $request['patient_name'] ?? '',
            'doc_type' => $docType,
            'file' => $file,
            'file_path' => $request['file_path'] ?? '',
            'file_name' => $request['file_name'] ?? ($file['name'] ?? 'attached-document.pdf'),
            'display_file_name' => $request['display_file_name'] ?? ($file['name'] ?? 'attached-document.pdf'),
            'mime_type' => $request['mime_type'] ?? ($file['type'] ?? 'application/pdf'),
            'role' => $request['role'] ?? 'doctor',
            'request_id' => $request['request_id'] ?? 'request',
        ]);
        if (($stored['ok'] ?? false) !== true) {
            $trace->add('DocumentIntakeWorker', 'blocked', 'Original source document storage failed.', [
                'request_id' => $request['request_id'] ?? '',
                'patient_id' => $patientId,
                'doc_type' => $docType,
                'error_code' => $stored['error_code'] ?? 'document_store_failed',
            ]);
            return $stored;
        }

        $trace->add('DocumentIntakeWorker', 'complete', 'Stored original source document in OpenEMR document storage.', [
            'request_id' => $request['request_id'] ?? '',
            'patient_id' => $patientId,
            'doc_type' => $docType,
            'source_document_id' => $stored['source_document_id'] ?? null,
            'status' => 'source_document_stored',
        ]);

        return [
            'ok' => true,
            'source_document' => $stored,
        ];
    }
}
