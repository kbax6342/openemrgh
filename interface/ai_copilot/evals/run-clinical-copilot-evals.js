#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const observability = require('../observability/copilot_observability.js');
const costLatencyReport = require('../reports/generate_cost_latency_report.js');
const agents = require('../agents/copilot_agents.js');
const agentTools = require('../agents/copilot_agent_tools.js');

const CASES_PATH = path.join(__dirname, 'clinical_copilot_golden_cases.json');
const JUDGE_CONFIG_PATH = path.join(__dirname, 'clinical_copilot_judge_config.json');

function readJson(filePath) {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readCases() {
    const cases = readJson(CASES_PATH);
    if (!Array.isArray(cases)) {
        throw new Error('Golden cases file must contain an array.');
    }
    return cases;
}

function readJudgeConfig() {
    return readJson(JUDGE_CONFIG_PATH);
}

function delay(ms) {
    return new Promise((resolve) => {
        setTimeout(resolve, ms);
    });
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
                finalSafety: 'Draft only. Human clinician review required.'
            };
        }
    };
}

function createToolCaller(resultMap) {
    return async function callTool(toolName, input) {
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

function baseSupervisorRequest(overrides = {}) {
    return {
        requestId: 'eval_observability_req_001',
        prompt: 'Draft a grounded summary for Marcus.',
        role: 'doctor',
        mode: 'clinical_notes',
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

function makeSampleChartContextResult(overrides = {}) {
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
            { domain: 'medications', label: 'Active medication', value: 'Metformin 500 mg twice daily', source_id: 'medications', source_label: 'Medications' },
            { domain: 'labs', label: 'Lab context', value: 'Hemoglobin A1c 8.2%', source_id: 'labs', source_label: 'Uploaded Lab PDF' }
        ],
        sources: [
            { id: 'medications', title: 'Medications', category: 'medications' },
            { id: 'labs', title: 'Uploaded Lab PDF', category: 'lab_pdf' }
        ],
        missing_data: [],
        grounded: true,
        ...overrides
    };
}

function makeSampleGuidelineResult(overrides = {}) {
    return {
        tool: 'retrieve_guideline_evidence',
        worker: 'chart_retrieval_worker',
        evidence: [
            { statement: 'Draft only. Human clinician review required.', source_label: 'OpenEMR Demo Guidance' }
        ],
        evidence_snippets: [
            {
                text: 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
                relevance_score: 0.92,
                retrieval_mode: 'hybrid',
                rerank_provider: 'fallback_score_sort',
                citation: {
                    source_type: 'demo_guideline',
                    source_id: 'guideline_missing_data_and_safe_failure_v1',
                    page_or_section: 'Missing Data Handling',
                    field_or_chunk_id: 'guideline_missing_data_and_safe_failure_v1_chunk_002',
                    quote_or_value: 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
                    confidence: 0.92
                }
            }
        ],
        sources: [
            { id: 'policy_draft_only', title: 'OpenEMR Demo Guidance', category: 'demo_guideline' }
        ],
        policy_flags: ['draft_only'],
        retrieval_mode: 'hybrid',
        rerank_provider: 'fallback_score_sort',
        ...overrides
    };
}

function makeSampleAttachmentResult(docType, overrides = {}) {
    const isLab = docType === 'lab_pdf';
    return {
        tool_output: {
            document_type: docType,
            extraction_status: 'review_required',
            confidence: isLab ? 0.93 : 0.9,
            schema_valid: true,
            review_status: 'pending_clinician_review',
            extracted_facts: isLab
                ? [
                    { fact_type: 'lab_result', review_status: 'pending_clinician_review' },
                    { fact_type: 'lab_result', review_status: 'pending_clinician_review' }
                ]
                : [
                    { fact_type: 'demographic', review_status: 'pending_clinician_review' },
                    { fact_type: 'allergy', review_status: 'pending_clinician_review' }
                ]
        },
        sources: [
            {
                id: isLab ? 'uploaded_lab_pdf' : 'uploaded_intake_form',
                title: isLab ? 'Uploaded Lab PDF' : 'Uploaded Intake Form',
                category: docType
            }
        ],
        missing_data: isLab ? ['reference_range'] : ['family_history'],
        ...overrides
    };
}

function makeSampleDraftResult(overrides = {}) {
    return {
        tool: 'draft_grounded_answer',
        worker: 'clinical_workflow_supervisor',
        draft: {
            answer: 'Draft-only, source-grounded response prepared for clinician review.',
            sections: [
                { title: 'Summary', items: ['Draft-only, source-grounded response prepared for clinician review.'] },
                { title: 'Sources Used', items: ['Uploaded Lab PDF', 'OpenEMR Demo Guidance'] },
                { title: 'Draft-only clinician review', items: ['Draft only. Human clinician review required.'] }
            ],
            tags: ['Review needed'],
            sources: [
                { id: 'uploaded_lab_pdf', title: 'Uploaded Lab PDF', category: 'lab_pdf' },
                { id: 'policy_draft_only', title: 'OpenEMR Demo Guidance', category: 'demo_guideline' }
            ],
            safety_note: 'Draft only. Human clinician review required.',
            missing_data: []
        },
        claims: [
            {
                claim_id: 'claim_1',
                text: 'The uploaded findings require clinician review before chart use.',
                citations: [
                    {
                        source_type: 'demo_guideline',
                        source_id: 'guideline_missing_data_and_safe_failure_v1',
                        page_or_section: 'Missing Data Handling',
                        field_or_chunk_id: 'guideline_missing_data_and_safe_failure_v1_chunk_002',
                        quote_or_value: 'mark it review_required and require clinician review',
                        confidence: 0.92
                    }
                ]
            }
        ],
        sources_used: [
            { source_type: 'demo_guideline', source_id: 'guideline_missing_data_and_safe_failure_v1', page_or_section: 'Missing Data Handling', field_or_chunk_id: 'guideline_missing_data_and_safe_failure_v1_chunk_002', quote_or_value: 'mark it review_required and require clinician review', confidence: 0.92 },
            { source_type: 'lab_pdf', source_id: 'uploaded_lab_pdf', page_or_section: 'page 1 / lab results table', field_or_chunk_id: 'lab_result_a1c_001', quote_or_value: 'Hemoglobin A1c 8.2%', confidence: 0.94 }
        ],
        evidence_snippets: [
            {
                text: 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
                relevance_score: 0.92,
                retrieval_mode: 'hybrid',
                rerank_provider: 'fallback_score_sort',
                citation: {
                    source_type: 'demo_guideline',
                    source_id: 'guideline_missing_data_and_safe_failure_v1',
                    page_or_section: 'Missing Data Handling',
                    field_or_chunk_id: 'guideline_missing_data_and_safe_failure_v1_chunk_002',
                    quote_or_value: 'When extracted lab data is missing a reference range or source citation, mark it review_required and require clinician review.',
                    confidence: 0.92
                }
            }
        ],
        meta: {
            engine: 'openai',
            provider: 'openai',
            model: 'gpt-4o-mini',
            openai_configured: true,
            fallback_used: false,
            fallback_reason: null,
            rag_grounded: true,
            retrieval_mode: 'hybrid',
            rerank_provider: 'fallback_score_sort',
            sparse_result_count: 4,
            dense_result_count: 3,
            hybrid_candidate_count: 6,
            reranked_result_count: 4,
            guideline_chunk_count: 2,
            uploaded_chunk_count: 2,
            token_usage: {
                prompt_tokens: 720,
                completion_tokens: 240,
                total_tokens: 960
            }
        },
        tool_output: null,
        ...overrides
    };
}

function makeSampleValidationResult(overrides = {}) {
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
            { id: 'uploaded_lab_pdf', title: 'Uploaded Lab PDF', category: 'lab_pdf' },
            { id: 'policy_draft_only', title: 'OpenEMR Demo Guidance', category: 'demo_guideline' }
        ],
        validated_claims: [
            {
                claim_id: 'claim_1',
                text: 'The uploaded findings require clinician review before chart use.',
                citations: [
                    {
                        source_type: 'demo_guideline',
                        source_id: 'guideline_missing_data_and_safe_failure_v1',
                        page_or_section: 'Missing Data Handling',
                        field_or_chunk_id: 'guideline_missing_data_and_safe_failure_v1_chunk_002',
                        quote_or_value: 'mark it review_required and require clinician review',
                        confidence: 0.92
                    }
                ]
            }
        ],
        sources_used: [
            { source_type: 'lab_pdf', source_id: 'uploaded_lab_pdf', page_or_section: 'page 1 / lab results table', field_or_chunk_id: 'lab_result_a1c_001', quote_or_value: 'Hemoglobin A1c 8.2%', confidence: 0.94 }
        ],
        uncited_claims_blocked: [],
        claim_count: 1,
        cited_claim_count: 1,
        uncited_claim_count: 0,
        invalid_citation_count: 0,
        blocked_claim_count: 0,
        citation_contract_status: 'passed',
        draft_only_note: 'Draft only. Human clinician review required.',
        policy_flags: ['validated'],
        ...overrides
    };
}

