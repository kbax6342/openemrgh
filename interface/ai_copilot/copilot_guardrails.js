(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotGuardrails = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const CLINICAL_DRAFT_NOTE = 'Draft only. Human review required. This does not replace clinical judgment or a final medical decision.';
    const BILLING_DRAFT_NOTE = 'Draft only. Human review required. This does not replace billing, compliance, or coder review.';
    const FRONT_DESK_NOTE = 'Administrative draft only. Human review required. Use minimum necessary PHI.';

    const ALL_MODES = [
        'general_assistant',
        'differential_diagnosis',
        'medication_info',
        'clinical_notes',
        'treatment_plan',
        'billing',
        'billing_review',
        'follow_up',
        'rag_chart_context',
        'lab_pdf_ingestion',
        'latest_ambient_summary',
        'visit_summary',
        'patient_education',
        'appointment_info',
        'patient_contact',
        'send_reminder',
        'front_desk_summary'
    ];

    const ROLE_ALLOWED_MODES = {
        doctor: new Set(ALL_MODES),
        nurse: new Set([
            'general_assistant',
            'medication_info',
            'clinical_notes',
            'follow_up',
            'latest_ambient_summary',
            'visit_summary',
            'patient_education',
            'appointment_info',
            'patient_contact',
            'front_desk_summary'
        ]),
        billing: new Set([
            'general_assistant',
            'billing',
            'billing_review',
            'latest_ambient_summary',
            'visit_summary',
            'appointment_info',
            'patient_contact',
            'front_desk_summary'
        ]),
        front_desk: new Set([
            'general_assistant',
            'appointment_info',
            'patient_contact',
            'send_reminder',
            'latest_ambient_summary',
            'front_desk_summary'
        ])
    };

    const PROMPT_INJECTION_PATTERNS = [
        /\bignore (all|any|previous|prior) instructions\b/i,
        /\bignore (your|the) rules\b/i,
        /\bbypass (role|guardrail|restriction|policy|safety)/i,
        /\bshow (me )?(the )?full chart\b/i,
        /\breveal (the )?(hidden|restricted|internal) (context|notes|data)\b/i,
        /\bact as (an )?admin\b/i,
        /\boverride (hipaa|role restrictions|privacy rules|safety rules)\b/i
    ];

    const TOPIC_PATTERNS = {
        lab_pdf_ingestion: /\b(lab pdf ingestion|lab pdf|attach.*pdf|upload.*pdf|ingest.*pdf|extract.*pdf|pdf lab result)\b/i,
        medication_info: /\b(medication|medications|meds|dose|dosage|prescription|prescriptions|interaction|counsel|refill|metformin|insulin|lisinopril|atorvastatin|albuterol|gabapentin)\b/i,
        differential_diagnosis: /\b(diagnose|diagnosis|differential|what could be causing|cause of|likely condition|red flag|do not miss)\b/i,
        clinical_notes: /\b(clinical note|soap|encounter note|documentation|note summary|chart summary|summarize the chart|subjective|objective|assessment|plan)\b/i,
        treatment_plan: /\b(treatment plan|plan for treatment|care plan|start treatment|stop treatment|change treatment|therap(y|ies)|dose change|prescribe)\b/i,
        follow_up: /\b(follow[- ]?up|monitor(ing)?|recheck|return visit|care coordination|next visit|escalation precaution)\b/i,
        billing: /\b(billing|claim|claim status|insurance|payer|payment|payment due|coverage|cpt|icd|coding|balance|invoice)\b/i,
        latest_ambient_summary: /\b(latest ambient encounter|ambient encounter only|latest ai-assisted visit review|latest approved ambient encounter)\b/i,
        appointment_info: /\b(appointment|schedule|scheduled|provider|location|check[- ]?in)\b/i,
        patient_contact: /\b(contact|phone|email|preferred outreach|reach the patient|contact information)\b/i,
        send_reminder: /\b(reminder|notify|notification|outreach message|send reminder)\b/i,
        patient_education: /\b(patient[- ]?friendly|patient education|counseling|home instructions)\b/i,
        visit_summary: /\b(visit summary|summary of visit)\b/i
    };

    const DEFINITIVE_DIAGNOSIS_PATTERN = /\b(definitive diagnosis|final diagnosis|i diagnose|the diagnosis is|this patient definitely has|this is clearly)\b/i;
    const MEDICATION_CHANGE_PATTERN = /\b(start|stop|discontinue|increase|decrease|raise|lower|double|halve|switch)\b[\s\S]{0,48}\b(medication|medications|dose|dosage|mg|tablet|capsule|insulin|metformin|lisinopril|atorvastatin|albuterol|gabapentin)\b/i;
    const URGENT_DIRECTIVE_PATTERN = /\b(call 911|go to the er|go to the emergency room|seek emergency care immediately|hospitalize immediately|admit immediately)\b/i;
    const ESCALATION_LANGUAGE_PATTERN = /\b(licensed clinician|supervising clinician|clinical protocol|emergency services|urgent evaluation|escalate)\b/i;
    const CLINICAL_DISCLOSURE_PATTERN = /\b(a1c|troponin|glucose|wbc|ldl|creatinine|medication|metformin|insulin|lisinopril|atorvastatin|albuterol|gabapentin|diagnosis|differential|treatment plan|clinical note|soap|lab|labs)\b/i;
    const DOCTOR_DIAGNOSIS_PROMPT_PATTERN = /\b(diagnose|final diagnosis|definitive diagnosis|certain diagnosis)\b/i;

    function normalizeRole(role) {
        return ROLE_ALLOWED_MODES[String(role || '').toLowerCase()] ? String(role || '').toLowerCase() : 'doctor';
    }

    function normalizeMode(mode) {
        return String(mode || 'general_assistant').toLowerCase();
    }

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function maxRisk(current, next) {
        const rank = { low: 1, medium: 2, high: 3 };
        return rank[next] > rank[current] ? next : current;
    }

    function responseToText(draftResponse, sections) {
        const parts = [];
        if (draftResponse) {
            parts.push(String(draftResponse));
        }

        (sections || []).forEach((section) => {
            if (!section || !Array.isArray(section.items) || !section.title) {
                return;
            }

            parts.push(section.title);
            section.items.forEach((item) => {
                if (item) {
                    parts.push(String(item));
                }
            });
        });

        return parts.join('\n').trim();
    }

    function inferTopic(mode, prompt) {
        const normalizedMode = normalizeMode(mode);
        if (normalizedMode && normalizedMode !== 'general_assistant') {
            return normalizedMode;
        }

        const text = String(prompt || '');
        for (const [topic, pattern] of Object.entries(TOPIC_PATTERNS)) {
            if (pattern.test(text)) {
                return topic;
            }
        }

        return 'general_assistant';
    }

    function containsPromptInjection(prompt) {
        const text = String(prompt || '');
        return PROMPT_INJECTION_PATTERNS.find((pattern) => pattern.test(text)) || null;
    }

    function buildBlockedMessage(role, blockedReason) {
        const messages = {
            prompt_injection_block: 'I can\'t bypass role restrictions or reveal hidden chart context. Please use a prompt that matches your selected role and approved workflow.',
            doctor_autonomous_diagnosis_block: 'I can support differential reasoning and draft clinical summaries, but I can\'t provide a definitive diagnosis. Ask for a differential diagnosis or chart summary for clinician review.',
            nurse_medication_change_block: 'I can help explain the current medication plan and flag items to review, but medication changes should be handled by the prescribing clinician.',
            nurse_clinical_scope_block: 'Diagnosis and prescribing guidance are restricted for the Nurse role. You can request care coordination, follow-up preparation, medication education, or patient education drafts.',
            billing_clinical_scope_block: 'Detailed clinical information is not available for the Billing Staff role. You can review claim status, insurance context, payment status, and billing workflow summaries.',
            front_desk_clinical_scope_block: 'Medication information is not available for the Front Desk role. You can view contact details, appointment information, and administrative outreach guidance.',
            front_desk_phi_limit: 'Clinical chart details are restricted for the Front Desk role. You can use appointment, contact, and reminder workflows with minimum necessary PHI only.',
            billing_phi_limit: 'This response includes more clinical detail than the Billing Staff role should receive. You can request claim status, insurance context, payment status, or billing workflow guidance instead.',
            medication_change_instruction: 'Medication start, stop, and dose-change instructions require licensed clinician review. I can summarize medication considerations, but I can\'t provide final medication change instructions.',
            autonomous_diagnosis_language: 'I can support differential reasoning, but I can\'t provide a definitive diagnosis. Please review the chart findings and confirm the assessment with a licensed clinician.'
        };

        if (messages[blockedReason]) {
            return messages[blockedReason];
        }

        if (role === 'front_desk') {
            return messages.front_desk_clinical_scope_block;
        }
        if (role === 'billing') {
            return messages.billing_clinical_scope_block;
        }
        if (role === 'nurse') {
            return messages.nurse_clinical_scope_block;
        }

        return 'This draft needs human review before it can be shown in this workflow.';
    }

    function defaultSafetyFor(role, topic, existingSafety) {
        const safetyText = String(existingSafety || '').trim();
        if (safetyText) {
            return safetyText;
        }

        if (role === 'front_desk') {
            return FRONT_DESK_NOTE;
        }
        if (role === 'billing' || topic === 'billing' || topic === 'billing_review') {
            return BILLING_DRAFT_NOTE;
        }

        return CLINICAL_DRAFT_NOTE;
    }

    function displayRoleLabel(role) {
        const labels = {
            doctor: 'Doctor',
            nurse: 'Nurse',
            billing: 'Billing Staff',
            front_desk: 'Front Desk'
        };

        return labels[role] || 'Doctor';
    }

    function displayReasonFor(blockedReason, role) {
        const reasons = {
            prompt_injection_block: 'This request attempted to override the demo safety rules.',
            doctor_autonomous_diagnosis_block: 'Definitive diagnosis language is restricted in this workflow.',
            nurse_medication_change_block: 'Medication change instructions are restricted for the Nurse role.',
            nurse_clinical_scope_block: 'This request is outside the Nurse role scope.',
            billing_clinical_scope_block: 'This request is outside the Billing Staff role scope.',
            front_desk_clinical_scope_block: 'This request is outside the Front Desk role scope.',
            front_desk_phi_limit: 'Clinical chart details are limited for the Front Desk role.',
            billing_phi_limit: 'Detailed clinical content is limited for the Billing Staff role.',
            medication_change_instruction: 'Medication start, stop, or dose-change instructions require clinician review.',
            autonomous_diagnosis_language: 'Autonomous diagnosis language is not allowed in the demo.'
        };

        return reasons[blockedReason] || `This request is outside the ${displayRoleLabel(role)} role scope.`;
    }

    function alternativeFor(role, blockedReason) {
        if (blockedReason === 'prompt_injection_block') {
            return 'Try a prompt that matches the selected staff role and approved workflow.';
        }

        const alternatives = {
            doctor: 'Ask for a differential diagnosis, chart summary, medication summary, or draft treatment plan for clinician review.',
            nurse: 'Ask for care coordination, follow-up preparation, medication education, or patient education support.',
            billing: 'Ask for claim status, insurance context, payment status, or billing workflow guidance.',
            front_desk: 'I can help with scheduling, contact confirmation, reminder drafting, or routing this to clinical staff.'
        };

        return alternatives[role] || alternatives.doctor;
    }

    function buildUiPayload(role, allowed, blockedReason, riskLevel, policyTags) {
        const doctorAllowed = allowed && role === 'doctor';
        return {
            blocked: !allowed,
            title: allowed ? 'Guardrails checked' : 'Guardrail blocked this request',
            statusLabel: doctorAllowed
                ? 'Guardrails checked · Doctor clinical role · Draft-only'
                : allowed
                    ? 'Guardrails checked · Role-safe · Draft-only'
                : 'Guardrails blocked · Safer alternative shown',
            displayReason: doctorAllowed
                ? 'Doctor clinical role enabled. Draft-only and human-review rules still apply.'
                : allowed
                    ? 'Role scope, prompt safety, and draft-only rules passed.'
                    : displayReasonFor(blockedReason, role),
            alternative: allowed ? '' : alternativeFor(role, blockedReason),
            roleLabel: displayRoleLabel(role),
            checks: ['Role scope', 'Prompt injection filter', 'Draft-only enforcement', 'PHI minimum necessary'],
            riskLevel: riskLevel || 'low',
            policyTags: unique(policyTags)
        };
    }

    function evaluatePromptPolicy(role, topic, prompt) {
        const normalizedPrompt = String(prompt || '').toLowerCase();
        const injectionMatch = containsPromptInjection(prompt);
        if (injectionMatch) {
            return {
                allowed: false,
                blockedReason: 'prompt_injection_block',
                riskLevel: 'high',
                policyTags: ['guardrails', 'prompt_injection', role]
            };
        }

        if (role === 'nurse' && (topic === 'differential_diagnosis' || topic === 'treatment_plan' || /\b(change|start|stop|increase|decrease|prescribe|diagnose)\b/i.test(prompt || '') || /\bmedication plan\b/i.test(prompt || ''))) {
            return {
                allowed: false,
                blockedReason: /\b(change|start|stop|increase|decrease|prescribe)\b/i.test(prompt || '') || /\bmedication plan\b/i.test(prompt || '')
                    ? 'nurse_medication_change_block'
                    : 'nurse_clinical_scope_block',
                riskLevel: 'high',
                policyTags: ['guardrails', 'nurse', 'role_scope']
            };
        }

        if (role === 'billing' && (
            topic === 'differential_diagnosis' ||
            topic === 'medication_info' ||
            topic === 'treatment_plan' ||
            /\b(full chart|full note|lab values|medications|treatment plan)\b/i.test(normalizedPrompt)
        )) {
            return {
                allowed: false,
                blockedReason: 'billing_clinical_scope_block',
                riskLevel: 'high',
                policyTags: ['guardrails', 'billing', 'role_scope']
            };
        }

        if (role === 'front_desk' && (
            /\b(tell me everything|show me everything|all chart data|entire chart|full history)\b/i.test(prompt || '') ||
            topic === 'differential_diagnosis' ||
            topic === 'medication_info' ||
            topic === 'clinical_notes' ||
            topic === 'treatment_plan' ||
            topic === 'follow_up' ||
            topic === 'patient_education' ||
            topic === 'visit_summary' ||
            topic === 'billing_review' ||
            CLINICAL_DISCLOSURE_PATTERN.test(prompt || '')
        )) {
            return {
                allowed: false,
                blockedReason: 'front_desk_clinical_scope_block',
                riskLevel: 'high',
                policyTags: ['guardrails', 'front_desk', 'minimum_phi']
            };
        }

        if (!ROLE_ALLOWED_MODES[role].has(topic) && topic !== 'general_assistant') {
            const blockedReasonByRole = {
                nurse: 'nurse_clinical_scope_block',
                billing: 'billing_clinical_scope_block',
                front_desk: 'front_desk_clinical_scope_block'
            };

            return {
                allowed: false,
                blockedReason: blockedReasonByRole[role] || 'role_scope_block',
                riskLevel: 'high',
                policyTags: ['guardrails', role, 'role_scope']
            };
        }

        return null;
    }

    function isDoctorDiagnosisPrompt(prompt, topic) {
        if (topic === 'differential_diagnosis') {
            return true;
        }

        return DOCTOR_DIAGNOSIS_PROMPT_PATTERN.test(String(prompt || ''));
    }

    function sanitizeDoctorDiagnosisLine(text) {
        let value = String(text || '').trim();
        if (!value) {
            return '';
        }

        value = value
            .replace(/\bthe diagnosis is\b/gi, 'A differential review should consider')
            .replace(/\bdefinitive diagnosis\b/gi, 'differential diagnosis review')
            .replace(/\bfinal diagnosis\b/gi, 'working differential assessment')
            .replace(/\bi diagnose\b/gi, 'I would frame the differential as')
            .replace(/\bthis patient definitely has\b/gi, 'The chart context may be consistent with')
            .replace(/\bthis is clearly\b/gi, 'This could represent');

        return value;
    }

    function sanitizeDoctorDiagnosisLanguage(text) {
        const lines = String(text || '')
            .split('\n')
            .map((line) => sanitizeDoctorDiagnosisLine(line))
            .filter(Boolean);

        const intro = [
            'I can\'t provide a definitive diagnosis, but I can draft a differential diagnosis review for clinician review.'
        ];

        return unique([...intro, ...lines]).join('\n\n').trim();
    }

    function sanitizeDoctorSections(sections, sanitizer) {
        return cloneSections(sections).map((section) => ({
            ...section,
            items: Array.isArray(section.items) ? section.items.map((item) => sanitizer(String(item || ''))).filter(Boolean) : []
        }));
    }

    function evaluateResponsePolicy(role, topic, responseText, prompt) {
        const text = String(responseText || '');
        if (!text) {
            return {
                riskLevel: 'low',
                blockedReason: '',
                policyTags: ['guardrails', role, topic],
                rewriteTypes: []
            };
        }

        let riskLevel = 'low';
        let blockedReason = '';
        const policyTags = ['guardrails', role, topic];
        const rewriteTypes = [];

        if (role === 'doctor' && isDoctorDiagnosisPrompt(prompt, topic)) {
            riskLevel = maxRisk(riskLevel, 'high');
            policyTags.push('doctor', 'diagnosis_review_only', 'draft_only');
            rewriteTypes.push('doctor_diagnosis_review');
        }

        if (role === 'front_desk' && CLINICAL_DISCLOSURE_PATTERN.test(text)) {
            blockedReason = 'front_desk_phi_limit';
            riskLevel = 'high';
            policyTags.push('minimum_phi');
        } else if (role === 'billing' && /\b(lab|labs|medication|medications|treatment plan|dose|dosage|troponin|a1c|glucose|wbc)\b/i.test(text)) {
            blockedReason = 'billing_phi_limit';
            riskLevel = 'high';
            policyTags.push('billing_scope');
        } else if (DEFINITIVE_DIAGNOSIS_PATTERN.test(text)) {
            if (role === 'doctor') {
                riskLevel = 'high';
                policyTags.push('diagnosis_review_only', 'draft_only');
                rewriteTypes.push('doctor_diagnosis_review');
            } else {
                blockedReason = 'autonomous_diagnosis_language';
                riskLevel = 'high';
                policyTags.push('diagnosis_review_only');
            }
        } else if (MEDICATION_CHANGE_PATTERN.test(text)) {
            if (role === 'doctor') {
                riskLevel = 'high';
                policyTags.push('doctor', 'medication_review_discussion', 'draft_only');
            } else {
                blockedReason = 'medication_change_instruction';
                riskLevel = 'high';
                policyTags.push('medication_review_only');
            }
        } else if (URGENT_DIRECTIVE_PATTERN.test(text) && !ESCALATION_LANGUAGE_PATTERN.test(text)) {
            riskLevel = 'medium';
            policyTags.push('escalation_added');
        }

        return {
            riskLevel,
            blockedReason,
            policyTags: unique(policyTags),
            rewriteTypes: unique(rewriteTypes)
        };
    }

    function cloneSections(sections) {
        return Array.isArray(sections)
            ? sections.map((section) => ({
                ...section,
                items: Array.isArray(section.items) ? section.items.slice() : []
            }))
            : [];
    }

    function evaluate(input) {
        const role = normalizeRole(input.role);
        const mode = normalizeMode(input.mode);
        const prompt = String(input.prompt || '');
        const topic = inferTopic(mode, prompt);
        const metadata = input.metadata || {};
        const sections = cloneSections(input.sections || []);
        const draftResponse = String(input.draftResponse || '');
        const draftText = responseToText(draftResponse, sections);
        const promptPolicy = evaluatePromptPolicy(role, topic, prompt);

        if (promptPolicy) {
            const finalResponse = buildBlockedMessage(role, promptPolicy.blockedReason);
            return {
                allowed: false,
                finalResponse,
                blockedReason: promptPolicy.blockedReason,
                riskLevel: promptPolicy.riskLevel,
                policyTags: unique(promptPolicy.policyTags),
                auditSummary: {
                    role,
                    mode,
                    topic,
                    patientKey: metadata.selectedPatientKey || metadata.patientKey || null,
                    phase: 'prompt',
                    blockedReason: promptPolicy.blockedReason
                },
                finalSections: [],
                finalSafety: defaultSafetyFor(role, topic, ''),
                ui: buildUiPayload(role, false, promptPolicy.blockedReason, promptPolicy.riskLevel, promptPolicy.policyTags)
            };
        }

        const responsePolicy = evaluateResponsePolicy(role, topic, draftText, prompt);
        if (responsePolicy.blockedReason) {
            const finalResponse = buildBlockedMessage(role, responsePolicy.blockedReason);
            return {
                allowed: false,
                finalResponse,
                blockedReason: responsePolicy.blockedReason,
                riskLevel: responsePolicy.riskLevel,
                policyTags: unique(responsePolicy.policyTags),
                auditSummary: {
                    role,
                    mode,
                    topic,
                    patientKey: metadata.selectedPatientKey || metadata.patientKey || null,
                    phase: draftText ? 'response' : 'prompt',
                    blockedReason: responsePolicy.blockedReason
                },
                finalSections: [],
                finalSafety: defaultSafetyFor(role, topic, ''),
                ui: buildUiPayload(role, false, responsePolicy.blockedReason, responsePolicy.riskLevel, responsePolicy.policyTags)
            };
        }

        let finalResponse = draftResponse;
        let finalSections = sections;
        if (role === 'doctor' && responsePolicy.rewriteTypes.includes('doctor_diagnosis_review')) {
            finalResponse = sanitizeDoctorDiagnosisLanguage(finalResponse);
            finalSections = sanitizeDoctorSections(finalSections, sanitizeDoctorDiagnosisLine);
        }

        if (responsePolicy.riskLevel === 'medium' && URGENT_DIRECTIVE_PATTERN.test(draftText) && !ESCALATION_LANGUAGE_PATTERN.test(draftText)) {
            finalResponse = `${finalResponse}\n\nIf symptoms are urgent or worsening, escalate immediately to the supervising clinician or emergency services per protocol.`.trim();
        }

        return {
            allowed: true,
            finalResponse,
            blockedReason: '',
            riskLevel: responsePolicy.riskLevel,
            policyTags: unique(responsePolicy.policyTags),
            auditSummary: {
                role,
                mode,
                topic,
                patientKey: metadata.selectedPatientKey || metadata.patientKey || null,
                    phase: draftText ? 'response' : 'prompt',
                    blockedReason: ''
                },
            finalSections,
            finalSafety: defaultSafetyFor(role, topic, input.safetyText || ''),
            ui: buildUiPayload(role, true, '', responsePolicy.riskLevel, responsePolicy.policyTags)
        };
    }

    return {
        evaluate,
        inferTopic,
        containsPromptInjection
    };
}));
