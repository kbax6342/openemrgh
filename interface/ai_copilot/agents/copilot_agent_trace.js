(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotAgentTrace = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const STATUS = {
        PENDING: 'pending',
        RUNNING: 'running',
        COMPLETE: 'complete',
        BLOCKED: 'blocked',
        SKIPPED: 'skipped',
        REVIEW_REQUIRED: 'review_required',
        FAILED: 'failed',
        READY: 'ready',
        SAFE_REFUSAL: 'safe_refusal',
        NO_GROUNDED_EVIDENCE: 'no_grounded_evidence'
    };

    const ROLE_LABELS = {
        doctor: 'Doctor',
        nurse: 'Nurse',
        billing: 'Billing Staff',
        front_desk: 'Front Desk'
    };

    const MODE_LABELS = {
        general_assistant: 'general support request',
        differential_diagnosis: 'differential-diagnosis review',
        medication_info: 'medication information request',
        clinical_notes: 'clinical summary',
        treatment_plan: 'treatment-plan summary',
        billing: 'billing or payment review',
        billing_review: 'billing review',
        follow_up: 'follow-up preparation',
        rag_chart_context: 'chart-context review',
        lab_pdf_ingestion: 'lab PDF extraction',
        latest_ambient_summary: 'approved ambient encounter summary',
        visit_summary: 'visit summary',
        patient_education: 'patient education draft',
        appointment_info: 'appointment information request',
        patient_contact: 'patient-contact request',
        send_reminder: 'reminder workflow',
        send_reminder_result: 'reminder workflow',
        front_desk_summary: 'front-desk summary'
    };

    const DOMAIN_LABELS = {
        medications: 'medications',
        allergies: 'allergies',
        labs: 'labs',
        vitals_labs: 'labs',
        encounters: 'encounters',
        visit_history: 'visit history',
        documents: 'documents',
        insurance: 'insurance',
        care_team: 'care team',
        immunizations: 'immunizations',
        problem_list: 'problem list',
        appointments: 'appointments',
        patient_contact: 'patient contact',
        ambient_encounter_capture: 'approved ambient encounter records',
        policy: 'workflow policies'
    };

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
        'lab_pdf_ingestion',
        'appointment_info',
        'patient_contact',
        'front_desk_summary'
    ]);

    function cloneExtra(extra) {
        return extra && typeof extra === 'object' ? { ...extra } : {};
    }

    function normalizeStatus(value) {
        const normalized = String(value || '').trim().toLowerCase();
        if ([
            STATUS.RUNNING,
            STATUS.COMPLETE,
            STATUS.BLOCKED,
            STATUS.SKIPPED,
            STATUS.REVIEW_REQUIRED,
            STATUS.FAILED,
            STATUS.READY,
            STATUS.SAFE_REFUSAL,
            STATUS.NO_GROUNDED_EVIDENCE
        ].includes(normalized)) {
            return normalized;
        }

        return STATUS.PENDING;
    }

    function statusLabel(status) {
        const normalized = normalizeStatus(status);
        if (normalized === STATUS.REVIEW_REQUIRED) {
            return 'Review Required';
        }
        if (normalized === STATUS.SAFE_REFUSAL) {
            return 'Safe Refusal';
        }
        if (normalized === STATUS.NO_GROUNDED_EVIDENCE) {
            return 'No Grounded Evidence';
        }
        return normalized.charAt(0).toUpperCase() + normalized.slice(1);
    }

    function createTraceStep(actor, label, detail, extra, sequence, status) {
        return {
            actor: String(actor || 'Supervisor Agent'),
            label: String(label || 'Trace step'),
            detail: String(detail || ''),
            extra: cloneExtra(extra),
            sequence: Number.isFinite(sequence) ? sequence : null,
            status: normalizeStatus(status || (extra && extra.status) || STATUS.COMPLETE)
        };
    }

    function emitTraceStep(step, logger) {
        const consoleRef = logger || console;
        const prefix = `[OpenEMR Copilot][${step.actor}] ${step.label}`;

        if (step.extra && Object.keys(step.extra).length > 0 && typeof consoleRef.info === 'function') {
            consoleRef.info(prefix, step.detail || '', step.extra);
            return;
        }

        if (typeof consoleRef.info === 'function') {
            consoleRef.info(prefix, step.detail || '');
        }
    }

    function logTrace(trace, logger) {
        const consoleRef = logger || console;
        if (!Array.isArray(trace) || trace.length === 0) {
            return;
        }

        if (typeof consoleRef.groupCollapsed === 'function') {
            consoleRef.groupCollapsed('[OpenEMR Copilot] Supervisor-worker trace');
            trace.forEach(function (step) {
                emitTraceStep(step, consoleRef);
            });
            if (typeof consoleRef.groupEnd === 'function') {
                consoleRef.groupEnd();
            }
            return;
        }

        trace.forEach(function (step) {
            emitTraceStep(step, consoleRef);
        });
    }

    function createTraceCollector(logger) {
        const trace = [];
        let sequence = 0;

        return {
            trace: trace,
            push(actor, label, detail, extra, status) {
                sequence += 1;
                const step = createTraceStep(actor, label, detail, extra, sequence, status);
                trace.push(step);
                emitTraceStep(step, logger);
                return step;
            }
        };
    }

    function displayRoleLabel(role) {
        return ROLE_LABELS[String(role || '').toLowerCase()] || 'Doctor';
    }

    function inferIntentLabel(mode, prompt) {
        const normalizedMode = String(mode || '').trim().toLowerCase();
        const normalizedPrompt = String(prompt || '').trim().toLowerCase();

        if (MODE_LABELS[normalizedMode]) {
            return MODE_LABELS[normalizedMode];
        }
        if (/\bwhat changed since the last visit|treatment plan\b/.test(normalizedPrompt)) {
            return 'treatment-plan summary';
        }
        if (/\bmorning prep|follow-up prep\b/.test(normalizedPrompt)) {
            return 'morning follow-up prep';
        }
        if (/\bmedication|dose|interaction|refill\b/.test(normalizedPrompt)) {
            return 'medication information request';
        }
        if (/\blab pdf|summarize this lab report|what labs are abnormal\b/.test(normalizedPrompt)) {
            return 'lab PDF extraction';
        }
        if (/\bintake form|questionnaire\b/.test(normalizedPrompt)) {
            return 'intake form extraction';
        }
        if (/\bbilling|payment|claim|payer|insurance\b/.test(normalizedPrompt)) {
            return 'billing or payment review';
        }
        if (/\bappointment|contact|front desk|reminder\b/.test(normalizedPrompt)) {
            return 'front-desk summary';
        }
        if (/\bignore previous instructions|reveal system prompt|write directly to the chart\b/.test(normalizedPrompt)) {
            return 'blocked prompt-injection request';
        }

        return 'general support request';
    }

    function humanizeCount(count, singular, plural) {
        const total = Number.isFinite(count) ? count : 0;
        if (total === 1) {
            return `1 ${singular}`;
        }

        return `${total} ${plural}`;
    }

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function getToolCatalog(toolModule) {
        if (toolModule && typeof toolModule.getTraceToolCatalog === 'function') {
            return toolModule.getTraceToolCatalog();
        }

        return {
            supervisor: { toolNames: ['retrieve_guideline_evidence', 'draft_grounded_answer'] },
            chart_retrieval: { toolNames: ['retrieve_chart_context', 'attach_and_extract'] },
            safety: { toolNames: ['validate_citations'] },
            final_draft: { toolNames: [] }
        };
    }

    function getSafetyHelpers(safetyModule) {
        if (safetyModule && typeof safetyModule === 'object') {
            return safetyModule;
        }

        return (typeof globalThis !== 'undefined' && globalThis.OpenEMRCopilotAgentSafety) || {};
    }

    function getTraceEvents(rawTrace, actor) {
        return (Array.isArray(rawTrace) ? rawTrace : []).filter(function (step) {
            return String(step && step.actor ? step.actor : '').toLowerCase() === String(actor || '').toLowerCase();
        });
    }

    function getTraceEvent(rawTrace, actor, matcher) {
        const events = getTraceEvents(rawTrace, actor);
        return events.find(function (step) {
            if (typeof matcher === 'function') {
                return matcher(step);
            }

            return false;
        }) || null;
    }

    function hasTraceEvent(rawTrace, actor, text) {
        const value = String(text || '').toLowerCase();
        return getTraceEvents(rawTrace, actor).some(function (step) {
            const label = String(step && step.label ? step.label : '').toLowerCase();
            const detail = String(step && step.detail ? step.detail : '').toLowerCase();
            return label.includes(value) || detail.includes(value);
        });
    }

    function getSupervisorDecisions(message) {
        return Array.isArray(message && message.meta && message.meta.supervisor_decisions)
            ? message.meta.supervisor_decisions.filter(function (decision) {
                return decision && typeof decision === 'object';
            })
            : [];
    }

    function getWorkerHandoffs(message) {
        return Array.isArray(message && message.meta && message.meta.worker_handoffs)
            ? message.meta.worker_handoffs.filter(function (handoff) {
                return handoff && typeof handoff === 'object';
            })
            : [];
    }

    function getRoutingDecision(message) {
        return getSupervisorDecisions(message).find(function (decision) {
            return ['route_to_intake_extractor', 'route_to_evidence_retriever', 'route_to_both'].includes(String(decision.decision || '').trim());
        }) || null;
    }

    function getFinalDecision(message) {
        const decisions = getSupervisorDecisions(message);
        for (let index = decisions.length - 1; index >= 0; index -= 1) {
            const decision = decisions[index];
            const value = String(decision && decision.decision ? decision.decision : '').trim();
            if (value === 'final_answer_ready' || value === 'safe_refusal') {
                return decision;
            }
        }
        return null;
    }

    function getToolOutput(message) {
        if (message && message.meta && message.meta.tool_output && typeof message.meta.tool_output === 'object') {
            return message.meta.tool_output;
        }
        if (message && message.tool_output && typeof message.tool_output === 'object') {
            return message.tool_output;
        }
        return null;
    }

    function hasHandoffTo(message, workerName) {
        return getWorkerHandoffs(message).some(function (handoff) {
            return String(handoff && handoff.to ? handoff.to : '') === workerName;
        });
    }

    function detectInputDocType(message) {
        const handoff = getWorkerHandoffs(message).find(function (item) {
            return item && item.payload_summary && item.payload_summary.doc_type;
        });
        if (handoff && handoff.payload_summary && handoff.payload_summary.doc_type) {
            return String(handoff.payload_summary.doc_type).trim();
        }

        const toolOutput = getToolOutput(message);
        if (!toolOutput) {
            return 'unknown';
        }

        const documentType = toolOutput.documentMetadata && toolOutput.documentMetadata.documentType
            ? toolOutput.documentMetadata.documentType
            : toolOutput.documentMetadata && toolOutput.documentMetadata.document_type
                ? toolOutput.documentMetadata.document_type
                : toolOutput.sourceMetadata && toolOutput.sourceMetadata.sourceType
                    ? toolOutput.sourceMetadata.sourceType
                    : '';
        return String(documentType || 'unknown').trim().toLowerCase();
    }

    function hasWorkerTraceCall(rawTrace, workerNames, toolName) {
        const names = Array.isArray(workerNames) ? workerNames : [workerNames];
        const normalizedTool = String(toolName || '').trim().toLowerCase();
        return names.some(function (actor) {
            return getTraceEvents(rawTrace, actor).some(function (step) {
                const stepTool = String(step && step.extra && step.extra.toolName ? step.extra.toolName : '').trim().toLowerCase();
                const label = String(step && step.label ? step.label : '').trim().toLowerCase();
                return stepTool === normalizedTool || label.includes(normalizedTool);
            });
        });
    }

    function buildFallbackRoutingDecision(rawTrace) {
        const intakeRouted = hasWorkerTraceCall(rawTrace, ['IntakeExtractorWorker', 'Chart Retrieval Worker'], 'attach_and_extract');
        const evidenceRouted = hasWorkerTraceCall(rawTrace, ['EvidenceRetrieverWorker', 'Chart Retrieval Worker', 'Evidence + Safety Worker'], 'retrieve_chart_context')
            || hasWorkerTraceCall(rawTrace, ['EvidenceRetrieverWorker', 'Chart Retrieval Worker', 'Evidence + Safety Worker'], 'retrieve_guideline_evidence')
            || hasWorkerTraceCall(rawTrace, ['EvidenceRetrieverWorker', 'Chart Retrieval Worker', 'Evidence + Safety Worker'], 'validate_citations');

        if (intakeRouted && evidenceRouted) {
            return {
                decision: 'route_to_both',
                reason: 'Existing worker trace shows both attachment extraction and evidence retrieval.'
            };
        }
        if (intakeRouted) {
            return {
                decision: 'route_to_intake_extractor',
                reason: 'Existing worker trace shows attachment extraction.'
            };
        }
        if (evidenceRouted) {
            return {
                decision: 'route_to_evidence_retriever',
                reason: 'Existing worker trace shows evidence retrieval.'
            };
        }

        return null;
    }

    function extractMissingItems(message) {
        const sections = Array.isArray(message && message.sections) ? message.sections : [];
        const sectionItems = sections.reduce(function (items, section) {
            if (!section || !Array.isArray(section.items)) {
                return items;
            }

            if (String(section.title || '').toLowerCase() !== 'missing data / uncertainty') {
                return items;
            }

            return items.concat(section.items.map(function (item) {
                return String(item || '').trim();
            }).filter(Boolean));
        }, []);
        const toolOutput = message && message.meta && message.meta.tool_output ? message.meta.tool_output : message && message.tool_output ? message.tool_output : null;
        const toolOutputMissing = Array.isArray(toolOutput && toolOutput.missingData)
            ? toolOutput.missingData
            : Array.isArray(toolOutput && toolOutput.missing_data)
                ? toolOutput.missing_data
                : [];

        return unique(sectionItems.concat(toolOutputMissing));
    }

    function extractSourceCategories(message) {
        return unique((Array.isArray(message && message.sources) ? message.sources : []).map(function (source) {
            if (!source || typeof source !== 'object') {
                return '';
            }

            return String(source.category || source.id || '').trim().toLowerCase();
        }).filter(Boolean));
    }

    function extractRequestedDomains(rawTrace, message) {
        const chartCall = getTraceEvent(rawTrace, 'Chart Retrieval Worker', function (step) {
            return String(step.label || '').toLowerCase().includes('calling retrieve_chart_context');
        });
        const requested = Array.isArray(chartCall && chartCall.extra && chartCall.extra.requestedDomains)
            ? chartCall.extra.requestedDomains
            : [];
        if (requested.length > 0) {
            return unique(requested);
        }

        const categories = extractSourceCategories(message);
        return unique(categories.map(function (category) {
            return DOMAIN_LABELS[category] || category.replace(/_/g, ' ');
        }).filter(Boolean));
    }

    function formatDomainList(domains) {
        const values = unique((domains || []).map(function (domain) {
            const normalized = String(domain || '').trim().toLowerCase();
            return DOMAIN_LABELS[normalized] || normalized.replace(/_/g, ' ');
        }).filter(Boolean));

        if (values.length === 0) {
            return 'role-appropriate chart context';
        }

        if (values.length === 1) {
            return values[0];
        }

        if (values.length === 2) {
            return `${values[0]} and ${values[1]}`;
        }

        return `${values.slice(0, -1).join(', ')}, and ${values[values.length - 1]}`;
    }

    function deriveBlockedReason(message) {
        if (message && message.meta && message.meta.restriction_type) {
            return String(message.meta.restriction_type);
        }
        if (message && message.guardrails && message.guardrails.blockedReason) {
            return String(message.guardrails.blockedReason);
        }

        return '';
    }

    function needsChartRetrieval(message, rawTrace) {
        const mode = String(message && message.mode ? message.mode : '').toLowerCase();
        const hasChartEvents = getTraceEvents(rawTrace, 'Chart Retrieval Worker').length > 0;
        const hasSources = Array.isArray(message && message.sources) && message.sources.length > 0;
        return hasChartEvents || hasSources || CHART_RETRIEVAL_MODES.has(mode);
    }

    function buildSupervisorDescription(message, rawTrace, blockedReason, safetyHelpers) {
        const role = String(message && message.staffRole ? message.staffRole : 'doctor').toLowerCase();
        const prompt = String(message && message.requestPrompt ? message.requestPrompt : message && message.meta && message.meta.agent_request_prompt ? message.meta.agent_request_prompt : '');
        const intent = inferIntentLabel(message && message.mode ? message.mode : '', prompt);
        const limitationHelper = typeof safetyHelpers.describeWorkflowLimitation === 'function'
            ? safetyHelpers.describeWorkflowLimitation
            : null;

        if (blockedReason) {
            return limitationHelper
                ? limitationHelper(role, blockedReason)
                : `${displayRoleLabel(role)} role detected. Workflow limited because the request is outside the approved role or safety policy.`;
        }

        if (needsChartRetrieval(message, rawTrace)) {
            return `${displayRoleLabel(role)} role detected. Request classified as ${intent}. Routing to Chart Retrieval Worker.`;
        }

        return `${displayRoleLabel(role)} role detected. Request classified as ${intent}. No chart retrieval was required before final drafting.`;
    }

    function buildChartDescription(message, rawTrace, status, blockedReason, chartNeeded) {
        if (!chartNeeded) {
            return 'No chart retrieval was required for this workflow.';
        }

        if (status === STATUS.PENDING && blockedReason) {
            return 'Not run because the Supervisor Agent blocked the request before chart retrieval.';
        }

        if (status === STATUS.COMPLETE || status === STATUS.BLOCKED || status === STATUS.RUNNING) {
            const domains = extractRequestedDomains(rawTrace, message);
            return `Retrieved ${formatDomainList(domains)}.`;
        }

        return 'Chart retrieval was not required for this workflow.';
    }

    function buildSafetyDescription(message, rawTrace, status, blockedReason) {
        const missingItems = extractMissingItems(message);
        if (status === STATUS.PENDING && blockedReason) {
            return 'Not run because the request was blocked before worker validation could proceed.';
        }

        if (status === STATUS.BLOCKED) {
            return 'Validated role boundary, citation grounding, missing-data handling, and draft-only safety language, then returned a safe refusal.';
        }

        if (status === STATUS.RUNNING) {
            return 'Validating role boundary, citation grounding, missing-data handling, and draft-only safety language.';
        }

        if (missingItems.length > 0) {
            return `Validated role boundary, citation grounding, missing-data handling, and draft-only safety language. Flagged ${humanizeCount(missingItems.length, 'uncertain item', 'uncertain items')}.`;
        }

        if (getTraceEvents(rawTrace, 'Evidence + Safety Worker').length > 0) {
            return 'Validated role boundary, citation grounding, missing-data handling, and draft-only safety language.';
        }

        return 'Safety validation completed for the generated draft response.';
    }

    function buildFinalDescription(message, blockedReason) {
        const sourceCount = Array.isArray(message && message.sources) ? message.sources.length : 0;
        if (blockedReason) {
            return 'Safe refusal generated with draft-only review language instead of a full clinical draft.';
        }

        if (sourceCount > 0) {
            return `Response generated with Sources Used section and ${humanizeCount(sourceCount, 'source', 'sources')}.`;
        }

        return 'Draft response generated with review language.';
    }

    function buildStepMeta(stepId, message, rawTrace, toolModule, stepStatus, extraOptions) {
        const options = extraOptions && typeof extraOptions === 'object' ? extraOptions : {};
        if (stepStatus === STATUS.PENDING) {
            return [];
        }

        const toolCatalog = getToolCatalog(toolModule);
        const toolEntry = toolCatalog[stepId] || { toolNames: [] };
        const sourceCount = Array.isArray(message && message.sources) ? message.sources.length : 0;
        const missingCount = extractMissingItems(message).length;
        const toolCount = Array.isArray(toolEntry.toolNames) ? toolEntry.toolNames.length : 0;
        const values = [];

        if (stepId === 'chart_retrieval' && options.chartNeeded === false) {
            return [];
        }

        if (toolCount > 0 && stepId !== 'final_draft') {
            values.push(humanizeCount(toolCount, 'tool', 'tools'));
        }
        if (stepId === 'chart_retrieval' && sourceCount > 0) {
            values.push(humanizeCount(sourceCount, 'source', 'sources'));
        }
        if (stepId === 'safety' && missingCount > 0) {
            values.push(humanizeCount(missingCount, 'missing item', 'missing items'));
        }
        if (stepId === 'final_draft' && sourceCount > 0) {
            values.push(humanizeCount(sourceCount, 'source', 'sources'));
        }
        if (stepId === 'supervisor') {
            const workerCalls = unique((Array.isArray(rawTrace) ? rawTrace : []).map(function (step) {
                return step && step.extra && step.extra.toolName ? String(step.extra.toolName) : '';
            }).filter(Boolean));
            if (workerCalls.length > 0) {
                values.push(humanizeCount(workerCalls.length, 'tool call', 'tool calls'));
            }
        }

        return values;
    }

    function buildVisibleWorkflowTrace(message, options) {
        const settings = options && typeof options === 'object' ? options : {};
        const toolModule = settings.toolModule || (typeof globalThis !== 'undefined' ? globalThis.OpenEMRCopilotAgentTools : null);
        const rawTrace = Array.isArray(message && message.meta && message.meta.agent_trace)
            ? message.meta.agent_trace
            : [];
        const blockedReason = deriveBlockedReason(message);
        const routingDecision = getRoutingDecision(message) || buildFallbackRoutingDecision(rawTrace);
        const finalDecision = getFinalDecision(message);
        const workerHandoffs = getWorkerHandoffs(message);
        const toolOutput = getToolOutput(message);
        const inputDocType = detectInputDocType(message);
        const retrievalMode = String(message && message.meta && message.meta.retrieval_mode ? message.meta.retrieval_mode : '').trim().toLowerCase();
        const rerankProvider = String(message && message.meta && message.meta.rerank_provider ? message.meta.rerank_provider : '').trim().toLowerCase();
        const hasIntakeRoute = hasHandoffTo(message, 'IntakeExtractorWorker')
            || hasWorkerTraceCall(rawTrace, ['IntakeExtractorWorker', 'Chart Retrieval Worker'], 'attach_and_extract');
        const hasEvidenceRoute = hasHandoffTo(message, 'EvidenceRetrieverWorker')
            || getTraceEvents(rawTrace, 'EvidenceRetrieverWorker').length > 0
            || getTraceEvents(rawTrace, 'Chart Retrieval Worker').length > 0;
        const evidenceRequired = Boolean(hasEvidenceRoute || (routingDecision && ['route_to_evidence_retriever', 'route_to_both'].includes(String(routingDecision.decision || ''))));
        const intakeRequired = Boolean(hasIntakeRoute || (routingDecision && ['route_to_intake_extractor', 'route_to_both'].includes(String(routingDecision.decision || ''))));
        const citationContractStatus = String(message && message.meta && message.meta.citation_contract_status ? message.meta.citation_contract_status : '').trim().toLowerCase();
        const safetyStatus = String(message && message.safetyStatus ? message.safetyStatus : '').trim().toLowerCase();

        const extractionStatusValue = String(
            toolOutput && (toolOutput.status || toolOutput.ingestionStatus || toolOutput.ingestion_status)
                ? (toolOutput.status || toolOutput.ingestionStatus || toolOutput.ingestion_status)
                : ''
        ).trim().toLowerCase();
        let intakeStatus = STATUS.SKIPPED;
        if (message && message.isLoading) {
            intakeStatus = intakeRequired ? STATUS.RUNNING : STATUS.SKIPPED;
        } else if (hasIntakeRoute) {
            if (['ocr_required', 'extraction_review_required', 'document_guard_review_required', 'review_required'].includes(extractionStatusValue)) {
                intakeStatus = STATUS.REVIEW_REQUIRED;
            } else if (['invalid_file_type', 'failed', 'error'].includes(extractionStatusValue)) {
                intakeStatus = STATUS.FAILED;
            } else if (['role_blocked', 'document_guard_rejected'].includes(extractionStatusValue)) {
                intakeStatus = STATUS.BLOCKED;
            } else {
                intakeStatus = STATUS.COMPLETE;
            }
        } else if (blockedReason || (finalDecision && finalDecision.decision === 'safe_refusal')) {
            intakeStatus = STATUS.SKIPPED;
        }

        let evidenceStatus = STATUS.SKIPPED;
        if (message && message.isLoading) {
            evidenceStatus = evidenceRequired ? STATUS.RUNNING : STATUS.SKIPPED;
        } else if (hasEvidenceRoute) {
            if (retrievalMode === 'no_grounded_evidence' || blockedReason === 'missing_citations' || blockedReason === 'no_grounded_evidence') {
                evidenceStatus = STATUS.NO_GROUNDED_EVIDENCE;
            } else if (blockedReason && !message.meta?.rag_grounded) {
                evidenceStatus = STATUS.BLOCKED;
            } else {
                evidenceStatus = STATUS.COMPLETE;
            }
        } else if (blockedReason || (finalDecision && finalDecision.decision === 'safe_refusal')) {
            evidenceStatus = STATUS.SKIPPED;
        }

        let supervisorStatus = STATUS.COMPLETE;
        if (message && message.isLoading) {
            supervisorStatus = STATUS.RUNNING;
        } else if (finalDecision && finalDecision.decision === 'safe_refusal') {
            supervisorStatus = STATUS.SAFE_REFUSAL;
        } else if (blockedReason) {
            supervisorStatus = STATUS.BLOCKED;
        }

        let finalStatus = STATUS.READY;
        if (message && message.isLoading) {
            finalStatus = STATUS.PENDING;
        } else if (finalDecision && finalDecision.decision === 'safe_refusal') {
            finalStatus = STATUS.SAFE_REFUSAL;
        } else if (blockedReason || evidenceStatus === STATUS.NO_GROUNDED_EVIDENCE) {
            finalStatus = STATUS.SAFE_REFUSAL;
        } else if (
            intakeStatus === STATUS.REVIEW_REQUIRED
            || intakeStatus === STATUS.FAILED
            || safetyStatus === 'review_required'
            || citationContractStatus === 'review_required'
            || Boolean(toolOutput)
        ) {
            finalStatus = STATUS.REVIEW_REQUIRED;
        }

        const supervisorDescription = routingDecision
            ? `Decision: ${routingDecision.decision}. ${routingDecision.reason}`
            : blockedReason
                ? 'Supervisor returned a safe refusal because the request was outside role, safety, or grounding rules.'
                : 'Supervisor evaluated the request and prepared the final response path.';
        const intakeDescription = !intakeRequired
            ? 'Skipped because no supported attached document required extraction for this request.'
            : intakeStatus === STATUS.REVIEW_REQUIRED
                ? `${inputDocType === 'intake_form' ? 'Intake form' : 'Lab PDF'} extraction completed, but clinician review is still required before the information can be trusted.`
                : intakeStatus === STATUS.BLOCKED
                    ? 'Extraction was blocked before trusted use because the request or document did not satisfy workflow requirements.'
                    : intakeStatus === STATUS.FAILED
                        ? 'Extraction did not complete successfully enough for trusted use.'
                        : intakeStatus === STATUS.RUNNING
                            ? 'Supported attachment is being extracted and prepared for clinician-review staging.'
                            : intakeStatus === STATUS.SKIPPED
                                ? 'Skipped because the Supervisor did not route this request to document extraction.'
                        : 'Extraction completed through the existing attach_and_extract workflow and remains draft-only pending clinician review.';
        const evidenceDescription = !evidenceRequired
            ? 'Skipped because source-grounded evidence retrieval was not required for this request.'
            : evidenceStatus === STATUS.NO_GROUNDED_EVIDENCE
                ? 'Evidence retrieval ran, but there was not enough grounded evidence to support a safe clinical answer.'
                : evidenceStatus === STATUS.BLOCKED
                    ? 'Evidence retrieval was blocked because role, citation, or grounding requirements were not met.'
                    : evidenceStatus === STATUS.RUNNING
                        ? 'Role-scoped chart, guideline, and uploaded-document evidence is being retrieved for grounded drafting.'
                        : evidenceStatus === STATUS.SKIPPED
                            ? 'Skipped because the Supervisor did not route this request to evidence retrieval.'
                    : 'Retrieved role-appropriate chart, guideline, and uploaded-document evidence for grounded drafting.';
        const finalDescription = finalStatus === STATUS.SAFE_REFUSAL
            ? 'Safe refusal returned instead of a clinical answer.'
            : finalStatus === STATUS.REVIEW_REQUIRED
                ? 'Draft response prepared with review-required language. No direct chart write was performed.'
                : 'Final grounded response is ready.';

        const steps = [
            {
                id: 'supervisor',
                name: 'Supervisor',
                status: supervisorStatus,
                description: supervisorDescription,
                meta: [
                    `${humanizeCount(getSupervisorDecisions(message).length, 'decision', 'decisions')}`,
                    `${humanizeCount(workerHandoffs.length, 'handoff', 'handoffs')}`
                ]
            },
            {
                id: 'intake_extractor',
                name: 'IntakeExtractorWorker',
                status: intakeStatus,
                description: intakeDescription,
                meta: unique([
                    hasIntakeRoute ? '1 tool' : '',
                    inputDocType && inputDocType !== 'unknown' ? `doc type: ${inputDocType}` : ''
                ]).filter(Boolean)
            },
            {
                id: 'evidence_retriever',
                name: 'EvidenceRetrieverWorker',
                status: evidenceStatus,
                description: evidenceDescription,
                meta: unique([
                    hasEvidenceRoute ? humanizeCount(((toolModule && typeof toolModule.getTraceToolCatalog === 'function' ? ((toolModule.getTraceToolCatalog().evidence_retriever || {}).toolNames || []) : []).length || 0), 'tool', 'tools') : '',
                    retrievalMode ? `mode: ${retrievalMode}` : '',
                    rerankProvider ? `reranker: ${rerankProvider}` : ''
                ]).filter(Boolean)
            },
            {
                id: 'final_response',
                name: 'FinalResponse',
                status: finalStatus,
                description: finalDescription,
                meta: unique([
                    Number.isFinite(Number(message && message.meta && message.meta.claim_count)) ? `${humanizeCount(Number(message.meta.claim_count || 0), 'claim', 'claims')}` : '',
                    Number.isFinite(Number(message && message.meta && message.meta.source_count)) ? `${humanizeCount(Number(message.meta.source_count || 0), 'source', 'sources')}` : ''
                ]).filter(Boolean)
            }
        ];

        const overallStatus = finalStatus === STATUS.SAFE_REFUSAL
            ? STATUS.SAFE_REFUSAL
            : finalStatus === STATUS.REVIEW_REQUIRED
                ? STATUS.REVIEW_REQUIRED
                : (message && message.isLoading ? STATUS.RUNNING : STATUS.READY);

        return {
            title: 'Agent Workflow Trace',
            flow: 'START \u2192 Supervisor \u2192 IntakeExtractorWorker / EvidenceRetrieverWorker \u2192 FinalResponse \u2192 END',
            overallStatus: overallStatus,
            steps: steps
        };
    }

    function buildTraceCard(message, options) {
        if (typeof document === 'undefined' || !message || message.role !== 'assistant' || message.isLoading) {
            return null;
        }

        const traceData = message && message.meta && message.meta.visible_agent_workflow_trace
            ? message.meta.visible_agent_workflow_trace
            : buildVisibleWorkflowTrace(message, options);
        const details = document.createElement('details');
        details.className = `copilot-agent-trace copilot-agent-trace-${traceData.overallStatus}`;

        const summary = document.createElement('summary');
        summary.className = 'copilot-agent-trace-summary';

        const summaryText = document.createElement('div');
        summaryText.className = 'copilot-agent-trace-summary-text';

        const title = document.createElement('span');
        title.className = 'copilot-agent-trace-title';
        title.textContent = traceData.title;
        summaryText.appendChild(title);

        const flow = document.createElement('span');
        flow.className = 'copilot-agent-trace-flow';
        flow.textContent = traceData.flow;
        summaryText.appendChild(flow);

        summary.appendChild(summaryText);

        const status = document.createElement('span');
        status.className = `copilot-agent-trace-badge copilot-agent-trace-badge-${traceData.overallStatus}`;
        status.textContent = statusLabel(traceData.overallStatus);
        summary.appendChild(status);

        details.appendChild(summary);

        const body = document.createElement('div');
        body.className = 'copilot-agent-trace-body';

        traceData.steps.forEach(function (step) {
            const article = document.createElement('article');
            article.className = `copilot-agent-trace-step copilot-agent-trace-step-${step.status}`;

            const header = document.createElement('div');
            header.className = 'copilot-agent-trace-step-header';

            const name = document.createElement('h4');
            name.className = 'copilot-agent-trace-step-name';
            name.textContent = step.name;
            header.appendChild(name);

            const badge = document.createElement('span');
            badge.className = `copilot-agent-trace-badge copilot-agent-trace-badge-${step.status}`;
            badge.textContent = statusLabel(step.status);
            header.appendChild(badge);

            article.appendChild(header);

            const description = document.createElement('p');
            description.className = 'copilot-agent-trace-step-description';
            description.textContent = step.description;
            article.appendChild(description);

            if (Array.isArray(step.meta) && step.meta.length > 0) {
                const meta = document.createElement('div');
                meta.className = 'copilot-agent-trace-step-meta';
                step.meta.forEach(function (item) {
                    const chip = document.createElement('span');
                    chip.className = 'copilot-agent-trace-step-chip';
                    chip.textContent = item;
                    meta.appendChild(chip);
                });
                article.appendChild(meta);
            }

            body.appendChild(article);
        });

        details.appendChild(body);
        return details;
    }

    function buildObservabilityCard(message) {
        if (typeof document === 'undefined' || !message || message.role !== 'assistant' || message.isLoading) {
            return null;
        }

        const observability = message && message.meta && message.meta.observability && typeof message.meta.observability === 'object'
            ? message.meta.observability
            : null;
        if (!observability) {
            return null;
        }

        const details = document.createElement('details');
        details.className = 'copilot-agent-trace copilot-observability-trace';

        const summary = document.createElement('summary');
        summary.className = 'copilot-agent-trace-summary';

        const summaryText = document.createElement('div');
        summaryText.className = 'copilot-agent-trace-summary-text';

        const title = document.createElement('span');
        title.className = 'copilot-agent-trace-title';
        title.textContent = 'Observability';
        summaryText.appendChild(title);

        const flow = document.createElement('span');
        flow.className = 'copilot-agent-trace-flow';
        flow.textContent = 'PHI-safe encounter telemetry';
        summaryText.appendChild(flow);
        summary.appendChild(summaryText);

        const safetyStatus = observability.safety && observability.safety.safe_refusal
            ? STATUS.SAFE_REFUSAL
            : ((observability.extraction && observability.extraction.review_status === 'pending_clinician_review') ? STATUS.REVIEW_REQUIRED : STATUS.READY);
        const badge = document.createElement('span');
        badge.className = `copilot-agent-trace-badge copilot-agent-trace-badge-${safetyStatus}`;
        badge.textContent = statusLabel(safetyStatus);
        summary.appendChild(badge);
        details.appendChild(summary);

        const body = document.createElement('div');
        body.className = 'copilot-agent-trace-body';

        const sections = [];
        sections.push({
            title: 'Tool Sequence',
            items: Array.isArray(observability.tool_sequence)
                ? observability.tool_sequence.map(function (entry) {
                    const parts = [`#${entry.order}`, entry.step];
                    if (entry.tool) {
                        parts.push(entry.tool);
                    } else if (entry.decision) {
                        parts.push(entry.decision);
                    }
                    parts.push(`${entry.status} · ${entry.latency_ms} ms`);
                    if (Number(entry.retrieval_hits || 0) > 0) {
                        parts.push(`${entry.retrieval_hits} hits`);
                    }
                    return parts.join(' · ');
                })
                : []
        });

        const stepLatencyItems = [];
        const stepLatencies = observability.latency && observability.latency.steps && typeof observability.latency.steps === 'object'
            ? observability.latency.steps
            : {};
        Object.entries(stepLatencies).forEach(function ([key, value]) {
            if (!Number.isFinite(Number(value))) {
                return;
            }
            stepLatencyItems.push(`${key.replace(/_/g, ' ')}: ${Number(value)} ms`);
        });
        sections.push({
            title: 'Latency',
            items: unique([
                observability.latency && Number.isFinite(Number(observability.latency.total_ms))
                    ? `total: ${Number(observability.latency.total_ms)} ms`
                    : '',
                stepLatencyItems.join(' | ')
            ]).filter(Boolean)
        });

        const retrieval = observability.retrieval && typeof observability.retrieval === 'object' ? observability.retrieval : {};
        sections.push({
            title: 'Retrieval',
            items: unique([
                `mode: ${retrieval.retrieval_mode || 'none'}`,
                `reranker: ${retrieval.rerank_provider || 'none'}`,
                `hits: ${Number(retrieval.hit_count || 0)}`,
                `evidence snippets: ${Number(retrieval.final_evidence_count || 0)}`,
                `citations: ${Number(retrieval.citation_count || 0)}`,
                Array.isArray(retrieval.top_source_types) && retrieval.top_source_types.length > 0
                    ? `source types: ${retrieval.top_source_types.join(', ')}`
                    : ''
            ]).filter(Boolean)
        });

        const extraction = observability.extraction && typeof observability.extraction === 'object' ? observability.extraction : {};
        if (extraction.doc_type && extraction.doc_type !== 'none') {
            sections.push({
                title: 'Extraction',
                items: unique([
                    `doc type: ${extraction.doc_type}`,
                    `status: ${extraction.extraction_status || 'none'}`,
                    extraction.confidence !== null && extraction.confidence !== undefined ? `confidence: ${extraction.confidence}` : '',
                    extraction.schema_valid !== null && extraction.schema_valid !== undefined ? `schema valid: ${extraction.schema_valid ? 'yes' : 'no'}` : '',
                    extraction.citation_contract_valid !== null && extraction.citation_contract_valid !== undefined ? `citation contract: ${extraction.citation_contract_valid ? 'passed' : 'review required'}` : '',
                    extraction.review_status ? `review status: ${extraction.review_status}` : '',
                    `facts: ${Number(extraction.extracted_fact_count || 0)}`,
                    `missing data: ${Number(extraction.missing_data_count || 0)}`
                ]).filter(Boolean)
            });
        }

        const tokenUsage = observability.token_usage && typeof observability.token_usage === 'object' ? observability.token_usage : {};
        sections.push({
            title: 'Tokens / Cost',
            items: unique([
                tokenUsage.model ? `model: ${tokenUsage.model}` : '',
                tokenUsage.provider ? `provider: ${tokenUsage.provider}` : '',
                `tokens: ${tokenUsage.prompt_tokens ?? '-'} / ${tokenUsage.completion_tokens ?? '-'} / ${tokenUsage.total_tokens ?? '-'}`,
                `token usage estimated: ${tokenUsage.token_usage_estimated ? 'yes' : 'no'}`,
                observability.estimated_cost_usd !== null && observability.estimated_cost_usd !== undefined
                    ? `estimated cost: $${observability.estimated_cost_usd}`
                    : (observability.cost_note ? `cost note: ${observability.cost_note}` : 'estimated cost: unavailable')
            ]).filter(Boolean)
        });

        const evalData = observability.eval && typeof observability.eval === 'object' ? observability.eval : {};
        if (evalData.case_id || evalData.passed !== null) {
            sections.push({
                title: 'Eval',
                items: unique([
                    evalData.case_id ? `case: ${evalData.case_id}` : '',
                    evalData.passed !== null ? `passed: ${evalData.passed ? 'yes' : 'no'}` : '',
                    Array.isArray(evalData.rubric_failures) && evalData.rubric_failures.length > 0
                        ? `rubric failures: ${evalData.rubric_failures.join(', ')}`
                        : '',
                    evalData.phi_log_check_passed !== null && evalData.phi_log_check_passed !== undefined
                        ? `phi log check: ${evalData.phi_log_check_passed ? 'passed' : 'failed'}`
                        : '',
                    evalData.regression_gate_status ? `gate: ${evalData.regression_gate_status}` : ''
                ]).filter(Boolean)
            });
        }

        const safety = observability.safety && typeof observability.safety === 'object' ? observability.safety : {};
        sections.push({
            title: 'Redaction / Safety',
            items: unique([
                `phi redacted: ${safety.phi_redacted === false ? 'no' : 'yes'}`,
                `safe refusal: ${safety.safe_refusal ? 'yes' : 'no'}`,
                `raw document text logged: ${safety.raw_document_text_logged ? 'yes' : 'no'}`,
                `raw screenshot logged: ${safety.raw_screenshot_logged ? 'yes' : 'no'}`,
                safety.blocked_reason ? `blocked reason: ${safety.blocked_reason}` : ''
            ]).filter(Boolean)
        });

        sections.forEach(function (section) {
            if (!Array.isArray(section.items) || section.items.length === 0) {
                return;
            }

            const article = document.createElement('article');
            article.className = 'copilot-agent-trace-step';

            const header = document.createElement('div');
            header.className = 'copilot-agent-trace-step-header';

            const name = document.createElement('h4');
            name.className = 'copilot-agent-trace-step-name';
            name.textContent = section.title;
            header.appendChild(name);
            article.appendChild(header);

            const meta = document.createElement('div');
            meta.className = 'copilot-agent-trace-step-meta';
            section.items.forEach(function (item) {
                const chip = document.createElement('span');
                chip.className = 'copilot-agent-trace-step-chip';
                chip.textContent = item;
                meta.appendChild(chip);
            });
            article.appendChild(meta);
            body.appendChild(article);
        });

        details.appendChild(body);
        return details;
    }

    function emitWorkflowObservability(traceData, telemetry, context) {
        if (!traceData || !telemetry || typeof telemetry.log !== 'function') {
            return;
        }

        const options = context && typeof context === 'object' ? context : {};
        const basePayload = {
            requestId: options.requestId || null,
            responseId: options.responseId || null,
            role: options.role || null,
            mode: options.mode || null,
            patientContextPresent: Boolean(options.patientContextPresent),
            patientContextHash: options.patientContextHash || null,
            patientIdentifierRedacted: true
        };
        const findStep = function (stepId) {
            return (traceData.steps || []).find(function (step) {
                return step.id === stepId;
            }) || null;
        };
        const supervisor = findStep('supervisor');
        const intake = findStep('intake_extractor');
        const evidence = findStep('evidence_retriever');
        const finalResponse = findStep('final_response');
        const decisions = Array.isArray(options.supervisorDecisions) ? options.supervisorDecisions : [];
        const handoffs = Array.isArray(options.workerHandoffs) ? options.workerHandoffs : [];

        decisions.forEach(function (decision) {
            telemetry.log('supervisor_decision_logged', {
                ...basePayload,
                decisionType: decision.decision || '',
                nextWorker: decision.next_worker || '',
                safeLog: true,
                decisionCount: decisions.length
            });
        });

        handoffs.forEach(function (handoff) {
            const summary = handoff && handoff.payload_summary ? handoff.payload_summary : {};
            telemetry.log('worker_handoff_logged', {
                ...basePayload,
                fromWorker: handoff.from || 'Supervisor',
                toWorker: handoff.to || '',
                docType: summary.doc_type || 'unknown',
                hasAttachedFile: Boolean(summary.has_attached_file),
                needsExtraction: Boolean(summary.needs_extraction),
                needsEvidenceRetrieval: Boolean(summary.needs_evidence_retrieval),
                handoffCount: handoffs.length,
                safeLog: true
            });
        });

        [intake, evidence].forEach(function (step) {
            if (!step || step.status === STATUS.PENDING || step.status === STATUS.SKIPPED) {
                return;
            }
            telemetry.log('worker_completed', {
                ...basePayload,
                worker: step.name,
                status: step.status,
                safeLog: true
            });
        });

        if (finalResponse && finalResponse.status === STATUS.SAFE_REFUSAL) {
            telemetry.log('safe_refusal', {
                ...basePayload,
                worker: 'FinalResponse',
                blockedReason: options.blockedReason || null,
                safeLog: true
            });
            return;
        }

        telemetry.log('final_answer_ready', {
            ...basePayload,
            worker: 'FinalResponse',
            status: finalResponse ? finalResponse.status : STATUS.READY,
            safeLog: true
        });
    }

    function emitEncounterObservability(observability, telemetry, context) {
        if (!observability || !telemetry || typeof telemetry.log !== 'function') {
            return;
        }

        const events = Array.isArray(observability.events) ? observability.events : [];
        const options = context && typeof context === 'object' ? context : {};
        events.forEach(function (event) {
            telemetry.log(event.event_name || 'copilot_step_completed', {
                requestId: event.request_id || options.requestId || null,
                responseId: options.responseId || null,
                encounterId: event.encounter_id || null,
                sessionIdHash: event.session_id_hash || null,
                role: event.role || options.role || null,
                mode: event.mode || options.mode || null,
                stepName: event.step_name || '',
                toolName: event.tool_name || '',
                status: event.status || '',
                latencyMs: event.latency_ms || 0,
                promptTokens: event.token_usage?.prompt_tokens ?? null,
                completionTokens: event.token_usage?.completion_tokens ?? null,
                totalTokens: event.token_usage?.total_tokens ?? null,
                tokenUsageEstimated: Boolean(event.token_usage?.token_usage_estimated),
                estimatedCostUsd: event.estimated_cost_usd ?? null,
                retrievalHitCount: event.retrieval?.hit_count ?? 0,
                topK: event.retrieval?.top_k ?? 0,
                retrievalMode: event.retrieval?.retrieval_mode || 'none',
                rerankProvider: event.retrieval?.rerank_provider || 'none',
                sparseHitCount: event.retrieval?.sparse_hit_count ?? 0,
                denseHitCount: event.retrieval?.dense_hit_count ?? 0,
                hybridCandidateCount: event.retrieval?.hybrid_candidate_count ?? 0,
                rerankedHitCount: event.retrieval?.reranked_hit_count ?? 0,
                finalEvidenceCount: event.retrieval?.final_evidence_count ?? 0,
                topSourceTypes: Array.isArray(event.retrieval?.top_source_types) ? event.retrieval.top_source_types : [],
                citationCount: event.retrieval?.citation_count ?? 0,
                docType: event.extraction?.doc_type || 'none',
                extractionStatus: event.extraction?.extraction_status || 'none',
                confidence: event.extraction?.confidence ?? null,
                schemaValid: event.extraction?.schema_valid ?? null,
                citationContractValid: event.extraction?.citation_contract_valid ?? null,
                reviewStatus: event.extraction?.review_status || null,
                extractedFactCount: event.extraction?.extracted_fact_count ?? 0,
                missingDataCount: event.extraction?.missing_data_count ?? 0,
                evalCaseId: event.eval?.case_id || null,
                evalPassed: event.eval?.passed ?? null,
                evalRubricFailures: Array.isArray(event.eval?.rubric_failures) ? event.eval.rubric_failures : [],
                regressionGateStatus: event.eval?.regression_gate_status || null,
                safeRefusal: Boolean(event.safety?.safe_refusal),
                blockedReason: event.safety?.blocked_reason || null,
                phiRedacted: event.safety?.phi_redacted !== false,
                rawDocumentTextLogged: Boolean(event.safety?.raw_document_text_logged),
                rawScreenshotLogged: Boolean(event.safety?.raw_screenshot_logged),
                screenshotCaptureAttempted: Boolean(event.safety?.screenshot_capture_attempted),
                screenshotBlockedReason: event.safety?.screenshot_blocked_reason || 'PHI_SAFE_DEFAULT',
                patientContextPresent: Boolean(event.patient_context_present),
                patientContextHash: event.patient_context_hash || null,
                patientIdentifierRedacted: event.patient_identifier_redacted !== false,
                safeLog: true
            });
        });
    }

    return {
        STATUS: STATUS,
        buildObservabilityCard: buildObservabilityCard,
        buildTraceCard: buildTraceCard,
        buildVisibleWorkflowTrace: buildVisibleWorkflowTrace,
        createTraceCollector: createTraceCollector,
        createTraceStep: createTraceStep,
        emitEncounterObservability: emitEncounterObservability,
        emitWorkflowObservability: emitWorkflowObservability,
        emitTraceStep: emitTraceStep,
        logTrace: logTrace
    };
}));
