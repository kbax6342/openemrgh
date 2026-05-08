(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        const traceModule = require('./copilot_agent_trace.js');
        const safetyModule = require('./copilot_agent_safety.js');
        const observabilityModule = require('../observability/copilot_observability.js');
        module.exports = factory(traceModule, safetyModule, observabilityModule);
        return;
    }

    root.OpenEMRCopilotAgents = factory(root.OpenEMRCopilotAgentTrace, root.OpenEMRCopilotAgentSafety, root.OpenEMRCopilotObservability);
}(typeof globalThis !== 'undefined' ? globalThis : this, function (traceModule, safetyModule, observabilityModule) {
    'use strict';

    const traceHelpers = traceModule && typeof traceModule === 'object' ? traceModule : {};
    const safetyHelpers = safetyModule && typeof safetyModule === 'object' ? safetyModule : {};
    const observabilityHelpers = observabilityModule && typeof observabilityModule === 'object' ? observabilityModule : {};

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

    const buildEncounterObservability = typeof observabilityHelpers.buildEncounterObservability === 'function'
        ? observabilityHelpers.buildEncounterObservability
        : function fallbackBuildEncounterObservability() {
            return null;
        };

    const normalizeTokenUsage = typeof observabilityHelpers.normalizeTokenUsage === 'function'
        ? observabilityHelpers.normalizeTokenUsage
        : function fallbackNormalizeTokenUsage(usage) {
            const promptTokens = Number.isFinite(Number(usage && usage.prompt_tokens)) ? Number(usage.prompt_tokens) : null;
            const completionTokens = Number.isFinite(Number(usage && usage.completion_tokens)) ? Number(usage.completion_tokens) : null;
            const totalTokens = Number.isFinite(Number(usage && usage.total_tokens))
                ? Number(usage.total_tokens)
                : (promptTokens !== null || completionTokens !== null)
                    ? (promptTokens || 0) + (completionTokens || 0)
                    : null;
            return {
                prompt_tokens: promptTokens,
                completion_tokens: completionTokens,
                total_tokens: totalTokens,
                token_usage_estimated: !(promptTokens !== null || completionTokens !== null || totalTokens !== null)
            };
        };

    const estimateCost = typeof observabilityHelpers.estimateCost === 'function'
        ? observabilityHelpers.estimateCost
        : function fallbackEstimateCost() {
            return {
                estimated_cost_usd: null,
                cost_note: 'Token usage unavailable. Cost estimate not configured for this response.'
            };
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

    function normalizeDocumentType(value) {
        const normalized = String(value || '').trim().toLowerCase();
        if (normalized === 'lab_pdf' || normalized === 'intake_form') {
            return normalized;
        }
        if (normalized === 'lab_results') {
            return 'lab_pdf';
        }
        return normalized || 'unknown';
    }

    function attachedDocumentSummary(request) {
        const extraPayload = request && typeof request.extraPayload === 'object' ? request.extraPayload : {};
        const attachmentContext = extraPayload.attachment_context && typeof extraPayload.attachment_context === 'object'
            ? extraPayload.attachment_context
            : null;
        const labPdfContext = extraPayload.lab_pdf_context && typeof extraPayload.lab_pdf_context === 'object'
            ? extraPayload.lab_pdf_context
            : null;
        const inferredDocumentType = normalizeDocumentType(
            attachmentContext?.documentType
            || attachmentContext?.document_type
            || labPdfContext?.documentType
            || labPdfContext?.document_type
            || labPdfContext?.toolOutput?.documentMetadata?.documentType
            || labPdfContext?.toolOutput?.document_metadata?.document_type
            || request?.docType
        );
        const prompt = String(request && request.prompt ? request.prompt : '').toLowerCase();
        const mode = String(request && request.mode ? request.mode : '').toLowerCase();
        const documentType = inferredDocumentType !== 'unknown'
            ? inferredDocumentType
            : (mode === 'lab_pdf_ingestion' || /\blab pdf|uploaded lab|lab results\b/.test(prompt))
                ? 'lab_pdf'
                : /\bintake form|questionnaire|patient intake|attached form\b/.test(prompt)
                    ? 'intake_form'
                    : 'unknown';
        const hasAttachedFile = Boolean(attachmentContext || labPdfContext || documentType === 'lab_pdf' || documentType === 'intake_form');

        return {
            hasAttachedFile,
            docType: documentType,
            supported: documentType === 'lab_pdf' || documentType === 'intake_form',
            unsupported: hasAttachedFile && documentType !== 'lab_pdf' && documentType !== 'intake_form'
        };
    }

    function needsIntakeExtractionRoute(request, classification, attachment) {
        const prompt = String(request.prompt || '').toLowerCase();
        const mode = String(request.mode || '').toLowerCase();
        if (attachment.hasAttachedFile && attachment.supported) {
            return true;
        }
        if (mode === 'lab_pdf_ingestion') {
            return true;
        }
        return /\b(upload|ingest|extract|review document|lab pdf|intake form|attached form|attached pdf)\b/.test(prompt);
    }

    function needsEvidenceRetrievalRoute(request, classification, attachment) {
        const prompt = String(request.prompt || '').toLowerCase();
        const mode = String(request.mode || '').toLowerCase();
        const extractionOnlyPrompt = /\b(upload|ingest|extract|save pdf|save his data|review document|stage|clinician review only|attached pdf|attached form)\b/.test(prompt)
            && !/\b(summarize|summary|explain|interpret|compare|what changed|review findings|guideline|evidence|source-grounded|sources used|retrieve|retrieval|chart context|treatment plan|medication information|clinical summary|follow-up|using it|use it)\b/.test(prompt);
        if ((attachment.hasAttachedFile || mode === 'lab_pdf_ingestion') && extractionOnlyPrompt) {
            return false;
        }
        if (classification.needsChartContext) {
            return true;
        }
        if ([
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
            'appointment_info',
            'patient_contact',
            'front_desk_summary'
        ].includes(mode)) {
            return true;
        }
        if (/\b(summarize|summary|explain|interpret|compare|what changed|review findings|guideline|evidence|source-grounded|sources used|retrieve|retrieval|chart context|treatment plan|medication information|clinical summary|follow-up)\b/.test(prompt)) {
            return true;
        }
        if (attachment.hasAttachedFile && /\b(summarize|summary|explain|interpret|review|grounded|source|use it|using it)\b/.test(prompt)) {
            return true;
        }
        return false;
    }

    function isPatientSpecificTask(request, attachment, needsExtraction, needsEvidenceRetrieval) {
        const prompt = String(request.prompt || '').toLowerCase();
        const mode = String(request.mode || '').toLowerCase();
        if (needsExtraction) {
            return true;
        }
        if (needsEvidenceRetrieval && mode !== 'general_assistant') {
            return true;
        }
        return /\b(marcus|selected patient|this patient|his chart|her chart|uploaded|attached|this pdf|this form|this document)\b/.test(prompt);
    }

    function isoTimestamp() {
        return new Date().toISOString();
    }

    function buildDecisionId(index) {
        return `decision_${String(index).padStart(3, '0')}`;
    }

    function buildHandoffId(index) {
        return `handoff_${String(index).padStart(3, '0')}`;
    }

    function buildSupervisorDecision(request, decision, reason, nextWorker, index) {
        return {
            decision_id: buildDecisionId(index),
            request_id: request.requestId || '',
            decision,
            reason,
            inputs_checked: ['patient_id', 'role', 'mode', 'prompt', 'attached_file', 'doc_type'],
            next_worker: nextWorker,
            timestamp: isoTimestamp(),
            safe_log: true
        };
    }

    function buildWorkerHandoff(request, from, to, reason, payloadSummary, index) {
        return {
            handoff_id: buildHandoffId(index),
            request_id: request.requestId || '',
            from,
            to,
            reason,
            payload_summary: {
                patient_id_present: Boolean(payloadSummary && payloadSummary.patient_id_present),
                role: String(payloadSummary && payloadSummary.role ? payloadSummary.role : request.role || 'doctor'),
                doc_type: String(payloadSummary && payloadSummary.doc_type ? payloadSummary.doc_type : 'unknown'),
                has_attached_file: Boolean(payloadSummary && payloadSummary.has_attached_file),
                needs_extraction: Boolean(payloadSummary && payloadSummary.needs_extraction),
                needs_evidence_retrieval: Boolean(payloadSummary && payloadSummary.needs_evidence_retrieval)
            },
            timestamp: isoTimestamp(),
            safe_log: true
        };
    }

    function pushMachineDecision(tracer, decisions, request, decision, reason, nextWorker) {
        const entry = buildSupervisorDecision(request, decision, reason, nextWorker, decisions.length + 1);
        decisions.push(entry);
        tracer.push('Supervisor', 'supervisor_decision_logged', `${decision}: ${reason}`, {
            decisionType: decision,
            nextWorker,
            safeLog: true
        }, decision === 'safe_refusal' ? 'blocked' : 'complete');
        return entry;
    }

    function pushWorkerHandoff(tracer, handoffs, request, from, to, reason, payloadSummary) {
        const entry = buildWorkerHandoff(request, from, to, reason, payloadSummary, handoffs.length + 1);
        handoffs.push(entry);
        tracer.push(from, 'worker_handoff_logged', reason, {
            fromWorker: from,
            toWorker: to,
            docType: entry.payload_summary.doc_type,
            hasAttachedFile: entry.payload_summary.has_attached_file,
            needsExtraction: entry.payload_summary.needs_extraction,
            needsEvidenceRetrieval: entry.payload_summary.needs_evidence_retrieval,
            safeLog: true
        }, 'running');
        return entry;
    }

    function summarizeExtractionResult(attachmentResult, attachment, validationResult) {
        const result = attachmentResult && typeof attachmentResult === 'object' ? attachmentResult : {};
        const toolOutput = result.tool_output && typeof result.tool_output === 'object' ? result.tool_output : {};
        const extractedFacts = Array.isArray(toolOutput.extractedFacts)
            ? toolOutput.extractedFacts
            : (Array.isArray(toolOutput.extracted_facts) ? toolOutput.extracted_facts : []);
        const confidence = Number.isFinite(Number(toolOutput.confidence))
            ? Number(toolOutput.confidence)
            : (Number.isFinite(Number(toolOutput.extractionConfidence)) ? Number(toolOutput.extractionConfidence) : null);
        const validationValue = validationResult && typeof validationResult === 'object' ? validationResult : {};
        return {
            doc_type: attachment && attachment.docType ? attachment.docType : 'none',
            extraction_status: String(
                toolOutput.extraction_status
                || toolOutput.extractionStatus
                || toolOutput.status
                || 'none'
            ).trim().toLowerCase() || 'none',
            confidence: confidence,
            schema_valid: toolOutput.schema_valid !== undefined
                ? Boolean(toolOutput.schema_valid)
                : (toolOutput.schemaValid !== undefined ? Boolean(toolOutput.schemaValid) : null),
            citation_contract_valid: validationValue.citation_contract_status
                ? String(validationValue.citation_contract_status).trim().toLowerCase() === 'passed'
                : null,
            review_status: String(
                toolOutput.review_status
                || toolOutput.reviewStatus
                || toolOutput.documentMetadata?.reviewStatus
                || 'pending_clinician_review'
            ).trim() || 'pending_clinician_review',
            extracted_fact_count: extractedFacts.length,
            missing_data_count: Array.isArray(result.missing_data) ? result.missing_data.length : 0
        };
    }

    function topSourceTypes(sourcesUsed, sources) {
        return unique(
            []
                .concat(Array.isArray(sourcesUsed) ? sourcesUsed : [])
                .concat(Array.isArray(sources) ? sources : [])
                .map(function (source) {
                    if (!source || typeof source !== 'object') {
                        return '';
                    }

                    return String(
                        source.source_type
                        || source.sourceType
                        || source.document_type
                        || source.documentType
                        || source.category
                        || source.id
                        || ''
                    ).trim();
                })
                .filter(Boolean)
        ).slice(0, 8);
    }

    function buildObservabilityPayload(request, values) {
        const settings = values && typeof values === 'object' ? values : {};
        return buildEncounterObservability({
            request_id: request.requestId || null,
            encounter_id: request.requestId || null,
            role: request.role || 'doctor',
            mode: request.mode || 'general_assistant',
            selectedPatientKey: request.selectedPatientKey || request.patientId || null,
            tool_sequence: settings.tool_sequence || [],
            latency: {
                total_ms: settings.total_ms || 0,
                steps: settings.latency_steps || {}
            },
            token_usage: settings.token_usage || null,
            model: settings.model || null,
            provider: settings.provider || null,
            estimated_cost_usd: settings.estimated_cost_usd,
            retrieval: settings.retrieval || {},
            extraction: settings.extraction || {},
            eval: settings.eval || {
                case_id: null,
                passed: null,
                rubric_failures: [],
                phi_log_check_passed: null,
                regression_gate_status: null
            },
            safety: settings.safety || {}
        });
    }

    function buildSafeRefusalResponse(request, tracer, trace, toolSchemas, decisionObjects, handoffObjects, blockedReason, answer, options = {}) {
        const metadata = options && typeof options === 'object' ? options : {};
        tracer.push('FinalResponse', 'safe_refusal', 'Supervisor returned a safe refusal instead of a grounded clinical draft.', {
            decisionType: 'safe_refusal',
            blockedReason: blockedReason || 'safe_refusal',
            safeLog: true
        }, 'blocked');
        pushMachineDecision(tracer, decisionObjects, request, 'safe_refusal', answer, 'SafeRefusal');

        return {
            ok: true,
            mode: request.mode || 'general_assistant',
            role: request.role || 'doctor',
            patient: request.patientName || null,
            answer: answer,
            sections: [
                {
                    title: 'Summary',
                    tone: 'yellow',
                    items: [answer]
                },
                {
                    title: 'Draft-only clinician review',
                    tone: 'neutral',
                    items: [defaultSafetyNoteFor(request.role)]
                }
            ],
            tags: unique([blockedReason || 'safe_refusal', 'review_required']),
            sources: [],
            claims: [],
            sources_used: [],
            uncited_claims_blocked: [],
            evidence_snippets: [],
            safety_status: 'safe_refusal',
            safety_note: defaultSafetyNoteFor(request.role),
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
                restriction_type: blockedReason || 'safe_refusal',
                rag_grounded: false,
                retrieval_mode: 'no_grounded_evidence',
                rerank_provider: '',
                agent_architecture: 'week2_supervisor_intake_evidence',
                agent_trace: trace,
                agent_tools: Object.keys(toolSchemas),
                tool_schemas: toolSchemas,
                agent_workers: ['Supervisor', 'IntakeExtractorWorker', 'EvidenceRetrieverWorker', 'FinalResponse'],
                legacy_agent_workers: ['Supervisor Agent', 'Chart Retrieval Worker', 'Evidence + Safety Worker', 'Final Draft'],
                supervisor_decisions: decisionObjects,
                worker_handoffs: handoffObjects,
                observability: metadata.observability || null,
                ...metadata
            }
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
        const supervisorDecisions = [];
        const workerHandoffs = [];
        const encounterStartedAt = Date.now();
        const latencySteps = {};
        const toolSequence = [];
        const observabilityState = {
            token_usage: null,
            model: null,
            provider: null,
            estimated_cost_usd: null,
            cost_note: null,
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
                citation_count: 0
            },
            extraction: {
                doc_type: 'none',
                extraction_status: 'none',
                confidence: null,
                schema_valid: null,
                citation_contract_valid: null,
                review_status: null,
                extracted_fact_count: 0,
                missing_data_count: 0
            },
            safety: {
                safe_refusal: false,
                blocked_reason: null,
                phi_redacted: true,
                raw_document_text_logged: false,
                raw_screenshot_logged: false,
                screenshot_capture_attempted: false,
                screenshot_blocked_reason: 'PHI_SAFE_DEFAULT'
            }
        };

        function finishLatency(key, startedAt) {
            const duration = Math.max(0, Date.now() - startedAt);
            latencySteps[key] = duration;
            return duration;
        }

        function recordToolSequence(entry) {
            toolSequence.push({
                order: toolSequence.length + 1,
                step: entry.step || 'Supervisor',
                tool: entry.tool || '',
                decision: entry.decision || '',
                status: entry.status || 'completed',
                latency_ms: Math.max(0, Math.round(Number(entry.latency_ms || 0))),
                retrieval_hits: Math.max(0, Math.round(Number(entry.retrieval_hits || 0)))
            });
        }

        function buildObservabilitySnapshot(overrides) {
            const settings = overrides && typeof overrides === 'object' ? overrides : {};
            return buildObservabilityPayload(request, {
                tool_sequence: toolSequence,
                latency_steps: latencySteps,
                total_ms: Math.max(0, Date.now() - encounterStartedAt),
                token_usage: settings.token_usage || observabilityState.token_usage,
                model: settings.model || observabilityState.model,
                provider: settings.provider || observabilityState.provider,
                estimated_cost_usd: settings.estimated_cost_usd !== undefined
                    ? settings.estimated_cost_usd
                    : observabilityState.estimated_cost_usd,
                retrieval: settings.retrieval || observabilityState.retrieval,
                extraction: settings.extraction || observabilityState.extraction,
                safety: settings.safety || observabilityState.safety
            });
        }

        if (!callTool) {
            throw new Error('Supervisor requires a callable tool client.');
        }

        const supervisorDecisionStartedAt = Date.now();
        const classification = classifyIntent(request);
        const attachment = attachedDocumentSummary(request);
        const needsExtraction = needsIntakeExtractionRoute(request, classification, attachment);
        const needsEvidenceRetrieval = needsEvidenceRetrievalRoute(request, classification, attachment);
        const patientSpecificTask = isPatientSpecificTask(request, attachment, needsExtraction, needsEvidenceRetrieval);
        tracer.push('Supervisor', 'Received user request', 'Supervisor received a new role-scoped co-pilot request.', {
            role: request.role || 'doctor',
            mode: request.mode || 'general_assistant',
            requestId: request.requestId || null
        });
        tracer.push('Supervisor', 'Classified intent', classification.intent, {
            requestedDomains: classification.requestedDomains,
            needsChartContext: classification.needsChartContext,
            needsGuidelineEvidence: classification.needsGuidelineEvidence,
            needsAttachmentReview: classification.needsAttachmentReview,
            hasAttachedFile: attachment.hasAttachedFile,
            docType: attachment.docType,
            needsExtraction,
            needsEvidenceRetrieval
        });

        if (attachment.unsupported) {
            const supervisorDecisionLatency = finishLatency('supervisor_decision_ms', supervisorDecisionStartedAt);
            recordToolSequence({
                step: 'Supervisor',
                decision: 'safe_refusal',
                status: 'blocked',
                latency_ms: supervisorDecisionLatency
            });
            observabilityState.safety.safe_refusal = true;
            observabilityState.safety.blocked_reason = 'unsupported_doc_type_blocked';
            return buildSafeRefusalResponse(
                request,
                tracer,
                trace,
                toolSchemas,
                supervisorDecisions,
                workerHandoffs,
                'unsupported_doc_type_blocked',
                'Only lab PDFs and intake forms are supported in this MVP.',
                {
                    observability: buildObservabilitySnapshot()
                }
            );
        }

        if (!request.patientId && patientSpecificTask) {
            const supervisorDecisionLatency = finishLatency('supervisor_decision_ms', supervisorDecisionStartedAt);
            recordToolSequence({
                step: 'Supervisor',
                decision: 'safe_refusal',
                status: 'blocked',
                latency_ms: supervisorDecisionLatency
            });
            observabilityState.safety.safe_refusal = true;
            observabilityState.safety.blocked_reason = 'patient_required';
            return buildSafeRefusalResponse(
                request,
                tracer,
                trace,
                toolSchemas,
                supervisorDecisions,
                workerHandoffs,
                'patient_required',
                'Select a demo patient before running this patient-specific workflow.',
                {
                    observability: buildObservabilitySnapshot()
                }
            );
        }

        let routingDecisionType = 'route_to_evidence_retriever';
        if (needsExtraction && needsEvidenceRetrieval) {
            routingDecisionType = 'route_to_both';
            pushMachineDecision(tracer, supervisorDecisions, request, 'route_to_both', 'Attached supported document plus source-grounded question requires both extraction and evidence retrieval.', 'IntakeExtractorWorker');
            pushWorkerHandoff(tracer, workerHandoffs, request, 'Supervisor', 'IntakeExtractorWorker', 'Attached file requires extraction before grounded response drafting.', {
                patient_id_present: Boolean(request.patientId),
                role: request.role || 'doctor',
                doc_type: attachment.docType,
                has_attached_file: attachment.hasAttachedFile,
                needs_extraction: true,
                needs_evidence_retrieval: true
            });
            pushWorkerHandoff(tracer, workerHandoffs, request, 'Supervisor', 'EvidenceRetrieverWorker', 'Grounded retrieval is required for the uploaded-document question.', {
                patient_id_present: Boolean(request.patientId),
                role: request.role || 'doctor',
                doc_type: attachment.docType,
                has_attached_file: attachment.hasAttachedFile,
                needs_extraction: true,
                needs_evidence_retrieval: true
            });
        } else if (needsExtraction) {
            pushMachineDecision(tracer, supervisorDecisions, request, 'route_to_intake_extractor', 'Attached supported document requires extraction and clinician-review staging before any answer drafting.', 'IntakeExtractorWorker');
            pushWorkerHandoff(tracer, workerHandoffs, request, 'Supervisor', 'IntakeExtractorWorker', 'Attached file with a supported document type requires extraction.', {
                patient_id_present: Boolean(request.patientId),
                role: request.role || 'doctor',
                doc_type: attachment.docType,
                has_attached_file: attachment.hasAttachedFile,
                needs_extraction: true,
                needs_evidence_retrieval: false
            });
        } else {
            pushMachineDecision(tracer, supervisorDecisions, request, 'route_to_evidence_retriever', 'The request needs source-grounded evidence retrieval from role-allowed chart, guideline, or uploaded-document context.', 'EvidenceRetrieverWorker');
            pushWorkerHandoff(tracer, workerHandoffs, request, 'Supervisor', 'EvidenceRetrieverWorker', 'Source-grounded retrieval is required before final drafting.', {
                patient_id_present: Boolean(request.patientId),
                role: request.role || 'doctor',
                doc_type: attachment.docType,
                has_attached_file: attachment.hasAttachedFile,
                needs_extraction: false,
                needs_evidence_retrieval: true
            });
        }
        const supervisorDecisionLatency = finishLatency('supervisor_decision_ms', supervisorDecisionStartedAt);
        recordToolSequence({
            step: 'Supervisor',
            decision: routingDecisionType,
            status: 'completed',
            latency_ms: supervisorDecisionLatency
        });

        if (guardrails) {
            const guardrailStartedAt = Date.now();
            const preflight = guardrails.evaluate({
                role: request.role,
                mode: request.mode,
                prompt: request.prompt,
                draftResponse: '',
                sections: [],
                safetyText: '',
                metadata: {
                    patientContextPresent: Boolean(request.selectedPatientKey || request.patientId),
                    patientContextHash: request.selectedPatientKey || request.patientId ? String(request.requestId || '') : null
                }
            });
            finishLatency('guardrail_preflight_ms', guardrailStartedAt);

            if (!preflight.allowed) {
                tracer.push('Supervisor', 'Blocked before tool routing', preflight.blockedReason || 'guardrail_block', {
                    riskLevel: preflight.riskLevel || 'high'
                }, 'blocked');
                observabilityState.safety.safe_refusal = true;
                observabilityState.safety.blocked_reason = preflight.blockedReason || 'guardrails_block';
                recordToolSequence({
                    step: 'FinalResponse',
                    tool: 'safe_refusal',
                    decision: 'safe_refusal',
                    status: 'blocked',
                    latency_ms: 0
                });
                return buildSafeRefusalResponse(
                    request,
                    tracer,
                    trace,
                    toolSchemas,
                    supervisorDecisions,
                    workerHandoffs,
                    preflight.blockedReason || 'guardrails_block',
                    preflight.finalResponse || 'This request is outside the allowed demo workflow.',
                    {
                        draft_only_note: preflight.finalSafety || defaultSafetyNoteFor(request.role),
                        observability: buildObservabilitySnapshot()
                    }
                );
            }
        }

        const toolResults = {
            chartContextResult: null,
            attachmentResult: null,
            guidelineEvidenceResult: null
        };

        if (needsEvidenceRetrieval && classification.needsChartContext) {
            const chartStartedAt = Date.now();
            tracer.push('EvidenceRetrieverWorker', 'Calling retrieve_chart_context', 'Retrieving role-appropriate chart context.', {
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
            const chartLatency = finishLatency('retrieve_chart_context_ms', chartStartedAt);
            tracer.push('EvidenceRetrieverWorker', 'worker_completed', 'Structured chart context retrieval completed.', {
                sourceCount: Array.isArray(toolResults.chartContextResult?.sources) ? toolResults.chartContextResult.sources.length : 0,
                factCount: Array.isArray(toolResults.chartContextResult?.facts) ? toolResults.chartContextResult.facts.length : 0,
                missingDataCount: Array.isArray(toolResults.chartContextResult?.missing_data) ? toolResults.chartContextResult.missing_data.length : 0,
                toolName: 'retrieve_chart_context'
            }, 'complete');
            recordToolSequence({
                step: 'EvidenceRetrieverWorker',
                tool: 'retrieve_chart_context',
                status: 'completed',
                latency_ms: chartLatency,
                retrieval_hits: Array.isArray(toolResults.chartContextResult?.sources) ? toolResults.chartContextResult.sources.length : 0
            });
            tracer.push('Supervisor', 'Accepted evidence retrieval result', 'Chart context is available for grounded drafting.');
        } else {
            tracer.push('EvidenceRetrieverWorker', 'worker_skipped', 'No patient chart retrieval was required for this request.', {
                toolName: 'retrieve_chart_context'
            }, 'pending');
        }

        if (needsExtraction) {
            const labPdfToolOutput = request.extraPayload?.lab_pdf_context?.toolOutput || null;
            const attachmentContext = request.extraPayload?.attachment_context || null;
            const extractionStartedAt = Date.now();
            tracer.push('IntakeExtractorWorker', 'Calling attach_and_extract', 'Validating attached lab PDF or intake document payload.', {
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
            const extractionLatency = finishLatency('attach_and_extract_ms', extractionStartedAt);
            tracer.push('IntakeExtractorWorker', 'worker_completed', 'Attached document extraction completed.', {
                status: toolResults.attachmentResult?.tool_output?.status || null,
                missingDataCount: Array.isArray(toolResults.attachmentResult?.missing_data) ? toolResults.attachmentResult.missing_data.length : 0,
                docType: attachment.docType,
                toolName: 'attach_and_extract'
            }, 'complete');
            observabilityState.extraction = summarizeExtractionResult(toolResults.attachmentResult, attachment, null);
            recordToolSequence({
                step: 'IntakeExtractorWorker',
                tool: 'attach_and_extract',
                status: observabilityState.extraction.extraction_status || 'completed',
                latency_ms: extractionLatency
            });
            tracer.push('Supervisor', 'Accepted intake extraction result', 'Attachment context is available for grounded drafting.');
        } else {
            tracer.push('IntakeExtractorWorker', 'worker_skipped', 'No supported attached document needed extraction for this request.', {
                docType: attachment.docType
            }, 'pending');
        }

        if (needsEvidenceRetrieval && classification.needsGuidelineEvidence) {
            const guidelineStartedAt = Date.now();
            tracer.push('EvidenceRetrieverWorker', 'Calling retrieve_guideline_evidence', 'Loading demo workflow and role-policy evidence.', {
                toolName: 'retrieve_guideline_evidence'
            }, 'running');
            toolResults.guidelineEvidenceResult = await callTool('retrieve_guideline_evidence', {
                request_id: request.requestId || '',
                role: request.role,
                mode: request.mode,
                prompt: request.prompt,
                intent: classification.intent
            });
            const guidelineLatency = finishLatency('retrieve_guideline_evidence_ms', guidelineStartedAt);
            tracer.push('EvidenceRetrieverWorker', 'worker_completed', 'Guideline and uploaded-document evidence retrieval completed.', {
                evidenceCount: Array.isArray(toolResults.guidelineEvidenceResult?.evidence_snippets)
                    ? toolResults.guidelineEvidenceResult.evidence_snippets.length
                    : (Array.isArray(toolResults.guidelineEvidenceResult?.evidence) ? toolResults.guidelineEvidenceResult.evidence.length : 0),
                toolName: 'retrieve_guideline_evidence'
            }, 'complete');
            recordToolSequence({
                step: 'EvidenceRetrieverWorker',
                tool: 'retrieve_guideline_evidence',
                status: 'completed',
                latency_ms: guidelineLatency,
                retrieval_hits: Array.isArray(toolResults.guidelineEvidenceResult?.evidence_snippets)
                    ? toolResults.guidelineEvidenceResult.evidence_snippets.length
                    : (Array.isArray(toolResults.guidelineEvidenceResult?.evidence) ? toolResults.guidelineEvidenceResult.evidence.length : 0)
            });
        } else {
            tracer.push('EvidenceRetrieverWorker', 'worker_skipped', 'Evidence retrieval was not required for this request.', {
                toolName: 'retrieve_guideline_evidence'
            }, 'pending');
        }

        const draftStartedAt = Date.now();
        tracer.push('FinalResponse', 'Calling draft_grounded_answer', 'Combining worker outputs into a draft response.', {
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
        const draftLatency = finishLatency('draft_grounded_answer_ms', draftStartedAt);
        tracer.push('FinalResponse', 'worker_completed', 'Draft response returned by the final drafting tool.', {
            engine: draftResult?.meta?.engine || draftResult?.draft?.engine || null,
            provider: draftResult?.meta?.provider || draftResult?.draft?.provider || null,
            toolName: 'draft_grounded_answer'
        }, 'complete');
        recordToolSequence({
            step: 'FinalResponse',
            tool: 'draft_grounded_answer',
            status: 'completed',
            latency_ms: draftLatency
        });

        const validationStartedAt = Date.now();
        tracer.push('EvidenceRetrieverWorker', 'Calling validate_citations', 'Checking role boundaries, grounding, and missing data.', {
            toolName: 'validate_citations'
        }, 'running');
        const draftPayload = draftResult && draftResult.draft ? {
            ...draftResult.draft,
            claims: Array.isArray(draftResult.claims) ? draftResult.claims : [],
            sources_used: Array.isArray(draftResult.sources_used) ? draftResult.sources_used : [],
            evidence_snippets: Array.isArray(draftResult.evidence_snippets) ? draftResult.evidence_snippets : []
        } : {};
        const validationResult = await callTool('validate_citations', {
            request_id: request.requestId || '',
            role: request.role,
            mode: request.mode,
            prompt: request.prompt,
            draft: draftPayload,
            claims: Array.isArray(draftResult?.claims) ? draftResult.claims : [],
            chart_context_result: toolResults.chartContextResult,
            guideline_evidence_result: toolResults.guidelineEvidenceResult,
            attachment_result: toolResults.attachmentResult
        });
        const validationLatency = finishLatency('validate_citations_ms', validationStartedAt);
        tracer.push('EvidenceRetrieverWorker', 'worker_completed', validationResult.allowed ? 'Draft passed evidence and safety review.' : 'Draft was blocked or rewritten.', {
            allowed: Boolean(validationResult.allowed),
            blockedReason: validationResult.blocked_reason || '',
            citationGapCount: Array.isArray(validationResult.citation_gaps) ? validationResult.citation_gaps.length : 0,
            missingDataCount: Array.isArray(validationResult.missing_data) ? validationResult.missing_data.length : 0,
            toolName: 'validate_citations'
        }, validationResult.allowed ? 'complete' : 'blocked');
        recordToolSequence({
            step: 'EvidenceRetrieverWorker',
            tool: 'validate_citations',
            status: validationResult.allowed ? 'completed' : 'blocked',
            latency_ms: validationLatency,
            retrieval_hits: Array.isArray(validationResult.validated_sources) ? validationResult.validated_sources.length : 0
        });

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
        const claims = Array.isArray(validationResult.validated_claims)
            ? validationResult.validated_claims
            : (Array.isArray(draftResult?.claims) ? draftResult.claims : []);
        const sourcesUsed = Array.isArray(validationResult.sources_used)
            ? validationResult.sources_used
            : (Array.isArray(draftResult?.sources_used) ? draftResult.sources_used : []);
        const uncitedClaimsBlocked = Array.isArray(validationResult.uncited_claims_blocked)
            ? validationResult.uncited_claims_blocked
            : (Array.isArray(draftResult?.uncited_claims_blocked) ? draftResult.uncited_claims_blocked : []);
        const evidenceSnippets = Array.isArray(draftResult?.evidence_snippets)
            ? draftResult.evidence_snippets
            : [];
        const safetyNote = validationResult.draft_only_note || draft.safety_note || defaultSafetyNoteFor(request.role);
        observabilityState.token_usage = normalizeTokenUsage(draftResult?.meta?.token_usage || null);
        observabilityState.model = draftResult?.meta?.model || draft.model || null;
        observabilityState.provider = draftResult?.meta?.provider || draft.provider || 'local_fallback';
        const costEstimate = estimateCost({
            explicitCostUsd: draftResult?.meta?.estimated_cost_usd,
            tokenUsage: observabilityState.token_usage,
            model: observabilityState.model,
            provider: observabilityState.provider
        });
        observabilityState.estimated_cost_usd = costEstimate.estimated_cost_usd;
        observabilityState.cost_note = costEstimate.cost_note;
        observabilityState.retrieval = {
            hit_count: sources.length,
            top_k: Math.max(
                Number(draftResult?.meta?.reranked_result_count || 0),
                evidenceSnippets.length,
                sourcesUsed.length
            ),
            retrieval_mode: draftResult?.meta?.retrieval_mode || validationResult?.retrieval_mode || 'none',
            rerank_provider: draftResult?.meta?.rerank_provider || validationResult?.rerank_provider || 'none',
            sparse_hit_count: Number(draftResult?.meta?.sparse_result_count || 0),
            dense_hit_count: Number(draftResult?.meta?.dense_result_count || 0),
            hybrid_candidate_count: Number(draftResult?.meta?.hybrid_candidate_count || 0),
            reranked_hit_count: Number(draftResult?.meta?.reranked_result_count || evidenceSnippets.length || 0),
            final_evidence_count: evidenceSnippets.length,
            top_source_types: topSourceTypes(sourcesUsed, sources),
            citation_count: claims.reduce(function (count, claim) {
                return count + (Array.isArray(claim && claim.citations) ? claim.citations.length : 0);
            }, 0)
        };
        observabilityState.extraction = summarizeExtractionResult(toolResults.attachmentResult, attachment, validationResult);
        observabilityState.safety.safe_refusal = Boolean(!validationResult.allowed);
        observabilityState.safety.blocked_reason = validationResult.blocked_reason || null;
        observabilityState.extraction.citation_contract_valid = validationResult?.citation_contract_status
            ? String(validationResult.citation_contract_status).trim().toLowerCase() === 'passed'
            : observabilityState.extraction.citation_contract_valid;
        const meta = {
            ...(draftResult && draftResult.meta ? draftResult.meta : {}),
            engine: draftResult?.meta?.engine || draft.engine || 'fallback',
            provider: draftResult?.meta?.provider || draft.provider || 'local_fallback',
            model: draftResult?.meta?.model || draft.model || null,
            token_usage: observabilityState.token_usage,
            token_usage_estimated: Boolean(observabilityState.token_usage.token_usage_estimated),
            estimated_cost_usd: draftResult?.meta?.estimated_cost_usd ?? observabilityState.estimated_cost_usd,
            cost_note: draftResult?.meta?.cost_note || costEstimate.cost_note || null,
            restricted_by_role: Boolean(blocked),
            restriction_type: blocked ? (validationResult.blocked_reason || 'evidence_safety_block') : '',
            rag_grounded: draftResult?.meta?.rag_grounded !== undefined
                ? Boolean(draftResult.meta.rag_grounded)
                : sources.length > 0,
            retrieval_mode: draftResult?.meta?.retrieval_mode || validationResult?.retrieval_mode || '',
            rerank_provider: draftResult?.meta?.rerank_provider || validationResult?.rerank_provider || '',
            sparse_result_count: Number(draftResult?.meta?.sparse_result_count || 0),
            dense_result_count: Number(draftResult?.meta?.dense_result_count || 0),
            hybrid_candidate_count: Number(draftResult?.meta?.hybrid_candidate_count || 0),
            reranked_result_count: Number(draftResult?.meta?.reranked_result_count || evidenceSnippets.length || 0),
            guideline_chunk_count: Number(draftResult?.meta?.guideline_chunk_count || 0),
            uploaded_chunk_count: Number(draftResult?.meta?.uploaded_chunk_count || 0),
            claim_count: Number(validationResult?.claim_count ?? claims.length),
            cited_claim_count: Number(validationResult?.cited_claim_count ?? claims.length),
            uncited_claim_count: Number(validationResult?.uncited_claim_count ?? uncitedClaimsBlocked.length),
            invalid_citation_count: Number(validationResult?.invalid_citation_count ?? 0),
            blocked_claim_count: Number(validationResult?.blocked_claim_count ?? uncitedClaimsBlocked.length),
            citation_contract_status: validationResult?.citation_contract_status || '',
            agent_architecture: 'week2_supervisor_intake_evidence',
            agent_trace: trace,
            agent_tools: Object.keys(toolSchemas),
            tool_schemas: toolSchemas,
            agent_workers: ['Supervisor', 'IntakeExtractorWorker', 'EvidenceRetrieverWorker', 'FinalResponse'],
            legacy_agent_workers: ['Supervisor Agent', 'Chart Retrieval Worker', 'Evidence + Safety Worker', 'Final Draft'],
            supervisor_decisions: supervisorDecisions,
            worker_handoffs: workerHandoffs,
            demo_use_cases: DEMO_USE_CASES
        };

        if (toolResults.attachmentResult && toolResults.attachmentResult.tool_output) {
            meta.tool_output = toolResults.attachmentResult.tool_output;
        }

        const finalResponseStartedAt = Date.now();
        if (blocked) {
            pushMachineDecision(tracer, supervisorDecisions, request, 'safe_refusal', validationResult.safe_refusal || answer, 'SafeRefusal');
            tracer.push('FinalResponse', 'safe_refusal', 'Returning safe refusal with sources and draft-only review language.', {
                sourceCount: sources.length,
                sectionCount: sections.length,
                blockedReason: validationResult.blocked_reason || ''
            }, 'blocked');
            recordToolSequence({
                step: 'FinalResponse',
                decision: 'safe_refusal',
                status: 'blocked',
                latency_ms: 0
            });
        } else {
            pushMachineDecision(tracer, supervisorDecisions, request, 'final_answer_ready', 'Required worker calls completed and the response is ready with draft-only review language.', 'FinalResponse');
            tracer.push('FinalResponse', 'final_answer_ready', 'Returning grounded draft with sources and review language.', {
                sourceCount: sources.length,
                sectionCount: sections.length
            }, 'complete');
            recordToolSequence({
                step: 'FinalResponse',
                decision: 'final_answer_ready',
                status: meta.tool_output ? 'review_required' : 'ready',
                latency_ms: 0
            });
        }
        finishLatency('final_response_ms', finalResponseStartedAt);
        meta.observability = buildObservabilitySnapshot({
            token_usage: observabilityState.token_usage,
            model: observabilityState.model,
            provider: observabilityState.provider,
            estimated_cost_usd: meta.estimated_cost_usd,
            retrieval: observabilityState.retrieval,
            extraction: observabilityState.extraction,
            safety: observabilityState.safety
        });

        return {
            ok: true,
            mode: request.mode || 'general_assistant',
            role: request.role || 'doctor',
            patient: toolResults.chartContextResult?.patient?.name || request.patientName || null,
            answer: answer,
            sections: sections,
            tags: unique((draft.tags || []).concat(validationResult.policy_flags || [])),
            sources: sources,
            claims: claims,
            sources_used: sourcesUsed,
            uncited_claims_blocked: uncitedClaimsBlocked,
            evidence_snippets: evidenceSnippets,
            safety_status: blocked ? 'safe_refusal' : (validationResult?.citation_contract_status || ''),
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