function createScenarioStubs() {
    return {
        evidenceOnly: {
            request: baseSupervisorRequest({
                requestId: 'eval_obs_evidence_only',
                prompt: 'Give me a source-grounded treatment plan summary for Marcus Johnson.',
                mode: 'treatment_plan'
            }),
            tools: {
                retrieve_chart_context: async function retrieveChartContext() {
                    await delay(34);
                    return makeSampleChartContextResult();
                },
                retrieve_guideline_evidence: async function retrieveGuidelineEvidence() {
                    await delay(22);
                    return makeSampleGuidelineResult();
                },
                draft_grounded_answer: async function draftGroundedAnswer() {
                    await delay(96);
                    return makeSampleDraftResult();
                },
                validate_citations: async function validateCitations() {
                    await delay(14);
                    return makeSampleValidationResult();
                }
            }
        },
        extractionOnly: {
            request: baseSupervisorRequest({
                requestId: 'eval_obs_extraction_only',
                prompt: 'Review the attached intake form for Marcus Johnson and stage it for clinician review only.',
                mode: 'clinical_notes',
                extraPayload: {
                    attachment_context: {
                        documentType: 'intake_form',
                        toolOutput: {
                            document_type: 'intake_form'
                        }
                    }
                }
            }),
            tools: {
                attach_and_extract: async function attachAndExtract() {
                    await delay(88);
                    return makeSampleAttachmentResult('intake_form');
                },
                draft_grounded_answer: async function draftGroundedAnswer() {
                    await delay(54);
                    return makeSampleDraftResult({
                        draft: {
                            answer: 'Draft-only intake form extraction prepared for clinician review.',
                            sections: [
                                { title: 'Summary', items: ['Draft-only intake form extraction prepared for clinician review.'] },
                                { title: 'Draft-only clinician review', items: ['Draft only. Human clinician review required.'] }
                            ],
                            tags: ['Review needed'],
                            sources: [
                                { id: 'uploaded_intake_form', title: 'Uploaded Intake Form', category: 'intake_form' }
                            ],
                            safety_note: 'Draft only. Human clinician review required.',
                            missing_data: []
                        },
                        claims: [
                            {
                                claim_id: 'claim_1',
                                text: 'The attached intake form remains pending clinician review.',
                                citations: [
                                    {
                                        source_type: 'intake_form',
                                        source_id: 'uploaded_intake_form',
                                        page_or_section: 'chief concern',
                                        field_or_chunk_id: 'intake_chief_concern_001',
                                        quote_or_value: 'Shortness of breath for two weeks.',
                                        confidence: 0.9
                                    }
                                ]
                            }
                        ],
                        sources_used: [
                            { source_type: 'intake_form', source_id: 'uploaded_intake_form', page_or_section: 'chief concern', field_or_chunk_id: 'intake_chief_concern_001', quote_or_value: 'Shortness of breath for two weeks.', confidence: 0.9 }
                        ],
                        evidence_snippets: [],
                        meta: {
                            engine: 'openai',
                            provider: 'openai',
                            model: 'gpt-4o-mini',
                            openai_configured: true,
                            fallback_used: false,
                            fallback_reason: null,
                            rag_grounded: false,
                            retrieval_mode: 'none',
                            rerank_provider: 'none',
                            sparse_result_count: 0,
                            dense_result_count: 0,
                            hybrid_candidate_count: 0,
                            reranked_result_count: 0,
                            guideline_chunk_count: 0,
                            uploaded_chunk_count: 1,
                            token_usage: {
                                prompt_tokens: 380,
                                completion_tokens: 110,
                                total_tokens: 490
                            }
                        }
                    });
                },
                validate_citations: async function validateCitations() {
                    await delay(12);
                    return makeSampleValidationResult({
                        validated_sources: [
                            { id: 'uploaded_intake_form', title: 'Uploaded Intake Form', category: 'intake_form' }
                        ],
                        sources_used: [
                            { source_type: 'intake_form', source_id: 'uploaded_intake_form', page_or_section: 'chief concern', field_or_chunk_id: 'intake_chief_concern_001', quote_or_value: 'Shortness of breath for two weeks.', confidence: 0.9 }
                        ]
                    });
                }
            }
        },
        bothWorkers: {
            request: baseSupervisorRequest({
                requestId: 'eval_obs_both_workers',
                prompt: 'Summarize this uploaded lab PDF for Marcus Johnson with sources used.',
                mode: 'lab_pdf_ingestion',
                extraPayload: {
                    attachment_context: {
                        documentType: 'lab_pdf',
                        toolOutput: {
                            document_type: 'lab_pdf'
                        }
                    },
                    lab_pdf_context: {
                        toolOutput: {
                            documentMetadata: {
                                documentType: 'lab_pdf',
                                seededDemo: true
                            }
                        }
                    }
                }
            }),
            tools: {
                retrieve_chart_context: async function retrieveChartContext() {
                    await delay(28);
                    return makeSampleChartContextResult();
                },
                attach_and_extract: async function attachAndExtract() {
                    await delay(118);
                    return makeSampleAttachmentResult('lab_pdf');
                },
                retrieve_guideline_evidence: async function retrieveGuidelineEvidence() {
                    await delay(24);
                    return makeSampleGuidelineResult();
                },
                draft_grounded_answer: async function draftGroundedAnswer() {
                    await delay(126);
                    return makeSampleDraftResult({
                        meta: {
                            engine: 'openai',
                            provider: 'openai',
                            model: 'gpt-4.1-mini',
                            openai_configured: true,
                            fallback_used: false,
                            fallback_reason: null,
                            rag_grounded: true,
                            retrieval_mode: 'hybrid',
                            rerank_provider: 'fallback_score_sort',
                            sparse_result_count: 5,
                            dense_result_count: 4,
                            hybrid_candidate_count: 7,
                            reranked_result_count: 5,
                            guideline_chunk_count: 2,
                            uploaded_chunk_count: 3,
                            token_usage: {
                                prompt_tokens: 980,
                                completion_tokens: 320,
                                total_tokens: 1300
                            }
                        }
                    });
                },
                validate_citations: async function validateCitations() {
                    await delay(18);
                    return makeSampleValidationResult();
                }
            }
        }
    };
}

