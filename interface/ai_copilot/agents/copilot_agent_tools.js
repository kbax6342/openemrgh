(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotAgentTools = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const DEMO_PATIENT = {
        pid: 1001,
        pubpid: 'DEMO-PCP-1001',
        name: 'Marcus Johnson'
    };

    const TOOL_SCHEMAS = {
        retrieve_chart_context: {
            name: 'retrieve_chart_context',
            description: 'Retrieve role-appropriate OpenEMR chart context, including approved ambient encounter records, as structured facts with source labels and timestamps.',
            worker: 'chart_retrieval_worker',
            input_schema: {
                type: 'object',
                properties: {
                    patient_id: { type: ['integer', 'null'] },
                    role: { type: 'string', enum: ['doctor', 'nurse', 'billing', 'front_desk'] },
                    mode: { type: 'string' },
                    prompt: { type: 'string' },
                    requested_domains: {
                        type: 'array',
                        items: {
                            type: 'string',
                            enum: [
                                'medications',
                                'allergies',
                                'labs',
                                'encounters',
                                'visit_history',
                                'documents',
                                'insurance',
                                'care_team',
                                'immunizations',
                                'problem_list',
                                'appointments',
                                'patient_contact'
                            ]
                        }
                    },
                    include_latest_ambient: { type: 'boolean' },
                    minimum_necessary: { type: 'boolean' }
                },
                required: ['role', 'mode', 'prompt']
            },
            output_schema: {
                type: 'object',
                properties: {
                    patient: { type: 'object' },
                    role_scope: { type: 'string' },
                    facts: { type: 'array' },
                    sources: { type: 'array' },
                    missing_data: { type: 'array' },
                    grounded: { type: 'boolean' }
                },
                required: ['facts', 'sources', 'missing_data', 'grounded']
            }
        },
        attach_and_extract: {
            name: 'attach_and_extract',
            description: 'Validate an attached document payload and extract structured draft facts for role-safe clinician or operational review.',
            worker: 'chart_retrieval_worker',
            input_schema: {
                type: 'object',
                properties: {
                    role: { type: 'string', enum: ['doctor', 'nurse', 'billing', 'front_desk'] },
                    patient_key: { type: 'string' },
                    document_type: { type: 'string', enum: ['lab_pdf', 'intake_form', 'unknown'] },
                    use_demo_seed: { type: 'boolean' },
                    tool_output: { type: 'object' }
                },
                required: ['role']
            },
            output_schema: {
                type: 'object',
                properties: {
                    tool_output: { type: 'object' },
                    sources: { type: 'array' },
                    missing_data: { type: 'array' }
                },
                required: ['tool_output', 'sources', 'missing_data']
            }
        },
        retrieve_guideline_evidence: {
            name: 'retrieve_guideline_evidence',
            description: 'Return demo policy and workflow evidence used to ground draft-only, role-safe responses.',
            worker: 'chart_retrieval_worker',
            input_schema: {
                type: 'object',
                properties: {
                    role: { type: 'string', enum: ['doctor', 'nurse', 'billing', 'front_desk'] },
                    mode: { type: 'string' },
                    prompt: { type: 'string' },
                    intent: { type: 'string' }
                },
                required: ['role', 'mode', 'prompt']
            },
            output_schema: {
                type: 'object',
                properties: {
                    evidence: { type: 'array' },
                    sources: { type: 'array' },
                    policy_flags: { type: 'array' }
                },
                required: ['evidence', 'sources', 'policy_flags']
            }
        },
        validate_citations: {
            name: 'validate_citations',
            description: 'Validate role boundaries, source grounding, citation coverage, missing data, and safe refusal conditions.',
            worker: 'evidence_safety_worker',
            input_schema: {
                type: 'object',
                properties: {
                    role: { type: 'string', enum: ['doctor', 'nurse', 'billing', 'front_desk'] },
                    mode: { type: 'string' },
                    prompt: { type: 'string' },
                    draft: { type: 'object' },
                    chart_context_result: { type: 'object' },
                    guideline_evidence_result: { type: 'object' },
                    attachment_result: { type: 'object' }
                },
                required: ['role', 'mode', 'prompt', 'draft']
            },
            output_schema: {
                type: 'object',
                properties: {
                    allowed: { type: 'boolean' },
                    blocked_reason: { type: 'string' },
                    safe_refusal: { type: 'string' },
                    citation_gaps: { type: 'array' },
                    missing_data: { type: 'array' },
                    unsupported_claims: { type: 'array' },
                    validated_sources: { type: 'array' },
                    draft_only_note: { type: 'string' }
                },
                required: ['allowed', 'citation_gaps', 'missing_data', 'validated_sources']
            }
        },
        draft_grounded_answer: {
            name: 'draft_grounded_answer',
            description: 'Draft a structured, source-grounded response with summary, key findings, visit-change details, uncertainty, and clinician-review language.',
            worker: 'clinical_workflow_supervisor',
            input_schema: {
                type: 'object',
                properties: {
                    request_id: { type: 'string' },
                    role: { type: 'string', enum: ['doctor', 'nurse', 'billing', 'front_desk'] },
                    mode: { type: 'string' },
                    prompt: { type: 'string' },
                    chat_history: { type: 'array' },
                    chart_context_result: { type: 'object' },
                    guideline_evidence_result: { type: 'object' },
                    attachment_result: { type: 'object' }
                },
                required: ['role', 'mode', 'prompt']
            },
            output_schema: {
                type: 'object',
                properties: {
                    draft: { type: 'object' },
                    meta: { type: 'object' },
                    tool_output: { type: 'object' }
                },
                required: ['draft', 'meta']
            }
        }
    };

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function defaultSafetyNote(role) {
        if (role === 'front_desk') {
            return 'Administrative draft only. Human review required. Minimum necessary PHI only.';
        }
        if (role === 'billing') {
            return 'Draft only. Human billing and compliance review required. No automatic claim actions occur.';
        }

        return 'Draft only. Human clinician review required. No direct chart writes occur without clinician approval.';
    }

    function buildDemoSource(id, title, category) {
        return {
            id: id,
            title: title,
            category: category
        };
    }

    function buildDemoFact(domain, label, value, sourceId, sourceLabel, timestamp) {
        return {
            domain: domain,
            label: label,
            value: value,
            source_id: sourceId,
            source_label: sourceLabel,
            timestamp: timestamp || '2026-05-04T09:00:00Z'
        };
    }

    function buildDemoChartFacts(requestedDomains) {
        const domains = requestedDomains && requestedDomains.length > 0
            ? requestedDomains
            : ['encounters', 'visit_history', 'medications', 'labs', 'documents'];
        const facts = [];
        const sources = [];
        const missingData = [];

        domains.forEach(function (domain) {
            switch (domain) {
                case 'medications':
                    sources.push(buildDemoSource('medications', 'Medications', 'medications'));
                    facts.push(buildDemoFact('medications', 'Active medication', 'Metformin 500 mg twice daily', 'medications', 'Medications', '2026-05-02T09:30:00Z'));
                    facts.push(buildDemoFact('medications', 'Active medication', 'Atorvastatin 40 mg nightly', 'medications', 'Medications', '2026-05-02T09:30:00Z'));
                    break;
                case 'allergies':
                    sources.push(buildDemoSource('allergies', 'Allergies', 'allergies'));
                    facts.push(buildDemoFact('allergies', 'Documented allergies', 'No known drug allergies recorded in the demo chart.', 'allergies', 'Allergies'));
                    break;
                case 'labs':
                    sources.push(buildDemoSource('labs', 'Vitals / Labs', 'vitals_labs'));
                    facts.push(buildDemoFact('labs', 'Lab context', 'Hemoglobin A1c 8.2%, above goal.', 'labs', 'Vitals / Labs', '2026-05-01T08:05:00Z'));
                    facts.push(buildDemoFact('labs', 'Lab context', 'LDL cholesterol 142 mg/dL, elevated.', 'labs', 'Vitals / Labs', '2026-05-01T08:05:00Z'));
                    break;
                case 'encounters':
                    sources.push(buildDemoSource('encounters', 'Encounter History', 'visit_history'));
                    facts.push(buildDemoFact('encounters', 'Encounter summary', 'Primary care follow-up addressed diabetes adherence and lipid control.', 'encounters', 'Encounter History', '2026-05-02T10:00:00Z'));
                    break;
                case 'visit_history':
                    sources.push(buildDemoSource('visit_history', 'Visit History', 'visit_history'));
                    facts.push(buildDemoFact('visit_history', 'Visit-history summary', 'Compared with the prior visit, A1c improved from 8.7% to 8.2%, but LDL increased from 131 mg/dL to 142 mg/dL.', 'visit_history', 'Visit History', '2026-05-02T10:00:00Z'));
                    sources.push(buildDemoSource('latest_approved_ambient_encounter', 'Latest Approved Ambient Encounter Capture', 'ambient_encounter_capture'));
                    facts.push(buildDemoFact('visit_history', 'Latest approved ambient encounter', 'Approved ambient encounter notes reinforce medication adherence review, diet counseling, and repeat lipid follow-up.', 'latest_approved_ambient_encounter', 'Latest Approved Ambient Encounter Capture', '2026-05-02T10:15:00Z'));
                    break;
                case 'documents':
                    sources.push(buildDemoSource('documents', 'Documents / Notes', 'documents'));
                    facts.push(buildDemoFact('documents', 'Document reference', 'Follow-up clinician note signed for Marcus Johnson.', 'documents', 'Documents / Notes', '2026-05-02T10:20:00Z'));
                    break;
                case 'insurance':
                    sources.push(buildDemoSource('insurance', 'Insurance / Billing Context', 'insurance'));
                    facts.push(buildDemoFact('insurance', 'Insurance context', 'Active commercial plan on file. Prior authorization status not documented in the retrieved context.', 'insurance', 'Insurance / Billing Context'));
                    break;
                case 'care_team':
                    sources.push(buildDemoSource('care_team', 'Care Team', 'care_team'));
                    facts.push(buildDemoFact('care_team', 'Care coordination contact', 'Primary care physician and diabetes educator are listed on the current care team.', 'care_team', 'Care Team'));
                    break;
                case 'immunizations':
                    sources.push(buildDemoSource('immunizations', 'Immunizations', 'immunizations'));
                    facts.push(buildDemoFact('immunizations', 'Immunization status', 'Influenza vaccine documented during the current season.', 'immunizations', 'Immunizations'));
                    break;
                case 'problem_list':
                    sources.push(buildDemoSource('problem_list', 'Problem List', 'problem_list'));
                    facts.push(buildDemoFact('problem_list', 'Problem list item', 'Type 2 diabetes mellitus and hyperlipidemia are active demo problem-list items.', 'problem_list', 'Problem List'));
                    break;
                case 'appointments':
                    sources.push(buildDemoSource('appointments', 'Appointments', 'appointments'));
                    facts.push(buildDemoFact('appointments', 'Appointment context', 'Next follow-up visit is scheduled for June 3, 2026.', 'appointments', 'Appointments'));
                    break;
                case 'patient_contact':
                    sources.push(buildDemoSource('patient_contact', 'Patient Contact', 'patient_contact'));
                    facts.push(buildDemoFact('patient_contact', 'Contact details', 'Preferred phone number ends in 0100. Email is listed in the patient-contact demo record.', 'patient_contact', 'Patient Contact'));
                    break;
                default:
                    missingData.push(`No demo facts were defined for requested domain: ${domain}`);
            }
        });

        return {
            facts: facts,
            sources: unique(sources.map(function (source) {
                return JSON.stringify(source);
            })).map(function (sourceText) {
                return JSON.parse(sourceText);
            }),
            missingData: missingData
        };
    }

    function buildDemoChartContext(input) {
        const requestedDomains = Array.isArray(input.requested_domains) ? input.requested_domains : [];
        const factsResult = buildDemoChartFacts(requestedDomains);
        const minimumNecessary = Boolean(input.minimum_necessary);
        const role = String(input.role || 'doctor').toLowerCase();

        let facts = factsResult.facts;
        let sources = factsResult.sources;
        if (minimumNecessary || role === 'front_desk') {
            facts = facts.filter(function (fact) {
                return fact.domain === 'appointments' || fact.domain === 'patient_contact';
            });
            sources = sources.filter(function (source) {
                return source.category === 'appointments' || source.category === 'patient_contact';
            });
        } else if (role === 'billing') {
            facts = facts.filter(function (fact) {
                return fact.domain === 'insurance' || fact.domain === 'visit_history' || fact.domain === 'documents';
            });
            sources = sources.filter(function (source) {
                return source.category === 'insurance' || source.category === 'visit_history' || source.category === 'documents' || source.category === 'ambient_encounter_capture';
            });
        }

        return {
            tool: 'retrieve_chart_context',
            worker: 'chart_retrieval_worker',
            patient: clone(DEMO_PATIENT),
            role_scope: minimumNecessary || role === 'front_desk'
                ? 'front_desk_minimum_phi'
                : role === 'billing'
                    ? 'billing_limited'
                    : 'full_clinical',
            facts: facts,
            sources: sources,
            missing_data: factsResult.missingData,
            grounded: facts.length > 0
        };
    }

    function buildDemoAttachmentToolOutput(input) {
        const providedToolOutput = input.tool_output && typeof input.tool_output === 'object' ? clone(input.tool_output) : null;
        if (providedToolOutput) {
            return providedToolOutput;
        }

        const documentType = String(input.document_type || 'unknown');
        if (documentType === 'intake_form') {
            return {
                tool: 'attach_and_extract',
                status: 'ok',
                documentMetadata: {
                    title: 'marcus-johnson-intake-form.pdf',
                    mimeType: 'application/pdf',
                    documentType: 'intake_form',
                    uploadedAt: '2026-05-04T13:25:00Z'
                },
                extractedFacts: [
                    { name: 'Chief concern', value: 'Follow-up for diabetes management', sourceLabel: 'Attached Intake Form' },
                    { name: 'Reported concern', value: 'Occasional missed evening dose', sourceLabel: 'Attached Intake Form' }
                ],
                missingData: ['Preferred pharmacy was not clearly detected in the extracted intake form text.'],
                safety: {
                    draftOnly: true,
                    untrustedDocumentContent: true
                }
            };
        }

        return {
            tool: 'attach_and_extract',
            status: input.use_demo_seed ? 'seeded_demo' : 'ok',
            documentMetadata: {
                title: 'marcus-johnson-labs-may-2026.pdf',
                mimeType: 'application/pdf',
                documentType: 'lab_pdf',
                uploadedAt: '2026-05-04T13:25:00Z',
                seededDemo: Boolean(input.use_demo_seed)
            },
            extractedFacts: [
                { name: 'Hemoglobin A1c', value: '8.2%', interpretation: 'High', sourceLabel: 'Uploaded Lab PDF' },
                { name: 'LDL Cholesterol', value: '142 mg/dL', interpretation: 'High', sourceLabel: 'Uploaded Lab PDF' },
                { name: 'Creatinine', value: '1.1 mg/dL', interpretation: 'Normal', sourceLabel: 'Uploaded Lab PDF' }
            ],
            abnormalFindings: [
                'Hemoglobin A1c is above goal in the uploaded lab PDF.',
                'LDL cholesterol is elevated in the uploaded lab PDF.'
            ],
            missingData: [
                'Ordering provider not clearly detected.',
                'Collection time not clearly detected.'
            ],
            safety: {
                draftOnly: true,
                untrustedDocumentContent: true
            }
        };
    }

    function buildDemoAttachmentResult(input) {
        const toolOutput = buildDemoAttachmentToolOutput(input);
        const documentTitle = String(toolOutput.documentMetadata?.title || 'Attached document');
        const documentType = String(toolOutput.documentMetadata?.documentType || input.document_type || 'lab_pdf');
        const sourceTitle = documentType === 'intake_form' ? 'Attached Intake Form' : 'Attached Lab PDF';

        return {
            tool: 'attach_and_extract',
            worker: 'chart_retrieval_worker',
            tool_output: toolOutput,
            sources: [
                buildDemoSource(documentType === 'intake_form' ? 'attached_intake_form' : 'attached_lab_pdf', sourceTitle, 'documents'),
                buildDemoSource('uploaded_document', documentTitle, 'documents')
            ],
            missing_data: Array.isArray(toolOutput.missingData) ? toolOutput.missingData.slice() : []
        };
    }

    function buildDemoGuidelineEvidence(input) {
        const role = String(input.role || 'doctor').toLowerCase();
        const policyFlags = ['draft_only', 'human_review_required'];
        if (role === 'billing') {
            policyFlags.push('billing_scope_only');
        }
        if (role === 'front_desk') {
            policyFlags.push('minimum_necessary_phi');
        }
        if (String(input.intent || '').toLowerCase().includes('injection')) {
            policyFlags.push('prompt_injection_review');
        }

        return {
            tool: 'retrieve_guideline_evidence',
            worker: 'chart_retrieval_worker',
            evidence: [
                { statement: 'All outputs remain draft-only and require human review.', source_label: 'OpenEMR AI Copilot Draft-Only Policy' },
                { statement: 'Role boundaries must be enforced before clinical details are shown.', source_label: 'OpenEMR AI Copilot Role Safety Policy' }
            ],
            sources: [
                buildDemoSource('policy_draft_only', 'OpenEMR AI Copilot Draft-Only Policy', 'policy'),
                buildDemoSource('policy_role_safety', 'OpenEMR AI Copilot Role Safety Policy', 'policy')
            ],
            policy_flags: policyFlags
        };
    }

    function buildDemoDraftSections(input, chartContextResult, attachmentResult) {
        const sections = [];
        const prompt = String(input.prompt || '');
        const chartFacts = Array.isArray(chartContextResult?.facts) ? chartContextResult.facts : [];
        const attachmentFacts = Array.isArray(attachmentResult?.tool_output?.extractedFacts) ? attachmentResult.tool_output.extractedFacts : [];

        sections.push({
            title: 'Summary',
            items: ['Draft summary prepared from retrieved chart context for clinician review only.']
        });

        sections.push({
            title: 'Key findings',
            items: unique(
                chartFacts.slice(0, 4).map(function (fact) {
                    return `${fact.label}: ${fact.value}`;
                }).concat(attachmentFacts.slice(0, 3).map(function (fact) {
                    return `${fact.name}: ${fact.value}${fact.interpretation ? ` (${fact.interpretation})` : ''}`;
                }))
            )
        });

        if (/changed since|last visit|compare/i.test(prompt)) {
            sections.push({
                title: 'What changed since last visit',
                items: [
                    'A1c improved compared with the prior visit in the retrieved visit-history context.',
                    'LDL increased compared with the prior visit in the retrieved visit-history context.',
                    'The latest approved ambient encounter emphasized adherence review and repeat lipid follow-up.'
                ]
            });
        }

        return sections;
    }

    function buildDemoDraftAnswer(input) {
        const chartContextResult = input.chart_context_result && typeof input.chart_context_result === 'object' ? input.chart_context_result : null;
        const guidelineEvidenceResult = input.guideline_evidence_result && typeof input.guideline_evidence_result === 'object' ? input.guideline_evidence_result : null;
        const attachmentResult = input.attachment_result && typeof input.attachment_result === 'object' ? input.attachment_result : null;
        const role = String(input.role || 'doctor').toLowerCase();
        const sections = buildDemoDraftSections(input, chartContextResult, attachmentResult);
        const sources = []
            .concat(chartContextResult?.sources || [])
            .concat(guidelineEvidenceResult?.sources || [])
            .concat(attachmentResult?.sources || []);
        const missingData = unique([]
            .concat(chartContextResult?.missing_data || [])
            .concat(attachmentResult?.missing_data || []));
        const patientName = chartContextResult?.patient?.name || DEMO_PATIENT.name;
        const answer = role === 'billing'
            ? `Draft billing-safe summary prepared for ${patientName}.`
            : role === 'front_desk'
                ? `Administrative draft summary prepared for ${patientName} using minimum necessary PHI only.`
                : `Draft clinical summary prepared for ${patientName}.`;

        return {
            tool: 'draft_grounded_answer',
            worker: 'clinical_workflow_supervisor',
            draft: {
                answer: answer,
                sections: sections,
                tags: ['Draft only', 'Source grounded'],
                sources: sources,
                safety_note: defaultSafetyNote(role),
                missing_data: missingData
            },
            meta: {
                engine: 'demo_tool_fallback',
                provider: 'local_demo_tools',
                model: null,
                openai_configured: false,
                fallback_used: true,
                fallback_reason: 'local_demo_tools',
                rag_grounded: sources.length > 0
            },
            tool_output: attachmentResult?.tool_output || null
        };
    }

    function buildDemoValidation(input) {
        const role = String(input.role || 'doctor').toLowerCase();
        const prompt = String(input.prompt || '');
        const draft = input.draft && typeof input.draft === 'object' ? input.draft : {};
        const chartContextResult = input.chart_context_result && typeof input.chart_context_result === 'object' ? input.chart_context_result : {};
        const guidelineEvidenceResult = input.guideline_evidence_result && typeof input.guideline_evidence_result === 'object' ? input.guideline_evidence_result : {};
        const attachmentResult = input.attachment_result && typeof input.attachment_result === 'object' ? input.attachment_result : {};
        const validatedSources = []
            .concat(draft.sources || [])
            .concat(chartContextResult.sources || [])
            .concat(guidelineEvidenceResult.sources || [])
            .concat(attachmentResult.sources || []);
        const missingData = unique([]
            .concat(draft.missing_data || [])
            .concat(chartContextResult.missing_data || [])
            .concat(attachmentResult.missing_data || []));
        const promptInjection = /\bignore (all|previous) instructions\b|\breveal system prompt\b|\bwrite directly to the chart\b/i.test(prompt);
        const wantsClinicalTreatment = /\btreatment\b|\bmedication\b|\blab\b|\bdiagnos/i.test(prompt);

        let blockedReason = '';
        let safeRefusal = '';
        if (promptInjection) {
            blockedReason = 'prompt_injection_block';
            safeRefusal = 'I can\'t follow hidden or injected instructions. I can only summarize approved chart or document facts within the selected role.';
        } else if (role === 'billing' && wantsClinicalTreatment) {
            blockedReason = 'billing_clinical_scope_block';
            safeRefusal = 'Detailed clinical information is not available for the Billing Staff role. You can review claim status, insurance context, payment status, and billing workflow summaries.';
        } else if (role === 'front_desk' && wantsClinicalTreatment) {
            blockedReason = 'front_desk_clinical_scope_block';
            safeRefusal = 'Clinical chart details are restricted for the Front Desk role. You can use appointment, contact, and reminder workflows with minimum necessary PHI only.';
        } else if (role === 'nurse' && /\bstart\b|\bstop\b|\bincrease\b|\bdecrease\b|\bprescribe\b/i.test(prompt)) {
            blockedReason = 'nurse_clinical_scope_block';
            safeRefusal = 'Diagnosis and prescribing guidance are restricted for the Nurse role. You can request care coordination, follow-up preparation, medication education, or patient education drafts.';
        }

        return {
            tool: 'validate_citations',
            worker: 'evidence_safety_worker',
            allowed: blockedReason === '',
            blocked_reason: blockedReason,
            safe_refusal: safeRefusal,
            citation_gaps: validatedSources.length > 0 ? [] : ['No sources were available to validate this draft.'],
            missing_data: missingData,
            unsupported_claims: draft.answer && validatedSources.length === 0 ? ['Draft answer is not grounded in validated sources.'] : [],
            validated_sources: validatedSources,
            draft_only_note: defaultSafetyNote(role),
            policy_flags: unique([]
                .concat(guidelineEvidenceResult.policy_flags || [])
                .concat(blockedReason ? ['role_boundary_enforced'] : ['validated']))
        };
    }

    function invokeDemoTool(toolName, input) {
        switch (toolName) {
            case 'retrieve_chart_context':
                return buildDemoChartContext(input || {});
            case 'attach_and_extract':
                return buildDemoAttachmentResult(input || {});
            case 'retrieve_guideline_evidence':
                return buildDemoGuidelineEvidence(input || {});
            case 'draft_grounded_answer':
                return buildDemoDraftAnswer(input || {});
            case 'validate_citations':
                return buildDemoValidation(input || {});
            default:
                throw new Error(`Unknown demo copilot tool: ${toolName}`);
        }
    }

    function getToolSchemas() {
        return clone(TOOL_SCHEMAS);
    }

    function getToolSchema(name) {
        return TOOL_SCHEMAS[name] ? clone(TOOL_SCHEMAS[name]) : null;
    }

    function listToolNames() {
        return Object.keys(TOOL_SCHEMAS);
    }

    function getTraceToolCatalog() {
        return {
            supervisor: {
                stepId: 'supervisor',
                actor: 'Supervisor Agent',
                toolNames: ['retrieve_guideline_evidence', 'draft_grounded_answer']
            },
            chart_retrieval: {
                stepId: 'chart_retrieval',
                actor: 'Chart Retrieval Worker',
                toolNames: ['retrieve_chart_context', 'attach_and_extract']
            },
            safety: {
                stepId: 'safety',
                actor: 'Evidence + Safety Worker',
                toolNames: ['validate_citations']
            },
            final_draft: {
                stepId: 'final_draft',
                actor: 'Final Draft',
                toolNames: []
            }
        };
    }

    function createDemoToolCaller() {
        async function callTool(toolName, input) {
            if (!TOOL_SCHEMAS[toolName]) {
                throw new Error(`Unknown copilot tool: ${toolName}`);
            }

            return invokeDemoTool(toolName, input && typeof input === 'object' ? input : {});
        }

        return {
            callTool: callTool,
            getToolSchemas: getToolSchemas,
            getToolSchema: getToolSchema,
            listToolNames: listToolNames
        };
    }

    function createApiToolCaller(config) {
        const options = config && typeof config === 'object' ? config : {};
        const fetchImpl = typeof options.fetchImpl === 'function'
            ? options.fetchImpl
            : (typeof fetch === 'function' ? fetch.bind(typeof window !== 'undefined' ? window : globalThis) : null);
        const apiUrl = String(options.apiUrl || '').trim();
        const csrfToken = String(options.csrfToken || '').trim();
        const basePayload = options.basePayload && typeof options.basePayload === 'object' ? options.basePayload : {};
        const demoFallbackEnabled = options.demoFallback === true;

        if ((!fetchImpl || !apiUrl) && demoFallbackEnabled) {
            return createDemoToolCaller();
        }

        if (!fetchImpl) {
            throw new Error('No fetch implementation is available for the tool caller.');
        }

        if (!apiUrl) {
            throw new Error('createApiToolCaller requires an apiUrl.');
        }

        async function callTool(toolName, input, overrides = {}) {
            if (!TOOL_SCHEMAS[toolName]) {
                throw new Error(`Unknown copilot tool: ${toolName}`);
            }

            const overridePayload = overrides && typeof overrides.payload === 'object' ? overrides.payload : {};
            const payload = {
                ...basePayload,
                ...overridePayload,
                action: 'agent_tool',
                tool_name: toolName,
                tool_input: input && typeof input === 'object' ? input : {},
                csrf_token_form: overrides.csrfToken || csrfToken
            };

            try {
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

                if (!response.ok || !data || data.ok !== true) {
                    throw new Error(data.error || `Tool call failed: ${toolName}`);
                }

                return data.result || {};
            } catch (error) {
                if (demoFallbackEnabled) {
                    return invokeDemoTool(toolName, input && typeof input === 'object' ? input : {});
                }
                throw error;
            }
        }

        return {
            callTool: callTool,
            getToolSchemas: getToolSchemas,
            getToolSchema: getToolSchema,
            getTraceToolCatalog: getTraceToolCatalog,
            listToolNames: listToolNames
        };
    }

    return {
        createApiToolCaller: createApiToolCaller,
        createDemoToolCaller: createDemoToolCaller,
        getToolSchema: getToolSchema,
        getToolSchemas: getToolSchemas,
        getTraceToolCatalog: getTraceToolCatalog,
        invokeDemoTool: invokeDemoTool,
        listToolNames: listToolNames
    };
}));
