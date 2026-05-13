(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(
            require('./redTeamTypes.js'),
            require('./redTeamReports.js'),
            require('./agents/orchestratorAgent.js'),
            require('./agents/redTeamAgent.js'),
            require('./agents/mutationAgent.js'),
            require('./agents/runnerAgent.js'),
            require('./agents/judgeAgent.js'),
            require('./agents/regressionAgent.js'),
            require('./agents/reporterAgent.js')
        );
        return;
    }

    root.OpenEMRCopilotRedTeamHarness = factory(
        root.OpenEMRCopilotRedTeamTypes,
        root.OpenEMRCopilotRedTeamReports,
        root.OpenEMRCopilotRedTeamOrchestratorAgent,
        root.OpenEMRCopilotRedTeamAgent,
        root.OpenEMRCopilotMutationAgent,
        root.OpenEMRCopilotRunnerAgent,
        root.OpenEMRCopilotJudgeAgent,
        root.OpenEMRCopilotRegressionAgent,
        root.OpenEMRCopilotReporterAgent
    );
}(typeof globalThis !== 'undefined' ? globalThis : this, function (
    Types,
    Reports,
    OrchestratorModule,
    RedTeamModule,
    MutationModule,
    RunnerModule,
    JudgeModule,
    RegressionModule,
    ReporterModule
) {
    'use strict';

    const SEVERITY_RANK = Object.freeze({
        none: 0,
        low: 1,
        medium: 2,
        high: 3,
        critical: 4
    });

    function cloneAuditPayload(payload) {
        return Types.cloneValue(payload || {});
    }

    function createAuditRecorder(logger) {
        const records = [];
        const auditLogger = logger && typeof logger.log === 'function'
            ? function (eventName, payload) {
                return logger.log(eventName, payload);
            }
            : (typeof logger === 'function' ? logger : function (eventName, payload) {
                const label = '[Medical Co-Pilot OpenEMR Team Audit] ' + String(eventName || 'redteam_event');
                console.info(label, payload || {});
                return null;
            });

        return {
            emit: function (eventName, payload) {
                const record = {
                    event: String(eventName || 'redteam_event'),
                    payload: cloneAuditPayload(payload),
                    timestamp: Types.isoNow()
                };
                records.push(record);
                auditLogger(record.event, record.payload);
                return record;
            },
            list: function () {
                return records.slice();
            }
        };
    }

    function bestAttemptFor(attempts) {
        return (attempts || []).slice().sort(function (left, right) {
            const leftRank = SEVERITY_RANK[Types.normalizeSeverity(left.verdict && left.verdict.severity)] || 0;
            const rightRank = SEVERITY_RANK[Types.normalizeSeverity(right.verdict && right.verdict.severity)] || 0;
            if (rightRank !== leftRank) {
                return rightRank - leftRank;
            }
            return left.order - right.order;
        })[0] || null;
    }

    function summarizeAttempts(attempts) {
        return (attempts || []).map(function (attempt) {
            return {
                label: attempt.label,
                order: attempt.order,
                prompt: attempt.prompt,
                adapter: attempt.response && attempt.response.adapter ? attempt.response.adapter : 'mock',
                severity: attempt.verdict && attempt.verdict.severity ? attempt.verdict.severity : Types.RedTeamSeverity.NONE,
                violated: Boolean(attempt.verdict && attempt.verdict.violated)
            };
        });
    }

    function createRedTeamHarness(options) {
        const config = options && typeof options === 'object' ? options : {};
        const orchestratorAgent = config.orchestratorAgent || OrchestratorModule.createOrchestratorAgent({
            seedScenarios: config.seedScenarios,
            random: config.random
        });
        const redTeamAgent = config.redTeamAgent || RedTeamModule.createRedTeamAgent();
        const mutationAgent = config.mutationAgent || MutationModule.createMutationAgent();
        const runnerAgent = config.runnerAgent || RunnerModule.createRunnerAgent({
            liveAdapter: config.liveAdapter || null
        });
        const judgeAgent = config.judgeAgent || JudgeModule.createJudgeAgent();
        const regressionAgent = config.regressionAgent || RegressionModule.createRegressionAgent();
        const reporterAgent = config.reporterAgent || ReporterModule.createReporterAgent();
        const persistenceAdapter = config.persistenceAdapter || null;
        const auditLogger = config.auditLogger || null;

        let lastRunResult = null;
        let lastSuiteResult = null;

        async function executeRun(planInput, runOptions) {
            const optionsForRun = runOptions && typeof runOptions === 'object' ? runOptions : {};
            const plan = Types.createRunPlan(planInput || {});
            const audit = createAuditRecorder(auditLogger);
            const generatedPrompt = redTeamAgent.generatePrompt(plan);
            const mutatedPrompts = mutationAgent.generateMutations(plan, generatedPrompt).slice(0, 3);
            const shouldUseLiveCopilot = optionsForRun.useLiveCopilot !== false;
            const executionContext = {
                plan: plan,
                attachment: plan.attachment || null,
                useLiveCopilot: shouldUseLiveCopilot
            };
            const attempts = [];

            audit.emit('redteam_run_started', {
                runId: plan.runId,
                attackCategory: plan.category,
                role: plan.role,
                workflow: plan.workflow,
                selectedPatientKey: plan.patientId,
                useLiveCopilot: shouldUseLiveCopilot
            });
            audit.emit('redteam_plan_created', {
                runId: plan.runId,
                attackCategory: plan.category,
                role: plan.role,
                workflow: plan.workflow,
                expectedSafeBehavior: plan.expectedSafeBehavior,
                seedScenarioId: plan.seedScenarioId || ''
            });
            audit.emit('redteam_prompt_generated', {
                runId: plan.runId,
                attackCategory: plan.category,
                role: plan.role,
                workflow: plan.workflow,
                promptLength: generatedPrompt.length
            });
            audit.emit('redteam_mutations_created', {
                runId: plan.runId,
                attackCategory: plan.category,
                mutationCount: mutatedPrompts.length
            });

            try {
                const primaryResponse = await runnerAgent.runPromptAgainstCopilot(generatedPrompt, executionContext);
                const primaryVerdict = judgeAgent.evaluate(plan, generatedPrompt, primaryResponse);
                attempts.push({
                    label: 'primary',
                    order: 0,
                    prompt: generatedPrompt,
                    response: Types.cloneValue(primaryResponse),
                    verdict: Types.cloneValue(primaryVerdict)
                });

                const shouldProbeMutations = !primaryVerdict.violated
                    || Types.normalizeSeverity(primaryVerdict.severity) === Types.RedTeamSeverity.LOW
                    || Boolean(optionsForRun.forceMutationExecution);

                if (shouldProbeMutations) {
                    for (let index = 0; index < mutatedPrompts.length; index += 1) {
                        const mutationPrompt = mutatedPrompts[index];
                        const mutationResponse = await runnerAgent.runPromptAgainstCopilot(mutationPrompt, {
                            ...executionContext,
                            mutationIndex: index + 1
                        });
                        const mutationVerdict = judgeAgent.evaluate(plan, mutationPrompt, mutationResponse);
                        attempts.push({
                            label: 'mutation_' + String(index + 1),
                            order: index + 1,
                            prompt: mutationPrompt,
                            response: Types.cloneValue(mutationResponse),
                            verdict: Types.cloneValue(mutationVerdict)
                        });

                        if (mutationVerdict.violated && Types.normalizeSeverity(mutationVerdict.severity) === Types.RedTeamSeverity.CRITICAL) {
                            break;
                        }
                    }
                }

                const selectedAttempt = bestAttemptFor(attempts) || attempts[0];
                const baseResult = Types.createRunResult({
                    runId: plan.runId,
                    plan: plan,
                    generatedPrompt: generatedPrompt,
                    mutatedPrompts: mutatedPrompts,
                    selectedPrompt: selectedAttempt ? selectedAttempt.prompt : generatedPrompt,
                    copilotResponse: selectedAttempt ? selectedAttempt.response : null,
                    judgeVerdict: selectedAttempt ? selectedAttempt.verdict : null,
                    auditEvents: audit.list(),
                    attempts: attempts
                });

                audit.emit('redteam_runner_completed', {
                    runId: plan.runId,
                    attemptCount: attempts.length,
                    selectedPromptLabel: selectedAttempt ? selectedAttempt.label : 'primary',
                    adapter: selectedAttempt && selectedAttempt.response ? selectedAttempt.response.adapter : 'mock'
                });
                audit.emit('redteam_judge_completed', {
                    runId: plan.runId,
                    violated: Boolean(baseResult.judgeVerdict && baseResult.judgeVerdict.violated),
                    severity: baseResult.judgeVerdict && baseResult.judgeVerdict.severity ? baseResult.judgeVerdict.severity : Types.RedTeamSeverity.NONE,
                    policyArea: baseResult.judgeVerdict && baseResult.judgeVerdict.policy_area ? baseResult.judgeVerdict.policy_area : ''
                });

                const regressionSaved = regressionAgent.saveRegression(baseResult, {
                    download: Boolean(optionsForRun.downloadRegression)
                });
                if (regressionSaved.saved || regressionSaved.duplicate) {
                    audit.emit('redteam_regression_saved', {
                        runId: plan.runId,
                        saved: Boolean(regressionSaved.saved),
                        duplicate: Boolean(regressionSaved.duplicate),
                        evalId: regressionSaved.record ? regressionSaved.record.eval_id : ''
                    });
                }

                const finalResult = Types.createRunResult({
                    ...baseResult,
                    regressionSaved: regressionSaved
                });
                finalResult.reportMarkdown = reporterAgent.buildMarkdown(finalResult);
                finalResult.auditEvents = audit.list();

                if (persistenceAdapter && typeof persistenceAdapter.persistRun === 'function') {
                    try {
                        const persistenceResult = await persistenceAdapter.persistRun(finalResult);
                        finalResult.persistenceResult = Types.cloneValue(persistenceResult);
                        if (regressionSaved && typeof regressionSaved === 'object') {
                            finalResult.regressionSaved = {
                                ...regressionSaved,
                                persistence: Types.cloneValue(persistenceResult)
                            };
                        }
                    } catch (error) {
                        finalResult.persistenceResult = {
                            saved: false,
                            storage: 'database',
                            reason: error && error.message ? error.message : 'persistence_failed'
                        };
                    }
                }

                audit.emit('redteam_report_generated', {
                    runId: plan.runId,
                    reportLength: finalResult.reportMarkdown.length,
                    violated: Boolean(finalResult.judgeVerdict && finalResult.judgeVerdict.violated)
                });
                finalResult.auditEvents = audit.list();
                lastRunResult = finalResult;
                return finalResult;
            } catch (error) {
                audit.emit('redteam_run_failed', {
                    runId: plan.runId,
                    attackCategory: plan.category,
                    role: plan.role,
                    workflow: plan.workflow,
                    errorMessage: error && error.message ? error.message : 'Unknown OpenEMR Team harness error'
                });
                throw error;
            }
        }

        async function runRedTeamTest(input) {
            const request = input && typeof input === 'object' ? input : {};
            const plan = request.plan
                ? Types.createRunPlan(request.plan)
                : orchestratorAgent.createPlan(request);

            return executeRun(plan, request);
        }

        async function runSeedTests(options) {
            const configForSuite = options && typeof options === 'object' ? options : {};
            const suiteId = configForSuite.suiteId || Types.createRunId('redteam_suite');
            const seedPlans = orchestratorAgent.createSeedPlans(configForSuite.limit || 12);
            const results = [];

            for (let index = 0; index < seedPlans.length; index += 1) {
                const result = await executeRun(seedPlans[index], {
                    useLiveCopilot: configForSuite.useLiveCopilot,
                    downloadRegression: configForSuite.downloadRegression,
                    forceMutationExecution: configForSuite.forceMutationExecution
                });
                results.push(result);
                if (typeof configForSuite.onProgress === 'function') {
                    configForSuite.onProgress(result, index + 1, seedPlans.length);
                }
            }

            const summary = Reports.summarizeSuiteResults(results);
            const suiteResult = {
                suiteId: suiteId,
                createdAt: Types.isoNow(),
                results: results,
                summary: summary,
                reportMarkdown: Reports.buildSuiteMarkdown({
                    suiteId: suiteId,
                    createdAt: Types.isoNow(),
                    results: results,
                    summary: summary
                })
            };

            lastSuiteResult = Types.cloneValue(suiteResult);
            return suiteResult;
        }

        function exportReport(payload, options) {
            const target = payload || lastRunResult || lastSuiteResult;
            if (!target) {
                return {
                    fileName: '',
                    markdown: '',
                    downloaded: false
                };
            }

            return Reports.downloadMarkdownReport(target, options || {});
        }

        function getAdapterInfo(planLike) {
            if (runnerAgent && typeof runnerAgent.getAdapterInfo === 'function') {
                return runnerAgent.getAdapterInfo(planLike || {});
            }

            return {
                available: false,
                usingLive: false,
                label: 'Mock adapter'
            };
        }

        return {
            createPlan: function (input) {
                return orchestratorAgent.createPlan(input || {});
            },
            exportReport: exportReport,
            getAdapterInfo: getAdapterInfo,
            getLastRunResult: function () {
                return lastRunResult ? Types.cloneValue(lastRunResult) : null;
            },
            getLastSuiteResult: function () {
                return lastSuiteResult ? Types.cloneValue(lastSuiteResult) : null;
            },
            getRegressionRecords: function () {
                return regressionAgent.listRegressions();
            },
            hasLiveAdapter: Boolean(runnerAgent && runnerAgent.hasLiveAdapter),
            listSeedScenarios: function () {
                return orchestratorAgent.listSeedScenarios();
            },
            runRedTeamTest: runRedTeamTest,
            runSeedTests: runSeedTests,
            summarizeAttempts: summarizeAttempts
        };
    }

    return {
        createAuditRecorder: createAuditRecorder,
        createRedTeamHarness: createRedTeamHarness
    };
}));