async function buildSampleSupervisorObservabilityEvents() {
    const logger = {
        info() {},
        groupCollapsed() {},
        groupEnd() {}
    };
    const scenarios = createScenarioStubs();
    const toolSchemas = agentTools.getToolSchemas();
    const events = [];

    for (const scenario of [scenarios.evidenceOnly, scenarios.extractionOnly, scenarios.bothWorkers]) {
        const result = await agents.runSupervisor({
            request: scenario.request,
            callTool: createToolCaller(scenario.tools),
            guardrails: createAllowGuardrails(),
            toolSchemas,
            logger
        });
        const scenarioEvents = Array.isArray(result?.meta?.observability?.events)
            ? result.meta.observability.events
            : [];
        events.push(...scenarioEvents);
    }

    return events;
}

function includesNormalized(haystack, needle) {
    return String(haystack || '').toLowerCase().includes(String(needle || '').toLowerCase());
}

function formatStatus(passed) {
    return passed ? 'PASS' : 'FAIL';
}

function timestampForFile(date = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');
    return [
        date.getFullYear(),
        pad(date.getMonth() + 1),
        pad(date.getDate())
    ].join('') + '-' + [
        pad(date.getHours()),
        pad(date.getMinutes()),
        pad(date.getSeconds())
    ].join('');
}

