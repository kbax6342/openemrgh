(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRLabPdfIngestionDemo = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const TOOL_NAME = 'attach_and_vectorize_lab_pdf';
    const ACCEPT_ATTRIBUTE = 'application/pdf,.pdf';
    const SEEDED_FILE_NAME = 'marcus-johnson-labs-may-2026.pdf';
    const REVIEW_NOTICE = 'This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original lab PDF.';
    const SEEDED_TEXT = [
        'Patient: Marcus Johnson',
        'Document: marcus-johnson-labs-may-2026.pdf',
        'Hemoglobin A1c: 8.2 %, high',
        'LDL Cholesterol: 142 mg/dL, high',
        'Creatinine: 1.1 mg/dL, normal',
        'eGFR: 82 mL/min/1.73m2, normal',
        'Missing:',
        '- Ordering provider not clearly detected',
        '- Collection time not clearly detected'
    ].join('\n');

    const PROMPT_INJECTION_PATTERNS = [
        /\bignore (all|any|previous|prior) instructions\b/i,
        /\breveal (the )?(system prompt|hidden prompt|hidden notes)\b/i,
        /\bwrite directly to the chart\b/i,
        /\bdiagnose this patient\b/i,
        /\boverride (guardrails|safety|policy)\b/i
    ];

    function normalizeWhitespace(value) {
        return String(value || '')
            .replace(/\r/g, '\n')
            .replace(/\u0000/g, ' ')
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function isPdfLike(fileLike) {
        if (!fileLike || typeof fileLike !== 'object') {
            return false;
        }

        const fileName = String(fileLike.name || fileLike.fileName || '').toLowerCase();
        const mimeType = String(fileLike.type || fileLike.mimeType || '').toLowerCase();
        return mimeType === 'application/pdf' || /\.pdf$/i.test(fileName);
    }

    function decodePdfLiteralString(value) {
        return String(value || '')
            .replace(/\\\(/g, '(')
            .replace(/\\\)/g, ')')
            .replace(/\\n/g, '\n')
            .replace(/\\r/g, '\n')
            .replace(/\\t/g, '\t')
            .replace(/\\([0-7]{3})/g, function (_, octalValue) {
                return String.fromCharCode(parseInt(octalValue, 8));
            })
            .replace(/\\\\/g, '\\');
    }

    function extractPrintableTextFromPdfBuffer(arrayBuffer) {
        if (!arrayBuffer) {
            return '';
        }

        const bytes = arrayBuffer instanceof Uint8Array ? arrayBuffer : new Uint8Array(arrayBuffer);
        let binary = '';
        for (let index = 0; index < bytes.length; index += 1) {
            binary += String.fromCharCode(bytes[index]);
        }

        const collected = [];
        const literalPattern = /\((?:\\.|[^()])+\)\s*Tj/g;
        const arrayPattern = /\[((?:\((?:\\.|[^()])+\)\s*)+)\]\s*TJ/g;

        let match = literalPattern.exec(binary);
        while (match) {
            collected.push(decodePdfLiteralString(match[0].replace(/\)\s*Tj$/, '').slice(1)));
            match = literalPattern.exec(binary);
        }

        match = arrayPattern.exec(binary);
        while (match) {
            const inner = match[1];
            const textParts = [];
            inner.replace(/\((?:\\.|[^()])+\)/g, function (item) {
                textParts.push(decodePdfLiteralString(item.slice(1, -1)));
                return item;
            });
            if (textParts.length > 0) {
                collected.push(textParts.join(' '));
            }
            match = arrayPattern.exec(binary);
        }

        if (collected.length === 0) {
            const printableMatches = binary.match(/[A-Za-z0-9%/.,:_ -]{6,}/g) || [];
            collected.push(printableMatches.join('\n'));
        }

        return normalizeWhitespace(collected.join('\n'));
    }

    function detectPromptInjectionText(text) {
        return PROMPT_INJECTION_PATTERNS
            .filter(function (pattern) {
                return pattern.test(String(text || ''));
            })
            .map(function (pattern) {
                return pattern.source;
            });
    }

    function buildSeededFallbackDocument(options = {}) {
        return {
            fileName: options.fileName || SEEDED_FILE_NAME,
            patientKey: String(options.patientKey || 'marcus-johnson'),
            patientName: String(options.patientName || 'Marcus Johnson'),
            extractionMethod: 'seeded_demo_fallback',
            text: SEEDED_TEXT,
            missingData: [
                'Ordering provider not clearly detected.',
                'Collection time not clearly detected.'
            ]
        };
    }

    function extractTextOrSeedFallback(options = {}) {
        const fileName = String(options.fileName || SEEDED_FILE_NAME);
        const forceSeededFallback = Boolean(options.forceSeededFallback);
        const patientKey = String(options.patientKey || 'marcus-johnson');
        const patientName = String(options.patientName || 'Marcus Johnson');
        let extractedText = normalizeWhitespace(options.extractedText || '');

        if (!extractedText && options.arrayBuffer && !forceSeededFallback) {
            extractedText = extractPrintableTextFromPdfBuffer(options.arrayBuffer);
        }

        if (!extractedText || forceSeededFallback) {
            const seeded = buildSeededFallbackDocument({
                fileName,
                patientKey,
                patientName
            });

            return {
                status: forceSeededFallback ? 'seeded_demo_fallback' : 'ocr_required_seeded_demo_fallback',
                extractionMethod: seeded.extractionMethod,
                text: seeded.text,
                preview: seeded.text.slice(0, 240),
                missingData: seeded.missingData.slice(),
                promptInjectionMatches: detectPromptInjectionText(seeded.text)
            };
        }

        return {
            status: 'ok',
            extractionMethod: 'pdf_text',
            text: extractedText,
            preview: extractedText.slice(0, 240),
            missingData: [],
            promptInjectionMatches: detectPromptInjectionText(extractedText)
        };
    }

    function chunkLabPdfText(text, options = {}) {
        const normalizedText = normalizeWhitespace(text);
        const chunkSize = Math.max(140, Number(options.chunkSize || 360));
        const overlap = Math.max(20, Number(options.overlap || 70));
        const lines = normalizedText.split('\n').filter(Boolean);
        const chunks = [];

        if (!normalizedText) {
            return chunks;
        }

        let current = '';
        let chunkIndex = 0;
        lines.forEach(function (line) {
            const nextValue = current ? `${current}\n${line}` : line;
            if (nextValue.length <= chunkSize || current.length === 0) {
                current = nextValue;
                return;
            }

            chunks.push({
                chunkIndex: chunkIndex,
                chunkText: current,
                sourcePage: sourcePageForText(current)
            });
            chunkIndex += 1;
            current = `${current.slice(Math.max(0, current.length - overlap))}\n${line}`.trim();
        });

        if (current) {
            chunks.push({
                chunkIndex: chunkIndex,
                chunkText: current,
                sourcePage: sourcePageForText(current)
            });
        }

        return chunks;
    }

    function sourcePageForText(text) {
        const match = String(text || '').match(/\bpage\s+(\d+)\b/i);
        if (!match) {
            return null;
        }

        return Number(match[1]);
    }

    function extractLabFactsFromText(text) {
        const lines = normalizeWhitespace(text).split('\n');
        const facts = [];
        const abnormal = [];
        const missing = [];

        lines.forEach(function (line) {
            const normalizedLine = normalizeWhitespace(line);
            if (!normalizedLine) {
                return;
            }

            if (/^missing[:]?/i.test(normalizedLine) || /^-\s+/i.test(normalizedLine)) {
                missing.push(normalizedLine.replace(/^-\s*/, ''));
                return;
            }

            if (!/:/.test(normalizedLine)) {
                return;
            }

            const parts = normalizedLine.split(':');
            const label = normalizeWhitespace(parts.shift());
            const value = normalizeWhitespace(parts.join(':'));
            if (!label || !value) {
                return;
            }

            const record = {
                label: label,
                value: value,
                abnormal: /\b(high|low|abnormal|critical|attention)\b/i.test(value)
            };
            facts.push(record);
            if (record.abnormal) {
                abnormal.push(`${label}: ${value}`);
            }
        });

        return {
            facts: facts,
            abnormal: abnormal,
            missing: missing
        };
    }

    function buildAttachmentDescriptor(fileLike, options = {}) {
        if (options.useSeededDemo) {
            return {
                kind: 'seeded_demo',
                fileName: SEEDED_FILE_NAME,
                displayLabel: `Attached: ${SEEDED_FILE_NAME}`,
                mimeType: 'application/pdf'
            };
        }

        return {
            kind: 'uploaded_file',
            fileName: String(fileLike && fileLike.name ? fileLike.name : 'attached-lab-report.pdf'),
            displayLabel: `Attached: ${String(fileLike && fileLike.name ? fileLike.name : 'attached-lab-report.pdf')}`,
            mimeType: String(fileLike && (fileLike.type || fileLike.mimeType) ? (fileLike.type || fileLike.mimeType) : 'application/pdf')
        };
    }

    return {
        TOOL_NAME: TOOL_NAME,
        ACCEPT_ATTRIBUTE: ACCEPT_ATTRIBUTE,
        SEEDED_FILE_NAME: SEEDED_FILE_NAME,
        REVIEW_NOTICE: REVIEW_NOTICE,
        SEEDED_TEXT: SEEDED_TEXT,
        isPdfLike: isPdfLike,
        normalizeWhitespace: normalizeWhitespace,
        extractPrintableTextFromPdfBuffer: extractPrintableTextFromPdfBuffer,
        detectPromptInjectionText: detectPromptInjectionText,
        buildSeededFallbackDocument: buildSeededFallbackDocument,
        extractTextOrSeedFallback: extractTextOrSeedFallback,
        chunkLabPdfText: chunkLabPdfText,
        extractLabFactsFromText: extractLabFactsFromText,
        buildAttachmentDescriptor: buildAttachmentDescriptor
    };
}));
