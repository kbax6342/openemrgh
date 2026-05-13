(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('../redTeamTypes.js'));
        return;
    }

    root.OpenEMRCopilotRegressionAgent = factory(root.OpenEMRCopilotRedTeamTypes);
}(typeof globalThis !== 'undefined' ? globalThis : this, function (Types) {
    'use strict';

    const STORAGE_KEY = 'openemr_ai_copilot_redteam_regressions_v1';
    const inMemoryRecords = [];

    function readStoredRecords() {
        try {
            if (typeof localStorage !== 'undefined') {
                const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
                if (Array.isArray(parsed)) {
                    return parsed;
                }
            }
        } catch (error) {
        }

        return inMemoryRecords.slice();
    }

    function writeStoredRecords(records) {
        const payload = Array.isArray(records) ? records : [];
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
                return 'localStorage';
            }
        } catch (error) {
        }

        inMemoryRecords.length = 0;
        payload.forEach(function (record) {
            inMemoryRecords.push(record);
        });
        return 'memory';
    }

    function buildRegressionRecord(runResult) {
        const plan = Types.createRunPlan(runResult.plan || {});
        const fingerprint = Types.hashRegressionFingerprint({
            category: plan.category,
            role: plan.role,
            workflow: plan.workflow,
            prompt: runResult.selectedPrompt || runResult.generatedPrompt || ''
        });

        return {
            eval_id: fingerprint,
            attack_category: plan.category,
            role: plan.role,
            patient_id: plan.patientId,
            workflow: plan.workflow,
            prompt: runResult.selectedPrompt || runResult.generatedPrompt || '',
            expected_safe_behavior: plan.expectedSafeBehavior || '',
            policy_area: runResult.judgeVerdict ? runResult.judgeVerdict.policy_area : '',
            created_at: Types.isoNow()
        };
    }

    function downloadRegressionRecord(record) {
        if (!record || typeof document === 'undefined' || typeof URL === 'undefined' || typeof Blob === 'undefined') {
            return null;
        }

        const blob = new Blob([JSON.stringify(record, null, 2)], { type: 'application/json' });
        const link = document.createElement('a');
        const href = URL.createObjectURL(blob);
        link.href = href;
        link.download = record.eval_id + '.json';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(href);
        return record.eval_id + '.json';
    }

    function createRegressionAgent() {
        return {
            listRegressions: readStoredRecords,
            saveRegression: function (runResult, options) {
                const config = options && typeof options === 'object' ? options : {};
                if (!runResult || !runResult.judgeVerdict || !runResult.judgeVerdict.should_save_regression) {
                    return {
                        saved: false,
                        duplicate: false,
                        storage: null,
                        record: null,
                        fileName: null
                    };
                }

                const nextRecord = buildRegressionRecord(runResult);
                const existingRecords = readStoredRecords();
                const duplicateRecord = existingRecords.find(function (record) {
                    return record && record.eval_id === nextRecord.eval_id;
                }) || null;
                if (duplicateRecord) {
                    return {
                        saved: false,
                        duplicate: true,
                        storage: 'duplicate',
                        record: duplicateRecord,
                        fileName: null
                    };
                }

                existingRecords.push(nextRecord);
                const storage = writeStoredRecords(existingRecords);
                const fileName = config.download === true ? downloadRegressionRecord(nextRecord) : null;
                return {
                    saved: true,
                    duplicate: false,
                    storage: storage,
                    record: nextRecord,
                    fileName: fileName
                };
            }
        };
    }

    return {
        STORAGE_KEY: STORAGE_KEY,
        buildRegressionRecord: buildRegressionRecord,
        createRegressionAgent: createRegressionAgent,
        downloadRegressionRecord: downloadRegressionRecord
    };
}));
