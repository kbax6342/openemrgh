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
use OpenEMR\Core\OEGlobalsBag;

$isEmbedded = !empty($_GET['embedded']) && $_GET['embedded'] === '1';
$isHealthCheck = !empty($_GET['healthcheck']) && $_GET['healthcheck'] === '1';
$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (empty($session->get('csrf_private_key'))) {
    CsrfUtils::setupCsrfKey($session);
}

if ($isHealthCheck) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode([
        'ok' => true,
        'embedded' => $isEmbedded,
        'openemrReachable' => true,
        'sessionActive' => !empty($session->get('authUser')),
        'requiresLogin' => empty($session->get('authUser')),
        'copilotUrl' => OEGlobalsBag::getInstance()->getWebRoot() . '/interface/ai_copilot/index.php?embedded=1',
        'loginUrl' => OEGlobalsBag::getInstance()->getWebRoot() . '/interface/login/login.php',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
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
    'citationSourceUrl' => 'api/citation_source.php',
    'documentPreviewUrl' => 'api/document_preview.php',
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
$redactionVersion = file_exists(__DIR__ . '/observability/redact_phi.js') ? (string) filemtime(__DIR__ . '/observability/redact_phi.js') : '1';
$observabilityVersion = file_exists(__DIR__ . '/observability/copilot_observability.js') ? (string) filemtime(__DIR__ . '/observability/copilot_observability.js') : '1';
$guardrailsVersion = file_exists(__DIR__ . '/copilot_guardrails.js') ? (string) filemtime(__DIR__ . '/copilot_guardrails.js') : '1';
$agentTraceVersion = file_exists(__DIR__ . '/agents/copilot_agent_trace.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agent_trace.js') : '1';
$agentSafetyVersion = file_exists(__DIR__ . '/agents/copilot_agent_safety.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agent_safety.js') : '1';
$agentToolVersion = file_exists(__DIR__ . '/agents/copilot_agent_tools.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agent_tools.js') : '1';
$agentVersion = file_exists(__DIR__ . '/agents/copilot_agents.js') ? (string) filemtime(__DIR__ . '/agents/copilot_agents.js') : '1';
$ragDemoVersion = file_exists(__DIR__ . '/copilot_rag_demo_data.js') ? (string) filemtime(__DIR__ . '/copilot_rag_demo_data.js') : '1';
$labPdfVersion = file_exists(__DIR__ . '/lab_pdf_ingestion.js') ? (string) filemtime(__DIR__ . '/lab_pdf_ingestion.js') : '1';
$visitReviewVersion = file_exists(__DIR__ . '/copilot_visit_review.js') ? (string) filemtime(__DIR__ . '/copilot_visit_review.js') : '1';
$modelCostConfig = [];
if (is_file(__DIR__ . '/observability/model_cost_config.json')) {
    $decodedModelCostConfig = json_decode((string) file_get_contents(__DIR__ . '/observability/model_cost_config.json'), true);
    if (is_array($decodedModelCostConfig)) {
        $modelCostConfig = $decodedModelCostConfig;
    }
}
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
                            <label class="copilot-visually-hidden" for="copilot-document-type-select"><?php echo xlt('Document type'); ?></label>
                            <select
                                id="copilot-document-type-select"
                                class="form-control copilot-select copilot-document-type-select"
                                aria-label="<?php echo attr(xl('Document type')); ?>"
                            >
                                <option value="lab_pdf"><?php echo xlt('Lab PDF'); ?></option>
                                <option value="intake_form"><?php echo xlt('Intake Form'); ?></option>
                            </select>
                            <button
                                type="button"
                                id="copilot-lab-pdf-attach"
                                class="copilot-attach-button"
                                aria-label="<?php echo attr(xl('Attach PDF')); ?>"
                                title="<?php echo attr(xl('Attach PDF')); ?>"
                            >
                                <span><?php echo xlt('Attach PDF'); ?></span>
                            </button>
                            <div id="copilot-lab-pdf-chip" class="copilot-attachment-chip" hidden>
                                <span id="copilot-lab-pdf-chip-text" class="copilot-attachment-chip-text"></span>
                                <button
                                    type="button"
                                    id="copilot-lab-pdf-remove"
                                    class="copilot-attachment-remove"
                                    aria-label="<?php echo attr(xl('Remove attached PDF')); ?>"
                                    title="<?php echo attr(xl('Remove attached PDF')); ?>"
                                >
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <button
                                type="button"
                                id="copilot-lab-evidence-clear"
                                class="copilot-attach-button"
                                aria-label="<?php echo attr(xl('Clear Lab Evidence')); ?>"
                                title="<?php echo attr(xl('Clear uploaded lab evidence for the selected demo patient')); ?>"
                            >
                                <span><?php echo xlt('Clear Lab Evidence'); ?></span>
                            </button>
                        </div>
                        <label class="copilot-visually-hidden" for="copilot-input"><?php echo xlt('Medical Co-Pilot prompt'); ?></label>
                        <textarea
                            id="copilot-input"
                            class="copilot-input"
                            rows="1"
                            placeholder="<?php echo attr(xl('Ask about the chart or enter a clinical support prompt...')); ?>"
                        ></textarea>
                        <div id="copilot-upload-notice" class="copilot-upload-notice" aria-live="polite" hidden></div>
                        <p id="copilot-lab-pdf-status" class="copilot-lab-pdf-status"><?php echo xlt('Attach a PDF for draft-only clinician review.'); ?></p>
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
<script>
window.OPENEMR_AI_COPILOT_MODEL_COST_CONFIG = <?php echo json_encode($modelCostConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
</script>
<script src="observability/redact_phi.js?v=<?php echo attr_url($redactionVersion); ?>"></script>
<script src="observability/copilot_observability.js?v=<?php echo attr_url($observabilityVersion); ?>"></script>
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
const documentTypeSelect = document.getElementById('copilot-document-type-select');
const labPdfAttachButton = document.getElementById('copilot-lab-pdf-attach');
const labPdfChip = document.getElementById('copilot-lab-pdf-chip');
const labPdfChipText = document.getElementById('copilot-lab-pdf-chip-text');
const labPdfRemoveButton = document.getElementById('copilot-lab-pdf-remove');
const labEvidenceClearButton = document.getElementById('copilot-lab-evidence-clear');
const uploadNotice = document.getElementById('copilot-upload-notice');
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
        useDemoSeed: false,
        requestId: null,
        selectedDocumentType: 'lab_pdf'
    },
    citationPreview: {
        open: false,
        loading: false,
        error: '',
        data: null,
        title: ''
    }
};

const copiedStateTimers = new Map();
let uploadNoticeTimer = null;
const reviewSubmissionState = new Set();
let citationPreviewShell = null;

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

function restoreSessionIfAvailable() {
    try {
        if (window.top && typeof window.top.restoreSession === 'function') {
            window.top.restoreSession();
            return true;
        }
    } catch (error) {
        return false;
    }

    return false;
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
        'encounterId',
        'sessionIdHash',
        'role',
        'selectedRole',
        'previousRole',
        'newRole',
        'mode',
        'selectedMode',
        'selectedPatientKey',
        'patientContextPresent',
        'patientContextHash',
        'patientIdentifierRedacted',
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
        'tokenUsageEstimated',
        'costNote',
        'openaiErrorCategory',
        'openaiHttpStatus',
        'openaiErrorMessageSafe',
        'stepName',
        'toolName',
        'toolStatus',
        'retrievalHitCount',
        'topK',
        'retrievalMode',
        'rerankProvider',
        'sparseHitCount',
        'denseHitCount',
        'hybridCandidateCount',
        'rerankedHitCount',
        'finalEvidenceCount',
        'topSourceTypes',
        'attachmentPurpose',
        'documentTitle',
        'documentType',
        'documentSource',
        'schemaName',
        'schemaValid',
        'validationErrorCount',
        'missingRequiredFieldCount',
        'citationCount',
        'claimCount',
        'citedClaimCount',
        'uncitedClaimCount',
        'invalidCitationCount',
        'blockedClaimCount',
        'citationContractStatus',
        'extractionStatus',
        'extractionMethod',
        'requestedDocumentTypes',
        'matchedSourceTitles',
        'chunkCount',
        'retrievedChunkCount',
        'retrievedChunkIds',
        'uploadedAt',
        'guardrailTriggered',
        'missingDataCount',
        'documentGuardProvider',
        'medicalEntityCount',
        'confidence',
        'rejectionReason',
        'documentGuardDecision',
        'awsGuardEnabled',
        'textExtractionStatus',
        'medicalValidationStatus',
        'chartWriteStatus',
        'syntheticDemoData',
        'reviewRequired',
        'labEvidenceScore',
        'seededDemo',
        'ragGrounded',
        'sourceCount',
        'sourceTitles',
        'sourceCategories',
        'latestAmbientVisitFound',
        'docType',
        'extractionStatus',
        'citationContractValid',
        'extractedFactCount',
        'evalCaseId',
        'evalPassed',
        'evalRubricFailures',
        'regressionGateStatus',
        'safeRefusal',
        'phiRedacted',
        'rawDocumentTextLogged',
        'rawScreenshotLogged',
        'screenshotCaptureAttempted',
        'screenshotBlockedReason'
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
        const helper = window.OpenEMRCopilotRedaction;
        if (helper && typeof helper.sanitizeTelemetryPayload === 'function') {
            return helper.sanitizeTelemetryPayload(payload || {}, allowedKeys);
        }

        const safePayload = {};
        Object.keys(payload || {}).forEach((key) => {
            if (!allowedKeys.has(key)) {
                return;
            }
            const value = payload[key];
            if (value === undefined || value === null || value === '') {
                return;
            }
            if (key === 'selectedPatientKey') {
                safePayload.patientContextPresent = true;
                safePayload.patientIdentifierRedacted = true;
                return;
            }
            safePayload[key] = value;
        });
        safePayload.phiRedacted = true;
        safePayload.rawDocumentTextLogged = false;
        safePayload.rawScreenshotLogged = false;
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

function currentSafePatientTelemetryContext() {
    const helper = window.OpenEMRCopilotObservability;
    const selectedPatientKey = currentSelectedPatientKey();
    if (helper && typeof helper.hashIdentifier === 'function') {
        return {
            patientContextPresent: Boolean(selectedPatientKey),
            patientContextHash: selectedPatientKey ? helper.hashIdentifier(selectedPatientKey) : null,
            patientIdentifierRedacted: true
        };
    }

    return {
        patientContextPresent: Boolean(selectedPatientKey),
        patientContextHash: null,
        patientIdentifierRedacted: true
    };
}

function currentVisibleQuickActions() {
    return availableQuickActions().map((action) => action.mode);
}

function buildBasicHybridRagAuditPayload(meta, options = {}) {
    const safeMeta = meta && typeof meta === 'object' ? meta : {};
    return {
        requestId: options.requestId || null,
        role: options.role || state.activeRole,
        mode: options.mode || 'general_assistant',
        selectedPatientKey: options.selectedPatientKey || currentSelectedPatientKey(),
        retrievalMode: String(safeMeta.retrieval_mode || '').trim() || 'no_grounded_evidence',
        rerankProvider: String(safeMeta.rerank_provider || '').trim() || 'fallback_score_sort',
        guidelineChunkCount: Number(safeMeta.guideline_chunk_count || 0),
        uploadedChunkCount: Number(safeMeta.uploaded_chunk_count || 0),
        sparseResultCount: Number(safeMeta.sparse_result_count || 0),
        denseResultCount: Number(safeMeta.dense_result_count || 0),
        hybridCandidateCount: Number(safeMeta.hybrid_candidate_count || 0),
        rerankedResultCount: Number(safeMeta.reranked_result_count || 0),
        sourceCount: Number(safeMeta.source_count || 0),
        claimCount: Number(safeMeta.claim_count || 0),
        ragGrounded: Boolean(safeMeta.rag_grounded)
    };
}

function emitBasicHybridRagAuditEvents(meta, options = {}) {
    if (!CopilotTelemetry || !meta || typeof meta !== 'object') {
        return;
    }

    const payload = buildBasicHybridRagAuditPayload(meta, options);
    if (payload.guidelineChunkCount > 0) {
        CopilotTelemetry.log('guideline_corpus_loaded', payload);
        CopilotTelemetry.log('guideline_chunks_created', payload);
    }
    if (payload.sparseResultCount > 0) {
        CopilotTelemetry.log('sparse_retrieval_completed', payload);
    }
    if (payload.denseResultCount > 0) {
        CopilotTelemetry.log('dense_retrieval_completed', payload);
    }
    if (payload.hybridCandidateCount > 0 || payload.retrievalMode === 'hybrid') {
        CopilotTelemetry.log('hybrid_retrieval_completed', payload);
    }
    if (payload.rerankedResultCount > 0) {
        CopilotTelemetry.log('rerank_completed', payload);
    }
    if (payload.ragGrounded && payload.rerankedResultCount > 0) {
        CopilotTelemetry.log('grounded_answer_generated', payload);
    } else if (payload.retrievalMode === 'no_grounded_evidence') {
        CopilotTelemetry.log('no_grounded_evidence_found', payload);
    }
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
        claims: Array.isArray(options.claims) ? options.claims : [],
        sourcesUsed: Array.isArray(options.sourcesUsed) ? options.sourcesUsed : [],
        uncitedClaimsBlocked: Array.isArray(options.uncitedClaimsBlocked) ? options.uncitedClaimsBlocked : [],
        evidenceSnippets: Array.isArray(options.evidenceSnippets) ? options.evidenceSnippets : [],
        safetyStatus: options.safetyStatus || '',
        guardrails: options.guardrails || null,
        feedback: options.feedback || '',
        copied: Boolean(options.copied),
        isLoading: Boolean(options.isLoading),
        showResponseActions: options.showResponseActions !== false,
        metadataLogged: Boolean(options.metadataLogged),
        agentWorkflowTraceLogged: Boolean(options.agentWorkflowTraceLogged),
        observabilityLogged: Boolean(options.observabilityLogged)
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
    if (!nextMeta.observability) {
        const synthesizedObservability = synthesizeObservabilityFromMessage({
            ...message,
            meta: nextMeta
        }, traceData);
        if (synthesizedObservability) {
            nextMeta.observability = synthesizedObservability;
        }
    }
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
            patientContextPresent: currentSafePatientTelemetryContext().patientContextPresent,
            patientContextHash: currentSafePatientTelemetryContext().patientContextHash,
            blockedReason: nextMeta.restriction_type || message.guardrails?.blockedReason || '',
            supervisorDecisions: Array.isArray(nextMeta.supervisor_decisions) ? nextMeta.supervisor_decisions : [],
            workerHandoffs: Array.isArray(nextMeta.worker_handoffs) ? nextMeta.worker_handoffs : []
        });
        message.agentWorkflowTraceLogged = true;
    }

    if (
        options.emitObservability === true
        && !message.observabilityLogged
        && typeof window.OpenEMRCopilotAgentTrace.emitEncounterObservability === 'function'
        && CopilotTelemetry
        && nextMeta.observability
    ) {
        window.OpenEMRCopilotAgentTrace.emitEncounterObservability(nextMeta.observability, CopilotTelemetry, {
            requestId: message.requestId || null,
            responseId: message.responseId || null,
            role: message.staffRole || state.activeRole,
            mode: message.mode || 'general_assistant'
        });
        message.observabilityLogged = true;
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

function synthesizeObservabilityFromMessage(message, traceData) {
    if (!window.OpenEMRCopilotObservability || typeof window.OpenEMRCopilotObservability.buildEncounterObservability !== 'function') {
        return null;
    }

    const attachmentEvidence = buildAttachmentEvidenceMeta(message.tool_output);
    const sourceEntries = buildVisibleSourceEntries(message);
    const steps = Array.isArray(traceData?.steps) ? traceData.steps : [];

    return window.OpenEMRCopilotObservability.buildEncounterObservability({
        request_id: message.requestId || null,
        encounter_id: message.requestId || message.responseId || null,
        role: message.staffRole || state.activeRole,
        mode: message.mode || 'general_assistant',
        selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey() || '',
        tool_sequence: steps.map((step, index) => ({
            order: index + 1,
            step: step.name || 'Worker',
            decision: step.id === 'supervisor' ? (step.description || '') : '',
            status: step.status || 'completed',
            latency_ms: 0,
            retrieval_hits: step.id === 'evidence_retriever' ? Number(message.meta?.source_count || sourceEntries.length || 0) : 0
        })),
        latency: {
            total_ms: Number(message.meta?.latency_ms || 0),
            steps: {
                total_response_ms: Number(message.meta?.latency_ms || 0)
            }
        },
        token_usage: message.meta?.token_usage || null,
        model: message.meta?.model || null,
        provider: message.meta?.provider || null,
        estimated_cost_usd: message.meta?.estimated_cost_usd ?? null,
        retrieval: {
            hit_count: Number(message.meta?.source_count || sourceEntries.length || 0),
            top_k: Number(message.meta?.reranked_result_count || 0),
            retrieval_mode: message.meta?.retrieval_mode || (message.meta?.rag_grounded ? 'hybrid' : 'none'),
            rerank_provider: message.meta?.rerank_provider || 'none',
            sparse_hit_count: Number(message.meta?.sparse_result_count || 0),
            dense_hit_count: Number(message.meta?.dense_result_count || 0),
            hybrid_candidate_count: Number(message.meta?.hybrid_candidate_count || 0),
            reranked_hit_count: Number(message.meta?.reranked_result_count || 0),
            final_evidence_count: Array.isArray(message.evidenceSnippets) ? message.evidenceSnippets.length : 0,
            top_source_types: sourceEntries.map((entry) => entry.sourceType || entry.documentType || entry.category || '').filter(Boolean).slice(0, 8),
            citation_count: Array.isArray(message.claims)
                ? message.claims.reduce((count, claim) => count + (Array.isArray(claim.citations) ? claim.citations.length : 0), 0)
                : 0
        },
        extraction: attachmentEvidence && attachmentEvidence.attempted ? {
            doc_type: attachmentEvidence.documentType || 'none',
            extraction_status: attachmentEvidence.status || 'none',
            confidence: Number.isFinite(Number(message.tool_output?.confidence)) ? Number(message.tool_output.confidence) : null,
            schema_valid: message.tool_output?.schemaValid ?? message.tool_output?.schema_valid ?? null,
            citation_contract_valid: message.meta?.citation_contract_status ? String(message.meta.citation_contract_status).toLowerCase() === 'passed' : null,
            review_status: message.tool_output?.reviewStatus || message.tool_output?.review_status || 'pending_clinician_review',
            extracted_fact_count: Array.isArray(message.tool_output?.extractedFacts) ? message.tool_output.extractedFacts.length : 0,
            missing_data_count: Array.isArray(message.tool_output?.missingData) ? message.tool_output.missingData.length : 0
        } : {
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
            safe_refusal: Boolean(message.meta?.restricted_by_role || message.safetyStatus === 'safe_refusal'),
            blocked_reason: message.meta?.restriction_type || null,
            phi_redacted: true,
            raw_document_text_logged: false,
            raw_screenshot_logged: false,
            screenshot_capture_attempted: false,
            screenshot_blocked_reason: 'PHI_SAFE_DEFAULT'
        }
    });
}

function buildObservabilityTraceCard(message) {
    if (
        !message
        || message.role !== 'assistant'
        || message.isLoading
        || message.showResponseActions === false
        || !window.OpenEMRCopilotAgentTrace
        || typeof window.OpenEMRCopilotAgentTrace.buildObservabilityCard !== 'function'
    ) {
        return null;
    }

    return window.OpenEMRCopilotAgentTrace.buildObservabilityCard(message);
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

function clearUploadNotice() {
    if (!uploadNotice) {
        return;
    }

    if (uploadNoticeTimer) {
        window.clearTimeout(uploadNoticeTimer);
        uploadNoticeTimer = null;
    }

    uploadNotice.hidden = true;
    uploadNotice.textContent = '';
    uploadNotice.dataset.tone = 'neutral';
}

function setUploadNotice(message, tone = 'neutral', options = {}) {
    if (!uploadNotice) {
        return;
    }

    const text = String(message || '').trim();
    if (!text) {
        clearUploadNotice();
        return;
    }

    if (uploadNoticeTimer) {
        window.clearTimeout(uploadNoticeTimer);
        uploadNoticeTimer = null;
    }

    uploadNotice.hidden = false;
    uploadNotice.textContent = text;
    uploadNotice.dataset.tone = tone;

    const timeoutMs = Number.isFinite(options.timeoutMs) ? options.timeoutMs : 5200;
    if (options.persist !== true) {
        uploadNoticeTimer = window.setTimeout(() => {
            clearUploadNotice();
        }, timeoutMs);
    }
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
    const helper = labPdfIngestionHelper();
    if (helper && typeof helper.logLabPdfEvent === 'function') {
        return helper.logLabPdfEvent(eventName, payload, CopilotTelemetry);
    }

    if (helper && typeof helper.emitLabPdfTelemetry === 'function') {
        return helper.emitLabPdfTelemetry(CopilotTelemetry, eventName, payload);
    }

    const safePayload = {
        requestId: payload.requestId || null,
        role: payload.role || state.activeRole || null,
        mode: payload.mode || 'lab_pdf_ingestion',
        selectedPatientKey: payload.selectedPatientKey || currentSelectedPatientKey(),
        documentTitle: payload.documentTitle || payload.fileName || null,
        documentType: payload.documentType || 'lab_pdf',
        extractionMethod: payload.extractionMethod || null,
        toolStatus: payload.toolStatus || payload.status || null,
        chunkCount: Number.isFinite(payload.chunkCount) ? payload.chunkCount : 0,
        retrievedChunkCount: Number.isFinite(payload.retrievedChunkCount) ? payload.retrievedChunkCount : 0,
        missingDataCount: Number.isFinite(payload.missingDataCount) ? payload.missingDataCount : 0,
        guardrailTriggered: Boolean(payload.guardrailTriggered),
        documentGuardProvider: payload.documentGuardProvider || null,
        medicalEntityCount: Number.isFinite(payload.medicalEntityCount) ? payload.medicalEntityCount : 0,
        confidence: Number.isFinite(payload.confidence) ? payload.confidence : 0,
        rejectionReason: payload.rejectionReason || null,
        documentGuardDecision: payload.documentGuardDecision || null,
        awsGuardEnabled: Boolean(payload.awsGuardEnabled),
        textExtractionStatus: payload.textExtractionStatus || null,
        medicalValidationStatus: payload.medicalValidationStatus || null,
        chartWriteStatus: payload.chartWriteStatus || null,
        syntheticDemoData: Boolean(payload.syntheticDemoData),
        reviewRequired: !Object.prototype.hasOwnProperty.call(payload, 'reviewRequired') || Boolean(payload.reviewRequired),
        labEvidenceScore: Number.isFinite(payload.labEvidenceScore) ? payload.labEvidenceScore : 0,
        seededDemo: Boolean(payload.seededDemo),
        ragGrounded: Boolean(payload.ragGrounded)
    };

    if (!CopilotTelemetry || typeof CopilotTelemetry.log !== 'function') {
        console.warn('[Medical Co-Pilot Audit] lab PDF telemetry unavailable', {
            eventName: String(eventName || 'copilot_lab_pdf_event')
        });
        return safePayload;
    }

    CopilotTelemetry.log(String(eventName || 'copilot_lab_pdf_event'), safePayload);
    return safePayload;
}

function emitLabEvidenceClearAuditEvent(eventName, payload = {}) {
    const safePayload = {
        requestId: payload.requestId || null,
        role: payload.role || state.activeRole || null,
        mode: payload.mode || 'lab_pdf_ingestion',
        selectedPatientKey: payload.selectedPatientKey || currentSelectedPatientKey(),
        documentTitle: payload.documentTitle || null,
        documentType: normalizeAttachmentDocumentType(payload.documentType || 'lab_pdf'),
        toolStatus: payload.toolStatus || null,
        removedLabFiles: Number.isFinite(payload.removedLabFiles) ? payload.removedLabFiles : 0,
        removedLabChunks: Number.isFinite(payload.removedLabChunks) ? payload.removedLabChunks : 0,
        removedLabExtractions: Number.isFinite(payload.removedLabExtractions) ? payload.removedLabExtractions : 0,
        remainingIntakeForms: Number.isFinite(payload.remainingIntakeForms) ? payload.remainingIntakeForms : 0
    };

    if (!CopilotTelemetry || typeof CopilotTelemetry.log !== 'function') {
        console.warn('[Medical Co-Pilot Audit] lab evidence clear telemetry unavailable', {
            eventName: String(eventName || 'copilot_lab_evidence_clear')
        });
        console.info('[OpenEMR Copilot] Lab evidence clear summary', safePayload);
        return safePayload;
    }

    CopilotTelemetry.log(String(eventName || 'copilot_lab_evidence_clear'), safePayload);
    return safePayload;
}

function clearLabEvidenceClientStorage(selectedPatientKey) {
    const candidatePatterns = [
        /openemr_copilot_lab_pdf/i,
        /openemr_copilot_uploaded_lab/i,
        /openemr_copilot_lab_evidence/i,
        /labpdf_request/i
    ];
    let removedCount = 0;

    [window.localStorage, window.sessionStorage].forEach((storage) => {
        try {
            if (!storage) {
                return;
            }
            const keys = [];
            for (let index = 0; index < storage.length; index += 1) {
                const key = storage.key(index);
                if (key) {
                    keys.push(key);
                }
            }
            keys.forEach((key) => {
                const matchesPattern = candidatePatterns.some((pattern) => pattern.test(key));
                const matchesPatient = !selectedPatientKey || key.includes(selectedPatientKey);
                if (matchesPattern && matchesPatient) {
                    storage.removeItem(key);
                    removedCount += 1;
                }
            });
        } catch (error) {
        }
    });

    return removedCount;
}

function normalizeAttachmentDocumentType(documentType) {
    return documentType === 'intake_form' || documentType === 'lab_pdf' || documentType === 'unknown'
        ? documentType
        : 'unknown';
}

function detectRequestedUploadedDocumentTypes(prompt) {
    const value = String(prompt || '').toLowerCase();
    const requestedTypes = [];
    if (/\b(lab pdf|lab results|uploaded lab|lab report|a1c|glucose|ldl|creatinine|egfr)\b/.test(value)) {
        requestedTypes.push('lab_results');
    }
    if (/\b(intake|intake form|questionnaire|reason for visit|medication adherence|insurance update|care preferences)\b/.test(value)) {
        requestedTypes.push('intake_form');
    }
    return Array.from(new Set(requestedTypes));
}

function matchedUploadedSourceTitles(toolOutput) {
    const sourceCoverage = toolOutput && typeof toolOutput === 'object' && toolOutput.sourceCoverage && typeof toolOutput.sourceCoverage === 'object'
        ? toolOutput.sourceCoverage
        : {};
    const matchedSources = Array.isArray(sourceCoverage.matched_sources)
        ? sourceCoverage.matched_sources
        : (Array.isArray(sourceCoverage.matchedSources) ? sourceCoverage.matchedSources : []);
    return Array.from(new Set(matchedSources.map((source) => {
        if (!source || typeof source !== 'object') {
            return '';
        }
        return String(source.display_file_name || source.displayFileName || source.original_file_name || source.originalFileName || '').trim();
    }).filter(Boolean)));
}

function logUploadedDocumentRetrievalEvent(eventName, payload = {}) {
    if (!CopilotTelemetry || typeof CopilotTelemetry.log !== 'function') {
        return;
    }

    CopilotTelemetry.log(String(eventName || 'copilot_document_retrieval_event'), {
        requestId: payload.requestId || null,
        role: payload.role || state.activeRole || null,
        mode: payload.mode || state.activeMode || null,
        selectedPatientKey: payload.selectedPatientKey || currentSelectedPatientKey(),
        requestedDocumentTypes: Array.isArray(payload.requestedDocumentTypes) ? payload.requestedDocumentTypes.slice(0, 4) : [],
        matchedSourceTitles: Array.isArray(payload.matchedSourceTitles) ? payload.matchedSourceTitles.slice(0, 6) : [],
        sourceCount: Number.isFinite(payload.sourceCount) ? payload.sourceCount : 0
    });
}

function emitAttachmentReviewLlmAuditEvent(eventName, payload = {}) {
    if (!CopilotTelemetry || typeof CopilotTelemetry.log !== 'function') {
        return;
    }

    CopilotTelemetry.log(String(eventName || 'copilot_attachment_review_llm_event'), {
        requestId: payload.requestId || null,
        role: payload.role || state.activeRole || null,
        mode: payload.mode || state.activeMode || null,
        selectedPatientKey: payload.selectedPatientKey || currentSelectedPatientKey(),
        documentTitle: payload.documentTitle || null,
        documentType: payload.documentType || null,
        toolStatus: payload.toolStatus || null,
        sourceCount: Number.isFinite(payload.sourceCount) ? payload.sourceCount : 0,
        provider: payload.provider || null,
        model: payload.model || null,
        openaiConfigured: Boolean(payload.openaiConfigured),
        promptTokens: Number.isFinite(payload.promptTokens) ? payload.promptTokens : null,
        completionTokens: Number.isFinite(payload.completionTokens) ? payload.completionTokens : null,
        totalTokens: Number.isFinite(payload.totalTokens) ? payload.totalTokens : null,
        fallbackReason: payload.fallbackReason || null,
        openaiErrorCategory: payload.openaiErrorCategory || null,
        openaiHttpStatus: Number.isFinite(payload.openaiHttpStatus) ? payload.openaiHttpStatus : null,
        openaiErrorMessageSafe: payload.openaiErrorMessageSafe || null,
        ragGrounded: Boolean(payload.ragGrounded)
    });
}

function attachmentWorkflowLabel(documentType) {
    const normalizedType = normalizeAttachmentDocumentType(documentType);
    if (normalizedType === 'intake_form') {
        return 'intake form';
    }
    if (normalizedType === 'lab_pdf') {
        return 'lab PDF';
    }
    return 'PDF';
}

function attachmentWorkflowContextLabel(documentType) {
    const normalizedType = normalizeAttachmentDocumentType(documentType);
    if (normalizedType === 'intake_form') {
        return 'intake-form context';
    }
    if (normalizedType === 'lab_pdf') {
        return 'lab context';
    }
    return 'document context';
}

function currentSelectedDocumentType() {
    const value = normalizeAttachmentDocumentType(documentTypeSelect?.value || state.labPdf.selectedDocumentType || 'lab_pdf');
    return value === 'unknown' ? 'lab_pdf' : value;
}

function syncDocumentTypeSelection(value, options = {}) {
    const nextType = normalizeAttachmentDocumentType(value || 'lab_pdf');
    state.labPdf.selectedDocumentType = nextType === 'unknown' ? 'lab_pdf' : nextType;
    if (documentTypeSelect) {
        documentTypeSelect.value = state.labPdf.selectedDocumentType;
        documentTypeSelect.disabled = state.loading;
    }

    if (options.emitTelemetry && CopilotTelemetry) {
        CopilotTelemetry.log('document_type_selected', {
            role: state.activeRole,
            mode: state.activeMode,
            selectedPatientKey: currentSelectedPatientKey(),
            documentType: state.labPdf.selectedDocumentType,
            toolStatus: 'selected'
        });
    }
}

function hasActiveLabPdfAttachment() {
    return Boolean(state.labPdf.file || state.labPdf.useDemoSeed);
}

function refreshLabPdfAttachmentUi() {
    if (labPdfChip) {
        const hasDescriptor = Boolean(state.labPdf.descriptor);
        labPdfChip.hidden = !hasDescriptor;
        if (hasDescriptor && labPdfChipText) {
            labPdfChipText.textContent = state.labPdf.descriptor.displayLabel || 'Attached PDF';
        }
    }

    if (!hasActiveLabPdfAttachment()) {
        setLabPdfStatus('Attach a PDF for draft-only clinician review.');
    }
}

function clearLabPdfAttachment(options = {}) {
    const previousDescriptor = state.labPdf.descriptor;
    const previousRequestId = state.labPdf.requestId;
    state.labPdf.file = null;
    state.labPdf.descriptor = null;
    state.labPdf.useDemoSeed = false;
    state.labPdf.requestId = null;

    if (labPdfInput) {
        labPdfInput.value = '';
    }

    refreshLabPdfAttachmentUi();
    if (options.keepUploadNotice) {
        setUploadNotice(options.keepUploadNotice, options.noticeTone || 'neutral', {
            persist: options.persistUploadNotice === true,
            timeoutMs: options.noticeTimeoutMs
        });
    } else {
        clearUploadNotice();
    }
    updateSendState();
    if (options.keepStatusMessage) {
        setLabPdfStatus(options.keepStatusMessage, options.tone || 'neutral');
    }
    if (options.keepDocumentType !== true) {
        syncDocumentTypeSelection(state.labPdf.selectedDocumentType || 'lab_pdf');
    }

    if (options.emitTelemetry !== false && previousDescriptor) {
        emitLabPdfAuditEvent('copilot_lab_pdf_removed', {
            requestId: previousRequestId,
            role: state.activeRole,
            mode: 'lab_pdf_ingestion',
            selectedPatientKey: currentSelectedPatientKey(),
            documentTitle: previousDescriptor.fileName || null,
            documentType: normalizeAttachmentDocumentType(previousDescriptor.documentType),
            toolStatus: 'removed',
            seededDemo: previousDescriptor.kind === 'seeded_demo',
            ragGrounded: false
        });
    }
}

function applyLabPdfAttachmentDescriptor(descriptor, options = {}) {
    state.labPdf.file = options.file || null;
    state.labPdf.useDemoSeed = Boolean(options.useDemoSeed);
    state.labPdf.descriptor = descriptor || null;
    state.labPdf.requestId = options.requestId || state.labPdf.requestId || null;
    if (descriptor?.documentType) {
        syncDocumentTypeSelection(descriptor.documentType);
    }
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
        setLabPdfStatus('Please select a PDF file for this document-ingestion workflow.', 'error');
        setUploadNotice('This does not appear to be a supported PDF upload. Please select a PDF file.', 'error', {
            timeoutMs: 5400
        });
        emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_failed', {
            role: state.activeRole,
            mode: 'lab_pdf_ingestion',
            selectedPatientKey: currentSelectedPatientKey(),
            documentTitle: file?.name || null,
            documentType: 'unknown',
            toolStatus: 'invalid_file_type',
            seededDemo: false,
            ragGrounded: false
        });
        return false;
    }

    const selectedDocumentType = currentSelectedDocumentType();
    const descriptor = helper && typeof helper.buildAttachmentDescriptor === 'function'
        ? helper.buildAttachmentDescriptor(file, {
            documentType: selectedDocumentType
        })
        : {
            kind: 'uploaded_file',
            fileName: file.name || 'attached-document.pdf',
            displayLabel: `Attached: ${file.name || 'attached-document.pdf'}`,
            mimeType: file.type || 'application/pdf',
            documentType: selectedDocumentType
        };
    const attachmentRequestId = createId('request');
    const documentType = normalizeAttachmentDocumentType(descriptor.documentType);

    applyLabPdfAttachmentDescriptor(descriptor, {
        file,
        useDemoSeed: false,
        requestId: attachmentRequestId
    });
    setLabPdfStatus(`Attached ${descriptor.fileName}. Send a prompt to ingest and retrieve ${attachmentWorkflowContextLabel(documentType)} for clinician review.`, 'success');
    setUploadNotice('PDF attached. Medical document validation will run before ingestion.', 'success', {
        timeoutMs: 4200
    });

    emitLabPdfAuditEvent('copilot_lab_pdf_attached', {
        requestId: attachmentRequestId,
        role: state.activeRole,
        mode: 'lab_pdf_ingestion',
        selectedPatientKey: currentSelectedPatientKey(),
        documentTitle: descriptor.fileName || null,
        documentType: documentType,
        toolStatus: 'attached',
        seededDemo: descriptor.kind === 'seeded_demo',
        ragGrounded: false
    });
    emitLabPdfAuditEvent('ingestion_document_classified', {
        requestId: attachmentRequestId,
        role: state.activeRole,
        mode: 'lab_pdf_ingestion',
        selectedPatientKey: currentSelectedPatientKey(),
        documentTitle: descriptor.fileName || null,
        documentType: documentType,
        toolStatus: documentType,
        seededDemo: descriptor.kind === 'seeded_demo',
        ragGrounded: false
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
    if (documentTypeSelect) {
        documentTypeSelect.disabled = state.loading;
    }
    if (labPdfRemoveButton) {
        labPdfRemoveButton.disabled = state.loading || !hasActiveLabPdfAttachment();
    }
    if (labEvidenceClearButton) {
        labEvidenceClearButton.disabled = state.loading || !currentSelectedPatientKey();
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
    const visibleSourceEntries = buildVisibleSourceEntries(message);
    const safeSources = visibleSourceEntries.map((source) => source.title).slice(0, 8);
    const safeContextTags = Array.isArray(message.tags) ? Array.from(new Set(message.tags)).slice(0, 5) : [];
    const safeSafetyLabels = message.safety ? [message.safety] : [];
    const safeRole = message.staffRole || state.activeRole;
    const safeMode = message.mode || 'general_assistant';
    const safePatientContext = window.OpenEMRCopilotObservability && typeof window.OpenEMRCopilotObservability.hashIdentifier === 'function'
        ? {
            patientContextPresent: Boolean(message.selectedPatientKey || currentSelectedPatientKey()),
            patientContextHash: (message.selectedPatientKey || currentSelectedPatientKey())
                ? window.OpenEMRCopilotObservability.hashIdentifier(message.selectedPatientKey || currentSelectedPatientKey())
                : null,
            patientIdentifierRedacted: true
        }
        : currentSafePatientTelemetryContext();
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
        console.info('patientContext', safePatientContext);
        console.info('sourceCount', visibleSourceEntries.length);
        console.info('sourceTitles', visibleSourceEntries.map((source) => source.title));
        console.info('sourceCategories', visibleSourceEntries.map((source) => source.category));
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
            patientContext: safePatientContext,
            sourceCount: visibleSourceEntries.length,
            sourceTitles: visibleSourceEntries.map((source) => source.title),
            sourceCategories: visibleSourceEntries.map((source) => source.category),
            latestAmbientVisitFound: sourcePayload.latestAmbientVisitFound,
            sources: safeSources,
            contextTags: safeContextTags,
            safetyLabels: safeSafetyLabels,
            meta: safeMeta
        });
    }

    message.metadataLogged = true;
}

