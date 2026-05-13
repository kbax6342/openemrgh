(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotRedTeamTypes = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const RedTeamRole = Object.freeze({
        DOCTOR: 'doctor',
        NURSE: 'nurse',
        BILLING: 'billing',
        FRONT_DESK: 'front_desk'
    });

    const RedTeamRoles = Object.freeze([
        RedTeamRole.DOCTOR,
        RedTeamRole.NURSE,
        RedTeamRole.BILLING,
        RedTeamRole.FRONT_DESK
    ]);

    const RedTeamWorkflow = Object.freeze({
        CHART_SUMMARY: 'chart_summary',
        TREATMENT_PLAN: 'treatment_plan',
        MEDICATION_INFO: 'medication_info',
        LAB_PDF_INGESTION: 'lab_pdf_ingestion',
        INTAKE_FORM_INGESTION: 'intake_form_ingestion',
        INSURANCE_BILLING: 'insurance_billing',
        APPOINTMENT_SCHEDULING: 'appointment_scheduling',
        AMBIENT_ENCOUNTER_CAPTURE: 'ambient_encounter_capture',
        VISIT_HISTORY_RAG: 'visit_history_rag'
    });

    const RedTeamWorkflows = Object.freeze([
        RedTeamWorkflow.CHART_SUMMARY,
        RedTeamWorkflow.TREATMENT_PLAN,
        RedTeamWorkflow.MEDICATION_INFO,
        RedTeamWorkflow.LAB_PDF_INGESTION,
        RedTeamWorkflow.INTAKE_FORM_INGESTION,
        RedTeamWorkflow.INSURANCE_BILLING,
        RedTeamWorkflow.APPOINTMENT_SCHEDULING,
        RedTeamWorkflow.AMBIENT_ENCOUNTER_CAPTURE,
        RedTeamWorkflow.VISIT_HISTORY_RAG
    ]);

    const RedTeamAttackCategory = Object.freeze({
        ROLE_BOUNDARY_BYPASS: 'role_boundary_bypass',
        PROMPT_INJECTION: 'prompt_injection',
        UNAUTHORIZED_PHI_REQUEST: 'unauthorized_phi_request',
        DIRECT_CHART_WRITE_REQUEST: 'direct_chart_write_request',
        CITATION_BYPASS: 'citation_bypass',
        HALLUCINATION_PRESSURE: 'hallucination_pressure',
        INGESTION_WRONG_DOC_TYPE: 'ingestion_wrong_doc_type',
        BILLING_BOUNDARY_VIOLATION: 'billing_boundary_violation',
        NURSE_MEDICATION_PLAN_VIOLATION: 'nurse_medication_plan_violation',
        FRONT_DESK_MINIMUM_PHI_VIOLATION: 'front_desk_minimum_phi_violation'
    });

    const RedTeamAttackCategories = Object.freeze([
        RedTeamAttackCategory.ROLE_BOUNDARY_BYPASS,
        RedTeamAttackCategory.PROMPT_INJECTION,
        RedTeamAttackCategory.UNAUTHORIZED_PHI_REQUEST,
        RedTeamAttackCategory.DIRECT_CHART_WRITE_REQUEST,
        RedTeamAttackCategory.CITATION_BYPASS,
        RedTeamAttackCategory.HALLUCINATION_PRESSURE,
        RedTeamAttackCategory.INGESTION_WRONG_DOC_TYPE,
        RedTeamAttackCategory.BILLING_BOUNDARY_VIOLATION,
        RedTeamAttackCategory.NURSE_MEDICATION_PLAN_VIOLATION,
        RedTeamAttackCategory.FRONT_DESK_MINIMUM_PHI_VIOLATION
    ]);

    const RedTeamSeverity = Object.freeze({
        NONE: 'none',
        LOW: 'low',
        MEDIUM: 'medium',
        HIGH: 'high',
        CRITICAL: 'critical'
    });

    const RedTeamSeverities = Object.freeze([
        RedTeamSeverity.NONE,
        RedTeamSeverity.LOW,
        RedTeamSeverity.MEDIUM,
        RedTeamSeverity.HIGH,
        RedTeamSeverity.CRITICAL
    ]);

    const WorkflowToCopilotMode = Object.freeze({
        chart_summary: 'clinical_notes',
        treatment_plan: 'treatment_plan',
        medication_info: 'medication_info',
        lab_pdf_ingestion: 'lab_pdf_ingestion',
        intake_form_ingestion: 'lab_pdf_ingestion',
        insurance_billing: 'billing',
        appointment_scheduling: 'appointment_info',
        ambient_encounter_capture: 'latest_ambient_summary',
        visit_history_rag: 'rag_chart_context'
    });

    const DEFAULT_SYNTHETIC_PATIENT = Object.freeze({
        id: 'DEMO-PCP-1001',
        name: 'Marcus Johnson'
    });

    function normalizeEnum(value, allowedValues, fallback) {
        const normalized = String(value || '').trim().toLowerCase();
        return allowedValues.indexOf(normalized) !== -1 ? normalized : fallback;
    }

    function normalizeRole(value) {
        return normalizeEnum(value, RedTeamRoles, RedTeamRole.DOCTOR);
    }

    function normalizeWorkflow(value) {
        return normalizeEnum(value, RedTeamWorkflows, RedTeamWorkflow.CHART_SUMMARY);
    }

    function normalizeAttackCategory(value) {
        return normalizeEnum(value, RedTeamAttackCategories, RedTeamAttackCategory.ROLE_BOUNDARY_BYPASS);
    }

    function normalizeSeverity(value) {
        return normalizeEnum(value, RedTeamSeverities, RedTeamSeverity.NONE);
    }

    function isoNow() {
        return new Date().toISOString();
    }

    function createRunId(prefix) {
        const safePrefix = String(prefix || 'redteam_run').trim() || 'redteam_run';
        const randomChunk = Math.random().toString(16).slice(2, 10);
        return safePrefix + '_' + Date.now() + '_' + randomChunk;
    }

    function sortObject(value) {
        if (Array.isArray(value)) {
            return value.map(sortObject);
        }

        if (!value || typeof value !== 'object') {
            return value;
        }

        return Object.keys(value).sort().reduce(function (accumulator, key) {
            accumulator[key] = sortObject(value[key]);
            return accumulator;
        }, {});
    }

    function stableStringify(value) {
        return JSON.stringify(sortObject(value));
    }

    function hashString(value) {
        const input = String(value || '');
        let hash = 2166136261;
        for (let index = 0; index < input.length; index += 1) {
            hash ^= input.charCodeAt(index);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }

        return (hash >>> 0).toString(16).padStart(8, '0');
    }

    function hashRegressionFingerprint(input) {
        return 'rt_' + hashString(stableStringify(input));
    }

    function cloneValue(value) {
        return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
    }

    function createRunPlan(input) {
        const source = input && typeof input === 'object' ? input : {};
        const patientId = String(source.patientId || DEFAULT_SYNTHETIC_PATIENT.id);
        const patientName = String(source.patientName || DEFAULT_SYNTHETIC_PATIENT.name);
        return {
            runId: source.runId || createRunId('redteam_plan'),
            category: normalizeAttackCategory(source.category),
            role: normalizeRole(source.role),
            patientId: patientId,
            patientName: patientName,
            workflow: normalizeWorkflow(source.workflow),
            goal: String(source.goal || '').trim(),
            expectedSafeBehavior: String(source.expectedSafeBehavior || '').trim(),
            createdAt: String(source.createdAt || isoNow()),
            seedScenarioId: source.seedScenarioId || '',
            attachment: cloneValue(source.attachment || null),
            metadata: cloneValue(source.metadata || {})
        };
    }

    function createRunResult(input) {
        const source = input && typeof input === 'object' ? input : {};
        return {
            runId: source.runId || createRunId('redteam_result'),
            plan: source.plan ? createRunPlan(source.plan) : createRunPlan({}),
            generatedPrompt: String(source.generatedPrompt || ''),
            mutatedPrompts: Array.isArray(source.mutatedPrompts) ? source.mutatedPrompts.slice() : [],
            selectedPrompt: String(source.selectedPrompt || ''),
            copilotResponse: source.copilotResponse ? cloneValue(source.copilotResponse) : null,
            judgeVerdict: source.judgeVerdict ? cloneValue(source.judgeVerdict) : null,
            regressionSaved: source.regressionSaved ? cloneValue(source.regressionSaved) : null,
            reportMarkdown: String(source.reportMarkdown || ''),
            auditEvents: Array.isArray(source.auditEvents) ? source.auditEvents.slice() : [],
            attempts: Array.isArray(source.attempts) ? cloneValue(source.attempts) : [],
            createdAt: String(source.createdAt || isoNow())
        };
    }

    return {
        RedTeamRole: RedTeamRole,
        RedTeamRoles: RedTeamRoles,
        RedTeamWorkflow: RedTeamWorkflow,
        RedTeamWorkflows: RedTeamWorkflows,
        RedTeamAttackCategory: RedTeamAttackCategory,
        RedTeamAttackCategories: RedTeamAttackCategories,
        RedTeamSeverity: RedTeamSeverity,
        RedTeamSeverities: RedTeamSeverities,
        WorkflowToCopilotMode: WorkflowToCopilotMode,
        DEFAULT_SYNTHETIC_PATIENT: DEFAULT_SYNTHETIC_PATIENT,
        cloneValue: cloneValue,
        createRunId: createRunId,
        createRunPlan: createRunPlan,
        createRunResult: createRunResult,
        hashRegressionFingerprint: hashRegressionFingerprint,
        hashString: hashString,
        isoNow: isoNow,
        normalizeAttackCategory: normalizeAttackCategory,
        normalizeRole: normalizeRole,
        normalizeSeverity: normalizeSeverity,
        normalizeWorkflow: normalizeWorkflow,
        stableStringify: stableStringify
    };
}));
