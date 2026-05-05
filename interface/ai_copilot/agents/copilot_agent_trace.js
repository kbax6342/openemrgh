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
        BLOCKED: 'blocked'
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
        if (normalized === STATUS.RUNNING || normalized === STATUS.COMPLETE || normalized === STATUS.BLOCKED) {
            return normalized;
        }

        return STATUS.PENDING;
    }

    function statusLabel(status) {
        const normalized = normalizeStatus(status);
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
        const safetyHelpers = getSafetyHelpers(settings.safetyModule);
        const rawTrace = Array.isArray(message && message.meta && message.meta.agent_trace)
            ? message.meta.agent_trace
            : [];
        const blockedReason = deriveBlockedReason(message);
        const promptInjectionBlocked = blockedReason === 'prompt_injection_block';
        const roleBlocked = Boolean(blockedReason);
        const chartNeeded = needsChartRetrieval(message, rawTrace);
        const hasChartEvents = getTraceEvents(rawTrace, 'Chart Retrieval Worker').length > 0;
        const hasSafetyEvents = getTraceEvents(rawTrace, 'Evidence + Safety Worker').length > 0;
        const chartStatus = message && message.isLoading
            ? STATUS.PENDING
            : promptInjectionBlocked
                ? STATUS.PENDING
                : chartNeeded
                    ? (hasChartEvents || (Array.isArray(message && message.sources) && message.sources.length > 0) ? STATUS.COMPLETE : STATUS.PENDING)
                    : STATUS.COMPLETE;
        const safetyStatus = message && message.isLoading
            ? STATUS.PENDING
            : promptInjectionBlocked
                ? STATUS.PENDING
                : roleBlocked && hasSafetyEvents
                    ? STATUS.BLOCKED
                    : hasSafetyEvents || message && message.meta
                        ? STATUS.COMPLETE
                        : STATUS.PENDING;
        const supervisorStatus = message && message.isLoading
            ? STATUS.RUNNING
            : roleBlocked
                ? STATUS.BLOCKED
                : STATUS.COMPLETE;
        const finalStatus = message && message.isLoading
            ? STATUS.PENDING
            : STATUS.COMPLETE;

        const steps = [
            {
                id: 'supervisor',
                name: 'Supervisor Agent',
                status: supervisorStatus,
                description: buildSupervisorDescription(message, rawTrace, blockedReason, safetyHelpers),
                meta: buildStepMeta('supervisor', message, rawTrace, toolModule, supervisorStatus)
            },
            {
                id: 'chart_retrieval',
                name: 'Chart Retrieval Worker',
                status: chartStatus,
                description: buildChartDescription(message, rawTrace, chartStatus, blockedReason, chartNeeded),
                meta: buildStepMeta('chart_retrieval', message, rawTrace, toolModule, chartStatus, { chartNeeded: chartNeeded })
            },
            {
                id: 'safety',
                name: 'Evidence + Safety Worker',
                status: safetyStatus,
                description: buildSafetyDescription(message, rawTrace, safetyStatus, blockedReason),
                meta: buildStepMeta('safety', message, rawTrace, toolModule, safetyStatus)
            },
            {
                id: 'final_draft',
                name: 'Final Draft',
                status: finalStatus,
                description: buildFinalDescription(message, blockedReason),
                meta: buildStepMeta('final_draft', message, rawTrace, toolModule, finalStatus)
            }
        ];

        return {
            title: 'Agent Workflow Trace',
            flow: 'Supervisor Agent \u2192 Chart Retrieval Worker \u2192 Evidence + Safety Worker \u2192 Final Draft',
            overallStatus: steps.some(function (step) {
                return step.status === STATUS.BLOCKED;
            }) ? STATUS.BLOCKED : (message && message.isLoading ? STATUS.RUNNING : STATUS.COMPLETE),
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
            selectedPatientKey: options.selectedPatientKey || null
        };
        const findStep = function (stepId) {
            return (traceData.steps || []).find(function (step) {
                return step.id === stepId;
            }) || null;
        };
        const supervisor = findStep('supervisor');
        const chart = findStep('chart_retrieval');
        const safety = findStep('safety');
        const finalDraft = findStep('final_draft');

        telemetry.log('copilot_agent_supervisor_started', {
            ...basePayload,
            status: supervisor ? supervisor.status : STATUS.PENDING
        });

        if (chart && chart.status !== STATUS.PENDING) {
            telemetry.log('copilot_worker_chart_retrieval_started', {
                ...basePayload,
                status: chart.status
            });
            telemetry.log('copilot_worker_chart_retrieval_completed', {
                ...basePayload,
                status: chart.status
            });
        }

        if (safety && safety.status !== STATUS.PENDING) {
            telemetry.log('copilot_worker_safety_validation_started', {
                ...basePayload,
                status: safety.status
            });
            telemetry.log('copilot_worker_safety_validation_completed', {
                ...basePayload,
                status: safety.status
            });
        }

        if (supervisor && supervisor.status === STATUS.BLOCKED) {
            telemetry.log('copilot_agent_request_blocked', {
                ...basePayload,
                status: supervisor.status,
                blockedReason: options.blockedReason || null
            });
        } else {
            telemetry.log('copilot_agent_supervisor_completed', {
                ...basePayload,
                status: supervisor ? supervisor.status : STATUS.COMPLETE
            });
        }

        telemetry.log('copilot_agent_final_response_ready', {
            ...basePayload,
            status: finalDraft ? finalDraft.status : STATUS.COMPLETE,
            overallStatus: traceData.overallStatus
        });
    }

    return {
        STATUS: STATUS,
        buildTraceCard: buildTraceCard,
        buildVisibleWorkflowTrace: buildVisibleWorkflowTrace,
        createTraceCollector: createTraceCollector,
        createTraceStep: createTraceStep,
        emitWorkflowObservability: emitWorkflowObservability,
        emitTraceStep: emitTraceStep,
        logTrace: logTrace
    };
}));
