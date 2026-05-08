#!/usr/bin/env node
'use strict';

const assert = require('assert');
const observability = require('./copilot_observability.js');

const result = observability.buildEncounterObservability({
    request_id: 'req_obs_001',
    role: 'doctor',
    mode: 'treatment_plan',
    selectedPatientKey: 'DEMO-PCP-1001',
    tool_sequence: [
        { step: 'Supervisor', decision: 'route_to_both', status: 'completed', latency_ms: 12 },
        { step: 'IntakeExtractorWorker', tool: 'attach_and_extract', status: 'review_required', latency_ms: 220 },
        { step: 'EvidenceRetrieverWorker', tool: 'retrieve_guideline_evidence', status: 'completed', latency_ms: 88, retrieval_hits: 5 }
    ],
    latency_steps: {
        supervisor_decision_ms: 12,
        attach_and_extract_ms: 220,
        retrieve_guideline_evidence_ms: 88,
        draft_grounded_answer_ms: 510,
        validate_citations_ms: 44,
        final_response_ms: 10
    },
    total_ms: 884,
    token_usage: {
        prompt_tokens: 1000,
        completion_tokens: 500,
        total_tokens: 1500
    },
    model: 'gpt-4.1-mini',
    provider: 'openai',
    retrieval: {
        hit_count: 5,
        top_k: 5,
        retrieval_mode: 'hybrid',
        rerank_provider: 'fallback_score_sort',
        sparse_hit_count: 3,
        dense_hit_count: 2,
        hybrid_candidate_count: 5,
        reranked_hit_count: 5,
        final_evidence_count: 4,
        top_source_types: ['lab_pdf', 'demo_guideline'],
        citation_count: 4
    },
    extraction: {
        doc_type: 'lab_pdf',
        extraction_status: 'review_required',
        confidence: 0.92,
        schema_valid: true,
        citation_contract_valid: true,
        review_status: 'pending_clinician_review',
        extracted_fact_count: 4,
        missing_data_count: 1
    },
    eval: {
        case_id: 'lab_pdf_valid_extraction',
        passed: true,
        rubric_failures: [],
        phi_log_check_passed: true,
        regression_gate_status: 'not_applicable'
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
});

assert.ok(Array.isArray(result.tool_sequence));
assert.strictEqual(result.tool_sequence.length, 3);
assert.strictEqual(result.latency.total_ms, 884);
assert.strictEqual(result.retrieval.retrieval_mode, 'hybrid');
assert.strictEqual(result.extraction.doc_type, 'lab_pdf');
assert.strictEqual(result.safety.phi_redacted, true);
assert.ok(Array.isArray(result.events));
assert.ok(result.events.length >= 4);
assert.ok(result.estimated_cost_usd > 0);
assert.ok(!JSON.stringify(result).includes('DEMO-PCP-1001'));

const summary = observability.summarizeObservabilityEvents(result.events);
assert.ok(summary.latency_ms.p50 >= 0);
assert.ok(summary.latency_ms.p95 >= 0);
assert.ok(Array.isArray(summary.bottlenecks));
assert.strictEqual(summary.encounter_count, 1);

const mixedSummary = observability.summarizeObservabilityEvents(result.events.concat([
    observability.buildObservabilityEvent({
        event_name: 'eval_case_completed',
        request_id: 'eval_case_001',
        encounter_id: 'eval_case_001',
        role: 'doctor',
        mode: 'eval_runner',
        step_name: 'EvalRunner',
        tool_name: 'golden_case_grader',
        status: 'completed',
        latency_ms: 1,
        eval: {
            case_id: 'case_001',
            passed: true,
            rubric_failures: [],
            phi_log_check_passed: true
        },
        safety: {
            phi_redacted: true,
            raw_document_text_logged: false,
            raw_screenshot_logged: false
        }
    })
]));
assert.strictEqual(mixedSummary.encounter_count, 1);
assert.strictEqual(mixedSummary.latency_ms.p50, 884);
assert.strictEqual(mixedSummary.eval.case_count, 1);

console.log('copilot_observability.test.js: 2 tests passed');
