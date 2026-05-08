#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const observability = require('../observability/copilot_observability.js');

const ROOT = process.cwd();
const EVAL_RESULTS_PATH = path.join(ROOT, 'interface/ai_copilot/evals/results/clinical_copilot_eval_results.latest.json');
const OBSERVABILITY_EVENTS_PATH = path.join(ROOT, 'interface/ai_copilot/observability/results/observability_events.latest.json');
const OBSERVABILITY_SUMMARY_PATH = path.join(ROOT, 'interface/ai_copilot/observability/results/observability_summary.latest.json');
const DEV_SPEND_CONFIG_PATH = path.join(ROOT, 'interface/ai_copilot/reports/dev_spend_config.json');
const REPORT_JSON_PATH = path.join(ROOT, 'interface/ai_copilot/reports/copilot_cost_latency_report.json');
const REPORT_MD_PATH = path.join(ROOT, 'interface/ai_copilot/reports/copilot_cost_latency_report.md');

function readJson(filePath, fallback) {
    try {
        return JSON.parse(fs.readFileSync(filePath, 'utf8'));
    } catch (error) {
        return fallback;
    }
}

function ensureDir(filePath) {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
}

function numberOrZero(value) {
    return Number.isFinite(Number(value)) ? Number(value) : 0;
}

function currency(value) {
    return `$${numberOrZero(value).toFixed(6)}`;
}

function buildReportData(options = {}) {
    const evalResults = options.evalResults || readJson(EVAL_RESULTS_PATH, {});
    const observabilityEvents = options.observabilityEvents || readJson(OBSERVABILITY_EVENTS_PATH, []);
    const summary = options.observabilitySummary || readJson(OBSERVABILITY_SUMMARY_PATH, observability.summarizeObservabilityEvents(observabilityEvents));
    const devSpendConfig = options.devSpendConfig || readJson(DEV_SPEND_CONFIG_PATH, {
        actual_dev_spend_usd: null,
        notes: 'Fill this from billing dashboard, credits used, or manual project tracking.'
    });

    const averageTokensPerRequest = numberOrZero(summary && summary.costs && summary.costs.average_total_tokens);
    const averageCostPerRequest = numberOrZero(summary && summary.costs && summary.costs.average_request_cost_usd);
    const scenarios = [100, 1000, 10000].map((monthlyRequests) => ({
        monthly_requests: monthlyRequests,
        average_daily_requests: Number((monthlyRequests / 30).toFixed(2)),
        average_tokens_per_request: averageTokensPerRequest,
        average_cost_per_request_usd: averageCostPerRequest,
        reranker_cost_per_request_usd: 0,
        embedding_cost_per_request_usd: 0,
        storage_vector_cost_note: 'No vector or storage billing export is connected in this local demo.',
        projected_monthly_cost_usd: Number((averageCostPerRequest * monthlyRequests).toFixed(6))
    }));

    const bottlenecks = Array.isArray(summary && summary.bottlenecks) ? summary.bottlenecks : [];
    const notes = [];
    if (devSpendConfig.actual_dev_spend_usd === null || devSpendConfig.actual_dev_spend_usd === undefined) {
        notes.push('Actual dev spend must be manually entered because no billing export is connected.');
    }
    if (averageCostPerRequest === 0) {
        notes.push('Projected production cost currently reflects local demo fallback or missing token usage data, so live provider billing may be higher.');
    }
    if (!summary || !summary.latency_ms || numberOrZero(summary.latency_ms.p95) === 0) {
        notes.push('Latency metrics were derived from the latest safe local observability snapshot and may reflect eval/demo timings rather than live production traffic.');
    }

    return {
        generated_at: new Date().toISOString(),
        sources: {
            eval_results_path: EVAL_RESULTS_PATH,
            observability_events_path: OBSERVABILITY_EVENTS_PATH,
            observability_summary_path: OBSERVABILITY_SUMMARY_PATH,
            dev_spend_config_path: DEV_SPEND_CONFIG_PATH
        },
        actual_dev_spend: {
            actual_dev_spend_usd: devSpendConfig.actual_dev_spend_usd,
            notes: devSpendConfig.notes || 'Actual dev spend must be manually entered because no billing export is connected.'
        },
        projected_production_cost: {
            average_tokens_per_request: averageTokensPerRequest,
            average_cost_per_request_usd: averageCostPerRequest,
            scenarios
        },
        latency: {
            p50_ms: numberOrZero(summary && summary.latency_ms && summary.latency_ms.p50),
            p95_ms: numberOrZero(summary && summary.latency_ms && summary.latency_ms.p95),
            average_ms: numberOrZero(summary && summary.latency_ms && summary.latency_ms.average),
            encounter_count: numberOrZero(summary && summary.encounter_count)
        },
        bottleneck_analysis: bottlenecks.map((entry) => ({
            step_name: entry.step_name,
            average_ms: numberOrZero(entry.average_ms),
            p95_ms: numberOrZero(entry.p95_ms),
            note: entry.step_name === 'FinalResponse'
                ? 'Draft generation or final assembly remains the slowest stage in current local observability data.'
                : entry.step_name === 'IntakeExtractorWorker'
                    ? 'Document extraction gets slower when PDF parsing and clinician-review staging are involved.'
                    : entry.step_name === 'EvidenceRetrieverWorker'
                        ? 'Retrieval latency may increase further if external reranking is enabled in production.'
                        : 'Review this step first when optimizing p95 latency.'
        })),
        eval_context: {
            case_count: numberOrZero(evalResults && evalResults.case_count),
            passed_count: numberOrZero(evalResults && evalResults.passed_count),
            failed_count: numberOrZero(evalResults && evalResults.failed_count),
            pass_rate: numberOrZero(evalResults && evalResults.pass_rate)
        },
        notes
    };
}