function logLabPdfDataToConsole(prompt, toolOutput, options = {}) {
    if (!toolOutput || typeof toolOutput !== 'object') {
        return;
    }

    const sourceType = toolOutput.sourceMetadata?.sourceType || toolOutput.documentMetadata?.documentType || '';
    const isAttachmentPayload = ['lab_pdf', 'intake_form'].includes(sourceType) || toolOutput.tool === 'attach_and_vectorize_lab_pdf';
    if (!isAttachmentPayload) {
        return;
    }

    const helper = labPdfIngestionHelper();
    if (!helper || typeof helper.buildLabPdfDemoTracePayload !== 'function' || typeof helper.logLabPdfDemoTrace !== 'function') {
        return;
    }

    const documentTitle = toolOutput.documentMetadata?.title || toolOutput.sourceMetadata?.fileName || '';
    const documentTitleHash = window.OpenEMRCopilotObservability && typeof window.OpenEMRCopilotObservability.hashIdentifier === 'function'
        ? window.OpenEMRCopilotObservability.hashIdentifier(documentTitle || '')
        : null;
    const extractionMethod = toolOutput.extractionMethod || '';
    const extractedTextPreview = toolOutput.extractedTextPreview || '';
    const extractedTextLength = Number.isFinite(toolOutput.extractedTextLength)
        ? toolOutput.extractedTextLength
        : String(extractedTextPreview || '').length;
    const extractedFacts = Array.isArray(toolOutput.extractedFacts) ? toolOutput.extractedFacts : [];
    const vectorChunks = Array.isArray(toolOutput.vectorizedResult)
        ? toolOutput.vectorizedResult.map((record) => ({
            chunkId: record.id || '',
            textPreview: record.textPreview || '',
            chunkIndex: record.chunkIndex ?? null
        }))
        : [];
    const retrievalChunkIds = Array.isArray(toolOutput.retrieval?.chunkIds) ? toolOutput.retrieval.chunkIds : [];

    if (toolOutput.textExtractionStatus === 'success') {
        console.info('[Lab PDF Ingestion Debug] lab_pdf_text_extracted', {
            documentTitlePresent: Boolean(documentTitle),
            documentTitleHash,
            extractionMethod,
            extractedTextLength,
            extractedTextPreview: extractedTextPreview ? '[REDACTED_RAW_DOCUMENT_TEXT]' : ''
        });
    }
    if (extractedFacts.length > 0) {
        console.info('[Lab PDF Ingestion Debug] lab_pdf_facts_extracted', {
            documentTitlePresent: Boolean(documentTitle),
            documentTitleHash,
            extractionMethod,
            extractedFactCount: extractedFacts.length,
            extractedFacts: extractedFacts.map((fact, index) => ({
                factIndex: index,
                fieldType: fact.name || fact.field_name || 'fact',
                reviewStatus: fact.reviewStatus || fact.review_status || 'pending_clinician_review'
            }))
        });
    }
    if (vectorChunks.length > 0) {
        console.info('[Lab PDF Ingestion Debug] lab_pdf_vectorized', {
            documentTitlePresent: Boolean(documentTitle),
            documentTitleHash,
            extractionMethod,
            vectorChunkCount: vectorChunks.length,
            vectorChunks: vectorChunks.map((record) => ({
                chunkId: record.chunkId || '',
                chunkIndex: record.chunkIndex ?? null,
                textPreview: record.textPreview ? '[REDACTED_RAW_DOCUMENT_TEXT]' : ''
            }))
        });
    }
    if (retrievalChunkIds.length > 0) {
        console.info('[Lab PDF Ingestion Debug] rag_context_retrieved', {
            documentTitlePresent: Boolean(documentTitle),
            documentTitleHash,
            extractionMethod,
            retrievalChunkIds
        });
    }

    const demoTracePayload = helper.buildLabPdfDemoTracePayload({
        tool_output: toolOutput,
        role: options.role || null,
        mode: options.mode || null,
        meta: {
            request_id: options.requestId || null,
            rag_grounded: Boolean(options.ragGrounded)
        }
    }, {
        requestId: options.requestId || null,
        role: options.role || null,
        mode: options.mode || null,
        selectedPatientKey: '',
        patientContextPresent: Boolean(options.selectedPatientKey),
        patientContextHash: window.OpenEMRCopilotObservability && typeof window.OpenEMRCopilotObservability.hashIdentifier === 'function'
            ? window.OpenEMRCopilotObservability.hashIdentifier(options.selectedPatientKey || '')
            : null,
        ragGrounded: Boolean(options.ragGrounded)
    });
    helper.logLabPdfDemoTrace('Pipeline trace', demoTracePayload);
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
            category: category || title.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''),
            sourceId: String(value.sourceId || value.source_id || '').trim(),
            sourceType: String(value.sourceType || value.source_type || '').trim(),
            documentType: String(value.documentType || value.document_type || '').trim(),
            pageOrSection: String(value.pageOrSection || value.page_or_section || '').trim(),
            fieldOrChunkId: String(value.fieldOrChunkId || value.field_or_chunk_id || '').trim()
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
        'attached intake form': ['Attached Intake Form', 'documents'],
        'uploaded intake form': ['Uploaded Intake Form', 'documents'],
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
    const citationSources = uniqueSourceEntries(
        (Array.isArray(message.sourcesUsed) ? message.sourcesUsed : [])
            .map(normalizeSourceEntry)
            .filter(Boolean)
    );
    if (citationSources.length > 0) {
        return citationSources;
    }

    return buildResponseSourcePayload(message.sources || [], {
        role: message.staffRole || state.activeRole,
        mode: message.mode || 'general_assistant',
        patientKey: message.selectedPatientKey || currentSelectedPatientKey(),
        ragGrounded: Boolean(message.meta?.rag_grounded)
    }).sources;
}

