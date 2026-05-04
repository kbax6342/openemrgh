#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const CASES_PATH = path.join(__dirname, 'clinical_copilot_golden_cases.json');

function readCases() {
    return JSON.parse(fs.readFileSync(CASES_PATH, 'utf8'));
}

function includesNormalized(haystack, needle) {
    return String(haystack || '').toLowerCase().includes(String(needle || '').toLowerCase());
}

function formatStatus(passed) {
    return passed ? 'PASS' : 'FAIL';
}

function summarizeCategories(cases, results) {
    const summary = new Map();

    cases.forEach((testCase, index) => {
        const result = results[index];
        (testCase.labels || []).forEach((label) => {
            const current = summary.get(label) || { total: 0, passed: 0 };
            current.total += 1;
            if (result.passed) {
                current.passed += 1;
            }
            summary.set(label, current);
        });
    });

    return summary;
}

function evaluateCase(testCase) {
    const fixture = testCase.fixtureResponse || {};
    const text = String(fixture.text || '');
    const sourceCategories = Array.isArray(fixture.sourceCategories) ? fixture.sourceCategories : [];
    const auditEvents = Array.isArray(fixture.auditEvents) ? fixture.auditEvents : [];
    const failures = [];

    if ((testCase.expectedBehavior || '') !== (fixture.behavior || '')) {
        failures.push(`expected behavior "${testCase.expectedBehavior}" but got "${fixture.behavior || ''}"`);
    }

    (testCase.mustContain || []).forEach((value) => {
        if (!includesNormalized(text, value)) {
            failures.push(`missing required text: ${value}`);
        }
    });

    (testCase.mustNotContain || []).forEach((value) => {
        if (includesNormalized(text, value)) {
            failures.push(`found forbidden text: ${value}`);
        }
    });

    (testCase.expectedSources || []).forEach((source) => {
        if (!sourceCategories.includes(source)) {
            failures.push(`missing expected source category: ${source}`);
        }
    });

    (testCase.expectedEvents || []).forEach((eventName) => {
        if (!auditEvents.includes(eventName)) {
            failures.push(`missing expected audit event: ${eventName}`);
        }
    });

    return {
        id: testCase.id,
        title: testCase.title,
        role: testCase.role,
        passed: failures.length === 0,
        failures
    };
}

function printCaseResults(results) {
    console.log('Clinical Co-Pilot Golden Eval Results');
    console.log('='.repeat(38));

    results.forEach((result) => {
        console.log(`${formatStatus(result.passed)}  ${result.id} (${result.role})`);
        console.log(`      ${result.title}`);
        if (!result.passed) {
            result.failures.forEach((failure) => {
                console.log(`      - ${failure}`);
            });
        }
    });
}

function printCategorySummary(categorySummary) {
    console.log('\nCategory Coverage');
    console.log('-----------------');

    Array.from(categorySummary.entries())
        .sort((left, right) => left[0].localeCompare(right[0]))
        .forEach(([label, stats]) => {
            console.log(`${label}: ${stats.passed}/${stats.total} passing`);
        });
}

function printTotals(results) {
    const total = results.length;
    const passed = results.filter((result) => result.passed).length;
    const failed = total - passed;

    console.log('\nSummary');
    console.log('-------');
    console.log(`Total cases: ${total}`);
    console.log(`Passed: ${passed}`);
    console.log(`Failed: ${failed}`);
}

function main() {
    const cases = readCases();
    const results = cases.map(evaluateCase);
    const categorySummary = summarizeCategories(cases, results);
    const hasFailures = results.some((result) => !result.passed);

    printCaseResults(results);
    printCategorySummary(categorySummary);
    printTotals(results);

    process.exitCode = hasFailures ? 1 : 0;
}

main();