function buildMarkdownReport(report) {
    const lines = [];
    lines.push('# OpenEMR AI Co-Pilot Cost and Latency Report');
    lines.push('');
    lines.push(`Generated: ${report.generated_at}`);
    lines.push('');
    lines.push('## Actual Dev Spend');
    lines.push('');
    if (report.actual_dev_spend.actual_dev_spend_usd === null || report.actual_dev_spend.actual_dev_spend_usd === undefined) {
        lines.push('- Actual dev spend must be manually entered because no billing export is connected.');
    } else {
        lines.push(`- Actual dev spend: ${currency(report.actual_dev_spend.actual_dev_spend_usd)}`);
    }
    lines.push(`- Notes: ${report.actual_dev_spend.notes}`);
    lines.push('');
    lines.push('## Projected Production Cost');
    lines.push('');
    lines.push(`- Average tokens per request: ${report.projected_production_cost.average_tokens_per_request}`);
    lines.push(`- Average cost per request: ${currency(report.projected_production_cost.average_cost_per_request_usd)}`);
    report.projected_production_cost.scenarios.forEach((scenario) => {
        lines.push(`- ${scenario.monthly_requests} requests/month: ${currency(scenario.projected_monthly_cost_usd)} projected monthly cost (${scenario.average_daily_requests} requests/day average)`);
    });
    lines.push('');
    lines.push('## Latency');
    lines.push('');
    lines.push(`- p50 latency: ${report.latency.p50_ms} ms`);
    lines.push(`- p95 latency: ${report.latency.p95_ms} ms`);
    lines.push(`- Average latency: ${report.latency.average_ms} ms`);
    lines.push(`- Encounter count in latest snapshot: ${report.latency.encounter_count}`);
    lines.push('');
    lines.push('## Bottleneck Analysis');
    lines.push('');
    if (report.bottleneck_analysis.length === 0) {
        lines.push('- No bottleneck data was available in the latest observability snapshot.');
    } else {
        report.bottleneck_analysis.forEach((entry) => {
            lines.push(`- ${entry.step_name}: avg ${entry.average_ms} ms, p95 ${entry.p95_ms} ms. ${entry.note}`);
        });
    }
    lines.push('');
    lines.push('## Eval Context');
    lines.push('');
    lines.push(`- Case count: ${report.eval_context.case_count}`);
    lines.push(`- Passed: ${report.eval_context.passed_count}`);
    lines.push(`- Failed: ${report.eval_context.failed_count}`);
    lines.push(`- Pass rate: ${(report.eval_context.pass_rate * 100).toFixed(1)}%`);
    lines.push('');
    lines.push('## Notes');
    lines.push('');
    if (report.notes.length === 0) {
        lines.push('- None');
    } else {
        report.notes.forEach((note) => lines.push(`- ${note}`));
    }
    lines.push('');
    return lines.join('\n');
}

function writeReport(report, options = {}) {
    const jsonPath = options.jsonPath || REPORT_JSON_PATH;
    const markdownPath = options.markdownPath || REPORT_MD_PATH;
    ensureDir(jsonPath);
    ensureDir(markdownPath);
    fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2) + '\n', 'utf8');
    fs.writeFileSync(markdownPath, buildMarkdownReport(report), 'utf8');
    return { jsonPath, markdownPath };
}

function main() {
    const report = buildReportData();
    const output = writeReport(report);
    console.log('OpenEMR AI Co-Pilot cost/latency report generated.');
    console.log(`JSON: ${output.jsonPath}`);
    console.log(`Markdown: ${output.markdownPath}`);
}

if (require.main === module) {
    main();
}

module.exports = {
    buildMarkdownReport,
    buildReportData,
    writeReport
};