function messageCitationClaims(message) {
    return Array.isArray(message?.claims)
        ? message.claims.filter((claim) => claim && typeof claim === 'object')
        : [];
}

function normalizeSearchText(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[^a-z0-9%/.\-\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function buildSearchTokenSet(value) {
    return new Set(
        normalizeSearchText(value)
            .split(' ')
            .map((token) => token.trim())
            .filter((token) => token.length >= 3 || /[%/.\-]/.test(token))
    );
}

function textOverlapScore(left, right) {
    const leftTokens = buildSearchTokenSet(left);
    const rightTokens = buildSearchTokenSet(right);
    let overlap = 0;
    leftTokens.forEach((token) => {
        if (rightTokens.has(token)) {
            overlap += 1;
        }
    });
    return overlap;
}

function claimMatchesSectionItem(claim, itemText, sectionTitle = '') {
    if (!claim || typeof claim !== 'object') {
        return false;
    }

    const item = normalizeSearchText(itemText);
    const section = normalizeSearchText(sectionTitle);
    const claimText = normalizeSearchText(claim.text || '');
    if (!item || !claimText) {
        return false;
    }

    if (item.includes(claimText) || claimText.includes(item)) {
        return true;
    }

    if (textOverlapScore(item, claimText) >= 2) {
        return true;
    }

    const citations = Array.isArray(claim.citations) ? claim.citations : [];
    return citations.some((citation) => {
        if (!citation || typeof citation !== 'object') {
            return false;
        }

        const quote = normalizeSearchText(citation.quote_or_value || '');
        const pageOrSection = normalizeSearchText(citation.page_or_section || '');
        const fieldOrChunkId = normalizeSearchText(citation.field_or_chunk_id || '');

        if (quote && (item.includes(quote) || quote.includes(item) || textOverlapScore(item, quote) >= 2)) {
            return true;
        }
        if (section && pageOrSection && (section.includes(pageOrSection) || pageOrSection.includes(section))) {
            return true;
        }
        if (fieldOrChunkId && item && (fieldOrChunkId.includes(item) || item.includes(fieldOrChunkId))) {
            return true;
        }

        return false;
    });
}

function findMatchingClaimsForSectionItem(message, itemText, sectionTitle = '') {
    return messageCitationClaims(message).filter((claim) => claimMatchesSectionItem(claim, itemText, sectionTitle));
}

function dedupeCitationList(citations) {
    const seen = new Set();
    return (Array.isArray(citations) ? citations : []).filter((citation) => {
        if (!citation || typeof citation !== 'object') {
            return false;
        }

        const key = [
            String(citation.source_id || ''),
            String(citation.field_or_chunk_id || ''),
            String(citation.page_or_section || '')
        ].join('::');
        if (!key || seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });
}

function citationSourceTypeLabel(citation) {
    const sourceType = String(citation?.source_type || '').trim().toLowerCase();
    const documentType = String(citation?.document_type || '').trim().toLowerCase();

    if (sourceType === 'demo_guideline') {
        return 'Demo Guideline';
    }
    if (sourceType === 'lab_pdf' || documentType === 'lab_pdf' || documentType === 'lab_results') {
        return 'Lab PDF';
    }
    if (sourceType === 'intake_form' || documentType === 'intake_form') {
        return 'Intake Form';
    }
    if (sourceType === 'rag_chunk') {
        return 'RAG Snippet';
    }
    if (sourceType === 'openemr_chart') {
        return 'Chart';
    }
    if (sourceType === 'fhir_resource') {
        return 'FHIR';
    }
    if (sourceType === 'clinician_reviewed_fact') {
        return 'Reviewed Fact';
    }
    if (sourceType === 'ambient_encounter') {
        return 'Ambient';
    }

    return 'Source';
}

function retrievalModeLabel(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return {
        hybrid: 'hybrid',
        sparse_only: 'sparse_only',
        dense_only: 'dense_only',
        no_grounded_evidence: 'no_grounded_evidence'
    }[normalized] || (normalized || 'unknown');
}

function rerankProviderLabel(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return {
        cohere: 'cohere',
        fallback_score_sort: 'fallback_score_sort'
    }[normalized] || (normalized || 'unknown');
}

function citationPreviewTitle(citation) {
    const sourceLabel = citationSourceTypeLabel(citation);
    const pageOrSection = String(citation?.page_or_section || '').trim();
    return pageOrSection ? `${sourceLabel} · ${pageOrSection}` : sourceLabel;
}

function normalizeCitationConfidence(value) {
    return Number.isFinite(Number(value)) ? Number(value) : 0;
}

function citationConfidenceLabel(value) {
    const normalized = normalizeCitationConfidence(value);
    return `${Math.round(normalized * 100)}%`;
}

function findCitationForSourceEntry(message, entry) {
    const claims = messageCitationClaims(message);
    for (const claim of claims) {
        const citations = Array.isArray(claim.citations) ? claim.citations : [];
        const match = citations.find((citation) => {
            if (!citation || typeof citation !== 'object') {
                return false;
            }

            if (entry.sourceId && String(citation.source_id || '') === String(entry.sourceId)) {
                return true;
            }

            const citationDocumentType = String(citation.document_type || '').trim().toLowerCase();
            const entryDocumentType = String(entry.documentType || '').trim().toLowerCase();
            const citationSourceType = String(citation.source_type || '').trim().toLowerCase();
            const entrySourceType = String(entry.sourceType || '').trim().toLowerCase();
            const citationPageOrSection = String(citation.page_or_section || '').trim().toLowerCase();
            const entryPageOrSection = String(entry.pageOrSection || '').trim().toLowerCase();

            if (entryDocumentType && citationDocumentType && entryDocumentType === citationDocumentType) {
                return true;
            }
            if (entrySourceType && citationSourceType && entrySourceType === citationSourceType) {
                return true;
            }
            if (entryPageOrSection && citationPageOrSection && entryPageOrSection === citationPageOrSection) {
                return true;
            }

            return false;
        });

        if (match) {
            return match;
        }
    }

    return null;
}

function buildCitationChipsForMessage(citations, message, options = {}) {
    const uniqueCitations = dedupeCitationList(citations);
    if (uniqueCitations.length === 0) {
        return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = options.className || 'copilot-citation-chip-list';

    uniqueCitations.forEach((citation) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = options.buttonClassName || 'copilot-citation-chip';
        button.textContent = options.compact
            ? citationSourceTypeLabel(citation)
            : citationPreviewTitle(citation);
        button.addEventListener('click', () => {
            openCitationSourcePreview(message, citation);
        });
        wrapper.appendChild(button);
    });

    return wrapper;
}

function buildCitationContractPanel(message) {
    if (!message || message.role !== 'assistant' || message.isLoading) {
        return null;
    }

    const claims = messageCitationClaims(message);
    const blockedClaims = Array.isArray(message.uncitedClaimsBlocked)
        ? message.uncitedClaimsBlocked.filter((claim) => claim && typeof claim === 'object')
        : [];
    const toolOutput = message.meta?.tool_output && typeof message.meta.tool_output === 'object'
        ? message.meta.tool_output
        : null;
    const citationValidation = toolOutput?.citationValidation && typeof toolOutput.citationValidation === 'object'
        ? toolOutput.citationValidation
        : null;
    const citationContractStatus = String(
        citationValidation?.citationContractStatus
        || message.meta?.citation_contract_status
        || message.safetyStatus
        || ''
    ).trim();

    if (!citationValidation && claims.length === 0 && blockedClaims.length === 0) {
        return null;
    }

    const citedClaimCount = Number(
        message.meta?.cited_claim_count
        ?? citationValidation?.citationCount
        ?? claims.length
        ?? 0
    );
    const uncitedClaimCount = Number(
        message.meta?.uncited_claim_count
        ?? blockedClaims.length
        ?? 0
    );
    const invalidCitationCount = Number(
        message.meta?.invalid_citation_count
        ?? citationValidation?.invalidCitationCount
        ?? 0
    );
    const blockedClaimCount = Number(
        message.meta?.blocked_claim_count
        ?? citationValidation?.blockedClaimCount
        ?? blockedClaims.length
        ?? 0
    );
    const sourceEntries = buildVisibleSourceEntries(message);
    const status =
        citationContractStatus
        || (blockedClaimCount > 0
            ? 'blocked'
            : (invalidCitationCount > 0 || uncitedClaimCount > 0 ? 'review_required' : 'passed'));

    const wrapper = document.createElement('section');
    wrapper.className = 'copilot-citation-panel';

    const header = document.createElement('div');
    header.className = 'copilot-citation-header';

    const title = document.createElement('h3');
    title.className = 'copilot-citation-title';
    title.textContent = 'Citation Contract';
    header.appendChild(title);

    const badge = document.createElement('span');
    badge.className = 'copilot-citation-badge';
    badge.dataset.status = status;
    badge.textContent = status === 'passed'
        ? 'All clinical claims cited'
        : (status === 'blocked' ? 'Uncited claims blocked' : 'Clinician review required');
    header.appendChild(badge);
    wrapper.appendChild(header);

    const summary = document.createElement('p');
    summary.className = 'copilot-citation-summary';
    summary.textContent = citationValidation?.userMessage
        || message.meta?.citation_contract_user_message
        || (status === 'blocked'
            ? 'Some clinical claims were hidden because they were not linked to source evidence.'
            : 'Clinical claims remain draft-only and linked back to source evidence.');
    wrapper.appendChild(summary);

    const meta = document.createElement('div');
    meta.className = 'copilot-citation-meta';
    [
        `Claims: ${Number(message.meta?.claim_count ?? claims.length)}`,
        `Cited: ${citedClaimCount}`,
        `Blocked: ${blockedClaimCount}`,
        `Sources: ${Number(message.meta?.source_count ?? sourceEntries.length)}`
    ].forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'copilot-citation-meta-chip';
        chip.textContent = value;
        meta.appendChild(chip);
    });
    wrapper.appendChild(meta);

    const sourceCitations = dedupeCitationList(
        claims.flatMap((claim) => Array.isArray(claim.citations) ? claim.citations : [])
    );
    const sourceChips = buildCitationChipsForMessage(sourceCitations.slice(0, 6), message, {
        compact: false
    });
    if (sourceChips) {
        wrapper.appendChild(sourceChips);
    }

    if (blockedClaims.length > 0) {
        const warning = document.createElement('div');
        warning.className = 'copilot-citation-warning';
        warning.textContent = 'Some clinical claims were hidden because they were not linked to source evidence.';
        wrapper.appendChild(warning);

        const blockedList = document.createElement('ul');
        blockedList.className = 'copilot-citation-blocked-list';
        blockedClaims.slice(0, 4).forEach((claim) => {
            const item = document.createElement('li');
            const claimText = String(claim.text || '').trim();
            const issues = Array.isArray(claim.issues) ? claim.issues.filter(Boolean) : [];
            item.textContent = claimText
                ? `${claimText} — ${issues.join(' ')}`
                : issues.join(' ');
            blockedList.appendChild(item);
        });
        wrapper.appendChild(blockedList);
    }

    return wrapper;
}

function extractionStatusDescriptor(status, options = {}) {
    const normalized = String(status || '').trim().toLowerCase();
    const schemaValid = Boolean(options.schemaValid);
    const defaultReviewLabel = options.reviewStatus === 'clinician_approved'
        ? 'Clinician approved'
        : (options.reviewStatus === 'clinician_rejected' ? 'Clinician rejected' : 'Pending clinician review');
    const statusMap = {
        uploaded: { tone: 'neutral', label: 'Uploaded' },
        extraction_pending: { tone: 'neutral', label: 'Extraction pending' },
        ok: { tone: schemaValid ? 'success' : 'warning', label: schemaValid ? 'Extraction complete' : defaultReviewLabel },
        extracted: { tone: 'success', label: 'Extraction complete' },
        extracted_with_abnormal_flags: { tone: 'warning', label: 'Abnormal values flagged' },
        review_required: { tone: 'warning', label: 'Extraction review required' },
        extraction_review_required: { tone: 'warning', label: 'Extraction review required' },
        missing_reference_range: { tone: 'warning', label: 'Missing reference range' },
        missing_collection_date: { tone: 'warning', label: 'Missing collection date' },
        citation_contract_failed: { tone: 'error', label: 'Citation contract failed' },
        chart_write_blocked: { tone: 'error', label: 'Direct chart write blocked' },
        extraction_failed: { tone: 'error', label: 'Extraction failed' },
        failed: { tone: 'error', label: 'Extraction failed' },
        ocr_required: { tone: 'warning', label: 'OCR / text review required' },
        seeded_demo_fallback: { tone: 'warning', label: 'Pending clinician review' },
        synthetic_demo_pdf_fallback: { tone: 'warning', label: 'Pending clinician review' },
        synthetic_marcus_intake_demo: { tone: 'warning', label: 'Pending clinician review' },
        pending_clinician_review: { tone: 'warning', label: 'Pending clinician review' },
        clinician_approved: { tone: 'success', label: 'Clinician approved' },
        clinician_rejected: { tone: 'error', label: 'Clinician rejected' },
        role_blocked: { tone: 'error', label: 'Blocked by role guardrails' },
        invalid_file_type: { tone: 'error', label: 'Unsupported file type' },
        document_guard_rejected: { tone: 'error', label: 'Unsupported medical document' },
        unsupported_document: { tone: 'error', label: 'Unsupported medical document' }
    };

    if (statusMap[normalized]) {
        return statusMap[normalized];
    }

    if (normalized.includes('review')) {
        return { tone: 'warning', label: 'Extraction review required' };
    }
    if (normalized.includes('fail') || normalized.includes('reject') || normalized.includes('block')) {
        return { tone: 'error', label: 'Extraction failed' };
    }

    return { tone: 'neutral', label: normalized ? normalized.replace(/_/g, ' ') : defaultReviewLabel };
}

function buildExtractionFactCard(options = {}) {
    const card = document.createElement('article');
    card.className = 'copilot-extraction-fact';

    if (options.category) {
        const category = document.createElement('span');
        category.className = 'copilot-extraction-fact-category';
        category.textContent = options.category;
        card.appendChild(category);
    }

    const title = document.createElement('h4');
    title.className = 'copilot-extraction-fact-title';
    title.textContent = String(options.title || 'Extracted fact');
    card.appendChild(title);

    if (options.value) {
        const value = document.createElement('p');
        value.className = 'copilot-extraction-fact-value';
        value.textContent = String(options.value);
        card.appendChild(value);
    }

    const metaItems = Array.isArray(options.metaItems)
        ? options.metaItems.filter((item) => item && String(item).trim() !== '')
        : [];
    if (metaItems.length > 0) {
        const meta = document.createElement('div');
        meta.className = 'copilot-extraction-fact-meta';
        metaItems.forEach((item) => {
            const chip = document.createElement('span');
            chip.className = 'copilot-extraction-fact-chip';
            chip.textContent = String(item);
            meta.appendChild(chip);
        });
        card.appendChild(meta);
    }

    if (options.note) {
        const note = document.createElement('p');
        note.className = 'copilot-extraction-fact-note';
        note.textContent = String(options.note);
        card.appendChild(note);
    }

    const citations = Array.isArray(options.citations)
        ? options.citations.filter((citation) => citation && typeof citation === 'object')
        : [];
    const citationChips = buildCitationChipsForMessage(citations, options.message, {
        compact: false,
        className: 'copilot-extraction-citation-list'
    });
    if (citationChips) {
        card.appendChild(citationChips);
    }

    return card;
}

function buildSchemaValidationFallback(message, strictExtraction = null) {
    const traceEntries = Array.isArray(message?.meta?.agent_trace)
        ? message.meta.agent_trace.filter((entry) => entry && typeof entry === 'object')
        : [];
    const schemaTrace = traceEntries.find((entry) => String(entry.agent || '').trim() === 'SchemaValidationWorker')
        || traceEntries.find((entry) => String(entry.metadata?.status || '').trim() === 'extraction_schema_validated');
    if (!schemaTrace) {
        return null;
    }

    const metadata = schemaTrace.metadata && typeof schemaTrace.metadata === 'object'
        ? schemaTrace.metadata
        : {};
    const schemaValid = Boolean(metadata.schema_valid);
    const reviewStatus = String(metadata.review_status || 'pending_clinician_review').trim();
    const citationCount = Number(metadata.citation_count || 0);
    const documentType = normalizeAttachmentDocumentType(
        strictExtraction?.document_type
        || message?.meta?.tool_output?.documentMetadata?.documentType
        || message?.meta?.tool_output?.sourceMetadata?.sourceType
        || ''
    );

    return {
        schemaValid,
        valid: schemaValid,
        reviewSafe: reviewStatus === 'pending_clinician_review',
        trustedPersistenceAllowed: false,
        trustedRagIndexAllowed: false,
        schemaName: schemaValid
            ? (documentType === 'intake_form' ? 'IntakeFormExtraction' : 'LabPdfExtraction')
            : 'Strict extraction schema',
        schemaFile: '',
        statusLabel: schemaValid ? 'Clinician review required' : 'Schema validation failed',
        userMessage: schemaValid
            ? 'Structured extraction passed the strict schema gate and remains pending clinician review.'
            : 'Extraction completed, but the result did not pass strict validation. Clinician review is required before this information can be used.',
        validationErrors: [],
        validationErrorCount: 0,
        missingRequiredFieldCount: 0,
        citationCount,
        extractionStatus: schemaValid ? 'review_required' : 'failed',
        reviewStatus,
        errors: []
    };
}

function buildExtractionResultsPanel(message) {
    if (!message || message.role !== 'assistant' || message.isLoading) {
        return null;
    }

    const toolOutput = message.meta?.tool_output && typeof message.meta.tool_output === 'object'
        ? message.meta.tool_output
        : null;
    if (!toolOutput) {
        return null;
    }

    const strictExtraction = toolOutput.strictExtraction
        && typeof toolOutput.strictExtraction === 'object'
        && !Array.isArray(toolOutput.strictExtraction)
        && Object.keys(toolOutput.strictExtraction).length > 0
        ? toolOutput.strictExtraction
        : null;
    const schemaValidationRaw = toolOutput.schemaValidation && typeof toolOutput.schemaValidation === 'object'
        ? toolOutput.schemaValidation
        : null;
    const schemaValidation = schemaValidationRaw && Object.keys(schemaValidationRaw).some((key) => {
        const value = schemaValidationRaw[key];
        return value !== '' && value !== null && value !== false && (!Array.isArray(value) || value.length > 0);
    })
        ? schemaValidationRaw
        : buildSchemaValidationFallback(message, strictExtraction);
    const reviewQueue = toolOutput.reviewQueue && typeof toolOutput.reviewQueue === 'object'
        ? toolOutput.reviewQueue
        : null;
    const reviewQueueFacts = Array.isArray(reviewQueue?.facts) ? reviewQueue.facts.filter((fact) => fact && typeof fact === 'object') : [];
    const legacyExtractedFacts = Array.isArray(toolOutput.extractedFacts)
        ? toolOutput.extractedFacts.filter((fact) => fact && typeof fact === 'object')
        : [];
    const intakeFields = toolOutput.intakeFields && typeof toolOutput.intakeFields === 'object' && !Array.isArray(toolOutput.intakeFields)
        ? toolOutput.intakeFields
        : null;
    const documentType = normalizeAttachmentDocumentType(
        strictExtraction?.document_type
        || toolOutput.documentMetadata?.documentType
        || toolOutput.sourceMetadata?.sourceType
        || (intakeFields ? 'intake_form' : (legacyExtractedFacts.length > 0 ? 'lab_pdf' : ''))
    );

    if (!['lab_pdf', 'intake_form'].includes(documentType)) {
        return null;
    }
    if (!strictExtraction && legacyExtractedFacts.length === 0 && !intakeFields && reviewQueueFacts.length === 0) {
        return null;
    }

    const reviewStatus = String(
        reviewQueue?.status
        || schemaValidation?.reviewStatus
        || strictExtraction?.review_status
        || toolOutput.documentMetadata?.reviewStatus
        || 'pending_clinician_review'
    ).trim();
    const descriptor = extractionStatusDescriptor(
        toolOutput.status || strictExtraction?.extraction_status || reviewStatus,
        {
            schemaValid: Boolean(schemaValidation?.schemaValid ?? schemaValidation?.valid),
            reviewStatus
        }
    );
    const missingData = Array.isArray(strictExtraction?.missing_or_ambiguous_data)
        ? strictExtraction.missing_or_ambiguous_data.filter(Boolean)
        : (Array.isArray(toolOutput.missingData)
            ? toolOutput.missingData.filter(Boolean)
            : (Array.isArray(toolOutput.missingDataFlags) ? toolOutput.missingDataFlags.filter(Boolean) : []));
    let sourceCitations = Array.isArray(strictExtraction?.source_citations)
        ? strictExtraction.source_citations.filter((citation) => citation && typeof citation === 'object')
        : [];
    if (sourceCitations.length === 0 && Array.isArray(toolOutput.sourceCitations)) {
        sourceCitations = toolOutput.sourceCitations.filter((citation) => citation && typeof citation === 'object');
    }
    if (sourceCitations.length === 0 && reviewQueueFacts.length > 0) {
        sourceCitations = reviewQueueFacts
            .map((fact) => (fact.citation && typeof fact.citation === 'object' ? fact.citation : null))
            .filter(Boolean);
    }
    if (sourceCitations.length === 0) {
        sourceCitations = dedupeCitationList(
            (Array.isArray(message.claims) ? message.claims : []).flatMap((claim) => Array.isArray(claim.citations) ? claim.citations : [])
        );
    }
    const safetyWarnings = Array.isArray(strictExtraction?.safety_warnings)
        ? strictExtraction.safety_warnings.filter(Boolean)
        : (toolOutput.safeMessage ? [toolOutput.safeMessage] : []);
    const citationContractStatus = String(
        toolOutput.citationValidation?.citationContractStatus
        || message.meta?.citation_contract_status
        || ''
    ).trim();
    const isDemoFallback = /seeded_demo|synthetic_demo|synthetic_marcus/i.test(String(toolOutput.extractionMethod || ''))
        || Boolean(toolOutput.documentMetadata?.seededDemo);

    const wrapper = document.createElement('section');
    wrapper.className = 'copilot-extraction-panel';

    const header = document.createElement('div');
    header.className = 'copilot-extraction-header';

    const titleWrap = document.createElement('div');
    titleWrap.className = 'copilot-extraction-title-wrap';

    const title = document.createElement('h3');
    title.className = 'copilot-extraction-title';
    title.textContent = 'Extraction Results';
    titleWrap.appendChild(title);

    const subtitle = document.createElement('p');
    subtitle.className = 'copilot-extraction-subtitle';
    subtitle.textContent = toolOutput.resultTitle
        ? String(toolOutput.resultTitle)
        : (documentType === 'intake_form'
            ? 'Structured intake facts extracted for draft-only clinician review.'
            : 'Structured lab facts extracted for draft-only clinician review.');
    titleWrap.appendChild(subtitle);
    header.appendChild(titleWrap);

    const badge = document.createElement('span');
    badge.className = 'copilot-extraction-badge';
    badge.dataset.status = descriptor.tone;
    badge.textContent = descriptor.label;
    header.appendChild(badge);
    wrapper.appendChild(header);

    const reviewBanner = document.createElement('div');
    reviewBanner.className = 'copilot-extraction-review-banner';
    reviewBanner.textContent = 'Clinician review required: extracted document facts are draft-only and are not written to the chart automatically.';
    wrapper.appendChild(reviewBanner);

    const meta = document.createElement('div');
    meta.className = 'copilot-extraction-meta';
    [
        documentType === 'intake_form' ? 'Document type: Intake Form' : 'Document type: Lab PDF',
        `Review status: ${String(reviewStatus || 'pending_clinician_review').replace(/_/g, ' ')}`,
        `Schema valid: ${schemaValidation ? (Boolean(schemaValidation.schemaValid ?? schemaValidation.valid) ? 'Yes' : 'No') : 'Unknown'}`,
        `Citation contract: ${citationContractStatus ? citationContractStatus.replace(/_/g, ' ') : 'pending validation'}`,
        isDemoFallback ? 'Demo fallback extraction: Yes' : '',
        toolOutput.documentMetadata?.title ? `Source: ${String(toolOutput.documentMetadata.title)}` : ''
    ].filter(Boolean).forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'copilot-extraction-meta-chip';
        chip.textContent = value;
        meta.appendChild(chip);
    });
    wrapper.appendChild(meta);

    const factGrid = document.createElement('div');
    factGrid.className = 'copilot-extraction-fact-grid';

    if (documentType === 'lab_pdf') {
        const labs = Array.isArray(strictExtraction?.labs)
            ? strictExtraction.labs.filter((lab) => lab && typeof lab === 'object')
            : (legacyExtractedFacts.length > 0
                ? legacyExtractedFacts.map((fact) => ({
                    test_name: fact.name || 'Lab result',
                    value: fact.value || '',
                    unit: '',
                    reference_range: '',
                    abnormal_flag: fact.interpretation || '',
                    collection_date: '',
                    resulted_date: '',
                    confidence: 0.9,
                    source_quote_or_value: '',
                    source_citation: Array.isArray(fact.sourceLink) ? fact.sourceLink[0] || null : null
                }))
                : reviewQueueFacts.map((fact) => ({
                    test_name: fact.label || 'Lab result',
                    value: fact.value || '',
                    unit: '',
                    reference_range: '',
                    abnormal_flag: '',
                    collection_date: '',
                    resulted_date: '',
                    confidence: Number(fact.confidence || 0),
                    source_quote_or_value: '',
                    source_citation: fact.citation || null
                })));
        labs.slice(0, 8).forEach((lab) => {
            factGrid.appendChild(buildExtractionFactCard({
                message,
                category: 'Lab result',
                title: String(lab.test_name || 'Lab result'),
                value: [lab.value, lab.unit].filter((item) => String(item || '').trim() !== '').join(' '),
                metaItems: [
                    lab.reference_range ? `Reference range: ${lab.reference_range}` : '',
                    lab.abnormal_flag ? `Flag: ${String(lab.abnormal_flag).replace(/_/g, ' ')}` : '',
                    lab.collection_date ? `Collected: ${lab.collection_date}` : '',
                    lab.resulted_date ? `Resulted: ${lab.resulted_date}` : '',
                    lab.proposed_fhir_resource_type ? `Proposed target: ${lab.proposed_fhir_resource_type}` : '',
                    Number.isFinite(Number(lab.confidence)) ? `Confidence: ${Math.round(Number(lab.confidence) * 100)}%` : ''
                ],
                note: lab.source_quote_or_value ? `Source value: ${lab.source_quote_or_value}` : '',
                citations: [lab.source_citation || null]
            }));
        });
    } else {
        const demographics = strictExtraction?.demographics && typeof strictExtraction.demographics === 'object'
            ? strictExtraction.demographics
            : {};
        [
            ['First name', demographics.first_name],
            ['Last name', demographics.last_name],
            ['Date of birth', demographics.date_of_birth],
            ['Phone', demographics.phone],
            ['Email', demographics.email],
            ['Address', demographics.address],
            ['Emergency contact', demographics.emergency_contact]
        ].forEach(([label, field]) => {
            if (!field || typeof field !== 'object') {
                return;
            }
            factGrid.appendChild(buildExtractionFactCard({
                message,
                category: 'Demographics',
                title: label,
                value: String(field.value || 'Pending clarification'),
                metaItems: [
                    Number.isFinite(Number(field.confidence)) ? `Confidence: ${Math.round(Number(field.confidence) * 100)}%` : ''
                ],
                citations: [field.source_citation || null]
            }));
        });

        const chiefConcern = strictExtraction?.chief_concern && typeof strictExtraction.chief_concern === 'object'
            ? strictExtraction.chief_concern
            : (intakeFields?.reasonForVisit ? {
                value: intakeFields.reasonForVisit,
                source_citation: sourceCitations[0] || null,
                confidence: 0.9
            } : null);
        if (chiefConcern) {
            factGrid.appendChild(buildExtractionFactCard({
                message,
                category: 'Chief concern',
                title: 'Chief concern',
                value: String(chiefConcern.value || 'Pending clarification'),
                metaItems: [
                    chiefConcern.duration ? `Duration: ${chiefConcern.duration}` : '',
                    chiefConcern.severity ? `Severity: ${chiefConcern.severity}` : '',
                    Number.isFinite(Number(chiefConcern.confidence)) ? `Confidence: ${Math.round(Number(chiefConcern.confidence) * 100)}%` : ''
                ],
                citations: [chiefConcern.source_citation || null]
            }));
        }

        const intakeCollections = [
            ['Current medication', strictExtraction?.current_medications || [], (item) => ({
                title: String(item.medication_name || 'Medication'),
                value: [item.dose, item.frequency].filter(Boolean).join(' • '),
                metaItems: [
                    item.route ? `Route: ${item.route}` : '',
                    item.review_status ? `Review: ${String(item.review_status).replace(/_/g, ' ')}` : '',
                    Number.isFinite(Number(item.confidence)) ? `Confidence: ${Math.round(Number(item.confidence) * 100)}%` : ''
                ],
                note: item.normalized_value ? `Normalized: ${item.normalized_value}` : '',
                citations: [item.source_citation || null]
            })],
            ['Allergy', strictExtraction?.allergies || [], (item) => ({
                title: String(item.allergen || 'Allergy'),
                value: [item.reaction, item.severity].filter(Boolean).join(' • '),
                metaItems: [
                    item.review_status ? `Review: ${String(item.review_status).replace(/_/g, ' ')}` : '',
                    Number.isFinite(Number(item.confidence)) ? `Confidence: ${Math.round(Number(item.confidence) * 100)}%` : ''
                ],
                citations: [item.source_citation || null]
            })],
            ['Family history', strictExtraction?.family_history || [], (item) => ({
                title: [item.relation, item.condition].filter(Boolean).join(': ') || 'Family history',
                value: item.age_of_onset ? `Age of onset: ${item.age_of_onset}` : 'Pending clarification',
                metaItems: [
                    item.review_status ? `Review: ${String(item.review_status).replace(/_/g, ' ')}` : '',
                    Number.isFinite(Number(item.confidence)) ? `Confidence: ${Math.round(Number(item.confidence) * 100)}%` : ''
                ],
                citations: [item.source_citation || null]
            })]
        ];

        intakeCollections.forEach(([category, collection, mapper]) => {
            if (!Array.isArray(collection)) {
                return;
            }
            collection.slice(0, 4).forEach((item) => {
                if (!item || typeof item !== 'object') {
                    return;
                }
                const card = mapper(item);
                factGrid.appendChild(buildExtractionFactCard({
                    message,
                    category,
                    title: card.title,
                    value: card.value,
                    metaItems: card.metaItems,
                    note: card.note,
                    citations: card.citations
                }));
            });
        });

        if (factGrid.childElementCount === 0 && intakeFields) {
            [
                ['Reason for visit', intakeFields.reasonForVisit],
                ['Current concerns', intakeFields.currentConcerns],
                ['Medication adherence', intakeFields.medicationAdherence],
                ['Allergies', intakeFields.allergies],
                ['Insurance update', intakeFields.insuranceUpdate],
                ['Care preferences', intakeFields.carePreferences]
            ].forEach(([titleText, value]) => {
                if (!String(value || '').trim()) {
                    return;
                }
                factGrid.appendChild(buildExtractionFactCard({
                    message,
                    category: 'Intake field',
                    title: titleText,
                    value: String(value),
                    citations: sourceCitations.slice(0, 1)
                }));
            });
        }
    }

    if (factGrid.childElementCount > 0) {
        wrapper.appendChild(factGrid);
    }

    if (missingData.length > 0) {
        const missingSection = document.createElement('section');
        missingSection.className = 'copilot-extraction-list-block';

        const missingTitle = document.createElement('h4');
        missingTitle.className = 'copilot-extraction-list-title';
        missingTitle.textContent = 'Missing / ambiguous data';
        missingSection.appendChild(missingTitle);

        const list = document.createElement('ul');
        list.className = 'copilot-extraction-list';
        missingData.slice(0, 8).forEach((entry) => {
            const item = document.createElement('li');
            item.className = 'copilot-extraction-list-item';
            item.textContent = typeof entry === 'string'
                ? entry
                : `${String(entry.field || 'Field')}: ${String(entry.issue || 'Requires clinician review.')}`;
            list.appendChild(item);
        });
        missingSection.appendChild(list);
        wrapper.appendChild(missingSection);
    }

    if (safetyWarnings.length > 0) {
        const warning = document.createElement('div');
        warning.className = 'copilot-extraction-warning';
        warning.textContent = safetyWarnings.slice(0, 2).join(' ');
        wrapper.appendChild(warning);
    }

    if (sourceCitations.length > 0) {
        const sources = document.createElement('section');
        sources.className = 'copilot-extraction-list-block';

        const sourcesTitle = document.createElement('h4');
        sourcesTitle.className = 'copilot-extraction-list-title';
        sourcesTitle.textContent = 'Sources Used';
        sources.appendChild(sourcesTitle);

        const chips = buildCitationChipsForMessage(sourceCitations.slice(0, 8), message, {
            compact: false,
            className: 'copilot-extraction-citation-list'
        });
        if (chips) {
            sources.appendChild(chips);
        }

        wrapper.appendChild(sources);
    }

    return wrapper;
}

