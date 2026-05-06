#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const labIngestion = require('../interface/ai_copilot/lab_pdf_ingestion.js');
const agentTools = require('../interface/ai_copilot/agents/copilot_agent_tools.js');
const agents = require('../interface/ai_copilot/agents/copilot_agents.js');

const FIXTURES_DIR = path.join(__dirname, 'fixtures');
const LAB_FIXTURE_PATH = path.join(FIXTURES_DIR, 'marcus-johnson-lab-results.txt');
const INTAKE_FIXTURE_PATH = path.join(FIXTURES_DIR, 'marcus-johnson-intake-form.txt');

function readFixture(filePath) {
    return fs.readFileSync(filePath, 'utf8');
}

function parseSyntheticIntakeFixture(text) {
    const parsed = {
        reasonForVisit: '',
        medicationAdherenceIssue: '',
        allergies: '',
        insuranceUpdate: '',
        carePreference: ''
    };

    String(text || '').split(/\r?\n/).forEach((line) => {
        const match = line.match(/^([^:]+):\s*(.+)$/);
        if (!match) {
            return;
        }

        const label = match[1].trim().toLowerCase();
        const value = match[2].trim();
        if (label === 'reason for visit') {
            parsed.reasonForVisit = value;
        } else if (label === 'medication adherence issue') {
            parsed.medicationAdherenceIssue = value;
        } else if (label === 'allergies') {
            parsed.allergies = value;
        } else if (label === 'insurance update') {
            parsed.insuranceUpdate = value;
        } else if (label === 'care preference') {
            parsed.carePreference = value;
        }
    });

    return parsed;
}

function createAllowGuardrails() {
    return {
        evaluate() {
            return {
                allowed: true,
                finalResponse: '',
                blockedReason: '',
                riskLevel: 'low',
                policyTags: [],
                finalSections: [],
                finalSafety: 'Draft only. Human review required.'
            };
        }
    };
}

function createToolCaller(resultMap, calls) {
    return async function callTool(toolName, input) {
        calls.push({ toolName, input });
        const result = resultMap[toolName];
        if (typeof result === 'function') {
            return result(input);
        }
        if (result !== undefined) {
            return result;
        }
        throw new Error(`No stubbed result for ${toolName}`);
    };
}

function baseRequest(overrides = {}) {
    return {
        requestId: 'request_mvp_tuesday_001',
        prompt: 'Summarize the uploaded document and include sources.',
        role: 'doctor',
        mode: 'lab_pdf_ingestion',
        patientId: 1001,
        selectedPatientKey: 'DEMO-PCP-1001',
        contextScope: 'full_clinical',
        ambientVisitContext: { approved: true },
        chatHistory: [],
        extraPayload: {},
        patientName: 'Marcus Johnson',
        ...overrides
    };
}

function makeChartContextResult(overrides = {}) {
    return {
        tool: 'retrieve_chart_context',
        worker: 'chart_retrieval_worker',
        patient: {
            pid: 1001,
            pubpid: 'DEMO-PCP-1001',
            name: 'Marcus Johnson'
        },
        role_scope: 'full_clinical',
        facts: [
            { domain: 'labs', label: 'Recent lab context', value: 'A1c above goal', source_id: 'labs', source_label: 'Vitals / Labs' }
        ],
        sources: [
            { id: 'labs', title: 'Vitals / Labs', category: 'vitals_labs' },
            { id: 'attached_lab_pdf', title: 'Attached Lab PDF', category: 'documents' }
        ],
        missing_data: [],
        grounded: true,
        ...overrides
    };
}

function makeGuidelineResult(overrides = {}) {
    return {
        tool: 'retrieve_guideline_evidence',
        worker: 'chart_retrieval_worker',
        evidence: [
            { statement: 'Draft only.', source_label: 'OpenEMR AI Copilot Draft-Only Policy' }
        ],
        sources: [
            { id: 'policy_draft_only', title: 'OpenEMR AI Copilot Draft-Only Policy', category: 'policy' }
        ],
        policy_flags: ['draft_only'],
        ...overrides
    };
}

function makeDraftResult(overrides = {}) {
    return {
        tool: 'draft_grounded_answer',
        worker: 'clinical_workflow_supervisor',
        draft: {
            answer: 'Draft summary prepared for Marcus Johnson.',
            sections: [
                { title: 'Summary', items: ['Draft summary prepared for Marcus Johnson.'] }
            ],
            tags: ['Draft only', 'Source grounded'],
            sources: [
                { id: 'attached_lab_pdf', title: 'Attached Lab PDF', category: 'documents' },
                { id: 'policy_draft_only', title: 'OpenEMR AI Copilot Draft-Only Policy', category: 'policy' }
            ],
            safety_note: 'Draft only. Human clinician review required.',
            missing_data: []
        },
        meta: {
            engine: 'demo_tool_fallback',
            provider: 'local_demo_tools',
            model: null,
            openai_configured: false,
            fallback_used: true,
            fallback_reason: 'local_demo_tools',
            rag_grounded: true
        },
        tool_output: null,
        ...overrides
    };
}

function makeValidationResult(overrides = {}) {
    return {
        tool: 'validate_citations',
        worker: 'evidence_safety_worker',
        allowed: true,
        blocked_reason: '',
        safe_refusal: '',
        citation_gaps: [],
        missing_data: [],
        unsupported_claims: [],
        validated_sources: [
            { id: 'attached_lab_pdf', title: 'Attached Lab PDF', category: 'documents' },
            { id: 'policy_draft_only', title: 'OpenEMR AI Copilot Draft-Only Policy', category: 'policy' }
        ],
        draft_only_note: 'Draft only. Human clinician review required.',
        policy_flags: ['validated'],
        ...overrides
    };
}