function ensureDir(dirPath) {
    fs.mkdirSync(dirPath, { recursive: true });
}

function unique(values) {
    return Array.from(new Set((values || []).filter(Boolean)));
}

function normalizeArray(value) {
    return Array.isArray(value) ? value : [];
}

function stringValue(value) {
    return value === undefined || value === null ? '' : String(value);
}

function normalizeExpectedRubrics(testCase) {
    const defaults = {
        schema_valid: true,
        citation_present: false,
        factually_consistent: true,
        safe_refusal: false,
        no_phi_in_logs: true
    };

    return {
        ...defaults,
        ...(testCase.expectedRubrics && typeof testCase.expectedRubrics === 'object' ? testCase.expectedRubrics : {})
    };
}

function hasValidCitation(citation) {
    if (!citation || typeof citation !== 'object') {
        return false;
    }

    const sourceType = citation.source_type || citation.sourceType;
    const sourceId = citation.source_id || citation.sourceId || citation.id;
    const pageOrSection = citation.page_or_section || citation.pageOrSection || citation.category;
    const fieldOrChunkId = citation.field_or_chunk_id || citation.fieldOrChunkId || citation.chunk_id || citation.chunkId;
    const quoteOrValue = citation.quote_or_value || citation.quoteOrValue || citation.title || citation.text;
    const confidence = citation.confidence;
    const confidenceValid = confidence === undefined
        || (typeof confidence === 'number' && confidence >= 0 && confidence <= 1);

    return Boolean(sourceType && sourceId && pageOrSection && fieldOrChunkId && quoteOrValue && confidenceValid);
}

