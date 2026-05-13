(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('./redTeamTypes.js'));
        return;
    }

    root.OpenEMRCopilotRedTeamPanel = factory(root.OpenEMRCopilotRedTeamTypes);
}(typeof globalThis !== 'undefined' ? globalThis : this, function (Types) {
    'use strict';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function severityClassName(severity) {
        return 'copilot-redteam-badge-' + Types.normalizeSeverity(severity);
    }

    function formatVerdictText(verdict) {
        if (!verdict) {
            return 'No judge verdict yet.';
        }

        return verdict.violated
            ? 'Violation detected: ' + (verdict.reason || verdict.policy_area || 'Policy issue detected.')
            : (verdict.reason || 'No policy violation detected.');
    }

    function createPanelMarkup(seedScenarios) {
        const options = (seedScenarios || []).map(function (scenario) {
            return '<option value="' + escapeHtml(scenario.id) + '">' + escapeHtml(scenario.title) + '</option>';
        }).join('');

        return [
            '<div class="copilot-redteam-shell">',
            '    <p class="copilot-redteam-intro">Local synthetic defensive eval harness only. Uses Marcus Johnson or seeded demo patients, never real PHI, and never performs chart writes or external attacks.</p>',
            '    <div class="copilot-redteam-grid">',
            '        <section class="copilot-redteam-card">',
            '            <div class="copilot-redteam-field">',
            '                <label class="copilot-redteam-label" for="copilot-redteam-scenario">Seed scenario</label>',
            '                <select id="copilot-redteam-scenario" class="form-control copilot-select copilot-redteam-select">' + options + '</select>',
            '            </div>',
            '            <div class="copilot-redteam-plan-grid">',
            '                <div class="copilot-redteam-plan-item"><span class="copilot-redteam-plan-label">Attack category</span><strong id="copilot-redteam-category"></strong></div>',
            '                <div class="copilot-redteam-plan-item"><span class="copilot-redteam-plan-label">Role</span><strong id="copilot-redteam-role"></strong></div>',
            '                <div class="copilot-redteam-plan-item"><span class="copilot-redteam-plan-label">Workflow</span><strong id="copilot-redteam-workflow"></strong></div>',
            '                <div class="copilot-redteam-plan-item"><span class="copilot-redteam-plan-label">Patient</span><strong id="copilot-redteam-patient"></strong></div>',
            '            </div>',
            '            <p id="copilot-redteam-expected" class="copilot-redteam-expected"></p>',
            '            <div class="copilot-redteam-status-row">',
            '                <span id="copilot-redteam-adapter-badge" class="copilot-redteam-badge copilot-redteam-badge-neutral"></span>',
            '                <span class="copilot-redteam-badge copilot-redteam-badge-neutral">Draft-only harness</span>',
            '            </div>',
            '            <div class="copilot-redteam-actions">',
            '                <button type="button" id="copilot-redteam-run" class="copilot-redteam-button">Run OpenEMR Team Test</button>',
            '                <button type="button" id="copilot-redteam-run-suite" class="copilot-redteam-button copilot-redteam-button-secondary">Run 12 Seed Tests</button>',
            '                <button type="button" id="copilot-redteam-export" class="copilot-redteam-button copilot-redteam-button-secondary" disabled>Export Report</button>',
            '            </div>',
            '            <p id="copilot-redteam-status" class="copilot-redteam-helper">Ready to run the selected synthetic scenario.</p>',
            '        </section>',
            '        <section class="copilot-redteam-card">',
            '            <h3 class="copilot-redteam-card-title">OpenEMR Team Agent</h3>',
            '            <label class="copilot-redteam-label" for="copilot-redteam-generated-prompt">Generated prompt</label>',
            '            <textarea id="copilot-redteam-generated-prompt" class="copilot-redteam-output" rows="6" readonly></textarea>',
            '            <div class="copilot-redteam-agent-block">',
            '                <div class="copilot-redteam-agent-heading">Mutation Agent</div>',
            '                <ul id="copilot-redteam-mutations" class="copilot-redteam-list copilot-redteam-list-tight"></ul>',
            '            </div>',
            '        </section>',
            '    </div>',
            '    <div class="copilot-redteam-grid">',
            '        <section class="copilot-redteam-card">',
            '            <h3 class="copilot-redteam-card-title">Runner Agent</h3>',
            '            <div class="copilot-redteam-meta-row">',
            '                <span class="copilot-redteam-plan-label">Selected execution prompt</span>',
            '                <strong id="copilot-redteam-selected-label">Primary prompt</strong>',
            '            </div>',
            '            <pre id="copilot-redteam-response" class="copilot-redteam-pre">No co-pilot response captured yet.</pre>',
            '            <div class="copilot-redteam-agent-block">',
            '                <div class="copilot-redteam-agent-heading">Attempts</div>',
            '                <ul id="copilot-redteam-attempts" class="copilot-redteam-list"></ul>',
            '            </div>',
            '        </section>',
            '        <section class="copilot-redteam-card">',
            '            <h3 class="copilot-redteam-card-title">Judge / Regression / Reporter</h3>',
            '            <div class="copilot-redteam-status-row">',
            '                <span class="copilot-redteam-plan-label">Judge verdict</span>',
            '                <span id="copilot-redteam-severity" class="copilot-redteam-badge copilot-redteam-badge-neutral">none</span>',
            '            </div>',
            '            <p id="copilot-redteam-verdict" class="copilot-redteam-helper">No judge verdict yet.</p>',
            '            <p id="copilot-redteam-policy" class="copilot-redteam-helper">Policy area: not evaluated.</p>',
            '            <p id="copilot-redteam-regression" class="copilot-redteam-helper">Regression Agent: no regression saved yet.</p>',
            '            <p id="copilot-redteam-report" class="copilot-redteam-helper">Reporter Agent: report not generated yet.</p>',
            '        </section>',
            '    </div>',
            '    <section class="copilot-redteam-card">',
            '        <h3 class="copilot-redteam-card-title">Seed Suite Results</h3>',
            '        <p id="copilot-redteam-suite-summary" class="copilot-redteam-helper">Run the 12 seed tests to see batch results.</p>',
            '        <div id="copilot-redteam-suite-results" class="copilot-redteam-suite-results"></div>',
            '    </section>',
            '</div>'
        ].join('');
    }

    function createRedTeamPanel(options) {
        const config = options && typeof options === 'object' ? options : {};
        const root = config.root || null;
        const harness = config.harness || null;

        if (!root || !harness) {
            return null;
        }

        const seedScenarios = harness.listSeedScenarios();
        root.innerHTML = createPanelMarkup(seedScenarios);

        const elements = {
            scenarioSelect: root.querySelector('#copilot-redteam-scenario'),
            category: root.querySelector('#copilot-redteam-category'),
            role: root.querySelector('#copilot-redteam-role'),
            workflow: root.querySelector('#copilot-redteam-workflow'),
            patient: root.querySelector('#copilot-redteam-patient'),
            expected: root.querySelector('#copilot-redteam-expected'),
            adapterBadge: root.querySelector('#copilot-redteam-adapter-badge'),
            status: root.querySelector('#copilot-redteam-status'),
            runButton: root.querySelector('#copilot-redteam-run'),
            runSuiteButton: root.querySelector('#copilot-redteam-run-suite'),
            exportButton: root.querySelector('#copilot-redteam-export'),
            generatedPrompt: root.querySelector('#copilot-redteam-generated-prompt'),
            mutations: root.querySelector('#copilot-redteam-mutations'),
            selectedLabel: root.querySelector('#copilot-redteam-selected-label'),
            response: root.querySelector('#copilot-redteam-response'),
            attempts: root.querySelector('#copilot-redteam-attempts'),
            severity: root.querySelector('#copilot-redteam-severity'),
            verdict: root.querySelector('#copilot-redteam-verdict'),
            policy: root.querySelector('#copilot-redteam-policy'),
            regression: root.querySelector('#copilot-redteam-regression'),
            report: root.querySelector('#copilot-redteam-report'),
            suiteSummary: root.querySelector('#copilot-redteam-suite-summary'),
            suiteResults: root.querySelector('#copilot-redteam-suite-results')
        };

        const state = {
            busy: false,
            selectedScenarioId: seedScenarios[0] ? seedScenarios[0].id : '',
            latestExportTarget: null
        };

        function setBusy(isBusy, message) {
            state.busy = Boolean(isBusy);
            elements.runButton.disabled = state.busy;
            elements.runSuiteButton.disabled = state.busy;
            if (!state.busy) {
                elements.status.textContent = message || 'Ready to run the selected synthetic scenario.';
                return;
            }
            elements.status.textContent = message || 'Running the local synthetic OpenEMR Team harness...';
        }

        function renderScenarioPreview() {
            const plan = harness.createPlan({
                seedScenarioId: state.selectedScenarioId
            });
            const adapterInfo = harness.getAdapterInfo(plan);

            elements.category.textContent = plan.category;
            elements.role.textContent = plan.role;
            elements.workflow.textContent = plan.workflow;
            elements.patient.textContent = plan.patientName + ' (' + plan.patientId + ')';
            elements.expected.textContent = plan.expectedSafeBehavior || 'Expected safe behavior not provided.';
            elements.generatedPrompt.value = plan.metadata && plan.metadata.prompt ? plan.metadata.prompt : '';
            elements.mutations.innerHTML = '<li class="copilot-redteam-empty">Mutations will be generated after the primary prompt is prepared.</li>';
            elements.adapterBadge.textContent = adapterInfo.label || 'Mock adapter';
            elements.adapterBadge.className = 'copilot-redteam-badge ' + (adapterInfo.usingLive ? 'copilot-redteam-badge-success' : 'copilot-redteam-badge-neutral');
        }

        function renderAttempts(result) {
            const attempts = Array.isArray(result.attempts) ? result.attempts : [];
            if (attempts.length === 0) {
                elements.attempts.innerHTML = '<li class="copilot-redteam-empty">No attempts captured yet.</li>';
                return;
            }

            elements.attempts.innerHTML = attempts.map(function (attempt) {
                const verdict = attempt.verdict || {};
                return '<li><strong>' + escapeHtml(attempt.label || 'attempt') + '</strong> · '
                    + escapeHtml(attempt.response && attempt.response.adapter ? attempt.response.adapter : 'mock')
                    + ' · severity ' + escapeHtml(verdict.severity || 'none')
                    + ' · ' + escapeHtml(verdict.violated ? 'violation' : 'safe') + '</li>';
            }).join('');
        }

        function renderMutations(result) {
            const items = Array.isArray(result.mutatedPrompts) ? result.mutatedPrompts : [];
            if (items.length === 0) {
                elements.mutations.innerHTML = '<li class="copilot-redteam-empty">No mutation prompts were generated.</li>';
                return;
            }

            elements.mutations.innerHTML = items.map(function (prompt) {
                return '<li>' + escapeHtml(prompt) + '</li>';
            }).join('');
        }

        function renderRunResult(result) {
            const responseText = result.copilotResponse && (result.copilotResponse.plainText || result.copilotResponse.content || result.copilotResponse.responseText)
                ? String(result.copilotResponse.plainText || result.copilotResponse.content || result.copilotResponse.responseText)
                : 'No co-pilot response captured yet.';
            const verdict = result.judgeVerdict || null;
            const regression = result.regressionSaved || null;

            elements.generatedPrompt.value = result.generatedPrompt || '';
            renderMutations(result);
            elements.selectedLabel.textContent = result.selectedPrompt && result.selectedPrompt !== result.generatedPrompt
                ? 'Mutation-selected prompt'
                : 'Primary prompt';
            elements.response.textContent = responseText;
            renderAttempts(result);

            elements.severity.textContent = verdict && verdict.severity ? verdict.severity : 'none';
            elements.severity.className = 'copilot-redteam-badge ' + severityClassName(verdict && verdict.severity ? verdict.severity : 'none');
            elements.verdict.textContent = formatVerdictText(verdict);
            elements.policy.textContent = 'Policy area: ' + (verdict && verdict.policy_area ? verdict.policy_area : 'not evaluated');
            elements.regression.textContent = regression && regression.saved
                ? 'Regression Agent: saved regression ' + (regression.record ? regression.record.eval_id : '') + '.'
                : (regression && regression.duplicate
                    ? 'Regression Agent: duplicate regression already existed.'
                    : 'Regression Agent: no regression saved.');
            elements.report.textContent = result.reportMarkdown
                ? 'Reporter Agent: Markdown report ready for export.'
                : 'Reporter Agent: report not generated yet.';
            elements.exportButton.disabled = !result.reportMarkdown;
            state.latestExportTarget = {
                type: 'run',
                value: result
            };
        }

        function renderSuiteResult(suiteResult) {
            const summary = suiteResult && suiteResult.summary ? suiteResult.summary : null;
            if (!summary) {
                elements.suiteSummary.textContent = 'Run the 12 seed tests to see batch results.';
                elements.suiteResults.innerHTML = '';
                return;
            }

            elements.suiteSummary.textContent = '12-seed suite complete. Violations: '
                + String(summary.violatedCount || 0)
                + ' · safe: ' + String(summary.safeCount || 0)
                + ' · regressions saved: ' + String(summary.regressionSavedCount || 0) + '.';
            elements.suiteResults.innerHTML = '<ul class="copilot-redteam-list">'
                + (summary.runs || []).map(function (item) {
                    return '<li><strong>' + escapeHtml(item.title) + '</strong> · '
                        + escapeHtml(item.role) + ' / ' + escapeHtml(item.workflow)
                        + ' · severity ' + escapeHtml(item.severity)
                        + ' · ' + escapeHtml(item.violated ? 'violation' : 'safe') + '</li>';
                }).join('')
                + '</ul>';
            elements.exportButton.disabled = false;
            state.latestExportTarget = {
                type: 'suite',
                value: suiteResult
            };
        }

        async function runSelectedScenario() {
            setBusy(true, 'Running the selected local synthetic scenario...');
            try {
                const result = await harness.runRedTeamTest({
                    seedScenarioId: state.selectedScenarioId,
                    useLiveCopilot: true
                });
                renderRunResult(result);
                renderSuiteResult(null);
                setBusy(false, 'OpenEMR Team run complete.');
            } catch (error) {
                setBusy(false, error && error.message ? error.message : 'The OpenEMR Team run failed.');
            }
        }

        async function runSeedSuite() {
            setBusy(true, 'Running all 12 seed scenarios in sequence...');
            elements.suiteSummary.textContent = 'Running the 12 seed tests...';
            elements.suiteResults.innerHTML = '';

            try {
                const suiteResult = await harness.runSeedTests({
                    limit: 12,
                    useLiveCopilot: true,
                    onProgress: function (result, completed, total) {
                        elements.suiteSummary.textContent = 'Running seed tests: ' + String(completed) + ' of ' + String(total) + ' complete.';
                        renderRunResult(result);
                    }
                });
                renderSuiteResult(suiteResult);
                setBusy(false, 'Seed suite complete.');
            } catch (error) {
                setBusy(false, error && error.message ? error.message : 'The seed suite failed.');
            }
        }

        function exportLatestReport() {
            if (!state.latestExportTarget) {
                return;
            }

            harness.exportReport(state.latestExportTarget.value);
        }

        elements.scenarioSelect.addEventListener('change', function (event) {
            state.selectedScenarioId = event.target.value;
            renderScenarioPreview();
        });
        elements.runButton.addEventListener('click', function () {
            void runSelectedScenario();
        });
        elements.runSuiteButton.addEventListener('click', function () {
            void runSeedSuite();
        });
        elements.exportButton.addEventListener('click', function () {
            exportLatestReport();
        });

        renderScenarioPreview();

        return {
            destroy: function () {
                root.innerHTML = '';
            },
            renderRunResult: renderRunResult,
            renderSuiteResult: renderSuiteResult
        };
    }

    return {
        createRedTeamPanel: createRedTeamPanel
    };
}));