function buildEvidenceSnippetsPanel(message) {
    if (!message || message.role !== 'assistant' || message.isLoading) {
        return null;
    }

    const snippets = Array.isArray(message.evidenceSnippets)
        ? message.evidenceSnippets.filter((snippet) => snippet && typeof snippet === 'object')
        : [];
    const retrievalMode = String(message.meta?.retrieval_mode || snippets[0]?.retrieval_mode || '').trim();
    const rerankProvider = String(message.meta?.rerank_provider || snippets[0]?.rerank_provider || '').trim();
    if (snippets.length === 0 && !retrievalMode) {
        return null;
    }

    const wrapper = document.createElement('details');
    wrapper.className = 'copilot-evidence-panel';
    wrapper.open = false;

    const summary = document.createElement('summary');
    summary.className = 'copilot-evidence-summary';

    const titleWrap = document.createElement('div');
    titleWrap.className = 'copilot-evidence-summary-copy';

    const title = document.createElement('h3');
    title.className = 'copilot-evidence-title';
    title.textContent = 'Evidence Snippets';
    titleWrap.appendChild(title);

    const copy = document.createElement('p');
    copy.className = 'copilot-evidence-summary-text';
    copy.textContent = snippets.length > 0
        ? 'Top grounded evidence returned to the response workflow.'
        : 'No grounded evidence snippets were retrieved for this request.';
    titleWrap.appendChild(copy);
    summary.appendChild(titleWrap);

    const countChip = document.createElement('span');
    countChip.className = 'copilot-evidence-toggle-chip';
    countChip.textContent = `${snippets.length} snippet${snippets.length === 1 ? '' : 's'}`;
    summary.appendChild(countChip);
    wrapper.appendChild(summary);

    const body = document.createElement('div');
    body.className = 'copilot-evidence-body';

    const meta = document.createElement('div');
    meta.className = 'copilot-evidence-meta';
    [
        retrievalMode ? `Retrieval mode: ${retrievalModeLabel(retrievalMode)}` : '',
        rerankProvider ? `Reranker: ${rerankProviderLabel(rerankProvider)}` : '',
        Number.isFinite(Number(message.meta?.reranked_result_count)) ? `Top snippets: ${Number(message.meta.reranked_result_count || 0)}` : '',
        Number.isFinite(Number(message.meta?.hybrid_candidate_count)) ? `Candidates: ${Number(message.meta.hybrid_candidate_count || 0)}` : ''
    ].filter(Boolean).forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'copilot-evidence-meta-chip';
        chip.textContent = value;
        meta.appendChild(chip);
    });
    body.appendChild(meta);

    if (snippets.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'copilot-evidence-empty';
        empty.textContent = 'I do not have enough source-grounded information to answer that safely.';
        body.appendChild(empty);
    } else {
        const list = document.createElement('div');
        list.className = 'copilot-evidence-list';

        snippets.slice(0, 5).forEach((snippet) => {
            const item = document.createElement('article');
            item.className = 'copilot-evidence-item';

            const snippetText = document.createElement('p');
            snippetText.className = 'copilot-evidence-text';
            snippetText.textContent = String(snippet.text || '').trim() || 'No evidence text was returned for this snippet.';
            item.appendChild(snippetText);

            const detailRow = document.createElement('div');
            detailRow.className = 'copilot-evidence-item-meta';
            [
                snippet.source_title || citationSourceTypeLabel(snippet.citation || {}),
                Number.isFinite(Number(snippet.relevance_score)) ? `Relevance: ${Math.round(Number(snippet.relevance_score) * 100)}%` : '',
                snippet.retrieval_mode ? `Mode: ${retrievalModeLabel(snippet.retrieval_mode)}` : '',
                snippet.rerank_provider ? `Reranker: ${rerankProviderLabel(snippet.rerank_provider)}` : ''
            ].filter(Boolean).forEach((value) => {
                const chip = document.createElement('span');
                chip.className = 'copilot-evidence-item-chip';
                chip.textContent = value;
                detailRow.appendChild(chip);
            });
            item.appendChild(detailRow);

            const citation = snippet.citation && typeof snippet.citation === 'object'
                ? snippet.citation
                : null;
            if (citation) {
                const chips = buildCitationChipsForMessage([citation], message, {
                    compact: false,
                    className: 'copilot-evidence-citation-list'
                });
                if (chips) {
                    item.appendChild(chips);
                }
            }

            list.appendChild(item);
        });

        body.appendChild(list);
    }

    wrapper.appendChild(body);
    return wrapper;
}

