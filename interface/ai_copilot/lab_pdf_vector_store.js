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
        return {
            id: options.id || `labpdf_${options.requestId || 'request'}_${options.chunkIndex || 0}`,
            patientKey: String(options.patientKey || ''),
            patientDisplayName: String(options.patientDisplayName || ''),
            fileName: String(options.fileName || 'attached-lab-report.pdf'),
            chunkText: String(options.chunkText || ''),
            embedding: Array.isArray(options.embedding) ? options.embedding.slice() : buildDeterministicDemoEmbedding(options.chunkText || ''),
            metadata: {
                sourceType: 'lab_pdf',
                sourceLabel: 'Uploaded lab PDF',
                chunkIndex: Number.isFinite(options.chunkIndex) ? options.chunkIndex : 0,
                uploadedAt: String(options.uploadedAt || new Date().toISOString()),
                sourcePage: options.sourcePage ?? null,
                role: String(options.role || 'Doctor'),
                extractionMethod: String(options.extractionMethod || 'pdf_text'),
                requestId: String(options.requestId || '')
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
                fileName: options.fileName,
                chunkText: chunk.chunkText || '',
                chunkIndex: chunk.chunkIndex ?? index,
                sourcePage: chunk.sourcePage ?? null,
                uploadedAt: options.uploadedAt,
                role: options.role,
                extractionMethod: options.extractionMethod
            });
        });
    }

    function rankRecords(records, prompt, options = {}) {
        const queryEmbedding = buildDeterministicDemoEmbedding(prompt);
        const patientKey = String(options.patientKey || '');
        const fileName = String(options.fileName || '');

        return (Array.isArray(records) ? records : [])
            .filter(function (record) {
                if (patientKey && String(record.patientKey || '') !== patientKey) {
                    return false;
                }
                if (fileName && String(record.fileName || '') !== fileName) {
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
