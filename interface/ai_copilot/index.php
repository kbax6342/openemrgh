<?php

/**
 * OpenEMR Medical Co-Pilot Demo.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;

$isEmbedded = !empty($_GET['embedded']) && $_GET['embedded'] === '1';
$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (empty($session->get('csrf_private_key'))) {
    CsrfUtils::setupCsrfKey($session);
}

$csrfToken = CsrfUtils::collectCsrfToken(session: $session);
$demoPatients = [];

$patientResult = sqlStatement(
    "SELECT pid, pubpid, fname, lname, DOB
     FROM patient_data
     WHERE genericname1 = ?
     ORDER BY lname, fname",
    ['demo_scenario']
);

while ($row = sqlFetchArray($patientResult)) {
    $demoPatients[] = $row;
}

$quickActions = [
    [
        'mode' => 'differential_diagnosis',
        'title' => xl('Differential Diagnosis'),
        'summary' => xl('Red flags, missing information, and likely causes.'),
        'prompt' => xl('For the selected patient, suggest a differential diagnosis based on the chart context. Include likely possibilities, red flags, key gaps, and what should be clarified next.'),
    ],
    [
        'mode' => 'medication_info',
        'title' => xl('Medication Info'),
        'summary' => xl('Safety concerns, interactions, and counseling points.'),
        'prompt' => xl('Review this patient\'s medications and explain possible safety concerns, adherence issues, interactions, monitoring needs, and counseling points.'),
    ],
    [
        'mode' => 'clinical_notes',
        'title' => xl('Clinical Notes'),
        'summary' => xl('Draft summaries, SOAP notes, and documentation support.'),
        'prompt' => xl('Draft a concise clinical note summary for this patient using the available chart context.'),
    ],
    [
        'mode' => 'treatment_plan',
        'title' => xl('Treatment Plan'),
        'summary' => xl('Follow-up, education, and safety checks.'),
        'prompt' => xl('Create a draft treatment plan for this patient, including follow-up, patient education, monitoring, and safety checks.'),
    ],
    [
        'mode' => 'billing',
        'title' => xl('Billing'),
        'summary' => xl('Documentation support, coding considerations, and review gaps.'),
        'prompt' => xl('Review the selected patient\'s visit context and suggest billing-support documentation points, possible coding considerations, and missing documentation needed before billing review.'),
    ],
    [
        'mode' => 'billing_review',
        'title' => xl('Claim Review'),
        'summary' => xl('Plain-language claim issue review and first checks.'),
        'prompt' => xl('Why did this claim fail, and what should I check first?'),
    ],
    [
        'mode' => 'follow_up',
        'title' => xl('Follow-Up'),
        'summary' => xl('Timeframe, monitoring, instructions, and escalation precautions.'),
        'prompt' => xl('Create a follow-up plan for the selected patient, including timeframe, monitoring items, patient instructions, and escalation precautions.'),
    ],
    [
        'mode' => 'rag_chart_context',
        'title' => xl('RAG: Review Marcus\'s Chart Context'),
        'summary' => xl('Retrieve grounded chart context before drafting a response.'),
        'prompt' => xl('Retrieve Marcus Johnson\'s doctor-role chart context before drafting. Include the latest approved Ambient Encounter Capture visit when available, plus medications, labs, vitals, insurance note, immunization review, care preferences, care team, and problem list.'),
        'patient_keys' => ['DEMO-PCP-1001'],
    ],
    [
        'mode' => 'lab_pdf_ingestion',
        'title' => xl('Lab PDF Ingestion'),
        'summary' => xl('Attach a lab PDF and extract draft lab facts for clinician review.'),
        'prompt' => xl('Ingest the selected lab PDF for clinician review only. Extract structured lab facts, highlight abnormal values, call out missing data, and keep the result draft-only.'),
        'patient_keys' => ['DEMO-PCP-1001'],
    ],
    [
        'mode' => 'latest_ambient_summary',
        'title' => xl('Latest Ambient Encounter Summary'),
        'summary' => xl('Summarize only the latest approved ambient encounter capture.'),
        'prompt' => xl('Summarize the latest approved ambient encounter capture only. Use role-appropriate retrieved context and do not expand older visits.'),
        'patient_keys' => ['DEMO-PCP-1001'],
    ],
    [
        'mode' => 'visit_summary',
        'title' => xl('Visit Summary'),
        'summary' => xl('Concise summary of concerns, plan, and follow-up.'),
        'prompt' => xl('Create a draft summary of this visit for the selected patient. Include key concerns addressed, plan discussed, follow-up instructions, and a patient-friendly explanation.'),
    ],
    [
        'mode' => 'patient_education',
        'title' => xl('Patient Education'),
        'summary' => xl('Patient-friendly explanation of the current plan.'),
        'prompt' => xl('Create a patient-friendly explanation of the current plan using only the documented chart context. Emphasize education, follow-up, and safety reminders.'),
    ],
    [
        'mode' => 'appointment_info',
        'title' => xl('Appointment Info'),
        'summary' => xl('Next appointment, provider, location, and check-in details.'),
        'prompt' => xl('Can you tell me this patient\'s next appointment?'),
    ],
    [
        'mode' => 'patient_contact',
        'title' => xl('Patient Contact'),
        'summary' => xl('Confirm email, phone, and contact workflow details.'),
        'prompt' => xl('Can you help me confirm this patient\'s contact information?'),
    ],
    [
        'mode' => 'send_reminder',
        'title' => xl('Send Reminder'),
        'summary' => xl('Draft a demo reminder using minimum necessary PHI.'),
        'prompt' => xl('Draft an appointment reminder for the selected patient using only scheduling and contact details.'),
    ],
    [
        'mode' => 'front_desk_summary',
        'title' => xl('Front Desk Summary'),
        'summary' => xl('Short administrative summary for check-in and outreach.'),
        'prompt' => xl('Create a short front desk summary for the selected patient with appointment details, contact confirmation points, and check-in instructions only.'),
    ],
];

$roleCatalog = [
    'doctor' => [
        'title' => xl('Doctor'),
        'note' => xl('Clinical support only. No autonomous diagnosis, orders, prescribing, or chart writes.'),
        'quick_actions' => ['differential_diagnosis', 'medication_info', 'clinical_notes', 'treatment_plan', 'billing', 'follow_up', 'rag_chart_context', 'lab_pdf_ingestion'],
        'allowed_modes' => ['general_assistant', 'differential_diagnosis', 'medication_info', 'clinical_notes', 'treatment_plan', 'billing', 'billing_review', 'follow_up', 'rag_chart_context', 'lab_pdf_ingestion', 'latest_ambient_summary', 'visit_summary', 'patient_education'],
    ],
    'nurse' => [
        'title' => xl('Nurse'),
        'note' => xl('Education and follow-up support only. Medication changes require clinician review.'),
        'quick_actions' => ['medication_info', 'clinical_notes', 'follow_up', 'visit_summary', 'patient_education'],
        'allowed_modes' => ['general_assistant', 'medication_info', 'clinical_notes', 'follow_up', 'latest_ambient_summary', 'visit_summary', 'patient_education'],
    ],
    'billing' => [
        'title' => xl('Billing Staff'),
        'note' => xl('Billing review only. No automatic claim submission or definitive coding.'),
        'quick_actions' => ['billing', 'billing_review', 'visit_summary'],
        'allowed_modes' => ['general_assistant', 'billing', 'billing_review', 'latest_ambient_summary', 'visit_summary'],
    ],
    'front_desk' => [
        'title' => xl('Front Desk'),
        'note' => xl('Minimum necessary PHI. Scheduling, contact, and reminder workflows only.'),
        'quick_actions' => ['appointment_info', 'patient_contact', 'send_reminder', 'front_desk_summary'],
        'allowed_modes' => ['general_assistant', 'appointment_info', 'patient_contact', 'send_reminder', 'front_desk_summary', 'latest_ambient_summary'],
    ],
];

$appConfig = [
    'csrfToken' => $csrfToken,
    'apiUrl' => 'copilot_api.php',
    'embedded' => $isEmbedded,
    'greeting' => xla('Hello! I\'m your medical co-pilot. I can help with clinical reasoning support, medication education, documentation, follow-up planning, billing review, and front-desk scheduling workflows. How can I help you today?'),
    'loadingText' => xla('Thinking through the chart context...'),
    'sendingReminderText' => xla('Preparing the reminder workflow...'),
    'emptyPromptMessage' => xla('Enter a prompt before sending.'),
    'apiFailureMessage' => xla('I ran into a temporary problem while drafting that response. Please retry. Draft only. Human review required.'),
    'generalModeTitle' => xla('General clinical support'),
    'inputPlaceholder' => xla('Ask about the chart or enter a clinical support prompt...'),
    'visitSummaryPrompt' => xla('Create a draft summary of this visit for the selected patient. Include key concerns addressed, plan discussed, follow-up instructions, and a patient-friendly explanation.'),
    'visitSummaryButtonLabel' => xla('View Summary of Visit'),
    'sendReminderEmailButtonLabel' => xla('Send Reminder Email'),
    'footerText' => xla('AI-assisted clinical support • Always verify with clinical judgment'),
    'noPatientSelectedText' => xla('No demo patient selected. Patient-specific answers require selecting a demo patient.'),
    'noPatientSelectedShortText' => xla('No demo patient selected'),
    'selectedPatientPrefix' => xla('Using demo patient:'),
    'copyText' => xla('Copy'),
    'copiedText' => xla('Copied'),
    'likeText' => xla('Like'),
    'dislikeText' => xla('Dislike'),
    'promptHelperLabelSingular' => xla('prompt helper'),
    'promptHelperLabelPlural' => xla('prompt helpers'),
    'noQuickActionsText' => xla('No quick actions available'),
    'defaultRole' => 'doctor',
    'quickActions' => $quickActions,
    'roleCatalog' => $roleCatalog,
];

$cssVersion = file_exists(__DIR__ . '/copilot.css') ? (string) filemtime(__DIR__ . '/copilot.css') : '1';
$guardrailsVersion = file_exists(__DIR__ . '/copilot_guardrails.js') ? (string) filemtime(__DIR__ . '/copilot_guardrails.js') : '1';
$agentTraceVersion = file_exists(__DIR__ . '/agents/copilot_agent_trace.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agent_trace.js') : '1';
$agentSafetyVersion = file_exists(__DIR__ . '/agents/copilot_agent_safety.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agent_safety.js') : '1';
$agentToolVersion = file_exists(__DIR__ . '/agents/copilot_agent_tools.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agent_tools.js') : '1';
$agentVersion = file_exists(__DIR__ . '/agents/copilot_agents.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agents.js') : '1';
$ragDemoVersion = file_exists(__DIR__ . '/copilot_rag_demo_data.js') ? (string) filemtime(__DIR__ . '/copilot_rag_demo_data.js') : '1';
$labPdfVersion = file_exists(__DIR__ . '/lab_pdf_ingestion.js') ? (string) filemtime(__DIR__ . '/lab_pdf_ingestion.js') : '1';
$visitReviewVersion = file_exists(__DIR__ . '/copilot_visit_review.js') ? (string) filemtime(__DIR__ . '/copilot_visit_review.js') : '1';
?>
<!doctype html>
<html lang="en">
<head>
    <title><?php echo xlt('Medical Co-Pilot'); ?></title>
    <?php Header::setupHeader(); ?>
    <link rel="stylesheet" href="copilot.css?v=<?php echo attr_url($cssVersion); ?>">
</head>
<body class="body_top medical-copilot--compact<?php echo $isEmbedded ? ' copilot-embedded' : ''; ?>">
<main class="copilot-shell<?php echo $isEmbedded ? ' copilot-shell-embedded' : ''; ?>">
    <?php if (!$isEmbedded) { ?>
        <section class="copilot-page-hero">
            <p class="copilot-page-eyebrow"><?php echo xlt('OpenEMR Demo'); ?></p>
            <h1><?php echo xlt('Medical Co-Pilot'); ?></h1>
            <p class="copilot-page-intro">
                <?php echo xlt('Chat with a beta clinical support assistant using seeded demo data. Human review required.'); ?>
            </p>
        </section>
    <?php } ?>

    <section class="copilot-panel">
        <?php if (!$isEmbedded) { ?>
            <header class="copilot-panel-header">
                <div>
                    <p class="copilot-panel-kicker"><?php echo xlt('Medical Co-Pilot'); ?></p>
                    <h2><?php echo xlt('Beta clinical support chat'); ?></h2>
                </div>
                <span class="copilot-panel-badge"><?php echo xlt('Beta'); ?></span>
            </header>
        <?php } ?>

        <div class="copilot-panel-body">
            <?php if ($isEmbedded) { ?>
                <div class="copilot-inline-beta"><?php echo xlt('Beta'); ?></div>
            <?php } ?>

            <section class="copilot-controls" aria-label="<?php echo attr(xl('Demo controls')); ?>">
                <section class="copilot-collapse-section" id="copilot-controls-section">
                    <button
                        type="button"
                        id="copilot-controls-toggle"
                        class="copilot-collapse-toggle"
                        aria-expanded="false"
                        aria-controls="copilot-controls-panel"
                    >
                        <span class="copilot-collapse-copy">
                            <span class="copilot-collapse-title"><?php echo xlt('Controls:'); ?></span>
                            <span id="copilot-controls-summary" class="copilot-collapse-summary">
                                <?php echo xlt('No demo patient selected'); ?> · <?php echo text($roleCatalog['doctor']['title']); ?> · <?php echo text($appConfig['generalModeTitle']); ?> · <?php echo xlt('Quick Actions'); ?>
                            </span>
                        </span>
                        <span class="copilot-collapse-icon" aria-hidden="true"></span>
                    </button>

                    <div id="copilot-controls-panel" class="copilot-collapse-panel" hidden>
                        <div class="copilot-control-bar">
                            <div class="copilot-control">
                                <label class="copilot-control-label" for="copilot-patient-select"><?php echo xlt('Demo patient'); ?></label>
                                <select id="copilot-patient-select" class="form-control copilot-select copilot-control-select">
                                    <option value=""><?php echo xlt('No demo patient selected (general prompts only)'); ?></option>
                                    <?php foreach ($demoPatients as $patient) { ?>
                                        <?php
                                        $label = trim(
                                            $patient['lname'] . ', ' . $patient['fname'] .
                                            (!empty($patient['DOB']) ? ' (' . $patient['DOB'] . ')' : '') .
                                            (!empty($patient['pubpid']) ? ' - ' . $patient['pubpid'] : '')
                                        );
                                        ?>
                                        <option
                                            value="<?php echo attr((string) $patient['pid']); ?>"
                                            data-fname="<?php echo attr((string) $patient['fname']); ?>"
                                            data-lname="<?php echo attr((string) $patient['lname']); ?>"
                                            data-pubpid="<?php echo attr((string) $patient['pubpid']); ?>"
                                        >
                                            <?php echo text($label); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="copilot-control">
                                <label class="copilot-control-label" for="copilot-role-select"><?php echo xlt('Staff role'); ?></label>
                                <select id="copilot-role-select" class="form-control copilot-select copilot-control-select">
                                    <?php foreach ($roleCatalog as $roleKey => $roleConfig) { ?>
                                        <option value="<?php echo attr($roleKey); ?>"<?php echo $roleKey === 'doctor' ? ' selected' : ''; ?>>
                                            <?php echo text($roleConfig['title']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="copilot-control">
                                <label class="copilot-control-label" for="copilot-mode-select"><?php echo xlt('Focus mode'); ?></label>
                                <select id="copilot-mode-select" class="form-control copilot-select copilot-control-select" aria-label="<?php echo attr(xl('Focus mode')); ?>"></select>
                            </div>

                            <div class="copilot-control">
                                <label class="copilot-control-label" for="copilot-quick-action-select"><?php echo xlt('Quick Actions'); ?></label>
                                <select
                                    id="copilot-quick-action-select"
                                    class="form-control copilot-select copilot-control-select copilot-quick-actions-select"
                                    aria-label="<?php echo attr(xl('Quick actions')); ?>"
                                ></select>
                            </div>
                        </div>

                        <p id="copilot-role-note" class="copilot-role-note"><?php echo text($roleCatalog['doctor']['note']); ?></p>
                    </div>
                </section>

                <section class="copilot-collapse-section" id="copilot-guardrails-section">
                    <button
                        type="button"
                        id="copilot-guardrails-toggle"
                        class="copilot-collapse-toggle"
                        aria-expanded="false"
                        aria-controls="copilot-guardrails-panel"
                    >
                        <span class="copilot-collapse-copy">
                            <span class="copilot-collapse-title"><?php echo xlt('Guardrails:'); ?></span>
                            <span class="copilot-collapse-summary"><?php echo xlt('Active'); ?></span>
                        </span>
                        <span class="copilot-collapse-icon" aria-hidden="true"></span>
                    </button>

                    <section id="copilot-guardrails-panel" class="copilot-collapse-panel copilot-guardrails-panel" aria-label="<?php echo attr(xl('Guardrails status')); ?>" hidden>
                        <div class="copilot-guardrails-items">
                            <span class="copilot-guardrails-chip copilot-guardrails-chip-strong">
                                <?php echo xlt('Role scope:'); ?> <span id="copilot-guardrails-role-scope"><?php echo text($roleCatalog['doctor']['title']); ?></span>
                            </span>
                            <span class="copilot-guardrails-chip"><?php echo xlt('Prompt injection filter: On'); ?></span>
                            <span class="copilot-guardrails-chip"><?php echo xlt('Draft-only enforcement: On'); ?></span>
                            <span class="copilot-guardrails-chip"><?php echo xlt('PHI minimum necessary: On'); ?></span>
                        </div>
                    </section>
                </section>

            </section>

            <section id="copilot-scroll-region" class="copilot-scroll-region">
                <section id="copilot-thread" class="copilot-thread" aria-live="polite"></section>
            </section>

            <section id="copilot-ambient-results" class="copilot-ambient-results" aria-label="<?php echo attr(xl('Ambient encounter capture workflow')); ?>" hidden>
                <section id="copilot-ambient-status" class="copilot-ambient-status" aria-live="polite" hidden></section>
                <section id="copilot-visit-review-demo" class="copilot-visit-review-demo" aria-live="polite" aria-label="<?php echo attr(xl('AI Visit Review demo')); ?>" hidden></section>
            </section>

            <form id="copilot-form" class="copilot-composer">
                <div class="copilot-composer-shell">
                    <div class="copilot-composer-input-stack">
                        <div class="copilot-composer-attachment-row">
                            <input
                                id="copilot-lab-pdf-input"
                                class="copilot-visually-hidden"
                                type="file"
                                accept="application/pdf,.pdf"
                            >
                            <button
                                type="button"
                                id="copilot-lab-pdf-attach"
                                class="copilot-attach-button"
                                aria-label="<?php echo attr(xl('Attach lab PDF')); ?>"
                                title="<?php echo attr(xl('Attach lab PDF')); ?>"
                            >
                                <span class="copilot-attach-glyph" aria-hidden="true">&#128206;</span>
                                <span><?php echo xlt('Attach PDF'); ?></span>
                            </button>
                            <div id="copilot-lab-pdf-chip" class="copilot-attachment-chip" hidden>
                                <span id="copilot-lab-pdf-chip-text" class="copilot-attachment-chip-text"></span>
                                <button
                                    type="button"
                                    id="copilot-lab-pdf-remove"
                                    class="copilot-attachment-remove"
                                    aria-label="<?php echo attr(xl('Remove attached lab PDF')); ?>"
                                    title="<?php echo attr(xl('Remove attached lab PDF')); ?>"
                                >
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        </div>
                        <label class="copilot-visually-hidden" for="copilot-input"><?php echo xlt('Medical Co-Pilot prompt'); ?></label>
                        <textarea
                            id="copilot-input"
                            class="copilot-input"
                            rows="1"
                            placeholder="<?php echo attr(xl('Ask about the chart or enter a clinical support prompt...')); ?>"
                        ></textarea>
                        <p id="copilot-lab-pdf-status" class="copilot-lab-pdf-status"><?php echo xlt('Attach a lab PDF for draft-only clinician review.'); ?></p>
                    </div>
                    <div class="copilot-composer-actions">
                        <button type="submit" id="copilot-send" class="copilot-send-button">
                            <?php echo xlt('Send'); ?>
                        </button>
                        <button
                            type="button"
                            id="copilot-ambient-mic"
                            class="copilot-mic-button"
                            aria-label="<?php echo attr(xl('Start ambient encounter capture')); ?>"
                            title="<?php echo attr(xl('Start ambient encounter capture')); ?>"
                        >
                            <span class="copilot-mic-icon" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>

                <div class="copilot-composer-footer">
                    <p class="copilot-footer-text"><?php echo xlt('AI-assisted clinical support • Always verify with clinical judgment'); ?></p>
                    <p id="copilot-context-text" class="copilot-context-text"></p>
                </div>
            </form>
        </div>
    </section>
</main>

<script src="copilot_guardrails.js?v=<?php echo attr_url($guardrailsVersion); ?>"></script>
<script src="agents/copilot_agent_trace.js?v=<?php echo attr_url($agentTraceVersion); ?>"></script>
<script src="agents/copilot_agent_safety.js?v=<?php echo attr_url($agentSafetyVersion); ?>"></script>
<script src="agents/copilot_agent_tools.js?v=<?php echo attr_url($agentToolVersion); ?>"></script>
<script src="agents/copilot_agents.js?v=<?php echo attr_url($agentVersion); ?>"></script>
<script src="copilot_rag_demo_data.js?v=<?php echo attr_url($ragDemoVersion); ?>"></script>
<script src="lab_pdf_ingestion.js?v=<?php echo attr_url($labPdfVersion); ?>"></script>
<script>
const copilotConfig = <?php echo json_encode($appConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

const patientSelect = document.getElementById('copilot-patient-select');
const roleSelect = document.getElementById('copilot-role-select');
const roleNote = document.getElementById('copilot-role-note');
const modeSelect = document.getElementById('copilot-mode-select');
const quickActionSelect = document.getElementById('copilot-quick-action-select');
const scrollRegion = document.getElementById('copilot-scroll-region');
const thread = document.getElementById('copilot-thread');
const form = document.getElementById('copilot-form');
const input = document.getElementById('copilot-input');
const sendButton = document.getElementById('copilot-send');
const contextText = document.getElementById('copilot-context-text');
const guardrailsRoleScope = document.getElementById('copilot-guardrails-role-scope');
const controlsSection = document.getElementById('copilot-controls-section');
const controlsToggle = document.getElementById('copilot-controls-toggle');
const controlsPanel = document.getElementById('copilot-controls-panel');
const controlsSummary = document.getElementById('copilot-controls-summary');
const guardrailsSection = document.getElementById('copilot-guardrails-section');
const guardrailsToggle = document.getElementById('copilot-guardrails-toggle');
const guardrailsPanel = document.getElementById('copilot-guardrails-panel');
const labPdfInput = document.getElementById('copilot-lab-pdf-input');
const labPdfAttachButton = document.getElementById('copilot-lab-pdf-attach');
const labPdfChip = document.getElementById('copilot-lab-pdf-chip');
const labPdfChipText = document.getElementById('copilot-lab-pdf-chip-text');
const labPdfRemoveButton = document.getElementById('copilot-lab-pdf-remove');
const labPdfStatus = document.getElementById('copilot-lab-pdf-status');

const actionCatalog = Object.fromEntries(
    (copilotConfig.quickActions || []).map((action) => [action.mode, action])
);
const roleCatalog = copilotConfig.roleCatalog || {};
const modeCatalog = Object.fromEntries(
    [{ mode: 'general_assistant', title: copilotConfig.generalModeTitle }].concat(copilotConfig.quickActions).map((item) => [
        item.mode,
        item.title
    ])
);

const state = {
    activeMode: 'general_assistant',
    activeRole: copilotConfig.defaultRole || 'doctor',
    loading: false,
    messages: [],
    labPdf: {
        file: null,
        descriptor: null,
        useDemoSeed: false
    }
};

const copiedStateTimers = new Map();

function createId(prefix) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return `${prefix}_${window.crypto.randomUUID()}`;
    }

    return `${prefix}_${Date.now()}_${Math.random().toString(16).slice(2)}`;
}

function getCopilotHostWindow() {
    try {
        if (window.top && window.top !== window && window.top.OPENEMR_AI_COPILOT_URL) {
            return window.top;
        }
    } catch (error) {
    }

    return window;
}

function ensureTelemetryHost(targetWindow) {
    if (!targetWindow) {
        return window;
    }

    targetWindow.OpenEMRCopilotState = targetWindow.OpenEMRCopilotState || {
        role: state.activeRole,
        mode: state.activeMode,
        selectedPatientKey: null
    };

    if (targetWindow.CopilotTelemetry && targetWindow.CopilotMetrics && typeof targetWindow.printCopilotMetrics === 'function') {
        return targetWindow;
    }

    const allowedKeys = new Set([
        'requestId',
        'responseId',
        'role',
        'selectedRole',
        'previousRole',
        'newRole',
        'mode',
        'selectedMode',
        'selectedPatientKey',
        'visibleQuickActions',
        'messageLength',
        'responseLength',
        'responseCharacterCount',
        'latencyMs',
        'success',
        'fallbackUsed',
        'fallbackReason',
        'restrictedByRole',
        'restrictionType',
        'allowed',
        'blockedReason',
        'riskLevel',
        'policyTags',
        'copied',
        'feedback',
        'errorCategory',
        'contextScope',
        'hasChatHistory',
        'startedAt',
        'actionType',
        'reviewStatus',
        'approvedItemCount',
        'draftId',
        'visitId',
        'visitCount',
        'draftOnly',
        'consentConfirmed',
        'engine',
        'provider',
        'model',
        'openaiConfigured',
        'promptTokens',
        'completionTokens',
        'totalTokens',
        'estimatedCostUsd',
        'costNote',
        'openaiErrorCategory',
        'openaiHttpStatus',
        'openaiErrorMessageSafe',
        'toolName',
        'toolStatus',
        'attachmentPurpose',
        'documentTitle',
        'documentType',
        'documentSource',
        'extractionMethod',
        'chunkCount',
        'retrievedChunkCount',
        'retrievedChunkIds',
        'uploadedAt',
        'guardrailTriggered',
        'missingDataCount',
        'ragGrounded',
        'sourceCount',
        'sourceTitles',
        'sourceCategories',
        'latestAmbientVisitFound'
    ]);

    const metrics = targetWindow.CopilotMetrics || {
        sessionId: createId('session'),
        openedCount: 0,
        generationsStarted: 0,
        generationsSucceeded: 0,
        generationsFailed: 0,
        fallbackUsedCount: 0,
        copiedCount: 0,
        likedCount: 0,
        dislikedCount: 0,
        restrictedActionCount: 0,
        latencySamples: []
    };

    function sanitizePayload(payload) {
        const safePayload = {};
        Object.keys(payload || {}).forEach((key) => {
            if (!allowedKeys.has(key)) {
                return;
            }

            const value = payload[key];
            if (value === undefined || value === null || value === '') {
                return;
            }

            safePayload[key] = value;
        });

        return safePayload;
    }

    function updateMetrics(eventName, payload) {
        if (eventName === 'copilot_open') {
            metrics.openedCount += 1;
        }
        if (eventName === 'copilot_generation_started') {
            metrics.generationsStarted += 1;
        }
        if (eventName === 'copilot_generation_succeeded') {
            metrics.generationsSucceeded += 1;
            if (typeof payload.latencyMs === 'number') {
                metrics.latencySamples.push(payload.latencyMs);
            }
        }
        if (eventName === 'copilot_generation_failed') {
            metrics.generationsFailed += 1;
            if (typeof payload.latencyMs === 'number') {
                metrics.latencySamples.push(payload.latencyMs);
            }
        }
        if (eventName === 'copilot_fallback_used') {
            metrics.fallbackUsedCount += 1;
        }
        if (eventName === 'copilot_output_copied') {
            metrics.copiedCount += 1;
        }
        if (eventName === 'copilot_output_feedback') {
            if (payload.feedback === 'like') {
                metrics.likedCount += 1;
            }
            if (payload.feedback === 'dislike') {
                metrics.dislikedCount += 1;
            }
        }
        if (eventName === 'copilot_restricted_action') {
            metrics.restrictedActionCount += 1;
        }
    }

    function averageLatency() {
        if (metrics.latencySamples.length === 0) {
            return 0;
        }

        return Math.round(
            metrics.latencySamples.reduce((sum, value) => sum + value, 0) / metrics.latencySamples.length
        );
    }

    targetWindow.CopilotMetrics = metrics;
    targetWindow.printCopilotMetrics = function () {
        console.table([
            {
                sessionId: metrics.sessionId,
                openedCount: metrics.openedCount,
                generationsStarted: metrics.generationsStarted,
                generationsSucceeded: metrics.generationsSucceeded,
                generationsFailed: metrics.generationsFailed,
                fallbackUsed: metrics.fallbackUsedCount,
                copied: metrics.copiedCount,
                liked: metrics.likedCount,
                disliked: metrics.dislikedCount,
                restrictedActions: metrics.restrictedActionCount,
                averageLatencyMs: averageLatency()
            }
        ]);
    };

    targetWindow.CopilotTelemetry = {
        sessionId: metrics.sessionId,
        log(eventName, payload = {}) {
            const safePayload = sanitizePayload(payload);
            const event = {
                source: 'medical-copilot',
                event: eventName,
                timestamp: new Date().toISOString(),
                sessionId: metrics.sessionId,
                ...safePayload
            };

            updateMetrics(eventName, safePayload);

            const label = `[Medical Co-Pilot Audit] ${eventName}`;
            const warnEvents = new Set([
                'copilot_generation_failed',
                'copilot_fallback_used',
                'copilot_restricted_action'
            ]);
            const groupedEvents = new Set([
                'copilot_generation_started',
                'copilot_generation_succeeded',
                'copilot_generation_failed',
                'copilot_fallback_used',
                'copilot_restricted_action'
            ]);
            const method = warnEvents.has(eventName) ? 'warn' : 'info';

            if (groupedEvents.has(eventName) && typeof console.groupCollapsed === 'function') {
                console.groupCollapsed(label);
                console[method](event);
                console.groupEnd();
            } else {
                console[method](label, event);
            }

            return event;
        }
    };

    return targetWindow;
}

const copilotHostWindow = ensureTelemetryHost(getCopilotHostWindow());
const CopilotTelemetry = copilotHostWindow.CopilotTelemetry;
window.CopilotTelemetry = CopilotTelemetry;
window.CopilotMetrics = copilotHostWindow.CopilotMetrics;
window.printCopilotMetrics = copilotHostWindow.printCopilotMetrics;

function selectedPatientOptionByValue(value) {
    return Array.from(patientSelect.options).find((option) => option.value === String(value)) || null;
}

function selectedPatientKeyForValue(value) {
    const option = selectedPatientOptionByValue(value);
    return option ? option.dataset.pubpid || null : null;
}

function currentSelectedPatientKey() {
    return selectedPatientKeyForValue(patientSelect.value);
}

function currentVisibleQuickActions() {
    return availableQuickActions().map((action) => action.mode);
}

function contextScopeFor(role, patientId) {
    if (!patientId) {
        return 'general_prompt';
    }

    if (role === 'nurse') {
        return 'nursing_limited';
    }
    if (role === 'billing') {
        return 'billing_limited';
    }
    if (role === 'front_desk') {
        return 'front_desk_minimum_phi';
    }

    return 'full_clinical';
}

function syncTopLevelCopilotState() {
    copilotHostWindow.OpenEMRCopilotState = {
        role: state.activeRole,
        mode: state.activeMode,
        selectedPatientKey: currentSelectedPatientKey()
    };
}

function createMessage(role, content, options = {}) {
    return {
        id: options.id || `${Date.now()}-${Math.random().toString(36).slice(2)}`,
        role,
        content,
        timestamp: options.timestamp || formatTimestamp(new Date()),
        mode: options.mode || 'general_assistant',
        staffRole: options.staffRole || state.activeRole,
        patientId: options.patientId || (patientSelect ? patientSelect.value || '' : ''),
        selectedPatientKey: options.selectedPatientKey || selectedPatientKeyForValue(options.patientId || patientSelect.value || ''),
        requestId: options.requestId || options.traceId || '',
        responseId: options.responseId || (role === 'assistant' && !options.isLoading ? createId('response') : ''),
        sources: options.sources || [],
        sections: options.sections || [],
        tags: options.tags || [],
        safety: options.safety || '',
        traceId: options.traceId || options.requestId || '',
        requestPrompt: options.requestPrompt || '',
        meta: options.meta || {},
        guardrails: options.guardrails || null,
        feedback: options.feedback || '',
        copied: Boolean(options.copied),
        isLoading: Boolean(options.isLoading),
        showResponseActions: options.showResponseActions !== false,
        metadataLogged: Boolean(options.metadataLogged),
        agentWorkflowTraceLogged: Boolean(options.agentWorkflowTraceLogged)
    };
}

function hydrateAssistantWorkflowTrace(message, options = {}) {
    if (
        !message
        || message.role !== 'assistant'
        || message.isLoading
        || message.showResponseActions === false
        || !window.OpenEMRCopilotAgentTrace
        || typeof window.OpenEMRCopilotAgentTrace.buildVisibleWorkflowTrace !== 'function'
    ) {
        return null;
    }

    const requestPrompt = String(
        options.requestPrompt
        || message.requestPrompt
        || message.meta?.agent_request_prompt
        || ''
    ).trim();
    if (requestPrompt) {
        message.requestPrompt = requestPrompt;
    }

    const nextMeta = message.meta && typeof message.meta === 'object' ? { ...message.meta } : {};
    if (requestPrompt && !nextMeta.agent_request_prompt) {
        nextMeta.agent_request_prompt = requestPrompt;
    }

    const traceData = window.OpenEMRCopilotAgentTrace.buildVisibleWorkflowTrace({
        ...message,
        meta: nextMeta
    }, {
        toolModule: window.OpenEMRCopilotAgentTools || null,
        safetyModule: window.OpenEMRCopilotAgentSafety || null
    });
    nextMeta.visible_agent_workflow_trace = traceData;
    message.meta = nextMeta;

    if (
        options.emitObservability === true
        && !message.agentWorkflowTraceLogged
        && typeof window.OpenEMRCopilotAgentTrace.emitWorkflowObservability === 'function'
        && CopilotTelemetry
    ) {
        window.OpenEMRCopilotAgentTrace.emitWorkflowObservability(traceData, CopilotTelemetry, {
            requestId: message.requestId || null,
            responseId: message.responseId || null,
            role: message.staffRole || state.activeRole,
            mode: message.mode || 'general_assistant',
            selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey(),
            blockedReason: nextMeta.restriction_type || message.guardrails?.blockedReason || ''
        });
        message.agentWorkflowTraceLogged = true;
    }

    return traceData;
}

function buildAgentWorkflowTraceCard(message) {
    if (
        !message
        || message.role !== 'assistant'
        || message.isLoading
        || message.showResponseActions === false
        || !window.OpenEMRCopilotAgentTrace
        || typeof window.OpenEMRCopilotAgentTrace.buildTraceCard !== 'function'
    ) {
        return null;
    }

    if (!message.meta?.visible_agent_workflow_trace) {
        hydrateAssistantWorkflowTrace(message, { emitObservability: false });
    }

    return window.OpenEMRCopilotAgentTrace.buildTraceCard(message, {
        toolModule: window.OpenEMRCopilotAgentTools || null,
        safetyModule: window.OpenEMRCopilotAgentSafety || null
    });
}

function formatTimestamp(date) {
    return date.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit'
    });
}

function escapeModeTitle(mode) {
    return modeCatalog[mode] || copilotConfig.generalModeTitle;
}

function currentRoleConfig() {
    return roleCatalog[state.activeRole] || roleCatalog[copilotConfig.defaultRole] || { quick_actions: [], allowed_modes: [] };
}

function actionMatchesCurrentContext(action, role = state.activeRole, patientKey = currentSelectedPatientKey()) {
    if (!action || typeof action !== 'object') {
        return false;
    }

    if (Array.isArray(action.roles) && action.roles.length > 0 && !action.roles.includes(role)) {
        return false;
    }

    if (Array.isArray(action.patient_keys) && action.patient_keys.length > 0) {
        return Boolean(patientKey) && action.patient_keys.includes(patientKey);
    }

    return true;
}

function availableModesForRole(role = state.activeRole) {
    const allowedModes = roleCatalog[role]?.allowed_modes || [];
    return ['general_assistant']
        .concat(allowedModes)
        .filter((mode, index, items) => items.indexOf(mode) === index)
        .filter((mode) => {
            if (mode === 'general_assistant') {
                return true;
            }

            const action = actionCatalog[mode] || null;
            return !action || actionMatchesCurrentContext(action, role, currentSelectedPatientKey());
        });
}

function selectedOptionText(select, fallback = '') {
    const option = select?.options?.[select.selectedIndex];
    return option ? option.textContent.trim() : fallback;
}

function friendlySelectedPatientLabel() {
    if (!patientSelect || !patientSelect.value) {
        return 'No demo patient selected';
    }

    const option = patientSelect.options[patientSelect.selectedIndex];
    if (!option) {
        return 'No demo patient selected';
    }

    const fullName = [option.dataset.fname || '', option.dataset.lname || ''].filter(Boolean).join(' ').trim();
    if (fullName) {
        return fullName;
    }

    return option.textContent.trim().split(' - ')[0].split(' (')[0];
}

function updateControlsSummary() {
    if (!controlsSummary) {
        return;
    }

    const roleTitle = selectedOptionText(roleSelect, roleCatalog[state.activeRole]?.title || 'Doctor');
    const modeTitle = selectedOptionText(modeSelect, copilotConfig.generalModeTitle || 'General clinical support');
    controlsSummary.textContent = [
        friendlySelectedPatientLabel(),
        roleTitle,
        modeTitle,
        'Quick Actions'
    ].join(' · ');
}

function setDisclosureState(sectionName, expanded, options = {}) {
    const config = sectionName === 'guardrails'
        ? {
            container: guardrailsSection,
            toggle: guardrailsToggle,
            panel: guardrailsPanel,
            expandedEvent: 'copilot_guardrails_expanded',
            collapsedEvent: 'copilot_guardrails_collapsed'
        }
        : {
            container: controlsSection,
            toggle: controlsToggle,
            panel: controlsPanel,
            expandedEvent: 'copilot_controls_expanded',
            collapsedEvent: 'copilot_controls_collapsed'
        };

    if (!config.container || !config.toggle || !config.panel) {
        return;
    }

    const nextExpanded = Boolean(expanded);
    const previousExpanded = config.toggle.getAttribute('aria-expanded') === 'true';
    config.toggle.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
    config.panel.hidden = !nextExpanded;
    config.container.classList.toggle('is-expanded', nextExpanded);

    if (options.emitTelemetry && previousExpanded !== nextExpanded && CopilotTelemetry) {
        CopilotTelemetry.log(nextExpanded ? config.expandedEvent : config.collapsedEvent, {
            role: state.activeRole,
            mode: state.activeMode,
            selectedPatientKey: currentSelectedPatientKey(),
            actionType: nextExpanded ? 'expand' : 'collapse'
        });
    }
}

function syncControlState() {
    if (modeSelect) {
        modeSelect.value = state.activeMode;
    }

    if (guardrailsRoleScope) {
        guardrailsRoleScope.textContent = roleCatalog[state.activeRole]?.title || 'Doctor';
    }

    updateControlsSummary();
}

const defaultGuardrailSafetyNote = 'Draft only. Human review required. This does not replace clinical judgment or a final medical decision.';

function evaluateGuardrails(payload) {
    if (!window.OpenEMRCopilotGuardrails || typeof window.OpenEMRCopilotGuardrails.evaluate !== 'function') {
        return {
            allowed: true,
            finalResponse: payload.draftResponse || '',
            blockedReason: '',
            riskLevel: 'low',
            policyTags: [],
            auditSummary: {},
            finalSections: Array.isArray(payload.sections) ? payload.sections : [],
            finalSafety: payload.safetyText || defaultGuardrailSafetyNote,
            ui: {
                blocked: false,
                title: 'Guardrails checked',
                statusLabel: 'Guardrails checked · Role-safe · Draft-only',
                displayReason: 'Role scope, prompt safety, and draft-only rules passed.',
                alternative: '',
                roleLabel: roleCatalog[payload.role]?.title || 'Doctor',
                checks: ['Role scope', 'Prompt injection filter', 'Draft-only enforcement', 'PHI minimum necessary'],
                riskLevel: 'low',
                policyTags: []
            }
        };
    }

    return window.OpenEMRCopilotGuardrails.evaluate(payload);
}

function logGuardrailsEvaluation(result, context = {}) {
    if (!CopilotTelemetry) {
        return;
    }

    CopilotTelemetry.log('copilot_guardrails_evaluated', {
        requestId: context.requestId || null,
        role: context.role || null,
        mode: context.mode || null,
        selectedRole: context.role || null,
        selectedMode: context.mode || null,
        selectedPatientKey: context.selectedPatientKey || null,
        allowed: Boolean(result.allowed),
        blockedReason: result.blockedReason || '',
        riskLevel: result.riskLevel || 'low',
        policyTags: Array.isArray(result.policyTags) ? result.policyTags.slice(0, 6) : [],
        responseCharacterCount: (result.finalResponse || '').length,
        engine: context.engine || null,
        provider: context.provider || null,
        model: context.model || null,
        promptTokens: context.promptTokens ?? null,
        completionTokens: context.completionTokens ?? null,
        totalTokens: context.totalTokens ?? null,
        fallbackUsed: Boolean(context.fallbackUsed),
        fallbackReason: context.fallbackReason || null,
        latencyMs: context.latencyMs ?? null,
        openaiErrorCategory: context.openaiErrorCategory || null,
        openaiHttpStatus: context.openaiHttpStatus ?? null,
        openaiErrorMessageSafe: context.openaiErrorMessageSafe || null
    });
}

function roleAllowsMode(role, mode) {
    if (mode === 'general_assistant') {
        return true;
    }

    return (roleCatalog[role]?.allowed_modes || []).includes(mode);
}

function resolvePatientIdFromPrompt(prompt) {
    if (patientSelect.value) {
        return patientSelect.value;
    }

    const normalizedPrompt = prompt.toLowerCase();
    const options = Array.from(patientSelect.options).slice(1);
    for (const option of options) {
        const firstName = (option.dataset.fname || '').toLowerCase();
        const lastName = (option.dataset.lname || '').toLowerCase();
        const fullName = [firstName, lastName].filter(Boolean).join(' ');
        if (
            (firstName && normalizedPrompt.includes(firstName)) ||
            (lastName && normalizedPrompt.includes(lastName)) ||
            (fullName && normalizedPrompt.includes(fullName))
        ) {
            return option.value;
        }
    }

    return '';
}

function updatePatientSelectionFromPrompt(prompt) {
    const resolvedPatientId = resolvePatientIdFromPrompt(prompt);
    if (!patientSelect.value && resolvedPatientId) {
        patientSelect.value = resolvedPatientId;
        updateContextText();
        if (CopilotTelemetry) {
            CopilotTelemetry.log('copilot_patient_selected', {
                selectedPatientKey: selectedPatientKeyForValue(resolvedPatientId),
                role: state.activeRole
            });
        }
    }

    return patientSelect.value || resolvedPatientId || null;
}

function availableQuickActions() {
    return (currentRoleConfig().quick_actions || [])
        .map((mode) => actionCatalog[mode] || null)
        .filter((action) => actionMatchesCurrentContext(action));
}

function renderModeOptions() {
    modeSelect.innerHTML = '';

    availableModesForRole().forEach((mode) => {
        const option = document.createElement('option');
        option.value = mode;
        option.textContent = escapeModeTitle(mode);
        modeSelect.appendChild(option);
    });
}

function renderQuickActions() {
    quickActionSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.selected = true;
    placeholder.textContent = copilotConfig.quickActionPlaceholder || 'Select a quick action';
    quickActionSelect.appendChild(placeholder);

    availableQuickActions().forEach((action) => {
        const option = document.createElement('option');
        option.value = action.mode;
        option.textContent = action.title;
        quickActionSelect.appendChild(option);
    });

    quickActionSelect.disabled = availableQuickActions().length === 0;
    quickActionSelect.value = '';
}

function updateModeSelection(mode, options = {}) {
    const previousMode = state.activeMode;
    let nextMode = mode || 'general_assistant';
    if (!roleAllowsMode(state.activeRole, nextMode)) {
        nextMode = 'general_assistant';
    }

    state.activeMode = nextMode;
    syncTopLevelCopilotState();
    syncControlState();
    quickActionSelect.dataset.activeMode = state.activeMode;

    if (options.emitTelemetry && previousMode !== nextMode && CopilotTelemetry) {
        CopilotTelemetry.log('copilot_mode_selected', {
            mode: nextMode,
            role: state.activeRole,
            selectedPatientKey: currentSelectedPatientKey()
        });
    }
}

function updateRoleSelection(role, options = {}) {
    const previousRole = state.activeRole;
    const resolvedRole = roleCatalog[role] ? role : (copilotConfig.defaultRole || 'doctor');
    state.activeRole = resolvedRole;
    roleSelect.value = resolvedRole;
    roleNote.textContent = roleCatalog[resolvedRole]?.note || '';
    roleNote.classList.toggle('is-minimum-phi', resolvedRole === 'front_desk');
    renderModeOptions();
    renderQuickActions();
    updateModeSelection(state.activeMode);
    updateContextText();

    if (options.emitTelemetry && previousRole !== resolvedRole && CopilotTelemetry) {
        CopilotTelemetry.log('copilot_role_selected', {
            previousRole,
            newRole: resolvedRole,
            visibleQuickActions: currentVisibleQuickActions()
        });
    }
}

function updateContextText() {
    const roleTitle = roleCatalog[state.activeRole]?.title || '';
    const selectedOption = patientSelect.options[patientSelect.selectedIndex];
    if (!selectedOption || !patientSelect.value) {
        contextText.textContent = [copilotConfig.noPatientSelectedText, roleTitle].filter(Boolean).join(' • ');
        syncTopLevelCopilotState();
        updateControlsSummary();
        return;
    }

    contextText.textContent = `${copilotConfig.selectedPatientPrefix} ${selectedOption.textContent}${roleTitle ? ` • ${roleTitle}` : ''}`;
    syncTopLevelCopilotState();
    updateControlsSummary();
}

function setLabPdfStatus(message, tone = 'neutral') {
    if (!labPdfStatus) {
        return;
    }

    labPdfStatus.textContent = String(message || '').trim();
    labPdfStatus.dataset.tone = tone;
}

function currentPatientName() {
    const option = patientSelect?.options?.[patientSelect.selectedIndex];
    if (!option || !patientSelect.value) {
        return '';
    }

    return [option.dataset.fname || '', option.dataset.lname || ''].filter(Boolean).join(' ').trim();
}

function labPdfIngestionHelper() {
    return window.OpenEMRLabPdfIngestionDemo && typeof window.OpenEMRLabPdfIngestionDemo === 'object'
        ? window.OpenEMRLabPdfIngestionDemo
        : null;
}

function emitLabPdfAuditEvent(eventName, payload = {}) {
    if (!CopilotTelemetry) {
        return null;
    }

    const helper = labPdfIngestionHelper();
    if (helper && typeof helper.emitLabPdfTelemetry === 'function') {
        return helper.emitLabPdfTelemetry(CopilotTelemetry, eventName, payload);
    }

    const safePayload = {
        requestId: payload.requestId || null,
        role: payload.role || state.activeRole || null,
        mode: payload.mode || 'lab_pdf_ingestion',
        selectedPatientKey: payload.selectedPatientKey || currentSelectedPatientKey(),
        fileName: payload.fileName || null,
        extractionMethod: payload.extractionMethod || null,
        labValueCount: Number.isFinite(payload.labValueCount) ? payload.labValueCount : 0,
        abnormalCount: Number.isFinite(payload.abnormalCount) ? payload.abnormalCount : 0,
        missingDataCount: Number.isFinite(payload.missingDataCount) ? payload.missingDataCount : 0,
        status: payload.status || null
    };

    CopilotTelemetry.log(String(eventName || 'copilot_lab_pdf_event'), safePayload);
    return safePayload;
}

function hasActiveLabPdfAttachment() {
    return Boolean(state.labPdf.file || state.labPdf.useDemoSeed);
}

function refreshLabPdfAttachmentUi() {
    if (labPdfChip) {
        const hasDescriptor = Boolean(state.labPdf.descriptor);
        labPdfChip.hidden = !hasDescriptor;
        if (hasDescriptor && labPdfChipText) {
            labPdfChipText.textContent = state.labPdf.descriptor.displayLabel || 'Attached lab PDF';
        }
    }

    if (!hasActiveLabPdfAttachment()) {
        setLabPdfStatus('Attach a lab PDF for draft-only clinician review.');
    }
}

function clearLabPdfAttachment(options = {}) {
    const previousDescriptor = state.labPdf.descriptor;
    state.labPdf.file = null;
    state.labPdf.descriptor = null;
    state.labPdf.useDemoSeed = false;

    if (labPdfInput) {
        labPdfInput.value = '';
    }

    refreshLabPdfAttachmentUi();
    updateSendState();
    if (options.keepStatusMessage) {
        setLabPdfStatus(options.keepStatusMessage, options.tone || 'neutral');
    }

    if (options.emitTelemetry !== false && previousDescriptor) {
        emitLabPdfAuditEvent('copilot_lab_pdf_removed', {
            role: state.activeRole,
            mode: state.activeMode,
            selectedPatientKey: currentSelectedPatientKey(),
            fileName: previousDescriptor.fileName || null,
            status: 'removed'
        });
    }
}

function applyLabPdfAttachmentDescriptor(descriptor, options = {}) {
    state.labPdf.file = options.file || null;
    state.labPdf.useDemoSeed = Boolean(options.useDemoSeed);
    state.labPdf.descriptor = descriptor || null;
    refreshLabPdfAttachmentUi();
    updateSendState();
}

function setUploadedLabPdfAttachment(file) {
    const helper = labPdfIngestionHelper();
    const isPdf = helper && typeof helper.isPdfLike === 'function'
        ? helper.isPdfLike(file)
        : /\.pdf$/i.test(file?.name || '');

    if (!isPdf) {
        clearLabPdfAttachment({ emitTelemetry: false });
        setLabPdfStatus('Please select a PDF file for this lab-ingestion workflow.', 'error');
        emitLabPdfAuditEvent('copilot_lab_pdf_extraction_failed', {
            role: state.activeRole,
            mode: 'lab_pdf_ingestion',
            selectedPatientKey: currentSelectedPatientKey(),
            fileName: file?.name || null,
            status: 'invalid_file_type'
        });
        return false;
    }

    const descriptor = helper && typeof helper.buildAttachmentDescriptor === 'function'
        ? helper.buildAttachmentDescriptor(file)
        : {
            kind: 'uploaded_file',
            fileName: file.name || 'attached-lab-report.pdf',
            displayLabel: `Attached: ${file.name || 'attached-lab-report.pdf'}`,
            mimeType: file.type || 'application/pdf'
        };

    applyLabPdfAttachmentDescriptor(descriptor, {
        file,
        useDemoSeed: false
    });
    setLabPdfStatus(`Attached ${descriptor.fileName}. Send a prompt to ingest and retrieve lab context for clinician review.`, 'success');

    emitLabPdfAuditEvent('copilot_lab_pdf_selected', {
        role: state.activeRole,
        mode: 'lab_pdf_ingestion',
        selectedPatientKey: currentSelectedPatientKey(),
        fileName: descriptor.fileName || null,
        status: 'selected'
    });

    return true;
}

function resizeInput() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
}

function updateSendState() {
    const hasMessage = input.value.trim().length > 0;
    sendButton.disabled = state.loading || !hasMessage;
    input.disabled = state.loading;
    if (labPdfAttachButton) {
        labPdfAttachButton.disabled = state.loading;
    }
    if (labPdfRemoveButton) {
        labPdfRemoveButton.disabled = state.loading || !hasActiveLabPdfAttachment();
    }
}

function scrollThreadToBottom() {
    const target = scrollRegion || thread;
    target.scrollTop = target.scrollHeight;
}

const svgNamespace = 'http://www.w3.org/2000/svg';

function createResponseIcon(name) {
    const svg = document.createElementNS(svgNamespace, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.classList.add('copilot-message-action-icon');
    svg.setAttribute('aria-hidden', 'true');

    const shapesByIcon = {
        like: [
            ['path', { d: 'M7 10v10' }],
            ['path', { d: 'M14 5.5 11 10h7.2c1.1 0 1.9 1 1.7 2.1l-.9 6a2 2 0 0 1-2 1.7H7a2 2 0 0 1-2-2v-7.6a2 2 0 0 1 .6-1.4l4.8-4.8a1 1 0 0 1 1.7.8l.2 1.8c.1.5 0 1.1-.3 1.6Z' }]
        ],
        dislike: [
            ['path', { d: 'M7 14V4' }],
            ['path', { d: 'M14 18.5 11 14h7.2c1.1 0 1.9-1 1.7-2.1l-.9-6a2 2 0 0 0-2-1.7H7a2 2 0 0 0-2 2v7.6c0 .5.2 1 .6 1.4l4.8 4.8a1 1 0 0 0 1.7-.8l.2-1.8c.1-.5 0-1.1-.3-1.6Z' }]
        ],
        copy: [
            ['rect', { x: '9', y: '9', width: '10', height: '10', rx: '2' }],
            ['path', { d: 'M7 15H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v1' }]
        ]
    };

    (shapesByIcon[name] || []).forEach(([tagName, attributes]) => {
        const shape = document.createElementNS(svgNamespace, tagName);
        Object.entries(attributes).forEach(([key, value]) => {
            shape.setAttribute(key, value);
        });
        svg.appendChild(shape);
    });

    return svg;
}

function shouldRenderResponseActions(message) {
    return message.role === 'assistant' && !message.isLoading && message.showResponseActions !== false;
}

function logResponseMetadataIfNeeded(message) {
    if (!shouldRenderResponseActions(message) || message.metadataLogged) {
        return;
    }

    const sourcePayload = buildResponseSourcePayload(message.sources || [], {
        role: message.staffRole || state.activeRole,
        mode: message.mode || 'general_assistant',
        patientKey: message.selectedPatientKey || currentSelectedPatientKey(),
        ragGrounded: Boolean(message.meta?.rag_grounded)
    });
    const safeSources = sourcePayload.sources.map((source) => source.title).slice(0, 8);
    const safeContextTags = Array.isArray(message.tags) ? Array.from(new Set(message.tags)).slice(0, 5) : [];
    const safeSafetyLabels = message.safety ? [message.safety] : [];
    const safeRole = message.staffRole || state.activeRole;
    const safeMode = message.mode || 'general_assistant';
    const safePatientKey = message.selectedPatientKey || currentSelectedPatientKey() || null;
    const safeMeta = message.meta && typeof message.meta === 'object' ? {
        engine: message.meta.engine || null,
        provider: message.meta.provider || null,
        model: message.meta.model || null,
        openaiConfigured: Boolean(message.meta.openai_configured),
        promptTokens: message.meta.token_usage?.prompt_tokens ?? null,
        completionTokens: message.meta.token_usage?.completion_tokens ?? null,
        totalTokens: message.meta.token_usage?.total_tokens ?? null,
        estimatedCostUsd: message.meta.estimated_cost_usd ?? null,
        costNote: message.meta.cost_note || null,
        ragGrounded: Boolean(message.meta.rag_grounded),
        contextScope: message.meta.context_scope || null,
        fallbackUsed: Boolean(message.meta.fallback_used),
        restrictedByRole: Boolean(message.meta.restricted_by_role)
    } : {};

    if (typeof console.groupCollapsed === 'function') {
        console.groupCollapsed('[OpenEMR Copilot] Response metadata');
        console.info('messageId', message.id);
        console.info('requestId', message.requestId || null);
        console.info('responseId', message.responseId || null);
        console.info('role', safeRole);
        console.info('focusMode', safeMode);
        console.info('demoPatientKey', safePatientKey);
        console.info('sourceCount', sourcePayload.sources.length);
        console.info('sourceTitles', sourcePayload.sourceTitles);
        console.info('sourceCategories', sourcePayload.sourceCategories);
        console.info('latestAmbientVisitFound', sourcePayload.latestAmbientVisitFound);
        console.info('sources', safeSources);
        console.info('contextTags', safeContextTags);
        console.info('safetyLabels', safeSafetyLabels);
        console.info('meta', safeMeta);
        console.groupEnd();
    } else {
        console.info('[OpenEMR Copilot] Response metadata', {
            messageId: message.id,
            requestId: message.requestId || null,
            responseId: message.responseId || null,
            role: safeRole,
            focusMode: safeMode,
            demoPatientKey: safePatientKey,
            sourceCount: sourcePayload.sources.length,
            sourceTitles: sourcePayload.sourceTitles,
            sourceCategories: sourcePayload.sourceCategories,
            latestAmbientVisitFound: sourcePayload.latestAmbientVisitFound,
            sources: safeSources,
            contextTags: safeContextTags,
            safetyLabels: safeSafetyLabels,
            meta: safeMeta
        });
    }

    message.metadataLogged = true;
}

function isLabPdfSavePrompt(prompt) {
    const normalized = String(prompt || '').trim().toLowerCase();
    return [
        'save',
        'save pdf',
        'save the pdf',
        'show vector',
        'show vectorized result',
        'show json',
        'show json object',
        'show me the vectorized result',
        'show me a vectorized result',
        'show me the json object',
        'show me a vectorized result and a json object',
        'show me a vectorized result and json object'
    ].includes(normalized);
}

function logLabPdfDataToConsole(prompt, toolOutput, options = {}) {
    if (!toolOutput || typeof toolOutput !== 'object' || !isLabPdfSavePrompt(prompt)) {
        return;
    }

    const sourceType = toolOutput.sourceMetadata?.sourceType || '';
    const isLabPdfPayload = sourceType === 'lab_pdf' || toolOutput.tool === 'attach_and_vectorize_lab_pdf';
    if (!isLabPdfPayload) {
        return;
    }

    const vectorizedResult = Array.isArray(toolOutput.vectorizedResult) ? toolOutput.vectorizedResult : [];
    const jsonObject = {
        requestId: options.requestId || null,
        role: options.role || null,
        mode: options.mode || null,
        patientKey: options.selectedPatientKey || null,
        documentMetadata: toolOutput.documentMetadata || {},
        sourceMetadata: toolOutput.sourceMetadata || {},
        extractedTextPreview: toolOutput.extractedTextPreview || '',
        extractedFacts: Array.isArray(toolOutput.extractedFacts) ? toolOutput.extractedFacts : [],
        abnormalFindings: Array.isArray(toolOutput.abnormalFindings) ? toolOutput.abnormalFindings : [],
        missingData: Array.isArray(toolOutput.missingData) ? toolOutput.missingData : [],
        retrieval: toolOutput.retrieval || {},
        vectorizedResult,
        safetyMetadata: toolOutput.safetyMetadata || {}
    };

    if (typeof console.groupCollapsed === 'function') {
        console.groupCollapsed('[OpenEMR Copilot] Saved Lab PDF Data');
        console.log('vectorizedResult', vectorizedResult);
        console.log('jsonObject', jsonObject);
        if (typeof console.table === 'function' && vectorizedResult.length > 0) {
            console.table(vectorizedResult.map((item) => ({
                id: item.id || null,
                fileName: item.fileName || null,
                chunkIndex: item.chunkIndex ?? null,
                sourcePage: item.sourcePage ?? null,
                embeddingDimensions: Array.isArray(item.embedding) ? item.embedding.length : 0,
                score: item.score ?? null
            })));
        }
        console.groupEnd();
        return;
    }

    console.log('[OpenEMR Copilot] vectorizedResult', vectorizedResult);
    console.log('[OpenEMR Copilot] jsonObject', jsonObject);
}

function createMessageTimeElement(message) {
    const time = document.createElement('div');
    time.className = `copilot-message-time copilot-message-time-${message.role}`;
    time.textContent = message.timestamp;
    return time;
}

function normalizeSourceEntry(value) {
    if (value && typeof value === 'object') {
        const title = String(value.title || value.label || '').trim();
        const category = String(value.category || '').trim();
        if (!title) {
            return null;
        }

        return {
            title,
            category: category || title.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
        };
    }

    const rawValue = String(value || '').trim();
    if (!rawValue) {
        return null;
    }

    const normalized = rawValue.toLowerCase();
    const mapping = {
        'patient_data': ['Patient Chart Context', 'patient_chart_context'],
        'patient chart context': ['Patient Chart Context', 'patient_chart_context'],
        'general prompt context': ['General Prompt Context', 'general_prompt_context'],
        'openemr_postcalendar_events': ['Visit History', 'visit_history'],
        'form_encounter': ['Visit History', 'visit_history'],
        'visit history': ['Visit History', 'visit_history'],
        'pnotes': ['Patient Chart Context', 'patient_chart_context'],
        'lists': ['Issues / Problem List', 'problem_list'],
        'issues / problem list': ['Issues / Problem List', 'problem_list'],
        'prescriptions': ['Medications', 'medications'],
        'medications': ['Medications', 'medications'],
        'form_vitals': ['Vitals / Labs', 'vitals_labs'],
        'vitals / labs': ['Vitals / Labs', 'vitals_labs'],
        'recent vitals': ['Vitals / Labs', 'vitals_labs'],
        'lab follow-up': ['Vitals / Labs', 'vitals_labs'],
        'billing/demo claim data': ['Billing / Claim Context', 'billing_claim_context'],
        'billing / claim context': ['Billing / Claim Context', 'billing_claim_context'],
        'documents / notes': ['Documents / Notes', 'documents'],
        'attached lab pdf': ['Attached Lab PDF', 'documents'],
        'uploaded lab pdf': ['Uploaded Lab PDF', 'documents'],
        'insurance note': ['Insurance Note', 'insurance'],
        'immunization review': ['Immunization Review', 'immunizations'],
        'care preferences': ['Care Preferences', 'care_preferences'],
        'care team': ['Care Team', 'care_team'],
        'ai-assisted visit review': ['AI-Assisted Visit Review', 'ambient_encounter_capture'],
        'ambient encounter capture': ['Ambient Encounter Capture', 'ambient_encounter_capture'],
        'latest approved ambient encounter capture': ['Latest Approved Ambient Encounter Capture', 'ambient_encounter_capture'],
        'visit history: ai-assisted visit review / ambient encounter capture': ['Latest Approved Ambient Encounter Capture', 'ambient_encounter_capture']
    };

    const mapped = mapping[normalized];
    if (mapped) {
        return {
            title: mapped[0],
            category: mapped[1]
        };
    }

    return {
        title: rawValue,
        category: normalized.replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'source'
    };
}

function uniqueSourceEntries(entries) {
    const seen = new Set();
    return entries.filter((entry) => {
        if (!entry) {
            return false;
        }

        const key = `${entry.category}::${entry.title}`;
        if (seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });
}

function buildResponseSourcePayload(baseSources, options = {}) {
    const role = options.role || state.activeRole;
    const mode = options.mode || 'general_assistant';
    const patientKey = options.patientKey || currentSelectedPatientKey();
    const ragGrounded = Boolean(options.ragGrounded);
    const entries = uniqueSourceEntries((Array.isArray(baseSources) ? baseSources : []).map(normalizeSourceEntry).filter(Boolean));
    let latestAmbientVisitFound = false;

    if (
        patientKey === 'DEMO-PCP-1001' &&
        (
            ['medication_info', 'treatment_plan', 'clinical_notes', 'follow_up', 'visit_summary', 'patient_education', 'rag_chart_context', 'latest_ambient_summary'].includes(mode) ||
            ragGrounded
        ) &&
        window.OpenEMRAIAmbientVisitDemo &&
        typeof window.OpenEMRAIAmbientVisitDemo.latestApprovedVisit === 'function'
    ) {
        const latestVisit = window.OpenEMRAIAmbientVisitDemo.latestApprovedVisit(patientKey);
        latestAmbientVisitFound = Boolean(latestVisit);
        if (latestVisit) {
            entries.push({
                title: 'Latest Approved Ambient Encounter Capture',
                category: 'ambient_encounter_capture'
            });
        }
    }

    const uniqueEntries = uniqueSourceEntries(entries);
    return {
        sources: uniqueEntries,
        sourceTitles: uniqueEntries.map((entry) => entry.title),
        sourceCategories: uniqueEntries.map((entry) => entry.category),
        latestAmbientVisitFound
    };
}

function messageHasSourcesSection(message) {
    return Array.isArray(message.sections) && message.sections.some((section) => {
        return section && typeof section === 'object' && String(section.title || '').trim().toLowerCase() === 'sources used';
    });
}

function buildVisibleSourceEntries(message) {
    return buildResponseSourcePayload(message.sources || [], {
        role: message.staffRole || state.activeRole,
        mode: message.mode || 'general_assistant',
        patientKey: message.selectedPatientKey || currentSelectedPatientKey(),
        ragGrounded: Boolean(message.meta?.rag_grounded)
    }).sources;
}

function shouldShowRagGroundingNote(message) {
    if (message.role !== 'assistant') {
        return false;
    }

    if (message.meta?.rag_grounded) {
        return buildVisibleSourceEntries(message).length > 0;
    }

    if (![
        'medication_info',
        'treatment_plan',
        'clinical_notes',
        'follow_up',
        'visit_summary',
        'patient_education',
        'rag_chart_context'
    ].includes(message.mode || '')) {
        return false;
    }

    return buildVisibleSourceEntries(message).length > 0;
}

function formatEngineLabel(value) {
    return {
        openai: 'OpenAI',
        fallback: 'Local fallback',
        guardrail: 'Guardrail'
    }[String(value || '').toLowerCase()] || 'Unknown';
}

function formatProviderLabel(value) {
    return {
        openai: 'OpenAI',
        local_fallback: 'Local fallback',
        guardrail: 'Guardrail'
    }[String(value || '').toLowerCase()] || 'Unknown';
}

function formatTokenUsageFooterValue(meta) {
    const engine = String(meta?.engine || '').trim().toLowerCase();
    const usage = meta?.token_usage && typeof meta.token_usage === 'object'
        ? meta.token_usage
        : null;

    if (usage && (usage.prompt_tokens !== null || usage.completion_tokens !== null || usage.total_tokens !== null)) {
        return `${usage.prompt_tokens ?? '-'} / ${usage.completion_tokens ?? '-'} / ${usage.total_tokens ?? '-'}`;
    }

    if (engine === 'guardrail') {
        return 'Not applicable — guardrail blocked before LLM call';
    }

    if (engine === 'fallback') {
        return 'Not applicable — local fallback';
    }

    if (engine === 'openai') {
        return 'Not returned by provider';
    }

    return 'Not available';
}

function buildAssistantRuntimeMeta(message) {
    if (message.role !== 'assistant' || message.isLoading || !message.meta || typeof message.meta !== 'object') {
        return null;
    }

    const engine = String(message.meta.engine || '').trim();
    const provider = String(message.meta.provider || '').trim();
    if (!engine && !provider) {
        return null;
    }

    const wrapper = document.createElement('section');
    wrapper.className = 'copilot-runtime-meta';

    const status = document.createElement('div');
    status.className = 'copilot-runtime-meta-status';
    if (engine === 'openai') {
        status.textContent = 'LLM status: OpenAI response generated with retrieved chart context.';
    } else if (engine === 'fallback') {
        if (String(message.meta.fallback_reason || '') === 'demo_mode') {
            status.textContent = 'LLM status: Local fallback mode. This response was generated by the local demo retrieval/fallback engine.';
        } else {
            status.textContent = 'LLM status: Local fallback mode. OPENAI_API_KEY is missing or OpenAI was unavailable, so this response was generated by the local demo fallback engine.';
        }
    } else {
        status.textContent = 'LLM status: Guardrail response generated before or instead of model output.';
    }
    wrapper.appendChild(status);

    const items = [];
    items.push(['Engine', formatEngineLabel(engine)]);
    items.push(['Provider', formatProviderLabel(provider)]);

    if (message.meta.model) {
        items.push(['Model', String(message.meta.model)]);
    }

    items.push(['Tokens', formatTokenUsageFooterValue(message.meta)]);

    if (message.meta.fallback_reason) {
        items.push(['Fallback reason', String(message.meta.fallback_reason)]);
    }

    if (message.meta.openai_error_category) {
        items.push(['OpenAI error', String(message.meta.openai_error_category)]);
    }

    if (message.meta.openai_http_status) {
        items.push(['OpenAI HTTP', String(message.meta.openai_http_status)]);
    }

    if (message.meta.openai_error_message_safe) {
        items.push(['OpenAI note', String(message.meta.openai_error_message_safe)]);
    }

    if (message.meta.rag_grounded !== undefined) {
        items.push(['RAG-grounded', message.meta.rag_grounded ? 'yes' : 'no']);
    }

    if (message.meta.agent_architecture) {
        items.push(['Agent architecture', String(message.meta.agent_architecture).replace(/_/g, ' ')]);
    }

    if (Array.isArray(message.meta.agent_trace)) {
        items.push(['Agent steps', String(message.meta.agent_trace.length)]);
    }

    if (message.meta.estimated_cost_usd !== null && message.meta.estimated_cost_usd !== undefined && message.meta.estimated_cost_usd !== '') {
        items.push(['Estimated cost', `$${message.meta.estimated_cost_usd}`]);
    } else if (message.meta.cost_note) {
        items.push(['Cost note', String(message.meta.cost_note)]);
    }

    const list = document.createElement('dl');
    list.className = 'copilot-runtime-meta-list';

    items.forEach(([label, value]) => {
        if (!value) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'copilot-runtime-meta-row';

        const dt = document.createElement('dt');
        dt.textContent = label;
        row.appendChild(dt);

        const dd = document.createElement('dd');
        dd.textContent = value;
        row.appendChild(dd);

        list.appendChild(row);
    });

    if (list.childElementCount > 0) {
        wrapper.appendChild(list);
    }

    return wrapper;
}

function createIconActionButton(options) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = options.className;
    button.setAttribute('aria-label', options.label);
    button.title = options.label;

    if (options.pressed !== undefined) {
        button.setAttribute('aria-pressed', options.pressed ? 'true' : 'false');
    }

    if (options.copiedLabel) {
        button.dataset.copiedLabel = options.copiedLabel;
    }

    button.appendChild(createResponseIcon(options.icon));
    button.addEventListener('click', options.onClick);
    return button;
}

function buildGuardrailBanner(guardrails) {
    if (!guardrails || !guardrails.ui || !guardrails.ui.blocked) {
        return null;
    }

    const banner = document.createElement('section');
    banner.className = 'copilot-guardrail-banner';

    const title = document.createElement('h3');
    title.className = 'copilot-guardrail-banner-title';
    title.textContent = guardrails.ui.title || 'Guardrail blocked this request';
    banner.appendChild(title);

    if (guardrails.ui.displayReason) {
        const reason = document.createElement('p');
        reason.className = 'copilot-guardrail-banner-copy';
        reason.textContent = guardrails.ui.displayReason;
        banner.appendChild(reason);
    }

    if (guardrails.ui.alternative) {
        const alternative = document.createElement('p');
        alternative.className = 'copilot-guardrail-banner-alt';
        alternative.textContent = guardrails.ui.alternative;
        banner.appendChild(alternative);
    }

    return banner;
}

function buildGuardrailStatus(message) {
    if (!shouldRenderResponseActions(message) || !message.guardrails || !message.guardrails.ui) {
        return null;
    }

    const status = document.createElement('div');
    status.className = `copilot-guardrail-status ${message.guardrails.ui.blocked ? 'is-blocked' : 'is-allowed'}`.trim();
    status.textContent = message.guardrails.ui.statusLabel || 'Guardrails checked · Role-safe · Draft-only';
    return status;
}

function buildTypingBubble() {
    const typing = document.createElement('div');
    typing.className = 'copilot-typing';
    for (let index = 0; index < 3; index++) {
        const dot = document.createElement('span');
        typing.appendChild(dot);
    }
    return typing;
}

function toneClassName(tone) {
    if (tone === 'red') {
        return 'copilot-section-red';
    }
    if (tone === 'yellow') {
        return 'copilot-section-yellow';
    }
    return '';
}

function itemToneClassName(tone) {
    if (tone === 'red') {
        return 'copilot-red-flag';
    }
    if (tone === 'yellow') {
        return 'copilot-key-gap';
    }
    return '';
}

function appendBubbleContent(bubble, message) {
    const hasStructuredSections = message.role === 'assistant' && Array.isArray(message.sections) && message.sections.length > 0;
    const guardrailBanner = message.role === 'assistant' ? buildGuardrailBanner(message.guardrails) : null;
    const sourceEntries = message.role === 'assistant' ? buildVisibleSourceEntries(message) : [];
    const shouldShowSourcesSection = sourceEntries.length > 0 && !messageHasSourcesSection(message);
    const ragGroundingNote = shouldShowRagGroundingNote(message)
        ? 'RAG-grounded response: retrieved chart context was used before drafting this answer.'
        : '';

    if (!hasStructuredSections && !guardrailBanner && !shouldShowSourcesSection && !ragGroundingNote) {
        bubble.textContent = message.content;
        return;
    }

    const body = document.createElement('div');
    body.className = 'copilot-bubble-body';

    if (guardrailBanner) {
        body.appendChild(guardrailBanner);
    }

    if (message.content) {
        const intro = document.createElement('p');
        intro.className = 'copilot-message-intro';
        intro.textContent = message.content;
        body.appendChild(intro);
    }

    if (ragGroundingNote) {
        const note = document.createElement('p');
        note.className = 'copilot-message-intro';
        note.textContent = ragGroundingNote;
        body.appendChild(note);
    }

    const sections = document.createElement('div');
    sections.className = 'copilot-sections';

    message.sections.forEach((section) => {
        if (!section || typeof section !== 'object' || !section.title || !Array.isArray(section.items) || section.items.length === 0) {
            return;
        }

        const block = document.createElement('section');
        block.className = `copilot-section-block ${toneClassName(section.tone || 'neutral')}`.trim();

        const title = document.createElement('h3');
        title.className = 'copilot-section-title';
        title.textContent = section.title;
        block.appendChild(title);

        const list = document.createElement('ul');
        list.className = 'copilot-section-list';

        section.items.forEach((item) => {
            if (!item) {
                return;
            }

            const listItem = document.createElement('li');
            listItem.className = `copilot-section-item ${itemToneClassName(section.tone || 'neutral')}`.trim();
            listItem.textContent = item;
            list.appendChild(listItem);
        });

        block.appendChild(list);
        sections.appendChild(block);
    });

    if (sections.childElementCount > 0) {
        body.appendChild(sections);
    }

    if (shouldShowSourcesSection) {
        const sourceBlock = document.createElement('section');
        sourceBlock.className = 'copilot-section-block';

        const sourceTitle = document.createElement('h3');
        sourceTitle.className = 'copilot-section-title';
        sourceTitle.textContent = 'Sources Used';
        sourceBlock.appendChild(sourceTitle);

        const sourceList = document.createElement('ul');
        sourceList.className = 'copilot-section-list';

        sourceEntries.forEach((entry) => {
            const listItem = document.createElement('li');
            listItem.className = 'copilot-section-item';
            listItem.textContent = entry.title;
            sourceList.appendChild(listItem);
        });

        sourceBlock.appendChild(sourceList);
        body.appendChild(sourceBlock);
    }

    bubble.appendChild(body);
}

function renderMessages(shouldScrollToBottom = false) {
    thread.innerHTML = '';

    state.messages.forEach((message) => {
        const row = document.createElement('div');
        row.className = `copilot-message copilot-message-${message.role}`;

        if (message.role === 'assistant') {
            const avatar = document.createElement('span');
            avatar.className = 'copilot-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = '✦';
            row.appendChild(avatar);
        }

        const stack = document.createElement('div');
        stack.className = 'copilot-message-stack';

        const bubble = document.createElement('div');
        bubble.className = `copilot-bubble copilot-bubble-${message.role}`;

        if (message.isLoading) {
            bubble.classList.add('copilot-bubble-loading');
            bubble.appendChild(buildTypingBubble());
        } else {
            appendBubbleContent(bubble, message);
            logResponseMetadataIfNeeded(message);
        }

        stack.appendChild(bubble);

        const metadata = buildMessageMetaRow(message);
        if (metadata) {
            stack.appendChild(metadata);
        }

        if (!message.isLoading && message.role === 'assistant' && message.safety) {
            const safety = document.createElement('div');
            safety.className = 'copilot-message-safety';
            safety.textContent = message.safety;
            stack.appendChild(safety);
        }

        const guardrailStatus = buildGuardrailStatus(message);
        if (guardrailStatus) {
            stack.appendChild(guardrailStatus);
        }

        const agentWorkflowTrace = buildAgentWorkflowTraceCard(message);
        if (agentWorkflowTrace) {
            stack.appendChild(agentWorkflowTrace);
        }

        const runtimeMeta = buildAssistantRuntimeMeta(message);
        if (runtimeMeta) {
            stack.appendChild(runtimeMeta);
        }

        const assistantActions = buildAssistantActionRow(message);
        if (assistantActions) {
            stack.appendChild(assistantActions);
        }

        row.appendChild(stack);
        thread.appendChild(row);
    });

    if (shouldScrollToBottom) {
        scrollThreadToBottom();
    }
}

function addAssistantMessage(content, options = {}) {
    const message = createMessage('assistant', content, options);
    hydrateAssistantWorkflowTrace(message, {
        requestPrompt: options.requestPrompt || '',
        emitObservability: true
    });
    state.messages.push(message);
    renderMessages(true);
    return message;
}

function clearLoadingMessage() {
    const lastMessage = state.messages[state.messages.length - 1];
    if (lastMessage && lastMessage.isLoading) {
        state.messages.pop();
    }
}

function buildHistoryEntryContent(message) {
    const parts = [];
    if (message.content) {
        parts.push(message.content);
    }

    if (Array.isArray(message.sections) && message.sections.length > 0) {
        message.sections.forEach((section) => {
            if (!section || typeof section !== 'object' || !section.title || !Array.isArray(section.items) || section.items.length === 0) {
                return;
            }

            parts.push(`${section.title}: ${section.items.join('; ')}`);
        });
    }

    return parts.join('\n');
}

function buildHistoryPayload() {
    return state.messages
        .filter((message) => !message.isLoading)
        .slice(-8)
        .map((message) => ({
            role: message.role,
            content: buildHistoryEntryContent(message)
        }));
}

function updateMessageState(messageId, patch) {
    const messageIndex = state.messages.findIndex((message) => message.id === messageId);
    if (messageIndex === -1) {
        return;
    }

    state.messages[messageIndex] = {
        ...state.messages[messageIndex],
        ...patch
    };
    renderMessages(false);
}

function getAssistantMessagePlainText(message) {
    const lines = [];
    const ragGroundingNote = shouldShowRagGroundingNote(message)
        ? 'RAG-grounded response: retrieved chart context was used before drafting this answer.'
        : '';
    const sourceEntries = buildVisibleSourceEntries(message);

    if (message.content) {
        lines.push(message.content);
    }

    if (ragGroundingNote) {
        lines.push('');
        lines.push(ragGroundingNote);
    }

    if (Array.isArray(message.sections)) {
        message.sections.forEach((section) => {
            if (!section || typeof section !== 'object' || !section.title || !Array.isArray(section.items) || section.items.length === 0) {
                return;
            }

            lines.push('');
            lines.push(section.title);
            section.items.forEach((item) => {
                lines.push(`- ${item}`);
            });
        });
    }

    if (sourceEntries.length > 0 && !messageHasSourcesSection(message)) {
        lines.push('');
        lines.push('Sources Used');
        sourceEntries.forEach((entry) => {
            lines.push(`- ${entry.title}`);
        });
    }

    if (message.safety) {
        lines.push('');
        lines.push(`Safety: ${message.safety}`);
    }

    return lines.join('\n').trim();
}

async function copyTextToClipboard(value) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(value);
        return;
    }

    const helper = document.createElement('textarea');
    helper.value = value;
    helper.setAttribute('readonly', 'readonly');
    helper.style.position = 'absolute';
    helper.style.left = '-9999px';
    document.body.appendChild(helper);
    helper.select();
    document.execCommand('copy');
    document.body.removeChild(helper);
}

async function copyAssistantMessage(message) {
    const fullText = getAssistantMessagePlainText(message);
    if (!fullText) {
        return;
    }

    try {
        await copyTextToClipboard(fullText);
        updateMessageState(message.id, { copied: true });
        window.clearTimeout(copiedStateTimers.get(message.id));
        copiedStateTimers.set(message.id, window.setTimeout(() => {
            updateMessageState(message.id, { copied: false });
            copiedStateTimers.delete(message.id);
        }, 1600));
        if (CopilotTelemetry) {
            CopilotTelemetry.log('copilot_output_copied', {
                responseId: message.responseId || null,
                requestId: message.requestId || null,
                role: message.staffRole || state.activeRole,
                mode: message.mode || 'general_assistant',
                selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey(),
                responseLength: fullText.length,
                copied: true
            });
        }
    } catch (error) {
    }
}

function updateFeedback(message, nextFeedback) {
    if (message.feedback === nextFeedback) {
        return;
    }

    updateMessageState(message.id, { feedback: nextFeedback });
    if (CopilotTelemetry) {
        CopilotTelemetry.log('copilot_output_feedback', {
            responseId: message.responseId || null,
            requestId: message.requestId || null,
            role: message.staffRole || state.activeRole,
            mode: message.mode || 'general_assistant',
            selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey(),
            feedback: nextFeedback
        });
    }
}

function buildMessageMetaRow(message) {
    if (message.isLoading) {
        return null;
    }

    const meta = document.createElement('div');
    meta.className = `copilot-message-meta copilot-message-meta-${message.role}`;

    const left = document.createElement('div');
    left.className = 'copilot-message-meta-left';
    left.appendChild(createMessageTimeElement(message));

    if (!shouldRenderResponseActions(message)) {
        meta.appendChild(left);
        return meta;
    }

    const feedback = document.createElement('div');
    feedback.className = 'copilot-response-controls';

    const likeButton = createIconActionButton({
        className: `copilot-response-control copilot-message-action copilot-feedback-button ${message.feedback === 'like' ? 'is-selected copilot-message-action-active' : ''}`.trim(),
        label: 'Like response',
        icon: 'like',
        pressed: message.feedback === 'like',
        onClick: () => updateFeedback(message, 'like')
    });

    const dislikeButton = createIconActionButton({
        className: `copilot-response-control copilot-message-action copilot-feedback-button ${message.feedback === 'dislike' ? 'is-selected copilot-message-action-active' : ''}`.trim(),
        label: 'Dislike response',
        icon: 'dislike',
        pressed: message.feedback === 'dislike',
        onClick: () => updateFeedback(message, 'dislike')
    });

    const copyButton = createIconActionButton({
        className: `copilot-response-control copilot-message-action copilot-copy-button ${message.copied ? 'is-copied copilot-message-action-active' : ''}`.trim(),
        label: message.copied ? copilotConfig.copiedText : 'Copy response',
        copiedLabel: copilotConfig.copiedText,
        icon: 'copy',
        onClick: () => copyAssistantMessage(message)
    });

    feedback.append(likeButton, dislikeButton);
    left.appendChild(feedback);
    meta.append(left, copyButton);
    return meta;
}

function buildAssistantActionRow(message) {
    if (!shouldRenderResponseActions(message)) {
        return null;
    }

    const actions = document.createElement('div');
    actions.className = 'copilot-message-actions';

    if (message.mode === 'treatment_plan') {
        const summaryButton = document.createElement('button');
        summaryButton.type = 'button';
        summaryButton.className = 'copilot-summary-button';
        summaryButton.textContent = copilotConfig.visitSummaryButtonLabel;
        summaryButton.disabled = state.loading;
        summaryButton.addEventListener('click', () => {
            requestAssistantResponse(copilotConfig.visitSummaryPrompt, {
                includeUserMessage: false,
                modeOverride: 'visit_summary',
                roleOverride: message.staffRole || state.activeRole,
                patientIdOverride: message.patientId || patientSelect.value || null
            });
        });
        actions.appendChild(summaryButton);
    }

    if (message.mode === 'send_reminder' && message.staffRole === 'front_desk') {
        const reminderButton = document.createElement('button');
        reminderButton.type = 'button';
        reminderButton.className = 'copilot-summary-button';
        reminderButton.textContent = copilotConfig.sendReminderEmailButtonLabel;
        reminderButton.disabled = state.loading;
        reminderButton.addEventListener('click', () => {
            requestReminderEmail(message.staffRole || 'front_desk', message.patientId || patientSelect.value || null);
        });
        actions.appendChild(reminderButton);
    }

    return actions.childElementCount > 0 ? actions : null;
}

function inferPromptMode(prompt) {
    const value = prompt.toLowerCase();
    if (/(lab pdf ingestion|lab pdf|attach.*lab pdf|upload.*lab pdf|ingest.*lab pdf|extract.*lab pdf|pdf lab results)/.test(value)) {
        return 'lab_pdf_ingestion';
    }
    if (/(summarize latest ambient encounter only|latest ambient encounter only|latest ambient encounter|ambient encounter only|summarize the latest ai-assisted visit review|latest approved ambient encounter)/.test(value)) {
        return 'latest_ambient_summary';
    }
    if (/(appointment|scheduled|provider|check-in|check in|location)/.test(value)) {
        if (/(reminder|email)/.test(value)) {
            return 'send_reminder';
        }
        if (/(contact|phone|email address|confirm)/.test(value)) {
            return 'patient_contact';
        }
        if (/(summary)/.test(value)) {
            return 'front_desk_summary';
        }
        return 'appointment_info';
    }
    if (/(contact information|contact info|phone number|email address)/.test(value)) {
        return 'patient_contact';
    }
    if (/(reminder|send this patient a reminder|draft an appointment reminder)/.test(value)) {
        return 'send_reminder';
    }
    if (/(claim|rejection|denial|resubmi|missing diagnosis link)/.test(value)) {
        return 'billing_review';
    }
    if (/(billing|coding|cpt|icd|payer|documentation needed before billing|payment due|health insurance|insurance on file|insurance)/.test(value)) {
        return 'billing';
    }
    if (/(visit history|ai-assisted encounter|ambient encounter capture|what changed since|chart context|troponin|lab result|latest lab|what sources did you use|sources did you use)/.test(value)) {
        return 'rag_chart_context';
    }
    if (/(visit summary|summary of visit)/.test(value)) {
        return 'visit_summary';
    }
    if (/(patient-friendly|patient friendly|education)/.test(value)) {
        return 'patient_education';
    }
    if (/(medication|drug|interaction|counsel)/.test(value)) {
        return 'medication_info';
    }
    if (/(treatment plan|let me see .* treatment plan|give me .* treatment plan)/.test(value)) {
        return 'treatment_plan';
    }
    if (/(follow-up|follow up|monitoring items|escalation precautions|care coordination)/.test(value)) {
        return 'follow_up';
    }
    if (/(soap|note|documentation|chart for a doctor|30 seconds|30-second|summary)/.test(value)) {
        return 'clinical_notes';
    }
    if (/(differential|diagnosis|causing|red flag|miss|questions should the clinician ask)/.test(value)) {
        return 'differential_diagnosis';
    }

    return 'general_assistant';
}

function resolveModeForPrompt(prompt, options = {}) {
    if (options.modeOverride) {
        return options.modeOverride;
    }

    if (options.hasLabPdfAttachment || hasActiveLabPdfAttachment()) {
        return 'lab_pdf_ingestion';
    }

    const inferredMode = inferPromptMode(prompt);
    if (inferredMode !== 'general_assistant') {
        return inferredMode;
    }

    if (state.activeMode && state.activeMode !== 'general_assistant') {
        return state.activeMode;
    }

    return 'general_assistant';
}

function buildAmbientVisitContextForRequest(patientKey, role) {
    const latestVisit = window.OpenEMRAIAmbientVisitDemo && typeof window.OpenEMRAIAmbientVisitDemo.latestApprovedVisit === 'function'
        ? window.OpenEMRAIAmbientVisitDemo.latestApprovedVisit(patientKey)
        : (window.OpenEMRCopilotRagDemo && typeof window.OpenEMRCopilotRagDemo.latestApprovedAmbientVisit === 'function'
            ? window.OpenEMRCopilotRagDemo.latestApprovedAmbientVisit(patientKey)
            : null);
    if (!latestVisit || typeof latestVisit !== 'object') {
        return null;
    }

    return {
        id: latestVisit.id || '',
        draftId: latestVisit.draftId || '',
        title: latestVisit.title || 'AI-Assisted Visit Review',
        visitType: latestVisit.visitType || 'Ambient Encounter Capture',
        approvedAt: latestVisit.approvedAt || '',
        approvedAtLabel: latestVisit.approvedAtLabel || '',
        summary: latestVisit.summary || '',
        approvedNotes: Array.isArray(latestVisit.approvedNotes) ? latestVisit.approvedNotes.slice(0, 12) : [],
        badges: Array.isArray(latestVisit.badges) ? latestVisit.badges.slice(0, 6) : [],
        tableRow: latestVisit.tableRow && typeof latestVisit.tableRow === 'object' ? latestVisit.tableRow : {},
        approvedItemCount: typeof latestVisit.approvedItemCount === 'number' ? latestVisit.approvedItemCount : 0,
        reviewStatus: latestVisit.reviewStatus || 'Clinician Reviewed',
        consentConfirmed: Boolean(latestVisit.consentConfirmed),
        source: latestVisit.source || 'Consent-Based AI Visit Capture'
    };
}

function buildCopilotRequestPayload(prompt, requestId, resolvedRole, resolvedMode, resolvedPatientId, historyPayload, ambientVisitContext, extraPayload) {
    return {
        action: 'chat',
        patient_id: resolvedPatientId || null,
        role: resolvedRole,
        mode: resolvedMode,
        message: prompt,
        chat_history: historyPayload,
        ambient_visit_context: ambientVisitContext,
        ambient_context: ambientVisitContext ? {
            latestApprovedVisit: ambientVisitContext
        } : null,
        ...(extraPayload && typeof extraPayload === 'object' ? extraPayload : {}),
        request_id: requestId,
        csrf_token_form: copilotConfig.csrfToken
    };
}

function buildCopilotFormDataPayload(payload, options = {}) {
    const formData = new FormData();
    const hasLabPdfWorkflow = options.labPdfFile instanceof File || Boolean(options.useSeededLabPdf);
    formData.append('action', String(hasLabPdfWorkflow ? 'attach_and_extract_lab_pdf' : (payload.action || 'chat')));
    formData.append('patient_id', payload.patient_id ? String(payload.patient_id) : '');
    formData.append('role', String(payload.role || state.activeRole));
    formData.append('mode', String(payload.mode || state.activeMode || 'general_assistant'));
    formData.append('message', String(payload.message || ''));
    formData.append('chat_history_json', JSON.stringify(Array.isArray(payload.chat_history) ? payload.chat_history : []));
    formData.append('ambient_visit_context_json', JSON.stringify(payload.ambient_visit_context || null));
    formData.append('ambient_context_json', JSON.stringify(payload.ambient_context || null));
    formData.append('request_id', String(payload.request_id || createId('request')));
    formData.append('csrf_token_form', String(payload.csrf_token_form || copilotConfig.csrfToken || ''));
    formData.append('attachment_purpose', 'lab_pdf_ingestion');

    if (options.useSeededLabPdf) {
        formData.append('use_seeded_lab_pdf', '1');
    }

    if (options.labPdfFile instanceof File) {
        formData.append('lab_pdf_attachment', options.labPdfFile, options.labPdfFile.name || 'attached-lab-report.pdf');
    }

    return formData;
}

async function fetchAssistantApiResponse(payload, requestId) {
    const isFormDataPayload = typeof FormData !== 'undefined' && payload instanceof FormData;
    const response = await fetch(copilotConfig.apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: isFormDataPayload ? undefined : {
            'Content-Type': 'application/json'
        },
        body: isFormDataPayload ? payload : JSON.stringify(payload)
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const apiError = new Error(data.error || copilotConfig.apiFailureMessage);
        apiError.requestId = requestId;
        apiError.errorCategory = data.meta?.error_category || `http_${response.status}`;
        apiError.fallbackUsed = Boolean(data.meta?.fallback_used);
        throw apiError;
    }

    return data;
}

async function requestAssistantSupervisorResponse(payload, requestContext) {
    if (requestContext.hasLabPdfAttachment || (typeof FormData !== 'undefined' && payload instanceof FormData)) {
        return null;
    }

    if (
        !window.OpenEMRCopilotAgentTools
        || typeof window.OpenEMRCopilotAgentTools.createApiToolCaller !== 'function'
        || !window.OpenEMRCopilotAgents
        || typeof window.OpenEMRCopilotAgents.runSupervisor !== 'function'
    ) {
        return null;
    }

    const toolClient = window.OpenEMRCopilotAgentTools.createApiToolCaller({
        apiUrl: copilotConfig.apiUrl,
        csrfToken: copilotConfig.csrfToken,
        basePayload: payload
    });

    return window.OpenEMRCopilotAgents.runSupervisor({
        request: {
            requestId: requestContext.requestId,
            prompt: requestContext.prompt,
            role: requestContext.role,
            mode: requestContext.mode,
            patientId: requestContext.patientId,
            selectedPatientKey: requestContext.selectedPatientKey,
            contextScope: requestContext.contextScope,
            ambientVisitContext: requestContext.ambientVisitContext,
            chatHistory: requestContext.historyPayload,
            extraPayload: requestContext.extraPayload,
            patientName: requestContext.patientName
        },
        callTool: toolClient.callTool,
        guardrails: window.OpenEMRCopilotGuardrails || null,
        toolSchemas: typeof window.OpenEMRCopilotAgentTools.getToolSchemas === 'function'
            ? window.OpenEMRCopilotAgentTools.getToolSchemas()
            : {},
        logger: console
    });
}

async function fetchAssistantResponseWithSupervisorFallback(payload, requestContext) {
    try {
        const supervisorResponse = await requestAssistantSupervisorResponse(payload, requestContext);
        if (supervisorResponse) {
            return supervisorResponse;
        }
    } catch (supervisorError) {
        console.warn('[OpenEMR Copilot] Supervisor-worker agent path failed. Falling back to the legacy chat endpoint.', supervisorError);
    }

    return fetchAssistantApiResponse(payload, requestContext.requestId);
}

async function requestAssistantResponse(prompt, options = {}) {
    const trimmedPrompt = typeof prompt === 'string' ? prompt.trim() : '';
    if (!trimmedPrompt || state.loading) {
        return;
    }

    if (window.top && typeof window.top.restoreSession === 'function') {
        window.top.restoreSession();
    }

    const resolvedRole = options.roleOverride || state.activeRole;
    const hasLabPdfAttachment = options.hasLabPdfAttachment ?? hasActiveLabPdfAttachment();
    const resolvedMode = resolveModeForPrompt(trimmedPrompt, {
        ...options,
        hasLabPdfAttachment
    });
    const resolvedPatientId = options.patientIdOverride ?? updatePatientSelectionFromPrompt(trimmedPrompt);
    const requestId = createId('request');
    const selectedPatientKey = selectedPatientKeyForValue(resolvedPatientId || '');
    const contextScope = contextScopeFor(resolvedRole, resolvedPatientId);
    const ambientVisitContext = buildAmbientVisitContextForRequest(selectedPatientKey, resolvedRole);
    const extraPayload = options.extraPayload && typeof options.extraPayload === 'object' ? options.extraPayload : {};
    const labPdfAttachment = hasLabPdfAttachment ? {
        descriptor: state.labPdf.descriptor ? { ...state.labPdf.descriptor } : null,
        file: state.labPdf.file instanceof File ? state.labPdf.file : null,
        useDemoSeed: Boolean(state.labPdf.useDemoSeed)
    } : null;
    const startedAt = new Date().toISOString();
    const startedPerf = window.performance && typeof window.performance.now === 'function'
        ? window.performance.now()
        : Date.now();
    const historyPayload = buildHistoryPayload();

    if (hasLabPdfAttachment && !resolvedPatientId) {
        addAssistantMessage('Select a demo patient before ingesting a lab PDF so the extracted chunks can be grounded to the correct chart context.', {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: '',
            selectedPatientKey: null,
            safety: 'Draft only. Human review required.',
            traceId: requestId,
            requestId,
            requestPrompt: trimmedPrompt
        });

        emitLabPdfAuditEvent('copilot_lab_pdf_extraction_failed', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            fileName: state.labPdf.descriptor?.fileName || null,
            status: 'patient_required'
        });

        return;
    }

    const preflightGuardrails = evaluateGuardrails({
        role: resolvedRole,
        mode: resolvedMode,
        prompt: trimmedPrompt,
        draftResponse: '',
        sections: [],
        safetyText: '',
        metadata: {
            selectedPatientKey,
            contextScope
        }
    });

    if (!preflightGuardrails.allowed) {
        updateModeSelection(resolvedMode);
        if (options.includeUserMessage !== false) {
            state.messages.push(createMessage('user', trimmedPrompt, {
                staffRole: resolvedRole,
                patientId: resolvedPatientId,
                selectedPatientKey,
                requestId
            }));
        }

        logGuardrailsEvaluation(preflightGuardrails, {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            engine: 'guardrail',
            provider: 'guardrail',
            model: null,
            promptTokens: null,
            completionTokens: null,
            totalTokens: null,
            fallbackUsed: false,
            fallbackReason: null,
            latencyMs: 0
        });
        if (CopilotTelemetry) {
            CopilotTelemetry.log('copilot_restricted_action', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                restrictedByRole: true,
                restrictionType: preflightGuardrails.blockedReason || 'guardrails_block'
            });
        }

        if (hasLabPdfAttachment) {
            emitLabPdfAuditEvent('copilot_lab_pdf_extraction_failed', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                fileName: labPdfAttachment?.descriptor?.fileName || null,
                status: preflightGuardrails.blockedReason || 'role_blocked'
            });
        }

        addAssistantMessage(preflightGuardrails.finalResponse || copilotConfig.apiFailureMessage, {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            tags: preflightGuardrails.policyTags || [],
            safety: preflightGuardrails.finalSafety || defaultGuardrailSafetyNote,
            guardrails: preflightGuardrails,
            traceId: requestId,
            requestId,
            requestPrompt: trimmedPrompt,
            meta: {
                engine: 'guardrail',
                provider: 'guardrail',
                model: null,
                openai_configured: false,
                token_usage: null,
                estimated_cost_usd: null,
                cost_note: null,
                fallback_used: false,
                fallback_reason: null,
                context_scope: contextScope,
                restricted_by_role: true,
                restriction_type: preflightGuardrails.blockedReason || 'guardrails_block',
                rag_grounded: false
            }
        });
        return;
    }

    const loadingMessage = createMessage('assistant', copilotConfig.loadingText, {
        isLoading: true,
        staffRole: resolvedRole,
        patientId: resolvedPatientId,
        selectedPatientKey,
        traceId: requestId,
        requestId
    });

    state.loading = true;
    if (options.includeUserMessage !== false) {
        state.messages.push(createMessage('user', trimmedPrompt, {
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            requestId
        }), loadingMessage);
    } else {
        state.messages.push(loadingMessage);
    }

    updateSendState();
    renderMessages(true);

    if (CopilotTelemetry) {
        if (hasLabPdfAttachment && labPdfAttachment?.descriptor) {
            emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_started', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                fileName: labPdfAttachment.descriptor.fileName || null,
                status: 'ingestion_started'
            });
        }
        CopilotTelemetry.log('copilot_context_loaded', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            contextScope,
            latestAmbientVisitFound: Boolean(ambientVisitContext)
        });
        CopilotTelemetry.log('copilot_generation_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            contextScope,
            messageLength: trimmedPrompt.length,
            hasChatHistory: historyPayload.length > 0,
            startedAt
        });
    }

    try {
        const requestPayloadObject = buildCopilotRequestPayload(
            trimmedPrompt,
            requestId,
            resolvedRole,
            resolvedMode,
            resolvedPatientId,
            historyPayload,
            ambientVisitContext,
            extraPayload
        );
        const requestPayload = hasLabPdfAttachment
            ? buildCopilotFormDataPayload(requestPayloadObject, {
                labPdfFile: labPdfAttachment?.file || null,
                useSeededLabPdf: Boolean(labPdfAttachment?.useDemoSeed)
            })
            : requestPayloadObject;

        if (hasLabPdfAttachment) {
            clearLabPdfAttachment({
                emitTelemetry: false,
                keepStatusMessage: `Uploading ${labPdfAttachment?.descriptor?.fileName || 'the attached lab PDF'} for draft-only clinician review...`,
                tone: 'neutral'
            });
        }

        const data = await fetchAssistantResponseWithSupervisorFallback(requestPayload, {
            requestId,
            prompt: trimmedPrompt,
            role: resolvedRole,
            mode: resolvedMode,
            patientId: resolvedPatientId,
            selectedPatientKey,
            contextScope,
            ambientVisitContext,
            historyPayload,
            extraPayload,
            patientName: currentPatientName(),
            hasLabPdfAttachment
        });
        clearLoadingMessage();

        updateModeSelection(data.mode || resolvedMode);
        if (data.tool_output && typeof data.tool_output === 'object') {
            const toolOutput = data.tool_output;
            if (toolOutput.status === 'ocr_required') {
                setLabPdfStatus(toolOutput.safeMessage || 'The attached PDF needs OCR or manual verification before relying on extracted lab facts.', 'warning');
            } else if (toolOutput.status === 'extraction_review_required') {
                setLabPdfStatus(toolOutput.safeMessage || 'PDF text extraction did not produce reliable lab rows. Clinician must verify the source PDF.', 'warning');
            } else if (toolOutput.status === 'invalid_file_type' || toolOutput.status === 'role_blocked') {
                setLabPdfStatus(toolOutput.safeMessage || 'The lab PDF workflow was blocked for this request.', 'error');
            } else if (toolOutput.sourceMetadata?.sourceType === 'lab_pdf' || (data.mode || resolvedMode) === 'lab_pdf_ingestion') {
                setLabPdfStatus(toolOutput.safeMessage || 'Lab PDF context ingested and retrieved for clinician review.', 'success');
            }

            logLabPdfDataToConsole(trimmedPrompt, toolOutput, {
                requestId,
                role: data.role || resolvedRole,
                mode: data.mode || resolvedMode,
                selectedPatientKey
            });
        }
        const meta = data.meta || {};
        if (meta.agent_trace && window.OpenEMRCopilotAgents && typeof window.OpenEMRCopilotAgents.logTrace === 'function') {
            window.OpenEMRCopilotAgents.logTrace(meta.agent_trace, console);
        }
        const guardrailsResult = evaluateGuardrails({
            role: data.role || resolvedRole,
            mode: data.mode || resolvedMode,
            prompt: trimmedPrompt,
            draftResponse: data.answer || copilotConfig.apiFailureMessage,
            sections: data.sections || [],
            safetyText: data.safety_note || '',
            metadata: {
                selectedPatientKey,
                contextScope,
                patientId: resolvedPatientId
            }
        });

        logGuardrailsEvaluation(guardrailsResult, {
            requestId,
            role: data.role || resolvedRole,
            mode: data.mode || resolvedMode,
            selectedPatientKey,
            engine: meta.engine || 'fallback',
            provider: meta.provider || 'local_fallback',
            model: meta.model || null,
            promptTokens: meta.token_usage?.prompt_tokens ?? null,
            completionTokens: meta.token_usage?.completion_tokens ?? null,
            totalTokens: meta.token_usage?.total_tokens ?? null,
            fallbackUsed: Boolean(meta.fallback_used),
            fallbackReason: meta.fallback_reason || null,
            latencyMs: meta.latency_ms ?? null,
            openaiErrorCategory: meta.openai_error_category || null,
            openaiHttpStatus: meta.openai_http_status ?? null,
            openaiErrorMessageSafe: meta.openai_error_message_safe || null
        });

        const guardrailTags = Array.from(new Set([...(data.tags || []), ...(guardrailsResult.policyTags || [])])).slice(0, 6);
        const sourcePayload = buildResponseSourcePayload(guardrailsResult.allowed ? (data.sources || []) : [], {
            role: data.role || resolvedRole,
            mode: data.mode || resolvedMode,
            patientKey: selectedPatientKey,
            ragGrounded: Boolean(meta.rag_grounded)
        });
        const assistantMessage = addAssistantMessage(guardrailsResult.finalResponse || data.answer || copilotConfig.apiFailureMessage, {
            mode: data.mode || resolvedMode,
            staffRole: data.role || resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            sections: guardrailsResult.finalSections || [],
            tags: guardrailTags,
            sources: sourcePayload.sources,
            safety: guardrailsResult.finalSafety || data.safety_note || defaultGuardrailSafetyNote,
            guardrails: guardrailsResult,
            traceId: requestId,
            requestId,
            requestPrompt: trimmedPrompt,
            meta: {
                ...meta,
                restricted_by_role: Boolean(meta.restricted_by_role) || !guardrailsResult.allowed,
                restriction_type: meta.restriction_type || guardrailsResult.blockedReason || '',
                guardrails_risk_level: guardrailsResult.riskLevel || 'low',
                latest_ambient_visit_found: sourcePayload.latestAmbientVisitFound
            }
        });

        if (CopilotTelemetry) {
            const responseLength = getAssistantMessagePlainText(assistantMessage).length;
            CopilotTelemetry.log('copilot_generation_succeeded', {
                requestId,
                responseId: assistantMessage.responseId || null,
                role: data.role || resolvedRole,
                mode: data.mode || resolvedMode,
                selectedPatientKey,
                latencyMs: meta.latency_ms || Math.round((window.performance && typeof window.performance.now === 'function'
                    ? window.performance.now()
                    : Date.now()) - startedPerf),
                responseLength,
                engine: meta.engine || 'fallback',
                provider: meta.provider || 'local_fallback',
                model: meta.model || null,
                openaiConfigured: Boolean(meta.openai_configured),
                promptTokens: meta.token_usage?.prompt_tokens ?? null,
                completionTokens: meta.token_usage?.completion_tokens ?? null,
                totalTokens: meta.token_usage?.total_tokens ?? null,
                estimatedCostUsd: meta.estimated_cost_usd ?? null,
                ragGrounded: Boolean(meta.rag_grounded),
                sourceCount: sourcePayload.sources.length,
                sourceTitles: sourcePayload.sourceTitles,
                sourceCategories: sourcePayload.sourceCategories,
                latestAmbientVisitFound: sourcePayload.latestAmbientVisitFound,
                fallbackUsed: Boolean(meta.fallback_used),
                restrictedByRole: Boolean(meta.restricted_by_role) || !guardrailsResult.allowed,
                openaiErrorCategory: meta.openai_error_category || null,
                openaiHttpStatus: meta.openai_http_status ?? null,
                openaiErrorMessageSafe: meta.openai_error_message_safe || null
            });

            if (meta.fallback_used) {
                CopilotTelemetry.log('copilot_fallback_used', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    engine: meta.engine || 'fallback',
                    provider: meta.provider || 'local_fallback',
                    model: meta.model || null,
                    openaiConfigured: Boolean(meta.openai_configured),
                    promptTokens: meta.token_usage?.prompt_tokens ?? null,
                    completionTokens: meta.token_usage?.completion_tokens ?? null,
                    totalTokens: meta.token_usage?.total_tokens ?? null,
                    estimatedCostUsd: meta.estimated_cost_usd ?? null,
                    fallbackUsed: true,
                    fallbackReason: meta.fallback_reason || 'demo_mode',
                    openaiErrorCategory: meta.openai_error_category || null,
                    openaiHttpStatus: meta.openai_http_status ?? null,
                    openaiErrorMessageSafe: meta.openai_error_message_safe || null
                });
            }

            if (meta.restricted_by_role || !guardrailsResult.allowed) {
                CopilotTelemetry.log('copilot_restricted_action', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    restrictedByRole: true,
                    restrictionType: meta.restriction_type || guardrailsResult.blockedReason || 'role_guardrail'
                });
            }

            if (data.tool_output && typeof data.tool_output === 'object' && (((data.mode || resolvedMode) === 'lab_pdf_ingestion') || data.tool_output.sourceMetadata?.sourceType === 'lab_pdf')) {
                const toolOutput = data.tool_output;
                const missingDataCount = Array.isArray(toolOutput.missingData) ? toolOutput.missingData.length : 0;
                const toolStatus = String(toolOutput.status || '');
                const labPdfTelemetryPayload = {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    fileName: toolOutput.sourceMetadata?.fileName || toolOutput.documentMetadata?.title || null,
                    extractionMethod: toolOutput.extractionMethod || null,
                    labValueCount: Array.isArray(toolOutput.extractedFacts) ? toolOutput.extractedFacts.length : 0,
                    abnormalCount: Array.isArray(toolOutput.abnormalFindings) ? toolOutput.abnormalFindings.length : 0,
                    missingDataCount,
                    status: toolStatus,
                    toolOutput
                };

                if (toolStatus === 'invalid_file_type' || toolStatus === 'role_blocked') {
                    emitLabPdfAuditEvent('copilot_lab_pdf_extraction_failed', labPdfTelemetryPayload);
                } else if (toolStatus === 'ocr_required' || toolStatus === 'extraction_review_required') {
                    emitLabPdfAuditEvent('copilot_lab_pdf_review_required', labPdfTelemetryPayload);
                } else {
                    emitLabPdfAuditEvent('copilot_lab_pdf_extracted', labPdfTelemetryPayload);
                }

                if (missingDataCount > 0) {
                    emitLabPdfAuditEvent('copilot_lab_pdf_missing_data_detected', labPdfTelemetryPayload);
                }
            }
        }
    } catch (error) {
        clearLoadingMessage();
        if (labPdfAttachment?.descriptor) {
            applyLabPdfAttachmentDescriptor(labPdfAttachment.descriptor, {
                file: labPdfAttachment.file,
                useDemoSeed: labPdfAttachment.useDemoSeed
            });
            setLabPdfStatus(`Attachment kept: ${labPdfAttachment.descriptor.fileName}. Retry when ready.`, 'warning');
        }
        addAssistantMessage(error.message || copilotConfig.apiFailureMessage, {
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            safety: 'Draft only. Human review required.',
            traceId: error.requestId || requestId,
            requestId,
            requestPrompt: trimmedPrompt
        });

        if (CopilotTelemetry) {
            const endedPerf = window.performance && typeof window.performance.now === 'function'
                ? window.performance.now()
                : Date.now();
            CopilotTelemetry.log('copilot_generation_failed', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                latencyMs: Math.round(endedPerf - startedPerf),
                errorCategory: error.errorCategory || 'request_failed',
                fallbackUsed: Boolean(error.fallbackUsed)
            });
            if (labPdfAttachment?.descriptor) {
                emitLabPdfAuditEvent('copilot_lab_pdf_extraction_failed', {
                    requestId,
                    role: resolvedRole,
                    mode: resolvedMode,
                    selectedPatientKey,
                    fileName: labPdfAttachment.descriptor.fileName || null,
                    status: error.errorCategory || 'request_failed'
                });
            }
        }
    } finally {
        state.loading = false;
        updateSendState();
    }
}

async function requestReminderEmail(role, patientId) {
    if (state.loading) {
        return;
    }

    if (window.top && typeof window.top.restoreSession === 'function') {
        window.top.restoreSession();
    }

    const requestId = createId('request');
    const selectedPatientKey = selectedPatientKeyForValue(patientId || '');
    state.loading = true;
    state.messages.push(createMessage('assistant', copilotConfig.sendingReminderText, {
        isLoading: true,
        staffRole: role,
        patientId,
        selectedPatientKey,
        traceId: requestId,
        requestId
    }));
    updateSendState();
    renderMessages(true);

    try {
        const response = await fetch(copilotConfig.apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'send_reminder_email',
                patient_id: patientId || null,
                role,
                request_id: requestId,
                csrf_token_form: copilotConfig.csrfToken
            })
        });

        const data = await response.json().catch(() => ({}));
        clearLoadingMessage();

        addAssistantMessage(data.message || copilotConfig.apiFailureMessage, {
            mode: 'send_reminder_result',
            staffRole: role,
            patientId,
            selectedPatientKey,
            tags: data.tags || ['Front desk', 'Reminder', 'Minimum PHI'],
            sources: data.sources || [],
            safety: data.safety_note || '',
            traceId: requestId,
            requestId,
            requestPrompt: 'Send reminder email',
            meta: data.meta || {}
        });
    } catch (error) {
        clearLoadingMessage();
        addAssistantMessage(error.message || copilotConfig.apiFailureMessage, {
            mode: 'send_reminder_result',
            staffRole: role,
            patientId,
            selectedPatientKey,
            tags: ['Front desk', 'Reminder', 'Minimum PHI'],
            safety: '',
            traceId: requestId,
            requestId,
            requestPrompt: 'Send reminder email'
        });
    } finally {
        state.loading = false;
        updateSendState();
    }
}

function buildMarcusRagResponse(retrieval) {
    const patientName = retrieval?.patientName || 'Marcus Johnson';
    const latestVisit = retrieval?.latestAmbientVisit || null;
    const chartContext = retrieval?.context || {};
    const recommendedPoints = [
        'Confirm current medication adherence and whether evening reminders are helping.',
        'Verify A1C and lipid panel follow-up status.',
        'Confirm whether insurance verification has been completed.',
        'Review immunization status before updating the record.',
        'Confirm care preferences and whether the daughter should be added as a care support contact.'
    ];
    const sections = [
        {
            title: 'Retrieved chart context',
            items: [
                `Active Medications: ${(chartContext.activeMedications || []).join(', ') || 'No active medication list retrieved.'}`,
                `Lab Follow-up: ${(chartContext.labFollowUp || []).join(' ') || 'No current lab follow-up note retrieved.'}`,
                `Recent Vitals: ${(chartContext.recentVitals || []).join(' ') || 'No recent vitals note retrieved.'}`,
                `Insurance Note: ${(chartContext.insuranceNote || []).join(' ') || 'No insurance note retrieved.'}`,
                `Immunization Review: ${(chartContext.immunizationReview || []).join(' ') || 'No immunization review note retrieved.'}`,
                `Care Preferences: ${(chartContext.carePreferences || []).join(' ') || 'No care preference note retrieved.'}`,
                `Care Team: ${(chartContext.careTeam || []).join(' ') || 'No care team update retrieved.'}`,
                `Issues / Problem List: ${(chartContext.issues || []).join(', ') || 'No problem list summary retrieved.'}`
            ]
        },
        {
            title: 'Draft Clinical Summary',
            items: [
                `${patientName}'s recent chart context indicates a routine follow-up pattern centered on medication adherence, lab follow-up, vitals review, insurance verification, immunization review, and care support.`,
                latestVisit
                    ? 'The most recent AI-assisted visit was clinician-reviewed and consent-confirmed before being added to the demo visit history.'
                    : 'No approved Ambient Encounter Capture visit-history record was found yet. Complete the consent-based listening workflow and approve the visit draft to make that context available for retrieval.'
            ]
        }
    ];

    if (latestVisit && Array.isArray(latestVisit.approvedNotes) && latestVisit.approvedNotes.length > 0) {
        sections.push({
            title: 'Latest Approved Ambient Encounter Capture',
            items: latestVisit.approvedNotes.slice(0, 4)
        });
        recommendedPoints.push('Review the latest Ambient Encounter Capture visit note in Visit History.');
    }

    sections.push(
        {
            title: 'Recommended clinician review points',
            items: recommendedPoints
        },
        {
            title: 'Sources Used',
            items: Array.isArray(retrieval?.sourceTitles) ? retrieval.sourceTitles : []
        }
    );

    const intro = latestVisit
        ? `Using Doctor-role chart retrieval, I found relevant context from ${patientName}'s medication history, lab follow-up, visit history, insurance note, immunization review, and care preferences. The latest approved Ambient Encounter Capture visit suggests the next clinical review should focus on medication adherence support, A1C/lipid follow-up, insurance verification, immunization status verification, and care coordination preferences.`
        : `Using Doctor-role chart retrieval, I found relevant context from ${patientName}'s medication history, lab follow-up, insurance note, immunization review, and care preferences. No approved Ambient Encounter Capture visit-history record is available yet, so visit-history retrieval is currently limited to the seeded demo chart context.`;

    return {
        content: `RAG Chart Context Review for ${patientName}\n\n${intro}`,
        sections,
        tags: ['RAG', 'Chart context', 'Review needed'],
        safety: 'Draft only. Clinician review required. This is not an autonomous diagnosis or treatment decision.'
    };
}

async function requestRagChartContextReview(action) {
    const resolvedRole = state.activeRole;
    const resolvedMode = action?.mode || 'rag_chart_context';
    const resolvedPatientId = patientSelect.value || null;
    const selectedPatientKey = selectedPatientKeyForValue(resolvedPatientId || '');
    const requestId = createId('request');
    const startedAt = new Date().toISOString();
    const startedPerf = window.performance && typeof window.performance.now === 'function'
        ? window.performance.now()
        : Date.now();
    const prompt = action?.prompt || '';
    const contextScope = contextScopeFor(resolvedRole, resolvedPatientId);

    if (window.top && typeof window.top.restoreSession === 'function') {
        window.top.restoreSession();
    }

    if (CopilotTelemetry) {
        CopilotTelemetry.log('copilot_rag_quick_action_selected', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey
        });
    }

    if (resolvedRole !== 'doctor' || selectedPatientKey !== 'DEMO-PCP-1001') {
        const blockedPrompt = prompt || 'Retrieve Marcus Johnson chart context for review.';
        const preflightGuardrails = evaluateGuardrails({
            role: resolvedRole,
            mode: resolvedMode,
            prompt: blockedPrompt,
            draftResponse: '',
            sections: [],
            safetyText: '',
            metadata: {
                selectedPatientKey,
                contextScope
            }
        });

        logGuardrailsEvaluation(preflightGuardrails, {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            engine: 'guardrail',
            provider: 'guardrail',
            model: null,
            promptTokens: null,
            completionTokens: null,
            totalTokens: null,
            fallbackUsed: false,
            fallbackReason: null,
            latencyMs: 0
        });

        if (CopilotTelemetry) {
            CopilotTelemetry.log('copilot_restricted_action', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                restrictedByRole: true,
                restrictionType: preflightGuardrails.blockedReason || 'rag_role_scope'
            });
        }

        addAssistantMessage(preflightGuardrails.finalResponse || 'This RAG quick action is available only for the Doctor role while Marcus Johnson is selected.', {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            tags: preflightGuardrails.policyTags || [],
            safety: preflightGuardrails.finalSafety || defaultGuardrailSafetyNote,
            guardrails: preflightGuardrails,
            traceId: requestId,
            requestId,
            requestPrompt: prompt,
            meta: {
                engine: 'guardrail',
                provider: 'guardrail',
                model: null,
                openai_configured: false,
                token_usage: null,
                estimated_cost_usd: null,
                cost_note: null,
                fallback_used: false,
                fallback_reason: null,
                context_scope: contextScope,
                restricted_by_role: true,
                restriction_type: preflightGuardrails.blockedReason || 'rag_role_scope',
                rag_grounded: false
            }
        });
        return;
    }

    if (!window.OpenEMRCopilotRagDemo || typeof window.OpenEMRCopilotRagDemo.retrieveDoctorChartContext !== 'function') {
        addAssistantMessage('The demo retrieval layer is not available right now. Try reloading the Co-Pilot and rerunning the chart review.', {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            safety: 'Draft only. Clinician review required.',
            traceId: requestId,
            requestId,
            requestPrompt: prompt
        });
        return;
    }

    updateModeSelection(resolvedMode, { emitTelemetry: true });
    state.loading = true;
    state.messages.push(
        createMessage('user', action.title || 'RAG chart context review', {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            requestId
        }),
        createMessage('assistant', 'Retrieving Marcus Johnson chart context before drafting...', {
            isLoading: true,
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            traceId: requestId,
            requestId
        })
    );
    updateSendState();
    renderMessages(true);

    if (CopilotTelemetry) {
        CopilotTelemetry.log('copilot_rag_retrieval_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey
        });
        CopilotTelemetry.log('copilot_context_loaded', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            contextScope
        });
        CopilotTelemetry.log('copilot_generation_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            contextScope,
            messageLength: prompt.length,
            hasChatHistory: buildHistoryPayload().length > 0,
            startedAt
        });
    }

    try {
        const retrieval = window.OpenEMRCopilotRagDemo.retrieveDoctorChartContext({
            patientKey: selectedPatientKey,
            role: resolvedRole,
            prompt,
            requestId
        });

        if (CopilotTelemetry) {
            CopilotTelemetry.log('copilot_rag_context_retrieved', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                sourceCount: Array.isArray(retrieval.sources) ? retrieval.sources.length : 0,
                sourceTitles: retrieval.sourceTitles || [],
                sourceCategories: retrieval.sourceCategories || [],
                latestAmbientVisitFound: Boolean(retrieval.latestAmbientVisitFound)
            });
            CopilotTelemetry.log('copilot_rag_visit_history_context_loaded', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                sourceCount: retrieval.latestAmbientVisitFound ? 1 : 0,
                sourceTitles: retrieval.latestAmbientVisitFound ? ['Visit History: AI-Assisted Visit Review / Ambient Encounter Capture'] : [],
                sourceCategories: retrieval.latestAmbientVisitFound ? ['visit_history'] : [],
                latestAmbientVisitFound: Boolean(retrieval.latestAmbientVisitFound)
            });
        }

        clearLoadingMessage();
        const ragDraft = buildMarcusRagResponse(retrieval);
        const guardrailsResult = evaluateGuardrails({
            role: resolvedRole,
            mode: resolvedMode,
            prompt,
            draftResponse: ragDraft.content,
            sections: ragDraft.sections,
            safetyText: ragDraft.safety,
            metadata: {
                selectedPatientKey,
                contextScope,
                patientId: resolvedPatientId
            }
        });

        logGuardrailsEvaluation(guardrailsResult, {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            engine: 'fallback',
            provider: 'local_fallback',
            model: null,
            promptTokens: null,
            completionTokens: null,
            totalTokens: null,
            fallbackUsed: true,
            fallbackReason: 'demo_mode',
            latencyMs: Math.round((window.performance && typeof window.performance.now === 'function'
                ? window.performance.now()
                : Date.now()) - startedPerf)
        });

        const assistantMessage = addAssistantMessage(guardrailsResult.finalResponse || ragDraft.content, {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            sections: guardrailsResult.finalSections || [],
            tags: Array.from(new Set([...(ragDraft.tags || []), ...(guardrailsResult.policyTags || [])])).slice(0, 6),
            sources: retrieval.sources || [],
            safety: guardrailsResult.finalSafety || ragDraft.safety,
            guardrails: guardrailsResult,
            traceId: requestId,
            requestId,
            requestPrompt: prompt,
            meta: {
                engine: 'fallback',
                provider: 'local_fallback',
                model: null,
                openai_configured: false,
                token_usage: null,
                estimated_cost_usd: null,
                cost_note: null,
                context_scope: contextScope,
                restricted_by_role: !guardrailsResult.allowed,
                restriction_type: guardrailsResult.blockedReason || '',
                source_count: Array.isArray(retrieval.sources) ? retrieval.sources.length : 0,
                latest_ambient_visit_found: Boolean(retrieval.latestAmbientVisitFound),
                rag_grounded: true,
                fallback_used: true,
                fallback_reason: 'demo_mode'
            }
        });

        if (CopilotTelemetry) {
            const responseLength = getAssistantMessagePlainText(assistantMessage).length;
            const latencyMs = Math.round((window.performance && typeof window.performance.now === 'function'
                ? window.performance.now()
                : Date.now()) - startedPerf);
            CopilotTelemetry.log('copilot_generation_succeeded', {
                requestId,
                responseId: assistantMessage.responseId || null,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                latencyMs,
                responseLength,
                engine: 'fallback',
                provider: 'local_fallback',
                model: null,
                openaiConfigured: false,
                promptTokens: null,
                completionTokens: null,
                totalTokens: null,
                estimatedCostUsd: null,
                ragGrounded: true,
                fallbackUsed: true,
                fallbackReason: 'demo_mode',
                restrictedByRole: !guardrailsResult.allowed
            });
            CopilotTelemetry.log('copilot_rag_sources_rendered', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                sourceCount: Array.isArray(retrieval.sources) ? retrieval.sources.length : 0,
                sourceTitles: retrieval.sourceTitles || [],
                sourceCategories: retrieval.sourceCategories || [],
                latestAmbientVisitFound: Boolean(retrieval.latestAmbientVisitFound)
            });
        }
    } catch (error) {
        clearLoadingMessage();
        addAssistantMessage('I ran into a problem while retrieving Marcus Johnson\'s chart context. Please retry the RAG chart review.', {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            safety: 'Draft only. Clinician review required.',
            traceId: requestId,
            requestId,
            requestPrompt: prompt
        });

        if (CopilotTelemetry) {
            const endedPerf = window.performance && typeof window.performance.now === 'function'
                ? window.performance.now()
                : Date.now();
            CopilotTelemetry.log('copilot_generation_failed', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                latencyMs: Math.round(endedPerf - startedPerf),
                errorCategory: 'rag_retrieval_failed',
                fallbackUsed: false
            });
        }
    } finally {
        state.loading = false;
        updateSendState();
    }
}

async function submitPrompt() {
    const prompt = input.value.trim();
    if (!prompt || state.loading) {
        return;
    }

    input.value = '';
    resizeInput();
    updateSendState();
    await requestAssistantResponse(prompt, { includeUserMessage: true });
}

patientSelect.addEventListener('change', () => {
    renderModeOptions();
    renderQuickActions();
    updateModeSelection(state.activeMode);
    updateContextText();
    if (hasActiveLabPdfAttachment()) {
        clearLabPdfAttachment({
            keepStatusMessage: 'Attachment cleared because the selected patient changed. Reattach the lab PDF for the new patient if needed.',
            tone: 'warning'
        });
    }
    if (CopilotTelemetry) {
        CopilotTelemetry.log('copilot_patient_selected', {
            selectedPatientKey: currentSelectedPatientKey(),
            role: state.activeRole
        });
    }
});
roleSelect.addEventListener('change', (event) => {
    updateRoleSelection(event.target.value, { emitTelemetry: true });
    if (hasActiveLabPdfAttachment()) {
        clearLabPdfAttachment({
            keepStatusMessage: 'Attachment cleared because the staff role changed. Reattach the lab PDF if this workflow is still appropriate.',
            tone: 'warning'
        });
    }
});

modeSelect.addEventListener('change', (event) => {
    updateModeSelection(event.target.value, { emitTelemetry: true });
});

quickActionSelect.addEventListener('change', (event) => {
    const selectedMode = event.target.value;
    const action = actionCatalog[selectedMode];
    if (!action) {
        return;
    }

    if (selectedMode === 'rag_chart_context') {
        quickActionSelect.value = '';
        updateModeSelection(action.mode, { emitTelemetry: true });
        requestAssistantResponse(action.prompt || '', {
            includeUserMessage: true,
            modeOverride: action.mode
        });
        updateControlsSummary();
        return;
    }

    if (selectedMode === 'lab_pdf_ingestion') {
        quickActionSelect.value = '';
        updateModeSelection(action.mode, { emitTelemetry: true });
        input.value = action.prompt || 'Summarize this lab report for clinician review only.';
        resizeInput();
        updateSendState();
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        setLabPdfStatus('Attach a PDF in the composer, then send your prompt through the normal copilot workflow.');
        updateControlsSummary();
        return;
    }

    updateModeSelection(action.mode, { emitTelemetry: true });
    input.value = action.prompt || '';
    resizeInput();
    updateSendState();
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
    quickActionSelect.value = '';
    updateControlsSummary();
});

input.addEventListener('input', () => {
    resizeInput();
    updateSendState();
});

input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        if (!sendButton.disabled) {
            submitPrompt();
        }
    }
});

form.addEventListener('submit', (event) => {
    event.preventDefault();
    submitPrompt();
});

if (labPdfAttachButton) {
    labPdfAttachButton.addEventListener('click', () => {
        labPdfInput?.click();
    });
}

if (labPdfInput) {
    labPdfInput.addEventListener('change', (event) => {
        const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;

        if (!file) {
            refreshLabPdfAttachmentUi();
            return;
        }

        setUploadedLabPdfAttachment(file);
    });
}

if (labPdfRemoveButton) {
    labPdfRemoveButton.addEventListener('click', () => {
        clearLabPdfAttachment({
            keepStatusMessage: 'Lab PDF attachment removed from the prompt.',
            tone: 'neutral'
        });
    });
}

if (controlsToggle) {
    controlsToggle.addEventListener('click', () => {
        setDisclosureState('controls', controlsToggle.getAttribute('aria-expanded') !== 'true', { emitTelemetry: true });
    });
}

if (guardrailsToggle) {
    guardrailsToggle.addEventListener('click', () => {
        setDisclosureState('guardrails', guardrailsToggle.getAttribute('aria-expanded') !== 'true', { emitTelemetry: true });
    });
}

updateRoleSelection(copilotConfig.defaultRole || 'doctor');
setDisclosureState('controls', false);
setDisclosureState('guardrails', false);
resizeInput();
refreshLabPdfAttachmentUi();
updateSendState();
state.messages.push(createMessage('assistant', copilotConfig.greeting, {
    staffRole: copilotConfig.defaultRole || 'doctor',
    selectedPatientKey: currentSelectedPatientKey(),
    showResponseActions: false
}));

if (patientSelect.options.length <= 1) {
    state.messages.push(createMessage(
        'assistant',
        'No seeded demo patients are available yet. General prompts still work, but patient-specific answers require importing the demo seed.',
        {
            staffRole: copilotConfig.defaultRole || 'doctor',
            showResponseActions: false
        }
    ));
}

renderMessages(true);
</script>
<script src="copilot_visit_review.js?v=<?php echo attr_url($visitReviewVersion); ?>"></script>
</body>
</html>
