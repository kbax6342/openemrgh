#!/usr/bin/env node
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const ROOT = process.cwd();
const thresholdsPath = path.join(ROOT, 'interface/ai_copilot/evals/eval-thresholds.json');
const thresholds = JSON.parse(fs.readFileSync(thresholdsPath, 'utf8'));
const latestResultsPath = path.join(ROOT, thresholds.latest_results_path);

function recomputeSummary(results) {
    const rubricNames = Object.keys(results.rubric_summary || {});
    const rubricSummary = {};
    rubricNames.forEach((rubric) => {
        rubricSummary[rubric] = { passed: 0, failed: 0 };
    });

    results.results.forEach((result) => {
        rubricNames.forEach((rubric) => {
            const rubricResult = result.rubrics[rubric];
            if (rubricResult && rubricResult.passed) {
                rubricSummary[rubric].passed += 1;
            } else {
                rubricSummary[rubric].failed += 1;
            }
        });
    });

    const passedCount = results.results.filter((result) => result.passed).length;
    results.passed_count = passedCount;
    results.failed_count = results.results.length - passedCount;
    results.pass_rate = results.results.length === 0 ? 0 : passedCount / results.results.length;
    results.rubric_summary = rubricSummary;
}

function main() {
    if (!fs.existsSync(latestResultsPath)) {
        throw new Error(`Latest eval results not found: ${latestResultsPath}`);
    }

    const latest = JSON.parse(fs.readFileSync(latestResultsPath, 'utf8'));
    const degraded = JSON.parse(JSON.stringify(latest));
    let changed = 0;

    degraded.results.forEach((result) => {
        if (changed >= 10) {
            return;
        }
        const rubric = result && result.rubrics ? result.rubrics.citation_present : null;
        if (!rubric || rubric.expected !== true) {
            return;
        }
        rubric.passed = false;
        rubric.actual = false;
        rubric.failures = ['expected citation_present=true, but actual=false', 'intentional regression removed required citation metadata'];
        rubric.suggestedFix = 'Restore machine-readable citation metadata for this clinical claim.';
        result.passed = false;
        result.failures = result.failures || [];
        result.failures.push({
            rubric: 'citation_present',
            reason: 'intentional regression removed required citation metadata',
            suggestedFix: 'Restore machine-readable citation metadata for this clinical claim.'
        });
        changed += 1;
    });

    if (changed === 0) {
        throw new Error('Unable to create an intentional regression because no citation-positive cases were found.');
    }

    recomputeSummary(degraded);

    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'clinical-copilot-eval-regression-'));
    const tempResultsPath = path.join(tempDir, 'clinical_copilot_eval_results.regression.json');
    fs.writeFileSync(tempResultsPath, JSON.stringify(degraded, null, 2) + '\n', 'utf8');

    const gatePath = path.join(ROOT, 'interface/ai_copilot/evals/check-eval-gate.js');
    const run = spawnSync(process.execPath, [gatePath], {
        cwd: ROOT,
        env: {
            ...process.env,
            AI_COPILOT_EVAL_RESULTS_PATH: tempResultsPath
        },
        encoding: 'utf8'
    });

    if (run.stdout) {
        process.stdout.write(run.stdout);
    }
    if (run.stderr) {
        process.stderr.write(run.stderr);
    }

    try {
        fs.rmSync(tempDir, { recursive: true, force: true });
    } catch (error) {
        // Ignore temp cleanup errors.
    }

    if (run.status === 0) {
        console.error('Intentional regression unexpectedly passed the eval gate.');
        process.exitCode = 1;
        return;
    }

    console.log('Intentional regression correctly failed the eval gate.');
}

main();
