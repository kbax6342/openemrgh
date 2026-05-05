(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        const traceModule = require('./copilot_agent_trace.js');
        const safetyModule = require('./copilot_agent_safety.js');
        module.exports = factory(traceModule, safetyModule);
        return;
    }

    root.OpenEMRCopilotAgents = factory(root.OpenEMRCopilotAgentTrace, root.OpenEMRCopilotAgentSafety);
}(typeof globalThis !== 'undefined' ? globalThis : this, function (traceModule, safetyModule) {
    'use strict';

    const traceHelpers = traceModule && typeof traceModule === 'object' ? traceModule : {};
    const safetyHelpers = safetyModule && typeof safetyModule === 'object' ? safetyModule : {};

    const FRONT_DESK_MODES = new Set(['appointment_info', 'patient_contact', 'send_reminder', 'front_desk_summary']);
    const BILLING_MODES = new Set(['billing', 'billing_review']);
    const CHART_RETRIEVAL_MODES = new Set([
        'medication_info',
        'clinical_notes',
        'treatment_plan',
        'follow_up',
        'rag_chart_context',
        'latest_ambient_summary',
        'visit_summary',
        'patient_education',
        'billing',
        'billing_review',
        'lab_pdf_ingestion'
    ]);

    const DEMO_USE_CASES = [
        { id: 'morning_follow_up_prep', title: 'Morning follow-up prep', role: 'doctor', mode: 'follow_up' },
        { id: 'treatment_plan_summary', title: 'Treatment plan summary', role: 'doctor', mode: 'treatment_plan' },
        { id: 'medication_information', title: 'Medication information', role: 'doctor', mode: 'medication_info' },
        { id: 'lab_pdf_extraction', title: 'Lab PDF extraction', role: 'doctor', mode: 'lab_pdf_ingestion' },
        { id: 'intake_form_extraction', title: 'Intake form extraction', role: 'nurse', mode: 'clinical_notes' },
        { id: 'billing_payment_question', title: 'Billing/payment question', role: 'billing', mode: 'billing' },
        { id: 'prompt_injection_refusal', title: 'Prompt-injection or role-boundary refusal', role: 'front_desk', mode: 'general_assistant' }
    ];

    const unique = typeof safetyHelpers.unique === 'function'
        ? safetyHelpers.unique
        : function fallbackUnique(values) {
            return Array.from(new Set((values || []).filter(Boolean)));
        };

    const defaultSafetyNoteFor = typeof safetyHelpers.defaultSafetyNoteFor === 'function'
        ? safetyHelpers.defaultSafetyNoteFor
        : function fallbackDefaultSafetyNote(role) {
            if (role === 'front_desk') {
                return 'Administrative draft only. Human review required. Minimum necessary PHI only.';
            }
            if (role === 'billing') {
                return 'Draft only. Human billing and compliance review required. No automatic claim actions occur.';
            }

            return 'Draft only. Human clinician review required. No direct chart writes occur without clinician approval.';
        };

    const normalizeSources = typeof safetyHelpers.normalizeSources === 'function'
        ? safetyHelpers.normalizeSources
        : function fallbackNormalizeSources(sources) {
            return Array.isArray(sources) ? sources.slice() : [];
        };

    const normalizeFinalSections = typeof safetyHelpers.normalizeFinalSections === 'function'
        ? safetyHelpers.normalizeFinalSections
        : function fallbackNormalizeFinalSections(draft) {
            return {
                sections: Array.isArray(draft?.sections) ? draft.sections.slice() : [],
                sources: normalizeSources(draft?.sources || [])
            };
        };

    const buildBlockedSections = typeof safetyHelpers.buildBlockedSections === 'function'
        ? safetyHelpers.buildBlockedSections
        : function fallbackBuildBlockedSections(validation, toolResults, request) {
            return [{
                title: 'Summary',
                tone: 'yellow',
                items: [validation.safe_refusal || (safetyHelpers.defaultRoleBoundaryMessage
                    ? safetyHelpers.defaultRoleBoundaryMessage(request.role, validation.blocked_reason)
                    : 'This request was blocked for role or safety reasons.')]
            }];
        };

    const defaultRoleBoundaryMessage = typeof safetyHelpers.defaultRoleBoundaryMessage === 'function'
        ? safetyHelpers.defaultRoleBoundaryMessage
        : function fallbackRoleBoundaryMessage() {
            return 'This request is outside the allowed demo role scope.';
        };

    const createTraceCollector = typeof traceHelpers.createTraceCollector === 'function'
        ? traceHelpers.createTraceCollector
        : function fallbackCreateTraceCollector(logger) {
            const trace = [];
            return {
                trace: trace,
                push(actor, label, detail, extra) {
                    const step = { actor: actor, label: label, detail: detail || '', extra: extra || {} };
                    trace.push(step);
                    if (logger && typeof logger.info === 'function') {
                        logger.info(`[OpenEMR Copilot][${actor}] ${label}`, detail || '', extra || {});
                    }
                    return step;
                }
            };
        };

    const logTrace = typeof traceHelpers.logTrace === 'function'
        ? traceHelpers.logTrace
        : function fallbackLogTrace(trace, logger) {
            (trace || []).forEach(function (step) {
                if (logger && typeof logger.info === 'function') {
                    logger.info(`[OpenEMR Copilot][${step.actor}] ${step.label}`, step.detail || '', step.extra || {});
                }
            });
        };

    function inferIntentLabel(mode, prompt) {
        const normalizedMode = String(mode || '').trim().toLowerCase();
        const value = String(prompt || '').toLowerCase();

        if (normalizedMode === 'follow_up' || /\bmorning prep|morning follow-up|prep for clinic\b/.test(value)) {
            return 'morning_follow_up_prep';
        }
        if (normalizedMode === 'treatment_plan' || /\btreatment plan|care plan|what changed since the last visit\b/.test(value)) {
            return 'clinical_treatment_plan_summary';
        }
        if (normalizedMode === 'medication_info' || /\bmedication|dose|refill|interaction\b/.test(value)) {
            return 'medication_information';
        }
        if (normalizedMode === 'lab_pdf_ingestion' || /\blab pdf|uploaded lab|summarize this lab report\b/.test(value)) {
            return 'lab_pdf_extraction';
        }
        if (/\bintake form|questionnaire|new patient form\b/.test(value)) {
            return 'intake_form_extraction';
        }
        if (normalizedMode) {
            return normalizedMode;
        }
        if (/(billing|claim|payer|insurance|balance due|payment due)/.test(value)) {
            return 'billing_payment_question';
        }
        if (/(appointment|contact|reminder|check-in|front desk)/.test(value)) {
            return 'front_desk_summary';
        }
        if (/(medication|allergy|lab|visit history|chart|summary|clinical note|treatment)/.test(value)) {
            return 'clinical_summary';
        }
        if (/(ignore all previous instructions|reveal system prompt|write directly to the chart)/.test(value)) {
            return 'prompt_injection_refusal';
        }

        return 'general_assistant';
    }

    function requestedDomainsFor(request) {
        const role = String(request.role || 'doctor').toLowerCase();
        const mode = String(request.mode || 'general_assistant').toLowerCase();
        const prompt = String(request.prompt || '').toLowerCase();

        if (role === 'front_desk' || FRONT_DESK_MODES.has(mode)) {
            return ['appointments', 'patient_contact'];
        }

        if (role === 'billing' || BILLING_MODES.has(mode)) {
            return ['insurance', 'visit_history', 'documents'];
        }

        const domains = ['encounters', 'visit_history', 'documents'];
        if (mode === 'lab_pdf_ingestion' || /pdf|lab/.test(prompt)) {
            domains.push('labs');
        }
        if (/(medication|medications|dose|refill|interaction)/.test(prompt) || mode === 'medication_info') {
            domains.push('medications');
        }
        if (/(allergy|allergies)/.test(prompt)) {
            domains.push('allergies');
        }
        if (/(lab|troponin|a1c|glucose|ldl|creatinine|wbc)/.test(prompt) || ['treatment_plan', 'follow_up', 'rag_chart_context', 'clinical_notes', 'visit_summary'].includes(mode)) {
            domains.push('labs');
        }
        if (/(insurance|billing|payer|policy|claim|payment)/.test(prompt) || BILLING_MODES.has(mode)) {
            domains.push('insurance');
        }
        if (/(care team|daughter|support contact|care support)/.test(prompt) || ['rag_chart_context', 'latest_ambient_summary'].includes(mode)) {
            domains.push('care_team');
        }
        if (/(immunization|vaccine|flu)/.test(prompt) || ['rag_chart_context', 'visit_summary', 'patient_education'].includes(mode)) {
            domains.push('immunizations');
        }
        if (/(problem|condition|diagnosis|summary)/.test(prompt) || ['clinical_notes', 'treatment_plan', 'differential_diagnosis'].includes(mode)) {
            domains.push('problem_list');
        }

        return unique(domains);
    }

    function classifyIntent(request) {
        const prompt = String(request.prompt || '');
        const mode = String(request.mode || 'general_assistant');
        const patientSelected = Boolean(request.patientId);
        const labPdfAttached = Boolean(
            request.extraPayload
            && request.extraPayload.lab_pdf_context
            && request.extraPayload.lab_pdf_context.toolOutput
        );
        const intakeAttachment = Boolean(
            request.extraPayload
            && request.extraPayload.attachment_context
            && request.extraPayload.attachment_context.documentType === 'intake_form'
        );

        return {
            intent: inferIntentLabel(mode, prompt),
            needsChartContext: patientSelected && (CHART_RETRIEVAL_MODES.has(mode) || FRONT_DESK_MODES.has(mode) || BILLING_MODES.has(mode) || /chart|history|medication|lab|appointment|contact|insurance|summary|changed since/i.test(prompt)),
            needsGuidelineEvidence: mode !== 'send_reminder',
            needsAttachmentReview: mode === 'lab_pdf_ingestion' || labPdfAttached || intakeAttachment,
            requestedDomains: requestedDomainsFor(request)
        };
    }

    async function runSupervisor(input) {
        const options = input && typeof input === 'object' ? input : {};
        const request = options.request && typeof options.request === 'object' ? options.request : {};
        const callTool = typeof options.callTool === 'function' ? options.callTool : null;
        const guardrails = options.guardrails && typeof options.guardrails.evaluate === 'function' ? options.guardrails : null;
        const logger = options.logger || console;
        const toolSchemas = options.toolSchemas && typeof options.toolSchemas === 'object' ? options.toolSchemas : {};
        const tracer = createTraceCollector(logger);
        const trace = tracer.trace;

        if (!callTool) {
            throw new Error('Supervisor requires a callable tool client.');
        }

        const classification = classifyIntent(request);
        tracer.push('Supervisor Agent', 'Received user prompt', request.prompt || '', {
            role: request.role || 'doctor',
            mode: request.mode || 'general_assistant',
            requestId: request.requestId || null
        });
        tracer.push('Supervisor Agent', 'Classified intent', classification.intent, {
            requestedDomains: classification.requestedDomains,
            needsChartContext: classification.needsChartContext,
            needsGuidelineEvidence: classification.needsGuidelineEvidence,
            needsAttachmentReview: classification.needsAttachmentReview
        });

        if (guardrails) {
            const preflight = guardrails.evaluate({
                role: request.role,
                mode: request.mode,
                prompt: request.prompt,
                draftResponse: '',
                sections: [],
                safetyText: '',
                metadata: {
                    selectedPatientKey: request.selectedPatientKey || '',
                    patientKey: request.selectedPatientKey || ''
                }
            });

            if (!preflight.allowed) {
                tracer.push('Evidence + Safety Worker', 'Blocked before tool routing', preflight.blockedReason || 'guardrail_block', {
                    riskLevel: preflight.riskLevel || 'high'
                }, 'blocked');
                return {
                    ok: true,
                    mode: request.mode || 'general_assistant',
                    role: request.role || 'doctor',
                    patient: request.patientName || null,
                    answer: preflight.finalResponse || 'This request is outside the allowed demo workflow.',
                    sections: [
                        {
                            title: 'Summary',
                            tone: 'yellow',
                            items: [preflight.finalResponse || 'This request is outside the allowed demo workflow.']
                        },
                        {
                            title: 'Draft-only clinician review',
                            tone: 'neutral',
                            items: [preflight.finalSafety || defaultSafetyNoteFor(request.role)]
                        }
                    ],
                    tags: unique(preflight.policyTags || []),
                    sources: [],
                    safety_note: preflight.finalSafety || defaultSafetyNoteFor(request.role),
                    engine: 'guardrail',
                    provider: 'guardrail',
                    meta: {
                        engine: 'guardrail',
                        provider: 'guardrail',
                        model: null,
                        openai_configured: false,
                        fallback_used: false,
                        fallback_reason: null,
                        restricted_by_role: true,
                        restriction_type: preflight.blockedReason || 'guardrails_block',
                        rag_grounded: false,
                        agent_architecture: 'supervisor_worker',
                        agent_trace: trace,
                        agent_tools: Object.keys(toolSchemas),
                        tool_schemas: toolSchemas,
                        agent_workers: ['Supervisor Agent', 'Chart Retrieval Worker', 'Evidence + Safety Worker']
                    }
                };
            }
        }

        const toolResults = {
            chartContextResult: null,
            attachmentResult: null,
            guidelineEvidenceResult: null
        };

        if (classification.needsChartContext) {
            tracer.push('Chart Retrieval Worker', 'Calling retrieve_chart_context', 'Retrieving role-appropriate chart context.', {
                requestedDomains: classification.requestedDomains,
                toolName: 'retrieve_chart_context'
            }, 'running');
            toolResults.chartContextResult = await callTool('retrieve_chart_context', {
                patient_id: request.patientId || null,
                role: request.role,
                mode: request.mode,
                prompt: request.prompt,
                requested_domains: classification.requestedDomains,
                include_latest_ambient: Boolean(request.ambientVisitContext),
                minimum_necessary: request.role === 'front_desk'
            });
            tracer.push('Chart Retrieval Worker', 'Returned chart context', 'Structured facts and sources retrieved.', {
                sourceCount: Array.isArray(toolResults.chartContextResult?.sources) ? toolResults.chartContextResult.sources.length : 0,
                factCount: Array.isArray(toolResults.chartContextResult?.facts) ? toolResults.chartContextResult.facts.length : 0,
                missingDataCount: Array.isArray(toolResults.chartContextResult?.missing_data) ? toolResults.chartContextResult.missing_data.length : 0,
                toolName: 'retrieve_chart_context'
            }, 'complete');
            tracer.push('Supervisor Agent', 'Accepted chart retrieval result', 'Worker 1 context is available for drafting.');
        } else {
            tracer.push('Supervisor Agent', 'Skipped chart retrieval', 'No patient chart retrieval was required for this request.');
        }

        if (classification.needsAttachmentReview) {
            const labPdfToolOutput = request.extraPayload?.lab_pdf_context?.toolOutput || null;
            const attachmentContext = request.extraPayload?.attachment_context || null;
            tracer.push('Chart Retrieval Worker', 'Calling attach_and_extract', 'Validating attached lab PDF or intake document payload.', {
                toolName: 'attach_and_extract'
            }, 'running');
            toolResults.attachmentResult = await callTool('attach_and_extract', {
                role: request.role,
                patient_key: request.selectedPatientKey || '',
                document_type: attachmentContext?.documentType || (labPdfToolOutput ? 'lab_pdf' : 'unknown'),
                use_demo_seed: Boolean(
                    labPdfToolOutput
                    && labPdfToolOutput.documentMetadata
                    && labPdfToolOutput.documentMetadata.seededDemo
                ),
                tool_output: labPdfToolOutput || attachmentContext?.toolOutput || null
            });
            tracer.push('Chart Retrieval Worker', 'Returned attachment extraction result', 'Attached document payload reviewed.', {
                status: toolResults.attachmentResult?.tool_output?.status || null,
                missingDataCount: Array.isArray(toolResults.attachmentResult?.missing_data) ? toolResults.attachmentResult.missing_data.length : 0,
                toolName: 'attach_and_extract'
            }, 'complete');
            tracer.push('Supervisor Agent', 'Accepted attachment result', 'Attachment context is available for drafting.');
        }

        if (classification.needsGuidelineEvidence) {
            tracer.push('Supervisor Agent', 'Calling retrieve_guideline_evidence', 'Loading demo workflow and role-policy evidence.', {
                toolName: 'retrieve_guideline_evidence'
            }, 'running');
            toolResults.guidelineEvidenceResult = await callTool('retrieve_guideline_evidence', {
                role: request.role,
                mode: request.mode,
                prompt: request.prompt,
                intent: classification.intent
            });
            tracer.push('Supervisor Agent', 'Accepted guideline evidence', 'Policy and workflow evidence retrieved.', {
                evidenceCount: Array.isArray(toolResults.guidelineEvidenceResult?.evidence) ? toolResults.guidelineEvidenceResult.evidence.length : 0,
                toolName: 'retrieve_guideline_evidence'
            }, 'complete');
        }

        tracer.push('Supervisor Agent', 'Calling draft_grounded_answer', 'Combining worker outputs into a draft response.', {
            toolName: 'draft_grounded_answer'
        }, 'running');
        const draftResult = await callTool('draft_grounded_answer', {
            request_id: request.requestId || '',
            role: request.role,
            mode: request.mode,
            prompt: request.prompt,
            chat_history: request.chatHistory || [],
            chart_context_result: toolResults.chartContextResult,
            guideline_evidence_result: toolResults.guidelineEvidenceResult,
            attachment_result: toolResults.attachmentResult
        });
        tracer.push('Supervisor Agent', 'Received grounded draft', 'Draft response returned by the drafting tool.', {
            engine: draftResult?.meta?.engine || draftResult?.draft?.engine || null,
            provider: draftResult?.meta?.provider || draftResult?.draft?.provider || null,
            toolName: 'draft_grounded_answer'
        }, 'complete');

        tracer.push('Evidence + Safety Worker', 'Calling validate_citations', 'Checking role boundaries, grounding, and missing data.', {
            toolName: 'validate_citations'
        }, 'running');
        const validationResult = await callTool('validate_citations', {
            role: request.role,
            mode: request.mode,
            prompt: request.prompt,
            draft: draftResult && draftResult.draft ? draftResult.draft : {},
            chart_context_result: toolResults.chartContextResult,
            guideline_evidence_result: toolResults.guidelineEvidenceResult,
            attachment_result: toolResults.attachmentResult
        });
        tracer.push('Evidence + Safety Worker', 'Returned validation result', validationResult.allowed ? 'Draft passed evidence and safety review.' : 'Draft was blocked or rewritten.', {
            allowed: Boolean(validationResult.allowed),
            blockedReason: validationResult.blocked_reason || '',
            citationGapCount: Array.isArray(validationResult.citation_gaps) ? validationResult.citation_gaps.length : 0,
            missingDataCount: Array.isArray(validationResult.missing_data) ? validationResult.missing_data.length : 0,
            toolName: 'validate_citations'
        }, validationResult.allowed ? 'complete' : 'blocked');

        const draft = draftResult && draftResult.draft ? draftResult.draft : {};
        const normalized = normalizeFinalSections(draft, validationResult, {
            ...toolResults,
            draftResult: draftResult
        }, request);
        const blocked = validationResult && validationResult.allowed === false;
        const answer = blocked
            ? (validationResult.safe_refusal || defaultRoleBoundaryMessage(request.role, validationResult.blocked_reason || '') || draft.answer || 'This request was blocked for safety review.')
            : (draft.answer || 'Draft response prepared for review.');
        const sections = blocked
            ? buildBlockedSections(validationResult, toolResults, request)
            : normalized.sections;
        const sources = blocked
            ? normalizeSources(validationResult.validated_sources || draft.sources || [])
            : normalized.sources;
        const safetyNote = validationResult.draft_only_note || draft.safety_note || defaultSafetyNoteFor(request.role);
        const meta = {
            ...(draftResult && draftResult.meta ? draftResult.meta : {}),
            engine: draftResult?.meta?.engine || draft.engine || 'fallback',
            provider: draftResult?.meta?.provider || draft.provider || 'local_fallback',
            model: draftResult?.meta?.model || draft.model || null,
            restricted_by_role: Boolean(blocked),
            restriction_type: blocked ? (validationResult.blocked_reason || 'evidence_safety_block') : '',
            rag_grounded: draftResult?.meta?.rag_grounded !== undefined
                ? Boolean(draftResult.meta.rag_grounded)
                : sources.length > 0,
            agent_architecture: 'supervisor_worker',
            agent_trace: trace,
            agent_tools: Object.keys(toolSchemas),
            tool_schemas: toolSchemas,
            agent_workers: ['Supervisor Agent', 'Chart Retrieval Worker', 'Evidence + Safety Worker'],
            demo_use_cases: DEMO_USE_CASES
        };

        if (toolResults.attachmentResult && toolResults.attachmentResult.tool_output) {
            meta.tool_output = toolResults.attachmentResult.tool_output;
        }

        tracer.push(
            'Supervisor Agent',
            'Finalized response',
            blocked ? 'Returning safe refusal with sources and draft-only review language.' : 'Returning grounded draft with sources and review language.',
            {
                sourceCount: sources.length,
                sectionCount: sections.length
            },
            blocked ? 'blocked' : 'complete'
        );

        return {
            ok: true,
            mode: request.mode || 'general_assistant',
            role: request.role || 'doctor',
            patient: toolResults.chartContextResult?.patient?.name || request.patientName || null,
            answer: answer,
            sections: sections,
            tags: unique((draft.tags || []).concat(validationResult.policy_flags || [])),
            sources: sources,
            safety_note: safetyNote,
            engine: meta.engine,
            provider: meta.provider,
            meta: meta,
            tool_output: toolResults.attachmentResult?.tool_output || draftResult?.tool_output || null
        };
    }

    return {
        DEMO_USE_CASES: DEMO_USE_CASES,
        classifyIntent: classifyIntent,
        logTrace: logTrace,
        requestedDomainsFor: requestedDomainsFor,
        runSupervisor: runSupervisor
    };
}));
