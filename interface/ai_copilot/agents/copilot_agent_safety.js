(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotAgentSafety = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const CLINICAL_REVIEW_NOTE = 'Draft only. Human clinician review required. No direct chart writes occur without clinician approval.';
    const BILLING_REVIEW_NOTE = 'Draft only. Human billing and compliance review required. No automatic claim actions occur.';
    const FRONT_DESK_REVIEW_NOTE = 'Administrative draft only. Human review required. Minimum necessary PHI only.';

    function unique(values) {
        return Array.from(new Set((values || []).filter(Boolean)));
    }

    function defaultSafetyNoteFor(role) {
        if (role === 'front_desk') {
            return FRONT_DESK_REVIEW_NOTE;
        }
        if (role === 'billing') {
            return BILLING_REVIEW_NOTE;
        }

        return CLINICAL_REVIEW_NOTE;
    }

    function defaultRoleBoundaryMessage(role, blockedReason) {
        const blockedReasonText = String(blockedReason || '').trim();
        const messages = {
            billing_clinical_scope_block: 'Detailed clinical information is not available for the Billing Staff role. You can review claim status, insurance context, payment status, and billing workflow summaries.',
            front_desk_clinical_scope_block: 'Clinical chart details are restricted for the Front Desk role. You can use appointment, contact, and reminder workflows with minimum necessary PHI only.',
            nurse_clinical_scope_block: 'Diagnosis and prescribing guidance are restricted for the Nurse role. You can request care coordination, follow-up preparation, medication education, or patient education drafts.',
            prompt_injection_block: 'I can\'t bypass role restrictions or hidden system instructions. Please use a prompt that matches the selected role and approved workflow.'
        };

        if (messages[blockedReasonText]) {
            return messages[blockedReasonText];
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

        return 'This request needs licensed clinician review before it can be completed in the demo workflow.';
    }

    function describeWorkflowLimitation(role, blockedReason) {
        const baseMessage = defaultRoleBoundaryMessage(role, blockedReason);
        if (String(blockedReason || '') === 'prompt_injection_block') {
            return 'Supervisor Agent blocked the request because it attempted to override instructions or reveal hidden workflow details.';
        }
        if (String(blockedReason || '').includes('billing')) {
            return `Supervisor Agent limited the workflow for the Billing Staff role. ${baseMessage}`;
        }
        if (String(blockedReason || '').includes('front_desk')) {
            return `Supervisor Agent limited the workflow for the Front Desk role. ${baseMessage}`;
        }
        if (String(blockedReason || '').includes('nurse')) {
            return `Supervisor Agent limited the workflow for the Nurse role. ${baseMessage}`;
        }

        return baseMessage;
    }

    function normalizeSource(source) {
        if (!source) {
            return null;
        }

        if (typeof source === 'string') {
            return {
                title: source,
                category: source.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'source'
            };
        }

        const title = String(source.title || source.label || '').trim();
        if (!title) {
            return null;
        }

        const category = String(source.category || source.id || '').trim();
        return {
            title: title,
            category: category || title.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'source'
        };
    }

    function normalizeSources(sources) {
        const seen = new Set();
        return (Array.isArray(sources) ? sources : [])
            .map(normalizeSource)
            .filter(Boolean)
            .filter(function (source) {
                const key = `${source.category}::${source.title}`;
                if (seen.has(key)) {
                    return false;
                }
                seen.add(key);
                return true;
            });
    }

    function sectionTitleEquals(section, title) {
        return section && typeof section === 'object' && String(section.title || '').trim().toLowerCase() === String(title || '').trim().toLowerCase();
    }

    function cloneSections(sections) {
        return Array.isArray(sections)
            ? sections.map(function (section) {
                return {
                    title: String(section && section.title ? section.title : '').trim(),
                    tone: String(section && section.tone ? section.tone : 'neutral').trim() || 'neutral',
                    items: Array.isArray(section && section.items) ? section.items.map(function (item) {
                        return String(item || '').trim();
                    }).filter(Boolean) : []
                };
            }).filter(function (section) {
                return section.title && section.items.length > 0;
            })
            : [];
    }

    function flattenSectionItems(sections, ignoredTitles) {
        const ignored = new Set((ignoredTitles || []).map(function (value) {
            return String(value || '').trim().toLowerCase();
        }));

        return cloneSections(sections).reduce(function (items, section) {
            if (ignored.has(section.title.toLowerCase())) {
                return items;
            }

            return items.concat(section.items);
        }, []);
    }

    function ensureSection(sections, title, items, tone) {
        const nextItems = unique((items || []).map(function (item) {
            return String(item || '').trim();
        }).filter(Boolean));

        if (nextItems.length === 0) {
            return cloneSections(sections);
        }

        const cloned = cloneSections(sections);
        const existingIndex = cloned.findIndex(function (section) {
            return sectionTitleEquals(section, title);
        });

        if (existingIndex === -1) {
            cloned.push({
                title: title,
                tone: tone || 'neutral',
                items: nextItems
            });
            return cloned;
        }

        cloned[existingIndex] = {
            ...cloned[existingIndex],
            tone: tone || cloned[existingIndex].tone || 'neutral',
            items: unique(cloned[existingIndex].items.concat(nextItems))
        };
        return cloned;
    }

    function buildMissingDataItems(validation, toolResults) {
        const validationValue = validation && typeof validation === 'object' ? validation : {};
        const chartResult = toolResults.chartContextResult || {};
        const attachmentResult = toolResults.attachmentResult || {};
        const draftResult = toolResults.draftResult || {};
        const draftValue = draftResult && typeof draftResult === 'object' && draftResult.draft ? draftResult.draft : {};

        return unique(
            []
                .concat(validationValue.missing_data || [])
                .concat(draftValue.missing_data || [])
                .concat(chartResult.missing_data || [])
                .concat(attachmentResult.missing_data || [])
                .concat(validationValue.citation_gaps || [])
                .concat(validationValue.unsupported_claims || [])
        );
    }

    function normalizeFinalSections(draft, validation, toolResults, request) {
        const chartResult = toolResults.chartContextResult || {};
        const guidelineResult = toolResults.guidelineEvidenceResult || {};
        const attachmentResult = toolResults.attachmentResult || {};
        const draftValue = draft && typeof draft === 'object' ? draft : {};
        const validationValue = validation && typeof validation === 'object' ? validation : {};
        const requestMode = String(request && request.mode ? request.mode : '').toLowerCase();
        const requestPrompt = String(request && request.prompt ? request.prompt : '').toLowerCase();
        let sections = cloneSections(draftValue.sections || []);

        const summaryItems = [];
        if (draftValue.answer) {
            summaryItems.push(String(draftValue.answer).trim());
        }

        const keyFindingItems = flattenSectionItems(sections, [
            'summary',
            'what changed since last visit',
            'missing data / uncertainty',
            'sources used',
            'draft-only clinician review'
        ]).slice(0, 8);

        const changeItems = unique(
            flattenSectionItems(sections, ['summary', 'missing data / uncertainty', 'sources used', 'draft-only clinician review'])
                .filter(function (item) {
                    return /\bchanged|increase|decrease|new|since last visit|compared with prior\b/i.test(item);
                })
        );
        const needsChangeSection = changeItems.length > 0
            || /what changed since|last visit|compare|previous visit/.test(requestPrompt)
            || ['treatment_plan', 'follow_up', 'rag_chart_context', 'latest_ambient_summary', 'visit_summary'].includes(requestMode);

        const missingItems = buildMissingDataItems(validationValue, {
            ...toolResults,
            draftResult: { draft: draftValue }
        });

        const sources = normalizeSources(
            (validationValue.validated_sources || [])
                .concat(draftValue.sources || [])
                .concat(chartResult.sources || [])
                .concat(guidelineResult.sources || [])
                .concat(attachmentResult.sources || [])
        );

        const reviewItems = unique([
            validationValue.draft_only_note || '',
            draftValue.safety_note || '',
            defaultSafetyNoteFor(request.role),
            'No direct chart writes occur without clinician approval.'
        ]);

        sections = ensureSection(sections, 'Summary', summaryItems, 'neutral');
        sections = ensureSection(
            sections,
            'Key findings',
            keyFindingItems.length > 0 ? keyFindingItems : ['No grounded findings were returned for this draft.'],
            'neutral'
        );
        if (needsChangeSection) {
            sections = ensureSection(
                sections,
                'What changed since last visit',
                changeItems.length > 0 ? changeItems : ['No confirmed change details were retrieved from the available chart context.'],
                changeItems.length > 0 ? 'neutral' : 'yellow'
            );
        }
        sections = ensureSection(
            sections,
            'Missing data / uncertainty',
            missingItems.length > 0 ? missingItems : ['No major chart-grounding gaps were identified in the retrieved context used for this draft.'],
            missingItems.length > 0 ? 'yellow' : 'neutral'
        );
        sections = ensureSection(
            sections,
            'Sources Used',
            sources.map(function (source) {
                return source.title;
            }),
            'neutral'
        );
        sections = ensureSection(sections, 'Draft-only clinician review', reviewItems, 'neutral');

        return {
            sections: sections,
            sources: sources
        };
    }

    function buildBlockedSections(validation, toolResults, request) {
        const validationValue = validation && typeof validation === 'object' ? validation : {};
        const chartSources = normalizeSources(
            []
                .concat(validationValue.validated_sources || [])
                .concat(toolResults.chartContextResult?.sources || [])
                .concat(toolResults.guidelineEvidenceResult?.sources || [])
                .concat(toolResults.attachmentResult?.sources || [])
        );

        const summary = validationValue.safe_refusal || defaultRoleBoundaryMessage(request.role, validationValue.blocked_reason || '');
        const missingItems = unique([]
            .concat(validationValue.missing_data || [])
            .concat(validationValue.citation_gaps || [])
            .concat(validationValue.unsupported_claims || [])
            .concat(validationValue.blocked_reason ? [`Blocked reason: ${validationValue.blocked_reason}`] : []));

        return [
            {
                title: 'Summary',
                tone: 'yellow',
                items: [summary]
            },
            {
                title: 'Missing data / uncertainty',
                tone: 'yellow',
                items: missingItems.length > 0 ? missingItems : ['This request was blocked before a grounded draft could be completed.']
            },
            {
                title: 'Sources Used',
                tone: 'neutral',
                items: chartSources.map(function (source) {
                    return source.title;
                })
            },
            {
                title: 'Draft-only clinician review',
                tone: 'neutral',
                items: [validationValue.draft_only_note || defaultSafetyNoteFor(request.role)]
            }
        ].filter(function (section) {
            return section.items.length > 0;
        });
    }

    return {
        BILLING_REVIEW_NOTE: BILLING_REVIEW_NOTE,
        CLINICAL_REVIEW_NOTE: CLINICAL_REVIEW_NOTE,
        FRONT_DESK_REVIEW_NOTE: FRONT_DESK_REVIEW_NOTE,
        buildBlockedSections: buildBlockedSections,
        buildMissingDataItems: buildMissingDataItems,
        cloneSections: cloneSections,
        defaultRoleBoundaryMessage: defaultRoleBoundaryMessage,
        defaultSafetyNoteFor: defaultSafetyNoteFor,
        describeWorkflowLimitation: describeWorkflowLimitation,
        ensureSection: ensureSection,
        flattenSectionItems: flattenSectionItems,
        normalizeFinalSections: normalizeFinalSections,
        normalizeSource: normalizeSource,
        normalizeSources: normalizeSources,
        unique: unique
    };
}));