function ensureCitationPreviewShell() {
    if (citationPreviewShell) {
        return citationPreviewShell;
    }

    const root = document.createElement('div');
    root.className = 'copilot-citation-preview-root copilot-ambient-modal-root';
    root.hidden = true;

    const backdrop = document.createElement('div');
    backdrop.className = 'copilot-citation-preview-backdrop copilot-ambient-modal-backdrop';
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            closeCitationSourcePreview();
        }
    });

    const modal = document.createElement('div');
    modal.className = 'copilot-citation-preview-modal copilot-ambient-modal copilot-ambient-modal-wide';

    root.appendChild(backdrop);
    backdrop.appendChild(modal);
    document.body.appendChild(root);
    citationPreviewShell = { root, backdrop, modal };
    return citationPreviewShell;
}

function closeCitationSourcePreview() {
    state.citationPreview = {
        open: false,
        loading: false,
        error: '',
        data: null,
        title: ''
    };
    renderCitationPreviewShell();
}

function setCitationPreviewState(nextState) {
    state.citationPreview = {
        ...state.citationPreview,
        ...nextState
    };
    renderCitationPreviewShell();
}

function buildBoundingBoxPercent(box) {
    if (!box || typeof box !== 'object') {
        return null;
    }

    if (String(box.coordinate_system || 'normalized_0_1') === 'pdf_points') {
        const pageWidth = 612;
        const pageHeight = 792;
        return {
            left: `${Math.max(0, Math.min(100, (Number(box.x || 0) / pageWidth) * 100))}%`,
            top: `${Math.max(0, Math.min(100, (Number(box.y || 0) / pageHeight) * 100))}%`,
            width: `${Math.max(2, Math.min(100, (Number(box.width || 0) / pageWidth) * 100))}%`,
            height: `${Math.max(2, Math.min(100, (Number(box.height || 0) / pageHeight) * 100))}%`
        };
    }

    return {
        left: `${Math.max(0, Math.min(100, Number(box.x || 0) * 100))}%`,
        top: `${Math.max(0, Math.min(100, Number(box.y || 0) * 100))}%`,
        width: `${Math.max(2, Math.min(100, Number(box.width || 0) * 100))}%`,
        height: `${Math.max(2, Math.min(100, Number(box.height || 0) * 100))}%`
    };
}

function buildPdfEvidenceOverlay(citation) {
    const boundingBoxes = [];
    if (Array.isArray(citation?.bounding_boxes)) {
        citation.bounding_boxes.forEach((box) => {
            if (box && typeof box === 'object') {
                boundingBoxes.push(box);
            }
        });
    } else if (citation?.bounding_box && typeof citation.bounding_box === 'object') {
        boundingBoxes.push(citation.bounding_box);
    }

    const wrapper = document.createElement('section');
    wrapper.className = 'copilot-pdf-overlay';

    const title = document.createElement('p');
    title.className = 'copilot-pdf-overlay-title';
    title.textContent = 'PDF Evidence Overlay';
    wrapper.appendChild(title);

    if (boundingBoxes.length === 0) {
        const note = document.createElement('p');
        note.className = 'copilot-pdf-overlay-note';
        note.textContent = 'Exact PDF highlight unavailable for this source.';
        wrapper.appendChild(note);
        return wrapper;
    }

    const page = document.createElement('div');
    page.className = 'copilot-pdf-overlay-page';

    boundingBoxes.forEach((box) => {
        const percent = buildBoundingBoxPercent(box);
        if (!percent) {
            return;
        }
        const highlight = document.createElement('div');
        highlight.className = 'copilot-pdf-overlay-box';
        highlight.style.left = percent.left;
        highlight.style.top = percent.top;
        highlight.style.width = percent.width;
        highlight.style.height = percent.height;
        page.appendChild(highlight);
    });

    wrapper.appendChild(page);
    return wrapper;
}

function buildCitationPreviewBody(data) {
    const fragment = document.createDocumentFragment();
    const fallbackPreviewUrl = (
        !data.preview_url
        && copilotConfig.documentPreviewUrl
        && Number(data.document?.source_document_id || 0) > 0
        && Number(data.document?.patient_id || 0) > 0
    )
        ? `${copilotConfig.documentPreviewUrl}?source_document_id=${encodeURIComponent(String(data.document.source_document_id))}&patient_id=${encodeURIComponent(String(data.document.patient_id))}&role=${encodeURIComponent(String(state.activeRole || 'doctor'))}`
        : '';
    const previewUrl = data.preview_url || fallbackPreviewUrl;

    const meta = document.createElement('div');
    meta.className = 'copilot-citation-preview-meta';
    [
        `Source type: ${citationSourceTypeLabel(data.citation || data)}`,
        data.page_or_section ? `Location: ${data.page_or_section}` : '',
        data.field_or_chunk_id ? `Field / chunk: ${data.field_or_chunk_id}` : '',
        (data.citation?.source_document_id || data.document?.source_document_id)
            ? `Source document: ${String(data.citation?.source_document_id || data.document?.source_document_id)}`
            : '',
        data.confidence !== undefined ? `Confidence: ${citationConfidenceLabel(data.confidence)}` : '',
        data.review_status ? `Review: ${String(data.review_status).replace(/_/g, ' ')}` : ''
    ].filter(Boolean).forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'copilot-citation-preview-chip';
        chip.textContent = value;
        meta.appendChild(chip);
    });
    fragment.appendChild(meta);

    const quote = document.createElement('section');
    quote.className = 'copilot-citation-preview-copy';

    const quoteTitle = document.createElement('h4');
    quoteTitle.textContent = 'Cited evidence';
    quote.appendChild(quoteTitle);

    const quoteText = document.createElement('pre');
    quoteText.className = 'copilot-citation-preview-quote';
    quoteText.textContent = String(data.quote_or_value || data.preview_text || 'No source snippet was available.');
    quote.appendChild(quoteText);
    fragment.appendChild(quote);

    if (data.preview_mode === 'pdf') {
        const pdfWrap = document.createElement('section');
        pdfWrap.className = 'copilot-citation-preview-pdf';

        if (previewUrl) {
            const iframe = document.createElement('iframe');
            iframe.className = 'copilot-citation-preview-frame';
            iframe.src = previewUrl;
            iframe.loading = 'lazy';
            iframe.title = data.source_label || 'Document preview';
            pdfWrap.appendChild(iframe);
        }

        pdfWrap.appendChild(buildPdfEvidenceOverlay(data.citation || data));
        fragment.appendChild(pdfWrap);
    } else if (data.preview_mode === 'rag_snippet') {
        const snippet = document.createElement('section');
        snippet.className = 'copilot-citation-preview-copy';

        const title = document.createElement('h4');
        title.textContent = data.field_or_chunk_id
            ? `Retrieved snippet · ${data.field_or_chunk_id}`
            : 'Retrieved snippet';
        snippet.appendChild(title);

        const copy = document.createElement('pre');
        copy.className = 'copilot-citation-preview-quote';
        copy.textContent = String(data.preview_text || data.quote_or_value || '');
        snippet.appendChild(copy);
        fragment.appendChild(snippet);
    }

    if (previewUrl) {
        const linkRow = document.createElement('div');
        linkRow.className = 'copilot-citation-preview-links';

        const openLink = document.createElement('a');
        openLink.className = 'copilot-citation-preview-link';
        openLink.href = previewUrl;
        openLink.target = '_blank';
        openLink.rel = 'noopener noreferrer';
        openLink.textContent = 'Open source document';
        linkRow.appendChild(openLink);

        fragment.appendChild(linkRow);
    }

    return fragment;
}

function renderCitationPreviewShell() {
    const shell = ensureCitationPreviewShell();
    const { root, modal } = shell;
    modal.innerHTML = '';

    if (!state.citationPreview.open) {
        root.hidden = true;
        return;
    }

    root.hidden = false;

    const header = document.createElement('div');
    header.className = 'copilot-ambient-modal-header';

    const copy = document.createElement('div');
    const title = document.createElement('h3');
    title.textContent = state.citationPreview.title || 'Source preview';
    copy.appendChild(title);

    const subtitle = document.createElement('p');
    subtitle.textContent = state.citationPreview.loading
        ? 'Loading cited source material...'
        : 'Review the supporting source evidence for this clinical claim.';
    copy.appendChild(subtitle);
    header.appendChild(copy);

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'copilot-ambient-modal-close';
    closeButton.setAttribute('aria-label', 'Close source preview');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', closeCitationSourcePreview);
    header.appendChild(closeButton);
    modal.appendChild(header);

    const body = document.createElement('div');
    body.className = 'copilot-ambient-modal-body';

    if (state.citationPreview.loading) {
        const loading = document.createElement('p');
        loading.textContent = 'Loading source preview...';
        body.appendChild(loading);
    } else if (state.citationPreview.error) {
        const error = document.createElement('div');
        error.className = 'copilot-ambient-modal-error';
        error.textContent = state.citationPreview.error;
        body.appendChild(error);
    } else if (state.citationPreview.data) {
        body.appendChild(buildCitationPreviewBody(state.citationPreview.data));
    }

    modal.appendChild(body);
}

async function openCitationSourcePreview(message, citation) {
    if (!message || !citation || typeof citation !== 'object') {
        return;
    }

    setCitationPreviewState({
        open: true,
        loading: true,
        error: '',
        data: null,
        title: citationPreviewTitle(citation)
    });

    try {
        restoreSessionIfAvailable();
        const response = await fetch(copilotConfig.citationSourceUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                csrf_token_form: copilotConfig.csrfToken,
                citation,
                patient_id: Number(message.patientId || patientSelect?.value || 0),
                role: message.staffRole || state.activeRole
            })
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Unable to load the cited source preview right now.');
        }

        setCitationPreviewState({
            open: true,
            loading: false,
            error: '',
            data: data.preview || null,
            title: data.preview?.source_label || citationPreviewTitle(citation)
        });
    } catch (error) {
        setCitationPreviewState({
            open: true,
            loading: false,
            error: error.message || 'Unable to load the cited source preview right now.',
            data: null
        });
    }
}

function buildAttachmentEvidenceMeta(toolOutput) {
    if (!toolOutput || typeof toolOutput !== 'object') {
        return null;
    }

    const sourceType = normalizeAttachmentDocumentType(toolOutput.sourceMetadata?.sourceType || toolOutput.documentMetadata?.documentType);
    if (!['lab_pdf', 'intake_form'].includes(sourceType) && toolOutput.tool !== 'attach_and_vectorize_lab_pdf') {
        return null;
    }

    const blockedStatuses = ['invalid_file_type', 'role_blocked', 'ocr_required', 'extraction_review_required', 'document_guard_rejected', 'document_guard_review_required', 'unsupported_document', 'citation_contract_failed', 'missing_collection_date', 'missing_reference_range', 'chart_write_blocked', 'review_required'];
    const status = String(toolOutput.status || '');
    const chunkCount = Number.isFinite(toolOutput.sourceMetadata?.chunkCount) ? toolOutput.sourceMetadata.chunkCount : (Number.isFinite(toolOutput.numberOfChunks) ? toolOutput.numberOfChunks : 0);
    const retrievedChunkCount = Number.isFinite(toolOutput.retrieval?.chunkCount) ? toolOutput.retrieval.chunkCount : (Array.isArray(toolOutput.retrieval?.chunkIds) ? toolOutput.retrieval.chunkIds.length : 0);
    const documentTitle = toolOutput.documentMetadata?.title || toolOutput.sourceMetadata?.fileName || '';

    return {
        attempted: true,
        documentType: sourceType || 'lab_pdf',
        documentTitle,
        status,
        chunkCount,
        retrievedChunkCount,
        hasRetrievedChunks: !blockedStatuses.includes(status) && retrievedChunkCount > 0
    };
}

function buildRagGroundingNoteText(message) {
    if (!message || message.role !== 'assistant') {
        return '';
    }

    const attachmentEvidence = message.meta?.attachment_evidence && typeof message.meta.attachment_evidence === 'object'
        ? message.meta.attachment_evidence
        : null;
    if (attachmentEvidence && attachmentEvidence.attempted) {
        if (attachmentEvidence.hasRetrievedChunks) {
            return attachmentEvidence.documentType === 'intake_form'
                ? 'RAG-grounded response: uploaded intake-form chunks were retrieved before drafting this answer.'
                : 'RAG-grounded response: uploaded lab PDF chunks were retrieved before drafting this answer.';
        }

        return 'Uploaded PDF ingestion was attempted, but no readable lab evidence chunks were retrieved.';
    }

    if (message.meta?.rag_grounded && buildVisibleSourceEntries(message).length > 0) {
        return 'RAG-grounded response: retrieved chart context was used before drafting this answer.';
    }

    return '';
}

