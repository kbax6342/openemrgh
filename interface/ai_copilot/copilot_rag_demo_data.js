(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.OpenEMRCopilotRagDemo = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const MARCUS_PATIENT_KEY = 'DEMO-PCP-1001';
    const STORAGE_KEY = 'openemr_ai_copilot_demo_visits_DEMO-PCP-1001';
    const MARCUS_CONTEXT = {
        patientKey: MARCUS_PATIENT_KEY,
        patientName: 'Marcus Johnson',
        issues: [
            'Medication adherence support',
            'Lab follow-up',
            'Insurance verification',
            'Immunization review',
            'Care coordination'
        ],
        activeMedications: [
            'Metformin',
            'Glipizide',
            'Gabapentin'
        ],
        labFollowUp: [
            'Updated A1C follow-up is needed.',
            'Updated lipid panel follow-up was discussed.'
        ],
        recentVitals: [
            'Recent rooming vitals were reviewed during follow-up.',
            'Elevated glucose trend remains part of the chart context.'
        ],
        insuranceNote: [
            'Patient reported a recent insurance change that still needs verification.'
        ],
        immunizationReview: [
            'Seasonal vaccine status should be verified before updating the record.'
        ],
        carePreferences: [
            'Prefers afternoon phone reminders.',
            'Prefers written medication instructions.'
        ],
        careTeam: [
            'Patient requested his daughter be added as a care support contact.'
        ]
    };

    function safeLocalStorage() {
        try {
            return window.localStorage;
        } catch (error) {
            return null;
        }
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

    function normalizeAmbientVisit(record) {
        if (!record || typeof record !== 'object') {
            return null;
        }

        const approvedAt = record.approvedAt || new Date().toISOString();
        return {
            id: record.id || '',
            draftId: record.draftId || '',
            approvedAt: approvedAt,
            approvedAtLabel: record.approvedAtLabel || formatLocalDateTime(approvedAt),
            summary: record.summary || '',
            approvedNotes: Array.isArray(record.approvedNotes) ? record.approvedNotes.slice() : [],
            reviewStatus: record.reviewStatus || 'Clinician Reviewed',
            consentConfirmed: Boolean(record.consentConfirmed),
            source: record.source || 'Consent-Based AI Visit Capture'
        };
    }

    function readApprovedAmbientVisits(patientKey) {
        if (patientKey !== MARCUS_PATIENT_KEY) {
            return [];
        }

        const storage = safeLocalStorage();
        if (!storage) {
            return [];
        }

        try {
            const raw = storage.getItem(STORAGE_KEY);
            if (!raw) {
                return [];
            }

            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return [];
            }

            return parsed
                .map(normalizeAmbientVisit)
                .filter(Boolean)
                .sort(function (left, right) {
                    return new Date(right.approvedAt).getTime() - new Date(left.approvedAt).getTime();
                });
        } catch (error) {
            return [];
        }
    }

    function latestApprovedAmbientVisit(patientKey) {
        return readApprovedAmbientVisits(patientKey)[0] || null;
    }

    function buildSources(latestVisitFound) {
        const sources = [];
        if (latestVisitFound) {
            sources.push({
                title: 'Visit History: AI-Assisted Visit Review / Ambient Encounter Capture',
                category: 'visit_history'
            });
        }

        return sources.concat([
            {
                title: 'Active Medications',
                category: 'medications'
            },
            {
                title: 'Lab Follow-up',
                category: 'labs'
            },
            {
                title: 'Recent Vitals',
                category: 'vitals'
            },
            {
                title: 'Insurance Note',
                category: 'insurance'
            },
            {
                title: 'Immunization Review',
                category: 'immunizations'
            },
            {
                title: 'Care Preferences',
                category: 'care_preferences'
            },
            {
                title: 'Care Team',
                category: 'care_team'
            },
            {
                title: 'Issues / Problem List',
                category: 'problem_list'
            }
        ]);
    }

    function retrieveDoctorChartContext(options) {
        const patientKey = String(options && options.patientKey ? options.patientKey : '').trim();
        const role = String(options && options.role ? options.role : 'doctor').toLowerCase();
        if (patientKey !== MARCUS_PATIENT_KEY || role !== 'doctor') {
            return {
                patientKey: patientKey,
                patientName: '',
                latestAmbientVisitFound: false,
                latestAmbientVisit: null,
                sources: [],
                sourceTitles: [],
                sourceCategories: [],
                context: {}
            };
        }

        const latestVisit = latestApprovedAmbientVisit(patientKey);
        const sources = buildSources(Boolean(latestVisit));

        return {
            patientKey: patientKey,
            patientName: MARCUS_CONTEXT.patientName,
            latestAmbientVisitFound: Boolean(latestVisit),
            latestAmbientVisit: latestVisit,
            sources: sources,
            sourceTitles: sources.map(function (source) {
                return source.title;
            }),
            sourceCategories: sources.map(function (source) {
                return source.category;
            }),
            context: {
                issues: MARCUS_CONTEXT.issues.slice(),
                activeMedications: MARCUS_CONTEXT.activeMedications.slice(),
                labFollowUp: MARCUS_CONTEXT.labFollowUp.slice(),
                recentVitals: MARCUS_CONTEXT.recentVitals.slice(),
                insuranceNote: MARCUS_CONTEXT.insuranceNote.slice(),
                immunizationReview: MARCUS_CONTEXT.immunizationReview.slice(),
                carePreferences: MARCUS_CONTEXT.carePreferences.slice(),
                careTeam: MARCUS_CONTEXT.careTeam.slice(),
                latestAmbientVisit: latestVisit
            }
        };
    }

    return {
        patientKey: MARCUS_PATIENT_KEY,
        patientName: MARCUS_CONTEXT.patientName,
        readApprovedAmbientVisits: readApprovedAmbientVisits,
        latestApprovedAmbientVisit: latestApprovedAmbientVisit,
        retrieveDoctorChartContext: retrieveDoctorChartContext
    };
}));
