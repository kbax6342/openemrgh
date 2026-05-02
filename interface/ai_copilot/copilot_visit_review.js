(function () {
    'use strict';

    const MARCUS_PATIENT_KEY = 'DEMO-PCP-1001';
    const MARCUS_PATIENT_NAME = 'Marcus Johnson';
    const CLINICAL_ROLES = new Set(['doctor', 'nurse']);
    const DEMO_VISIT_STORAGE_PREFIX = 'openemr_ai_copilot_demo_visits_';

    function createId(prefix) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return prefix + '_' + window.crypto.randomUUID();
        }

        return prefix + '_' + Date.now() + '_' + Math.random().toString(16).slice(2);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getLocalStorage() {
        try {
            return window.localStorage;
        } catch (error) {
            return null;
        }
    }

    function storageKeyForPatient(patientKey) {
        return DEMO_VISIT_STORAGE_PREFIX + String(patientKey || '').trim();
    }

    function formatLocalDateTime(dateValue) {
        const date = dateValue instanceof Date ? dateValue : new Date(dateValue);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleString([], {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function normalizeVisitRecord(record) {
        if (!record || typeof record !== 'object') {
            return null;
        }

        const approvedAtValue = record.approvedAt || new Date().toISOString();
        const approvedAtLabel = record.approvedAtLabel || formatLocalDateTime(approvedAtValue);
        const defaultTableRow = {
            date: approvedAtLabel,
            issue: 'Medication adherence / care coordination',
            reason: 'AI-assisted follow-up visit review',
            form: 'Ambient Encounter Capture',
            provider: 'Dr. Demo Provider',
            billing: 'Review needed',
            insurance: 'Verification needed'
        };

        return {
            id: record.id || createId('ambient_visit'),
            draftId: record.draftId || '',
            patientKey: record.patientKey || '',
            patientName: record.patientName || '',
            title: record.title || 'AI-Assisted Visit Review',
            visitType: record.visitType || 'Ambient Encounter Capture',
            status: record.status || 'Completed',
            reviewStatus: record.reviewStatus || 'Clinician Reviewed',
            source: record.source || 'Consent-Based AI Visit Capture',
            consentConfirmed: Boolean(record.consentConfirmed),
            approvedAt: approvedAtValue,
            approvedAtLabel: approvedAtLabel,
            summary: record.summary || '',
            approvedNotes: Array.isArray(record.approvedNotes) ? record.approvedNotes.slice() : [],
            badges: Array.isArray(record.badges) ? record.badges.slice() : [],
            tableRow: Object.assign({}, defaultTableRow, record.tableRow || {}),
            requestId: record.requestId || '',
            approvedItemCount: typeof record.approvedItemCount === 'number' ? record.approvedItemCount : 0,
            reviewStatusKey: record.reviewStatusKey || 'clinician_reviewed',
            draftOnly: Boolean(record.draftOnly)
        };
    }

    function readApprovedVisits(patientKey) {
        if (!patientKey) {
            return [];
        }

        const storage = getLocalStorage();
        if (!storage) {
            return [];
        }

        try {
            const rawValue = storage.getItem(storageKeyForPatient(patientKey));
            if (!rawValue) {
                return [];
            }

            const parsed = JSON.parse(rawValue);
            if (!Array.isArray(parsed)) {
                return [];
            }

            return parsed
                .map(normalizeVisitRecord)
                .filter(Boolean)
                .sort(function (left, right) {
                    return new Date(right.approvedAt).getTime() - new Date(left.approvedAt).getTime();
                });
        } catch (error) {
            console.error('[Medical Co-Pilot Debug] ambient_visit_storage_read_failed', error);
            return [];
        }
    }

    function writeApprovedVisits(patientKey, records) {
        if (!patientKey) {
            return false;
        }

        const storage = getLocalStorage();
        if (!storage) {
            return false;
        }

        try {
            storage.setItem(storageKeyForPatient(patientKey), JSON.stringify(records));
            return true;
        } catch (error) {
            console.error('[Medical Co-Pilot Debug] ambient_visit_storage_write_failed', error);
            return false;
        }
    }

    function saveApprovedVisitRecord(record) {
        const normalizedRecord = normalizeVisitRecord(record);
        if (!normalizedRecord || !normalizedRecord.patientKey) {
            return {
                created: false,
                record: normalizedRecord,
                records: []
            };
        }

        const existingRecords = readApprovedVisits(normalizedRecord.patientKey);
        const existingRecord = existingRecords.find(function (entry) {
            return entry.draftId && entry.draftId === normalizedRecord.draftId;
        }) || null;

        if (existingRecord) {
            return {
                created: false,
                record: existingRecord,
                records: existingRecords
            };
        }

        const updatedRecords = [normalizedRecord].concat(existingRecords).sort(function (left, right) {
            return new Date(right.approvedAt).getTime() - new Date(left.approvedAt).getTime();
        });

        if (!writeApprovedVisits(normalizedRecord.patientKey, updatedRecords)) {
            return {
                created: false,
                record: normalizedRecord,
                records: existingRecords
            };
        }

        return {
            created: true,
            record: normalizedRecord,
            records: updatedRecords
        };
    }

    function latestApprovedVisit(patientKey) {
        return readApprovedVisits(patientKey)[0] || null;
    }

    function dispatchApprovedVisitEvent(detail) {
        const eventDetail = Object.assign({
            patientKey: MARCUS_PATIENT_KEY
        }, detail || {});

        try {
            window.dispatchEvent(new CustomEvent('openemr:aiVisitApproved', {
                detail: eventDetail
            }));
        } catch (error) {
        }

        try {
            if (window.top && window.top !== window) {
                window.top.dispatchEvent(new CustomEvent('openemr:aiVisitApproved', {
                    detail: eventDetail
                }));
            }
        } catch (error) {
        }
    }

    function resolveTelemetry() {
        if (window.CopilotTelemetry) {
            return window.CopilotTelemetry;
        }

        try {
            if (window.top && window.top.CopilotTelemetry) {
                return window.top.CopilotTelemetry;
            }
        } catch (error) {
        }

        return null;
    }

    function logDemoEvent(eventName, payload) {
        const safePayload = Object.assign({
            selectedPatientKey: MARCUS_PATIENT_KEY,
            source: 'medical-copilot'
        }, payload || {});

        const telemetry = resolveTelemetry();
        if (telemetry && typeof telemetry.log === 'function') {
            telemetry.log(eventName, safePayload);
            return;
        }

        console.info('[Medical Co-Pilot Audit] ' + eventName, Object.assign({
            event: eventName,
            timestamp: new Date().toISOString()
        }, safePayload));
    }

    window.OpenEMRAIAmbientVisitDemo = {
        patientKey: MARCUS_PATIENT_KEY,
        patientName: MARCUS_PATIENT_NAME,
        storageKeyForPatient: storageKeyForPatient,
        formatLocalDateTime: formatLocalDateTime,
        readApprovedVisits: readApprovedVisits,
        latestApprovedVisit: latestApprovedVisit,
        saveApprovedVisitRecord: saveApprovedVisitRecord,
        dispatchApprovedVisitEvent: dispatchApprovedVisitEvent,
        logEvent: logDemoEvent
    };

    function initVisitReviewDemo() {
        const ambientResults = document.getElementById('copilot-ambient-results');
        const mount = document.getElementById('copilot-visit-review-demo');
        const patientSelect = document.getElementById('copilot-patient-select');
        const roleSelect = document.getElementById('copilot-role-select');
        const modeSelect = document.getElementById('copilot-mode-select');
        const micButton = document.getElementById('copilot-ambient-mic');
        const statusMount = document.getElementById('copilot-ambient-status');

        if (!ambientResults || !mount || !patientSelect || !roleSelect || !modeSelect || !micButton || !statusMount) {
            return;
        }

        const state = {
            modalType: null,
            consentConfirmed: false,
            consentChecked: false,
            isListening: false,
            requestId: '',
            draftId: '',
            draft: null,
            approvedSnapshot: null,
            auditEntries: [],
            reviewNotice: '',
            editMode: false,
            editDraftValue: '',
            draftReady: false,
            modalError: '',
            ambientUiVisible: false,
            lastUpdatesRenderKey: '',
            lastReviewRenderKey: ''
        };

        mount.innerHTML = '';
        statusMount.innerHTML = '';

        const modalRoot = document.createElement('div');
        modalRoot.id = 'copilot-ambient-modal-root';
        modalRoot.className = 'copilot-ambient-modal-root';
        modalRoot.hidden = true;
        document.body.appendChild(modalRoot);

        const refs = {
            ambientResults,
            mount,
            micButton,
            statusMount,
            modalRoot
        };

        function currentRole() {
            return String(roleSelect.value || 'doctor').toLowerCase();
        }

        function currentMode() {
            return String(modeSelect.value || 'general_assistant').toLowerCase();
        }

        function currentPatientOption() {
            return patientSelect.options[patientSelect.selectedIndex] || null;
        }

        function currentPatientKey() {
            const option = currentPatientOption();
            return option ? (option.dataset.pubpid || null) : null;
        }

        function isMarcusSelected() {
            return currentPatientKey() === MARCUS_PATIENT_KEY;
        }

        function isAmbientRoleAllowed() {
            return CLINICAL_ROLES.has(currentRole());
        }

        function getTelemetry() {
            if (window.CopilotTelemetry) {
                return window.CopilotTelemetry;
            }

            try {
                if (window.top && window.top.CopilotTelemetry) {
                    return window.top.CopilotTelemetry;
                }
            } catch (error) {
            }

            return null;
        }

        function logAuditEvent(eventName, extra) {
            const payload = Object.assign({
                requestId: state.requestId || null,
                selectedPatientKey: currentPatientKey(),
                role: currentRole(),
                mode: currentMode(),
                reviewStatus: '',
                approvedItemCount: undefined,
                draftOnly: true,
                consentConfirmed: state.consentConfirmed
            }, extra || {});

            const telemetry = getTelemetry();
            if (telemetry) {
                telemetry.log(eventName, payload);
            }

            state.auditEntries.unshift({
                event: eventName,
                timestamp: new Date().toISOString(),
                reviewStatus: payload.reviewStatus || '',
                approvedItemCount: typeof payload.approvedItemCount === 'number' ? payload.approvedItemCount : '',
                consentConfirmed: payload.consentConfirmed ? 'Yes' : 'No'
            });
        }

        function debugLog(eventName, extra, level) {
            const payload = Object.assign({
                event: eventName,
                timestamp: new Date().toISOString(),
                requestId: state.requestId || null,
                selectedPatientKey: currentPatientKey(),
                role: currentRole(),
                mode: currentMode(),
                consentConfirmed: state.consentConfirmed,
                modalType: state.modalType || null,
                source: 'medical-copilot'
            }, extra || {});

            const method = level === 'error' ? 'error' : (level === 'warn' ? 'warn' : 'info');
            console[method]('[Medical Co-Pilot Debug]', payload);
        }

        function availableState() {
            return {
                enabled: isMarcusSelected() && isAmbientRoleAllowed()
            };
        }

        function buildDraft() {
            return {
                requestId: state.requestId,
                draftId: state.draftId,
                generatedAt: formatLocalDateTime(new Date()),
                conversationSummary: 'Marcus Johnson discussed medication adherence concerns, insurance changes, recent vitals, lab follow-up needs, appointment scheduling, immunization review, and care experience preferences.',
                visitHistorySummary: 'Patient presented for routine follow-up. Discussed medication adherence, insurance update, recent vitals, lab work, reminder preferences, immunization review, and care team support. Clinician reviewed next steps and recommended follow-up labs.',
                groups: [
                    {
                        id: 'conversation_summary',
                        title: 'Conversation Summary',
                        items: [
                            {
                                id: 'conversation_summary',
                                label: 'Marcus Johnson discussed medication adherence concerns, insurance changes, recent vitals, lab follow-up needs, appointment scheduling, immunization review, and care experience preferences.',
                                checked: true
                            }
                        ]
                    },
                    {
                        id: 'dashboard_updates',
                        title: 'Suggested Dashboard Updates',
                        items: [
                            {
                                id: 'medication_support',
                                label: 'Medication Support Needed',
                                detail: 'Patient reports difficulty remembering evening medication dose.',
                                checked: true,
                                approvedLabel: 'Medication Support: Evening medication reminder recommended.'
                            },
                            {
                                id: 'insurance_change',
                                label: 'Insurance Change',
                                detail: 'Patient stated his insurance coverage recently changed and needs verification.',
                                checked: true,
                                approvedLabel: 'Insurance: Patient reported recent insurance change. Verification needed.'
                            },
                            {
                                id: 'lab_follow_up',
                                label: 'Lab Follow-up Needed',
                                detail: 'Clinician discussed ordering updated A1C and lipid panel.',
                                checked: true,
                                approvedLabel: 'Labs: A1C and lipid panel discussed for follow-up.'
                            },
                            {
                                id: 'vitals_reviewed',
                                label: 'Vitals Reviewed',
                                detail: 'Nurse-recorded vitals were discussed as part of the rooming process.',
                                checked: true,
                                approvedLabel: 'Vitals: Nurse-recorded vitals reviewed during visit.'
                            },
                            {
                                id: 'care_preference',
                                label: 'Care Preference',
                                detail: 'Patient prefers afternoon phone reminders and written medication instructions.',
                                checked: true,
                                approvedLabel: 'Care Preferences: Prefers afternoon phone reminders and written medication instructions.'
                            },
                            {
                                id: 'care_team_update',
                                label: 'Care Team Update',
                                detail: 'Patient wants his daughter added as a care support contact.',
                                checked: true,
                                approvedLabel: 'Care Team: Daughter requested as care support contact.'
                            }
                        ]
                    },
                    {
                        id: 'visit_history',
                        title: 'Suggested Visit History Entry',
                        intro: 'AI-drafted visit summary:',
                        items: [
                            {
                                id: 'visit_history_summary',
                                label: 'Patient presented for routine follow-up. Discussed medication adherence, insurance update, recent vitals, lab work, reminder preferences, immunization review, and care team support. Clinician reviewed next steps and recommended follow-up labs.',
                                checked: true
                            }
                        ]
                    },
                    {
                        id: 'appointments',
                        title: 'Suggested Appointments',
                        items: [
                            {
                                id: 'future_appointment',
                                label: 'Future Appointment: Primary Care Follow-up — June 18, 2026',
                                detail: 'Primary care follow-up on June 18, 2026.',
                                checked: true,
                                approvedLabel: 'Next Appointment: Primary Care Follow-up — June 18, 2026.'
                            },
                            {
                                id: 'recurring_appointment',
                                label: 'Recurring Appointment: Medication check-in reminder — Every 4 weeks',
                                detail: 'Medication check-in reminder every 4 weeks.',
                                checked: true,
                                approvedLabel: 'Recurring Reminder: Medication check-in every 4 weeks.'
                            }
                        ]
                    },
                    {
                        id: 'immunization_review',
                        title: 'Suggested Immunization Review',
                        items: [
                            {
                                id: 'immunization_review',
                                label: 'Patient may be due for seasonal vaccine review. Clinician should verify immunization history before updating record.',
                                detail: 'Clinician should verify whether seasonal vaccines are due before updating the record.',
                                checked: true,
                                approvedLabel: 'Immunization: Seasonal vaccine status should be verified.'
                            }
                        ]
                    }
                ]
            };
        }

        function flattenItems() {
            if (!state.draft || !Array.isArray(state.draft.groups)) {
                return [];
            }

            return state.draft.groups.reduce(function (items, group) {
                return items.concat(group.items || []);
            }, []);
        }

        function checkedItems() {
            return flattenItems().filter(function (item) {
                return item.checked;
            });
        }

        function findItemById(itemId) {
            return flattenItems().find(function (item) {
                return item.id === itemId;
            }) || null;
        }

        function buildApprovedSnapshot() {
            const approvedItems = checkedItems();
            const dashboardLines = approvedItems
                .map(function (item) {
                    return item.approvedLabel || '';
                })
                .filter(Boolean);
            const approvedAt = new Date();

            return {
                requestId: state.requestId,
                draftId: state.draftId,
                patientKey: MARCUS_PATIENT_KEY,
                patientName: MARCUS_PATIENT_NAME,
                approvedAt: formatLocalDateTime(approvedAt),
                approvedAtIso: approvedAt.toISOString(),
                approvedItemCount: approvedItems.length,
                badges: ['Completed', 'Clinician Reviewed', 'AI Draft Approved', 'Consent Confirmed'],
                dashboardLines: dashboardLines,
                visitHistoryTitle: 'AI-Assisted Visit Review',
                visitType: 'Ambient Encounter Capture',
                status: 'Completed',
                reviewStatus: 'Clinician Reviewed',
                visitHistoryReason: 'Routine follow-up with medication, labs, insurance, vitals, appointments, immunization review, and care coordination discussion.',
                visitHistorySummary: state.draft ? state.draft.visitHistorySummary : '',
                sourceLabel: 'Consent-Based AI Visit Capture'
            };
        }

        function buildApprovedVisitRecord(snapshot) {
            return normalizeVisitRecord({
                id: createId('ambient_visit'),
                draftId: snapshot.draftId || state.draftId,
                patientKey: snapshot.patientKey || MARCUS_PATIENT_KEY,
                patientName: snapshot.patientName || MARCUS_PATIENT_NAME,
                title: snapshot.visitHistoryTitle,
                visitType: snapshot.visitType,
                status: snapshot.status,
                reviewStatus: snapshot.reviewStatus,
                source: snapshot.sourceLabel,
                consentConfirmed: state.consentConfirmed,
                approvedAt: snapshot.approvedAtIso || new Date().toISOString(),
                approvedAtLabel: snapshot.approvedAt,
                summary: snapshot.visitHistorySummary,
                approvedNotes: snapshot.dashboardLines || [],
                badges: snapshot.badges || [],
                tableRow: {
                    date: snapshot.approvedAt,
                    issue: 'Medication adherence / care coordination',
                    reason: 'AI-assisted follow-up visit review',
                    form: 'Ambient Encounter Capture',
                    provider: 'Dr. Demo Provider',
                    billing: 'Review needed',
                    insurance: 'Verification needed'
                },
                requestId: state.requestId,
                approvedItemCount: snapshot.approvedItemCount,
                reviewStatusKey: 'clinician_reviewed',
                draftOnly: false
            });
        }

        function revealAmbientResults(reviewStatus) {
            if (state.ambientUiVisible) {
                return;
            }

            state.ambientUiVisible = true;
            logAuditEvent('copilot_ambient_rows_revealed', {
                draftOnly: !state.approvedSnapshot,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: reviewStatus || 'pending_consent'
            });
        }

        function hideAmbientResults() {
            state.ambientUiVisible = false;
            state.lastUpdatesRenderKey = '';
            state.lastReviewRenderKey = '';
            refs.ambientResults.hidden = true;
            refs.statusMount.hidden = true;
            refs.statusMount.innerHTML = '';
            refs.mount.hidden = true;
            refs.mount.innerHTML = '';
        }

        function currentAmbientPhase() {
            if (state.approvedSnapshot) {
                return 'approved';
            }

            if (state.isListening) {
                return 'listening';
            }

            if (state.draftReady) {
                return 'draft_ready';
            }

            return 'pending_consent';
        }

        function renderStatusChips(labels) {
            return labels.map(function (label) {
                return '<span class="copilot-ambient-chip">' + escapeHtml(label) + '</span>';
            }).join('');
        }

        function renderUpdatesRow(phase) {
            let summaryMeta = 'Ambient capture pending consent';
            let bodyContent = '<p class="copilot-ambient-row-copy">Ambient encounter capture will stay draft-only until a clinician reviews it.</p>';
            let openAttribute = '';

            if (phase === 'listening') {
                summaryMeta = 'Listening · Consent confirmed · Draft only';
                openAttribute = ' open';
                bodyContent = [
                    '<p class="copilot-ambient-row-copy">Listening — consent confirmed. Ambient encounter capture is drafting updates for clinician review only.</p>',
                    '<div class="copilot-ambient-chip-row">',
                    renderStatusChips(['Consent Confirmed', 'Draft Only', 'Clinician Review Required']),
                    '</div>'
                ].join('');
            } else if (phase === 'draft_ready') {
                summaryMeta = 'Draft ready for review';
                openAttribute = ' open';
                bodyContent = [
                    '<p class="copilot-ambient-row-copy">Draft ready for review. Nothing has been added to the chart.</p>',
                    '<div class="copilot-ambient-chip-row">',
                    renderStatusChips(['Draft Ready', 'Review Required', 'Not Added to Chart']),
                    '</div>'
                ].join('');
            } else if (phase === 'approved' && state.approvedSnapshot) {
                summaryMeta = 'Clinician Reviewed · Consent Confirmed · AI Draft Approved';
                bodyContent = [
                    '<div class="copilot-ambient-chip-row">',
                    renderStatusChips(['Clinician Reviewed', 'Consent Confirmed', 'AI Draft Approved']),
                    '</div>',
                    '<ul class="copilot-ambient-list">',
                    state.approvedSnapshot.dashboardLines.map(function (line) {
                        return '<li>' + escapeHtml(line) + '</li>';
                    }).join(''),
                    '</ul>'
                ].join('');
            }

            return [
                '<details class="copilot-ambient-row" data-ambient-row="updates"' + openAttribute + '>',
                '  <summary class="copilot-ambient-row-summary">',
                '    <span class="copilot-ambient-row-copy-wrap">',
                '      <strong class="copilot-ambient-row-title">AI Reviewed Visit Updates</strong>',
                '      <span class="copilot-ambient-row-meta">' + escapeHtml(summaryMeta) + '</span>',
                '    </span>',
                '    <span class="copilot-ambient-row-icon" aria-hidden="true"></span>',
                '  </summary>',
                '  <div class="copilot-ambient-row-body">',
                bodyContent,
                '  </div>',
                '</details>'
            ].join('');
        }

        function renderReviewRow(phase) {
            let summaryMeta = 'Ambient capture pending consent';
            let bodyContent = [
                '<p class="copilot-ambient-row-copy">Ambient encounter capture is waiting for consent before drafting any visit review content.</p>',
                '<p class="copilot-ambient-review-footnote">Draft only. Requires clinician review. Not medical advice. Not automatically written to the chart.</p>'
            ].join('');
            let openAttribute = '';

            if (phase === 'listening') {
                summaryMeta = 'Listening — consent confirmed';
                openAttribute = ' open';
                bodyContent = [
                    '<section class="copilot-listening-indicator copilot-listening-active">',
                    '  <div class="copilot-listening-main">',
                    '    <div class="copilot-waveform" aria-hidden="true">',
                    '      <span class="copilot-waveform-bar"></span>',
                    '      <span class="copilot-waveform-bar"></span>',
                    '      <span class="copilot-waveform-bar"></span>',
                    '      <span class="copilot-waveform-bar"></span>',
                    '    </div>',
                    '    <div class="copilot-listening-copy">',
                    '      <strong>Listening — consent confirmed</strong>',
                    '      <p>Ambient encounter capture is drafting notes for clinician review only.</p>',
                    '    </div>',
                    '  </div>',
                    '  <div class="copilot-listening-meta">',
                    '    <div class="copilot-ambient-chip-row">',
                    renderStatusChips(['Consent Confirmed', 'Draft Only', 'Clinician Review Required']),
                    '    </div>',
                    '    <button type="button" class="copilot-ambient-inline-action" data-ambient-action="stop-listening">Stop</button>',
                    '  </div>',
                    '</section>'
                ].join('');
            } else if (phase === 'draft_ready' && state.draft) {
                summaryMeta = (state.draft.generatedAt || 'Draft ready') + ' · Draft ready for review';
                openAttribute = ' open';
                bodyContent = [
                    '<section class="copilot-ambient-draft-ready">',
                    '  <div class="copilot-ambient-draft-ready-main">',
                    '    <div>',
                    '      <strong>AI-Assisted Visit Review</strong>',
                    '      <p>Draft ready for review. Nothing has been added to the chart.</p>',
                    '    </div>',
                    '    <button type="button" class="copilot-ambient-inline-action" data-ambient-action="review-draft">Review visit draft</button>',
                    '  </div>',
                    '  <p class="copilot-ambient-review-footnote">Draft only. Requires clinician review. Not medical advice. Not automatically written to the chart.</p>',
                    '</section>'
                ].join('');
            } else if (phase === 'approved' && state.approvedSnapshot) {
                summaryMeta = state.approvedSnapshot.approvedAt + ' · Completed · Clinician Reviewed';
                bodyContent = [
                    '<article class="copilot-ambient-card copilot-ambient-card-review">',
                    '  <div class="copilot-ambient-card-header">',
                    '    <div>',
                    '      <p class="copilot-ambient-card-date">' + escapeHtml(state.approvedSnapshot.approvedAt) + '</p>',
                    '      <h4>AI-Assisted Visit Review / Ambient Encounter Capture</h4>',
                    '      <p>Completed · Clinician Reviewed · Consent-Based AI Visit Capture</p>',
                    '    </div>',
                    '  </div>',
                    '  <div class="copilot-ambient-badge-row">',
                    state.approvedSnapshot.badges.map(function (badge) {
                        return '<span class="copilot-ambient-badge">' + escapeHtml(badge) + '</span>';
                    }).join(''),
                    '  </div>',
                    '  <dl class="copilot-ambient-definition-list">',
                    '    <div><dt>Visit type</dt><dd>' + escapeHtml(state.approvedSnapshot.visitType) + '</dd></div>',
                    '    <div><dt>Reason</dt><dd>' + escapeHtml(state.approvedSnapshot.visitHistoryReason) + '</dd></div>',
                    '    <div><dt>Summary</dt><dd>' + escapeHtml(state.approvedSnapshot.visitHistorySummary) + '</dd></div>',
                    '    <div><dt>Source</dt><dd>' + escapeHtml(state.approvedSnapshot.sourceLabel) + '</dd></div>',
                    '  </dl>',
                    '  <div class="copilot-ambient-link-row">',
                    '    <button type="button" class="copilot-ambient-link-button" data-ambient-action="view-review-details">View Review Details</button>',
                    '    <button type="button" class="copilot-ambient-link-button" data-ambient-action="view-audit-trail">View Audit Trail</button>',
                    '  </div>',
                    '</article>'
                ].join('');
            }

            return [
                '<details class="copilot-ambient-row copilot-ambient-row-review" data-ambient-row="review"' + openAttribute + '>',
                '  <summary class="copilot-ambient-row-summary">',
                '    <span class="copilot-ambient-row-copy-wrap">',
                '      <strong class="copilot-ambient-row-title">AI-Assisted Visit Review</strong>',
                '      <span class="copilot-ambient-row-meta">' + escapeHtml(summaryMeta) + '</span>',
                '    </span>',
                '    <span class="copilot-ambient-row-icon" aria-hidden="true"></span>',
                '  </summary>',
                '  <div class="copilot-ambient-row-body">',
                bodyContent,
                '  </div>',
                '</details>'
            ].join('');
        }

        function renderAmbientResults() {
            if (!state.ambientUiVisible || !isMarcusSelected()) {
                hideAmbientResults();
                return;
            }

            const phase = currentAmbientPhase();
            const draftOnly = phase !== 'approved';
            const reviewStatus = phase === 'approved' ? 'clinician_reviewed' : phase;
            const visitId = state.approvedSnapshot ? (state.approvedSnapshot.visitId || null) : null;

            refs.ambientResults.hidden = false;
            refs.statusMount.hidden = false;
            refs.mount.hidden = false;
            refs.statusMount.innerHTML = renderUpdatesRow(phase);
            refs.mount.innerHTML = renderReviewRow(phase);

            const updatesRenderKey = phase + '|' + (state.approvedSnapshot ? (state.approvedSnapshot.visitId || '') : (state.draftId || 'pending'));
            if (state.lastUpdatesRenderKey !== updatesRenderKey) {
                state.lastUpdatesRenderKey = updatesRenderKey;
                logAuditEvent('copilot_below_chat_visit_updates_rendered', {
                    draftOnly: draftOnly,
                    consentConfirmed: state.consentConfirmed,
                    reviewStatus: reviewStatus,
                    draftId: state.draftId || null,
                    visitId: visitId
                });
            }

            const reviewRenderKey = phase + '|' + (state.approvedSnapshot ? (state.approvedSnapshot.visitId || '') : (state.draftId || 'pending'));
            if (state.lastReviewRenderKey !== reviewRenderKey) {
                state.lastReviewRenderKey = reviewRenderKey;
                logAuditEvent('copilot_below_chat_visit_review_rendered', {
                    draftOnly: draftOnly,
                    consentConfirmed: state.consentConfirmed,
                    reviewStatus: reviewStatus,
                    draftId: state.draftId || null,
                    visitId: visitId
                });
            }
        }

        function syncAmbientUi() {
            const availability = availableState();
            refs.micButton.disabled = !availability.enabled && !state.isListening;
            refs.micButton.classList.toggle('copilot-listening-active', state.isListening);
            refs.micButton.setAttribute('aria-pressed', state.isListening ? 'true' : 'false');
            refs.micButton.setAttribute('aria-label', state.isListening ? 'Stop ambient encounter capture' : 'Start ambient encounter capture');
            refs.micButton.title = state.isListening ? 'Stop ambient encounter capture' : 'Start ambient encounter capture';
            refs.micButton.classList.toggle('is-unavailable', !availability.enabled && !state.isListening);
            renderAmbientResults();
        }

        function openModal(type) {
            state.modalType = type;
            renderModal();
        }

        function closeModal() {
            const closingType = state.modalType;
            debugLog('ambient_modal_closed', {
                modalType: closingType || null
            });
            state.modalType = null;
            state.modalError = '';
            refs.modalRoot.hidden = true;
            refs.modalRoot.innerHTML = '';

            if (closingType === 'consent' && !state.consentConfirmed && !state.isListening && !state.draftReady) {
                state.requestId = '';
                state.draftId = '';
                state.draft = null;
                state.reviewNotice = '';
                state.editMode = false;
                hideAmbientResults();
            }
        }

        function renderConsentModal() {
            const errorMessage = state.modalError
                ? '<div class="copilot-ambient-modal-error">' + escapeHtml(state.modalError) + '</div>'
                : '';

            return [
                '<div class="copilot-ambient-modal-backdrop">',
                '  <section class="copilot-ambient-modal" role="dialog" aria-modal="true" aria-labelledby="copilot-ambient-consent-title">',
                '    <header class="copilot-ambient-modal-header">',
                '      <div>',
                '        <h3 id="copilot-ambient-consent-title">Confirm Patient Consent</h3>',
                '      </div>',
                '      <button type="button" class="copilot-ambient-modal-close" data-ambient-action="close-modal" aria-label="Close">&times;</button>',
                '    </header>',
                '    <div class="copilot-ambient-modal-body">',
                '      <p>“Before we begin, I’d like to use an AI assistant to help draft notes from today’s visit. It will not make medical decisions, and I will review anything before it becomes part of your record. Do I have your permission to use this tool during our appointment?”</p>',
                '      <label class="copilot-ambient-checkbox">',
                '        <input type="checkbox" id="copilot-ambient-consent-checkbox"' + (state.consentChecked ? ' checked' : '') + '>',
                '        <span>Patient gave verbal consent for AI-assisted note drafting.</span>',
                '      </label>',
                errorMessage,
                '    </div>',
                '    <footer class="copilot-ambient-modal-footer">',
                '      <button type="button" class="copilot-ambient-button copilot-ambient-button-secondary" data-ambient-action="close-modal">Cancel</button>',
                '      <button type="button" id="copilot-ambient-confirm-start" class="copilot-ambient-button copilot-ambient-button-primary" data-ambient-action="confirm-consent"' + (state.consentChecked ? '' : ' disabled') + '>Start Listening</button>',
                '    </footer>',
                '  </section>',
                '</div>'
            ].join('');
        }

        function renderReviewCards() {
            if (!state.draft) {
                return '';
            }

            return state.draft.groups.map(function (group) {
                const groupBody = group.items.map(function (item) {
                    const detail = item.detail
                        ? '<span class="copilot-ambient-review-detail">' + escapeHtml(item.detail) + '</span>'
                        : '';

                    return [
                        '<label class="copilot-ambient-review-check">',
                        '  <input type="checkbox" data-review-item-id="' + item.id + '"' + (item.checked ? ' checked' : '') + '>',
                        '  <span><strong>' + escapeHtml(item.label) + '</strong>' + detail + '</span>',
                        '</label>'
                    ].join('');
                }).join('');

                const extraIntro = group.intro ? '<p class="copilot-ambient-review-intro">' + escapeHtml(group.intro) + '</p>' : '';
                const editor = group.id === 'visit_history' && state.editMode ? [
                    '<div class="copilot-ambient-editor">',
                    '  <label class="copilot-ambient-editor-label" for="copilot-ambient-edit-textarea">Edit AI-drafted visit summary</label>',
                    '  <textarea id="copilot-ambient-edit-textarea" class="copilot-ambient-editor-textarea" rows="4">' + escapeHtml(state.editDraftValue) + '</textarea>',
                    '  <div class="copilot-ambient-editor-actions">',
                    '    <button type="button" class="copilot-ambient-button copilot-ambient-button-primary" data-ambient-action="save-edit">Save Draft Edits</button>',
                    '    <button type="button" class="copilot-ambient-button copilot-ambient-button-secondary" data-ambient-action="cancel-edit">Cancel</button>',
                    '  </div>',
                    '</div>'
                ].join('') : '';

                return [
                    '<article class="copilot-ambient-review-card">',
                    '  <h4>' + escapeHtml(group.title) + '</h4>',
                    extraIntro,
                    '  <div class="copilot-ambient-review-body">',
                    groupBody,
                    '  </div>',
                    editor,
                    '</article>'
                ].join('');
            }).join('');
        }

        function renderReviewModal() {
            const notice = state.reviewNotice
                ? '<div class="copilot-ambient-review-notice">' + state.reviewNotice + '</div>'
                : '';

            return [
                '<div class="copilot-ambient-modal-backdrop">',
                '  <section class="copilot-ambient-modal copilot-ambient-modal-wide" role="dialog" aria-modal="true" aria-labelledby="copilot-ambient-review-title">',
                '    <header class="copilot-ambient-modal-header">',
                '      <div>',
                '        <h3 id="copilot-ambient-review-title">AI Visit Review — Marcus Johnson</h3>',
                '        <p>Review extracted visit updates before adding anything to the medical record.</p>',
                '      </div>',
                '      <button type="button" class="copilot-ambient-modal-close" data-ambient-action="close-modal" aria-label="Close">&times;</button>',
                '    </header>',
                '    <div class="copilot-ambient-modal-body">',
                notice,
                renderReviewCards(),
                '      <p class="copilot-ambient-review-footnote">Draft only. Requires clinician review. Not medical advice. Not automatically written to the chart.</p>',
                '    </div>',
                '    <footer class="copilot-ambient-modal-footer">',
                '      <button type="button" class="copilot-ambient-button copilot-ambient-button-primary" data-ambient-action="approve-selected">Approve Selected</button>',
                '      <button type="button" class="copilot-ambient-button copilot-ambient-button-secondary" data-ambient-action="reject-selected">Reject Selected</button>',
                '      <button type="button" class="copilot-ambient-button copilot-ambient-button-secondary" data-ambient-action="edit-draft">Edit Draft</button>',
                '    </footer>',
                '  </section>',
                '</div>'
            ].join('');
        }

        function renderAuditModal() {
            const rows = state.auditEntries.map(function (entry) {
                return [
                    '<li class="copilot-ambient-audit-item">',
                    '  <div class="copilot-ambient-audit-event">' + escapeHtml(entry.event) + '</div>',
                    '  <div class="copilot-ambient-audit-meta">',
                    '    <span>' + escapeHtml(entry.timestamp) + '</span>',
                    entry.reviewStatus ? '<span>Status: ' + escapeHtml(entry.reviewStatus) + '</span>' : '',
                    entry.approvedItemCount !== '' ? '<span>Items: ' + escapeHtml(entry.approvedItemCount) + '</span>' : '',
                    '<span>Consent: ' + escapeHtml(entry.consentConfirmed) + '</span>',
                    '  </div>',
                    '</li>'
                ].join('');
            }).join('');

            return [
                '<div class="copilot-ambient-modal-backdrop">',
                '  <section class="copilot-ambient-modal" role="dialog" aria-modal="true" aria-labelledby="copilot-ambient-audit-title">',
                '    <header class="copilot-ambient-modal-header">',
                '      <div>',
                '        <h3 id="copilot-ambient-audit-title">AI Visit Review Audit Trail</h3>',
                '        <p>Safe metadata only. No transcript text is stored here.</p>',
                '      </div>',
                '      <button type="button" class="copilot-ambient-modal-close" data-ambient-action="close-modal" aria-label="Close">&times;</button>',
                '    </header>',
                '    <div class="copilot-ambient-modal-body">',
                '      <ul class="copilot-ambient-audit-list">' + rows + '</ul>',
                '    </div>',
                '    <footer class="copilot-ambient-modal-footer">',
                '      <button type="button" class="copilot-ambient-button copilot-ambient-button-secondary" data-ambient-action="close-modal">Close</button>',
                '    </footer>',
                '  </section>',
                '</div>'
            ].join('');
        }

        function renderModal() {
            if (!state.modalType) {
                closeModal();
                return;
            }

            let markup = '';
            if (state.modalType === 'consent') {
                markup = renderConsentModal();
            } else if (state.modalType === 'review') {
                markup = renderReviewModal();
            } else if (state.modalType === 'audit') {
                markup = renderAuditModal();
            }

            refs.modalRoot.innerHTML = markup;
            refs.modalRoot.hidden = false;
        }

        function openConsentModal() {
            const availability = availableState();
            if (!availability.enabled) {
                debugLog('ambient_capture_unavailable', {
                    actionType: 'mic_clicked'
                }, 'warn');
                return;
            }

            state.consentChecked = false;
            state.consentConfirmed = false;
            state.approvedSnapshot = null;
            state.draft = null;
            state.draftReady = false;
            state.modalError = '';
            revealAmbientResults('pending_consent');
            syncAmbientUi();
            logAuditEvent('copilot_ambient_capture_requested', {
                draftOnly: true,
                consentConfirmed: false,
                reviewStatus: 'requested'
            });
            debugLog('ambient_capture_requested', {
                actionType: 'mic_clicked'
            });
            openModal('consent');
            logAuditEvent('copilot_listening_consent_modal_opened', {
                draftOnly: true,
                consentConfirmed: false
            });
            debugLog('consent_modal_opened');
        }

        function confirmConsentAndStart() {
            debugLog('start_listening_clicked', {
                checked: state.consentChecked
            });

            if (!state.consentChecked) {
                return;
            }

            try {
                state.requestId = createId('visit');
                state.draftId = createId('draft');
                state.consentConfirmed = true;
                state.consentChecked = true;
                state.isListening = true;
                state.approvedSnapshot = null;
                state.draft = null;
                state.draftReady = false;
                state.reviewNotice = '';
                state.editMode = false;
                state.modalError = '';
                closeModal();
                syncAmbientUi();
                debugLog('listening_state_changed', {
                    isListening: true
                });
                logAuditEvent('copilot_listening_consent_confirmed', {
                    draftOnly: true,
                    consentConfirmed: true,
                    reviewStatus: 'consent_confirmed'
                });
                logAuditEvent('copilot_listening_started', {
                    draftOnly: true,
                    consentConfirmed: true,
                    reviewStatus: 'listening'
                });
            } catch (error) {
                state.isListening = false;
                state.modalError = 'Unable to start listening. Check browser console for local debug details.';
                renderModal();
                console.error('[Medical Co-Pilot Debug] ambient_listening_start_failed', error);
                debugLog('ambient_listening_start_failed', {
                    errorCategory: 'ambient_start_failure'
                }, 'error');
                logAuditEvent('copilot_listening_start_failed', {
                    draftOnly: true,
                    consentConfirmed: state.consentConfirmed,
                    reviewStatus: 'failed'
                });
            }
        }

        function stopListening() {
            if (!state.isListening) {
                return;
            }

            debugLog('stop_listening_clicked');
            state.isListening = false;
            state.draft = buildDraft();
            state.draftReady = true;
            state.reviewNotice = '';
            state.editMode = false;
            state.editDraftValue = state.draft.visitHistorySummary;
            syncAmbientUi();
            debugLog('listening_state_changed', {
                isListening: false
            });
            logAuditEvent('copilot_listening_stopped', {
                draftOnly: true,
                consentConfirmed: true,
                reviewStatus: 'stopped'
            });
            logAuditEvent('copilot_visit_draft_generated', {
                draftOnly: true,
                consentConfirmed: true,
                reviewStatus: 'draft_generated'
            });
            debugLog('visit_draft_generated', {
                reviewStatus: 'draft_generated'
            });
        }

        function openReviewModal() {
            if (!state.draft) {
                return;
            }

            state.reviewNotice = '';
            openModal('review');
            logAuditEvent('copilot_visit_review_opened', {
                draftOnly: true,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: 'pending'
            });
        }

        function rejectSelected() {
            const items = checkedItems();
            if (items.length === 0) {
                state.reviewNotice = 'No selected items are currently available to reject.';
                renderModal();
                return;
            }

            items.forEach(function (item) {
                item.checked = false;
            });
            state.reviewNotice = 'Selected draft items were rejected. You can approve the remaining checked items.';
            logAuditEvent('copilot_visit_draft_rejected', {
                draftOnly: true,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: 'rejected',
                approvedItemCount: items.length
            });
            renderModal();
        }

        function saveDraftEdit() {
            const textarea = document.getElementById('copilot-ambient-edit-textarea');
            const value = textarea ? textarea.value.trim() : '';
            if (!value || !state.draft) {
                return;
            }

            state.draft.visitHistorySummary = value;
            const visitSummaryItem = findItemById('visit_history_summary');
            if (visitSummaryItem) {
                visitSummaryItem.label = value;
            }
            state.editDraftValue = value;
            state.editMode = false;
            state.reviewNotice = 'Draft summary updated for clinician review.';
            logAuditEvent('copilot_visit_draft_edited', {
                draftOnly: true,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: 'edited'
            });
            renderModal();
        }

        function approveSelected() {
            const itemCount = checkedItems().length;
            if (itemCount === 0) {
                state.reviewNotice = 'Select at least one draft item before approving.';
                renderModal();
                return;
            }

            const approvedSnapshot = buildApprovedSnapshot();
            const visitRecord = buildApprovedVisitRecord(approvedSnapshot);
            const persistence = saveApprovedVisitRecord(visitRecord);

            approvedSnapshot.visitId = persistence.record ? persistence.record.id : visitRecord.id;
            approvedSnapshot.savedRecordCount = persistence.records.length;

            state.approvedSnapshot = approvedSnapshot;
            state.draftReady = false;
            closeModal();
            syncAmbientUi();

            debugLog('ambient_visit_record_saved', {
                draftId: state.draftId || null,
                visitId: approvedSnapshot.visitId || null,
                created: persistence.created,
                approvedItemCount: itemCount
            });

            logAuditEvent('copilot_visit_draft_approved', {
                draftOnly: false,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: 'clinician_reviewed',
                approvedItemCount: itemCount,
                draftId: state.draftId || null,
                visitId: approvedSnapshot.visitId || null
            });

            if (persistence.created) {
                logAuditEvent('copilot_demo_visit_history_record_created', {
                    draftOnly: false,
                    consentConfirmed: state.consentConfirmed,
                    reviewStatus: 'clinician_reviewed',
                    approvedItemCount: itemCount,
                    draftId: state.draftId || null,
                    visitId: approvedSnapshot.visitId || null
                });

                logAuditEvent('copilot_demo_visit_history_row_created', {
                    draftOnly: false,
                    consentConfirmed: state.consentConfirmed,
                    reviewStatus: 'clinician_reviewed',
                    approvedItemCount: itemCount,
                    draftId: state.draftId || null,
                    visitId: approvedSnapshot.visitId || null
                });

                dispatchApprovedVisitEvent({
                    patientKey: MARCUS_PATIENT_KEY,
                    draftId: state.draftId || null,
                    visitId: approvedSnapshot.visitId || null,
                    approvedAt: persistence.record ? persistence.record.approvedAt : approvedSnapshot.approvedAtIso,
                    reviewStatus: 'clinician_reviewed'
                });
            }

            logAuditEvent('copilot_dashboard_updates_rendered', {
                draftOnly: false,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: 'clinician_reviewed',
                approvedItemCount: itemCount,
                draftId: state.draftId || null,
                visitId: approvedSnapshot.visitId || null
            });
            logAuditEvent('copilot_visit_history_updated', {
                draftOnly: false,
                consentConfirmed: state.consentConfirmed,
                reviewStatus: 'clinician_reviewed',
                approvedItemCount: itemCount,
                draftId: state.draftId || null,
                visitId: approvedSnapshot.visitId || null
            });
        }

        function handleSectionClick(event) {
            const action = event.target.closest('[data-ambient-action]');
            if (!action) {
                return;
            }

            const actionType = action.getAttribute('data-ambient-action');
            if (actionType === 'stop-listening') {
                stopListening();
            } else if (actionType === 'review-draft') {
                openReviewModal();
            } else if (actionType === 'view-review-details') {
                openReviewModal();
            } else if (actionType === 'view-audit-trail') {
                openModal('audit');
            }
        }

        refs.micButton.addEventListener('click', function () {
            debugLog('mic_clicked', {
                isListening: state.isListening
            });
            if (state.isListening) {
                stopListening();
                return;
            }

            openConsentModal();
        });

        refs.statusMount.addEventListener('click', handleSectionClick);
        refs.mount.addEventListener('click', handleSectionClick);

        refs.modalRoot.addEventListener('click', function (event) {
            if (event.target === event.currentTarget || event.target.classList.contains('copilot-ambient-modal-backdrop')) {
                return;
            }

            const action = event.target.closest('[data-ambient-action]');
            if (!action) {
                return;
            }

            const actionType = action.getAttribute('data-ambient-action');
            if (actionType === 'close-modal') {
                closeModal();
                return;
            }
            if (actionType === 'confirm-consent') {
                confirmConsentAndStart();
                return;
            }
            if (actionType === 'approve-selected') {
                approveSelected();
                return;
            }
            if (actionType === 'reject-selected') {
                rejectSelected();
                return;
            }
            if (actionType === 'edit-draft') {
                state.editMode = true;
                state.editDraftValue = state.draft ? state.draft.visitHistorySummary : '';
                renderModal();
                return;
            }
            if (actionType === 'save-edit') {
                saveDraftEdit();
                return;
            }
            if (actionType === 'cancel-edit') {
                state.editMode = false;
                state.reviewNotice = '';
                renderModal();
            }
        });

        refs.modalRoot.addEventListener('change', function (event) {
            if (event.target && event.target.id === 'copilot-ambient-consent-checkbox') {
                state.consentChecked = event.target.checked;
                debugLog('consent_checkbox_changed', {
                    checked: state.consentChecked
                });
                const confirmButton = document.getElementById('copilot-ambient-confirm-start');
                if (confirmButton) {
                    confirmButton.disabled = !state.consentChecked;
                }
                return;
            }

            const reviewItemId = event.target && event.target.getAttribute('data-review-item-id');
            if (reviewItemId) {
                const item = findItemById(reviewItemId);
                if (item) {
                    item.checked = Boolean(event.target.checked);
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && state.modalType) {
                event.preventDefault();
                event.stopPropagation();
                closeModal();
            }
        });

        patientSelect.addEventListener('change', syncAmbientUi);
        roleSelect.addEventListener('change', syncAmbientUi);
        modeSelect.addEventListener('change', syncAmbientUi);

        syncAmbientUi();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVisitReviewDemo, { once: true });
    } else {
        initVisitReviewDemo();
    }
}());
