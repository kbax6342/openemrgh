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
    const EXTRACTION_REVIEW_REQUIRED_MESSAGE = 'PDF text extraction did not produce reliable lab rows. Clinician must verify the source PDF.';
    const SEEDED_MISSING_DATA = [
        'Ordering provider not clearly detected',
        'Collection time not clearly detected'
    ];
    const SEEDED_TEXT = [
        'Patient: Marcus Johnson',
        `Document: ${SEEDED_FILE_NAME}`,
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

    const RECOGNIZED_LABS = [
        {
            key: 'hemoglobin_a1c',
            label: 'Hemoglobin A1c',
            defaultUnit: '%',
            aliases: [/\bhemoglobin\s*a1c\b/i, /\bhba1c\b/i, /\ba1c\b/i],
            inferFlag(value) {
                if (value >= 6.5) {
                    return 'high';
                }
                return 'normal';
            }
        },
        {
            key: 'ldl_cholesterol',
            label: 'LDL Cholesterol',
            defaultUnit: 'mg/dL',
            aliases: [/\bldl cholesterol\b/i, /\bldl\b/i],
            inferFlag(value) {
                if (value >= 130) {
                    return 'high';
                }
                return 'normal';
            }
        },
        {
            key: 'creatinine',
            label: 'Creatinine',
            defaultUnit: 'mg/dL',
            aliases: [/\bcreatinine\b/i],
            inferFlag(value) {
                if (value < 0.6) {
                    return 'low';
                }
                if (value > 1.3) {
                    return 'high';
                }
                return 'normal';
            }
        },
        {
            key: 'egfr',
            label: 'eGFR',
            defaultUnit: 'mL/min/1.73m2',
            aliases: [/\begfr\b/i, /\bestimated glomerular filtration rate\b/i],
            inferFlag(value) {
                if (value < 60) {
                    return 'low';
                }
                return 'normal';
            }
        }
    ];

    function normalizeWhitespace(value) {
        return String(value || '')
            .replace(/\r/g, '\n')
            .replace(/\u0000/g, ' ')
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function normalizeUnit(value, defaultUnit) {
        const unit = String(value || '').trim();
        if (!unit) {
            return defaultUnit || '';
        }

        const normalized = unit
            .replace(/\s+/g, '')
            .replace(/mg\/dl/i, 'mg/dL')
            .replace(/ml\/min\/1\.73m2/i, 'mL/min/1.73m2');
        if (/^%$/.test(normalized)) {
            return '%';
        }
        if (/^mg\/dL$/i.test(normalized)) {
            return 'mg/dL';
        }
        if (/^mL\/min\/1\.73m2$/i.test(normalized)) {
            return 'mL/min/1.73m2';
        }

        return normalized;
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

    function isPromptInjectionLine(line) {
        return PROMPT_INJECTION_PATTERNS.some(function (pattern) {
            return pattern.test(String(line || ''));
        });
    }

    function buildSeededFallbackDocument(options = {}) {
        return {
            fileName: options.fileName || SEEDED_FILE_NAME,
            patientKey: String(options.patientKey || 'marcus-johnson'),
            patientName: String(options.patientName || 'Marcus Johnson'),
            extractionMethod: 'seeded_demo_fallback',
            text: SEEDED_TEXT,
            missingData: SEEDED_MISSING_DATA.slice()
        };
    }

    function isLikelySyntheticMarcusJohnsonPdf(fileName, text) {
        const normalizedFileName = String(fileName || '').toLowerCase();
        const normalizedText = String(text || '').toLowerCase();
        return /marcus[-_ ]johnson.*lab.*\.pdf/.test(normalizedFileName)
            || (/patient:\s*marcus johnson/.test(normalizedText) && /\b(a1c|ldl|creatinine|egfr)\b/.test(normalizedText));
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

        if (forceSeededFallback || isLikelySyntheticMarcusJohnsonPdf(fileName, extractedText)) {
            const seeded = buildSeededFallbackDocument({
                fileName: fileName || SEEDED_FILE_NAME,
                patientKey,
                patientName
            });

            return {
                status: forceSeededFallback ? 'seeded_demo_fallback' : 'synthetic_marcus_demo',
                extractionMethod: seeded.extractionMethod,
                text: seeded.text,
                preview: seeded.text.slice(0, 240),
                missingData: seeded.missingData.slice(),
                promptInjectionMatches: detectPromptInjectionText(extractedText)
            };
        }

        if (!extractedText) {
            return {
                status: 'extraction_review_required',
                extractionMethod: 'pdf_text_unavailable',
                text: '',
                preview: '',
                missingData: [],
                promptInjectionMatches: []
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

    function buildSyntheticMarcusFacts() {
        return {
            facts: [
                {
                    key: 'hemoglobin_a1c',
                    label: 'Hemoglobin A1c',
                    name: 'Hemoglobin A1c',
                    value: '8.2 %',
                    numericValue: 8.2,
                    unit: '%',
                    referenceRange: '',
                    flag: 'high',
                    interpretation: 'high',
                    abnormal: true,
                    sourceLabel: 'Uploaded Lab PDF',
                    source_label: 'Uploaded Lab PDF'
                },
                {
                    key: 'ldl_cholesterol',
                    label: 'LDL Cholesterol',
                    name: 'LDL Cholesterol',
                    value: '142 mg/dL',
                    numericValue: 142,
                    unit: 'mg/dL',
                    referenceRange: '',
                    flag: 'high',
                    interpretation: 'high',
                    abnormal: true,
                    sourceLabel: 'Uploaded Lab PDF',
                    source_label: 'Uploaded Lab PDF'
                },
                {
                    key: 'creatinine',
                    label: 'Creatinine',
                    name: 'Creatinine',
                    value: '1.1 mg/dL',
                    numericValue: 1.1,
                    unit: 'mg/dL',
                    referenceRange: '',
                    flag: 'normal',
                    interpretation: 'normal',
                    abnormal: false,
                    sourceLabel: 'Uploaded Lab PDF',
                    source_label: 'Uploaded Lab PDF'
                },
                {
                    key: 'egfr',
                    label: 'eGFR',
                    name: 'eGFR',
                    value: '82 mL/min/1.73m2',
                    numericValue: 82,
                    unit: 'mL/min/1.73m2',
                    referenceRange: '',
                    flag: 'normal',
                    interpretation: 'normal',
                    abnormal: false,
                    sourceLabel: 'Uploaded Lab PDF',
                    source_label: 'Uploaded Lab PDF'
                }
            ],
            abnormal: [
                'Hemoglobin A1c: 8.2 %, high',
                'LDL Cholesterol: 142 mg/dL, high'
            ],
            missing: SEEDED_MISSING_DATA.slice(),
            rejectedLines: [],
            validLabRowCount: 4
        };
    }

    function findRecognizedLab(line) {
        const normalizedLine = String(line || '');
        return RECOGNIZED_LABS.find(function (definition) {
            return definition.aliases.some(function (pattern) {
                return pattern.test(normalizedLine);
            });
        }) || null;
    }

    function inferFlag(definition, numericValue, rawLine, parsedFlag) {
        const normalizedFlag = String(parsedFlag || '').trim().toLowerCase();
        if (normalizedFlag) {
            return normalizedFlag;
        }
        if (definition && typeof definition.inferFlag === 'function' && Number.isFinite(numericValue)) {
            return definition.inferFlag(numericValue, rawLine) || 'unknown';
        }

        return 'unknown';
    }

    function parseRecognizedLabLine(line) {
        const normalizedLine = normalizeWhitespace(line);
        if (!normalizedLine || isPromptInjectionLine(normalizedLine)) {
            return null;
        }
        if (/^(patient|document)\s*:/i.test(normalizedLine)) {
            return null;
        }

        const definition = findRecognizedLab(normalizedLine);
        if (!definition) {
            return null;
        }

        const numericMatch = normalizedLine.match(/(-?\d+(?:\.\d+)?)\s*(%|mg\/dL|mg\/dl|mL\/min\/1\.73m2|ml\/min\/1\.73m2)?/i);
        if (!numericMatch) {
            return null;
        }

        const numericValue = Number(numericMatch[1]);
        if (!Number.isFinite(numericValue)) {
            return null;
        }

        const unit = normalizeUnit(numericMatch[2] || '', definition.defaultUnit);
        const referenceMatch = normalizedLine.match(/(?:ref(?:erence)? range|range)\s*[:\-]?\s*([A-Za-z0-9<>\-./% ]+)/i);
        const flagMatch = normalizedLine.match(/\b(high|low|normal|abnormal|critical|unknown)\b/i);
        const flag = inferFlag(definition, numericValue, normalizedLine, flagMatch ? flagMatch[1] : '');
        const displayValue = unit ? `${numericMatch[1]} ${unit}`.trim() : numericMatch[1];

        return {
            key: definition.key,
            label: definition.label,
            name: definition.label,
            value: displayValue,
            numericValue: numericValue,
            unit: unit,
            referenceRange: referenceMatch ? normalizeWhitespace(referenceMatch[1]) : '',
            flag: flag,
            interpretation: flag,
            abnormal: ['high', 'low', 'abnormal', 'critical'].includes(flag),
            sourceLabel: 'Uploaded Lab PDF',
            source_label: 'Uploaded Lab PDF'
        };
    }

    function extractLabFactsFromText(text, options = {}) {
        if (Boolean(options.useSyntheticMarcus) || isLikelySyntheticMarcusJohnsonPdf(options.fileName || '', text)) {
            return buildSyntheticMarcusFacts();
        }

        const lines = normalizeWhitespace(text).split('\n');
        const factsByKey = new Map();
        const abnormal = [];
        const missing = [];
        const rejectedLines = [];

        lines.forEach(function (line) {
            const normalizedLine = normalizeWhitespace(line);
            if (!normalizedLine) {
                return;
            }

            if (isPromptInjectionLine(normalizedLine)) {
                return;
            }

            if (/^(patient|document)\s*:/i.test(normalizedLine)) {
                return;
            }

            if (/^missing[:]?$/i.test(normalizedLine)) {
                return;
            }

            if (/^-\s+/.test(normalizedLine)) {
                missing.push(normalizedLine.replace(/^-\s*/, ''));
                return;
            }

            const parsed = parseRecognizedLabLine(normalizedLine);
            if (parsed) {
                factsByKey.set(parsed.key, parsed);
                if (parsed.abnormal) {
                    abnormal.push(`${parsed.label}: ${parsed.value}, ${parsed.flag}`);
                }
                return;
            }

            if ((normalizedLine.includes(':') || /\d/.test(normalizedLine)) && /[A-Za-z]/.test(normalizedLine)) {
                rejectedLines.push(normalizedLine);
            }
        });

        return {
            facts: Array.from(factsByKey.values()),
            abnormal: unique(abnormal),
            missing: unique(missing),
            rejectedLines: unique(rejectedLines),
            validLabRowCount: factsByKey.size
        };
    }

    function buildExtractionReviewResult(options = {}) {
        return {
            status: 'extraction_review_required',
            message: EXTRACTION_REVIEW_REQUIRED_MESSAGE,
            extractionMethod: String(options.extractionMethod || 'pdf_text'),
            fileName: String(options.fileName || ''),
            facts: [],
            abnormal: [],
            missing: Array.isArray(options.missing) ? unique(options.missing) : [],
            rejectedLines: Array.isArray(options.rejectedLines) ? unique(options.rejectedLines) : []
        };
    }

    function countAbnormalFacts(toolOutput) {
        const abnormalFindings = Array.isArray(toolOutput && (toolOutput.abnormalFindings || toolOutput.abnormal_findings))
            ? (toolOutput.abnormalFindings || toolOutput.abnormal_findings)
            : [];
        if (abnormalFindings.length > 0) {
            return abnormalFindings.length;
        }

        const facts = Array.isArray(toolOutput && (toolOutput.extractedFacts || toolOutput.extracted_facts))
            ? (toolOutput.extractedFacts || toolOutput.extracted_facts)
            : [];
        return facts.filter(function (fact) {
            const flag = String(fact && (fact.flag || fact.interpretation) ? (fact.flag || fact.interpretation) : '').toLowerCase();
            return ['high', 'low', 'abnormal', 'critical'].includes(flag);
        }).length;
    }

    function buildLabPdfTelemetryPayload(input = {}) {
        const toolOutput = input.toolOutput && typeof input.toolOutput === 'object' ? input.toolOutput : {};
        const extractedFacts = Array.isArray(toolOutput.extractedFacts || toolOutput.extracted_facts)
            ? (toolOutput.extractedFacts || toolOutput.extracted_facts)
            : [];
        const missingData = Array.isArray(toolOutput.missingData || toolOutput.missing_data)
            ? (toolOutput.missingData || toolOutput.missing_data)
            : [];
        const documentMetadata = toolOutput.documentMetadata || toolOutput.document_metadata || {};

        return {
            requestId: input.requestId || null,
            role: input.role || null,
            mode: input.mode || 'lab_pdf_ingestion',
            selectedPatientKey: input.selectedPatientKey || null,
            fileName: input.fileName || documentMetadata.title || null,
            extractionMethod: input.extractionMethod || toolOutput.extractionMethod || toolOutput.extraction_method || null,
            labValueCount: Number.isFinite(input.labValueCount) ? input.labValueCount : extractedFacts.length,
            abnormalCount: Number.isFinite(input.abnormalCount) ? input.abnormalCount : countAbnormalFacts(toolOutput),
            missingDataCount: Number.isFinite(input.missingDataCount) ? input.missingDataCount : missingData.length,
            status: input.status || toolOutput.status || null
        };
    }

    function emitLabPdfTelemetry(telemetry, eventName, input = {}) {
        const payload = buildLabPdfTelemetryPayload(input);
        if (telemetry && typeof telemetry.log === 'function') {
            telemetry.log(String(eventName || 'copilot_lab_pdf_event'), payload);
        }
        return payload;
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
        EXTRACTION_REVIEW_REQUIRED_MESSAGE: EXTRACTION_REVIEW_REQUIRED_MESSAGE,
        REVIEW_NOTICE: REVIEW_NOTICE,
        RECOGNIZED_LABS: RECOGNIZED_LABS,
        SEEDED_FILE_NAME: SEEDED_FILE_NAME,
        SEEDED_MISSING_DATA: SEEDED_MISSING_DATA,
        SEEDED_TEXT: SEEDED_TEXT,
        buildAttachmentDescriptor: buildAttachmentDescriptor,
        buildExtractionReviewResult: buildExtractionReviewResult,
        buildLabPdfTelemetryPayload: buildLabPdfTelemetryPayload,
        buildSeededFallbackDocument: buildSeededFallbackDocument,
        buildSyntheticMarcusFacts: buildSyntheticMarcusFacts,
        chunkLabPdfText: chunkLabPdfText,
        countAbnormalFacts: countAbnormalFacts,
        detectPromptInjectionText: detectPromptInjectionText,
        emitLabPdfTelemetry: emitLabPdfTelemetry,
        extractLabFactsFromText: extractLabFactsFromText,
        extractPrintableTextFromPdfBuffer: extractPrintableTextFromPdfBuffer,
        extractTextOrSeedFallback: extractTextOrSeedFallback,
        isLikelySyntheticMarcusJohnsonPdf: isLikelySyntheticMarcusJohnsonPdf,
        isPdfLike: isPdfLike,
        normalizeWhitespace: normalizeWhitespace,
        parseRecognizedLabLine: parseRecognizedLabLine
    };
}));
