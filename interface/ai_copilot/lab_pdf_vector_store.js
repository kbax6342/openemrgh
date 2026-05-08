(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRLabPdfVectorStore = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const DEMO_COMMENT = 'Demo vector store only. Replace with approved HIPAA-compliant vector storage before production.';

    function tokenize(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9%./]+/g, ' ')
            .trim()
            .split(/\s+/)
            .filter(Boolean);
    }

    function buildDeterministicDemoEmbedding(text, dimensions = 24) {
        const vector = new Array(dimensions).fill(0);
        tokenize(text).forEach(function (token, tokenIndex) {
            let hash = 0;
            for (let charIndex = 0; charIndex < token.length; charIndex += 1) {
                hash = ((hash << 5) - hash) + token.charCodeAt(charIndex);
                hash |= 0;
            }

            const index = Math.abs(hash + tokenIndex) % dimensions;
            vector[index] += 1 + (token.length / 12);
        });

        const magnitude = Math.sqrt(vector.reduce(function (sum, value) {
            return sum + (value * value);
        }, 0));

        if (!magnitude) {
            return vector;
        }

        return vector.map(function (value) {
            return Number((value / magnitude).toFixed(6));
        });
    }

    function cosineSimilarity(left, right) {
        const size = Math.max(Array.isArray(left) ? left.length : 0, Array.isArray(right) ? right.length : 0);
        if (!size) {
            return 0;
        }

        let dot = 0;
        let leftMagnitude = 0;
        let rightMagnitude = 0;
        for (let index = 0; index < size; index += 1) {
            const leftValue = Number(left[index] || 0);
            const rightValue = Number(right[index] || 0);
            dot += leftValue * rightValue;
            leftMagnitude += leftValue * leftValue;
            rightMagnitude += rightValue * rightValue;
        }

        if (!leftMagnitude || !rightMagnitude) {
            return 0;
        }

        return dot / (Math.sqrt(leftMagnitude) * Math.sqrt(rightMagnitude));
    }

    function createRecord(input) {
        const options = input && typeof input === 'object' ? input : {};
        const sourceType = String(options.sourceType || 'lab_pdf');
        const documentType = String(options.documentType || (sourceType === 'intake_form' ? 'intake_form' : 'lab_results'));
        const originalFileName = String(options.originalFileName || options.fileName || 'attached-lab-report.pdf');
        const displayFileName = String(options.displayFileName || originalFileName);
        const sourceId = String(options.sourceId || `source_${options.requestId || 'request'}`);
        return {
            id: options.id || `labpdf_${options.requestId || 'request'}_${options.chunkIndex || 0}`,
            patientId: Number.isFinite(options.patientId) ? options.patientId : null,
            patientKey: String(options.patientKey || ''),
            patientDisplayName: String(options.patientDisplayName || ''),
            sourceDocumentId: Number.isFinite(options.sourceDocumentId) ? options.sourceDocumentId : null,
            fileName: originalFileName,
            displayFileName: displayFileName,
            chunkText: String(options.chunkText || ''),
            embedding: Array.isArray(options.embedding) ? options.embedding.slice() : buildDeterministicDemoEmbedding(options.chunkText || ''),
            metadata: {
                sourceType: sourceType,
                documentType: documentType,
                sourceLabel: String(options.sourceLabel || (sourceType === 'intake_form' ? 'Uploaded intake form' : 'Uploaded lab PDF')),
                originalFileName: originalFileName,
                displayFileName: displayFileName,
                sourceId: sourceId,
                sourceDocumentId: Number.isFinite(options.sourceDocumentId) ? options.sourceDocumentId : null,
                chunkIndex: Number.isFinite(options.chunkIndex) ? options.chunkIndex : 0,
                uploadedAt: String(options.uploadedAt || new Date().toISOString()),
                sourcePage: options.sourcePage ?? null,
                role: String(options.role || 'Doctor'),
                extractionMethod: String(options.extractionMethod || 'pdf_text'),
                requestId: String(options.requestId || ''),
                seededDemo: Boolean(options.seededDemo),
                ingestionOrigin: String(options.ingestionOrigin || 'uploaded_file'),
                reviewStatus: String(options.reviewStatus || 'pending_clinician_review')
            }
        };
    }

    function vectorizeChunks(input) {
        const options = input && typeof input === 'object' ? input : {};
        const chunks = Array.isArray(options.chunks) ? options.chunks : [];
        return chunks.map(function (chunk, index) {
            return createRecord({
                requestId: options.requestId,
                patientKey: options.patientKey,
                patientDisplayName: options.patientDisplayName,
                patientId: options.patientId,
                fileName: options.fileName,
                originalFileName: options.originalFileName || options.fileName,
                displayFileName: options.displayFileName || options.fileName,
                chunkText: chunk.chunkText || '',
                chunkIndex: chunk.chunkIndex ?? index,
                sourcePage: chunk.sourcePage ?? null,
                uploadedAt: options.uploadedAt,
                role: options.role,
                extractionMethod: options.extractionMethod,
                sourceType: options.sourceType,
                sourceLabel: options.sourceLabel,
                documentType: options.documentType,
                sourceId: options.sourceId,
                sourceDocumentId: options.sourceDocumentId,
                seededDemo: options.seededDemo,
                ingestionOrigin: options.ingestionOrigin,
                reviewStatus: options.reviewStatus
            });
        });
    }

    function rankRecords(records, prompt, options = {}) {
        const queryEmbedding = buildDeterministicDemoEmbedding(prompt);
        const patientKey = String(options.patientKey || '');
        const fileName = String(options.fileName || '');
        const sourceType = String(options.sourceType || '').toLowerCase();
        const documentType = String(options.documentType || '').toLowerCase();
        const sourceId = String(options.sourceId || '');

        return (Array.isArray(records) ? records : [])
            .filter(function (record) {
                if (patientKey && String(record.patientKey || '') !== patientKey) {
                    return false;
                }
                if (fileName && String(record.fileName || '') !== fileName) {
                    return false;
                }
                if (sourceType && String(record.metadata && record.metadata.sourceType ? record.metadata.sourceType : '').toLowerCase() !== sourceType) {
                    return false;
                }
                const recordDocumentType = String(
                    record.metadata && record.metadata.documentType
                        ? record.metadata.documentType
                        : ((record.metadata && record.metadata.sourceType ? record.metadata.sourceType : '') === 'intake_form' ? 'intake_form' : 'lab_results')
                ).toLowerCase();
                if (documentType && recordDocumentType !== documentType) {
                    return false;
                }
                if (sourceId && String(record.metadata && record.metadata.sourceId ? record.metadata.sourceId : '') !== sourceId) {
                    return false;
                }
                return true;
            })
            .map(function (record) {
                return {
                    ...record,
                    score: cosineSimilarity(queryEmbedding, record.embedding || [])
                };
            })
            .sort(function (left, right) {
                return right.score - left.score;
            });
    }

    function createInMemoryStore(initialRecords = []) {
        let records = Array.isArray(initialRecords) ? initialRecords.slice() : [];

        return {
            comment: DEMO_COMMENT,
            all() {
                return records.slice();
            },
            upsert(nextRecords) {
                const map = new Map(records.map(function (record) {
                    return [record.id, record];
                }));
                (Array.isArray(nextRecords) ? nextRecords : []).forEach(function (record) {
                    map.set(record.id, record);
                });
                records = Array.from(map.values());
                return this.all();
            },
            listDocuments(options = {}) {
                const patientKey = String(options.patientKey || '');
                const sourceType = String(options.sourceType || '').toLowerCase();
                const documentType = String(options.documentType || '').toLowerCase();
                const documentTypes = Array.isArray(options.documentTypes)
                    ? options.documentTypes.map(function (value) {
                        return String(value || '').toLowerCase();
                    }).filter(Boolean)
                    : [];
                const sourceId = String(options.sourceId || '');
                const ingestionOrigin = String(options.ingestionOrigin || '');
                const documents = new Map();

                records.forEach(function (record) {
                    const metadata = record && record.metadata ? record.metadata : {};
                    const recordSourceType = String(metadata.sourceType || '').toLowerCase();
                    const recordDocumentType = String(metadata.documentType || (recordSourceType === 'intake_form' ? 'intake_form' : 'lab_results')).toLowerCase();
                    const recordSourceId = String(metadata.sourceId || '');
                    const recordIngestionOrigin = String(metadata.ingestionOrigin || '');
                    if (patientKey && String(record.patientKey || '') !== patientKey) {
                        return;
                    }
                    if (sourceType && recordSourceType !== sourceType) {
                        return;
                    }
                    if (documentType && recordDocumentType !== documentType) {
                        return;
                    }
                    if (documentTypes.length > 0 && !documentTypes.includes(recordDocumentType)) {
                        return;
                    }
                    if (sourceId && recordSourceId !== sourceId) {
                        return;
                    }
                    if (ingestionOrigin && recordIngestionOrigin !== ingestionOrigin) {
                        return;
                    }

                    const key = recordSourceId || `${record.patientKey || ''}::${recordDocumentType}::${record.fileName || ''}`;
                    if (!documents.has(key)) {
                        documents.set(key, {
                            sourceId: recordSourceId || key,
                            patientKey: String(record.patientKey || ''),
                            patientName: String(record.patientDisplayName || ''),
                            documentType: recordDocumentType || (recordSourceType === 'intake_form' ? 'intake_form' : 'lab_results'),
                            sourceType: recordSourceType || 'lab_pdf',
                            originalFileName: String(metadata.originalFileName || record.fileName || ''),
                            displayFileName: String(metadata.displayFileName || record.displayFileName || record.fileName || ''),
                            uploadedAt: String(metadata.uploadedAt || ''),
                            extractionMethod: String(metadata.extractionMethod || 'pdf_text'),
                            seededDemo: Boolean(metadata.seededDemo),
                            ingestionOrigin: recordIngestionOrigin || 'uploaded_file',
                            chunkIds: [],
                            chunkCount: 0
                        });
                    }

                    const document = documents.get(key);
                    if (record.id) {
                        document.chunkIds.push(String(record.id));
                    }
                    document.chunkCount += 1;
                });

                return Array.from(documents.values()).map(function (document) {
                    return {
                        ...document,
                        chunkIds: Array.from(new Set(document.chunkIds.filter(Boolean)))
                    };
                }).sort(function (left, right) {
                    return String(right.uploadedAt || '').localeCompare(String(left.uploadedAt || ''))
                        || String(left.displayFileName || '').localeCompare(String(right.displayFileName || ''));
                });
            },
            clearWhere(options = {}) {
                const patientKey = String(options.patientKey || '');
                const sourceType = String(options.sourceType || '').toLowerCase();
                const sourceDocumentId = Number.isFinite(options.sourceDocumentId) ? Number(options.sourceDocumentId) : null;
                const sourceId = String(options.sourceId || '');
                const requestId = String(options.requestId || '');
                const removed = [];
                const kept = [];

                records.forEach(function (record) {
                    const metadata = record && record.metadata ? record.metadata : {};
                    const matchesPatient = !patientKey || String(record.patientKey || '') === patientKey;
                    const matchesSourceType = !sourceType || String(metadata.sourceType || '').toLowerCase() === sourceType;
                    const matchesSourceDocument = sourceDocumentId === null || Number(record.sourceDocumentId ?? metadata.sourceDocumentId ?? NaN) === sourceDocumentId;
                    const matchesSourceId = !sourceId || String(metadata.sourceId || '') === sourceId;
                    const matchesRequestId = !requestId || String(metadata.requestId || '') === requestId;
                    if (matchesPatient && matchesSourceType && matchesSourceDocument && matchesSourceId && matchesRequestId) {
                        removed.push(record);
                        return;
                    }
                    kept.push(record);
                });

                records = kept;
                return {
                    removedRecords: removed.slice(),
                    remainingRecords: kept.slice()
                };
            },
            query(prompt, options = {}) {
                const limit = Math.max(1, Number(options.limit || 4));
                return rankRecords(records, prompt, options).slice(0, limit);
            }
        };
    }

    return {
        DEMO_COMMENT: DEMO_COMMENT,
        buildDeterministicDemoEmbedding: buildDeterministicDemoEmbedding,
        cosineSimilarity: cosineSimilarity,
        createRecord: createRecord,
        vectorizeChunks: vectorizeChunks,
        rankRecords: rankRecords,
        createInMemoryStore: createInMemoryStore
    };
}));
