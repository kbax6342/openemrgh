#!/usr/bin/env node
'use strict';

const assert = require('assert');
const redaction = require('./redact_phi.js');

function run(name, fn) {
    fn();
    return name;
}

const tests = [
    run('redacts emails, phones, and dob values', () => {
        const value = 'email=marcus@example.com phone=555-010-2222 DOB 1984-02-11';
        const redacted = redaction.redactText(value);
        assert.ok(!redacted.includes('marcus@example.com'));
        assert.ok(!redacted.includes('555-010-2222'));
        assert.ok(!redacted.includes('1984-02-11'));
    }),
    run('sanitizes raw patient identifiers from telemetry payloads', () => {
        const payload = redaction.sanitizeTelemetryPayload({
            selectedPatientKey: 'DEMO-PCP-1001',
            role: 'doctor',
            mode: 'clinical_notes'
        }, new Set(['selectedPatientKey', 'role', 'mode']));
        assert.strictEqual(payload.patientContextPresent, true);
        assert.ok(payload.patientContextHash);
        assert.strictEqual(payload.patientIdentifierRedacted, true);
        assert.ok(!Object.prototype.hasOwnProperty.call(payload, 'selectedPatientKey'));
    }),
    run('blocks raw document text and screenshot/blob fields', () => {
        const payload = redaction.sanitizeTelemetryPayload({
            extractedTextPreview: '%PDF-1.4 raw bytes',
            screenshotData: 'data:image/png;base64,' + 'A'.repeat(160),
            rawTranscript: 'raw transcript: hello'
        }, new Set(['extractedTextPreview', 'screenshotData', 'rawTranscript']));
        assert.strictEqual(payload.rawDocumentTextLogged, false);
        assert.strictEqual(payload.rawScreenshotLogged, false);
        assert.strictEqual(payload.screenshotCaptureAttempted, true);
        assert.strictEqual(payload.screenshotBlockedReason, 'PHI_SAFE_DEFAULT');
    }),
    run('detects obvious PHI markers', () => {
        const issues = redaction.detectPhiIssues('selectedPatientKey=DEMO-PCP-1001 email marcus@example.com');
        assert.ok(issues.includes('email'));
        assert.ok(issues.includes('patient key'));
    })
];

console.log(`redact_phi.test.js: ${tests.length} tests passed`);
