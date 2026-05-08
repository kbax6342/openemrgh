#!/usr/bin/env node
'use strict';

const assert = require('assert');
const report = require('./generate_cost_latency_report.js');

const data = report.buildReportData({
    evalResults: {
        case_count: 50,
        passed_count: 50,
        failed_count: 0,
        pass_rate: 1
    },
    observabilitySummary: {
        encounter_count: 50,
        latency_ms: {
            average: 820,
            p50: 780,
            p95: 1250
        },
        costs: {
            average_request_cost_usd: 0.00125,
            average_total_tokens: 1400
        },
        bottlenecks: [
            { step_name: 'FinalResponse', average_ms: 510, p95_ms: 900 },
            { step_name: 'IntakeExtractorWorker', average_ms: 210, p95_ms: 340 }
        ]
    },
    observabilityEvents: [],
    devSpendConfig: {
        actual_dev_spend_usd: null,
        notes: 'Manual entry required.'
    }
});

assert.strictEqual(data.latency.p50_ms, 780);
assert.strictEqual(data.latency.p95_ms, 1250);
assert.strictEqual(data.projected_production_cost.scenarios.length, 3);
assert.ok(data.bottleneck_analysis.length >= 2);
assert.ok(Array.isArray(data.notes));
assert.ok(data.notes.some((note) => /manually entered|manual/i.test(note)));

const markdown = report.buildMarkdownReport(data);
assert.ok(markdown.includes('p50 latency'));
assert.ok(markdown.includes('p95 latency'));
assert.ok(markdown.includes('Bottleneck Analysis'));

console.log('copilot_cost_latency_report.test.js: 2 tests passed');
