#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const ingestion = require('./lab_pdf_ingestion.js');

const ACCEPTED_MEDICAL_DOCUMENT_CLASSES = [
    'lab_results',
    'intake_form',
    'discharge_summary',
    'medication_list',
    'insurance_claim',
    'clinical_note',
    'visit_summary'
];

const REJECTED_DOCUMENT_CLUES = [
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

const MEDICAL_ENTITY_HINTS = [
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
    'random glucose',
    'ldl',
    'hdl',
    'hemoglobin',
    'platelets',
    'bun',
    'sodium',
    'potassium',
    'total cholesterol',
    'triglycerides',
    'creatinine',
    'egfr',
    'wbc',
    'crp',
    'esr',
    'urine albumin/creatinine ratio',
    'reference range',
    'ordering provider',
    'clinical laboratory',
    'lab results',
    'specimen',
    'collected',
    'reported',
    'mg/dl',
    'mg/l',
    'g/dl',
    'mg/g',
    'k/ul',
    'mm/hr',
    'mmol/l',
    'blood pressure',
    'pulse',
    'member id',
    'claim',
    'payer',
    'insurance',
    'coverage',
    'reason for visit',
    'chief concern',
    'current concerns',
    'current medications',
    'family history',
    'recent symptoms',
    'care preferences',
    'clinical intake responses',
    'patient intake form',
    'preferred contact'
];

const DOCUMENT_CLASS_HINTS = {
    lab_results: {
        filePatterns: [/\blab\b/i, /\blabs\b/i, /\bresult\b/i, /\bdiagnostic\b/i],
        textPatterns: [
            /\blab results?\b/i,
            /\bclinical laboratory\b/i,
            /\bclinical laboratory services\b/i,
            /\bhemoglobin\s*a1c\b/i,
            /\ba1c\b/i,
            /\brandom glucose\b/i,
            /\bglucose\b/i,
            /\bldl\b/i,
            /\bhdl\b/i,
            /\bhemoglobin\b/i,
            /\bplatelets?\b/i,
            /\btotal cholesterol\b/i,
            /\btriglycerides?\b/i,
            /\bcreatinine\b/i,
            /\begfr\b/i,
            /\bbun\b/i,
            /\bsodium\b/i,
            /\bpotassium\b/i,
            /\bwbc\b/i,
            /\bcrp\b/i,
            /\besr\b/i,
            /\burine albumin\/creatinine ratio\b/i,
            /\breference range\b/i,
            /\bspecimen\b/i,
            /\bcollected\b/i,
            /\breported\b/i,
            /\bordering provider\b/i,
            /\bmg\/dL\b/i,
            /\bmg\/L\b/i,
            /\bg\/dL\b/i,
            /\bmmol\/L\b/i,
            /\bK\/uL\b/i,
            /\bmg\/g\b/i,
            /\bmm\/hr\b/i,
            /%/,
            /\bhigh\b/i,
            /\blow\b/i,
            /\bnormal\b/i,
            /\b[HL]\b/
        ]
    },
    intake_form: {
        filePatterns: [/\bintake\b/i, /\bquestionnaire\b/i, /\bform\b/i],
        textPatterns: [/\bpatient intake form\b/i, /\bclinical intake responses\b/i, /\breason for visit\b/i, /\bchief concern\b/i, /\bcurrent concerns\b/i, /\bcurrent medications\b/i, /\bmedication adherence\b/i, /\ballergies\b/i, /\bfamily history\b/i, /\brecent symptoms\b/i, /\binsurance update\b/i, /\bcare preferences\b/i, /\bconsent (?:confirmed|note)\b/i]
    },
    discharge_summary: {
        filePatterns: [/\bdischarge\b/i, /\bhospital\b/i],
        textPatterns: [/\bdischarge summary\b/i, /\bhospital course\b/i, /\bdischarge diagnosis\b/i, /\bdischarge medications\b/i]
    },
    medication_list: {
        filePatterns: [/\bmedication\b/i, /\bmed-list\b/i, /\bmed list\b/i],
        textPatterns: [/\bmedication list\b/i, /\bcurrent medications\b/i, /\bdosage\b/i, /\broute\b/i]
    },
    insurance_claim: {
        filePatterns: [/\binsurance\b/i, /\bclaim\b/i, /\bpayer\b/i],
        textPatterns: [/\bmember id\b/i, /\bpolicy\b/i, /\bcoverage\b/i, /\bclaim\b/i, /\binsurance\b/i, /\bsubscriber\b/i]
    },
    clinical_note: {
        filePatterns: [/\bnote\b/i, /\bprogress\b/i, /\bclinical\b/i],
        textPatterns: [/\bhpi\b/i, /\bassessment\b/i, /\bplan\b/i, /\bsubjective\b/i, /\bobjective\b/i]
    },
    visit_summary: {
        filePatterns: [/\bvisit\b/i, /\bsummary\b/i],
        textPatterns: [/\bvisit summary\b/i, /\bfollow-up\b/i, /\bnext steps\b/i, /\bcare plan\b/i]
    }
};

const SYNTHETIC_DEMO_LABEL_PATTERNS = [
    /\bsynthetic demo data only\b/i,
    /\bsynthetic demo data\b/i,
    /\bnot a real medical record\b/i,
    /\bsynthetic lab results\b/i,
    /\bsynthetic intake form\b/i
];

function normalizeText(value) {
    return String(value || '')
        .replace(/\r/g, '\n')
        .replace(/\u0000/g, ' ')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function previewText(value, limit = 220) {
    const normalized = normalizeText(value);
    return normalized.slice(0, limit);
}

function parseNumber(value, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function currentIso() {
    return new Date().toISOString();
}

function envText(key, fallback = '') {
    const value = process.env[key];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;
}

function envFlag(key, fallback = false) {
    const value = envText(key, '');
    if (!value) {
        return Boolean(fallback);
    }

    return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
}

function envInt(key, fallback) {
    return Math.max(0, Math.round(parseNumber(envText(key, ''), fallback)));
}

function envFloat(key, fallback) {
    const parsed = parseNumber(envText(key, ''), fallback);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }

    return parsed;
}

function detectAcceptedDocumentClass(fileName, text) {
    const normalizedFileName = String(fileName || '');
    const normalizedText = String(text || '');
    let bestType = 'unknown';
    let bestScore = 0;

    for (const [documentType, hints] of Object.entries(DOCUMENT_CLASS_HINTS)) {
        let score = 0;
        for (const pattern of hints.filePatterns) {
            if (pattern.test(normalizedFileName)) {
                score += 2;
            }
        }
        for (const pattern of hints.textPatterns) {
            if (pattern.test(normalizedText)) {
                score += 1;
            }
        }

        if (score > bestScore) {
            bestScore = score;
            bestType = documentType;
        }
    }

    return bestScore > 0 ? bestType : 'unknown';
}

function findRejectedClues(text) {
    const lower = normalizeText(text).toLowerCase();
    return REJECTED_DOCUMENT_CLUES.filter((clue) => lower.includes(clue));
}

function buildLocalEntitySummary(text, minimumScore) {
    const normalized = normalizeText(text);
    const lower = normalized.toLowerCase();
    const matches = MEDICAL_ENTITY_HINTS.filter((hint) => lower.includes(hint.toLowerCase()));
    const entityCount = matches.length;
    const highConfidenceEntityCount = matches.length;
    const averageScore = matches.length > 0
        ? Math.min(0.99, minimumScore + (Math.min(matches.length, 6) * 0.03))
        : 0;

    return {
        totalEntities: entityCount,
        highConfidenceEntityCount,
        highConfidenceEntities: matches.slice(0, 12).map((hint) => ({
            text: hint,
            category: 'MEDICAL_HINT',
            score: averageScore
        })),
        averageScore,
        minimumScore
    };
}

function detectSyntheticDemoLabels(text) {
    const normalized = normalizeText(text);
    if (!normalized) {
        return [];
    }

    return Array.from(new Set(SYNTHETIC_DEMO_LABEL_PATTERNS.reduce((collection, pattern) => {
        const match = normalized.match(pattern);
        if (match && match[0]) {
            collection.push(previewText(match[0], 80));
        }
        return collection;
    }, [])));
}

function buildLabEvidenceSummary(text) {
    const normalized = normalizeText(text);
    if (!normalized) {
        return {
            score: 0,
            matchedSignals: [],
            structuredRowCount: 0
        };
    }

    const matchedSignals = [];
    (DOCUMENT_CLASS_HINTS.lab_results.textPatterns || []).forEach((pattern) => {
        const match = normalized.match(pattern);
        if (match && match[0]) {
            matchedSignals.push(previewText(match[0], 80));
        }
    });

    const structuredRowCount = normalized.split('\n').reduce((count, line) => {
        const parsed = ingestion.parseRecognizedLabLine ? ingestion.parseRecognizedLabLine(line) : null;
        return parsed ? count + 1 : count;
    }, 0);

    return {
        score: Array.from(new Set(matchedSignals)).length + structuredRowCount,
        matchedSignals: Array.from(new Set(matchedSignals)).slice(0, 24),
        structuredRowCount
    };
}

function buildIntakeEvidenceSummary(text) {
    const extracted = ingestion.extractIntakeFactsFromText
        ? ingestion.extractIntakeFactsFromText(text, { fileName: 'intake-form.pdf' })
        : { validFieldCount: 0 };

    return {
        score: Number(extracted.validFieldCount || 0),
        validFieldCount: Number(extracted.validFieldCount || 0)
    };
}

function summarizeComprehendEntities(entities, minimumScore) {
    const safeEntities = Array.isArray(entities) ? entities : [];
    const highConfidence = safeEntities.filter((entity) => parseNumber(entity.Score, 0) >= minimumScore);
    const categoryCounts = {};
    highConfidence.forEach((entity) => {
        const category = String(entity.Category || entity.Type || 'UNKNOWN').trim() || 'UNKNOWN';
        categoryCounts[category] = (categoryCounts[category] || 0) + 1;
    });

    const totalScore = highConfidence.reduce((sum, entity) => sum + parseNumber(entity.Score, 0), 0);
    const averageScore = highConfidence.length > 0 ? totalScore / highConfidence.length : 0;

    return {
        totalEntities: safeEntities.length,
        highConfidenceEntityCount: highConfidence.length,
        highConfidenceEntities: highConfidence.slice(0, 20).map((entity) => ({
            text: previewText(entity.Text || '', 40),
            category: String(entity.Category || entity.Type || 'UNKNOWN'),
            score: Number(parseNumber(entity.Score, 0).toFixed(4))
        })),
        categoryCounts,
        averageScore: Number(averageScore.toFixed(4)),
        minimumScore
    };
}

function buildDecisionFromSignals(input) {
    const fileName = String(input.fileName || '');
    const text = normalizeText(input.text || '');
    const minimumEntities = Math.max(1, parseNumber(input.minimumEntities, 2));
    const minimumScore = envFloat('AWS_COMPREHEND_MEDICAL_MIN_SCORE', 0.70);
    const entitySummary = input.detectedEntitySummary || buildLocalEntitySummary(text, minimumScore);
    const documentType = detectAcceptedDocumentClass(fileName, text);
    const syntheticDemoLabels = detectSyntheticDemoLabels(text);
    const isSyntheticDemoData = syntheticDemoLabels.length > 0;
    const labEvidence = buildLabEvidenceSummary(text);
    const intakeEvidence = buildIntakeEvidenceSummary(text);
    const rejectedClues = findRejectedClues(`${fileName}\n${text}`);
    const highConfidenceEntityCount = parseNumber(entitySummary.highConfidenceEntityCount, 0);
    const confidenceBase = Math.max(parseNumber(entitySummary.averageScore, 0), rejectedClues.length > 0 ? 0.25 : 0.45);
    const documentTypeRecognized = ACCEPTED_MEDICAL_DOCUMENT_CLASSES.includes(documentType);
    const confidenceBoost = documentTypeRecognized ? 0.18 : 0;
    const entityBoost = Math.min(highConfidenceEntityCount, 6) * 0.04;
    const labEvidenceBoost = Math.min(labEvidence.score, 8) * 0.03;
    const intakeEvidenceBoost = Math.min(intakeEvidence.score, 6) * 0.03;
    const confidencePenalty = rejectedClues.length > 0 ? 0.25 : 0;
    const confidence = Number(Math.max(0, Math.min(0.99, confidenceBase + confidenceBoost + entityBoost + labEvidenceBoost + intakeEvidenceBoost - confidencePenalty)).toFixed(4));

    if (text === '') {
        return {
            decision: 'review_required',
            documentType: documentTypeRecognized ? documentType : 'unknown',
            confidence: 0,
            extractedTextPreview: '',
            detectedEntitySummary: {
                ...entitySummary,
                rejectedClues,
                medicalEntityCount: highConfidenceEntityCount,
                labEvidenceScore: labEvidence.score,
                labEvidenceSignals: labEvidence.matchedSignals,
                structuredLabRowCount: labEvidence.structuredRowCount,
                intakeEvidenceScore: intakeEvidence.score,
                syntheticDemoLabels
            },
            rejectionReason: 'No reliable text was available for medical-document validation.',
            textExtractionStatus: 'failed',
            medicalValidationStatus: 'review_required',
            chartWriteStatus: 'requires_clinician_review',
            isSyntheticDemoData,
            reviewRequired: true,
            syntheticDemoLabels,
            labEvidenceScore: labEvidence.score
        };
    }

    const strongLabEvidence = documentType === 'lab_results' && (labEvidence.structuredRowCount >= 2 || labEvidence.score >= 6);
    const strongIntakeEvidence = documentType === 'intake_form' && intakeEvidence.validFieldCount >= 3;
    const summary = {
        ...entitySummary,
        rejectedClues,
        medicalEntityCount: highConfidenceEntityCount,
        labEvidenceScore: labEvidence.score,
        labEvidenceSignals: labEvidence.matchedSignals,
        structuredLabRowCount: labEvidence.structuredRowCount,
        intakeEvidenceScore: intakeEvidence.score,
        syntheticDemoLabels
    };

    if (rejectedClues.length >= 2 && highConfidenceEntityCount < minimumEntities && labEvidence.score < 2 && intakeEvidence.score < 2) {
        return {
            decision: 'rejected',
            documentType: 'unknown',
            confidence,
            extractedTextPreview: previewText(text),
            detectedEntitySummary: summary,
            rejectionReason: 'The uploaded PDF appears to be a non-medical document based on business or unrelated document language.',
            textExtractionStatus: 'success',
            medicalValidationStatus: 'rejected',
            chartWriteStatus: 'rejected',
            isSyntheticDemoData,
            reviewRequired: true,
            syntheticDemoLabels,
            labEvidenceScore: labEvidence.score
        };
    }

    if (strongLabEvidence || strongIntakeEvidence || (documentTypeRecognized && highConfidenceEntityCount >= minimumEntities && confidence >= minimumScore)) {
        return {
            decision: 'allowed',
            documentType,
            confidence,
            extractedTextPreview: previewText(text),
            detectedEntitySummary: summary,
            textExtractionStatus: 'success',
            medicalValidationStatus: 'allowed',
            chartWriteStatus: 'requires_clinician_review',
            isSyntheticDemoData,
            reviewRequired: true,
            syntheticDemoLabels,
            labEvidenceScore: labEvidence.score
        };
    }

    if (!documentTypeRecognized && highConfidenceEntityCount <= 0 && labEvidence.score < 2 && intakeEvidence.score < 2) {
        return {
            decision: 'rejected',
            documentType: 'unknown',
            confidence,
            extractedTextPreview: previewText(text),
            detectedEntitySummary: summary,
            rejectionReason: 'The uploaded PDF did not contain enough recognizable clinical or healthcare language to be ingested.',
            textExtractionStatus: 'success',
            medicalValidationStatus: 'rejected',
            chartWriteStatus: 'rejected',
            isSyntheticDemoData,
            reviewRequired: true,
            syntheticDemoLabels,
            labEvidenceScore: labEvidence.score
        };
    }

    return {
        decision: 'review_required',
        documentType: documentTypeRecognized ? documentType : 'unknown',
        confidence,
        extractedTextPreview: previewText(text),
        detectedEntitySummary: summary,
        rejectionReason: 'Document type could not be verified with high confidence.',
        textExtractionStatus: 'success',
        medicalValidationStatus: 'review_required',
        chartWriteStatus: 'requires_clinician_review',
        isSyntheticDemoData,
        reviewRequired: true,
        syntheticDemoLabels,
        labEvidenceScore: labEvidence.score
    };
}

async function runLocalMedicalDocumentGuard(input) {
    const text = normalizeText(
        input.extractedText
            || input.text
            || input.fallbackText
            || ''
    );
    const minimumEntities = Math.max(1, parseNumber(input.minimumEntities, envInt('AWS_COMPREHEND_MEDICAL_MIN_ENTITIES', 2)));
    const minimumScore = envFloat('AWS_COMPREHEND_MEDICAL_MIN_SCORE', 0.70);
    const detectedEntitySummary = buildLocalEntitySummary(text, minimumScore);
    const result = buildDecisionFromSignals({
        fileName: input.fileName,
        text,
        minimumEntities,
        detectedEntitySummary
    });

    return {
        ...result,
        ok: true,
        guardProvider: 'local_validation_fallback',
        extractionMethod: 'local_text_heuristics',
        textractStatus: 'not_run',
        comprehendStatus: 'not_run',
        extractedText: text,
        auditEvents: [
            'copilot_document_guard_started',
            result.decision === 'allowed'
                ? 'copilot_document_guard_allowed'
                : (result.decision === 'rejected' ? 'copilot_document_guard_rejected' : 'copilot_document_guard_review_required')
        ]
    };
}

async function loadAwsClients() {
    const s3 = require('@aws-sdk/client-s3');
    const textract = require('@aws-sdk/client-textract');
    const comprehendMedical = require('@aws-sdk/client-comprehendmedical');
    return {
        S3Client: s3.S3Client,
        PutObjectCommand: s3.PutObjectCommand,
        DeleteObjectCommand: s3.DeleteObjectCommand,
        TextractClient: textract.TextractClient,
        StartDocumentTextDetectionCommand: textract.StartDocumentTextDetectionCommand,
        GetDocumentTextDetectionCommand: textract.GetDocumentTextDetectionCommand,
        ComprehendMedicalClient: comprehendMedical.ComprehendMedicalClient,
        DetectEntitiesV2Command: comprehendMedical.DetectEntitiesV2Command
    };
}

async function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function runAwsMedicalDocumentGuard(input) {
    const region = envText('AWS_REGION');
    const bucket = envText('AWS_TEXTRACT_UPLOAD_BUCKET');
    if (!region || !bucket) {
        return {
            ok: true,
            decision: 'review_required',
            documentType: 'unknown',
            confidence: 0,
            extractedTextPreview: '',
            detectedEntitySummary: {
                totalEntities: 0,
                highConfidenceEntityCount: 0,
                highConfidenceEntities: [],
                categoryCounts: {},
                averageScore: 0,
                minimumScore: envFloat('AWS_COMPREHEND_MEDICAL_MIN_SCORE', 0.70),
                rejectedClues: [],
                medicalEntityCount: 0
            },
            rejectionReason: 'AWS medical document validation is enabled, but required AWS configuration is missing.',
            guardProvider: 'aws_textract_comprehend_medical',
            extractionMethod: 'aws_guard_unavailable',
            textractStatus: 'failed',
            comprehendStatus: 'not_run',
            extractedText: '',
            auditEvents: [
                'copilot_document_guard_started',
                'copilot_textract_started',
                'copilot_textract_failed',
                'copilot_document_guard_review_required',
                'copilot_vectorization_blocked'
            ]
        };
    }

    const filePath = String(input.filePath || '');
    const fileName = String(input.fileName || path.basename(filePath || 'attached-document.pdf'));
    const minimumEntities = Math.max(1, parseNumber(input.minimumEntities, envInt('AWS_COMPREHEND_MEDICAL_MIN_ENTITIES', 2)));
    const minimumScore = envFloat('AWS_COMPREHEND_MEDICAL_MIN_SCORE', 0.70);
    const pollIntervalMs = Math.max(400, envInt('AWS_TEXTRACT_POLL_INTERVAL_MS', 1200));
    const maxPollAttempts = Math.max(3, envInt('AWS_TEXTRACT_MAX_POLLS', 20));
    const requestId = String(input.requestId || 'request');

    if (!filePath || !fs.existsSync(filePath)) {
        return {
            ok: true,
            decision: 'review_required',
            documentType: 'unknown',
            confidence: 0,
            extractedTextPreview: '',
            detectedEntitySummary: {
                totalEntities: 0,
                highConfidenceEntityCount: 0,
                highConfidenceEntities: [],
                categoryCounts: {},
                averageScore: 0,
                minimumScore,
                rejectedClues: [],
                medicalEntityCount: 0
            },
            rejectionReason: 'The uploaded PDF file was not available for AWS medical-document validation.',
            guardProvider: 'aws_textract_comprehend_medical',
            extractionMethod: 'aws_guard_unavailable',
            textractStatus: 'failed',
            comprehendStatus: 'not_run',
            extractedText: '',
            auditEvents: [
                'copilot_document_guard_started',
                'copilot_textract_started',
                'copilot_textract_failed',
                'copilot_document_guard_review_required',
                'copilot_vectorization_blocked'
            ]
        };
    }

    let S3Client;
    let PutObjectCommand;
    let DeleteObjectCommand;
    let TextractClient;
    let StartDocumentTextDetectionCommand;
    let GetDocumentTextDetectionCommand;
    let ComprehendMedicalClient;
    let DetectEntitiesV2Command;
    let s3Client;
    let textractClient;
    let comprehendClient;
    const objectKey = `openemr-copilot/${requestId}/${Date.now()}-${path.basename(fileName)}`;
    const binary = fs.readFileSync(filePath);
    let textractStatus = 'started';
    let comprehendStatus = 'not_run';
    let extractedText = '';

    try {
        ({
            S3Client,
            PutObjectCommand,
            DeleteObjectCommand,
            TextractClient,
            StartDocumentTextDetectionCommand,
            GetDocumentTextDetectionCommand,
            ComprehendMedicalClient,
            DetectEntitiesV2Command
        } = await loadAwsClients());
        s3Client = new S3Client({ region });
        textractClient = new TextractClient({ region });
        comprehendClient = new ComprehendMedicalClient({ region });

        await s3Client.send(new PutObjectCommand({
            Bucket: bucket,
            Key: objectKey,
            Body: binary,
            ContentType: 'application/pdf'
        }));

        const startResponse = await textractClient.send(new StartDocumentTextDetectionCommand({
            DocumentLocation: {
                S3Object: {
                    Bucket: bucket,
                    Name: objectKey
                }
            }
        }));

        const jobId = String(startResponse.JobId || '');
        if (!jobId) {
            throw new Error('Textract did not return a job id.');
        }

        let nextToken;
        let jobStatus = 'IN_PROGRESS';
        let attempts = 0;
        const lineBlocks = [];

        while (attempts < maxPollAttempts) {
            attempts += 1;
            const result = await textractClient.send(new GetDocumentTextDetectionCommand({
                JobId: jobId,
                NextToken: nextToken
            }));
            jobStatus = String(result.JobStatus || 'FAILED');

            if (jobStatus === 'SUCCEEDED') {
                (result.Blocks || []).forEach((block) => {
                    if (String(block.BlockType || '') === 'LINE' && block.Text) {
                        lineBlocks.push(String(block.Text));
                    }
                });
                if (result.NextToken) {
                    nextToken = String(result.NextToken);
                    continue;
                }
                break;
            }

            if (jobStatus === 'FAILED' || jobStatus === 'PARTIAL_SUCCESS') {
                throw new Error(`Textract job ended with status ${jobStatus}.`);
            }

            await sleep(pollIntervalMs);
        }

        if (jobStatus !== 'SUCCEEDED') {
            throw new Error(`Textract job timed out after ${maxPollAttempts} polls.`);
        }

        extractedText = normalizeText(lineBlocks.join('\n'));
        textractStatus = 'succeeded';
        if (!extractedText) {
            return {
                ok: true,
                decision: 'review_required',
                documentType: 'unknown',
                confidence: 0,
                extractedTextPreview: '',
                detectedEntitySummary: {
                    totalEntities: 0,
                    highConfidenceEntityCount: 0,
                    highConfidenceEntities: [],
                    categoryCounts: {},
                    averageScore: 0,
                    minimumScore,
                    rejectedClues: [],
                    medicalEntityCount: 0
                },
                rejectionReason: 'Amazon Textract did not return enough readable text to validate this medical document.',
                guardProvider: 'aws_textract_comprehend_medical',
                extractionMethod: 'aws_textract',
                textractStatus,
                comprehendStatus,
                extractedText,
                auditEvents: [
                    'copilot_document_guard_started',
                    'copilot_textract_started',
                    'copilot_textract_succeeded',
                    'copilot_document_guard_review_required',
                    'copilot_vectorization_blocked'
                ]
            };
        }

        comprehendStatus = 'started';
        const comprehendResponse = await comprehendClient.send(new DetectEntitiesV2Command({
            Text: extractedText
        }));
        comprehendStatus = 'succeeded';
        const detectedEntitySummary = summarizeComprehendEntities(comprehendResponse.Entities || [], minimumScore);
        const result = buildDecisionFromSignals({
            fileName,
            text: extractedText,
            minimumEntities,
            detectedEntitySummary
        });

        return {
            ...result,
            ok: true,
            guardProvider: 'aws_textract_comprehend_medical',
            extractionMethod: 'aws_textract_comprehend_medical',
            textractStatus,
            comprehendStatus,
            extractedText,
            auditEvents: [
                'copilot_document_guard_started',
                'copilot_textract_started',
                'copilot_textract_succeeded',
                'copilot_comprehend_medical_started',
                'copilot_comprehend_medical_succeeded',
                result.decision === 'allowed'
                    ? 'copilot_document_guard_allowed'
                    : (result.decision === 'rejected' ? 'copilot_document_guard_rejected' : 'copilot_document_guard_review_required'),
                result.decision === 'allowed' ? null : 'copilot_vectorization_blocked'
            ].filter(Boolean)
        };
    } catch (error) {
        const safeErrorMessage = error instanceof Error ? error.message : String(error || 'Unknown AWS medical-document-guard error');
        const likelyCorruptedPdf = /bad document|invalid|malformed|corrupt|unsupported|parse/i.test(safeErrorMessage);
        return {
            ok: true,
            decision: likelyCorruptedPdf ? 'rejected' : 'review_required',
            documentType: 'unknown',
            confidence: 0,
            extractedTextPreview: previewText(extractedText),
            detectedEntitySummary: {
                totalEntities: 0,
                highConfidenceEntityCount: 0,
                highConfidenceEntities: [],
                categoryCounts: {},
                averageScore: 0,
                minimumScore,
                rejectedClues: [],
                medicalEntityCount: 0
            },
            rejectionReason: likelyCorruptedPdf
                ? 'The uploaded PDF could not be read for medical-document validation.'
                : 'AWS medical document validation was unavailable. Review required before ingestion.',
            guardProvider: 'aws_textract_comprehend_medical',
            extractionMethod: 'aws_guard_failure',
            textractStatus: textractStatus === 'started' ? 'failed' : textractStatus,
            comprehendStatus: comprehendStatus === 'started' ? 'failed' : comprehendStatus,
            extractedText,
            auditEvents: [
                'copilot_document_guard_started',
                'copilot_textract_started',
                textractStatus === 'succeeded' ? 'copilot_textract_succeeded' : 'copilot_textract_failed',
                comprehendStatus === 'started' || comprehendStatus === 'failed' ? 'copilot_comprehend_medical_started' : null,
                likelyCorruptedPdf ? 'copilot_document_guard_rejected' : 'copilot_document_guard_review_required',
                'copilot_vectorization_blocked'
            ].filter(Boolean),
            internalError: safeErrorMessage
        };
    } finally {
        try {
            await s3Client.send(new DeleteObjectCommand({
                Bucket: bucket,
                Key: objectKey
            }));
        } catch (cleanupError) {
        }
    }
}

async function runMedicalDocumentGuard(input) {
    const mode = String(input.mode || '').toLowerCase();
    const awsEnabled = envFlag('AWS_MEDICAL_DOCUMENT_GUARD_ENABLED', false);

    if (mode === 'local' || !awsEnabled) {
        const localResult = await runLocalMedicalDocumentGuard(input);
        return {
            ...localResult,
            awsGuardEnabled: awsEnabled
        };
    }

    const awsResult = await runAwsMedicalDocumentGuard(input);
    return {
        ...awsResult,
        awsGuardEnabled: true
    };
}

async function main() {
    const inputPath = process.argv[2];
    if (!inputPath) {
        process.stderr.write('Missing JSON input path.\n');
        process.exit(1);
        return;
    }

    const payload = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
    const result = await runMedicalDocumentGuard(payload);
    process.stdout.write(JSON.stringify(result));
}

if (require.main === module) {
    main().catch((error) => {
        process.stderr.write((error && error.stack) ? error.stack : String(error));
        process.exit(1);
    });
}

module.exports = {
    ACCEPTED_MEDICAL_DOCUMENT_CLASSES,
    REJECTED_DOCUMENT_CLUES,
    detectAcceptedDocumentClass,
    buildDecisionFromSignals,
    runLocalMedicalDocumentGuard,
    runAwsMedicalDocumentGuard,
    runMedicalDocumentGuard
};
