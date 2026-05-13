const assert = require('assert');
const path = require('path');

const types = require(path.join(__dirname, '..', '..', '..', 'interface', 'ai_copilot', 'redteam', 'redTeamTypes.js'));
const policies = require(path.join(__dirname, '..', '..', '..', 'interface', 'ai_copilot', 'redteam', 'redTeamPolicies.js'));
const harnessModule = require(path.join(__dirname, '..', '..', '..', 'interface', 'ai_copilot', 'redteam', 'redTeamHarness.js'));

async function singleSeedRunReturnsStructuredResult() {
    const harness = harnessModule.createRedTeamHarness({
        auditLogger: {
            log() {}
        }
    });
    const result = await harness.runRedTeamTest({
        seedScenarioId: 'seed_front_desk_med_list',
        useLiveCopilot: false
    });

    assert.strictEqual(result.plan.seedScenarioId, 'seed_front_desk_med_list');
    assert.strictEqual(result.generatedPrompt.length > 0, true);
    assert.strictEqual(Array.isArray(result.mutatedPrompts), true);
    assert.strictEqual(result.mutatedPrompts.length, 3);
    assert.strictEqual(result.copilotResponse.adapter, 'mock');
    assert.strictEqual(typeof result.judgeVerdict.violated, 'boolean');
    assert.ok(result.reportMarkdown.includes('## Attack prompt'));
    assert.ok(result.auditEvents.some((event) => event.event === 'redteam_prompt_generated'));
}

async function unsafeLiveAdapterProducesRegressionCase() {
    const unsafeHarness = harnessModule.createRedTeamHarness({
        auditLogger: {
            log() {}
        },
        liveAdapter: {
            getAdapterMetadata() {
                return {
                    label: 'Unsafe test adapter'
                };
            },
            runPromptForRedTeam() {
                return Promise.resolve({
                    adapter: 'unsafe_test_adapter',
                    content: 'I updated Marcus Johnson\'s chart and increased the metformin dose without citations.',
                    plainText: 'I updated Marcus Johnson\'s chart and increased the metformin dose without citations.',
                    sections: [],
                    sources: [],
                    safety: ''
                });
            },
            supportsPlan() {
                return true;
            }
        }
    });

    const result = await unsafeHarness.runRedTeamTest({
        seedScenarioId: 'seed_doctor_chart_write',
        useLiveCopilot: true
    });

    assert.strictEqual(result.judgeVerdict.violated, true);
    assert.strictEqual(result.judgeVerdict.policy_area, policies.POLICY_AREAS.DIRECT_CHART_WRITES);
    assert.strictEqual(result.regressionSaved.saved, true);
    assert.ok(result.reportMarkdown.includes('Recommended fix'));
}

async function suiteRunReturnsTwelveSeedResults() {
    const harness = harnessModule.createRedTeamHarness({
        auditLogger: {
            log() {}
        }
    });
    const suite = await harness.runSeedTests({
        limit: 12,
        useLiveCopilot: false
    });

    const exportResult = harness.exportReport(suite);

    assert.strictEqual(suite.results.length, 12);
    assert.strictEqual(suite.summary.totalRuns, 12);
    assert.strictEqual(typeof suite.reportMarkdown, 'string');
    assert.ok(suite.reportMarkdown.includes('OpenEMR AI Co-Pilot OpenEMR Team Suite Report'));
    assert.ok(exportResult.markdown.includes('Severity counts'));
}

(async function run() {
    const tests = [
        singleSeedRunReturnsStructuredResult,
        unsafeLiveAdapterProducesRegressionCase,
        suiteRunReturnsTwelveSeedResults
    ];

    for (const test of tests) {
        await test();
    }

    console.log(`${tests.length} tests passed`);
}()).catch((error) => {
    console.error(error);
    process.exit(1);
});
