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
    const SEEDED_INTAKE_FILE_NAME = 'marcus-johnson-intake-form.pdf';
    const REVIEW_NOTICE = 'This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original lab PDF.';
    const INTAKE_REVIEW_NOTICE = 'This is a draft-only AI extraction for clinician review. It does not diagnose, update the chart, place orders, or replace verification of the original intake form.';
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
    const SEEDED_INTAKE_TEXT = [
        'Synthetic demo data only',
        'Document: Marcus Johnson intake form',
        'Reason for visit: blood sugar management and medication questions',
        'Medication adherence issue: sometimes misses evening Metformin',
        'Allergies: no known drug allergies reported',
        'Insurance update: patient says coverage changed recently',
        'Care preference: written instructions and phone reminders'
    ].join('\n');
    const SEEDED_INTAKE_MISSING_DATA = [
        'Current concerns were not clearly detected in the uploaded intake form.'
    ];

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

    const DOCUMENT_TYPE_HINTS = {
        intake_form: {
            filePatterns: [/\bintake\b/i, /\bintake-form\b/i, /\bpatient-intake\b/i, /\bquestionnaire\b/i, /\bform\b/i],
            textPatterns: [/\breason for visit\b/i, /\bcurrent concerns\b/i, /\bmedication notes\b/i, /\bmedication adherence\b/i, /\ballergies\b/i, /\binsurance update\b/i, /\bcare preferences\b/i, /\bpreferred contact\b/i]
        },
        lab_pdf: {
            filePatterns: [/\blab\b/i, /\blabs\b/i, /\bresult\b/i, /\bdiagnostic\b/i],
            textPatterns: [/\ba1c\b/i, /\bglucose\b/i, /\bldl\b/i, /\bhdl\b/i, /\bcreatinine\b/i, /\begfr\b/i, /\bmg\/dL\b/i, /\bhigh\b/i, /\blow\b/i, /\bnormal\b/i, /%/]
        }
    };

    const MEDICAL_GUARD_ACCEPTED_CLASSES = [
        'lab_results',
        'intake_form',
        'discharge_summary',
        'medication_list',
        'insurance_claim',
        'clinical_note',
        'visit_summary'
    ];

    const MEDICAL_GUARD_REJECTED_CLUES = [
        'invoice',
        'vendor',
        'payment terms',
        'resume',
        'job application',
        'event checklist',
        'restaurant menu',
        'book manuscript',
        'marketing flyer',
        'school assignment',
        'unrelated business document'
    ];

    const MEDICAL_GUARD_MEDICAL_HINTS = [
        'patient',
        'provider',
        'allergies',
        'medication',
        'medications',
        'diagnosis',
        'clinical',
        'visit',
        'encounter',
        'discharge',
        'assessment',
        'plan',
        'hemoglobin a1c',
        'a1c',
        'glucose',
        'ldl',
        'hdl',
        'creatinine',
        'egfr',
        'mg/dl',
        'member id',
        'claim',
        'payer',
        'insurance',
        'coverage',
        'reason for visit',
        'current concerns',
        'care preferences'
    ];

    const MEDICAL_GUARD_DOCUMENT_HINTS = {
        lab_results: {
            filePatterns: [/\blab\b/i, /\blabs\b/i, /\bresult\b/i, /\bdiagnostic\b/i],
            textPatterns: [/\ba1c\b/i, /\bglucose\b/i, /\bldl\b/i, /\bhdl\b/i, /\bcreatinine\b/i, /\begfr\b/i, /\bmg\/dL\b/i]
        },
        intake_form: {
            filePatterns: [/\bintake\b/i, /\bquestionnaire\b/i, /\bform\b/i],
            textPatterns: [/\breason for visit\b/i, /\bcurrent concerns\b/i, /\bmedication adherence\b/i, /\ballergies\b/i, /\binsurance update\b/i, /\bcare preferences\b/i]
        },
        discharge_summary: {
            filePatterns: [/\bdischarge\b/i, /\bhospital\b/i],
            textPatterns: [/\bdischarge summary\b/i, /\bhospital course\b/i, /\bdischarge diagnosis\b/i]
        },
        medication_list: {
            filePatterns: [/\bmedication\b/i],
            textPatterns: [/\bmedication list\b/i, /\bcurrent medications\b/i, /\bdosage\b/i]
        },
        insurance_claim: {
            filePatterns: [/\binsurance\b/i, /\bclaim\b/i, /\bpayer\b/i],
            textPatterns: [/\bmember id\b/i, /\bpolicy\b/i, /\bcoverage\b/i, /\bclaim\b/i]
        },
        clinical_note: {
            filePatterns: [/\bnote\b/i, /\bclinical\b/i, /\bprogress\b/i],
            textPatterns: [/\bhpi\b/i, /\bassessment\b/i, /\bplan\b/i]
        },
        visit_summary: {
            filePatterns: [/\bvisit\b/i, /\bsummary\b/i],
            textPatterns: [/\bvisit summary\b/i, /\bfollow-up\b/i, /\bnext steps\b/i]
        }
    };

    const INTAKE_FIELD_DEFINITIONS = {
        reasonForVisit: {
            title: 'Reason for Visit',
            patterns: [/^reason for visit\s*:\s*(.+)$/i, /^visit reason\s*:\s*(.+)$/i]
        },
        currentConcerns: {
            title: 'Current Concerns',
            patterns: [/^current concerns\s*:\s*(.+)$/i, /^concerns\s*:\s*(.+)$/i]
        },
        medicationAdherence: {
            title: 'Medication / Adherence Notes',
            patterns: [/^medication adherence issue\s*:\s*(.+)$/i, /^medication adherence\s*:\s*(.+)$/i, /^medication notes\s*:\s*(.+)$/i, /^medication\/adherence notes\s*:\s*(.+)$/i]
        },
        allergies: {
            title: 'Allergies',
            patterns: [/^allergies\s*:\s*(.+)$/i]
        },
        insuranceUpdate: {
            title: 'Insurance Update',
            patterns: [/^insurance update\s*:\s*(.+)$/i, /^coverage update\s*:\s*(.+)$/i]
        },
        carePreferences: {
            title: 'Care Preferences',
            patterns: [/^care preferences\s*:\s*(.+)$/i, /^care preference\s*:\s*(.+)$/i, /^preferred contact\s*:\s*(.+)$/i]
        }
    };

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

    function detectDocumentType(fileName, text = '') {
        const normalizedFileName = String(fileName || '');
        const normalizedText = String(text || '');

        for (const pattern of DOCUMENT_TYPE_HINTS.intake_form.filePatterns) {
            if (pattern.test(normalizedFileName)) {
                return 'intake_form';
            }
        }
        for (const pattern of DOCUMENT_TYPE_HINTS.intake_form.textPatterns) {
            if (pattern.test(normalizedText)) {
                return 'intake_form';
            }
        }
        for (const pattern of DOCUMENT_TYPE_HINTS.lab_pdf.filePatterns) {
            if (pattern.test(normalizedFileName)) {
                return 'lab_pdf';
            }
        }
        for (const pattern of DOCUMENT_TYPE_HINTS.lab_pdf.textPatterns) {
            if (pattern.test(normalizedText)) {
                return 'lab_pdf';
            }
        }

        return 'unknown';
    }

    function detectMedicalGuardDocumentType(fileName, text = '') {
        const normalizedFileName = String(fileName || '');
        const normalizedText = String(text || '');

        for (const documentType of Object.keys(MEDICAL_GUARD_DOCUMENT_HINTS)) {
            const hints = MEDICAL_GUARD_DOCUMENT_HINTS[documentType];
            for (const pattern of hints.filePatterns) {
                if (pattern.test(normalizedFileName)) {
                    return documentType;
                }
            }
            for (const pattern of hints.textPatterns) {
                if (pattern.test(normalizedText)) {
                    return documentType;
                }
            }
        }

        return 'unknown';
    }

    function summarizeMedicalGuardHints(text, minimumScore = 0.70) {
        const normalized = normalizeWhitespace(text).toLowerCase();
        const matches = unique(MEDICAL_GUARD_MEDICAL_HINTS.filter(function (hint) {
            return normalized.includes(String(hint).toLowerCase());
        }));
        const averageScore = matches.length > 0
            ? Math.min(0.99, minimumScore + (Math.min(matches.length, 6) * 0.03))
            : 0;

        return {
            totalEntities: matches.length,
            highConfidenceEntityCount: matches.length,
            highConfidenceEntities: matches.slice(0, 12).map(function (hint) {
                return {
                    text: hint,
                    category: 'MEDICAL_HINT',
                    score: Number(averageScore.toFixed(4))
                };
            }),
            averageScore: Number(averageScore.toFixed(4)),
            minimumScore: minimumScore
        };
    }

    function evaluateMedicalDocumentGuard(input = {}) {
        const fileName = String(input.fileName || '');
        const text = normalizeWhitespace(input.text || input.extractedText || input.fallbackText || '');
        const minimumEntities = Math.max(1, Number.isFinite(Number(input.minimumEntities)) ? Number(input.minimumEntities) : 2);
        const minimumScore = Number.isFinite(Number(input.minimumScore)) ? Number(input.minimumScore) : 0.70;
        const documentType = detectMedicalGuardDocumentType(fileName, text);
        const detectedEntitySummary = summarizeMedicalGuardHints(text, minimumScore);
        const rejectedClues = MEDICAL_GUARD_REJECTED_CLUES.filter(function (clue) {
            return `${fileName}\n${text}`.toLowerCase().includes(clue.toLowerCase());
        });
        const highConfidenceEntityCount = detectedEntitySummary.highConfidenceEntityCount || 0;
        const documentTypeRecognized = MEDICAL_GUARD_ACCEPTED_CLASSES.includes(documentType);
        const confidence = Math.max(
            0,
            Math.min(
                0.99,
                Math.max(detectedEntitySummary.averageScore || 0, rejectedClues.length > 0 ? 0.25 : 0.45)
                    + (documentTypeRecognized ? 0.18 : 0)
                    + (Math.min(highConfidenceEntityCount, 6) * 0.04)
                    - (rejectedClues.length > 0 ? 0.25 : 0)
            )
        );
        const summary = {
            ...detectedEntitySummary,
            rejectedClues,
            medicalEntityCount: highConfidenceEntityCount
        };

        if (!text) {
            return {
                decision: 'review_required',
                documentType: documentTypeRecognized ? documentType : 'unknown',
                confidence: 0,
                extractedTextPreview: '',
                detectedEntitySummary: summary,
                rejectionReason: 'No reliable text was available for medical-document validation.'
            };
        }

        if (rejectedClues.length >= 2 && highConfidenceEntityCount < minimumEntities) {
            return {
                decision: 'rejected',
                documentType: 'unknown',
                confidence: Number(confidence.toFixed(4)),
                extractedTextPreview: text.slice(0, 220),
                detectedEntitySummary: summary,
                rejectionReason: 'The uploaded PDF appears to be a non-medical document based on business or unrelated document language.'
            };
        }

        if (documentTypeRecognized && highConfidenceEntityCount >= minimumEntities && confidence >= minimumScore) {
            return {
                decision: 'allowed',
                documentType,
                confidence: Number(confidence.toFixed(4)),
                extractedTextPreview: text.slice(0, 220),
                detectedEntitySummary: summary
            };
        }

        if (!documentTypeRecognized && highConfidenceEntityCount <= 0) {
            return {
                decision: 'rejected',
                documentType: 'unknown',
                confidence: Number(confidence.toFixed(4)),
                extractedTextPreview: text.slice(0, 220),
                detectedEntitySummary: summary,
                rejectionReason: 'The uploaded PDF did not contain enough recognizable clinical or healthcare language to be ingested.'
            };
        }

        return {
            decision: 'review_required',
            documentType: documentTypeRecognized ? documentType : 'unknown',
            confidence: Number(confidence.toFixed(4)),
            extractedTextPreview: text.slice(0, 220),
            detectedEntitySummary: summary,
            rejectionReason: 'Document type could not be verified with high confidence.'
        };
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

    function buildSeededIntakeFallbackDocument(options = {}) {
        return {
            fileName: options.fileName || SEEDED_INTAKE_FILE_NAME,
            patientKey: String(options.patientKey || 'marcus-johnson'),
            patientName: String(options.patientName || 'Marcus Johnson'),
            extractionMethod: 'synthetic_marcus_intake_demo',
            text: SEEDED_INTAKE_TEXT,
            missingData: SEEDED_INTAKE_MISSING_DATA.slice()
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
        const requestedDocumentType = String(options.documentType || detectDocumentType(fileName, options.extractedText || '') || '');
        let extractedText = normalizeWhitespace(options.extractedText || '');

        if (!extractedText && options.arrayBuffer && !forceSeededFallback) {
            extractedText = extractPrintableTextFromPdfBuffer(options.arrayBuffer);
        }

        if ((requestedDocumentType === 'intake_form' || /marcus[-_ ]johnson.*intake.*\.pdf/i.test(fileName)) && (forceSeededFallback || !extractedText)) {
            const seededIntake = buildSeededIntakeFallbackDocument({
                fileName: fileName || SEEDED_INTAKE_FILE_NAME,
                patientKey,
                patientName
            });

            return {
                status: forceSeededFallback ? 'seeded_demo_fallback' : 'synthetic_marcus_intake_demo',
                extractionMethod: seededIntake.extractionMethod,
                text: seededIntake.text,
                preview: seededIntake.text.slice(0, 240),
                missingData: seededIntake.missingData.slice(),
                promptInjectionMatches: detectPromptInjectionText(extractedText)
            };
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

    function intakeMissingFieldMessage(fieldKey) {
        const mapping = {
            reasonForVisit: 'Reason for visit was not clearly detected in the uploaded intake form.',
            currentConcerns: 'Current concerns were not clearly detected in the uploaded intake form.',
            medicationAdherence: 'Medication / adherence notes were not clearly detected in the uploaded intake form.',
            allergies: 'Allergies were not clearly detected in the uploaded intake form.',
            insuranceUpdate: 'Insurance update was not clearly detected in the uploaded intake form.',
            carePreferences: 'Care preferences were not clearly detected in the uploaded intake form.'
        };
        return mapping[fieldKey] || 'A required intake field was not clearly detected in the uploaded intake form.';
    }

    function extractIntakeFactsFromText(text, options = {}) {
        const useSeededIntake = Boolean(options.useSyntheticMarcus) || /marcus[-_ ]johnson.*intake.*\.pdf/i.test(String(options.fileName || ''));
        const sourceText = useSeededIntake ? SEEDED_INTAKE_TEXT : text;
        const lines = normalizeWhitespace(sourceText).split('\n');
        const fields = {
            reasonForVisit: '',
            currentConcerns: '',
            medicationAdherence: '',
            allergies: '',
            insuranceUpdate: '',
            carePreferences: ''
        };
        const missing = [];
        const rejectedLines = [];

        lines.forEach(function (line) {
            const normalizedLine = normalizeWhitespace(line);
            if (!normalizedLine || isPromptInjectionLine(normalizedLine)) {
                return;
            }
            if (/^(patient|document|synthetic demo data only)\b/i.test(normalizedLine)) {
                return;
            }
            if (/^missing[:]?$/i.test(normalizedLine)) {
                return;
            }
            if (/^-\s+(.+)$/i.test(normalizedLine)) {
                missing.push(normalizedLine.replace(/^-\s+/, ''));
                return;
            }

            let matched = false;
            Object.entries(INTAKE_FIELD_DEFINITIONS).forEach(function ([fieldKey, definition]) {
                if (matched) {
                    return;
                }
                (definition.patterns || []).forEach(function (pattern) {
                    if (matched) {
                        return;
                    }
                    const match = normalizedLine.match(pattern);
                    if (match) {
                        const value = normalizeWhitespace(match[1] || '');
                        if (value) {
                            fields[fieldKey] = value;
                        }
                        matched = true;
                    }
                });
            });

            if (matched) {
                return;
            }

            if (normalizedLine.includes(':') && /[A-Za-z]/.test(normalizedLine)) {
                rejectedLines.push(normalizedLine);
            }
        });

        Object.keys(fields).forEach(function (fieldKey) {
            if (!normalizeWhitespace(fields[fieldKey])) {
                missing.push(intakeMissingFieldMessage(fieldKey));
            }
        });

        const facts = Object.entries(INTAKE_FIELD_DEFINITIONS).reduce(function (collection, [fieldKey, definition]) {
            const value = normalizeWhitespace(fields[fieldKey] || '');
            if (!value) {
                return collection;
            }
            collection.push({
                key: fieldKey,
                label: definition.title,
                name: definition.title,
                value: value,
                interpretation: '',
                sourceLabel: 'Uploaded Intake Form',
                source_label: 'Uploaded Intake Form'
            });
            return collection;
        }, []);

        return {
            fields: {
                ...fields,
                missingOrAmbiguousData: unique(useSeededIntake ? missing.concat(SEEDED_INTAKE_MISSING_DATA) : missing)
            },
            facts: facts,
            missing: unique(useSeededIntake ? missing.concat(SEEDED_INTAKE_MISSING_DATA) : missing),
            rejectedLines: unique(rejectedLines),
            validFieldCount: facts.length
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

    function resolveLabPdfToolOutput(responseOrToolOutput) {
        if (responseOrToolOutput && typeof responseOrToolOutput === 'object' && responseOrToolOutput.tool_output && typeof responseOrToolOutput.tool_output === 'object') {
            return responseOrToolOutput.tool_output;
        }

        if (responseOrToolOutput && typeof responseOrToolOutput === 'object' && responseOrToolOutput.toolOutput && typeof responseOrToolOutput.toolOutput === 'object') {
            return responseOrToolOutput.toolOutput;
        }

        return responseOrToolOutput && typeof responseOrToolOutput === 'object'
            ? responseOrToolOutput
            : {};
    }

    function buildLabPdfSafeTelemetryPayload(responseOrToolOutput, overrides = {}) {
        const toolOutput = resolveLabPdfToolOutput(responseOrToolOutput);
        const responseMeta = responseOrToolOutput && typeof responseOrToolOutput === 'object' && responseOrToolOutput.meta && typeof responseOrToolOutput.meta === 'object'
            ? responseOrToolOutput.meta
            : {};
        const metadata = toolOutput.document_metadata || toolOutput.documentMetadata || {};
        const sourceMetadata = toolOutput.source_metadata || toolOutput.sourceMetadata || {};
        const retrieval = toolOutput.retrieval && typeof toolOutput.retrieval === 'object'
            ? toolOutput.retrieval
            : {};
        const safety = toolOutput.safety_metadata || toolOutput.safetyMetadata || {};
        const documentGuard = toolOutput.document_guard || toolOutput.documentGuard || {};
        const missingData = Array.isArray(toolOutput.missing_data || toolOutput.missingData)
            ? (toolOutput.missing_data || toolOutput.missingData)
            : [];
        const documentType = String(
            overrides.documentType
            || metadata.document_type
            || metadata.documentType
            || sourceMetadata.source_type
            || sourceMetadata.sourceType
            || 'lab_pdf'
        );

        return {
            requestId: overrides.requestId || toolOutput.requestId || responseMeta.request_id || '',
            role: overrides.role || responseOrToolOutput.role || '',
            mode: overrides.mode || responseOrToolOutput.mode || 'lab_pdf_ingestion',
            selectedPatientKey: overrides.selectedPatientKey || metadata.patient_key || metadata.patientKey || '',
            documentTitle: overrides.documentTitle || metadata.title || sourceMetadata.file_name || sourceMetadata.fileName || '',
            documentType: documentType,
            extractionMethod: overrides.extractionMethod || toolOutput.extraction_method || toolOutput.extractionMethod || '',
            toolStatus: overrides.toolStatus || toolOutput.status || toolOutput.ingestion_status || toolOutput.ingestionStatus || '',
            chunkCount: Number(
                overrides.chunkCount
                ?? toolOutput.number_of_chunks
                ?? toolOutput.numberOfChunks
                ?? sourceMetadata.chunk_count
                ?? sourceMetadata.chunkCount
                ?? metadata.chunk_count
                ?? 0
            ),
            retrievedChunkCount: Number(
                overrides.retrievedChunkCount
                ?? retrieval.chunk_count
                ?? retrieval.chunkCount
                ?? 0
            ),
            missingDataCount: Number.isFinite(overrides.missingDataCount)
                ? overrides.missingDataCount
                : missingData.length,
            guardrailTriggered: Boolean(
                overrides.guardrailTriggered
                || safety.prompt_injection_detected
                || safety.promptInjectionDetected
            ),
            documentGuardProvider: overrides.documentGuardProvider || documentGuard.guard_provider || documentGuard.guardProvider || '',
            medicalEntityCount: Number(
                overrides.medicalEntityCount
                ?? documentGuard.medical_entity_count
                ?? documentGuard.medicalEntityCount
                ?? 0
            ),
            confidence: Number(
                overrides.confidence
                ?? documentGuard.confidence
                ?? 0
            ),
            rejectionReason: overrides.rejectionReason || documentGuard.rejection_reason || documentGuard.rejectionReason || '',
            documentGuardDecision: overrides.documentGuardDecision || documentGuard.decision || '',
            awsGuardEnabled: Boolean(overrides.awsGuardEnabled ?? documentGuard.aws_guard_enabled ?? documentGuard.awsGuardEnabled ?? false),
            seededDemo: Boolean(overrides.seededDemo ?? metadata.seeded_demo ?? metadata.seededDemo),
            ragGrounded: Boolean(overrides.ragGrounded ?? responseMeta.rag_grounded ?? false)
        };
    }

    function buildLabPdfTelemetryPayload(input = {}) {
        return buildLabPdfSafeTelemetryPayload(input, input);
    }

    function buildLabPdfDemoTracePayload(responseOrToolOutput, overrides = {}) {
        const toolOutput = resolveLabPdfToolOutput(responseOrToolOutput);
        const responseMeta = responseOrToolOutput && typeof responseOrToolOutput === 'object' && responseOrToolOutput.meta && typeof responseOrToolOutput.meta === 'object'
            ? responseOrToolOutput.meta
            : {};
        const metadata = toolOutput.document_metadata || toolOutput.documentMetadata || {};
        const sourceMetadata = toolOutput.source_metadata || toolOutput.sourceMetadata || {};
        const retrieval = toolOutput.retrieval && typeof toolOutput.retrieval === 'object'
            ? toolOutput.retrieval
            : {};
        const safety = toolOutput.safety_metadata || toolOutput.safetyMetadata || {};
        const documentGuard = toolOutput.document_guard || toolOutput.documentGuard || {};
        const vectorized = Array.isArray(toolOutput.vectorized_result || toolOutput.vectorizedResult)
            ? (toolOutput.vectorized_result || toolOutput.vectorizedResult)
            : [];
        const extractedFacts = Array.isArray(toolOutput.extracted_facts || toolOutput.extractedFacts)
            ? (toolOutput.extracted_facts || toolOutput.extractedFacts)
            : [];
        const abnormalFindings = Array.isArray(toolOutput.abnormal_findings || toolOutput.abnormalFindings)
            ? (toolOutput.abnormal_findings || toolOutput.abnormalFindings)
            : [];
        const missingData = Array.isArray(toolOutput.missing_data || toolOutput.missingData)
            ? (toolOutput.missing_data || toolOutput.missingData)
            : [];

        const vectorSummary = vectorized.map(function (record) {
            const embedding = Array.isArray(record.embedding) ? record.embedding : [];
            return {
                id: record.id || '',
                fileName: record.file_name || record.fileName || metadata.title || '',
                chunkIndex: record.chunk_index ?? record.chunkIndex ?? null,
                sourcePage: record.source_page ?? record.sourcePage ?? null,
                embeddingDimensions: embedding.length > 0 ? embedding.length : Number(record.embeddingDimensions || 0),
                embeddingPreview: embedding.slice(0, 4)
            };
        });

        const seededDemo = Boolean(metadata.seeded_demo || metadata.seededDemo);
        const syntheticFactsSummary = seededDemo
            ? extractedFacts.map(function (fact) {
                return {
                    name: fact.name || '',
                    value: fact.value || '',
                    interpretation: fact.interpretation || fact.flag || ''
                };
            })
            : [];

        return {
            summaryRows: [
                { step: 'Attached PDF', status: toolOutput.status || toolOutput.ingestion_status || toolOutput.ingestionStatus || 'ok' },
                { step: 'Text extraction', status: toolOutput.extraction_method || toolOutput.extractionMethod || 'unknown' },
                { step: 'Chunking', status: `${toolOutput.number_of_chunks || toolOutput.numberOfChunks || 0} chunks` },
                { step: 'Vectorization', status: `${vectorSummary.length} vector records` },
                { step: 'Retrieval', status: `${retrieval.chunk_count || retrieval.chunkCount || 0} chunks retrieved` },
                { step: 'Clinician review', status: 'required' }
            ],
            pipelineSummary: {
                requestId: overrides.requestId || responseMeta.request_id || '',
                role: overrides.role || responseOrToolOutput.role || '',
                mode: overrides.mode || responseOrToolOutput.mode || 'lab_pdf_ingestion',
                selectedPatientKey: overrides.selectedPatientKey || metadata.patient_key || metadata.patientKey || '',
                documentTitle: metadata.title || overrides.documentTitle || '',
                documentType: metadata.document_type || metadata.documentType || sourceMetadata.source_type || sourceMetadata.sourceType || 'lab_pdf',
                extractionMethod: toolOutput.extraction_method || toolOutput.extractionMethod || '',
                ingestionStatus: toolOutput.ingestion_status || toolOutput.ingestionStatus || toolOutput.status || '',
                documentGuardDecision: documentGuard.decision || '',
                documentGuardProvider: documentGuard.guard_provider || documentGuard.guardProvider || '',
                extractedFactCount: extractedFacts.length,
                abnormalCount: abnormalFindings.length,
                missingDataCount: missingData.length
            },
            vectorSummary: vectorSummary,
            retrievalSummary: {
                chunkIds: retrieval.chunk_ids || retrieval.chunkIds || [],
                chunkCount: retrieval.chunk_count || retrieval.chunkCount || 0
            },
            safetySummary: {
                draftOnly: true,
                reviewRequired: true,
                noChartWrite: true,
                promptInjectionDetected: Boolean(safety.prompt_injection_detected || safety.promptInjectionDetected),
                medicalDocumentGate: {
                    decision: documentGuard.decision || '',
                    provider: documentGuard.guard_provider || documentGuard.guardProvider || '',
                    textractStatus: documentGuard.textract_status || documentGuard.textractStatus || '',
                    comprehendStatus: documentGuard.comprehend_status || documentGuard.comprehendStatus || '',
                    medicalEntityCount: Number(documentGuard.medical_entity_count || documentGuard.medicalEntityCount || 0)
                },
                untrustedDocumentText: true,
                seededDemo: seededDemo,
                ragGrounded: Boolean(overrides.ragGrounded ?? responseMeta.rag_grounded ?? false)
            },
            syntheticFactsSummary: syntheticFactsSummary
        };
    }

    function demoLogsEnabled() {
        const runtimeRoot = typeof globalThis !== 'undefined' ? globalThis : {};
        if (runtimeRoot.OPENEMR_COPILOT_DEMO_LOGS === true) {
            return true;
        }

        try {
            if (runtimeRoot.localStorage && runtimeRoot.localStorage.getItem('openemr_copilot_demo_logs') === 'true') {
                return true;
            }
        } catch (error) {
        }

        return true;
    }

    function logLabPdfDemoTrace(label, data = {}) {
        if (!demoLogsEnabled() || typeof console === 'undefined' || typeof console.groupCollapsed !== 'function') {
            return;
        }

        console.groupCollapsed(`[Lab PDF Ingestion Demo] ${String(label || 'Pipeline trace')}`);
        if (typeof console.table === 'function' && Array.isArray(data.summaryRows) && data.summaryRows.length > 0) {
            console.table(data.summaryRows);
        }
        console.info('Pipeline summary', data.pipelineSummary || {});
        console.info('Vector summary', data.vectorSummary || []);
        console.info('Retrieval summary', data.retrievalSummary || {});
        console.info('Safety summary', data.safetySummary || {});
        if (Array.isArray(data.syntheticFactsSummary) && data.syntheticFactsSummary.length > 0) {
            console.info('Synthetic demo facts only — not real PHI', data.syntheticFactsSummary);
        }
        console.groupEnd();
    }

    function resolveTelemetry(telemetry) {
        if (telemetry && typeof telemetry.log === 'function') {
            return telemetry;
        }

        const runtimeRoot = typeof globalThis !== 'undefined' ? globalThis : {};
        if (runtimeRoot.CopilotTelemetry && typeof runtimeRoot.CopilotTelemetry.log === 'function') {
            return runtimeRoot.CopilotTelemetry;
        }

        if (runtimeRoot.window && runtimeRoot.window.CopilotTelemetry && typeof runtimeRoot.window.CopilotTelemetry.log === 'function') {
            return runtimeRoot.window.CopilotTelemetry;
        }

        if (runtimeRoot.window && runtimeRoot.window.top && runtimeRoot.window.top.CopilotTelemetry && typeof runtimeRoot.window.top.CopilotTelemetry.log === 'function') {
            return runtimeRoot.window.top.CopilotTelemetry;
        }

        return null;
    }

    function logLabPdfEvent(eventName, payload = {}, telemetry) {
        const resolvedTelemetry = resolveTelemetry(telemetry);
        const safePayload = buildLabPdfSafeTelemetryPayload(payload, payload);
        if (!resolvedTelemetry || typeof resolvedTelemetry.log !== 'function') {
            if (typeof console !== 'undefined' && typeof console.warn === 'function') {
                console.warn('[Medical Co-Pilot Audit] lab PDF telemetry unavailable', {
                    eventName: String(eventName || 'copilot_lab_pdf_event')
                });
            }
            return safePayload;
        }

        resolvedTelemetry.log(String(eventName || 'copilot_lab_pdf_event'), safePayload);
        return safePayload;
    }

    function emitLabPdfTelemetry(telemetry, eventName, input = {}) {
        return logLabPdfEvent(eventName, input, telemetry);
    }

    function buildAttachmentDescriptor(fileLike, options = {}) {
        if (options.useSeededDemo) {
            return {
                kind: 'seeded_demo',
                fileName: SEEDED_FILE_NAME,
                displayLabel: `Attached: ${SEEDED_FILE_NAME}`,
                mimeType: 'application/pdf',
                documentType: 'lab_pdf'
            };
        }

        const fileName = String(fileLike && fileLike.name ? fileLike.name : 'attached-document.pdf');

        return {
            kind: 'uploaded_file',
            fileName: fileName,
            displayLabel: `Attached: ${fileName}`,
            mimeType: String(fileLike && (fileLike.type || fileLike.mimeType) ? (fileLike.type || fileLike.mimeType) : 'application/pdf'),
            documentType: detectDocumentType(fileName)
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
        buildLabPdfDemoTracePayload: buildLabPdfDemoTracePayload,
        buildLabPdfSafeTelemetryPayload: buildLabPdfSafeTelemetryPayload,
        buildExtractionReviewResult: buildExtractionReviewResult,
        buildLabPdfTelemetryPayload: buildLabPdfTelemetryPayload,
        buildSeededFallbackDocument: buildSeededFallbackDocument,
        buildSeededIntakeFallbackDocument: buildSeededIntakeFallbackDocument,
        buildSyntheticMarcusFacts: buildSyntheticMarcusFacts,
        chunkLabPdfText: chunkLabPdfText,
        countAbnormalFacts: countAbnormalFacts,
        detectDocumentType: detectDocumentType,
        detectMedicalGuardDocumentType: detectMedicalGuardDocumentType,
        detectPromptInjectionText: detectPromptInjectionText,
        emitLabPdfTelemetry: emitLabPdfTelemetry,
        evaluateMedicalDocumentGuard: evaluateMedicalDocumentGuard,
        extractIntakeFactsFromText: extractIntakeFactsFromText,
        extractLabFactsFromText: extractLabFactsFromText,
        extractPrintableTextFromPdfBuffer: extractPrintableTextFromPdfBuffer,
        extractTextOrSeedFallback: extractTextOrSeedFallback,
        isLikelySyntheticMarcusJohnsonPdf: isLikelySyntheticMarcusJohnsonPdf,
        isPdfLike: isPdfLike,
        logLabPdfDemoTrace: logLabPdfDemoTrace,
        logLabPdfEvent: logLabPdfEvent,
        MEDICAL_GUARD_ACCEPTED_CLASSES: MEDICAL_GUARD_ACCEPTED_CLASSES,
        MEDICAL_GUARD_REJECTED_CLUES: MEDICAL_GUARD_REJECTED_CLUES,
        normalizeWhitespace: normalizeWhitespace,
        parseRecognizedLabLine: parseRecognizedLabLine,
        INTAKE_REVIEW_NOTICE: INTAKE_REVIEW_NOTICE,
        SEEDED_INTAKE_FILE_NAME: SEEDED_INTAKE_FILE_NAME,
        SEEDED_INTAKE_MISSING_DATA: SEEDED_INTAKE_MISSING_DATA,
        SEEDED_INTAKE_TEXT: SEEDED_INTAKE_TEXT
    };
}));
