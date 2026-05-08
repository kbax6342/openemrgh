(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotRedaction = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const SENSITIVE_KEY_SET = new Set([
        'selectedpatientkey',
        'patientkey',
        'patient_id',
        'patientid',
        'pubpid',
        'patientname',
        'email',
        'phone',
        'dob',
        'dateofbirth',
        'address',
        'insuranceid',
        'subscriberid',
        'memberid',
        'documenttext',
        'rawdocumenttext',
        'rawtranscript',
        'systemprompt',
        'hiddennotes',
        'screenshot',
        'screenshotdata',
        'imagedata',
        'base64',
        'prompt',
        'requestprompt',
        'transcript'
    ]);

    const RAW_TEXT_KEY_SET = new Set([
        'documenttext',
        'rawdocumenttext',
        'rawtranscript',
        'systemprompt',
        'hiddennotes',
        'prompt',
        'requestprompt',
        'text',
        'previewtext',
        'extractedtext',
        'extractedtextpreview',
        'quote_or_value',
        'quoteorvalue'
    ]);

    const SCREENSHOT_KEY_SET = new Set([
        'screenshot',
        'screenshotdata',
        'image',
        'imagedata',
        'blob',
        'base64'
    ]);

    const REDACTION_MARKERS = {
        email: '[REDACTED_EMAIL]',
        phone: '[REDACTED_PHONE]',
        dob: '[REDACTED_DOB]',
        address: '[REDACTED_ADDRESS]',
        insurance: '[REDACTED_INSURANCE_ID]',
        patientId: '[REDACTED_PATIENT_ID]',
        rawDocument: '[REDACTED_RAW_DOCUMENT_TEXT]',
        rawTranscript: '[REDACTED_RAW_TRANSCRIPT]',
        hiddenPrompt: '[REDACTED_HIDDEN_PROMPT]',
        screenshot: '[REDACTED_SCREENSHOT_DATA]',
        base64: '[REDACTED_BINARY_BLOB]',
        longText: '[REDACTED_LONG_TEXT_PAYLOAD]',
        sensitive: '[REDACTED]'
    };

    function hashIdentifier(value) {
        const input = String(value || '').trim();
        if (!input) {
            return null;
        }

        let hash = 2166136261;
        for (let index = 0; index < input.length; index += 1) {
            hash ^= input.charCodeAt(index);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }

        return `h_${(hash >>> 0).toString(16).padStart(8, '0')}`;
    }

    function stringValue(value) {
        return value === undefined || value === null ? '' : String(value);
    }

    function normalizeKey(key) {
        return String(key || '').replace(/[^a-z0-9]/gi, '').toLowerCase();
    }

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function redactText(value) {
        let text = stringValue(value);
        if (!text) {
            return text;
        }

        if (/%PDF-|endobj|xref|\/BaseFont/i.test(text)) {
            return REDACTION_MARKERS.rawDocument;
        }

        if (/raw transcript/i.test(text)) {
            return REDACTION_MARKERS.rawTranscript;
        }

        if (/system prompt|hidden notes/i.test(text)) {
            return REDACTION_MARKERS.hiddenPrompt;
        }

        if (/[A-Za-z0-9+/]{120,}={0,2}/.test(text)) {
            return REDACTION_MARKERS.base64;
        }

        text = text
            .replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, REDACTION_MARKERS.email)
            .replace(/\b(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)\d{3}[-.\s]?\d{4}\b/g, REDACTION_MARKERS.phone)
            .replace(/\b(?:dob|date of birth)\s*[:=]?\s*(?:19|20)\d{2}[-/](?:0[1-9]|1[0-2])[-/](?:0[1-9]|[12]\d|3[01])\b/gi, `DOB ${REDACTION_MARKERS.dob}`)
            .replace(/\b(?:19|20)\d{2}[-/](?:0[1-9]|1[0-2])[-/](?:0[1-9]|[12]\d|3[01])\b/g, REDACTION_MARKERS.dob)
            .replace(/\b\d{1,5}\s+[A-Za-z0-9.'-]+\s+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr|Court|Ct|Way)\b/gi, REDACTION_MARKERS.address)
            .replace(/\b(?:subscriber|member|policy|insurance)[\s_-]*(?:id|identifier|number)?\s*[:#]?\s*[A-Z0-9-]{6,}\b/gi, REDACTION_MARKERS.insurance)
            .replace(/\b(?:selectedPatientKey|patientKey|patient_id|patientId|pubpid)\s*[:=]\s*[A-Za-z0-9_-]+\b/gi, (match) => {
                const key = match.split(/[:=]/)[0];
                return `${key}=${REDACTION_MARKERS.patientId}`;
            });

        if (text.length > 600 && /\s/.test(text)) {
            return REDACTION_MARKERS.longText;
        }

        return text;
    }

    function detectPhiIssues(value) {
        const text = typeof value === 'string' ? value : JSON.stringify(value || {});
        const issues = [];
        const checks = [
            { label: 'email', regex: /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i },
            { label: 'phone', regex: /\b(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)\d{3}[-.\s]?\d{4}\b/ },
            { label: 'dob', regex: /\b(?:19|20)\d{2}[-/](?:0[1-9]|1[0-2])[-/](?:0[1-9]|[12]\d|3[01])\b/ },
            { label: 'address', regex: /\b\d{1,5}\s+[A-Za-z0-9.'-]+\s+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr|Court|Ct|Way)\b/i },
            { label: 'insurance id', regex: /\b(?:subscriber|member|policy|insurance)[\s_-]*(?:id|identifier|number)?\s*[:#]?\s*[A-Z0-9-]{6,}\b/i },
            { label: 'patient key', regex: /\b(?:selectedPatientKey|patientKey|patient_id|patientId|pubpid)\b/i },
            { label: 'raw pdf', regex: /%PDF-|endobj|xref|\/BaseFont/i },
            { label: 'raw transcript', regex: /raw transcript/i },
            { label: 'system prompt', regex: /system prompt/i },
            { label: 'hidden notes', regex: /hidden notes/i },
            { label: 'base64 blob', regex: /[A-Za-z0-9+/]{120,}={0,2}/ }
        ];

        checks.forEach((check) => {
            if (check.regex.test(text)) {
                issues.push(check.label);
            }
        });

        return unique(issues);
    }

    function redactObject(value, depth) {
        const level = Number.isFinite(depth) ? depth : 0;
        if (level > 3) {
            return REDACTION_MARKERS.sensitive;
        }

        if (Array.isArray(value)) {
            return value.slice(0, 12).map(function (item) {
                return redactObject(item, level + 1);
            });
        }

        if (!value || typeof value !== 'object') {
            return typeof value === 'string' ? redactText(value) : value;
        }

        const safe = {};
        Object.keys(value).forEach(function (key) {
            const normalizedKey = normalizeKey(key);
            const item = value[key];

            if (SENSITIVE_KEY_SET.has(normalizedKey)) {
                if (normalizedKey === 'selectedpatientkey' || normalizedKey === 'patientkey' || normalizedKey === 'patientid' || normalizedKey === 'patient_id' || normalizedKey === 'pubpid') {
                    safe.patientContextPresent = Boolean(item);
                    safe.patientContextHash = item ? hashIdentifier(item) : null;
                    safe.patientIdentifierRedacted = true;
                }
                if (SCREENSHOT_KEY_SET.has(normalizedKey)) {
                    safe.screenshotCaptureAttempted = true;
                    safe.screenshotBlockedReason = 'PHI_SAFE_DEFAULT';
                    safe.rawScreenshotLogged = false;
                }
                if (RAW_TEXT_KEY_SET.has(normalizedKey)) {
                    safe.rawDocumentTextLogged = false;
                }
                return;
            }

            if (typeof item === 'string') {
                safe[key] = redactText(item);
                return;
            }

            if (Array.isArray(item) || (item && typeof item === 'object')) {
                safe[key] = redactObject(item, level + 1);
                return;
            }

            safe[key] = item;
        });

        return safe;
    }

    function sanitizeTelemetryPayload(payload, allowedKeys) {
        const safePayload = {};
        const patientValue = payload && (
            payload.selectedPatientKey
            || payload.patientKey
            || payload.patient_id
            || payload.patientId
            || payload.pubpid
            || null
        );
        if (patientValue) {
            safePayload.patientContextPresent = true;
            safePayload.patientContextHash = hashIdentifier(patientValue);
            safePayload.patientIdentifierRedacted = true;
        } else if (payload && Object.prototype.hasOwnProperty.call(payload, 'selectedPatientKey')) {
            safePayload.patientContextPresent = false;
            safePayload.patientIdentifierRedacted = true;
        }

        Object.keys(payload || {}).forEach(function (key) {
            if (allowedKeys && !allowedKeys.has(key)) {
                return;
            }

            const normalizedKey = normalizeKey(key);
            const value = payload[key];

            if (value === undefined || value === null || value === '') {
                return;
            }

            if (normalizedKey === 'selectedpatientkey' || normalizedKey === 'patientkey' || normalizedKey === 'patientid' || normalizedKey === 'patient_id' || normalizedKey === 'pubpid') {
                return;
            }

            if (normalizedKey === 'sessionid') {
                safePayload.sessionIdHash = hashIdentifier(value);
                return;
            }

            if (normalizeKey(key).includes('screenshot') || SCREENSHOT_KEY_SET.has(normalizedKey)) {
                safePayload.screenshotCaptureAttempted = true;
                safePayload.screenshotBlockedReason = 'PHI_SAFE_DEFAULT';
                safePayload.rawScreenshotLogged = false;
                return;
            }

            if (RAW_TEXT_KEY_SET.has(normalizedKey)) {
                safePayload.rawDocumentTextLogged = false;
                return;
            }

            if (SENSITIVE_KEY_SET.has(normalizedKey)) {
                return;
            }

            if (typeof value === 'string') {
                safePayload[key] = redactText(value);
                return;
            }

            if (Array.isArray(value) || (value && typeof value === 'object')) {
                safePayload[key] = redactObject(value, 0);
                return;
            }

            safePayload[key] = value;
        });

        if (!Object.prototype.hasOwnProperty.call(safePayload, 'phiRedacted')) {
            safePayload.phiRedacted = true;
        }
        if (!Object.prototype.hasOwnProperty.call(safePayload, 'rawDocumentTextLogged')) {
            safePayload.rawDocumentTextLogged = false;
        }
        if (!Object.prototype.hasOwnProperty.call(safePayload, 'rawScreenshotLogged')) {
            safePayload.rawScreenshotLogged = false;
        }

        return safePayload;
    }

    return {
        REDACTION_MARKERS: REDACTION_MARKERS,
        detectPhiIssues: detectPhiIssues,
        hashIdentifier: hashIdentifier,
        redactObject: redactObject,
        redactText: redactText,
        sanitizeTelemetryPayload: sanitizeTelemetryPayload
    };
}));
