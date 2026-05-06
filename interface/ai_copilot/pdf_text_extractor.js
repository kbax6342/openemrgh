#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

function normalizeText(value) {
    return String(value || '')
        .replace(/\r/g, '\n')
        .replace(/\u0000/g, ' ')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function previewText(value, limit = 240) {
    return normalizeText(value).slice(0, limit);
}

function envText(key, fallback = '') {
    const value = process.env[key];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;
}

function envInt(key, fallback) {
    const parsed = Number(envText(key, ''));
    return Number.isFinite(parsed) ? Math.max(0, Math.round(parsed)) : fallback;
}

function buildFailureResult(overrides = {}) {
    const extractedText = normalizeText(overrides.extractedText || '');
    const auditEvents = Array.isArray(overrides.auditEvents) ? overrides.auditEvents.slice() : [];
    return {
        ok: true,
        textExtractionStatus: overrides.textExtractionStatus || 'failed',
        extractedText,
        extractedTextPreview: previewText(overrides.extractedTextPreview || extractedText),
        pageCount: Number.isFinite(overrides.pageCount) ? overrides.pageCount : 0,
        extractionMethod: overrides.extractionMethod || 'failed',
        rawBytesDetected: Boolean(overrides.rawBytesDetected),
        auditEvents,
        internalError: overrides.internalError || ''
    };
}

function looksLikeRawPdfSyntax(text) {
    const normalized = normalizeText(text);
    if (!normalized) {
        return false;
    }

    if (/^%PDF-\d/i.test(normalized)) {
        return true;
    }

    const syntaxMatches = [
        /\b\d+\s+\d+\s+obj\b/i,
        /\bendobj\b/i,
        /\bxref\b/i,
        /\/BaseFont\b/i,
        /\/Type\s*\/Page\b/i,
        /ReportLab Generated PDF/i
    ].filter((pattern) => pattern.test(normalized)).length;

    return syntaxMatches >= 2;
}

function hasEnoughReadableText(text) {
    const normalized = normalizeText(text);
    if (!normalized || looksLikeRawPdfSyntax(normalized)) {
        return false;
    }

    const alphaCharacters = (normalized.match(/[A-Za-z]/g) || []).length;
    const wordCount = normalized.split(/\s+/).filter(Boolean).length;
    return alphaCharacters >= 40 && wordCount >= 12;
}

async function loadPdfParse() {
    try {
        return require('pdf-parse');
    } catch (error) {
        if (process.env.AI_COPILOT_PDF_PARSE_PATH) {
            return require(process.env.AI_COPILOT_PDF_PARSE_PATH);
        }
        throw error;
    }
}

async function extractWithPdfParse(filePath) {
    const pdfParse = await loadPdfParse();
    const buffer = fs.readFileSync(filePath);
    const parsed = await pdfParse(buffer);
    return {
        text: normalizeText(parsed && parsed.text ? parsed.text : ''),
        pageCount: Number.isFinite(parsed && parsed.numpages) ? parsed.numpages : 0
    };
}

async function loadAwsClients() {
    const s3 = require('@aws-sdk/client-s3');
    const textract = require('@aws-sdk/client-textract');
    return {
        S3Client: s3.S3Client,
        PutObjectCommand: s3.PutObjectCommand,
        DeleteObjectCommand: s3.DeleteObjectCommand,
        TextractClient: textract.TextractClient,
        StartDocumentTextDetectionCommand: textract.StartDocumentTextDetectionCommand,
        GetDocumentTextDetectionCommand: textract.GetDocumentTextDetectionCommand
    };
}

function canUseTextractFallback() {
    return Boolean(envText('AWS_REGION')) && Boolean(envText('AWS_TEXTRACT_UPLOAD_BUCKET'));
}

async function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function extractWithTextract(filePath, fileName, requestId) {
    const region = envText('AWS_REGION');
    const bucket = envText('AWS_TEXTRACT_UPLOAD_BUCKET');
    const pollIntervalMs = Math.max(400, envInt('AWS_TEXTRACT_POLL_INTERVAL_MS', 1200));
    const maxPollAttempts = Math.max(3, envInt('AWS_TEXTRACT_MAX_POLLS', 20));

    const {
        S3Client,
        PutObjectCommand,
        DeleteObjectCommand,
        TextractClient,
        StartDocumentTextDetectionCommand,
        GetDocumentTextDetectionCommand
    } = await loadAwsClients();

    const s3Client = new S3Client({ region });
    const textractClient = new TextractClient({ region });
    const objectKey = `openemr-copilot/pdf-extraction/${requestId}/${Date.now()}-${path.basename(fileName || filePath)}`;
    const binary = fs.readFileSync(filePath);

    try {
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
        let pageCount = 0;

        while (attempts < maxPollAttempts) {
            attempts += 1;
            const result = await textractClient.send(new GetDocumentTextDetectionCommand({
                JobId: jobId,
                NextToken: nextToken
            }));
            jobStatus = String(result.JobStatus || 'FAILED');

            if (jobStatus === 'SUCCEEDED') {
                (result.Blocks || []).forEach((block) => {
                    if (String(block.BlockType || '') === 'PAGE') {
                        pageCount += 1;
                    }
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

        return {
            text: normalizeText(lineBlocks.join('\n')),
            pageCount
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

async function extractPdfText(input) {
    const requestId = String(input.requestId || 'request');
    const filePath = String(input.filePath || '');
    const fileName = String(input.fileName || path.basename(filePath || 'attached-document.pdf'));
    const auditEvents = ['copilot_pdf_text_extraction_started'];

    if (!filePath || !fs.existsSync(filePath)) {
        auditEvents.push('copilot_pdf_text_extraction_failed');
        return buildFailureResult({
            textExtractionStatus: 'failed',
            extractionMethod: 'failed',
            auditEvents,
            internalError: 'Uploaded PDF file path was not available for extraction.'
        });
    }

    let parsedText = '';
    let pageCount = 0;

    try {
        const parsed = await extractWithPdfParse(filePath);
        parsedText = parsed.text;
        pageCount = parsed.pageCount;
    } catch (error) {
        auditEvents.push('copilot_pdf_text_extraction_failed');
        if (!canUseTextractFallback()) {
            return buildFailureResult({
                textExtractionStatus: 'failed',
                extractionMethod: 'failed',
                auditEvents,
                internalError: error instanceof Error ? error.message : String(error || 'pdf-parse failed')
            });
        }
    }

    if (looksLikeRawPdfSyntax(parsedText)) {
        auditEvents.push('copilot_pdf_raw_bytes_detected');
        parsedText = '';
    }

    if (hasEnoughReadableText(parsedText)) {
        auditEvents.push('copilot_pdf_text_extraction_succeeded');
        return {
            ok: true,
            textExtractionStatus: 'success',
            extractedText: parsedText,
            extractedTextPreview: previewText(parsedText),
            pageCount,
            extractionMethod: 'pdf_text',
            rawBytesDetected: false,
            auditEvents
        };
    }

    auditEvents.push('copilot_pdf_ocr_or_textract_fallback_started');
    if (!canUseTextractFallback()) {
        auditEvents.push('copilot_pdf_text_extraction_failed');
        return buildFailureResult({
            textExtractionStatus: 'ocr_required',
            extractionMethod: 'ocr',
            rawBytesDetected: looksLikeRawPdfSyntax(parsedText),
            auditEvents,
            extractedText: '',
            extractedTextPreview: '',
            pageCount
        });
    }

    try {
        const textractResult = await extractWithTextract(filePath, fileName, requestId);
        if (hasEnoughReadableText(textractResult.text)) {
            auditEvents.push('copilot_pdf_text_extraction_succeeded');
            return {
                ok: true,
                textExtractionStatus: 'success',
                extractedText: textractResult.text,
                extractedTextPreview: previewText(textractResult.text),
                pageCount: textractResult.pageCount,
                extractionMethod: 'textract',
                rawBytesDetected: false,
                auditEvents
            };
        }

        auditEvents.push('copilot_pdf_text_extraction_failed');
        return buildFailureResult({
            textExtractionStatus: 'ocr_required',
            extractionMethod: 'ocr',
            rawBytesDetected: false,
            auditEvents,
            extractedText: textractResult.text,
            extractedTextPreview: previewText(textractResult.text),
            pageCount: textractResult.pageCount
        });
    } catch (error) {
        auditEvents.push('copilot_pdf_text_extraction_failed');
        return buildFailureResult({
            textExtractionStatus: 'ocr_required',
            extractionMethod: 'ocr',
            rawBytesDetected: false,
            auditEvents,
            internalError: error instanceof Error ? error.message : String(error || 'Textract fallback failed')
        });
    }
}

async function main() {
    const inputPath = process.argv[2];
    if (!inputPath) {
        process.stderr.write('Missing JSON input path.\n');
        process.exit(1);
        return;
    }

    const payload = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
    const result = await extractPdfText(payload);
    process.stdout.write(JSON.stringify(result));
}

if (require.main === module) {
    main().catch((error) => {
        process.stderr.write((error && error.stack) ? error.stack : String(error));
        process.exit(1);
    });
}

module.exports = {
    canUseTextractFallback,
    extractPdfText,
    hasEnoughReadableText,
    looksLikeRawPdfSyntax
};