function findSection(result, title) {
    return (result.sections || []).find((section) => String(section.title || '').toLowerCase() === title.toLowerCase());
}

const tests = [
    function labExtractionReturnsExpectedFacts() {
        const labText = readFixture(LAB_FIXTURE_PATH);
        const result = labIngestion.extractLabFactsFromText(labText, {
            fileName: 'marcus-johnson-lab-results.txt'
        });

        const factMap = new Map(result.facts.map((fact) => [fact.label, fact]));
        assert.ok(factMap.has('Hemoglobin A1c'));
        assert.ok(factMap.has('LDL Cholesterol'));
        assert.ok(factMap.has('Creatinine'));
        assert.ok(factMap.has('eGFR'));
        assert.strictEqual(factMap.get('Hemoglobin A1c').flag, 'high');
        assert.strictEqual(factMap.get('LDL Cholesterol').flag, 'high');
        assert.strictEqual(factMap.get('Creatinine').flag, 'normal');
    },
    function intakeExtractionReturnsExpectedFacts() {
        const intakeText = readFixture(INTAKE_FIXTURE_PATH);
        const parsed = parseSyntheticIntakeFixture(intakeText);

        assert.strictEqual(parsed.reasonForVisit, 'blood sugar management and medication questions');
        assert.strictEqual(parsed.medicationAdherenceIssue, 'sometimes misses evening Metformin');
        assert.strictEqual(parsed.allergies, 'no known drug allergies reported');
        assert.strictEqual(parsed.insuranceUpdate, 'patient says coverage changed recently');
        assert.strictEqual(parsed.carePreference, 'written instructions and phone reminders');
    },
    async function retrievalAnswerIncludesSourceNames() {
        const demoCaller = agentTools.createDemoToolCaller();
        const result = await agents.runSupervisor({
            request: baseRequest({
                prompt: 'Summarize the uploaded lab PDF and include sources used.',
                mode: 'lab_pdf_ingestion'
            }),
            callTool: demoCaller.callTool,
            guardrails: createAllowGuardrails(),
            toolSchemas: agentTools.getToolSchemas(),
            logger: { info() {}, groupCollapsed() {}, groupEnd() {} }
        });

        const sourceTitles = (result.sources || []).map((source) => source.title);
        assert.ok(sourceTitles.includes('Attached Lab PDF'));
        assert.ok(sourceTitles.includes('OpenEMR AI Copilot Draft-Only Policy'));
        assert.strictEqual(result.meta.rag_grounded, true);
    },
    async function missingPotassiumQuestionReturnsNotFoundStyleAnswer() {
        const calls = [];
        const result = await agents.runSupervisor({
            request: baseRequest({
                prompt: "What was Marcus Johnson's potassium result?",
                mode: 'rag_chart_context'
            }),
            callTool: createToolCaller({
                retrieve_chart_context: makeChartContextResult(),
                retrieve_guideline_evidence: makeGuidelineResult(),
                draft_grounded_answer: makeDraftResult({
                    draft: {
                        answer: 'Draft summary prepared for Marcus Johnson.',
                        sections: [
                            { title: 'Summary', items: ['Draft summary prepared for Marcus Johnson.'] }
                        ],
                        tags: ['Draft only', 'Source grounded'],
                        sources: [
                            { id: 'attached_lab_pdf', title: 'Attached Lab PDF', category: 'documents' },
                            { id: 'policy_draft_only', title: 'OpenEMR AI Copilot Draft-Only Policy', category: 'policy' }
                        ],
                        safety_note: 'Draft only. Human clinician review required.',
                        missing_data: [
                            'Potassium was not found in the retrieved context or attached sources. Check the source chart or uploaded documents directly before making a clinical decision.'
                        ]
                    }
                }),
                validate_citations: makeValidationResult()
            }, calls),
            guardrails: createAllowGuardrails(),
            toolSchemas: agentTools.getToolSchemas(),
            logger: { info() {}, groupCollapsed() {}, groupEnd() {} }
        });

        const missingSection = findSection(result, 'Missing data / uncertainty');
        assert.ok(missingSection);
        assert.ok(missingSection.items.some((item) => /Potassium was not found/i.test(item)));
        assert.ok(missingSection.items.some((item) => /source chart|uploaded documents/i.test(item)));
    },
    async function billingRoleCannotAccessClinicalLabDetails() {
        const demoCaller = agentTools.createDemoToolCaller();
        const result = await agents.runSupervisor({
            request: baseRequest({
                prompt: "Review Marcus Johnson's lab PDF and tell me the treatment plan.",
                role: 'billing',
                mode: 'lab_pdf_ingestion'
            }),
            callTool: demoCaller.callTool,
            guardrails: createAllowGuardrails(),
            toolSchemas: agentTools.getToolSchemas(),
            logger: { info() {}, groupCollapsed() {}, groupEnd() {} }
        });

        assert.strictEqual(result.meta.restricted_by_role, true);
        assert.ok(/Billing Staff role|billing workflow summaries/i.test(result.answer));
    }
];

(async function run() {
    let passed = 0;
    for (const test of tests) {
        await test();
        passed += 1;
        console.log(`PASS ${test.name}`);
    }
    console.log(`${passed} MVP Tuesday smoke checks passed`);
})().catch((error) => {
    console.error(`FAIL ${error && error.message ? error.message : error}`);
    process.exit(1);
});