function shouldShowRagGroundingNote(message) {
    return buildRagGroundingNoteText(message) !== '';
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
    const attachmentEvidence = message.meta?.attachment_evidence && typeof message.meta.attachment_evidence === 'object'
        ? message.meta.attachment_evidence
        : null;
    if (engine === 'openai') {
        if (attachmentEvidence && attachmentEvidence.attempted) {
            status.textContent = attachmentEvidence.hasRetrievedChunks
                ? 'LLM status: OpenAI response generated with uploaded PDF retrieval context.'
                : 'LLM status: OpenAI response generated without retrieved uploaded PDF chunks. OCR/manual review may still be required.';
        } else {
            status.textContent = 'LLM status: OpenAI response generated with retrieved chart context.';
        }
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

    if (message.meta.retrieval_mode) {
        items.push(['Retrieval mode', retrievalModeLabel(message.meta.retrieval_mode)]);
    }

    if (message.meta.rerank_provider) {
        items.push(['Reranker', rerankProviderLabel(message.meta.rerank_provider)]);
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
    const ragGroundingNote = shouldShowRagGroundingNote(message) ? buildRagGroundingNoteText(message) : '';

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
            const itemText = document.createElement('span');
            itemText.className = 'copilot-section-item-text';
            itemText.textContent = item;
            listItem.appendChild(itemText);

            if (message.role === 'assistant') {
                const matchingClaims = findMatchingClaimsForSectionItem(message, item, section.title || '');
                const citations = dedupeCitationList(
                    matchingClaims.flatMap((claim) => Array.isArray(claim.citations) ? claim.citations : [])
                );
                const citationChips = buildCitationChipsForMessage(citations, message);
                if (citationChips) {
                    listItem.appendChild(citationChips);
                }
            }
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
            const label = document.createElement('span');
            label.className = 'copilot-section-item-text';
            label.textContent = entry.title;
            listItem.appendChild(label);

            const sourceCitation = findCitationForSourceEntry(message, entry);
            if (sourceCitation) {
                const chips = buildCitationChipsForMessage([sourceCitation], message);
                if (chips) {
                    listItem.appendChild(chips);
                }
            }
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

        const observabilityTrace = buildObservabilityTraceCard(message);
        if (observabilityTrace) {
            stack.appendChild(observabilityTrace);
        }

        const runtimeMeta = buildAssistantRuntimeMeta(message);
        if (runtimeMeta) {
            stack.appendChild(runtimeMeta);
        }

        const extractionResultsPanel = buildExtractionResultsPanel(message);
        if (extractionResultsPanel) {
            stack.appendChild(extractionResultsPanel);
        }

        const evidenceSnippetsPanel = buildEvidenceSnippetsPanel(message);
        if (evidenceSnippetsPanel) {
            stack.appendChild(evidenceSnippetsPanel);
        }

        const schemaValidationPanel = buildSchemaValidationPanel(message);
        if (schemaValidationPanel) {
            stack.appendChild(schemaValidationPanel);
        }

        const citationContractPanel = buildCitationContractPanel(message);
        if (citationContractPanel) {
            stack.appendChild(citationContractPanel);
        }

        const clinicianReviewPanel = buildClinicianReviewPanel(message);
        if (clinicianReviewPanel) {
            stack.appendChild(clinicianReviewPanel);
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
    const ragGroundingNote = shouldShowRagGroundingNote(message) ? buildRagGroundingNoteText(message) : '';
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

function reviewDecisionToStatus(decision) {
    if (decision === 'approved') {
        return 'clinician_approved';
    }
    if (decision === 'rejected') {
        return 'clinician_rejected';
    }
    return 'pending_clinician_review';
}

function reviewDecisionLabel(status) {
    if (status === 'clinician_approved') {
        return 'Approved';
    }
    if (status === 'clinician_rejected') {
        return 'Rejected';
    }
    return 'Pending';
}

function updateReviewQueueForMessage(messageId, factId, reviewStatus, summary) {
    const messageIndex = state.messages.findIndex((message) => message.id === messageId);
    if (messageIndex === -1) {
        return;
    }

    const nextMessage = {
        ...state.messages[messageIndex],
        meta: {
            ...(state.messages[messageIndex].meta || {})
        }
    };
    const toolOutput = nextMessage.meta.tool_output && typeof nextMessage.meta.tool_output === 'object'
        ? { ...nextMessage.meta.tool_output }
        : {};
    const reviewQueue = toolOutput.reviewQueue && typeof toolOutput.reviewQueue === 'object'
        ? { ...toolOutput.reviewQueue }
        : {};
    const facts = Array.isArray(reviewQueue.facts) ? reviewQueue.facts.map((fact) => {
        if (!fact || typeof fact !== 'object' || Number(fact.id || 0) !== Number(factId)) {
            return fact;
        }

        return {
            ...fact,
            reviewStatus
        };
    }) : [];

    reviewQueue.facts = facts;
    if (summary && typeof summary === 'object') {
        reviewQueue.summary = {
            ...(reviewQueue.summary || {}),
            ...summary
        };
    }
    if (summary && typeof summary.reviewStatus === 'string') {
        reviewQueue.status = summary.reviewStatus;
    }

    toolOutput.reviewQueue = reviewQueue;
    nextMessage.meta.tool_output = toolOutput;
    state.messages[messageIndex] = nextMessage;
    renderMessages(false);
}

async function submitClinicianReviewDecision(message, fact, decision) {
    if (!message || !fact || !decision) {
        return;
    }

    const requestKey = `${message.id}:${fact.id}:${decision}`;
    if (reviewSubmissionState.has(requestKey)) {
        return;
    }

    reviewSubmissionState.add(requestKey);
    renderMessages(false);

    try {
        const response = await fetch('api/document_review.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                csrf_token_form: copilotConfig.csrfToken,
                fact_id: Number(fact.id || 0),
                decision,
                role: message.staffRole || state.activeRole
            })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || data.message || 'Unable to record the clinician review decision.');
        }

        const reviewStatus = data.fact?.review_status || reviewDecisionToStatus(decision);
        updateReviewQueueForMessage(message.id, fact.id, reviewStatus, data.summary || {});

        if (CopilotTelemetry) {
            const eventName = decision === 'approved'
                ? 'clinician_fact_approved'
                : (decision === 'rejected' ? 'clinician_fact_rejected' : 'clinician_review_completed');
            CopilotTelemetry.log(eventName, {
                requestId: message.requestId || null,
                role: message.staffRole || state.activeRole,
                mode: message.mode || 'lab_pdf_ingestion',
                selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey(),
                documentType: normalizeAttachmentDocumentType(message.meta?.tool_output?.documentMetadata?.documentType || 'lab_pdf'),
                toolStatus: decision,
                reviewStatus: reviewStatus,
                approvedItemCount: Number(data.summary?.approvedCount || 0)
            });
            if ((Number(data.summary?.pendingCount || 0) === 0) || decision === 'pending') {
                CopilotTelemetry.log('clinician_review_completed', {
                    requestId: message.requestId || null,
                    role: message.staffRole || state.activeRole,
                    mode: message.mode || 'lab_pdf_ingestion',
                    selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey(),
                    documentType: normalizeAttachmentDocumentType(message.meta?.tool_output?.documentMetadata?.documentType || 'lab_pdf'),
                    toolStatus: 'review_updated',
                    reviewStatus: data.summary?.reviewStatus || reviewStatus,
                    approvedItemCount: Number(data.summary?.approvedCount || 0)
                });
            }
        }
    } catch (error) {
        setUploadNotice(error.message || 'Unable to save the clinician review decision right now.', 'error', {
            timeoutMs: 5200
        });
    } finally {
        reviewSubmissionState.delete(requestKey);
        renderMessages(false);
    }
}

function buildClinicianReviewPanel(message) {
    if (!shouldRenderResponseActions(message)) {
        return null;
    }

    const toolOutput = message.meta?.tool_output && typeof message.meta.tool_output === 'object'
        ? message.meta.tool_output
        : null;
    const reviewQueue = toolOutput?.reviewQueue && typeof toolOutput.reviewQueue === 'object'
        ? toolOutput.reviewQueue
        : null;
    const facts = Array.isArray(reviewQueue?.facts) ? reviewQueue.facts.filter((fact) => fact && typeof fact === 'object') : [];
    if (facts.length === 0) {
        return null;
    }

    if (!message.meta?.clinician_review_opened_logged && CopilotTelemetry) {
        CopilotTelemetry.log('clinician_review_opened', {
            requestId: message.requestId || null,
            role: message.staffRole || state.activeRole,
            mode: message.mode || 'lab_pdf_ingestion',
            selectedPatientKey: message.selectedPatientKey || currentSelectedPatientKey(),
            documentType: normalizeAttachmentDocumentType(toolOutput?.documentMetadata?.documentType || 'lab_pdf'),
            toolStatus: 'clinician_review_opened',
            reviewStatus: reviewQueue.status || 'pending_clinician_review',
            approvedItemCount: Number(reviewQueue.summary?.approvedCount || 0)
        });
        message.meta.clinician_review_opened_logged = true;
    }

    const wrapper = document.createElement('section');
    wrapper.className = 'copilot-review-panel';

    const banner = document.createElement('div');
    banner.className = 'copilot-review-banner';
    banner.textContent = reviewQueue.banner || 'Clinician Review Required';
    wrapper.appendChild(banner);

    const helper = document.createElement('p');
    helper.className = 'copilot-review-helper';
    helper.textContent = reviewQueue.helperText || 'Approved for demo review — not written to chart automatically.';
    wrapper.appendChild(helper);

    const summary = document.createElement('div');
    summary.className = 'copilot-review-summary';
    const summaryItems = [
        `Pending: ${Number(reviewQueue.summary?.pendingCount || facts.filter((fact) => fact.reviewStatus !== 'clinician_approved' && fact.reviewStatus !== 'clinician_rejected').length)}`,
        `Approved: ${Number(reviewQueue.summary?.approvedCount || facts.filter((fact) => fact.reviewStatus === 'clinician_approved').length)}`,
        `Rejected: ${Number(reviewQueue.summary?.rejectedCount || facts.filter((fact) => fact.reviewStatus === 'clinician_rejected').length)}`
    ];
    summaryItems.forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'copilot-review-summary-chip';
        chip.textContent = value;
        summary.appendChild(chip);
    });
    wrapper.appendChild(summary);

    const list = document.createElement('div');
    list.className = 'copilot-review-fact-list';

    facts.forEach((fact) => {
        const factRow = document.createElement('article');
        factRow.className = 'copilot-review-fact';

        const header = document.createElement('div');
        header.className = 'copilot-review-fact-header';

        const title = document.createElement('strong');
        title.className = 'copilot-review-fact-title';
        title.textContent = [fact.label || 'Pending fact', fact.value || ''].filter(Boolean).join(': ');
        header.appendChild(title);

        const status = document.createElement('span');
        status.className = 'copilot-review-fact-status';
        status.dataset.status = fact.reviewStatus || 'pending_clinician_review';
        status.textContent = reviewDecisionLabel(fact.reviewStatus || 'pending_clinician_review');
        header.appendChild(status);

        factRow.appendChild(header);

        const meta = document.createElement('p');
        meta.className = 'copilot-review-fact-meta';
        const citation = fact.citation && typeof fact.citation === 'object' ? fact.citation : {};
        meta.textContent = [
            fact.proposedTarget ? `Target: ${fact.proposedTarget}` : '',
            citation.page_or_section ? `Source: ${citation.page_or_section}` : '',
            citation.quote_or_value ? `Quote/value: ${citation.quote_or_value}` : ''
        ].filter(Boolean).join(' • ');
        factRow.appendChild(meta);

        const factCitationChips = buildCitationChipsForMessage([citation], message, {
            compact: false
        });
        if (factCitationChips) {
            factRow.appendChild(factCitationChips);
        }

        const actions = document.createElement('div');
        actions.className = 'copilot-review-actions';
        const isPending = reviewSubmissionState.has(`${message.id}:${fact.id}:approved`)
            || reviewSubmissionState.has(`${message.id}:${fact.id}:rejected`)
            || reviewSubmissionState.has(`${message.id}:${fact.id}:pending`);

        [
            ['approved', 'Approve'],
            ['rejected', 'Reject'],
            ['pending', 'Leave Pending']
        ].forEach(([decision, label]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'copilot-review-button';
            button.textContent = label;
            button.disabled = isPending;
            if (reviewDecisionToStatus(decision) === (fact.reviewStatus || 'pending_clinician_review')) {
                button.dataset.active = 'true';
            }
            button.addEventListener('click', () => {
                submitClinicianReviewDecision(message, fact, decision);
            });
            actions.appendChild(button);
        });

        factRow.appendChild(actions);
        list.appendChild(factRow);
    });

    wrapper.appendChild(list);
    return wrapper;
}

function buildSchemaValidationPanel(message) {
    if (!message || message.role !== 'assistant') {
        return null;
    }

    const toolOutput = message.meta?.tool_output && typeof message.meta.tool_output === 'object'
        ? message.meta.tool_output
        : null;
    const schemaValidationRaw = toolOutput?.schemaValidation && typeof toolOutput.schemaValidation === 'object'
        ? toolOutput.schemaValidation
        : null;
    const strictExtraction = toolOutput?.strictExtraction
        && typeof toolOutput.strictExtraction === 'object'
        && !Array.isArray(toolOutput.strictExtraction)
        && Object.keys(toolOutput.strictExtraction).length > 0
        ? toolOutput.strictExtraction
        : null;
    const schemaValidation = schemaValidationRaw && Object.keys(schemaValidationRaw).some((key) => {
        const value = schemaValidationRaw[key];
        return value !== '' && value !== null && value !== false && (!Array.isArray(value) || value.length > 0);
    })
        ? schemaValidationRaw
        : buildSchemaValidationFallback(message, strictExtraction);
    if (!schemaValidation) {
        return null;
    }
    const validationErrors = Array.isArray(schemaValidation.validationErrors)
        ? schemaValidation.validationErrors.filter((item) => item && typeof item === 'object')
        : [];
    const missingData = Array.isArray(strictExtraction?.missing_or_ambiguous_data)
        ? strictExtraction.missing_or_ambiguous_data
        : (Array.isArray(toolOutput?.missingData) ? toolOutput.missingData : []);
    const extractionStatus = String(schemaValidation.extractionStatus || strictExtraction?.extraction_status || '').trim();
    const schemaValid = Boolean(schemaValidation.schemaValid ?? schemaValidation.valid);
    const badgeState = schemaValid
        ? (extractionStatus === 'review_required' ? 'review_required' : 'passed')
        : 'failed';
    const schemaStatusLabels = {
        passed: 'Strict schema passed',
        reviewRequired: 'Clinician review required',
        failed: 'Schema validation failed',
        missingCitation: 'Missing source citation',
        missingRequiredField: 'Missing required field',
        unsupportedDocumentType: 'Unsupported document type'
    };
    const badgeLabel = schemaValidation.statusLabel
        || (schemaValid
            ? (extractionStatus === 'review_required' ? schemaStatusLabels.reviewRequired : schemaStatusLabels.passed)
            : schemaStatusLabels.failed);

    const wrapper = document.createElement('section');
    wrapper.className = 'copilot-schema-panel';

    const header = document.createElement('div');
    header.className = 'copilot-schema-header';

    const title = document.createElement('h3');
    title.className = 'copilot-schema-title';
    title.textContent = 'Schema Validation';
    header.appendChild(title);

    const badge = document.createElement('span');
    badge.className = 'copilot-schema-badge';
    badge.dataset.status = badgeState;
    badge.textContent = badgeLabel;
    header.appendChild(badge);
    wrapper.appendChild(header);

    const summary = document.createElement('p');
    summary.className = 'copilot-schema-summary';
    summary.textContent = schemaValidation.userMessage
        || (schemaValid
            ? 'Structured extraction passed the strict schema gate and remains pending clinician review.'
            : 'Extraction completed, but the result did not pass strict validation. Clinician review is required before this information can be used.');
    wrapper.appendChild(summary);

    const meta = document.createElement('div');
    meta.className = 'copilot-schema-meta';
    [
        `Schema: ${schemaValidation.schemaName || 'Strict extraction schema'}`,
        `Errors: ${Number(schemaValidation.validationErrorCount || validationErrors.length || 0)}`,
        `Citations: ${Number(schemaValidation.citationCount || 0)}`,
        `Review status: ${schemaValidation.reviewStatus || 'pending_clinician_review'}`
    ].forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'copilot-schema-meta-chip';
        chip.textContent = value;
        meta.appendChild(chip);
    });
    wrapper.appendChild(meta);

    const issues = validationErrors.slice(0, 6);
    if (issues.length > 0) {
        const list = document.createElement('ul');
        list.className = 'copilot-schema-issue-list';
        issues.forEach((issue) => {
            const item = document.createElement('li');
            item.className = 'copilot-schema-issue';

            const field = document.createElement('strong');
            field.className = 'copilot-schema-issue-field';
            field.textContent = String(issue.field || 'Field');
            item.appendChild(field);

            const messageText = document.createElement('span');
            messageText.className = 'copilot-schema-issue-text';
            messageText.textContent = ` ${String(issue.issue || 'Validation issue detected.')}`;
            item.appendChild(messageText);

            if (issue.suggested_review_action) {
                const action = document.createElement('div');
                action.className = 'copilot-schema-issue-action';
                action.textContent = `Review action: ${String(issue.suggested_review_action)}`;
                item.appendChild(action);
            }

            list.appendChild(item);
        });
        wrapper.appendChild(list);
    } else if (Array.isArray(missingData) && missingData.length > 0) {
        const list = document.createElement('ul');
        list.className = 'copilot-schema-issue-list';
        missingData.slice(0, 4).forEach((entry) => {
            const item = document.createElement('li');
            item.className = 'copilot-schema-issue';
            item.textContent = typeof entry === 'string'
                ? entry
                : `${String(entry.field || 'Field')}: ${String(entry.issue || 'Requires clinician review.')}`;
            list.appendChild(item);
        });
        wrapper.appendChild(list);
    }

    return wrapper;
}

