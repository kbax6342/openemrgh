(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        const redaction = require('./redact_phi.js');
        const fs = require('fs');
        const path = require('path');
        module.exports = factory(redaction, fs, path, null);
        return;
    }

    root.OpenEMRCopilotObservability = factory(
        root.OpenEMRCopilotRedaction || {},
        null,
        null,
        root
    );
}(typeof globalThis !== 'undefined' ? globalThis : this, function (redactionModule, fs, path, root) {
    'use strict';

    const redaction = redactionModule && typeof redactionModule === 'object' ? redactionModule : {};
    const hashIdentifier = typeof redaction.hashIdentifier === 'function'
        ? redaction.hashIdentifier
        : function fallbackHashIdentifier(value) {
            const input = String(value || '').trim();
            if (!input) {
                return null;
            }
            let hash = 2166136261;
            for (let index = 0; index < input.length; index += 1) {
                hash ^= input.charCodeAt(index);
                hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
            }
            return `h_${(hash >>> 0).toString(16).padStart(8, '0')}`;
        };

    const sanitizeTelemetryPayload = typeof redaction.sanitizeTelemetryPayload === 'function'
        ? redaction.sanitizeTelemetryPayload
        : function fallbackSanitizeTelemetryPayload(payload) {
            return payload && typeof payload === 'object' ? { ...payload } : {};
        };

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function numberOrNull(value) {
        return Number.isFinite(Number(value)) ? Number(value) : null;
    }

    function stringValue(value) {
        return value === undefined || value === null ? '' : String(value);
    }

    function loadModelCostConfig() {
        if (root && root.OPENEMR_AI_COPILOT_MODEL_COST_CONFIG && typeof root.OPENEMR_AI_COPILOT_MODEL_COST_CONFIG === 'object') {
            return clone(root.OPENEMR_AI_COPILOT_MODEL_COST_CONFIG);
        }

        if (fs && path) {
            const configPath = path.join(__dirname, 'model_cost_config.json');
            try {
                return JSON.parse(fs.readFileSync(configPath, 'utf8'));
            } catch (error) {
                return {
                    default_currency: 'USD',
                    models: {
                        local_demo_fallback: {
                            input_per_1m_tokens_usd: 0,
                            output_per_1m_tokens_usd: 0
                        }
                    }
                };
            }
        }

        return {
            default_currency: 'USD',
            models: {
                local_demo_fallback: {
                    input_per_1m_tokens_usd: 0,
                    output_per_1m_tokens_usd: 0
                }
            }
        };
    }

    function normalizeTokenUsage(usage) {
        const promptTokens = numberOrNull(usage && usage.prompt_tokens);
        const completionTokens = numberOrNull(usage && usage.completion_tokens);
        const totalTokens = numberOrNull(usage && usage.total_tokens) !== null
            ? numberOrNull(usage && usage.total_tokens)
            : (promptTokens !== null || completionTokens !== null)
                ? (promptTokens || 0) + (completionTokens || 0)
                : null;

        return {
            prompt_tokens: promptTokens,
            completion_tokens: completionTokens,
            total_tokens: totalTokens,
            token_usage_estimated: !(promptTokens !== null || completionTokens !== null || totalTokens !== null)
        };
    }

    function estimateCost(options) {
        const settings = options && typeof options === 'object' ? options : {};
        const explicit = numberOrNull(settings.explicitCostUsd);
        if (explicit !== null) {
            return {
                estimated_cost_usd: Number(explicit.toFixed(6)),
                cost_note: null
            };
        }

        const tokenUsage = normalizeTokenUsage(settings.tokenUsage || {});
        const model = stringValue(settings.model).trim();
        const provider = stringValue(settings.provider).trim().toLowerCase();
        const config = loadModelCostConfig();
        const modelConfig = config.models[model]
            || config.models[(provider === 'guardrail' || provider === 'local_fallback') ? 'local_demo_fallback' : 'gpt-4.1-mini']
            || config.models.local_demo_fallback;

        if (tokenUsage.total_tokens === null && tokenUsage.prompt_tokens === null && tokenUsage.completion_tokens === null) {
            return {
                estimated_cost_usd: null,
                cost_note: 'Token usage unavailable. Cost estimate not configured for this response.'
            };
        }

        const promptTokens = tokenUsage.prompt_tokens || 0;
        const completionTokens = tokenUsage.completion_tokens || Math.max(0, (tokenUsage.total_tokens || 0) - promptTokens);
        const inputRate = numberOrNull(modelConfig && modelConfig.input_per_1m_tokens_usd) || 0;
        const outputRate = numberOrNull(modelConfig && modelConfig.output_per_1m_tokens_usd) || 0;
        const estimate = ((promptTokens / 1000000) * inputRate) + ((completionTokens / 1000000) * outputRate);

        return {
            estimated_cost_usd: Number(estimate.toFixed(6)),
            cost_note: null
        };
    }

    function safePatientContext(value) {
        const present = Boolean(value);
        return {
            patient_context_present: present,
            patient_context_hash: present ? hashIdentifier(value) : null,
            patient_identifier_redacted: true
        };
    }

    function buildToolSequenceEntry(entry, order) {
        const input = entry && typeof entry === 'object' ? entry : {};
        return {
            order: Number.isFinite(order) ? order : (Number(input.order) || 0),
            step: stringValue(input.step).trim() || 'Supervisor',
            tool: stringValue(input.tool).trim() || '',
            decision: stringValue(input.decision).trim() || '',
            status: stringValue(input.status).trim() || 'completed',
            latency_ms: Math.max(0, Math.round(numberOrNull(input.latency_ms ?? input.latencyMs) || 0)),
            retrieval_hits: Math.max(0, Math.round(numberOrNull(input.retrieval_hits ?? input.retrievalHits) || 0))
        };
    }

    function buildObservabilityEvent(event) {
        const input = event && typeof event === 'object' ? event : {};
        const tokenUsage = normalizeTokenUsage(input.token_usage || input.tokenUsage || {});
        const patientContext = safePatientContext(input.patient_context_hash ? `hashed:${input.patient_context_hash}` : (input.patientContextValue || ''));
        return {
            event_name: stringValue(input.event_name || input.eventName).trim() || 'copilot_step_completed',
            request_id: stringValue(input.request_id || input.requestId).trim() || null,
            encounter_id: stringValue(input.encounter_id || input.encounterId || input.request_id || input.requestId).trim() || null,
            session_id_hash: stringValue(input.session_id_hash || input.sessionIdHash).trim() || null,
            role: stringValue(input.role).trim() || null,
            mode: stringValue(input.mode).trim() || null,
            step_name: stringValue(input.step_name || input.stepName).trim() || 'FinalResponse',
            tool_name: stringValue(input.tool_name || input.toolName).trim() || '',
            status: stringValue(input.status).trim() || 'completed',
            latency_ms: Math.max(0, Math.round(numberOrNull(input.latency_ms ?? input.latencyMs) || 0)),
            token_usage: tokenUsage,
            estimated_cost_usd: numberOrNull(input.estimated_cost_usd ?? input.estimatedCostUsd),
            retrieval: {
                hit_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.hit_count) || 0)),
                top_k: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.top_k) || 0)),
                retrieval_mode: stringValue(input.retrieval && input.retrieval.retrieval_mode).trim() || 'none',
                rerank_provider: stringValue(input.retrieval && input.retrieval.rerank_provider).trim() || 'none',
                sparse_hit_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.sparse_hit_count) || 0)),
                dense_hit_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.dense_hit_count) || 0)),
                hybrid_candidate_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.hybrid_candidate_count) || 0)),
                reranked_hit_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.reranked_hit_count) || 0)),
                final_evidence_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.final_evidence_count) || 0)),
                top_source_types: unique(input.retrieval && input.retrieval.top_source_types),
                citation_count: Math.max(0, Math.round(numberOrNull(input.retrieval && input.retrieval.citation_count) || 0))
            },
            extraction: {
                doc_type: stringValue(input.extraction && input.extraction.doc_type).trim() || 'none',
                extraction_status: stringValue(input.extraction && input.extraction.extraction_status).trim() || 'none',
                confidence: numberOrNull(input.extraction && input.extraction.confidence),
                schema_valid: input.extraction && input.extraction.schema_valid !== undefined ? Boolean(input.extraction.schema_valid) : null,
                citation_contract_valid: input.extraction && input.extraction.citation_contract_valid !== undefined ? Boolean(input.extraction.citation_contract_valid) : null,
                review_status: stringValue(input.extraction && input.extraction.review_status).trim() || null,
                extracted_fact_count: Math.max(0, Math.round(numberOrNull(input.extraction && input.extraction.extracted_fact_count) || 0)),
                missing_data_count: Math.max(0, Math.round(numberOrNull(input.extraction && input.extraction.missing_data_count) || 0))
            },
            eval: {
                case_id: stringValue(input.eval && input.eval.case_id).trim() || null,
                passed: input.eval && input.eval.passed !== undefined ? Boolean(input.eval.passed) : null,
                rubric_failures: unique(input.eval && input.eval.rubric_failures),
                phi_log_check_passed: input.eval && input.eval.phi_log_check_passed !== undefined ? Boolean(input.eval.phi_log_check_passed) : null,
                regression_gate_status: stringValue(input.eval && input.eval.regression_gate_status).trim() || null
            },
            safety: {
                safe_refusal: Boolean(input.safety && input.safety.safe_refusal),
                blocked_reason: stringValue(input.safety && input.safety.blocked_reason).trim() || null,
                phi_redacted: input.safety && input.safety.phi_redacted !== undefined ? Boolean(input.safety.phi_redacted) : true,
                raw_document_text_logged: Boolean(input.safety && input.safety.raw_document_text_logged),
                raw_screenshot_logged: Boolean(input.safety && input.safety.raw_screenshot_logged),
                screenshot_capture_attempted: Boolean(input.safety && input.safety.screenshot_capture_attempted),
                screenshot_blocked_reason: stringValue(input.safety && input.safety.screenshot_blocked_reason).trim() || 'PHI_SAFE_DEFAULT'
            },
            patient_context_present: input.patient_context_present !== undefined
                ? Boolean(input.patient_context_present)
                : patientContext.patient_context_present,
            patient_context_hash: stringValue(input.patient_context_hash).trim() || patientContext.patient_context_hash,
            patient_identifier_redacted: input.patient_identifier_redacted !== undefined
                ? Boolean(input.patient_identifier_redacted)
                : true,
            timestamp: stringValue(input.timestamp).trim() || new Date().toISOString()
        };
    }

    function buildEncounterObservability(input) {
        const settings = input && typeof input === 'object' ? input : {};
        const patientContext = safePatientContext(
            settings.selectedPatientKey
            || settings.patientKey
            || settings.patientId
            || settings.patient_id
            || ''
        );
        const tokenUsage = normalizeTokenUsage(settings.token_usage || settings.tokenUsage || {});
        const cost = estimateCost({
            explicitCostUsd: settings.estimated_cost_usd ?? settings.estimatedCostUsd,
            tokenUsage: tokenUsage,
            model: settings.model,
            provider: settings.provider
        });
        const retrieval = settings.retrieval && typeof settings.retrieval === 'object' ? settings.retrieval : {};
        const extraction = settings.extraction && typeof settings.extraction === 'object' ? settings.extraction : {};
        const safety = settings.safety && typeof settings.safety === 'object' ? settings.safety : {};
        const evalResult = settings.eval && typeof settings.eval === 'object' ? settings.eval : {};
        const rawSequence = Array.isArray(settings.tool_sequence || settings.toolSequence) ? (settings.tool_sequence || settings.toolSequence) : [];
        const toolSequence = rawSequence.map(function (entry, index) {
            return buildToolSequenceEntry(entry, index + 1);
        });
        const encounterId = stringValue(settings.encounter_id || settings.encounterId || settings.request_id || settings.requestId).trim() || null;
        const latencySteps = settings.latency && settings.latency.steps
            ? settings.latency.steps
            : (settings.latency_steps || settings.latencySteps || {});
        const totalLatencyMs = Math.max(
            0,
            Math.round(
                numberOrNull(
                    settings.total_ms
                    ?? settings.totalLatencyMs
                    ?? (settings.latency && settings.latency.total_ms)
                ) || 0
            )
        );

        const events = toolSequence.map(function (entry) {
            return buildObservabilityEvent({
                event_name: 'copilot_step_completed',
                request_id: settings.request_id || settings.requestId || null,
                encounter_id: encounterId,
                session_id_hash: settings.session_id_hash || settings.sessionIdHash || null,
                role: settings.role || null,
                mode: settings.mode || null,
                step_name: entry.step,
                tool_name: entry.tool,
                status: entry.status,
                latency_ms: entry.latency_ms,
                token_usage: tokenUsage,
                estimated_cost_usd: cost.estimated_cost_usd,
                retrieval: {
                    hit_count: entry.retrieval_hits || 0,
                    top_k: retrieval.top_k || 0,
                    retrieval_mode: retrieval.retrieval_mode || 'none',
                    rerank_provider: retrieval.rerank_provider || 'none',
                    sparse_hit_count: retrieval.sparse_hit_count || 0,
                    dense_hit_count: retrieval.dense_hit_count || 0,
                    hybrid_candidate_count: retrieval.hybrid_candidate_count || 0,
                    reranked_hit_count: retrieval.reranked_hit_count || 0,
                    final_evidence_count: retrieval.final_evidence_count || 0,
                    top_source_types: retrieval.top_source_types || [],
                    citation_count: retrieval.citation_count || 0
                },
                extraction: extraction,
                eval: evalResult,
                safety: safety,
                patient_context_present: patientContext.patient_context_present,
                patient_context_hash: patientContext.patient_context_hash,
                patient_identifier_redacted: true
            });
        });

        events.push(buildObservabilityEvent({
            event_name: 'copilot_encounter_completed',
            request_id: settings.request_id || settings.requestId || null,
            encounter_id: encounterId,
            session_id_hash: settings.session_id_hash || settings.sessionIdHash || null,
            role: settings.role || null,
            mode: settings.mode || null,
            step_name: 'FinalResponse',
            tool_name: 'final_response',
            status: safety.safe_refusal ? 'blocked' : (extraction.review_status === 'pending_clinician_review' ? 'review_required' : 'completed'),
            latency_ms: totalLatencyMs,
            token_usage: tokenUsage,
            estimated_cost_usd: cost.estimated_cost_usd,
            retrieval: retrieval,
            extraction: extraction,
            eval: evalResult,
            safety: safety,
            patient_context_present: patientContext.patient_context_present,
            patient_context_hash: patientContext.patient_context_hash,
            patient_identifier_redacted: true
        }));

        return {
            request_id: stringValue(settings.request_id || settings.requestId).trim() || null,
            encounter_id: encounterId,
            session_id_hash: stringValue(settings.session_id_hash || settings.sessionIdHash).trim() || null,
            patient_context_present: patientContext.patient_context_present,
            patient_context_hash: patientContext.patient_context_hash,
            patient_identifier_redacted: true,
            tool_sequence: toolSequence,
            latency: {
                total_ms: totalLatencyMs,
                steps: clone(latencySteps || {})
            },
            token_usage: {
                ...tokenUsage,
                model: stringValue(settings.model).trim() || null,
                provider: stringValue(settings.provider).trim() || null
            },
            estimated_cost_usd: cost.estimated_cost_usd,
            cost_note: cost.cost_note,
            retrieval: {
                hit_count: Math.max(0, Math.round(numberOrNull(retrieval.hit_count) || 0)),
                top_k: Math.max(0, Math.round(numberOrNull(retrieval.top_k) || 0)),
                retrieval_mode: stringValue(retrieval.retrieval_mode).trim() || 'none',
                rerank_provider: stringValue(retrieval.rerank_provider).trim() || 'none',
                sparse_hit_count: Math.max(0, Math.round(numberOrNull(retrieval.sparse_hit_count) || 0)),
                dense_hit_count: Math.max(0, Math.round(numberOrNull(retrieval.dense_hit_count) || 0)),
                hybrid_candidate_count: Math.max(0, Math.round(numberOrNull(retrieval.hybrid_candidate_count) || 0)),
                reranked_hit_count: Math.max(0, Math.round(numberOrNull(retrieval.reranked_hit_count) || 0)),
                final_evidence_count: Math.max(0, Math.round(numberOrNull(retrieval.final_evidence_count) || 0)),
                top_source_types: unique(retrieval.top_source_types),
                citation_count: Math.max(0, Math.round(numberOrNull(retrieval.citation_count) || 0))
            },
            extraction: {
                doc_type: stringValue(extraction.doc_type).trim() || 'none',
                extraction_status: stringValue(extraction.extraction_status).trim() || 'none',
                confidence: numberOrNull(extraction.confidence),
                schema_valid: extraction.schema_valid !== undefined ? Boolean(extraction.schema_valid) : null,
                citation_contract_valid: extraction.citation_contract_valid !== undefined ? Boolean(extraction.citation_contract_valid) : null,
                review_status: stringValue(extraction.review_status).trim() || null,
                extracted_fact_count: Math.max(0, Math.round(numberOrNull(extraction.extracted_fact_count) || 0)),
                missing_data_count: Math.max(0, Math.round(numberOrNull(extraction.missing_data_count) || 0))
            },
            eval: {
                case_id: stringValue(evalResult.case_id).trim() || null,
                passed: evalResult.passed !== undefined ? Boolean(evalResult.passed) : null,
                rubric_failures: unique(evalResult.rubric_failures),
                phi_log_check_passed: evalResult.phi_log_check_passed !== undefined ? Boolean(evalResult.phi_log_check_passed) : null,
                regression_gate_status: stringValue(evalResult.regression_gate_status).trim() || null
            },
            safety: {
                safe_refusal: Boolean(safety.safe_refusal),
                blocked_reason: stringValue(safety.blocked_reason).trim() || null,
                phi_redacted: safety.phi_redacted !== undefined ? Boolean(safety.phi_redacted) : true,
                raw_document_text_logged: Boolean(safety.raw_document_text_logged),
                raw_screenshot_logged: Boolean(safety.raw_screenshot_logged),
                screenshot_capture_attempted: Boolean(safety.screenshot_capture_attempted),
                screenshot_blocked_reason: stringValue(safety.screenshot_blocked_reason).trim() || 'PHI_SAFE_DEFAULT'
            },
            events: events
        };
    }

    function percentile(values, ratio) {
        const sorted = (values || []).filter(function (value) {
            return Number.isFinite(Number(value));
        }).map(Number).sort(function (left, right) {
            return left - right;
        });
        if (sorted.length === 0) {
            return 0;
        }
        if (sorted.length === 1) {
            return sorted[0];
        }

        const index = Math.min(sorted.length - 1, Math.max(0, Math.ceil(ratio * sorted.length) - 1));
        return sorted[index];
    }

    function summarizeObservabilityEvents(events) {
        const allEvents = Array.isArray(events) ? events.map(buildObservabilityEvent) : [];
        const copilotEncounterEvents = allEvents.filter(function (event) {
            return event.event_name === 'copilot_encounter_completed';
        });
        const evalEncounterEvents = allEvents.filter(function (event) {
            return event.event_name === 'eval_case_completed';
        });
        const encounterEvents = copilotEncounterEvents.length > 0
            ? copilotEncounterEvents
            : evalEncounterEvents;
        const stepEvents = allEvents.filter(function (event) {
            return event.event_name === 'copilot_step_completed';
        });
        const latencyValues = encounterEvents.map(function (event) {
            return event.latency_ms;
        }).filter(function (value) {
            return Number.isFinite(Number(value)) && Number(value) > 0;
        }).map(Number);
        const costValues = encounterEvents.map(function (event) {
            return numberOrNull(event.estimated_cost_usd);
        }).filter(function (value) {
            return value !== null;
        });
        const tokenValues = encounterEvents.map(function (event) {
            return numberOrNull(event.token_usage && event.token_usage.total_tokens);
        }).filter(function (value) {
            return value !== null;
        });
        const stepSummary = {};

        stepEvents.forEach(function (event) {
            const stepName = event.step_name || 'Unknown';
            const current = stepSummary[stepName] || { count: 0, total_ms: 0, samples: [] };
            current.count += 1;
            current.total_ms += event.latency_ms || 0;
            current.samples.push(event.latency_ms || 0);
            stepSummary[stepName] = current;
        });

        const normalizedStepSummary = Object.fromEntries(Object.entries(stepSummary).map(function ([stepName, stats]) {
            const averageMs = stats.count > 0 ? Number((stats.total_ms / stats.count).toFixed(2)) : 0;
            return [stepName, {
                count: stats.count,
                average_ms: averageMs,
                p95_ms: percentile(stats.samples, 0.95)
            }];
        }));

        const bottlenecks = Object.entries(normalizedStepSummary)
            .sort(function (left, right) {
                return right[1].average_ms - left[1].average_ms;
            })
            .slice(0, 5)
            .map(function ([stepName, stats]) {
                return {
                    step_name: stepName,
                    average_ms: stats.average_ms,
                    p95_ms: stats.p95_ms
                };
            });

        const summary = {
            generated_at: new Date().toISOString(),
            event_count: allEvents.length,
            encounter_count: encounterEvents.length,
            latency_ms: {
                average: latencyValues.length > 0
                    ? Number((latencyValues.reduce(function (sum, value) {
                        return sum + value;
                    }, 0) / latencyValues.length).toFixed(2))
                    : 0,
                p50: percentile(latencyValues, 0.50),
                p95: percentile(latencyValues, 0.95)
            },
            step_latency_ms: normalizedStepSummary,
            bottlenecks: bottlenecks,
            costs: {
                average_request_cost_usd: costValues.length > 0
                    ? Number((costValues.reduce(function (sum, value) {
                        return sum + value;
                    }, 0) / costValues.length).toFixed(6))
                    : 0,
                total_estimated_cost_usd: costValues.length > 0
                    ? Number(costValues.reduce(function (sum, value) {
                        return sum + value;
                    }, 0).toFixed(6))
                    : 0,
                average_total_tokens: tokenValues.length > 0
                    ? Number((tokenValues.reduce(function (sum, value) {
                        return sum + value;
                    }, 0) / tokenValues.length).toFixed(2))
                    : 0
            },
            retrieval: {
                events_with_hits: encounterEvents.filter(function (event) {
                    return (event.retrieval && event.retrieval.hit_count) > 0;
                }).length,
                final_evidence_count: encounterEvents.reduce(function (sum, event) {
                    return sum + Number(event.retrieval && event.retrieval.final_evidence_count || 0);
                }, 0),
                top_source_types: unique(encounterEvents.flatMap(function (event) {
                    return Array.isArray(event.retrieval && event.retrieval.top_source_types)
                        ? event.retrieval.top_source_types
                        : [];
                }))
            },
            extraction: {
                doc_types: encounterEvents.reduce(function (accumulator, event) {
                    const docType = stringValue(event.extraction && event.extraction.doc_type).trim() || 'none';
                    accumulator[docType] = (accumulator[docType] || 0) + 1;
                    return accumulator;
                }, {}),
                statuses: encounterEvents.reduce(function (accumulator, event) {
                    const status = stringValue(event.extraction && event.extraction.extraction_status).trim() || 'none';
                    accumulator[status] = (accumulator[status] || 0) + 1;
                    return accumulator;
                }, {})
            },
            eval: {
                case_count: evalEncounterEvents.filter(function (event) {
                    return event.eval && event.eval.case_id;
                }).length,
                passed_count: evalEncounterEvents.filter(function (event) {
                    return event.eval && event.eval.passed === true;
                }).length,
                failed_count: evalEncounterEvents.filter(function (event) {
                    return event.eval && event.eval.passed === false;
                }).length,
                phi_log_check_passed_count: evalEncounterEvents.filter(function (event) {
                    return event.eval && event.eval.phi_log_check_passed === true;
                }).length
            },
            safety: {
                phi_redacted_event_count: encounterEvents.filter(function (event) {
                    return event.safety && event.safety.phi_redacted;
                }).length,
                raw_document_text_logged_count: encounterEvents.filter(function (event) {
                    return event.safety && event.safety.raw_document_text_logged;
                }).length,
                raw_screenshot_logged_count: encounterEvents.filter(function (event) {
                    return event.safety && event.safety.raw_screenshot_logged;
                }).length
            }
        };

        return summary;
    }

    function writeObservabilityResults(events, summary, options) {
        if (!fs || !path) {
            return null;
        }

        const settings = options && typeof options === 'object' ? options : {};
        const directory = path.isAbsolute(settings.directory || '')
            ? settings.directory
            : path.join(process.cwd(), settings.directory || 'interface/ai_copilot/observability/results');
        fs.mkdirSync(directory, { recursive: true });

        const eventsPath = path.join(directory, 'observability_events.latest.json');
        const summaryPath = path.join(directory, 'observability_summary.latest.json');
        fs.writeFileSync(eventsPath, JSON.stringify(Array.isArray(events) ? events : [], null, 2) + '\n', 'utf8');
        fs.writeFileSync(summaryPath, JSON.stringify(summary || summarizeObservabilityEvents(events || []), null, 2) + '\n', 'utf8');
        return { eventsPath, summaryPath };
    }

    return {
        buildEncounterObservability: buildEncounterObservability,
        buildObservabilityEvent: buildObservabilityEvent,
        buildToolSequenceEntry: buildToolSequenceEntry,
        estimateCost: estimateCost,
        hashIdentifier: hashIdentifier,
        loadModelCostConfig: loadModelCostConfig,
        normalizeTokenUsage: normalizeTokenUsage,
        sanitizeTelemetryPayload: sanitizeTelemetryPayload,
        summarizeObservabilityEvents: summarizeObservabilityEvents,
        writeObservabilityResults: writeObservabilityResults
    };
}));
