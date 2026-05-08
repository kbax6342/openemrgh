#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = process.cwd();
const DEFAULT_THRESHOLDS_PATH = path.join(ROOT, 'interface/ai_copilot/evals/eval-thresholds.json');
const DEFAULT_DATASET_PATH = path.join(ROOT, 'interface/ai_copilot/evals/clinical_copilot_golden_cases.json');
const DEFAULT_JUDGE_CONFIG_PATH = path.join(ROOT, 'interface/ai_copilot/evals/clinical_copilot_judge_config.json');

function resolvePath(filePath) {
    if (!filePath) {
        return '';
    }
    return path.isAbsolute(filePath) ? filePath : path.join(ROOT, filePath);
}

function readJson(filePath) {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function exists(filePath) {
    return fs.existsSync(filePath);
}

function loadThresholds() {
    const filePath = resolvePath(process.env.AI_COPILOT_EVAL_THRESHOLDS_PATH || DEFAULT_THRESHOLDS_PATH);
    if (!exists(filePath)) {
        throw new Error(`Threshold config not found: ${filePath}`);
    }
    return { filePath, data: readJson(filePath) };
}

function loadJudgeConfig() {
    const filePath = resolvePath(process.env.AI_COPILOT_EVAL_JUDGE_CONFIG_PATH || DEFAULT_JUDGE_CONFIG_PATH);
    if (!exists(filePath)) {
        throw new Error(`Judge config not found: ${filePath}`);
    }
    return { filePath, data: readJson(filePath) };
}

function loadDataset() {
    const filePath = resolvePath(process.env.AI_COPILOT_EVAL_DATASET_PATH || DEFAULT_DATASET_PATH);
    if (!exists(filePath)) {
        throw new Error(`Dataset not found: ${filePath}`);
    }
    const data = readJson(filePath);
    if (!Array.isArray(data)) {
        throw new Error('Dataset must be a JSON array.');
    }
    return { filePath, data };
}

function loadResults(filePath) {
    if (!exists(filePath)) {
        throw new Error(`Results file not found: ${filePath}`);
    }
    return readJson(filePath);
}

function percentage(value) {
    return `${Math.round(value * 100)}%`;
}

function computeRubricPassRates(results, requiredRubrics) {
    const totals = {};
    requiredRubrics.forEach((rubric) => {
        totals[rubric] = { passed: 0, failed: 0, rate: 0 };
    });

    (Array.isArray(results.results) ? results.results : []).forEach((result) => {
        requiredRubrics.forEach((rubric) => {
            const rubricResult = result && result.rubrics ? result.rubrics[rubric] : null;
            if (!rubricResult || typeof rubricResult.passed !== 'boolean') {
                totals[rubric].failed += 1;
                return;
            }
            if (rubricResult.passed) {
                totals[rubric].passed += 1;
            } else {
                totals[rubric].failed += 1;
            }
        });
    });

    const caseCount = Array.isArray(results.results) ? results.results.length : 0;
    requiredRubrics.forEach((rubric) => {
        totals[rubric].rate = caseCount === 0 ? 0 : totals[rubric].passed / caseCount;
    });
    return totals;
}

function computeDatasetPassRate(results) {
    const caseCount = Array.isArray(results.results) ? results.results.length : 0;
    const passedCount = (Array.isArray(results.results) ? results.results : []).filter((result) => result && result.passed === true).length;
    return {
        caseCount,
        passedCount,
        failedCount: caseCount - passedCount,
        passRate: caseCount === 0 ? 0 : passedCount / caseCount
    };
}

function buildSuggestions(failures) {
    const suggestions = new Set();
    failures.forEach((failure) => {
        const value = `${failure.reason} ${failure.detail || ''}`.toLowerCase();
        if (value.includes('citation')) {
            suggestions.add('Check uncited clinical claims and source citation metadata in lab_pdf, intake_form, and RAG outputs.');
        }
        if (value.includes('schema')) {
            suggestions.add('Check strict extraction validation and downgrade invalid extractions to review_required or failed.');
        }
        if (value.includes('factually_consistent') || value.includes('pass rate')) {
            suggestions.add('Review the failing fixtures and rerun: node interface/ai_copilot/evals/run-clinical-copilot-evals.js');
        }
        if (value.includes('no_phi_in_logs') || value.includes('phi')) {
            suggestions.add('Remove DOB, phone, email, address, insurance identifiers, raw document text, and hidden/system content from logs.');
        }
        if (value.includes('result files')) {
            suggestions.add('Ensure the eval runner completed and wrote latest JSON plus markdown summary artifacts before the gate runs.');
        }
        if (value.includes('dataset')) {
            suggestions.add('Restore the 50-case dataset and rerun the eval suite before merging.');
        }
    });
    suggestions.add('Re-run: node interface/ai_copilot/evals/run-clinical-copilot-evals.js');
    suggestions.add('Re-run: node interface/ai_copilot/evals/check-eval-gate.js');
    return Array.from(suggestions);
}

function fail(failures, warnings = []) {
    console.error('Clinical Co-Pilot Eval Gate: FAIL');
    console.error('');
    console.error('Reason:');
    failures.forEach((failure) => {
        if (failure.detail) {
            console.error(`- ${failure.reason} ${failure.detail}`);
        } else {
            console.error(`- ${failure.reason}`);
        }
    });
    if (warnings.length > 0) {
        console.error('');
        console.error('Warnings:');
        warnings.forEach((warning) => console.error(`- ${warning}`));
    }
    console.error('');
    console.error('Suggested fixes:');
    buildSuggestions(failures).forEach((suggestion) => console.error(`- ${suggestion}`));
    process.exitCode = 1;
}

function pass(summary, warnings = []) {
    console.log('Clinical Co-Pilot Eval Gate: PASS');
    console.log('');
    console.log(`Dataset cases: ${summary.caseCount}`);
    console.log(`Dataset pass rate: ${percentage(summary.passRate)}`);
    summary.requiredRubrics.forEach((rubric) => {
        const stats = summary.rubricRates[rubric];
        console.log(`- ${rubric}: ${percentage(stats.rate)} (${stats.passed}/${summary.caseCount})`);
    });
    if (warnings.length > 0) {
        console.log('');
        console.log('Warnings:');
        warnings.forEach((warning) => console.log(`- ${warning}`));
    }
}

function main() {
    const { data: thresholds } = loadThresholds();
    const { data: judgeConfig } = loadJudgeConfig();
    const { data: dataset } = loadDataset();

    const latestResultsPath = resolvePath(process.env.AI_COPILOT_EVAL_RESULTS_PATH || thresholds.latest_results_path);
    const baselinePath = resolvePath(process.env.AI_COPILOT_EVAL_BASELINE_PATH || thresholds.baseline_path);
    const resultsDirectory = resolvePath((judgeConfig.output && judgeConfig.output.resultsDirectory) || 'interface/ai_copilot/evals/results');
    const markdownSummaryPath = path.join(resultsDirectory, 'clinical_copilot_eval_summary.md');

    const failures = [];
    const warnings = [];

    if (!exists(latestResultsPath)) {
        failures.push({ reason: 'Required result files are missing.', detail: `Missing latest results JSON at ${latestResultsPath}.` });
        fail(failures, warnings);
        return;
    }
    if (!exists(markdownSummaryPath)) {
        failures.push({ reason: 'Required result files are missing.', detail: `Missing markdown summary at ${markdownSummaryPath}.` });
        fail(failures, warnings);
        return;
    }

    const latestResults = loadResults(latestResultsPath);
    const requiredRubrics = Array.isArray(thresholds.required_rubrics) ? thresholds.required_rubrics : [];
    const rubricRates = computeRubricPassRates(latestResults, requiredRubrics);
    const datasetStats = computeDatasetPassRate(latestResults);

    if (dataset.length < Number(thresholds.minimum_case_count || 0)) {
        failures.push({ reason: 'Eval dataset is too small.', detail: `Found ${dataset.length} cases, expected at least ${thresholds.minimum_case_count}.` });
    }
    if (latestResults.case_count !== dataset.length) {
        failures.push({ reason: 'Eval result case count does not match dataset case count.', detail: `results.case_count=${latestResults.case_count}, dataset=${dataset.length}.` });
    }
    if (!Array.isArray(latestResults.results)) {
        failures.push({ reason: 'Eval results are malformed.', detail: 'results array is missing.' });
    } else if (latestResults.results.length !== dataset.length) {
        failures.push({ reason: 'Eval result case array length does not match dataset case count.', detail: `results.length=${latestResults.results.length}, dataset=${dataset.length}.` });
    }
    if (latestResults.case_count < Number(thresholds.minimum_case_count || 0)) {
        failures.push({ reason: 'Eval result case count is below minimum threshold.', detail: `case_count=${latestResults.case_count}, minimum=${thresholds.minimum_case_count}.` });
    }
    if (!latestResults.rubric_summary || typeof latestResults.rubric_summary !== 'object') {
        failures.push({ reason: 'Eval results are missing rubric summary.' });
    }

    if (datasetStats.passRate < Number(thresholds.minimum_total_pass_rate || 0)) {
        failures.push({
            reason: 'Total pass rate is below threshold.',
            detail: `${percentage(datasetStats.passRate)} is below ${percentage(Number(thresholds.minimum_total_pass_rate || 0))}.`
        });
    }

    requiredRubrics.forEach((rubric) => {
        const threshold = Number((thresholds.minimum_rubric_pass_rates || {})[rubric]);
        const stats = rubricRates[rubric];
        if (!stats) {
            failures.push({ reason: 'Required rubric missing from computed results.', detail: rubric });
            return;
        }
        if (!latestResults.rubric_summary || !latestResults.rubric_summary[rubric]) {
            failures.push({ reason: 'Required rubric missing from eval result summary.', detail: rubric });
            return;
        }
        if (stats.rate < threshold) {
            failures.push({
                reason: `${rubric} pass rate is below threshold.`,
                detail: `${percentage(stats.rate)} is below ${percentage(threshold)}.`
            });
        }
    });

    const missingRubricsInCases = [];
    (Array.isArray(latestResults.results) ? latestResults.results : []).forEach((result) => {
        requiredRubrics.forEach((rubric) => {
            if (!result || !result.rubrics || !result.rubrics[rubric]) {
                missingRubricsInCases.push(`${result && result.id ? result.id : 'unknown_case'}:${rubric}`);
            }
        });
    });
    if (missingRubricsInCases.length > 0) {
        failures.push({
            reason: 'One or more case results are missing required rubric entries.',
            detail: missingRubricsInCases.slice(0, 5).join(', ') + (missingRubricsInCases.length > 5 ? ' ...' : '')
        });
    }

    if (exists(baselinePath)) {
        const baselineResults = loadResults(baselinePath);
        const baselineRubricRates = computeRubricPassRates(baselineResults, requiredRubrics);
        const maxRegression = Number(thresholds.maximum_allowed_rubric_regression || 0);
        requiredRubrics.forEach((rubric) => {
            const currentRate = rubricRates[rubric].rate;
            const baselineRate = baselineRubricRates[rubric] ? baselineRubricRates[rubric].rate : null;
            if (baselineRate === null) {
                warnings.push(`Baseline is missing rubric ${rubric}; regression check skipped for that rubric.`);
                return;
            }
            const regression = baselineRate - currentRate;
            if (regression > maxRegression) {
                failures.push({
                    reason: `${rubric} regressed beyond the allowed threshold.`,
                    detail: `Current ${percentage(currentRate)} vs baseline ${percentage(baselineRate)} (regression ${percentage(regression)}, max allowed ${percentage(maxRegression)}).`
                });
            }
        });
    } else {
        warnings.push(`Baseline not found at ${baselinePath}. Absolute thresholds were enforced without regression comparison.`);
    }

    if (failures.length > 0) {
        fail(failures, warnings);
        return;
    }

    pass({
        caseCount: datasetStats.caseCount,
        passRate: datasetStats.passRate,
        rubricRates,
        requiredRubrics
    }, warnings);
}

main();