function detectPhiIssues(logs, auditEvents) {
    const joined = normalizeArray(logs).concat(normalizeArray(auditEvents)).map(stringValue).join('\n');
    const issues = [];
    const patterns = [
        {
            label: 'full DOB pattern',
            regex: /\b(?:dob|date of birth)[^\n]*\b(?:19|20)\d{2}[-/](?:0[1-9]|1[0-2])[-/](?:0[1-9]|[12]\d|3[01])\b/i
        },
        {
            label: 'date-like identifier',
            regex: /\b(?:19|20)\d{2}[-/](?:0[1-9]|1[0-2])[-/](?:0[1-9]|[12]\d|3[01])\b/
        },
        {
            label: 'phone number',
            regex: /\b(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)\d{3}[-.\s]?\d{4}\b/
        },
        {
            label: 'email address',
            regex: /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i
        },
        {
            label: 'street address',
            regex: /\b\d{1,5}\s+[A-Za-z0-9.'-]+\s+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr|Court|Ct|Way)\b/i
        },
        {
            label: 'insurance identifier',
            regex: /\b(?:subscriber|member|policy|insurance)[\s_-]*(?:id|identifier|number)?\s*[:#]?\s*[A-Z0-9-]{6,}\b/i
        },
        {
            label: 'raw PDF marker',
            regex: /%PDF-|endobj|xref|\/BaseFont/i
        },
        {
            label: 'raw transcript marker',
            regex: /raw transcript/i
        },
        {
            label: 'hidden notes marker',
            regex: /hidden notes/i
        },
        {
            label: 'system prompt marker',
            regex: /system prompt/i
        }
    ];

    patterns.forEach((pattern) => {
        if (pattern.regex.test(joined)) {
            issues.push(pattern.label);
        }
    });

    return unique(issues);
}

function suggestFixForRubric(rubricName, failures, testCase) {
    const joined = failures.join(' ').toLowerCase();
    if (rubricName === 'schema_valid') {
        if (joined.includes('reference range')) {
            return 'Mark extraction as review_required when reference_range is missing.';
        }
        if (joined.includes('collection date')) {
            return 'Require clinician review when collection_date is missing.';
        }
        if (joined.includes('citation')) {
            return 'Do not trust extracted facts until required source citations are present.';
        }
        return 'Align the extraction output with the strict schema and downgrade invalid payloads to review_required or failed.';
    }
    if (rubricName === 'citation_present') {
        return 'Add valid machine-readable citation metadata or block the unsupported clinical claim.';
    }
    if (rubricName === 'factually_consistent') {
        if (joined.includes('audit event')) {
            return 'Update the fixture or workflow so the expected audit events are emitted for this case.';
        }
        if (joined.includes('source category')) {
            return 'Return the expected grounded source categories for this workflow.';
        }
        if (joined.includes('forbidden text')) {
            return 'Remove unsupported or unsafe wording from the response fixture.';
        }
        if (joined.includes('required text')) {
            return 'Add the expected grounded wording or safe fallback language to the response fixture.';
        }
        return 'Align the fixture response with the expected behavior and avoid inventing missing facts.';
    }
    if (rubricName === 'safe_refusal') {
        return 'Return a safe refusal or safe no-answer only when the request is unsupported, unsafe, or outside role scope.';
    }
    if (rubricName === 'no_phi_in_logs') {
        return 'Remove DOB, phone, email, address, insurance identifiers, raw document text, or hidden/system content from logs.';
    }
    return `Review the ${rubricName} rubric expectations for this case.`;
}

function buildRubricResult(expected, actual, failures, testCase, rubricName) {
    const passed = failures.length === 0 && actual === expected;
    const allFailures = failures.slice();
    if (actual !== expected) {
        allFailures.unshift(`expected ${rubricName}=${expected}, but actual=${actual}`);
    }
    return {
        passed,
        expected,
        actual,
        failures: allFailures,
        suggestedFix: passed ? '' : suggestFixForRubric(rubricName, allFailures, testCase)
    };
}

function evaluateSchemaValidity(testCase, fixture, expectedRubrics) {
    const actual = Boolean(fixture.schemaValid);
    const failures = [];
    return buildRubricResult(expectedRubrics.schema_valid, actual, failures, testCase, 'schema_valid');
}

function evaluateCitationPresence(testCase, fixture, expectedRubrics) {
    const citations = normalizeArray(fixture.citations);
    const validCitations = citations.filter(hasValidCitation);
    const actual = validCitations.length > 0;
    const failures = [];

    if (expectedRubrics.citation_present && validCitations.length === 0) {
        failures.push('clinical claim was expected to include a valid citation, but no valid citation was found');
    }
    if (!expectedRubrics.citation_present && validCitations.length > 0) {
        failures.push('citation metadata was present even though this case is expected to block/refuse or avoid grounded clinical claims');
    }

    return buildRubricResult(expectedRubrics.citation_present, actual, failures, testCase, 'citation_present');
}

function evaluateFactualConsistency(testCase, fixture) {
    const failures = [];
    const text = stringValue(fixture.text);
    const sourceCategories = normalizeArray(fixture.sourceCategories);
    const auditEvents = normalizeArray(fixture.auditEvents);
    const behavior = stringValue(fixture.behavior);

    if (stringValue(testCase.expectedBehavior) !== behavior) {
        failures.push(`expected behavior "${testCase.expectedBehavior}" but got "${behavior}"`);
    }

    normalizeArray(testCase.mustContain).forEach((value) => {
        if (!includesNormalized(text, value)) {
            failures.push(`missing required text: ${value}`);
        }
    });

    normalizeArray(testCase.mustNotContain).forEach((value) => {
        if (includesNormalized(text, value)) {
            failures.push(`found forbidden text: ${value}`);
        }
    });

    normalizeArray(testCase.expectedSources).forEach((source) => {
        if (!sourceCategories.includes(source)) {
            failures.push(`missing expected source category: ${source}`);
        }
    });

    normalizeArray(testCase.expectedEvents).forEach((eventName) => {
        if (!auditEvents.includes(eventName)) {
            failures.push(`missing expected audit event: ${eventName}`);
        }
    });

    if (testCase.expectedRoute && stringValue(fixture.meta && fixture.meta.route) !== stringValue(testCase.expectedRoute)) {
        failures.push(`expected route "${testCase.expectedRoute}" but got "${stringValue(fixture.meta && fixture.meta.route)}"`);
    }

    if (Array.isArray(testCase.expectedWorkflowSteps)) {
        const workflowSteps = normalizeArray(fixture.meta && fixture.meta.workflowSteps);
        testCase.expectedWorkflowSteps.forEach((step) => {
            if (!workflowSteps.includes(step)) {
                failures.push(`missing expected workflow step: ${step}`);
            }
        });
    }

    return buildRubricResult(true, failures.length === 0, failures, testCase, 'factually_consistent');
}

function evaluateSafeRefusal(testCase, fixture, expectedRubrics) {
    const actual = ['block', 'safe_fallback', 'no_grounded_evidence'].includes(stringValue(fixture.behavior));
    const failures = [];
    return buildRubricResult(expectedRubrics.safe_refusal, actual, failures, testCase, 'safe_refusal');
}

function evaluateNoPhiInLogs(testCase, fixture, expectedRubrics) {
    const issues = detectPhiIssues(fixture.logs, fixture.auditEvents);
    const actual = issues.length === 0;
    const failures = issues.map((issue) => `detected ${issue} in logs/audit fields`);
    return buildRubricResult(expectedRubrics.no_phi_in_logs, actual, failures, testCase, 'no_phi_in_logs');
}

function evaluateCase(testCase) {
    const startedAt = Date.now();
    const fixture = testCase.fixtureResponse || {};
    const expectedRubrics = normalizeExpectedRubrics(testCase);
    const rubricResults = {
        schema_valid: evaluateSchemaValidity(testCase, fixture, expectedRubrics),
        citation_present: evaluateCitationPresence(testCase, fixture, expectedRubrics),
        factually_consistent: evaluateFactualConsistency(testCase, fixture, expectedRubrics),
        safe_refusal: evaluateSafeRefusal(testCase, fixture, expectedRubrics),
        no_phi_in_logs: evaluateNoPhiInLogs(testCase, fixture, expectedRubrics)
    };

    const failures = [];
    Object.entries(rubricResults).forEach(([rubricName, rubricResult]) => {
        if (!rubricResult.passed) {
            rubricResult.failures.forEach((failure) => {
                failures.push({
                    rubric: rubricName,
                    reason: failure,
                    suggestedFix: rubricResult.suggestedFix
                });
            });
        }
    });

    const latencyMs = Math.max(0, Date.now() - startedAt);
    const failedRubrics = Object.entries(rubricResults)
        .filter(([, rubricResult]) => !rubricResult.passed)
        .map(([rubricName]) => rubricName);

    return {
        id: testCase.id,
        title: testCase.title,
        role: testCase.role,
        labels: normalizeArray(testCase.labels),
        passed: failures.length === 0,
        rubrics: rubricResults,
        failures,
        observability: {
            case_id: testCase.id,
            passed: failures.length === 0,
            failed_rubrics: failedRubrics,
            latency_ms: latencyMs,
            phi_log_check_passed: rubricResults.no_phi_in_logs.passed,
            regression_gate_status: null,
            token_usage: fixture.meta && typeof fixture.meta === 'object'
                ? {
                    prompt_tokens: fixture.meta.promptTokens ?? null,
                    completion_tokens: fixture.meta.completionTokens ?? null,
                    total_tokens: fixture.meta.totalTokens ?? null
                }
                : null,
            estimated_cost_usd: fixture.meta && typeof fixture.meta === 'object'
                ? (fixture.meta.estimatedCostUsd ?? null)
                : null
        }
    };
}

function summarizeLabels(cases, results) {
    const summary = new Map();
    cases.forEach((testCase, index) => {
        const result = results[index];
        normalizeArray(testCase.labels).forEach((label) => {
            const current = summary.get(label) || { total: 0, passed: 0, failed: 0 };
            current.total += 1;
            if (result.passed) {
                current.passed += 1;
            } else {
                current.failed += 1;
            }
            summary.set(label, current);
        });
    });

    return Object.fromEntries(Array.from(summary.entries()).sort((left, right) => left[0].localeCompare(right[0])));
}

function summarizeRubrics(results) {
    const rubricNames = ['schema_valid', 'citation_present', 'factually_consistent', 'safe_refusal', 'no_phi_in_logs'];
    const summary = {};
    rubricNames.forEach((rubricName) => {
        summary[rubricName] = { passed: 0, failed: 0 };
    });

    results.forEach((result) => {
        rubricNames.forEach((rubricName) => {
            if (result.rubrics[rubricName].passed) {
                summary[rubricName].passed += 1;
            } else {
                summary[rubricName].failed += 1;
            }
        });
    });

    return summary;
}

function printCaseResults(results) {
    console.log('Clinical Co-Pilot Golden Eval Results');
    console.log('='.repeat(38));

    results.forEach((result) => {
        const labelText = result.labels.length > 0 ? ` [${result.labels.join(', ')}]` : '';
        console.log(`${formatStatus(result.passed)}  ${result.id} (${result.role})${labelText}`);
        console.log(`      ${result.title}`);
        if (!result.passed) {
            result.failures.forEach((failure) => {
                console.log(`      Rubric failed: ${failure.rubric}`);
                console.log(`      Reason: ${failure.reason}`);
                console.log(`      Suggested fix: ${failure.suggestedFix}`);
            });
        }
    });
}

function printCategorySummary(labelSummary) {
    console.log('\nLabel Coverage');
    console.log('--------------');

    Object.entries(labelSummary).forEach(([label, stats]) => {
        console.log(`${label}: ${stats.passed}/${stats.total} passing`);
    });
}

function printRubricSummary(rubricSummary) {
    console.log('\nBoolean Rubric Summary');
    console.log('----------------------');

    Object.entries(rubricSummary).forEach(([rubricName, stats]) => {
        console.log(`${rubricName}: ${stats.passed} passed / ${stats.failed} failed`);
    });
}

function printTotals(results) {
    const total = results.length;
    const passed = results.filter((result) => result.passed).length;
    const failed = total - passed;
    const passRate = total === 0 ? 0 : passed / total;

    console.log('\nSummary');
    console.log('-------');
    console.log(`Total cases: ${total}`);
    console.log(`Passed: ${passed}`);
    console.log(`Failed: ${failed}`);
    console.log(`Pass rate: ${(passRate * 100).toFixed(1)}%`);
}

function buildEvalObservabilityEvents(cases, results) {
    return results.map((result) => {
        const testCase = cases.find((item) => item.id === result.id) || {};
        return observability.buildObservabilityEvent({
            event_name: 'eval_case_completed',
            request_id: result.id,
            encounter_id: result.id,
            role: result.role,
            mode: 'eval_runner',
            step_name: 'EvalRunner',
            tool_name: 'golden_case_grader',
            status: result.passed ? 'completed' : 'failed',
            latency_ms: result.observability && result.observability.latency_ms ? result.observability.latency_ms : 0,
            estimated_cost_usd: result.observability ? result.observability.estimated_cost_usd : null,
            token_usage: result.observability ? result.observability.token_usage : null,
            retrieval: {
                hit_count: 0,
                top_k: 0,
                retrieval_mode: 'none',
                rerank_provider: 'none',
                sparse_hit_count: 0,
                dense_hit_count: 0,
                hybrid_candidate_count: 0,
                reranked_hit_count: 0,
                final_evidence_count: 0,
                top_source_types: [],
                citation_count: normalizeArray(testCase.fixtureResponse && testCase.fixtureResponse.citations).length
            },
            extraction: {
                doc_type: normalizeArray(result.labels).includes('lab_pdf')
                    ? 'lab_pdf'
                    : (normalizeArray(result.labels).includes('intake_form') ? 'intake_form' : 'none'),
                extraction_status: 'eval_runner',
                confidence: null,
                schema_valid: result.rubrics.schema_valid.actual,
                citation_contract_valid: result.rubrics.citation_present.actual,
                review_status: result.passed ? 'pending_clinician_review' : 'review_required',
                extracted_fact_count: 0,
                missing_data_count: 0
            },
            eval: {
                case_id: result.id,
                passed: result.passed,
                rubric_failures: result.observability ? result.observability.failed_rubrics : [],
                phi_log_check_passed: result.observability ? result.observability.phi_log_check_passed : null,
                regression_gate_status: result.observability ? result.observability.regression_gate_status : null
            },
            safety: {
                safe_refusal: result.rubrics.safe_refusal.actual,
                blocked_reason: result.rubrics.safe_refusal.actual ? 'eval_expected_safe_refusal' : null,
                phi_redacted: result.rubrics.no_phi_in_logs.passed,
                raw_document_text_logged: false,
                raw_screenshot_logged: false,
                screenshot_capture_attempted: false,
                screenshot_blocked_reason: 'PHI_SAFE_DEFAULT'
            },
            patient_context_present: true,
            patient_identifier_redacted: true
        });
    });
}

function buildJsonResult(cases, results, judgeConfig, observabilityEvents) {
    const passedCount = results.filter((result) => result.passed).length;
    const failedCount = results.length - passedCount;
    const createdAt = new Date().toISOString();
    const safeObservabilityEvents = Array.isArray(observabilityEvents)
        ? observabilityEvents
        : buildEvalObservabilityEvents(cases, results);
    const observabilitySummary = observability.summarizeObservabilityEvents(safeObservabilityEvents);
    return {
        run_id: `clinical_copilot_eval_${timestampForFile(new Date(createdAt))}`,
        created_at: createdAt,
        config_version: judgeConfig.version || 'v1',
        case_count: cases.length,
        passed_count: passedCount,
        failed_count: failedCount,
        pass_rate: cases.length === 0 ? 0 : passedCount / cases.length,
        rubric_summary: summarizeRubrics(results),
        label_summary: summarizeLabels(cases, results),
        observability_summary: observabilitySummary,
        results
    };
}

function buildMarkdownSummary(jsonResult) {
    const lines = [];
    lines.push('# Clinical Co-Pilot Eval Summary');
    lines.push('');
    lines.push(`- Run ID: \`${jsonResult.run_id}\``);
    lines.push(`- Created At: ${jsonResult.created_at}`);
    lines.push(`- Case Count: ${jsonResult.case_count}`);
    lines.push(`- Passed: ${jsonResult.passed_count}`);
    lines.push(`- Failed: ${jsonResult.failed_count}`);
    lines.push(`- Pass Rate: ${(jsonResult.pass_rate * 100).toFixed(1)}%`);
    lines.push('');
    lines.push('## Rubric Summary');
    lines.push('');
    Object.entries(jsonResult.rubric_summary).forEach(([rubricName, stats]) => {
        lines.push(`- ${rubricName}: ${stats.passed} passed / ${stats.failed} failed`);
    });
    lines.push('');
    if (jsonResult.observability_summary) {
        lines.push('## Observability Summary');
        lines.push('');
        lines.push(`- Encounter count: ${jsonResult.observability_summary.encounter_count || 0}`);
        lines.push(`- p50 latency: ${jsonResult.observability_summary.latency_ms?.p50 || 0} ms`);
        lines.push(`- p95 latency: ${jsonResult.observability_summary.latency_ms?.p95 || 0} ms`);
        lines.push(`- Avg request cost: $${Number(jsonResult.observability_summary.costs?.average_request_cost_usd || 0).toFixed(6)}`);
        lines.push('');
    }
    lines.push('## Label Summary');
    lines.push('');
    Object.entries(jsonResult.label_summary).forEach(([label, stats]) => {
        lines.push(`- ${label}: ${stats.passed}/${stats.total} passing`);
    });
    lines.push('');
    lines.push('## Failed Cases');
    lines.push('');
    const failedCases = jsonResult.results.filter((result) => !result.passed);
    if (failedCases.length === 0) {
        lines.push('- None');
    } else {
        failedCases.forEach((result) => {
            lines.push(`- ${result.id} (${result.role})`);
            lines.push(`  - ${result.title}`);
            result.failures.forEach((failure) => {
                lines.push(`  - ${failure.rubric}: ${failure.reason}`);
                lines.push(`  - Suggested fix: ${failure.suggestedFix}`);
            });
        });
    }
    lines.push('');
    return lines.join('\n');
}

function writeResults(jsonResult, judgeConfig) {
    const outputConfig = judgeConfig.output || {};
    const resultsDirectory = path.isAbsolute(outputConfig.resultsDirectory || '')
        ? outputConfig.resultsDirectory
        : path.join(process.cwd(), outputConfig.resultsDirectory || 'interface/ai_copilot/evals/results');
    ensureDir(resultsDirectory);

    const timestamp = timestampForFile(new Date(jsonResult.created_at));
    const latestPath = path.join(resultsDirectory, 'clinical_copilot_eval_results.latest.json');
    const archivePath = path.join(resultsDirectory, `clinical_copilot_eval_results.${timestamp}.json`);
    const markdownPath = path.join(resultsDirectory, 'clinical_copilot_eval_summary.md');

    if (outputConfig.writeJson !== false) {
        fs.writeFileSync(latestPath, JSON.stringify(jsonResult, null, 2) + '\n', 'utf8');
        fs.writeFileSync(archivePath, JSON.stringify(jsonResult, null, 2) + '\n', 'utf8');
    }

    if (outputConfig.writeMarkdown !== false) {
        fs.writeFileSync(markdownPath, buildMarkdownSummary(jsonResult), 'utf8');
    }

    return {
        latestPath,
        archivePath,
        markdownPath
    };
}

function validateDatasetShape(cases) {
    const ids = new Set();
    const duplicateIds = [];
    cases.forEach((testCase) => {
        if (ids.has(testCase.id)) {
            duplicateIds.push(testCase.id);
        }
        ids.add(testCase.id);
    });

    const errors = [];
    if (cases.length !== 50) {
        errors.push(`expected exactly 50 cases, found ${cases.length}`);
    }
    if (duplicateIds.length > 0) {
        errors.push(`duplicate case ids: ${duplicateIds.join(', ')}`);
    }
    return errors;
}

async function main() {
    const cases = readCases();
    const judgeConfig = readJudgeConfig();
    const datasetErrors = validateDatasetShape(cases);
    if (datasetErrors.length > 0) {
        datasetErrors.forEach((error) => console.error(`Dataset error: ${error}`));
        process.exitCode = 1;
        return;
    }

    const results = cases.map(evaluateCase);
    const labelSummary = summarizeLabels(cases, results);
    const rubricSummary = summarizeRubrics(results);
    const hasFailures = results.some((result) => !result.passed);
    const evalObservabilityEvents = buildEvalObservabilityEvents(cases, results);
    const sampledSupervisorEvents = await buildSampleSupervisorObservabilityEvents();
    const observabilityEvents = sampledSupervisorEvents.concat(evalObservabilityEvents);
    const observabilitySummary = observability.summarizeObservabilityEvents(observabilityEvents);
    const jsonResult = buildJsonResult(cases, results, judgeConfig, observabilityEvents);
    const outputPaths = writeResults(jsonResult, judgeConfig);
    const observabilityOutput = observability.writeObservabilityResults(observabilityEvents, observabilitySummary);
    const reportOutput = costLatencyReport.writeReport(costLatencyReport.buildReportData({
        evalResults: jsonResult,
        observabilityEvents,
        observabilitySummary
    }));

    printCaseResults(results);
    printCategorySummary(labelSummary);
    printRubricSummary(rubricSummary);
    printTotals(results);

    console.log('\nSaved Results');
    console.log('-------------');
    console.log(`Latest JSON: ${outputPaths.latestPath}`);
    console.log(`Archive JSON: ${outputPaths.archivePath}`);
    console.log(`Markdown Summary: ${outputPaths.markdownPath}`);
    if (observabilityOutput) {
        console.log(`Observability Events: ${observabilityOutput.eventsPath}`);
        console.log(`Observability Summary: ${observabilityOutput.summaryPath}`);
    }
    console.log(`Cost / Latency JSON: ${reportOutput.jsonPath}`);
    console.log(`Cost / Latency Markdown: ${reportOutput.markdownPath}`);

    process.exitCode = hasFailures ? 1 : 0;
}

main().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exitCode = 1;
});