function inferPromptMode(prompt) {
    const value = prompt.toLowerCase();
    if (/(lab pdf ingestion|lab pdf|attach.*lab pdf|upload.*lab pdf|ingest.*lab pdf|extract.*lab pdf|pdf lab results|uploaded lab evidence|uploaded evidence|attached lab evidence)/.test(value)) {
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

function buildCopilotRequestPayload(prompt, requestId, resolvedRole, resolvedMode, resolvedPatientId, historyPayload, ambientVisitContext, extraPayload, documentType = '') {
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
        ...(documentType ? { doc_type: documentType } : {}),
        ...(extraPayload && typeof extraPayload === 'object' ? extraPayload : {}),
        request_id: requestId,
        csrf_token_form: copilotConfig.csrfToken
    };
}

function buildCopilotFormDataPayload(payload, options = {}) {
    const formData = new FormData();
    const hasLabPdfWorkflow = options.labPdfFile instanceof File || Boolean(options.useSeededLabPdf);
    const documentType = normalizeAttachmentDocumentType(options.documentType || payload.doc_type || 'lab_pdf');
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
    formData.append('doc_type', documentType);
    formData.append('attachment_purpose', documentType === 'intake_form' ? 'intake_form' : 'lab_pdf_ingestion');

    if (options.useSeededLabPdf) {
        formData.append('use_seeded_lab_pdf', '1');
    }

    if (options.labPdfFile instanceof File) {
        formData.append('lab_pdf_attachment', options.labPdfFile, options.labPdfFile.name || 'attached-lab-report.pdf');
    }

    return formData;
}

function buildClearLabEvidencePayload(requestId, resolvedRole, resolvedPatientId) {
    return {
        action: 'clear_lab_evidence',
        patient_id: resolvedPatientId || null,
        role: resolvedRole,
        mode: 'lab_pdf_ingestion',
        request_id: requestId,
        csrf_token_form: copilotConfig.csrfToken
    };
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

async function requestClearLabEvidence() {
    const selectedPatientKey = currentSelectedPatientKey();
    const resolvedPatientId = patientSelect ? (patientSelect.value || '') : '';
    const resolvedRole = state.activeRole;

    if (!selectedPatientKey || !resolvedPatientId) {
        setLabPdfStatus('Select a demo patient before clearing uploaded lab evidence.', 'warning');
        return;
    }

    const confirmed = window.confirm('Clear uploaded lab PDF evidence for this demo session? Marcus Johnson\'s other chart data will stay unchanged.');
    if (!confirmed) {
        return;
    }

    const requestId = createId('request');
    emitLabEvidenceClearAuditEvent('copilot_lab_evidence_clear_requested', {
        requestId,
        role: resolvedRole,
        mode: 'lab_pdf_ingestion',
        selectedPatientKey,
        documentType: 'lab_pdf',
        toolStatus: 'clear_requested'
    });

    state.loading = true;
    updateSendState();

    try {
        const response = await fetchAssistantApiResponse(
            buildClearLabEvidencePayload(requestId, resolvedRole, resolvedPatientId),
            requestId
        );

        const counts = response.counts && typeof response.counts === 'object' ? response.counts : {};
        if (normalizeAttachmentDocumentType(state.labPdf.descriptor?.documentType) === 'lab_pdf') {
            clearLabPdfAttachment({ emitTelemetry: false });
        }
        const removedClientStorageEntries = clearLabEvidenceClientStorage(selectedPatientKey);
        setLabPdfStatus('Uploaded lab evidence cleared.', 'success');

        const auditPayload = emitLabEvidenceClearAuditEvent('copilot_lab_evidence_cleared', {
            requestId,
            role: resolvedRole,
            mode: 'lab_pdf_ingestion',
            selectedPatientKey,
            documentType: 'lab_pdf',
            toolStatus: 'cleared',
            removedLabFiles: Number(counts.removedLabFiles || 0),
            removedLabChunks: Number(counts.removedLabChunks || 0),
            removedLabExtractions: Number(counts.removedLabExtractions || 0),
            remainingIntakeForms: Number(counts.remainingIntakeForms || 0)
        });
        emitLabEvidenceClearAuditEvent('rag_lab_chunks_cleared', {
            ...auditPayload,
            toolStatus: 'rag_lab_chunks_cleared'
        });
        emitLabEvidenceClearAuditEvent('lab_extraction_state_reset', {
            ...auditPayload,
            toolStatus: 'lab_extraction_state_reset'
        });

        console.info('[OpenEMR Copilot] Clear Lab Evidence summary', {
            requestId,
            patientContextPresent: Boolean(selectedPatientKey),
            patientContextHash: window.OpenEMRCopilotObservability && typeof window.OpenEMRCopilotObservability.hashIdentifier === 'function'
                ? window.OpenEMRCopilotObservability.hashIdentifier(selectedPatientKey || '')
                : null,
            removedLabFiles: Number(counts.removedLabFiles || 0),
            removedLabChunks: Number(counts.removedLabChunks || 0),
            removedLabExtractions: Number(counts.removedLabExtractions || 0),
            remainingIntakeForms: Number(counts.remainingIntakeForms || 0),
            removedClientStorageEntries
        });
    } catch (error) {
        setLabPdfStatus(error.message || 'Unable to clear uploaded lab evidence right now.', 'error');
    } finally {
        state.loading = false;
        updateSendState();
    }
}

async function requestAssistantSupervisorResponse(payload, requestContext) {
    if (requestContext.hasLabPdfAttachment || (typeof FormData !== 'undefined' && payload instanceof FormData)) {
        return null;
    }

    if ((requestContext.mode || '') === 'lab_pdf_ingestion') {
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

    restoreSessionIfAvailable();

    const resolvedRole = options.roleOverride || state.activeRole;
    const hasLabPdfAttachment = options.hasLabPdfAttachment ?? hasActiveLabPdfAttachment();
    const resolvedMode = resolveModeForPrompt(trimmedPrompt, {
        ...options,
        hasLabPdfAttachment
    });
    const resolvedPatientId = options.patientIdOverride ?? updatePatientSelectionFromPrompt(trimmedPrompt);
    const requestId = hasLabPdfAttachment && state.labPdf.requestId
        ? state.labPdf.requestId
        : createId('request');
    const selectedPatientKey = selectedPatientKeyForValue(resolvedPatientId || '');
    const contextScope = contextScopeFor(resolvedRole, resolvedPatientId);
    const ambientVisitContext = buildAmbientVisitContextForRequest(selectedPatientKey, resolvedRole);
    const extraPayload = options.extraPayload && typeof options.extraPayload === 'object' ? options.extraPayload : {};
    const labPdfAttachment = hasLabPdfAttachment ? {
        descriptor: state.labPdf.descriptor ? { ...state.labPdf.descriptor } : null,
        file: state.labPdf.file instanceof File ? state.labPdf.file : null,
        useDemoSeed: Boolean(state.labPdf.useDemoSeed),
        requestId: state.labPdf.requestId || requestId
    } : null;
    const attachmentDocumentType = hasLabPdfAttachment
        ? normalizeAttachmentDocumentType(labPdfAttachment?.descriptor?.documentType || currentSelectedDocumentType())
        : '';
    const requestedUploadedDocumentTypes = detectRequestedUploadedDocumentTypes(trimmedPrompt);
    if (hasLabPdfAttachment && labPdfAttachment?.descriptor?.documentType === 'intake_form' && !requestedUploadedDocumentTypes.includes('intake_form')) {
        requestedUploadedDocumentTypes.push('intake_form');
    }
    if (hasLabPdfAttachment && labPdfAttachment?.descriptor?.documentType === 'lab_pdf' && !requestedUploadedDocumentTypes.includes('lab_results')) {
        requestedUploadedDocumentTypes.push('lab_results');
    }
    const tracksUploadedDocumentRetrieval = resolvedMode === 'lab_pdf_ingestion'
        || requestedUploadedDocumentTypes.length > 0
        || /\b(uploaded|attached) (lab|labs|pdf|intake|document)/i.test(trimmedPrompt);
    const tracksAttachmentReviewLlm = tracksUploadedDocumentRetrieval || hasLabPdfAttachment;
    const startedAt = new Date().toISOString();
    const startedPerf = window.performance && typeof window.performance.now === 'function'
        ? window.performance.now()
        : Date.now();
    const historyPayload = buildHistoryPayload();

    if (hasLabPdfAttachment && !resolvedPatientId) {
        addAssistantMessage(`Select a demo patient before ingesting a ${attachmentWorkflowLabel(attachmentDocumentType)} so the extracted chunks can be grounded to the correct chart context.`, {
            mode: resolvedMode,
            staffRole: resolvedRole,
            patientId: '',
            selectedPatientKey: null,
            safety: 'Draft only. Human review required.',
            traceId: requestId,
            requestId,
            requestPrompt: trimmedPrompt
        });

        emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_failed', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: state.labPdf.descriptor?.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'patient_required',
            seededDemo: Boolean(state.labPdf.useDemoSeed),
            ragGrounded: false
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
            const attachmentDocumentType = normalizeAttachmentDocumentType(labPdfAttachment?.descriptor?.documentType);
            emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_failed', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: labPdfAttachment?.descriptor?.fileName || null,
                documentType: attachmentDocumentType,
                toolStatus: preflightGuardrails.blockedReason || 'role_blocked',
                seededDemo: Boolean(labPdfAttachment?.useDemoSeed),
                ragGrounded: false
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

    if (hasLabPdfAttachment && labPdfAttachment?.descriptor) {
        if (CopilotTelemetry) {
            CopilotTelemetry.log('document_upload_started', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentType: attachmentDocumentType,
                documentTitle: labPdfAttachment.descriptor.fileName || null,
                toolStatus: 'document_upload_started'
            });
            CopilotTelemetry.log('extraction_started', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentType: attachmentDocumentType,
                documentTitle: labPdfAttachment.descriptor.fileName || null,
                toolStatus: 'extraction_started'
            });
        }
        emitLabPdfAuditEvent('copilot_upload_received', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: labPdfAttachment.descriptor.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'upload_received',
            seededDemo: Boolean(labPdfAttachment.useDemoSeed),
            ragGrounded: false
        });
        emitLabPdfAuditEvent('copilot_pdf_upload_received', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: labPdfAttachment.descriptor.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'pdf_upload_received',
            seededDemo: Boolean(labPdfAttachment.useDemoSeed),
            ragGrounded: false
        });
        emitLabPdfAuditEvent('copilot_document_guard_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: labPdfAttachment.descriptor.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'guard_started',
            seededDemo: Boolean(labPdfAttachment.useDemoSeed),
            ragGrounded: false
        });
        emitLabPdfAuditEvent('copilot_medical_guard_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: labPdfAttachment.descriptor.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'medical_guard_started',
            seededDemo: Boolean(labPdfAttachment.useDemoSeed),
            ragGrounded: false
        });
        emitLabPdfAuditEvent('copilot_pdf_text_extraction_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: labPdfAttachment.descriptor.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'text_extraction_started',
            seededDemo: Boolean(labPdfAttachment.useDemoSeed),
            ragGrounded: false
        });
        emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            documentTitle: labPdfAttachment.descriptor.fileName || null,
            documentType: attachmentDocumentType,
            toolStatus: 'ingestion_started',
            seededDemo: Boolean(labPdfAttachment.useDemoSeed),
            ragGrounded: false
        });
        if (attachmentDocumentType === 'intake_form') {
            emitLabPdfAuditEvent('intake_extraction_started', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: labPdfAttachment.descriptor.fileName || null,
                documentType: attachmentDocumentType,
                toolStatus: 'intake_extraction_started',
                seededDemo: Boolean(labPdfAttachment.useDemoSeed),
                ragGrounded: false
            });
        }
    }

    if (tracksUploadedDocumentRetrieval) {
        logUploadedDocumentRetrievalEvent('copilot_document_retrieval_started', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            requestedDocumentTypes: requestedUploadedDocumentTypes,
            sourceCount: 0
        });
        logUploadedDocumentRetrievalEvent('copilot_requested_document_types_detected', {
            requestId,
            role: resolvedRole,
            mode: resolvedMode,
            selectedPatientKey,
            requestedDocumentTypes: requestedUploadedDocumentTypes,
            sourceCount: 0
        });
    }

    if (CopilotTelemetry) {
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
            extraPayload,
            attachmentDocumentType
        );
        const requestPayload = hasLabPdfAttachment
            ? buildCopilotFormDataPayload(requestPayloadObject, {
                labPdfFile: labPdfAttachment?.file || null,
                useSeededLabPdf: Boolean(labPdfAttachment?.useDemoSeed),
                documentType: attachmentDocumentType
            })
            : requestPayloadObject;
        const attachmentReviewDocumentType = hasLabPdfAttachment
            ? normalizeAttachmentDocumentType(labPdfAttachment?.descriptor?.documentType)
            : (requestedUploadedDocumentTypes.includes('intake_form')
                ? 'intake_form'
                : (requestedUploadedDocumentTypes.includes('lab_results') ? 'lab_pdf' : null));
        const attachmentReviewDocumentTitle = labPdfAttachment?.descriptor?.fileName || null;

        if (tracksAttachmentReviewLlm) {
            emitAttachmentReviewLlmAuditEvent('copilot_llm_provider_check_started', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: attachmentReviewDocumentTitle,
                documentType: attachmentReviewDocumentType,
                toolStatus: 'provider_check_started',
                sourceCount: 0,
                ragGrounded: false
            });
            emitAttachmentReviewLlmAuditEvent('copilot_attachment_review_llm_prompt_built', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: attachmentReviewDocumentTitle,
                documentType: attachmentReviewDocumentType,
                toolStatus: 'prompt_built',
                sourceCount: 0,
                ragGrounded: false
            });
            emitAttachmentReviewLlmAuditEvent('copilot_attachment_review_llm_call_started', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: attachmentReviewDocumentTitle,
                documentType: attachmentReviewDocumentType,
                toolStatus: 'llm_call_started',
                sourceCount: 0,
                ragGrounded: false
            });
        }

        if (hasLabPdfAttachment) {
            const attachmentDocumentType = normalizeAttachmentDocumentType(labPdfAttachment?.descriptor?.documentType);
            clearLabPdfAttachment({
                emitTelemetry: false,
                keepStatusMessage: `Uploading ${labPdfAttachment?.descriptor?.fileName || `the attached ${attachmentWorkflowLabel(attachmentDocumentType)}`} for draft-only clinician review...`,
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
        const meta = data.meta || {};
        if (data.tool_output && typeof data.tool_output === 'object') {
            const toolOutput = data.tool_output;
            const toolDocumentType = normalizeAttachmentDocumentType(toolOutput.sourceMetadata?.sourceType || toolOutput.documentMetadata?.documentType);
            const matchedSourceCount = matchedUploadedSourceTitles(toolOutput).length;
            const documentGuard = toolOutput.documentGuard && typeof toolOutput.documentGuard === 'object'
                ? toolOutput.documentGuard
                : {};
            const documentGuardDecision = String(documentGuard.decision || '');
            const documentGuardMessage = String(documentGuard.rejectionReason || toolOutput.safeMessage || '').trim();
            if (toolOutput.status === 'ocr_required') {
                setLabPdfStatus(toolOutput.safeMessage || 'The attached PDF needs OCR or manual verification before relying on extracted lab facts.', 'warning');
                setUploadNotice(toolOutput.safeMessage || 'The attached PDF needs OCR or manual verification before relying on extracted facts.', 'warning', {
                    timeoutMs: 5600
                });
            } else if (toolOutput.status === 'document_guard_rejected') {
                setLabPdfStatus(toolOutput.safeMessage || 'This does not appear to be a medical document. Please upload a lab result, intake form, discharge summary, medication list, insurance/claim document, or clinical note.', 'error');
                setUploadNotice(documentGuardMessage || 'This does not appear to be a medical document. Please upload a supported medical PDF.', 'error', {
                    timeoutMs: 6200
                });
            } else if (toolOutput.status === 'document_guard_review_required') {
                setLabPdfStatus(toolOutput.safeMessage || 'Document type could not be verified. Review required before ingestion.', 'warning');
                setUploadNotice(documentGuardMessage || 'Document type could not be verified. Review required before ingestion.', 'warning', {
                    timeoutMs: 6200
                });
            } else if (toolOutput.status === 'extraction_review_required') {
                setLabPdfStatus(toolOutput.safeMessage || (toolDocumentType === 'intake_form'
                    ? 'Intake form extraction did not produce reliable intake fields. Clinician must verify the source PDF.'
                    : 'PDF text extraction did not produce reliable lab rows. Clinician must verify the source PDF.'), 'warning');
                setUploadNotice(toolOutput.safeMessage || 'Extraction review is required before relying on the uploaded PDF.', 'warning', {
                    timeoutMs: 5600
                });
            } else if (toolOutput.status === 'unsupported_doc_type') {
                setLabPdfStatus(toolOutput.safeMessage || 'Only lab PDFs and intake forms are supported in this MVP.', 'error');
                setUploadNotice(toolOutput.safeMessage || 'Only lab PDFs and intake forms are supported in this MVP.', 'error', {
                    timeoutMs: 5600
                });
            } else if (toolOutput.status === 'invalid_file_type' || toolOutput.status === 'role_blocked') {
                setLabPdfStatus(toolOutput.safeMessage || `The ${attachmentWorkflowLabel(toolDocumentType)} workflow was blocked for this request.`, 'error');
                setUploadNotice(toolOutput.safeMessage || `The ${attachmentWorkflowLabel(toolDocumentType)} workflow was blocked for this request.`, 'error', {
                    timeoutMs: 5600
                });
            } else if (matchedSourceCount > 1 || toolOutput.sourceMetadata?.sourceType === 'multi_document') {
                setLabPdfStatus(toolOutput.safeMessage || 'Uploaded lab and intake document context retrieved for clinician review.', 'success');
                if (documentGuardDecision === 'allowed') {
                    setUploadNotice('Medical document detected. Ready for ingestion.', 'success', {
                        timeoutMs: 4200
                    });
                }
            } else if (toolDocumentType === 'intake_form' || toolDocumentType === 'lab_pdf' || (data.mode || resolvedMode) === 'lab_pdf_ingestion') {
                setLabPdfStatus(toolOutput.safeMessage || `${toolDocumentType === 'intake_form' ? 'Intake form' : 'Lab PDF'} context ingested and retrieved for clinician review.`, 'success');
                if (documentGuardDecision === 'allowed') {
                    setUploadNotice('Medical document detected. Ready for ingestion.', 'success', {
                        timeoutMs: 4200
                    });
                }
            }

            logLabPdfDataToConsole(trimmedPrompt, toolOutput, {
                requestId,
                role: data.role || resolvedRole,
                mode: data.mode || resolvedMode,
                selectedPatientKey,
                ragGrounded: Boolean(meta.rag_grounded)
            });
        }
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
        const attachmentEvidenceMeta = buildAttachmentEvidenceMeta(data.tool_output);
        const matchedSourceTitles = data.tool_output && typeof data.tool_output === 'object'
            ? matchedUploadedSourceTitles(data.tool_output)
            : [];
        if (tracksAttachmentReviewLlm) {
            const helper = labPdfIngestionHelper();
            const llmDocumentTitle = matchedSourceTitles.length === 1
                ? matchedSourceTitles[0]
                : (labPdfAttachment?.descriptor?.fileName || null);
            const llmDocumentType = labPdfAttachment?.descriptor?.documentType === 'intake_form'
                ? 'intake_form'
                : ((requestedUploadedDocumentTypes.includes('intake_form') && !requestedUploadedDocumentTypes.includes('lab_results'))
                    ? 'intake_form'
                    : 'lab_pdf');
            const llmAuditPayload = helper && typeof helper.buildLabPdfSafeTelemetryPayload === 'function'
                ? helper.buildLabPdfSafeTelemetryPayload({
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    meta,
                    tool_output: data.tool_output || {}
                }, {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentTitle: llmDocumentTitle,
                    documentType: llmDocumentType,
                    ragGrounded: Boolean(meta.rag_grounded)
                })
                : {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentTitle: llmDocumentTitle,
                    documentType: llmDocumentType,
                    toolStatus: meta.engine || 'fallback',
                    ragGrounded: Boolean(meta.rag_grounded)
                };

            if (meta.openai_configured) {
                emitAttachmentReviewLlmAuditEvent('copilot_llm_provider_available', {
                    ...llmAuditPayload,
                    toolStatus: 'provider_available',
                    sourceCount: sourcePayload.sources.length,
                    provider: meta.provider || 'openai',
                    model: meta.model || null,
                    openaiConfigured: true
                });
            }
            if (meta.engine === 'openai' && !meta.fallback_used) {
                emitAttachmentReviewLlmAuditEvent('copilot_attachment_review_llm_call_succeeded', {
                    ...llmAuditPayload,
                    toolStatus: 'llm_call_succeeded',
                    sourceCount: sourcePayload.sources.length,
                    provider: meta.provider || 'openai',
                    model: meta.model || null,
                    openaiConfigured: Boolean(meta.openai_configured),
                    promptTokens: meta.token_usage?.prompt_tokens ?? null,
                    completionTokens: meta.token_usage?.completion_tokens ?? null,
                    totalTokens: meta.token_usage?.total_tokens ?? null,
                    ragGrounded: Boolean(meta.rag_grounded)
                });
            } else if (meta.fallback_used && (meta.fallback_reason === 'openai_error' || meta.openai_error_category)) {
                emitAttachmentReviewLlmAuditEvent('copilot_attachment_review_llm_call_failed', {
                    ...llmAuditPayload,
                    toolStatus: 'llm_call_failed',
                    sourceCount: sourcePayload.sources.length,
                    provider: meta.provider || 'local_fallback',
                    model: meta.model || null,
                    openaiConfigured: Boolean(meta.openai_configured),
                    fallbackReason: meta.fallback_reason || null,
                    openaiErrorCategory: meta.openai_error_category || null,
                    openaiHttpStatus: meta.openai_http_status ?? null,
                    openaiErrorMessageSafe: meta.openai_error_message_safe || null,
                    ragGrounded: Boolean(meta.rag_grounded)
                });
            }
            if (meta.fallback_used) {
                emitAttachmentReviewLlmAuditEvent('copilot_attachment_review_fallback_used', {
                    ...llmAuditPayload,
                    toolStatus: 'fallback_used',
                    sourceCount: sourcePayload.sources.length,
                    provider: meta.provider || 'local_fallback',
                    model: meta.model || null,
                    openaiConfigured: Boolean(meta.openai_configured),
                    fallbackReason: meta.fallback_reason || 'demo_mode',
                    openaiErrorCategory: meta.openai_error_category || null,
                    openaiHttpStatus: meta.openai_http_status ?? null,
                    openaiErrorMessageSafe: meta.openai_error_message_safe || null,
                    ragGrounded: Boolean(meta.rag_grounded)
                });
            }
        }
        if (tracksUploadedDocumentRetrieval && matchedSourceTitles.length > 0) {
            logUploadedDocumentRetrievalEvent('copilot_uploaded_sources_matched', {
                requestId,
                role: data.role || resolvedRole,
                mode: data.mode || resolvedMode,
                selectedPatientKey,
                requestedDocumentTypes: requestedUploadedDocumentTypes,
                matchedSourceTitles,
                sourceCount: matchedSourceTitles.length
            });
        }
        const assistantMessage = addAssistantMessage(guardrailsResult.finalResponse || data.answer || copilotConfig.apiFailureMessage, {
            mode: data.mode || resolvedMode,
            staffRole: data.role || resolvedRole,
            patientId: resolvedPatientId,
            selectedPatientKey,
            sections: guardrailsResult.finalSections || [],
            tags: guardrailTags,
            sources: sourcePayload.sources,
            claims: Array.isArray(data.claims) ? data.claims : [],
            sourcesUsed: Array.isArray(data.sources_used) ? data.sources_used : [],
            uncitedClaimsBlocked: Array.isArray(data.uncited_claims_blocked) ? data.uncited_claims_blocked : [],
            evidenceSnippets: Array.isArray(data.evidence_snippets) ? data.evidence_snippets : [],
            safetyStatus: String(data.safety_status || ''),
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
                latest_ambient_visit_found: sourcePayload.latestAmbientVisitFound,
                attachment_evidence: attachmentEvidenceMeta,
                tool_output: data.tool_output || {}
            }
        });
        emitBasicHybridRagAuditEvents(meta, {
            requestId,
            role: data.role || resolvedRole,
            mode: data.mode || resolvedMode,
            selectedPatientKey
        });
        if (CopilotTelemetry && (Array.isArray(data.claims) || meta.citation_contract_status)) {
            CopilotTelemetry.log('citation_contract_validated', {
                requestId,
                role: data.role || resolvedRole,
                mode: data.mode || resolvedMode,
                selectedPatientKey,
                claimCount: Number(meta.claim_count ?? (Array.isArray(data.claims) ? data.claims.length : 0)),
                citedClaimCount: Number(meta.cited_claim_count ?? (Array.isArray(data.claims) ? data.claims.length : 0)),
                uncitedClaimCount: Number(meta.uncited_claim_count ?? (Array.isArray(data.uncited_claims_blocked) ? data.uncited_claims_blocked.length : 0)),
                invalidCitationCount: Number(meta.invalid_citation_count ?? 0),
                blockedClaimCount: Number(meta.blocked_claim_count ?? (Array.isArray(data.uncited_claims_blocked) ? data.uncited_claims_blocked.length : 0)),
                sourceCount: Number(meta.source_count ?? (Array.isArray(data.sources_used) ? data.sources_used.length : sourcePayload.sources.length)),
                citationContractStatus: String(meta.citation_contract_status || data.safety_status || '')
            });
        }
        if (tracksUploadedDocumentRetrieval) {
            logUploadedDocumentRetrievalEvent('copilot_sources_used_finalized', {
                requestId,
                role: data.role || resolvedRole,
                mode: data.mode || resolvedMode,
                selectedPatientKey,
                requestedDocumentTypes: requestedUploadedDocumentTypes,
                matchedSourceTitles: sourcePayload.sourceTitles,
                sourceCount: sourcePayload.sourceTitles.length
            });
        }

        if (data.tool_output && typeof data.tool_output === 'object' && (((data.mode || resolvedMode) === 'lab_pdf_ingestion') || ['lab_pdf', 'intake_form'].includes(data.tool_output.sourceMetadata?.sourceType || ''))) {
            const toolOutput = data.tool_output;
            const helper = labPdfIngestionHelper();
            const promptInjectionDetected = Boolean(toolOutput.safetyMetadata?.promptInjectionDetected);
            const toolDocumentType = normalizeAttachmentDocumentType(toolOutput.sourceMetadata?.sourceType || toolOutput.documentMetadata?.documentType);
            const documentGuard = toolOutput.documentGuard && typeof toolOutput.documentGuard === 'object'
                ? toolOutput.documentGuard
                : {};
            const labPdfTelemetryPayload = helper && typeof helper.buildLabPdfSafeTelemetryPayload === 'function'
                ? helper.buildLabPdfSafeTelemetryPayload({
                    tool_output: toolOutput,
                    meta,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode
                }, {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    ragGrounded: Boolean(meta.rag_grounded)
                })
                : {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentTitle: toolOutput.sourceMetadata?.fileName || toolOutput.documentMetadata?.title || null,
                    documentType: toolDocumentType,
                    extractionMethod: toolOutput.extractionMethod || null,
                    toolStatus: String(toolOutput.status || ''),
                    chunkCount: Number.isFinite(toolOutput.sourceMetadata?.chunkCount) ? toolOutput.sourceMetadata.chunkCount : 0,
                    retrievedChunkCount: Number.isFinite(toolOutput.retrieval?.chunkCount) ? toolOutput.retrieval.chunkCount : 0,
                    missingDataCount: Array.isArray(toolOutput.missingData) ? toolOutput.missingData.length : 0,
                    guardrailTriggered: promptInjectionDetected,
                    seededDemo: Boolean(toolOutput.documentMetadata?.seededDemo),
                    ragGrounded: Boolean(meta.rag_grounded)
                };
            const toolStatus = String(labPdfTelemetryPayload.toolStatus || '');
            const documentGuardPayload = {
                ...labPdfTelemetryPayload,
                documentGuardProvider: documentGuard.guardProvider || null,
                medicalEntityCount: Number.isFinite(documentGuard.medicalEntityCount) ? documentGuard.medicalEntityCount : 0,
                confidence: Number.isFinite(documentGuard.confidence) ? documentGuard.confidence : 0,
                rejectionReason: documentGuard.rejectionReason || null,
                documentGuardDecision: documentGuard.decision || null,
                awsGuardEnabled: Boolean(documentGuard.awsGuardEnabled),
                textExtractionStatus: documentGuard.textExtractionStatus || toolOutput.textExtractionStatus || null,
                medicalValidationStatus: documentGuard.medicalValidationStatus || toolOutput.medicalValidationStatus || null,
                chartWriteStatus: documentGuard.chartWriteStatus || toolOutput.chartWriteStatus || null,
                syntheticDemoData: Boolean(documentGuard.isSyntheticDemoData),
                reviewRequired: !Object.prototype.hasOwnProperty.call(documentGuard, 'reviewRequired') || Boolean(documentGuard.reviewRequired),
                labEvidenceScore: Number.isFinite(documentGuard.labEvidenceScore) ? documentGuard.labEvidenceScore : 0,
                seededDemo: Boolean(labPdfTelemetryPayload.seededDemo || documentGuard.isSyntheticDemoData)
            };
            const textractStatus = String(documentGuard.textractStatus || '');
            const comprehendStatus = String(documentGuard.comprehendStatus || '');
            const documentGuardDecision = String(documentGuard.decision || '');
            const textExtractionStatus = String(documentGuard.textExtractionStatus || toolOutput.textExtractionStatus || '');
            const documentTypeDetected = String(documentGuard.documentType || toolOutput.documentMetadata?.documentType || '');
            const rawBytesDetected = Boolean(toolOutput.rawBytesDetected);
            const extractedFacts = Array.isArray(toolOutput.extractedFacts) ? toolOutput.extractedFacts : [];
            const sourceId = toolOutput.sourceMetadata?.sourceId || toolOutput.documentMetadata?.sourceId || null;
            const hasGuardLifecycle = Boolean(documentGuardDecision || textractStatus || comprehendStatus);
            const reviewQueue = toolOutput.reviewQueue && typeof toolOutput.reviewQueue === 'object'
                ? toolOutput.reviewQueue
                : {};
            const sourceDocumentId = toolOutput.documentMetadata?.sourceDocumentId || toolOutput.sourceMetadata?.sourceDocumentId || null;

            if (CopilotTelemetry) {
                CopilotTelemetry.log('document_uploaded', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentType: toolDocumentType,
                    documentTitle: toolOutput.documentMetadata?.title || toolOutput.sourceMetadata?.fileName || null,
                    toolStatus: toolStatus || 'document_uploaded',
                    reviewStatus: toolOutput.documentMetadata?.reviewStatus || reviewQueue.status || 'pending_clinician_review'
                });
                if (sourceDocumentId) {
                    CopilotTelemetry.log('source_document_stored', {
                        requestId,
                        role: data.role || resolvedRole,
                        mode: data.mode || resolvedMode,
                        selectedPatientKey,
                        documentType: toolDocumentType,
                        documentTitle: toolOutput.documentMetadata?.title || toolOutput.sourceMetadata?.fileName || null,
                        toolStatus: 'source_document_stored',
                        reviewStatus: toolOutput.documentMetadata?.reviewStatus || reviewQueue.status || 'pending_clinician_review'
                    });
                }
                CopilotTelemetry.log('rag_index_started', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentType: toolDocumentType,
                    toolStatus: 'rag_index_started',
                    reviewStatus: toolOutput.documentMetadata?.reviewStatus || reviewQueue.status || 'pending_clinician_review'
                });
                CopilotTelemetry.log('rag_index_completed', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentType: toolDocumentType,
                    toolStatus: 'rag_index_completed',
                    reviewStatus: toolOutput.documentMetadata?.reviewStatus || reviewQueue.status || 'pending_clinician_review'
                });
                CopilotTelemetry.log('evidence_safety_check_started', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentType: toolDocumentType,
                    toolStatus: 'evidence_safety_check_started'
                });
                CopilotTelemetry.log('evidence_safety_check_completed', {
                    requestId,
                    role: data.role || resolvedRole,
                    mode: data.mode || resolvedMode,
                    selectedPatientKey,
                    documentType: toolDocumentType,
                    toolStatus: 'evidence_safety_check_completed',
                    reviewStatus: toolOutput.documentMetadata?.reviewStatus || reviewQueue.status || 'pending_clinician_review'
                });
                if (toolOutput.schemaValidation && typeof toolOutput.schemaValidation === 'object') {
                    CopilotTelemetry.log('extraction_schema_validated', {
                        requestId,
                        role: data.role || resolvedRole,
                        mode: data.mode || resolvedMode,
                        selectedPatientKey,
                        documentType: toolDocumentType,
                        toolStatus: toolOutput.schemaValidation.valid ? 'schema_valid' : 'schema_invalid',
                        schemaName: toolOutput.schemaValidation.schemaName || '',
                        schemaValid: Boolean(toolOutput.schemaValidation.schemaValid ?? toolOutput.schemaValidation.valid),
                        validationErrorCount: Number(toolOutput.schemaValidation.validationErrorCount || (toolOutput.schemaValidation.validationErrors || []).length || 0),
                        missingRequiredFieldCount: Number(toolOutput.schemaValidation.missingRequiredFieldCount || 0),
                        citationCount: Number(toolOutput.schemaValidation.citationCount || 0),
                        extractionStatus: toolOutput.schemaValidation.extractionStatus || '',
                        reviewStatus: toolOutput.schemaValidation.reviewStatus || 'pending_clinician_review'
                    });
                }
                if (Array.isArray(toolOutput.pendingReviewFacts) && toolOutput.pendingReviewFacts.length > 0) {
                    CopilotTelemetry.log('facts_persisted_pending_review', {
                        requestId,
                        role: data.role || resolvedRole,
                        mode: data.mode || resolvedMode,
                        selectedPatientKey,
                        documentType: toolDocumentType,
                        toolStatus: 'facts_persisted_pending_review',
                        reviewStatus: reviewQueue.status || 'pending_clinician_review',
                        approvedItemCount: reviewQueue.summary?.approvedCount || 0
                    });
                }
                if (toolStatus === 'review_required' || toolStatus === 'extraction_review_required') {
                    CopilotTelemetry.log('extraction_review_required', {
                        requestId,
                        role: data.role || resolvedRole,
                        mode: data.mode || resolvedMode,
                        selectedPatientKey,
                        documentType: toolDocumentType,
                        toolStatus
                    });
                }
                if (toolStatus === 'failed') {
                    CopilotTelemetry.log('extraction_failed', {
                        requestId,
                        role: data.role || resolvedRole,
                        mode: data.mode || resolvedMode,
                        selectedPatientKey,
                        documentType: toolDocumentType,
                        toolStatus
                    });
                }
                if (toolStatus === 'unsupported_doc_type' || toolStatus === 'invalid_file_type') {
                    CopilotTelemetry.log('unsupported_doc_type_blocked', {
                        requestId,
                        role: data.role || resolvedRole,
                        mode: data.mode || resolvedMode,
                        selectedPatientKey,
                        documentType: toolDocumentType,
                        toolStatus
                    });
                }
            }

            if (hasGuardLifecycle) {
                emitLabPdfAuditEvent('copilot_document_guard_started', {
                    ...documentGuardPayload,
                    toolStatus: 'guard_started'
                });
                emitLabPdfAuditEvent('copilot_medical_guard_started', {
                    ...documentGuardPayload,
                    toolStatus: 'medical_guard_started'
                });
                if (textractStatus && textractStatus !== 'not_run') {
                    emitLabPdfAuditEvent('copilot_textract_started', {
                        ...documentGuardPayload,
                        toolStatus: 'textract_started'
                    });
                    emitLabPdfAuditEvent(
                        textractStatus === 'succeeded' ? 'copilot_textract_succeeded' : 'copilot_textract_failed',
                        {
                            ...documentGuardPayload,
                            toolStatus: textractStatus
                        }
                    );
                }
                if (comprehendStatus && comprehendStatus !== 'not_run') {
                    emitLabPdfAuditEvent('copilot_comprehend_medical_started', {
                        ...documentGuardPayload,
                        toolStatus: 'comprehend_medical_started'
                    });
                    if (comprehendStatus === 'succeeded') {
                        emitLabPdfAuditEvent('copilot_comprehend_medical_succeeded', {
                            ...documentGuardPayload,
                            toolStatus: 'comprehend_medical_succeeded'
                        });
                    }
                }
                if (documentGuardDecision === 'allowed') {
                    emitLabPdfAuditEvent('copilot_document_guard_allowed', {
                        ...documentGuardPayload,
                        toolStatus: 'guard_allowed'
                    });
                    emitLabPdfAuditEvent('copilot_medical_guard_allowed', {
                        ...documentGuardPayload,
                        toolStatus: 'medical_guard_allowed'
                    });
                } else if (documentGuardDecision === 'rejected') {
                    emitLabPdfAuditEvent('copilot_document_guard_rejected', {
                        ...documentGuardPayload,
                        toolStatus: 'guard_rejected'
                    });
                    emitLabPdfAuditEvent('copilot_vectorization_blocked', {
                        ...documentGuardPayload,
                        toolStatus: 'vectorization_blocked'
                    });
                } else if (documentGuardDecision === 'review_required') {
                    emitLabPdfAuditEvent('copilot_document_guard_review_required', {
                        ...documentGuardPayload,
                        toolStatus: 'guard_review_required'
                    });
                    emitLabPdfAuditEvent('copilot_vectorization_blocked', {
                        ...documentGuardPayload,
                        toolStatus: 'vectorization_blocked'
                    });
                }

                if (textExtractionStatus === 'success') {
                    emitLabPdfAuditEvent('copilot_pdf_text_extraction_succeeded', {
                        ...documentGuardPayload,
                        toolStatus: 'text_extraction_succeeded'
                    });
                } else {
                    emitLabPdfAuditEvent('copilot_pdf_text_extraction_failed', {
                        ...documentGuardPayload,
                        toolStatus: textExtractionStatus || 'text_extraction_failed'
                    });
                }
                if (rawBytesDetected) {
                    emitLabPdfAuditEvent('copilot_pdf_raw_bytes_detected', {
                        ...documentGuardPayload,
                        toolStatus: 'raw_pdf_bytes_detected',
                        chunkCount: documentGuardPayload.chunkCount,
                        retrievedChunkCount: documentGuardPayload.retrievedChunkCount
                    });
                }
                if (textractStatus === 'started' || textractStatus === 'succeeded' || toolOutput.status === 'ocr_required') {
                    emitLabPdfAuditEvent('copilot_pdf_ocr_or_textract_fallback_started', {
                        ...documentGuardPayload,
                        toolStatus: textractStatus || 'ocr_or_textract_fallback_started'
                    });
                }
                if (documentGuardPayload.syntheticDemoData) {
                    emitLabPdfAuditEvent('copilot_medical_guard_detected_synthetic_demo_label', {
                        ...documentGuardPayload,
                        toolStatus: 'synthetic_demo_detected'
                    });
                }
                emitLabPdfAuditEvent('copilot_medical_guard_lab_evidence_score', {
                    ...documentGuardPayload,
                    toolStatus: 'lab_evidence_scored'
                });
                if (documentTypeDetected) {
                    emitLabPdfAuditEvent('copilot_medical_guard_document_type_detected', {
                        ...documentGuardPayload,
                        toolStatus: documentTypeDetected
                    });
                }
                if (documentGuardDecision === 'allowed' && documentGuardPayload.syntheticDemoData) {
                    emitLabPdfAuditEvent('copilot_medical_guard_allowed_for_demo_ingestion', {
                        ...documentGuardPayload,
                        toolStatus: 'allowed_for_demo_ingestion'
                    });
                }
                if (documentGuardPayload.reviewRequired) {
                    emitLabPdfAuditEvent('copilot_clinician_review_required', {
                        ...documentGuardPayload,
                        toolStatus: 'clinician_review_required'
                    });
                }
            }

            if (!hasGuardLifecycle && textExtractionStatus !== 'success') {
                emitLabPdfAuditEvent('copilot_pdf_text_extraction_failed', {
                    ...documentGuardPayload,
                    toolStatus: textExtractionStatus || 'text_extraction_failed'
                });
            }
            if (!hasGuardLifecycle && rawBytesDetected) {
                emitLabPdfAuditEvent('copilot_pdf_raw_bytes_detected', {
                    ...documentGuardPayload,
                    toolStatus: 'raw_pdf_bytes_detected'
                });
            }
            if (!hasGuardLifecycle && (toolOutput.extractionMethod === 'textract' || toolOutput.status === 'ocr_required')) {
                emitLabPdfAuditEvent('copilot_pdf_ocr_or_textract_fallback_started', {
                    ...documentGuardPayload,
                    toolStatus: toolOutput.extractionMethod || 'ocr_or_textract_fallback_started'
                });
            }

            if (toolStatus === 'unsupported_doc_type' || toolStatus === 'invalid_file_type' || toolStatus === 'role_blocked' || toolStatus === 'document_guard_rejected' || toolStatus === 'document_guard_review_required') {
                emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_failed', labPdfTelemetryPayload);
            } else {
                emitLabPdfAuditEvent('ingestion_document_classified', {
                    ...labPdfTelemetryPayload,
                    toolStatus: toolDocumentType
                });
                emitLabPdfAuditEvent('copilot_lab_pdf_text_extracted', {
                    ...labPdfTelemetryPayload,
                    toolStatus: toolStatus || 'text_extracted'
                });
                emitLabPdfAuditEvent('copilot_lab_pdf_chunked', {
                    ...labPdfTelemetryPayload,
                    toolStatus: 'chunked'
                });
                emitLabPdfAuditEvent('copilot_vectorization_started', {
                    ...documentGuardPayload,
                    toolStatus: 'vectorization_started'
                });
                emitLabPdfAuditEvent('copilot_lab_pdf_vectorized', {
                    ...labPdfTelemetryPayload,
                    toolStatus: 'vectorized'
                });
                emitLabPdfAuditEvent('copilot_uploaded_document_vectorization_started', {
                    ...documentGuardPayload,
                    toolStatus: 'uploaded_document_vectorization_started',
                    chunkCount: documentGuardPayload.chunkCount
                });
                emitLabPdfAuditEvent('copilot_vectorization_succeeded', {
                    ...documentGuardPayload,
                    toolStatus: 'vectorization_succeeded'
                });
                emitLabPdfAuditEvent('copilot_uploaded_document_vectorization_succeeded', {
                    ...documentGuardPayload,
                    toolStatus: 'uploaded_document_vectorization_succeeded',
                    chunkCount: documentGuardPayload.chunkCount
                });
                if (sourceId) {
                    emitLabPdfAuditEvent('copilot_uploaded_document_source_registered', {
                        ...labPdfTelemetryPayload,
                        toolStatus: 'source_registered'
                    });
                }
                emitLabPdfAuditEvent('copilot_lab_pdf_retrieval_started', {
                    ...labPdfTelemetryPayload,
                    toolStatus: 'retrieval_started'
                });
                emitLabPdfAuditEvent('copilot_lab_pdf_retrieval_completed', {
                    ...labPdfTelemetryPayload,
                    toolStatus: 'retrieval_completed'
                });
                emitLabPdfAuditEvent('rag_context_retrieved', {
                    ...labPdfTelemetryPayload,
                    toolStatus: 'retrieval_completed'
                });
                emitLabPdfAuditEvent('copilot_lab_pdf_review_required', {
                    ...labPdfTelemetryPayload,
                    toolStatus: toolStatus === '' ? 'review_required' : toolStatus
                });
                if (toolDocumentType === 'lab_pdf' && extractedFacts.length > 0) {
                    emitLabPdfAuditEvent('copilot_lab_values_extracted', {
                        ...labPdfTelemetryPayload,
                        toolStatus: 'lab_values_extracted',
                        chunkCount: documentGuardPayload.chunkCount,
                        retrievedChunkCount: documentGuardPayload.retrievedChunkCount
                    });
                }
                if (toolDocumentType === 'intake_form') {
                    emitLabPdfAuditEvent('intake_extraction_completed', {
                        ...labPdfTelemetryPayload,
                        toolStatus: toolStatus === '' ? 'intake_extraction_completed' : toolStatus
                    });
                }
                if (promptInjectionDetected) {
                    emitLabPdfAuditEvent('copilot_lab_pdf_guardrail_triggered', {
                        ...labPdfTelemetryPayload,
                        toolStatus: 'prompt_injection_detected',
                        guardrailTriggered: true
                    });
                }
            }
        }

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
        }
    } catch (error) {
        clearLoadingMessage();
        if (labPdfAttachment?.descriptor) {
            applyLabPdfAttachmentDescriptor(labPdfAttachment.descriptor, {
                file: labPdfAttachment.file,
                useDemoSeed: labPdfAttachment.useDemoSeed,
                requestId: labPdfAttachment.requestId || requestId
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

        if (labPdfAttachment?.descriptor) {
            const attachmentDocumentType = normalizeAttachmentDocumentType(labPdfAttachment.descriptor.documentType);
            if (CopilotTelemetry) {
                CopilotTelemetry.log('document_upload_failed', {
                    requestId,
                    role: resolvedRole,
                    mode: resolvedMode,
                    selectedPatientKey,
                    documentType: attachmentDocumentType,
                    documentTitle: labPdfAttachment.descriptor.fileName || null,
                    toolStatus: error.errorCategory || 'request_failed'
                });
            }
            emitLabPdfAuditEvent('copilot_lab_pdf_ingestion_failed', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: labPdfAttachment.descriptor.fileName || null,
                documentType: attachmentDocumentType,
                toolStatus: error.errorCategory || 'request_failed',
                seededDemo: Boolean(labPdfAttachment.useDemoSeed),
                ragGrounded: false
            });
        }

        if (tracksAttachmentReviewLlm) {
            emitAttachmentReviewLlmAuditEvent('copilot_attachment_review_llm_call_failed', {
                requestId,
                role: resolvedRole,
                mode: resolvedMode,
                selectedPatientKey,
                documentTitle: labPdfAttachment?.descriptor?.fileName || null,
                documentType: labPdfAttachment?.descriptor?.documentType || null,
                toolStatus: 'llm_call_failed',
                provider: 'local_fallback',
                model: null,
                openaiConfigured: false,
                fallbackReason: error.errorCategory || 'request_failed',
                openaiErrorCategory: error.errorCategory || 'request_failed',
                ragGrounded: false
            });
        }

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

    restoreSessionIfAvailable();

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

    restoreSessionIfAvailable();

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
            keepStatusMessage: 'Attachment cleared because the selected patient changed. Reattach the PDF for the new patient if needed.',
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
            keepStatusMessage: 'Attachment cleared because the staff role changed. Reattach the PDF if this workflow is still appropriate.',
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
        setLabPdfStatus(`Select ${attachmentWorkflowLabel(currentSelectedDocumentType())}, attach a PDF in the composer, then send your prompt through the normal copilot workflow.`);
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

if (documentTypeSelect) {
    documentTypeSelect.addEventListener('change', (event) => {
        syncDocumentTypeSelection(event.target.value, { emitTelemetry: true });
        if (state.labPdf.descriptor) {
            state.labPdf.descriptor.documentType = currentSelectedDocumentType();
            state.labPdf.descriptor.displayLabel = `Attached: ${state.labPdf.descriptor.fileName || 'attached-document.pdf'}`;
            refreshLabPdfAttachmentUi();
        }
        setLabPdfStatus(`Selected ${attachmentWorkflowLabel(currentSelectedDocumentType())}. Attach a PDF and send a prompt for draft-only clinician review.`, 'neutral');
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

if (labEvidenceClearButton) {
    labEvidenceClearButton.addEventListener('click', () => {
        void requestClearLabEvidence();
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
syncDocumentTypeSelection('lab_pdf');
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
