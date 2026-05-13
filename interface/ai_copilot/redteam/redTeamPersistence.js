(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(
            require('./redTeamTypes.js'),
            require('./redTeamPolicies.js')
        );
        return;
    }

    root.OpenEMRCopilotRedTeamPersistence = factory(
        root.OpenEMRCopilotRedTeamTypes,
        root.OpenEMRCopilotRedTeamPolicies
    );
}(typeof globalThis !== 'undefined' ? globalThis : this, function (Types, Policies) {
    'use strict';

    function responseTextFromRunResult(runResult) {
        const response = runResult && runResult.copilotResponse ? runResult.copilotResponse : {};
        return String(response.plainText || response.content || response.responseText || '').trim();
    }

    function uniqueStrings(items) {
        return Array.from(new Set((items || []).map(function (item) {
            return String(item || '').trim();
        }).filter(Boolean)));
    }

    function buildRetrievedContextIds(response) {
        const sourceItems = [];
        const safeResponse = response && typeof response === 'object' ? response : {};
        const meta = safeResponse.meta && typeof safeResponse.meta === 'object' ? safeResponse.meta : {};

        if (Array.isArray(meta.retrieved_chunk_ids)) {
            sourceItems.push.apply(sourceItems, meta.retrieved_chunk_ids);
        }
        if (Array.isArray(meta.matched_source_titles)) {
            sourceItems.push.apply(sourceItems, meta.matched_source_titles);
        }
        if (Array.isArray(meta.sourceTitles)) {
            sourceItems.push.apply(sourceItems, meta.sourceTitles);
        }
        if (Array.isArray(safeResponse.sources)) {
            safeResponse.sources.forEach(function (source) {
                if (!source) {
                    return;
                }
                if (typeof source === 'string') {
                    sourceItems.push(source);
                    return;
                }
                if (source.sourceId) {
                    sourceItems.push(String(source.sourceId));
                }
                if (source.title) {
                    sourceItems.push(String(source.title));
                }
            });
        }

        return uniqueStrings(sourceItems).slice(0, 64);
    }

    function mustBlockForResult(runResult) {
        const plan = Types.createRunPlan(runResult && runResult.plan ? runResult.plan : {});
        switch (Types.normalizeAttackCategory(plan.category)) {
            case Types.RedTeamAttackCategory.CITATION_BYPASS:
            case Types.RedTeamAttackCategory.HALLUCINATION_PRESSURE:
            case Types.RedTeamAttackCategory.INGESTION_WRONG_DOC_TYPE:
                return false;
            default:
                return true;
        }
    }

    function mustIncludeSourcesForResult(runResult) {
        const plan = Types.createRunPlan(runResult && runResult.plan ? runResult.plan : {});
        return Types.normalizeAttackCategory(plan.category) === Types.RedTeamAttackCategory.CITATION_BYPASS
            || Policies.workflowNeedsClinicalCitations(plan.workflow);
    }

    function buildAttackRunRecord(runResult) {
        const result = Types.createRunResult(runResult || {});
        const verdict = result.judgeVerdict || {};
        return {
            attack_category: result.plan.category,
            user_role: result.plan.role,
            patient_id: result.plan.patientId,
            workflow: result.plan.workflow,
            prompt: result.selectedPrompt || result.generatedPrompt || '',
            retrieved_context_ids: buildRetrievedContextIds(result.copilotResponse),
            model_response: responseTextFromRunResult(result),
            judge_result: Types.cloneValue(verdict),
            severity: verdict.severity || Types.RedTeamSeverity.NONE,
            violated_policy: verdict.policy_area || '',
            created_at: result.createdAt || Types.isoNow()
        };
    }

    function buildRegressionCaseRecord(runResult, sourceAttackRunId) {
        const result = Types.createRunResult(runResult || {});
        const verdict = result.judgeVerdict || {};
        if (!verdict.should_save_regression) {
            return null;
        }

        return {
            source_attack_run_id: sourceAttackRunId || null,
            eval_name: result.plan.seedScenarioId || result.plan.metadata?.title || [
                result.plan.category,
                result.plan.role,
                result.plan.workflow
            ].join('_'),
            prompt: result.selectedPrompt || result.generatedPrompt || '',
            expected_behavior: verdict.expected_behavior || result.plan.expectedSafeBehavior || '',
            role: result.plan.role,
            workflow: result.plan.workflow,
            must_block: mustBlockForResult(result),
            must_include_sources: mustIncludeSourcesForResult(result),
            created_at: Types.isoNow()
        };
    }

    function buildGuardrailRecord(stage, evaluation, requestId) {
        const guardrail = evaluation && typeof evaluation === 'object' ? evaluation : null;
        if (!guardrail) {
            return null;
        }

        return {
            request_id: String(requestId || '').trim(),
            stage: String(stage || 'post_response'),
            passed: Boolean(guardrail.allowed),
            reason: String(
                guardrail.blockedReason
                || guardrail.reason
                || guardrail.finalSafety
                || guardrail.ui?.displayReason
                || ''
            ).trim(),
            policy_code: String(
                (Array.isArray(guardrail.policyTags) && guardrail.policyTags[0])
                || guardrail.blockedReason
                || ''
            ).trim(),
            created_at: Types.isoNow()
        };
    }

    function buildGuardrailRecords(runResult) {
        const result = Types.createRunResult(runResult || {});
        const response = result.copilotResponse && typeof result.copilotResponse === 'object' ? result.copilotResponse : {};
        const requestId = response.requestId || result.runId;
        const records = [];
        const preRecord = buildGuardrailRecord('pre_response', response.preGuardrails || null, requestId);
        const postRecord = buildGuardrailRecord('post_response', response.postGuardrails || null, requestId);

        if (preRecord) {
            records.push(preRecord);
        }
        if (postRecord) {
            records.push(postRecord);
        } else if (!preRecord && response.guardrails) {
            records.push(buildGuardrailRecord(response.blocked ? 'pre_response' : 'post_response', response.guardrails, requestId));
        }

        return records.filter(Boolean);
    }

    function buildPersistPayload(runResult) {
        const attackRunRecord = buildAttackRunRecord(runResult);
        const regressionCaseRecord = buildRegressionCaseRecord(runResult, null);
        const guardrailRecords = buildGuardrailRecords(runResult);
        const requestId = runResult && runResult.copilotResponse && runResult.copilotResponse.requestId
            ? runResult.copilotResponse.requestId
            : Types.createRunResult(runResult || {}).runId;

        return {
            action: 'redteam_persist',
            request_id: requestId,
            record: {
                attack_run: attackRunRecord,
                regression_case: regressionCaseRecord,
                guardrail_results: guardrailRecords
            }
        };
    }

    function createPersistenceClient(options) {
        const config = options && typeof options === 'object' ? options : {};
        const apiUrl = String(config.apiUrl || '').trim();
        const csrfToken = String(config.csrfToken || '').trim();
        const fetchImpl = config.fetchImpl || (typeof fetch === 'function' ? fetch.bind(typeof window !== 'undefined' ? window : globalThis) : null);

        async function persistRun(runResult) {
            if (!apiUrl || !csrfToken || !fetchImpl) {
                return {
                    saved: false,
                    storage: 'browser_only',
                    reason: 'persistence_unavailable'
                };
            }

            const payload = buildPersistPayload(runResult);
            payload.csrf_token_form = csrfToken;

            const response = await fetchImpl(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json().catch(function () {
                return {};
            });

            if (!response.ok) {
                return {
                    saved: false,
                    storage: 'database',
                    reason: data.error || 'persistence_failed',
                    missingTables: Array.isArray(data.missing_tables) ? data.missing_tables : []
                };
            }

            return {
                saved: true,
                storage: 'database',
                attackRunId: data.attack_run_id || null,
                regressionCaseId: data.regression_case_id || null,
                guardrailResultIds: Array.isArray(data.guardrail_result_ids) ? data.guardrail_result_ids : [],
                duplicateRegression: Boolean(data.duplicate_regression_case)
            };
        }

        return {
            available: Boolean(apiUrl && csrfToken && fetchImpl),
            buildPersistPayload: buildPersistPayload,
            persistRun: persistRun
        };
    }

    return {
        buildAttackRunRecord: buildAttackRunRecord,
        buildGuardrailRecords: buildGuardrailRecords,
        buildPersistPayload: buildPersistPayload,
        buildRegressionCaseRecord: buildRegressionCaseRecord,
        buildRetrievedContextIds: buildRetrievedContextIds,
        createPersistenceClient: createPersistenceClient,
        mustBlockForResult: mustBlockForResult,
        mustIncludeSourcesForResult: mustIncludeSourcesForResult
    };
}));
