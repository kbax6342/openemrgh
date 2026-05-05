<?php

/**
 * OpenEMR Medical Co-Pilot Demo JSON endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once(__DIR__ . '/lab_pdf_vector_store.php');
require_once(__DIR__ . '/lab_pdf_ingestion.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use PHPMailer\PHPMailer\PHPMailer;

const AI_COPILOT_SAFETY_NOTE = 'Draft only. Human review required. This does not replace clinical, billing, or compliance review.';
const AI_COPILOT_NO_PATIENT_NOTE = 'No demo patient is selected, so this answer is general guidance only. Select a demo patient for chart-specific support.';

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$requestStartedAt = microtime(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    aiCopilotJsonResponse(405, [
        'error' => 'Use POST for this endpoint.',
        'meta' => aiCopilotErrorMeta(aiCopilotResolveRequestId(null), 'method_not_allowed'),
    ]);
    exit;
}

$requestId = aiCopilotResolveRequestId(null);
$payload = aiCopilotParseRequestPayload();
if (!is_array($payload)) {
    aiCopilotJsonResponse(400, [
        'error' => 'Invalid request payload.',
        'meta' => aiCopilotErrorMeta($requestId, 'invalid_json'),
    ]);
    exit;
}

$requestId = aiCopilotResolveRequestId($payload['request_id'] ?? $payload['trace_id'] ?? null);

$csrfToken = $payload['csrf_token_form'] ?? '';
if (!is_string($csrfToken) || !CsrfUtils::verifyCsrfToken($csrfToken, session: $session)) {
    aiCopilotJsonResponse(403, [
        'error' => xla('Authentication Error'),
        'meta' => aiCopilotErrorMeta($requestId, 'authentication_error'),
    ]);
    exit;
}

$action = aiCopilotResolveAction($payload['action'] ?? 'chat');
$roleCatalog = aiCopilotRoleCatalog();
$role = aiCopilotResolveRole($payload['role'] ?? 'doctor', $roleCatalog);
$validModes = aiCopilotModeCatalog();

$patientId = filter_var($payload['patient_id'] ?? $payload['pid'] ?? null, FILTER_VALIDATE_INT);
$patient = [];
if ($patientId !== false && $patientId !== null && $patientId > 0) {
    $patient = sqlQuery(
        "SELECT pid, pubpid, fname, lname, DOB, sex, genericval1, genericval2, email, phone_home, phone_cell
         FROM patient_data
         WHERE pid = ?
           AND genericname1 = 'demo_scenario'
         LIMIT 1",
        [$patientId]
    ) ?: [];

    if (empty($patient)) {
        aiCopilotJsonResponse(404, [
            'error' => 'Selected demo patient was not found.',
            'meta' => aiCopilotErrorMeta($requestId, 'patient_not_found'),
        ]);
        exit;
    }
}

$requestedMode = is_string($payload['mode'] ?? '') ? trim($payload['mode']) : '';
$openAiConfigured = aiCopilotReadEnv('OPENAI_API_KEY') !== '';
$toolInput = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
$toolPrompt = aiCopilotNormalizePrompt($toolInput['prompt'] ?? $payload['message'] ?? '');
$uploadedLabPdf = aiCopilotNormalizeUploadedLabPdf($_FILES['lab_pdf_attachment'] ?? null);
$useSeededLabPdf = aiCopilotRequestTruthValue($payload['use_seeded_lab_pdf'] ?? false);

if ($action === 'agent_tool') {
    $toolName = aiCopilotNormalizePrompt($payload['tool_name'] ?? '');
    $toolRequestedMode = is_string($toolInput['mode'] ?? null)
        ? trim((string) $toolInput['mode'])
        : $requestedMode;
    $mode = aiCopilotResolveMode($toolRequestedMode, $toolPrompt, $validModes);
    $chatHistory = aiCopilotNormalizeChatHistory($toolInput['chat_history'] ?? $payload['chat_history'] ?? []);
    $fullContext = !empty($patient)
        ? aiCopilotBuildContext($patient, $mode)
        : aiCopilotBuildGeneralContext($mode, $toolPrompt);
    $context = aiCopilotFilterContextForRole($fullContext, $role, $mode);
    $context = aiCopilotAttachClientAmbientVisitContext($context, $payload['ambient_visit_context'] ?? null, $role);
    $context = aiCopilotAttachClientAmbientVisitContext($context, $payload['ambient_context']['latestApprovedVisit'] ?? null, $role);
    $context = aiCopilotAttachClientLabPdfContext($context, $payload['lab_pdf_context'] ?? null, $role);
    $context = aiCopilotAttachRetrievedLabPdfContext($context, $toolPrompt, $role, $mode, $requestId);

    $result = aiCopilotExecuteAgentTool(
        $toolName,
        $toolInput,
        $requestId,
        $role,
        $mode,
        $toolPrompt,
        $chatHistory,
        $context,
        $validModes,
        $openAiConfigured,
        $requestStartedAt
    );

    if (($result['ok'] ?? false) !== true) {
        aiCopilotJsonResponse(400, [
            'error' => $result['error'] ?? 'Unsupported tool request.',
            'meta' => aiCopilotErrorMeta($requestId, $result['error_category'] ?? 'agent_tool_error'),
        ]);
        exit;
    }

    aiCopilotJsonResponse(200, [
        'ok' => true,
        'tool_name' => $toolName,
        'result' => $result['result'] ?? [],
        'meta' => $result['meta'] ?? aiCopilotErrorMeta($requestId, 'agent_tool'),
    ]);
    exit;
}

if ($action === 'send_reminder_email') {
    $fullContext = !empty($patient)
        ? aiCopilotBuildContext($patient, 'send_reminder')
        : aiCopilotBuildGeneralContext('send_reminder', '');
    $context = aiCopilotFilterContextForRole($fullContext, $role, 'send_reminder');
    $result = aiCopilotSendReminderEmailAction($role, $context);
    $result['meta'] = aiCopilotBuildResponseMeta(
        $requestId,
        $role,
        'send_reminder',
        $context,
        [
            'answer' => $result['message'] ?? '',
            'sections' => [],
            'tags' => $result['tags'] ?? [],
        ],
        [
            'restricted_by_role' => $role !== 'front_desk',
            'restriction_type' => $role !== 'front_desk' ? 'front_desk_reminder_permission_block' : null,
            'fallback_used' => false,
            'engine' => 'fallback',
            'provider' => 'local_fallback',
            'model' => null,
            'openai_configured' => $openAiConfigured,
        ],
        $requestStartedAt
    );
    aiCopilotJsonResponse(200, $result);
    exit;
}

$message = aiCopilotNormalizePrompt($payload['message'] ?? '');
if ($message === '') {
    aiCopilotJsonResponse(400, [
        'error' => 'Enter a prompt before sending.',
        'meta' => aiCopilotErrorMeta($requestId, 'empty_prompt'),
    ]);
    exit;
}

$mode = aiCopilotResolveMode($requestedMode, $message, $validModes);
$chatHistory = aiCopilotNormalizeChatHistory($payload['chat_history'] ?? []);
$fullContext = !empty($patient)
    ? aiCopilotBuildContext($patient, $mode)
    : aiCopilotBuildGeneralContext($mode, $message);
$context = aiCopilotFilterContextForRole($fullContext, $role, $mode);
$context = aiCopilotAttachClientAmbientVisitContext($context, $payload['ambient_visit_context'] ?? null, $role);
$context = aiCopilotAttachClientAmbientVisitContext($context, $payload['ambient_context']['latestApprovedVisit'] ?? null, $role);
$context = aiCopilotAttachClientLabPdfContext($context, $payload['lab_pdf_context'] ?? null, $role);
$context = aiCopilotAttachUploadedLabPdfContext($context, $uploadedLabPdf, $useSeededLabPdf, $payload, $role, $mode, $message, $requestId);
$context = aiCopilotAttachRetrievedLabPdfContext($context, $message, $role, $mode, $requestId);
$sources = aiCopilotBuildSources($context, $mode);
$clientLabPdfToolOutput = aiCopilotLabPdfToolOutputForClient(is_array($context['attached_lab_pdf_tool_output'] ?? null) ? $context['attached_lab_pdf_tool_output'] : []);
$permissionResponse = aiCopilotMaybeBuildRolePermissionResponse($role, $mode, $message, $context);
if ($permissionResponse !== []) {
    $meta = aiCopilotBuildResponseMeta(
        $requestId,
        $role,
        $mode,
        $context,
        $permissionResponse,
        [
            'restricted_by_role' => true,
            'restriction_type' => $permissionResponse['restriction_type'] ?? 'role_guardrail',
            'fallback_used' => false,
            'engine' => 'guardrail',
            'provider' => 'guardrail',
            'model' => null,
            'openai_configured' => $openAiConfigured,
            'rag_grounded' => aiCopilotShouldMarkRagGroundedForRequest($mode, $message, $context),
        ],
        $requestStartedAt
    );
    aiCopilotJsonResponse(200, [
        'ok' => true,
        'mode' => $mode,
        'role' => $role,
        'patient' => !empty($context['patient']['name']) ? $context['patient']['name'] : null,
        'answer' => $permissionResponse['answer'],
        'sections' => $permissionResponse['sections'],
        'tags' => $permissionResponse['tags'],
        'sources' => $sources,
        'safety_note' => aiCopilotRoleSafetyNote($role),
        'engine' => 'guardrail',
        'provider' => 'guardrail',
        'tool_output' => $clientLabPdfToolOutput !== [] ? $clientLabPdfToolOutput : null,
        'meta' => $meta,
    ]);
    exit;
}

$draft = aiCopilotGenerateDraft($requestId, $role, $mode, $message, $chatHistory, $context, $validModes[$mode]);
$meta = aiCopilotBuildResponseMeta(
    $requestId,
    $role,
    $mode,
    $context,
    $draft,
    [
        'restricted_by_role' => false,
        'fallback_used' => ($draft['engine'] ?? '') === 'fallback',
        'fallback_reason' => $draft['fallback_reason'] ?? null,
        'engine' => $draft['engine'] ?? 'fallback',
        'provider' => $draft['provider'] ?? (($draft['engine'] ?? '') === 'openai' ? 'openai' : 'local_fallback'),
        'model' => $draft['model'] ?? null,
        'openai_configured' => (bool) ($draft['openai_configured'] ?? $openAiConfigured),
        'error_category' => $draft['error_category'] ?? null,
        'openai_error_category' => $draft['openai_error_category'] ?? null,
        'openai_http_status' => $draft['openai_http_status'] ?? null,
        'openai_error_message_safe' => $draft['openai_error_message_safe'] ?? null,
        'rag_grounded' => aiCopilotShouldMarkRagGroundedForRequest($mode, $message, $context),
    ],
    $requestStartedAt
);

aiCopilotJsonResponse(200, [
    'ok' => true,
    'mode' => $mode,
    'role' => $role,
    'patient' => !empty($context['patient']['name']) ? $context['patient']['name'] : null,
    'answer' => $draft['answer'],
    'sections' => $draft['sections'],
    'tags' => $draft['tags'],
    'sources' => $sources,
    'safety_note' => aiCopilotRoleSafetyNote($role),
    'engine' => $draft['engine'],
    'provider' => $draft['provider'] ?? (($draft['engine'] ?? '') === 'openai' ? 'openai' : 'local_fallback'),
    'tool_output' => $clientLabPdfToolOutput !== [] ? $clientLabPdfToolOutput : null,
    'meta' => $meta,
]);

function aiCopilotJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function aiCopilotCreateId(string $prefix): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(8));
    } catch (\Throwable) {
        return $prefix . '_' . str_replace('.', '', uniqid('', true));
    }
}

function aiCopilotResolveRequestId(mixed $value): string
{
    $candidate = is_string($value) ? trim($value) : '';
    if ($candidate === '') {
        return aiCopilotCreateId('request');
    }

    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $candidate) ?: aiCopilotCreateId('request');
}

function aiCopilotErrorMeta(string $requestId, string $errorCategory): array
{
    return [
        'request_id' => $requestId,
        'error_category' => $errorCategory,
        'openai_error_category' => null,
        'openai_http_status' => null,
        'openai_error_message_safe' => null,
        'fallback_used' => false,
    ];
}

function aiCopilotResponseTextLength(array $response): int
{
    $parts = [];
    $answer = aiCopilotCleanText($response['answer'] ?? '');
    if ($answer !== '') {
        $parts[] = $answer;
    }

    foreach (($response['sections'] ?? []) as $section) {
        if (!is_array($section)) {
            continue;
        }

        $title = aiCopilotCleanText($section['title'] ?? '');
        if ($title !== '') {
            $parts[] = $title;
        }

        foreach (($section['items'] ?? []) as $item) {
            $itemText = aiCopilotCleanText((string) $item);
            if ($itemText !== '') {
                $parts[] = $itemText;
            }
        }
    }

    $value = implode("\n", $parts);
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function aiCopilotContextScope(string $role, array $context): string
{
    if (!aiCopilotContextHasPatient($context)) {
        return 'general_prompt';
    }

    return match ($role) {
        'nurse' => 'nursing_limited',
        'billing' => 'billing_limited',
        'front_desk' => 'front_desk_minimum_phi',
        default => 'full_clinical',
    };
}

function aiCopilotBuildResponseMeta(
    string $requestId,
    string $role,
    string $mode,
    array $context,
    array $response,
    array $overrides,
    float $requestStartedAt
): array {
    $engine = (string) ($overrides['engine'] ?? $response['engine'] ?? 'fallback');
    $provider = (string) ($overrides['provider'] ?? $response['provider'] ?? ($engine === 'openai' ? 'openai' : ($engine === 'guardrail' ? 'guardrail' : 'local_fallback')));
    $model = $overrides['model'] ?? ($response['model'] ?? null);
    $tokenUsage = aiCopilotNormalizeTokenUsage($response['token_usage'] ?? null);
    $estimatedCostUsd = $response['estimated_cost_usd'] ?? null;
    $costNote = $response['cost_note'] ?? null;
    $ragGrounded = (bool) ($overrides['rag_grounded'] ?? aiCopilotIsRagGrounded($mode, $context));

    $openAiErrorMessageSafe = aiCopilotCleanText((string) ($overrides['openai_error_message_safe'] ?? ($response['openai_error_message_safe'] ?? '')));

    return [
        'request_id' => $requestId,
        'mode' => $mode,
        'role' => $role,
        'engine' => $engine,
        'provider' => $provider,
        'model' => is_string($model) && trim($model) !== '' ? trim($model) : null,
        'fallback_used' => (bool) ($overrides['fallback_used'] ?? false),
        'fallback_reason' => $overrides['fallback_reason'] ?? null,
        'openai_configured' => (bool) ($overrides['openai_configured'] ?? ($response['openai_configured'] ?? false)),
        'token_usage' => $tokenUsage,
        'estimated_cost_usd' => is_numeric($estimatedCostUsd) ? (float) $estimatedCostUsd : null,
        'cost_note' => is_string($costNote) && trim($costNote) !== '' ? trim($costNote) : null,
        'error_category' => $overrides['error_category'] ?? ($response['error_category'] ?? null),
        'openai_error_category' => $overrides['openai_error_category'] ?? ($response['openai_error_category'] ?? null),
        'openai_http_status' => isset($overrides['openai_http_status']) && is_numeric($overrides['openai_http_status'])
            ? (int) $overrides['openai_http_status']
            : (isset($response['openai_http_status']) && is_numeric($response['openai_http_status']) ? (int) $response['openai_http_status'] : null),
        'openai_error_message_safe' => $openAiErrorMessageSafe !== '' ? $openAiErrorMessageSafe : null,
        'openai_response_format' => aiCopilotCleanText((string) ($overrides['openai_response_format'] ?? ($response['openai_response_format'] ?? ''))),
        'restricted_by_role' => (bool) ($overrides['restricted_by_role'] ?? false),
        'restriction_type' => $overrides['restriction_type'] ?? null,
        'context_scope' => aiCopilotContextScope($role, $context),
        'latency_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
        'response_length' => aiCopilotResponseTextLength($response),
        'rag_grounded' => $ragGrounded,
    ];
}

function aiCopilotNormalizeTokenUsage(mixed $usage): ?array
{
    if (!is_array($usage)) {
        return null;
    }

    $promptTokens = isset($usage['prompt_tokens']) && is_numeric($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null;
    $completionTokens = isset($usage['completion_tokens']) && is_numeric($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null;
    $totalTokens = isset($usage['total_tokens']) && is_numeric($usage['total_tokens']) ? (int) $usage['total_tokens'] : null;

    if ($promptTokens === null && $completionTokens === null && $totalTokens === null) {
        return null;
    }

    return [
        'prompt_tokens' => $promptTokens,
        'completion_tokens' => $completionTokens,
        'total_tokens' => $totalTokens,
    ];
}

function aiCopilotPromptImpliesChartRetrieval(string $message): bool
{
    return preg_match('/\b(chart|chart context|visit history|ai-assisted encounter|ambient encounter capture|latest ambient encounter|troponin|a1c|glucose|wbc|ldl|creatinine|lab result|labs|medication history|medication information|insurance|immunization|care preference|payment due|balance due|payer|billing context|policy number)\b/i', $message) === 1;
}

function aiCopilotShouldMarkRagGroundedForRequest(string $mode, string $message, array $context): bool
{
    if (!aiCopilotContextHasPatient($context)) {
        return false;
    }

    if (aiCopilotIsRagGrounded($mode, $context)) {
        return true;
    }

    if (in_array($mode, ['appointment_info', 'patient_contact', 'send_reminder', 'front_desk_summary'], true)) {
        return false;
    }

    return aiCopilotPromptImpliesChartRetrieval($message);
}

function aiCopilotIsRagGrounded(string $mode, array $context): bool
{
    if (!aiCopilotContextHasPatient($context)) {
        return false;
    }

    return in_array($mode, [
        'medication_info',
        'treatment_plan',
        'clinical_notes',
        'follow_up',
        'visit_summary',
        'patient_education',
        'rag_chart_context',
        'latest_ambient_summary',
        'lab_pdf_ingestion',
    ], true);
}

function aiCopilotResolveAction(mixed $value): string
{
    $action = is_string($value) ? trim($value) : 'chat';
    return in_array($action, ['chat', 'send_reminder_email', 'agent_tool'], true) ? $action : 'chat';
}

function aiCopilotParseRequestPayload(): ?array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data')) {
        $payload = $_POST;
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['chat_history_json']) && !isset($payload['chat_history'])) {
            $decoded = json_decode((string) $payload['chat_history_json'], true);
            $payload['chat_history'] = is_array($decoded) ? $decoded : [];
        }

        if (isset($payload['ambient_visit_context_json']) && !isset($payload['ambient_visit_context'])) {
            $decoded = json_decode((string) $payload['ambient_visit_context_json'], true);
            $payload['ambient_visit_context'] = is_array($decoded) ? $decoded : null;
        }

        if (isset($payload['ambient_context_json']) && !isset($payload['ambient_context'])) {
            $decoded = json_decode((string) $payload['ambient_context_json'], true);
            $payload['ambient_context'] = is_array($decoded) ? $decoded : null;
        }

        if (isset($payload['lab_pdf_context_json']) && !isset($payload['lab_pdf_context'])) {
            $decoded = json_decode((string) $payload['lab_pdf_context_json'], true);
            $payload['lab_pdf_context'] = is_array($decoded) ? $decoded : null;
        }

        return $payload;
    }

    $rawInput = file_get_contents('php://input') ?: '';
    $decoded = json_decode($rawInput, true);
    return is_array($decoded) ? $decoded : null;
}

function aiCopilotRequestTruthValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value === 1;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function aiCopilotNormalizeUploadedLabPdf(mixed $file): ?array
{
    if (!is_array($file)) {
        return null;
    }

    if (!isset($file['name']) && !isset($file['tmp_name'])) {
        return null;
    }

    return $file;
}

function aiCopilotRoleCatalog(): array
{
    return [
        'doctor' => [
            'title' => 'Doctor',
            'note' => 'Clinical support only. No autonomous diagnosis, prescribing, orders, or chart writes.',
            'allowed_modes' => [
                'general_assistant',
                'differential_diagnosis',
                'medication_info',
                'clinical_notes',
                'treatment_plan',
                'follow_up',
                'rag_chart_context',
                'lab_pdf_ingestion',
                'latest_ambient_summary',
                'visit_summary',
                'patient_education',
                'billing',
                'billing_review',
            ],
        ],
        'nurse' => [
            'title' => 'Nurse',
            'note' => 'Education and follow-up support only. Medication changes require clinician review.',
            'allowed_modes' => [
                'general_assistant',
                'medication_info',
                'clinical_notes',
                'follow_up',
                'visit_summary',
                'patient_education',
                'latest_ambient_summary',
            ],
        ],
        'billing' => [
            'title' => 'Billing Staff',
            'note' => 'Billing review only. No automatic claim submission or definitive coding.',
            'allowed_modes' => [
                'general_assistant',
                'billing',
                'billing_review',
                'visit_summary',
                'latest_ambient_summary',
            ],
        ],
        'front_desk' => [
            'title' => 'Front Desk',
            'note' => 'Minimum necessary PHI. Scheduling, contact, and reminder workflows only.',
            'allowed_modes' => [
                'general_assistant',
                'appointment_info',
                'patient_contact',
                'send_reminder',
                'front_desk_summary',
                'latest_ambient_summary',
            ],
        ],
    ];
}

function aiCopilotResolveRole(mixed $value, array $roleCatalog): string
{
    $role = is_string($value) ? trim($value) : 'doctor';
    return isset($roleCatalog[$role]) ? $role : 'doctor';
}

function aiCopilotModeCatalog(): array
{
    return [
        'general_assistant' => [
            'title' => 'General clinical support',
            'style' => 'Answer like a concise beta clinical support assistant. Use the user message as the main instruction and stay grounded in the provided chart context when available.',
        ],
        'differential_diagnosis' => [
            'title' => 'Differential Diagnosis',
            'style' => 'Offer likely possibilities, red flags, key gaps, and what to clarify next. Never claim final diagnosis certainty or recommend unsupervised treatment.',
        ],
        'medication_info' => [
            'title' => 'Medication Info',
            'style' => 'Review medication safety, adherence, interactions, monitoring, and counseling points. Do not change medications or prescribe.',
        ],
        'clinical_notes' => [
            'title' => 'Clinical Notes',
            'style' => 'Draft concise note support using the chart context. Include Subjective, Objective, Assessment, and Plan sections.',
        ],
        'treatment_plan' => [
            'title' => 'Treatment Plan',
            'style' => 'Draft follow-up, monitoring, education, and safety checks. Do not issue orders or a final treatment decision.',
        ],
        'billing' => [
            'title' => 'Billing',
            'style' => 'Provide billing-support suggestions only. Focus on documentation points, possible coding considerations, and missing documentation to review before billing. Never submit claims, never suggest upcoding, and never present definitive CPT or ICD coding without documentation support.',
        ],
        'follow_up' => [
            'title' => 'Follow-Up',
            'style' => 'Draft follow-up timing, monitoring, patient instructions, escalation precautions, and care coordination. Do not place orders or finalize treatment.',
        ],
        'rag_chart_context' => [
            'title' => 'RAG: Review Marcus\'s Chart Context',
            'style' => 'Retrieve relevant chart context first, summarize what changed, cite visit history and other chart sources, and stay draft-only. Do not answer from uncited memory.',
        ],
        'lab_pdf_ingestion' => [
            'title' => 'Lab PDF Ingestion',
            'style' => 'Extract draft lab facts from an attached lab PDF, call out missing data, cite the attachment as a source, and require clinician review. Never write the extracted content back to the chart automatically.',
        ],
        'latest_ambient_summary' => [
            'title' => 'Latest Ambient Encounter Summary',
            'style' => 'Summarize only the latest approved ambient encounter capture or AI-assisted visit review. Keep the summary role-appropriate, draft-only, and grounded in the retrieved ambient encounter context.',
        ],
        'visit_summary' => [
            'title' => 'Visit Summary',
            'style' => 'Draft a concise visit summary using the chart context and any prior treatment-plan discussion in chat history. Keep it patient-safe, beta-labeled, and read-only.',
        ],
        'patient_education' => [
            'title' => 'Patient Education',
            'style' => 'Explain the existing plan in patient-friendly language using the documented chart context only. Do not create a new diagnosis or change treatment.',
        ],
        'physician_summary' => [
            'title' => 'Physician Summary',
            'style' => 'Provide a concise chart summary for rapid review.',
        ],
        'ma_rooming' => [
            'title' => 'MA Rooming Checklist',
            'style' => 'Provide preparation and confirmation items only.',
        ],
        'billing_review' => [
            'title' => 'Billing Claim Review',
            'style' => 'Explain the billing issue in plain language and what should be checked first. Never submit or modify claims automatically.',
        ],
        'appointment_info' => [
            'title' => 'Appointment Info',
            'style' => 'Use minimum necessary scheduling information only: appointment type, date/time, provider, location, and check-in instructions.',
        ],
        'patient_contact' => [
            'title' => 'Patient Contact',
            'style' => 'Use minimum necessary contact information only. Confirm email, phone, and basic appointment workflow details without exposing clinical content.',
        ],
        'send_reminder' => [
            'title' => 'Send Reminder',
            'style' => 'Draft a scheduling reminder using only administrative appointment details and no clinical information.',
        ],
        'front_desk_summary' => [
            'title' => 'Front Desk Summary',
            'style' => 'Provide a short administrative summary with appointment details, contact confirmation points, and check-in instructions only.',
        ],
    ];
}

function aiCopilotResolveMode(string $requestedMode, string $message, array $validModes): string
{
    if ($requestedMode !== '' && $requestedMode !== 'general_assistant' && isset($validModes[$requestedMode])) {
        return $requestedMode;
    }

    $inferredMode = aiCopilotInferModeFromMessage($message);
    if (isset($validModes[$inferredMode]) && $inferredMode !== 'general_assistant') {
        return $inferredMode;
    }

    if ($requestedMode !== '' && isset($validModes[$requestedMode])) {
        return $requestedMode;
    }

    return 'general_assistant';
}

function aiCopilotInferModeFromMessage(string $message): string
{
    $value = strtolower($message);

    if (preg_match('/lab pdf ingestion|lab pdf|attach.*pdf|upload.*pdf|ingest.*pdf|extract.*pdf|pdf lab results?/', $value)) {
        return 'lab_pdf_ingestion';
    }
    if (preg_match('/summarize latest ambient encounter only|latest ambient encounter only|latest ambient encounter|ambient encounter only|latest ai-assisted visit review|latest approved ambient encounter/', $value)) {
        return 'latest_ambient_summary';
    }
    if (preg_match('/appointment|scheduled|check[- ]?in|reminder email|reminder message|reminder/', $value)) {
        if (preg_match('/contact|email|phone|confirm/', $value)) {
            return 'patient_contact';
        }
        if (preg_match('/send|draft/', $value)) {
            return 'send_reminder';
        }
        if (preg_match('/summary/', $value)) {
            return 'front_desk_summary';
        }
        return 'appointment_info';
    }
    if (preg_match('/contact information|contact info|phone number|email address/', $value)) {
        return 'patient_contact';
    }
    if (preg_match('/claim|cpt|icd|payer|rejection|denial|resubmi|missing diagnosis link/', $value)) {
        return 'billing_review';
    }
    if (preg_match('/billing|coding|coder|documentation needed before billing|payment due|next payment due|balance due|patient balance|insurance balance|what does .* owe|health insurance|insurance on file|insurance/', $value)) {
        return 'billing';
    }
    if (preg_match('/visit history|ai-assisted encounter|ambient encounter capture|what changed since|chart context|troponin|lab result|latest lab|what sources did you use|sources did you use/', $value)) {
        return 'rag_chart_context';
    }
    if (preg_match('/visit summary|summary of visit/', $value)) {
        return 'visit_summary';
    }
    if (preg_match('/patient-friendly|patient friendly|education|counseling points/', $value)) {
        return 'patient_education';
    }
    if (preg_match('/medication|drug|interaction|counsel|double-check/', $value)) {
        return 'medication_info';
    }
    if (preg_match('/treatment plan|let me see .* treatment plan|give me .* treatment plan/', $value)) {
        return 'treatment_plan';
    }
    if (preg_match('/follow-up|follow up|monitoring items|escalation precautions|care coordination/', $value)) {
        return 'follow_up';
    }
    if (preg_match('/soap|note|documentation|chart for a doctor|30 seconds|30-second|summary/', $value)) {
        return 'clinical_notes';
    }
    if (preg_match('/differential|diagnosis|causing|red flag|miss|questions should the clinician ask/', $value)) {
        return 'differential_diagnosis';
    }
    if (preg_match('/rooming|checklist|confirm before the provider/', $value)) {
        return 'ma_rooming';
    }

    return 'general_assistant';
}

function aiCopilotNormalizePrompt(mixed $value): string
{
    return is_string($value) ? aiCopilotCleanText($value) : '';
}

function aiCopilotNormalizeChatHistory(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $role = $item['role'] ?? '';
        $content = aiCopilotNormalizePrompt($item['content'] ?? '');
        if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
            continue;
        }

        $normalized[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    return array_slice($normalized, -8);
}

function aiCopilotBuildGeneralContext(string $mode, string $message): array
{
    return [
        'role' => 'doctor',
        'mode' => $mode,
        'scenario' => 'general_prompt',
        'patient_selected' => false,
        'patient' => [],
        'prompt' => $message,
        'next_appointment' => [],
        'appointments' => [],
        'encounters' => [],
        'notes' => [],
        'problems' => [],
        'allergies' => [],
        'medications' => [],
        'latest_vitals' => [],
        'primary_insurance' => [],
        'billing' => [
            'encounter' => [],
            'rows' => [],
            'claim' => [],
            'payment_summary' => [],
        ],
        'approved_ambient_visit' => [],
    ];
}

function aiCopilotBuildContext(array $patient, string $mode): array
{
    $pid = (int) $patient['pid'];
    $patientLabel = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));

    $nextAppointment = sqlQuery(
        "SELECT pc_title, pc_hometext, pc_eventDate, pc_startTime, pc_endTime, pc_apptstatus, pc_room, pc_location
         FROM openemr_postcalendar_events
         WHERE pc_pid = ?
           AND pc_eventDate >= CURDATE()
         ORDER BY pc_eventDate ASC, pc_startTime ASC
         LIMIT 1",
        [(string) $pid]
    ) ?: [];

    $appointments = aiCopilotFetchAll(
        "SELECT pc_title, pc_hometext, pc_eventDate, pc_startTime, pc_endTime, pc_apptstatus, pc_room, pc_location
         FROM openemr_postcalendar_events
         WHERE pc_pid = ?
         ORDER BY pc_eventDate DESC, pc_startTime DESC
         LIMIT 6",
        [(string) $pid]
    );

    $encounters = aiCopilotFetchAll(
        "SELECT date, reason, encounter, billing_note
         FROM form_encounter
         WHERE pid = ?
         ORDER BY date DESC
         LIMIT 6",
        [$pid]
    );

    $notes = aiCopilotFetchAll(
        "SELECT date, title, body
         FROM pnotes
         WHERE pid = ?
           AND deleted = 0
         ORDER BY date DESC
         LIMIT 8",
        [$pid]
    );

    $problems = aiCopilotFetchAll(
        "SELECT title, diagnosis, comments, begdate
         FROM lists
         WHERE pid = ?
           AND type = 'medical_problem'
           AND (enddate IS NULL OR enddate = '0000-00-00 00:00:00' OR enddate > NOW())
         ORDER BY begdate DESC, date DESC
         LIMIT 12",
        [$pid]
    );

    $allergies = aiCopilotFetchAll(
        "SELECT title, diagnosis, comments, begdate
         FROM lists
         WHERE pid = ?
           AND type = 'allergy'
           AND (enddate IS NULL OR enddate = '0000-00-00 00:00:00' OR enddate > NOW())
         ORDER BY begdate DESC, date DESC
         LIMIT 12",
        [$pid]
    );

    $medications = aiCopilotFetchAll(
        "SELECT drug, dosage, quantity, note, start_date
         FROM prescriptions
         WHERE patient_id = ?
           AND active = 1
         ORDER BY COALESCE(date_modified, date_added) DESC, id DESC
         LIMIT 12",
        [$pid]
    );

    $latestVitals = sqlQuery(
        "SELECT date, bps, bpd, weight, height, temperature, pulse, respiration, note, BMI, oxygen_saturation
         FROM form_vitals
         WHERE pid = ?
           AND activity = 1
         ORDER BY date DESC, id DESC
         LIMIT 1",
        [$pid]
    ) ?: [];

    $primaryInsurance = sqlQuery(
        "SELECT i.type, i.plan_name, i.policy_number, i.copay, ic.name AS carrier
         FROM insurance_data AS i
         LEFT JOIN insurance_companies AS ic ON ic.id = i.provider
         WHERE i.pid = ?
           AND i.type = 'primary'
         ORDER BY (i.date IS NULL) ASC, i.date DESC
         LIMIT 1",
        [$pid]
    ) ?: [];

    $billingEncounter = $encounters[0] ?? [];
    $billingRows = [];
    $claimRow = [];
    if (!empty($billingEncounter['encounter'])) {
        $billingRows = aiCopilotFetchAll(
            "SELECT code_type, code, code_text, fee, justify, billed, activity
             FROM billing
             WHERE pid = ?
               AND encounter = ?
             ORDER BY id ASC",
            [$pid, (int) $billingEncounter['encounter']]
        );

        $claimRow = sqlQuery(
            "SELECT version, payer_id, status, bill_time, process_time, process_file, submitted_claim
             FROM claims
             WHERE patient_id = ?
               AND encounter_id = ?
             ORDER BY version DESC
             LIMIT 1",
            [$pid, (int) $billingEncounter['encounter']]
        ) ?: [];
    }

    if (empty($billingRows)) {
        $billingRows = aiCopilotFetchAll(
            "SELECT code_type, code, code_text, fee, justify, billed, activity
             FROM billing
             WHERE pid = ?
             ORDER BY date DESC, id DESC
             LIMIT 10",
            [$pid]
        );
    }

    if (empty($claimRow)) {
        $claimRow = sqlQuery(
            "SELECT version, payer_id, status, bill_time, process_time, process_file, submitted_claim
             FROM claims
             WHERE patient_id = ?
             ORDER BY process_time DESC, bill_time DESC, version DESC
             LIMIT 1",
            [$pid]
        ) ?: [];
    }

    $normalizedPrimaryInsurance = aiCopilotNormalizeInsurance($primaryInsurance);
    $normalizedBillingEncounter = aiCopilotNormalizeEncounter($billingEncounter);
    $billingPaymentSummary = aiCopilotBuildBillingPaymentSummary($notes, $normalizedPrimaryInsurance, $normalizedBillingEncounter);

    return [
        'role' => 'doctor',
        'mode' => $mode,
        'scenario' => $patient['genericval1'] ?? '',
        'patient_selected' => true,
        'patient' => [
            'pid' => $pid,
            'pubpid' => $patient['pubpid'] ?? '',
            'name' => $patientLabel,
            'fname' => $patient['fname'] ?? '',
            'lname' => $patient['lname'] ?? '',
            'dob' => $patient['DOB'] ?? '',
            'sex' => $patient['sex'] ?? '',
            'email' => aiCopilotCleanText($patient['email'] ?? ''),
            'phone_home' => aiCopilotCleanText($patient['phone_home'] ?? ''),
            'phone_cell' => aiCopilotCleanText($patient['phone_cell'] ?? ''),
        ],
        'prompt' => $patient['genericval2'] ?? '',
        'next_appointment' => aiCopilotNormalizeAppointment($nextAppointment),
        'appointments' => array_map('aiCopilotNormalizeAppointment', $appointments),
        'encounters' => array_map('aiCopilotNormalizeEncounter', $encounters),
        'notes' => array_map('aiCopilotNormalizeNote', $notes),
        'problems' => array_map('aiCopilotNormalizeProblem', $problems),
        'allergies' => array_map('aiCopilotNormalizeProblem', $allergies),
        'medications' => array_map('aiCopilotNormalizeMedication', $medications),
        'latest_vitals' => aiCopilotNormalizeVitals($latestVitals),
        'primary_insurance' => $normalizedPrimaryInsurance,
        'billing' => [
            'encounter' => $normalizedBillingEncounter,
            'rows' => array_map('aiCopilotNormalizeBillingRow', $billingRows),
            'claim' => aiCopilotNormalizeClaim($claimRow),
            'payment_summary' => $billingPaymentSummary,
        ],
        'approved_ambient_visit' => [],
    ];
}

function aiCopilotBuildLlmContext(string $role, string $mode, array $context, string $message): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return [
            'patient_selected' => false,
            'role' => $role,
            'mode' => $mode,
            'available_sources' => ['General Prompt Context'],
            'instruction' => 'No patient is selected. Treat the answer as general support only.',
        ];
    }

    $facts = aiCopilotExtractClinicalFacts($context);
    $ambientVisit = is_array($context['latest_approved_ambient_encounter'] ?? null)
        ? $context['latest_approved_ambient_encounter']
        : (is_array($context['approved_ambient_visit'] ?? null) ? $context['approved_ambient_visit'] : []);
    $messageLower = strtolower($message);
    $billingSummary = is_array($context['billing']['payment_summary'] ?? null) ? $context['billing']['payment_summary'] : [];

    $base = [
        'patient' => [
            'name' => aiCopilotCleanText($context['patient']['name'] ?? ''),
            'pubpid' => aiCopilotCleanText($context['patient']['pubpid'] ?? ''),
        ],
        'role' => $role,
        'mode' => $mode,
        'available_sources' => aiCopilotBuildSources($context, $mode),
        'latest_visit_summary' => aiCopilotSummarizeEncounterForLlm($context['encounters'][0] ?? []),
        'prior_visit_summary' => aiCopilotSummarizeEncounterForLlm($context['encounters'][1] ?? []),
        'recent_note_summary' => aiCopilotBuildRecentNoteSummaryForLlm($facts),
        'medications' => aiCopilotBuildMedicationRowsForLlm($context),
        'problem_list' => array_slice(array_values(array_filter(array_map(static function ($problem) {
            return aiCopilotCleanText((string) ($problem['title'] ?? ''));
        }, $context['problems'] ?? []))), 0, 6),
        'allergies' => array_slice(array_values(array_filter(array_map(static function ($allergy) {
            return aiCopilotCleanText((string) ($allergy['title'] ?? ''));
        }, $context['allergies'] ?? []))), 0, 6),
        'latest_vitals_summary' => aiCopilotCleanText($facts['vitals_line'] ?? ''),
        'recent_labs_summary' => aiCopilotCleanText($facts['recent_labs'] ?? ''),
        'follow_up_considerations' => aiCopilotCleanText($facts['follow_up_considerations'] ?? ''),
        'care_preferences' => aiCopilotExtractCarePreferencesForLlm($context),
        'immunization_review_note' => aiCopilotBuildImmunizationReviewForLlm($context),
        'latest_approved_ambient_encounter' => aiCopilotBuildAmbientVisitContextForLlm($ambientVisit, $role),
        'billing_context' => aiCopilotBuildBillingContextForLlm($context),
    ];

    if (str_contains($messageLower, 'troponin')) {
        $base['missing_data_instruction'] = aiCopilotContextContainsKeywords($context, ['troponin'])
            ? 'If troponin is present in retrieved context, report only the documented value and source context.'
            : 'Troponin is not present in the retrieved context. Explicitly say troponin was not found, do not invent a value, mention other available lab context only if present, and suggest checking the source labs or chart.';
    }

    if (preg_match('/what changed since the last visit|what changed since last visit|ai-assisted encounter|ambient encounter capture|visit history/', $messageLower) === 1) {
        $base['comparison_instruction'] = 'Compare the latest visit summary with the prior visit summary and the latest approved ambient encounter summary when present. State clearly which comparison elements are missing.';
    }

    if (preg_match('/payment due|patient balance|insurance balance|what does .* owe|insurance on file/', $messageLower) === 1) {
        $base['billing_instruction'] = 'Answer with billing and insurance context only: due date, patient balance, insurance balance, total balance, payer, plan, billing provider, and payment note when present.';
    }

    $compact = match ($mode) {
        'billing', 'billing_review' => [
            'patient' => $base['patient'],
            'role' => $role,
            'mode' => $mode,
            'available_sources' => $base['available_sources'],
            'billing_context' => $base['billing_context'],
            'latest_visit_summary' => $base['latest_visit_summary'],
            'billing_instruction' => $base['billing_instruction'] ?? null,
        ],
        'latest_ambient_summary' => [
            'patient' => $base['patient'],
            'role' => $role,
            'mode' => $mode,
            'available_sources' => ['Patient Chart Context', 'Latest Approved Ambient Encounter Capture', 'AI-Assisted Visit Review', 'Ambient Encounter Capture'],
            'latest_approved_ambient_encounter' => $base['latest_approved_ambient_encounter'],
            'care_preferences' => $base['care_preferences'],
            'billing_context' => in_array($role, ['doctor', 'billing'], true) ? $base['billing_context'] : [],
            'instruction' => 'Summarize only the latest approved ambient encounter. Do not expand older visits. Keep the answer role-appropriate and draft-only.',
        ],
        'rag_chart_context' => [
            'patient' => $base['patient'],
            'role' => $role,
            'mode' => $mode,
            'available_sources' => $base['available_sources'],
            'latest_visit_summary' => $base['latest_visit_summary'],
            'prior_visit_summary' => $base['prior_visit_summary'],
            'recent_note_summary' => $base['recent_note_summary'],
            'medications' => $base['medications'],
            'problem_list' => $base['problem_list'],
            'latest_vitals_summary' => $base['latest_vitals_summary'],
            'recent_labs_summary' => $base['recent_labs_summary'],
            'care_preferences' => $base['care_preferences'],
            'latest_approved_ambient_encounter' => $base['latest_approved_ambient_encounter'],
            'billing_context' => preg_match('/insurance|billing|payment|payer/', $messageLower) === 1 ? $base['billing_context'] : [],
            'comparison_instruction' => $base['comparison_instruction'] ?? null,
            'missing_data_instruction' => $base['missing_data_instruction'] ?? null,
        ],
        'medication_info' => [
            'patient' => $base['patient'],
            'role' => $role,
            'mode' => $mode,
            'available_sources' => $base['available_sources'],
            'medications' => $base['medications'],
            'problem_list' => $base['problem_list'],
            'allergies' => $base['allergies'],
            'latest_vitals_summary' => $base['latest_vitals_summary'],
            'recent_labs_summary' => $base['recent_labs_summary'],
            'follow_up_considerations' => $base['follow_up_considerations'],
            'care_preferences' => $base['care_preferences'],
            'latest_approved_ambient_encounter' => $base['latest_approved_ambient_encounter'],
            'missing_data_instruction' => $base['missing_data_instruction'] ?? null,
        ],
        'treatment_plan', 'clinical_notes', 'follow_up', 'visit_summary', 'patient_education', 'differential_diagnosis' => [
            'patient' => $base['patient'],
            'role' => $role,
            'mode' => $mode,
            'available_sources' => $base['available_sources'],
            'latest_visit_summary' => $base['latest_visit_summary'],
            'prior_visit_summary' => $base['prior_visit_summary'],
            'recent_note_summary' => $base['recent_note_summary'],
            'problem_list' => $base['problem_list'],
            'medications' => $base['medications'],
            'latest_vitals_summary' => $base['latest_vitals_summary'],
            'recent_labs_summary' => $base['recent_labs_summary'],
            'follow_up_considerations' => $base['follow_up_considerations'],
            'care_preferences' => $base['care_preferences'],
            'immunization_review_note' => $base['immunization_review_note'],
            'latest_approved_ambient_encounter' => $base['latest_approved_ambient_encounter'],
            'comparison_instruction' => $base['comparison_instruction'] ?? null,
        ],
        default => [
            'patient' => $base['patient'],
            'role' => $role,
            'mode' => $mode,
            'available_sources' => $base['available_sources'],
            'latest_visit_summary' => $base['latest_visit_summary'],
            'recent_note_summary' => $base['recent_note_summary'],
            'medications' => $base['medications'],
            'problem_list' => $base['problem_list'],
            'latest_vitals_summary' => $base['latest_vitals_summary'],
            'recent_labs_summary' => $base['recent_labs_summary'],
            'billing_context' => preg_match('/payment|billing|insurance|payer/', $messageLower) === 1 ? $base['billing_context'] : [],
            'latest_approved_ambient_encounter' => $base['latest_approved_ambient_encounter'],
            'missing_data_instruction' => $base['missing_data_instruction'] ?? null,
        ],
    };

    return aiCopilotFilterEmptyLlmValue($compact);
}

function aiCopilotBuildMedicationRowsForLlm(array $context): array
{
    $rows = aiCopilotBuildMedicationInformationRows($context);
    return array_slice(array_values(array_filter(array_map('aiCopilotCleanText', $rows))), 0, 6);
}

function aiCopilotSummarizeEncounterForLlm(array $encounter): string
{
    if ($encounter === []) {
        return '';
    }

    $parts = array_filter([
        aiCopilotCleanText((string) ($encounter['date'] ?? '')),
        aiCopilotCleanText((string) ($encounter['reason'] ?? '')),
        aiCopilotCleanText((string) ($encounter['billing_note'] ?? '')),
    ], static fn($value) => $value !== '');

    return aiCopilotCleanText(implode(' | ', $parts));
}

function aiCopilotBuildRecentNoteSummaryForLlm(array $facts): array
{
    return aiCopilotFilterEmptyLlmValue([
        'subjective' => aiCopilotCleanText($facts['recent_note_subjective'] ?? ''),
        'assessment' => aiCopilotCleanText($facts['recent_note_assessment'] ?? ''),
        'plan' => aiCopilotCleanText($facts['recent_note_plan'] ?? ''),
    ]);
}

function aiCopilotExtractCarePreferencesForLlm(array $context): array
{
    $items = [];
    if (aiCopilotContextContainsKeywords($context, ['afternoon phone reminders'])) {
        $items[] = 'Prefers afternoon phone reminders.';
    }
    if (aiCopilotContextContainsKeywords($context, ['written medication instructions'])) {
        $items[] = 'Prefers written medication instructions.';
    }
    if (aiCopilotContextContainsKeywords($context, ['daughter', 'care support contact'])) {
        $items[] = 'Requested daughter as a care support contact.';
    }

    return array_slice($items, 0, 4);
}

function aiCopilotBuildImmunizationReviewForLlm(array $context): string
{
    if (!aiCopilotContextContainsKeywords($context, ['vaccine', 'immunization', 'seasonal vaccine', 'flu'])) {
        return '';
    }

    return 'Seasonal vaccine or immunization review was mentioned in the retrieved chart context and should be verified before updating the record.';
}

function aiCopilotBuildAmbientVisitContextForLlm(array $ambientVisit, string $role = 'doctor'): array
{
    if ($ambientVisit === []) {
        return [];
    }

    $resolvedRole = in_array($role, ['doctor', 'nurse', 'billing', 'front_desk'], true) ? $role : 'doctor';
    $approvedNotes = array_slice(array_values(array_filter(array_map('aiCopilotCleanText', $ambientVisit['approved_notes'] ?? []))), 0, 5);

    if ($resolvedRole === 'front_desk') {
        $approvedNotes = aiCopilotAmbientNotesMatching($ambientVisit, [
            'insurance',
            'appointment',
            'reminder',
            'care preference',
            'written medication instructions',
            'afternoon phone reminders',
        ]);
    } elseif ($resolvedRole === 'billing') {
        $approvedNotes = aiCopilotAmbientNotesMatching($ambientVisit, [
            'insurance',
            'verification',
            'appointment',
        ]);
    } elseif ($resolvedRole === 'nurse') {
        $approvedNotes = aiCopilotAmbientNotesMatching($ambientVisit, [
            'medication support',
            'care preference',
            'care team',
            'appointment',
            'reminder',
            'immunization',
            'insurance',
        ]);
    }

    return aiCopilotFilterEmptyLlmValue([
        'approved_at' => aiCopilotCleanText((string) ($ambientVisit['approved_at_label'] ?? $ambientVisit['approved_at'] ?? '')),
        'title' => aiCopilotCleanText((string) ($ambientVisit['title'] ?? 'AI-Assisted Visit Review')),
        'visit_type' => aiCopilotCleanText((string) ($ambientVisit['visit_type'] ?? 'Ambient Encounter Capture')),
        'summary' => aiCopilotCleanText((string) ($ambientVisit['summary'] ?? '')),
        'approved_notes' => $approvedNotes,
        'review_status' => aiCopilotCleanText((string) ($ambientVisit['review_status'] ?? '')),
    ]);
}

function aiCopilotBuildBillingContextForLlm(array $context): array
{
    $paymentSummary = is_array($context['billing']['payment_summary'] ?? null) ? $context['billing']['payment_summary'] : [];
    $insurance = is_array($context['primary_insurance'] ?? null) ? $context['primary_insurance'] : [];
    $claim = is_array($context['billing']['claim'] ?? null) ? $context['billing']['claim'] : [];

    return aiCopilotFilterEmptyLlmValue([
        'payment_summary' => [
            'next_payment_due_date' => aiCopilotCleanText((string) ($paymentSummary['next_payment_due_date'] ?? '')),
            'patient_balance_due' => aiCopilotCleanText((string) ($paymentSummary['patient_balance_due'] ?? '')),
            'insurance_balance_due' => aiCopilotCleanText((string) ($paymentSummary['insurance_balance_due'] ?? '')),
            'total_balance_due' => aiCopilotCleanText((string) ($paymentSummary['total_balance_due'] ?? '')),
            'payer' => aiCopilotCleanText((string) ($paymentSummary['payer'] ?? $insurance['carrier'] ?? '')),
            'plan_name' => aiCopilotCleanText((string) ($paymentSummary['plan_name'] ?? $insurance['plan_name'] ?? '')),
            'billing_provider' => aiCopilotCleanText((string) ($paymentSummary['billing_provider'] ?? '')),
            'payment_note' => aiCopilotCleanText((string) ($paymentSummary['payment_note'] ?? '')),
            'insurance_note' => aiCopilotCleanText((string) ($paymentSummary['insurance_note'] ?? '')),
        ],
        'claim_status' => isset($claim['status']) ? (string) $claim['status'] : '',
        'insurance_label' => aiCopilotCleanText((string) ($insurance['label'] ?? '')),
    ]);
}

function aiCopilotFilterEmptyLlmValue(mixed $value): mixed
{
    if (is_array($value)) {
        $filtered = [];
        foreach ($value as $key => $item) {
            $normalized = aiCopilotFilterEmptyLlmValue($item);
            if ($normalized === null) {
                continue;
            }
            if (is_array($normalized) && $normalized === []) {
                continue;
            }
            if (is_string($normalized) && $normalized === '') {
                continue;
            }
            $filtered[$key] = $normalized;
        }
        return $filtered;
    }

    if (is_string($value)) {
        return aiCopilotCleanText($value);
    }

    return $value;
}

function aiCopilotBuildLlmRequestInstruction(string $role, string $mode, array $context, string $message): string
{
    $instructions = [
        'Answer the user request first using the compact retrieved chart context.',
        'If the requested data exists in context, include it explicitly in the answer body.',
        'If requested data is missing, say it was not found in the retrieved OpenEMR context and do not invent a value.',
        'Keep the answer draft-only and suitable for human review.',
        'Do not dump the full chart JSON or reference fields not present in the compact context.',
    ];

    $messageLower = strtolower($message);
    if ($mode === 'medication_info') {
        $instructions[] = 'If medications are present, include them explicitly under Current Medication Information.';
    }
    if ($mode === 'treatment_plan') {
        $instructions[] = 'Use Draft Treatment Plan as a section title and base the plan only on the retrieved context.';
    }
    if ($mode === 'latest_ambient_summary') {
        $instructions[] = 'Summarize only the latest approved ambient encounter or AI-assisted visit review. Do not expand older encounters or appointments unless they are explicitly part of the latest approved ambient summary.';
    }
    if ($mode === 'rag_chart_context' || preg_match('/what changed since the last visit|visit history|ai-assisted encounter|ambient encounter capture/', $messageLower) === 1) {
        $instructions[] = 'Use RAG Visit History Summary as a section title and compare only the visit-history context that is provided.';
    }
    if (str_contains($messageLower, 'troponin')) {
        $instructions[] = 'If troponin is not present in retrieved context, explicitly say Troponin was not found, mention any other available lab context only if present, and suggest checking the source chart or labs.';
    }
    if (preg_match('/payment due|patient balance|insurance balance|what does .* owe|insurance on file/', $messageLower) === 1) {
        $instructions[] = 'For billing answers, include due date, balances, payer, plan, billing provider, and payment note when present. Do not include diagnosis, medications, labs, or treatment plan details for billing-focused answers.';
    }

    return implode(' ', $instructions);
}

function aiCopilotBuildLlmChatHistory(array $chatHistory): array
{
    $trimmedHistory = array_slice($chatHistory, -4);
    $normalized = [];

    foreach ($trimmedHistory as $historyMessage) {
        if (!is_array($historyMessage)) {
            continue;
        }

        $role = $historyMessage['role'] ?? '';
        $content = aiCopilotCleanText((string) ($historyMessage['content'] ?? ''));
        if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
            continue;
        }

        if (function_exists('mb_substr')) {
            $content = mb_substr($content, 0, 500);
        } else {
            $content = substr($content, 0, 500);
        }

        $normalized[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    return $normalized;
}

function aiCopilotGenerateDraft(string $requestId, string $role, string $mode, string $message, array $chatHistory, array $context, array $modeConfig): array
{
    if ($mode === 'lab_pdf_ingestion') {
        $fallbackResponse = aiCopilotBuildLabPdfIngestionResponse($context);
        $fallbackResponse['engine'] = 'fallback';
        $fallbackResponse['provider'] = 'local_fallback';
        $fallbackResponse['model'] = null;
        $fallbackResponse['openai_configured'] = aiCopilotReadEnv('OPENAI_API_KEY') !== '';
        $fallbackResponse['token_usage'] = null;
        $fallbackResponse['estimated_cost_usd'] = null;
        $fallbackResponse['cost_note'] = null;
        $fallbackResponse['fallback_reason'] = 'attachment_review_workflow';
        $fallbackResponse['error_category'] = null;
        $fallbackResponse['openai_error_category'] = null;
        $fallbackResponse['openai_http_status'] = null;
        $fallbackResponse['openai_error_message_safe'] = null;
        return aiCopilotEnhanceDraftResponse($fallbackResponse, $role, $mode, $message, $context);
    }

    $apiKey = aiCopilotReadEnv('OPENAI_API_KEY');
    $openAiConfigured = $apiKey !== '';
    $fallbackReason = 'missing_openai_key';
    $errorCategory = null;
    $openAiAttempt = [];
    if ($openAiConfigured) {
        $openAiAttempt = aiCopilotGenerateOpenAiDraft($requestId, $apiKey, $role, $mode, $message, $chatHistory, $context, $modeConfig);
        if (($openAiAttempt['ok'] ?? false) === true && !empty($openAiAttempt['response'])) {
            $openAiResponse = $openAiAttempt['response'];
            $openAiResponse['engine'] = 'openai';
            $openAiResponse['provider'] = 'openai';
            $openAiResponse['openai_configured'] = true;
            $openAiResponse['fallback_reason'] = null;
            $openAiResponse['openai_error_category'] = null;
            $openAiResponse['openai_http_status'] = null;
            $openAiResponse['openai_error_message_safe'] = null;
            return aiCopilotEnhanceDraftResponse($openAiResponse, $role, $mode, $message, $context);
        }
        $fallbackReason = 'openai_error';
        $errorCategory = $openAiAttempt['error_category'] ?? 'unknown_openai_error';
        aiCopilotLogOpenAiFailure(
            $requestId,
            $role,
            $mode,
            aiCopilotCleanText((string) ($openAiAttempt['model'] ?? '')),
            $errorCategory,
            isset($openAiAttempt['http_status']) && is_numeric($openAiAttempt['http_status']) ? (int) $openAiAttempt['http_status'] : null
        );
    }

    $fallbackResponse = aiCopilotGenerateFallbackDraft($role, $mode, $message, $context);
    $fallbackResponse['engine'] = 'fallback';
    $fallbackResponse['provider'] = 'local_fallback';
    $fallbackResponse['model'] = null;
    $fallbackResponse['openai_configured'] = $openAiConfigured;
    $fallbackResponse['token_usage'] = null;
    $fallbackResponse['estimated_cost_usd'] = null;
    $fallbackResponse['cost_note'] = null;
    $fallbackResponse['fallback_reason'] = $fallbackReason;
    $fallbackResponse['error_category'] = $errorCategory;
    $fallbackResponse['openai_error_category'] = $openAiAttempt['error_category'] ?? null;
    $fallbackResponse['openai_http_status'] = $openAiAttempt['http_status'] ?? null;
    $fallbackResponse['openai_error_message_safe'] = $openAiAttempt['error_message_safe'] ?? null;
    return aiCopilotEnhanceDraftResponse($fallbackResponse, $role, $mode, $message, $context);
}

function aiCopilotGenerateOpenAiDraft(string $requestId, string $apiKey, string $role, string $mode, string $message, array $chatHistory, array $context, array $modeConfig): array
{
    $model = aiCopilotReadEnv('OPENAI_MODEL');
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    if (!function_exists('curl_init')) {
        return aiCopilotBuildOpenAiFailure('curl_unavailable', $model);
    }

    $baseUrl = aiCopilotReadEnv('OPENAI_BASE_URL');
    if ($baseUrl === '') {
        $baseUrl = 'https://api.openai.com/v1';
    }

    $llmContext = aiCopilotBuildLlmContext($role, $mode, $context, $message);
    $llmInstruction = aiCopilotBuildLlmRequestInstruction($role, $mode, $context, $message);
    $llmContextJson = json_encode($llmContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($llmContextJson) || $llmContextJson === '') {
        return aiCopilotBuildOpenAiFailure('response_parse_error', $model);
    }

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are Medical Co-Pilot, a beta OpenEMR demo assistant for read-only clinical decision support. Use the user message as the main instruction and answer the request first. Stay grounded in the supplied chart context JSON. If requested data exists in context, include it visibly. If requested data is missing, say it is missing and suggest checking the source chart or labs. Do not let safety language replace the answer body. Never claim final diagnosis certainty. Never write to the chart, submit orders, prescribe, finalize diagnosis, submit claims, or suggest upcoding. If no patient is selected, say the answer is general only.',
        ],
        [
            'role' => 'system',
            'content' => aiCopilotRoleSystemInstruction($role),
        ],
        [
            'role' => 'system',
            'content' => 'Mode guidance: ' . ($modeConfig['title'] ?? $mode) . '. ' . ($modeConfig['style'] ?? ''),
        ],
        [
            'role' => 'system',
            'content' => aiCopilotBuildStructuredFormatInstruction($mode),
        ],
        [
            'role' => 'system',
            'content' => 'Compact chart/demo context JSON: ' . $llmContextJson,
        ],
        [
            'role' => 'system',
            'content' => 'Additional request instruction: ' . $llmInstruction,
        ],
    ];

    foreach (aiCopilotBuildLlmChatHistory($chatHistory) as $historyMessage) {
        $messages[] = $historyMessage;
    }

    $messages[] = [
        'role' => 'user',
        'content' => $message,
    ];

    $requestBody = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => 720,
    ];

    $curl = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
    if ($curl === false) {
        return aiCopilotBuildOpenAiFailure('curl_error', $model);
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($responseBody === false || $curlError !== '') {
        return aiCopilotBuildOpenAiFailure('curl_error', $model);
    }

    if (!is_string($responseBody) || $responseBody === '') {
        return aiCopilotBuildOpenAiFailure('missing_content', $model);
    }

    if ($httpCode >= 400) {
        return aiCopilotBuildOpenAiFailure(
            aiCopilotDetectOpenAiErrorCategory($responseBody, $httpCode),
            $model,
            $httpCode
        );
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return aiCopilotBuildOpenAiFailure('invalid_json', $model, $httpCode > 0 ? $httpCode : null);
    }

    $assistantText = $decoded['choices'][0]['message']['content'] ?? '';
    if (!is_string($assistantText) || trim($assistantText) === '') {
        return aiCopilotBuildOpenAiFailure('missing_content', aiCopilotCleanText((string) ($decoded['model'] ?? $model)), $httpCode > 0 ? $httpCode : null);
    }

    $structured = aiCopilotDecodeStructuredAssistantResponse($assistantText, $mode);
    if ($structured === []) {
        $structured = aiCopilotSalvagePlainTextAssistantResponse($assistantText);
        if ($structured === []) {
            return aiCopilotBuildOpenAiFailure('response_parse_error', aiCopilotCleanText((string) ($decoded['model'] ?? $model)), $httpCode > 0 ? $httpCode : null);
        }
        $structured['openai_response_format'] = 'plain_text_salvaged';
    } else {
        $structured['openai_response_format'] = 'structured_json';
    }

    $structured['model'] = aiCopilotCleanText((string) ($decoded['model'] ?? $model));
    $structured['provider'] = 'openai';
    $structured['openai_configured'] = true;
    $structured['token_usage'] = aiCopilotNormalizeTokenUsage($decoded['usage'] ?? null);
    $structured['estimated_cost_usd'] = null;
    $structured['cost_note'] = $structured['token_usage'] !== null ? 'Token usage captured; cost estimate not configured.' : null;

    return [
        'ok' => true,
        'response' => $structured,
    ];
}

function aiCopilotSalvagePlainTextAssistantResponse(string $assistantText): array
{
    $text = aiCopilotCleanMultilineText($assistantText);
    if ($text === '') {
        return [];
    }

    return [
        'answer' => $text,
        'sections' => [],
        'tags' => [],
    ];
}

function aiCopilotBuildOpenAiFailure(string $errorCategory, string $model, ?int $httpStatus = null): array
{
    $category = in_array($errorCategory, [
        'curl_unavailable',
        'curl_error',
        'http_error',
        'invalid_json',
        'missing_content',
        'response_parse_error',
        'context_too_large',
        'unknown_openai_error',
    ], true) ? $errorCategory : 'unknown_openai_error';

    return [
        'ok' => false,
        'error_category' => $category,
        'http_status' => $httpStatus,
        'error_message_safe' => aiCopilotBuildOpenAiSafeErrorMessage($category, $httpStatus),
        'model' => $model,
    ];
}

function aiCopilotDetectOpenAiErrorCategory(string $responseBody, int $httpStatus): string
{
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return $httpStatus === 413 ? 'context_too_large' : 'http_error';
    }

    $message = strtolower(aiCopilotCleanText((string) ($decoded['error']['message'] ?? '')));
    $code = strtolower(aiCopilotCleanText((string) ($decoded['error']['code'] ?? '')));

    if (
        $httpStatus === 413
        || str_contains($code, 'context_length')
        || str_contains($message, 'maximum context length')
        || str_contains($message, 'context length')
        || str_contains($message, 'too many tokens')
    ) {
        return 'context_too_large';
    }

    return 'http_error';
}

function aiCopilotBuildOpenAiSafeErrorMessage(string $errorCategory, ?int $httpStatus = null): string
{
    return match ($errorCategory) {
        'curl_unavailable' => 'cURL is unavailable in the PHP runtime, so OpenAI could not be called.',
        'curl_error' => 'The OpenAI request failed during transport before a valid response was received.',
        'http_error' => 'OpenAI returned an HTTP ' . ($httpStatus ?? 'error') . ' response while generating this draft.',
        'invalid_json' => 'OpenAI returned a response that could not be parsed as JSON.',
        'missing_content' => 'OpenAI returned no assistant content for this request.',
        'response_parse_error' => 'OpenAI returned content that did not match the expected structured response format.',
        'context_too_large' => 'OpenAI rejected the request because the prompt context exceeded the provider limit.',
        default => 'OpenAI failed for an unknown reason while generating this draft.',
    };
}

function aiCopilotLogOpenAiFailure(string $requestId, string $role, string $mode, string $model, string $errorCategory, ?int $httpStatus = null): void
{
    error_log(sprintf(
        '[OpenEMR Clinical Co-Pilot] openai_failure request_id=%s role=%s mode=%s model=%s error_category=%s http_status=%s',
        $requestId,
        $role,
        $mode,
        $model !== '' ? $model : 'unknown_model',
        $errorCategory !== '' ? $errorCategory : 'unknown_openai_error',
        $httpStatus !== null ? (string) $httpStatus : 'null'
    ));
}

function aiCopilotBuildStructuredFormatInstruction(string $mode): string
{
    $instruction = match ($mode) {
        'differential_diagnosis' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Likely considerations (tone neutral), Red flags (tone red), Key gaps (tone yellow), Next steps / clarification (tone neutral). Each section must contain an items array of short bullet strings.',
        'medication_info' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Current Medication Information, Clinical review considerations, Monitoring considerations, Patient counseling points. The Current Medication Information section must reflect retrieved medication rows from chart context when they exist. Use tone neutral unless a clear caution deserves tone yellow.',
        'clinical_notes' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Draft note, Subjective, Objective, Assessment, Plan. Keep content concise and chart-style.',
        'treatment_plan' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Draft Treatment Plan, Monitoring and safety checks, Patient education considerations, Follow-up considerations, Safety precautions.',
        'billing' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Billing documentation summary, Possible coding considerations, Missing documentation, Risk / compliance reminders.',
        'follow_up' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Follow-up timeframe, What to monitor, Patient instructions, Escalation precautions, Care coordination.',
        'visit_summary' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Visit summary, Key concerns addressed, Plan discussed, Follow-up instructions, Patient-friendly explanation.',
        'patient_education' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Patient-friendly explanation, Safety reminders.',
        'rag_chart_context' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: RAG Visit History Summary, Recommended clinician review points, Retrieval notes. Use retrieved chart context only and do not invent facts.',
        'latest_ambient_summary' => 'Return JSON only with keys: answer, sections, tags. Use sections appropriate to role and the latest approved ambient encounter only. For doctor, begin with Latest Ambient Encounter Summary. For nurse, focus on care coordination, adherence, and follow-up. For billing, focus on insurance or documentation-safe follow-up only. For front desk, focus on minimum-necessary administrative follow-up only.',
        'billing_review' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Plain-language issue, What to check first, Guardrails.',
        'appointment_info' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Appointment details, Check-in instructions.',
        'patient_contact' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Contact details, Contact workflow reminders.',
        'send_reminder' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Reminder draft, Delivery details.',
        'front_desk_summary' => 'Return JSON only with keys: answer, sections, tags. Use sections in this order: Administrative summary, Next appointment, Contact details.',
        default => 'Return JSON only with keys: answer, sections, tags. Use a few short sections that best fit the user request.',
    };

    return $instruction . ' Tags should be a short array of compact clinical labels. Include a brief statement in answer that the draft must be verified by a licensed clinician.';
}

function aiCopilotDecodeStructuredAssistantResponse(string $assistantText, string $mode): array
{
    $candidate = trim($assistantText);
    if (str_starts_with($candidate, '```')) {
        $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $candidate) ?? $candidate;
        $candidate = trim($candidate);
    }

    $decoded = json_decode($candidate, true);
    if (!is_array($decoded)) {
        $jsonStart = strpos($candidate, '{');
        $jsonEnd = strrpos($candidate, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $fragment = substr($candidate, $jsonStart, $jsonEnd - $jsonStart + 1);
            $decoded = json_decode($fragment, true);
        }
    }

    if (!is_array($decoded)) {
        return [];
    }

    return aiCopilotNormalizeStructuredResponse($decoded, $mode);
}

function aiCopilotNormalizeStructuredResponse(array $response, string $mode): array
{
    $answer = aiCopilotNormalizePrompt($response['answer'] ?? '');
    $sections = [];
    $rawSections = $response['sections'] ?? [];
    if (is_array($rawSections)) {
        foreach ($rawSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = aiCopilotNormalizePrompt($section['title'] ?? '');
            $tone = strtolower(aiCopilotNormalizePrompt($section['tone'] ?? 'neutral'));
            $items = [];
            foreach (($section['items'] ?? []) as $item) {
                $itemText = aiCopilotNormalizePrompt($item);
                if ($itemText !== '') {
                    $items[] = $itemText;
                }
            }

            if ($title === '' || $items === []) {
                continue;
            }

            if (!in_array($tone, ['neutral', 'red', 'yellow'], true)) {
                $tone = 'neutral';
            }

            $sections[] = [
                'title' => $title,
                'tone' => $tone,
                'items' => array_slice($items, 0, 6),
            ];
        }
    }

    $tags = [];
    foreach (($response['tags'] ?? []) as $tag) {
        $tagText = aiCopilotNormalizePrompt($tag);
        if ($tagText !== '') {
            $tags[] = $tagText;
        }
    }
    $tags = aiCopilotFinalizeTags($tags);

    if ($answer === '' && $sections === []) {
        return [];
    }

    if ($answer === '') {
        $answer = 'Beta clinical support draft for review by a licensed clinician.';
    }

    return [
        'answer' => $answer,
        'sections' => $sections,
        'tags' => $tags,
    ];
}

function aiCopilotAttachClientAmbientVisitContext(array $context, mixed $value, string $role): array
{
    $ambientVisit = aiCopilotNormalizeAmbientVisitContext($value);
    if ($ambientVisit === []) {
        return $context;
    }

    $context['approved_ambient_visit'] = $ambientVisit;
    $context['latest_approved_ambient_encounter'] = $ambientVisit;
    return $context;
}

function aiCopilotNormalizeAmbientVisitContext(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $approvedNotes = [];
    foreach (($value['approvedNotes'] ?? []) as $note) {
        $noteText = aiCopilotCleanText((string) $note);
        if ($noteText !== '') {
            $approvedNotes[] = $noteText;
        }
    }

    $summary = aiCopilotCleanText((string) ($value['summary'] ?? ''));
    if ($summary === '' && $approvedNotes === []) {
        return [];
    }

    $badges = [];
    foreach (($value['badges'] ?? []) as $badge) {
        $badgeText = aiCopilotCleanText((string) $badge);
        if ($badgeText !== '') {
            $badges[] = $badgeText;
        }
    }

    $tableRow = is_array($value['tableRow'] ?? null) ? $value['tableRow'] : [];

    return [
        'id' => aiCopilotCleanText((string) ($value['id'] ?? '')),
        'draft_id' => aiCopilotCleanText((string) ($value['draftId'] ?? '')),
        'approved_at' => aiCopilotCleanText((string) ($value['approvedAt'] ?? '')),
        'approved_at_label' => aiCopilotCleanText((string) ($value['approvedAtLabel'] ?? '')),
        'title' => aiCopilotCleanText((string) ($value['title'] ?? 'AI-Assisted Visit Review')),
        'visit_type' => aiCopilotCleanText((string) ($value['visitType'] ?? 'Ambient Encounter Capture')),
        'summary' => $summary,
        'approved_notes' => array_slice($approvedNotes, 0, 12),
        'badges' => array_slice($badges, 0, 6),
        'table_row' => [
            'date' => aiCopilotCleanText((string) ($tableRow['date'] ?? '')),
            'issue' => aiCopilotCleanText((string) ($tableRow['issue'] ?? '')),
            'reason' => aiCopilotCleanText((string) ($tableRow['reason'] ?? '')),
            'form' => aiCopilotCleanText((string) ($tableRow['form'] ?? '')),
            'provider' => aiCopilotCleanText((string) ($tableRow['provider'] ?? '')),
            'billing' => aiCopilotCleanText((string) ($tableRow['billing'] ?? '')),
            'insurance' => aiCopilotCleanText((string) ($tableRow['insurance'] ?? '')),
        ],
        'approved_item_count' => isset($value['approvedItemCount']) && is_numeric($value['approvedItemCount']) ? (int) $value['approvedItemCount'] : count($approvedNotes),
        'review_status' => aiCopilotCleanText((string) ($value['reviewStatus'] ?? 'Clinician Reviewed')),
        'consent_confirmed' => !empty($value['consentConfirmed']),
        'source' => aiCopilotCleanText((string) ($value['source'] ?? 'Consent-Based AI Visit Capture')),
    ];
}

function aiCopilotEnhanceDraftResponse(array $draft, string $role, string $mode, string $message, array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return $draft;
    }

    $normalizedMessage = strtolower($message);

    if ($role === 'front_desk' && preg_match('/\b(everything about|all chart data|full history|entire chart)\b/i', $message) === 1) {
        return $draft;
    }

    if (
        $role === 'doctor'
        && ($draft['engine'] ?? '') === 'fallback'
        && preg_match('/(visit history|what changed since).*(ai-assisted encounter|ambient encounter capture)|last ai-assisted encounter/', $normalizedMessage) === 1
    ) {
        return aiCopilotBuildFallbackRagVisitHistoryResponse($context, $draft);
    }

    if ($role === 'doctor' && preg_match('/what sources did you use|sources did you use|retrieval notes|what chart context did you use/', $normalizedMessage) === 1) {
        return aiCopilotBuildSourcesExplanationResponse($context, $draft);
    }

    if (
        $mode === 'latest_ambient_summary'
        || preg_match('/summarize latest ambient encounter only|latest ambient encounter only|latest ambient encounter|ambient encounter only|latest ai-assisted visit review|latest approved ambient encounter/', $normalizedMessage) === 1
    ) {
        return aiCopilotBuildLatestAmbientSummaryResponse($context, $draft, $role);
    }

    if ($role === 'doctor' && preg_match('/what changed since the last visit|what changed since last visit/', $normalizedMessage) === 1) {
        return aiCopilotBuildWhatChangedSinceLastVisitResponse($context, $draft);
    }

    if (
        $role === 'doctor'
        && str_contains($normalizedMessage, 'troponin')
        && !aiCopilotContextContainsKeywords($context, ['troponin'])
    ) {
        return aiCopilotBuildMissingLabResultResponse($context, 'Troponin', $draft);
    }

    if ($role === 'doctor' && $mode === 'medication_info') {
        return aiCopilotEnsureMedicationSummaryResponse($draft, $context);
    }

    if ($role === 'doctor' && $mode === 'treatment_plan') {
        return aiCopilotEnsureTreatmentPlanResponse($draft, $context, $message);
    }

    if ($role === 'doctor' && $mode === 'rag_chart_context') {
        return aiCopilotEnsureRagChartContextResponse($draft, $context, $message);
    }

    if (in_array($role, ['doctor', 'billing'], true) && preg_match('/payment due|next payment due|balance due|patient balance|insurance balance|what does .* owe|insurance on file/', $normalizedMessage) === 1) {
        return aiCopilotBuildBillingPaymentDueResponse($context, $draft);
    }

    if ($role === 'front_desk' && preg_match('/contact info|contact information|outreach|phone|email/', $normalizedMessage) === 1) {
        return aiCopilotBuildFrontDeskContactContextResponse($context, $draft);
    }

    return $draft;
}

function aiCopilotAnswerLooksGeneric(array $draft, string $mode, array $context): bool
{
    $text = strtolower(aiCopilotCleanText(($draft['answer'] ?? '') . "\n" . implode("\n", array_map(static function ($section) {
        if (!is_array($section)) {
            return '';
        }
        return aiCopilotCleanText(($section['title'] ?? '') . ' ' . implode(' ', $section['items'] ?? []));
    }, $draft['sections'] ?? []))));

    if ($text === '') {
        return true;
    }

    $patientName = strtolower(aiCopilotCleanText($context['patient']['name'] ?? ''));
    $hasPatientName = $patientName !== '' && str_contains($text, $patientName);

    return match ($mode) {
        'medication_info' => !str_contains($text, 'current medication information') || !$hasPatientName,
        'treatment_plan' => !str_contains($text, 'draft treatment plan'),
        'rag_chart_context' => !str_contains($text, 'visit history') && !str_contains($text, 'ambient encounter'),
        'latest_ambient_summary' => !str_contains($text, 'ambient encounter') && !str_contains($text, 'ai-assisted visit review'),
        default => !$hasPatientName && preg_match('/draft only|human review required|licensed clinician/', $text) === 1,
    };
}

function aiCopilotEnsureMedicationSummaryResponse(array $draft, array $context): array
{
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $facts = aiCopilotExtractClinicalFacts($context);
    $medicationSection = aiCopilotBuildSection('Current Medication Information', aiCopilotBuildMedicationInformationRows($context));
    $clinicalReviewItems = aiCopilotExtractSectionItemsByKeywords($draft['sections'] ?? [], ['safety check', 'clinical review']);
    $monitoringItems = aiCopilotExtractSectionItemsByKeywords($draft['sections'] ?? [], ['monitoring consideration']);
    $counselingItems = aiCopilotExtractSectionItemsByKeywords($draft['sections'] ?? [], ['patient counseling', 'counseling']);

    $sections = [
        $medicationSection,
        aiCopilotBuildSection(
            'Clinical review considerations',
            $clinicalReviewItems !== [] ? $clinicalReviewItems : aiCopilotBuildMedicationClinicalReviewItems($context, $facts)
        ),
        aiCopilotBuildSection(
            'Monitoring considerations',
            $monitoringItems !== [] ? $monitoringItems : aiCopilotBuildMedicationMonitoringItems($context, $facts)
        ),
        aiCopilotBuildSection(
            'Patient counseling points',
            $counselingItems !== [] ? $counselingItems : aiCopilotBuildMedicationCounselingItems($context, $facts)
        ),
    ];

    $draft['answer'] = 'The following medication information for ' . $patientName . ' must be verified by a licensed clinician.';
    $draft['sections'] = $sections;
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Medication review', 'Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotEnsureTreatmentPlanResponse(array $draft, array $context, string $message): array
{
    $facts = aiCopilotExtractClinicalFacts($context);
    $ambientVisit = is_array($context['approved_ambient_visit'] ?? null) ? $context['approved_ambient_visit'] : [];
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');

    $planItems = [
        'The retrieved OpenEMR context does not contain a finalized treatment plan for ' . $patientName . ', so this is a safe draft framework based only on available chart context.',
        'Problems currently shaping the draft plan: ' . aiCopilotFallbackValue(aiCopilotJoinList($facts['conditions']), 'No structured problems were found in the retrieved context.'),
    ];
    if ($facts['follow_up_considerations'] !== '') {
        $planItems[] = 'Follow-up context already documented: ' . $facts['follow_up_considerations'];
    }
    if (!empty($ambientVisit['summary'])) {
        $planItems[] = 'Latest approved Ambient Encounter Capture context: ' . $ambientVisit['summary'];
    }

    $monitoringItems = [
        'Review recent vitals and lab context before finalizing clinician-directed next steps: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$facts['vitals_line'], $facts['recent_labs']]), 'Recent vitals or lab summary were not found in retrieved context.'),
        'Reassess symptom severity, medication adherence, and whether the latest visit-history documentation changes urgency or follow-up timing.',
    ];

    $educationItems = [
        'Use patient education language that reinforces the documented plan, warning signs, and follow-up expectations without creating a new order set.',
    ];
    if (aiCopilotContextContainsKeywords($context, ['written medication instructions', 'afternoon phone reminders'])) {
        $educationItems[] = 'Care preferences already documented in the chart context should be considered when explaining the plan.';
    }

    $followUpItems = [
        aiCopilotFallbackValue($facts['follow_up_considerations'], 'Define short-interval review versus routine follow-up using the existing chart context only.'),
        'Review whether the latest visit history or approved ambient encounter adds new coordination needs before finalizing follow-up.',
    ];

    $safetyItems = [
        'Draft only. Human review required. Do not treat this draft as an order, prescription, or final clinical decision.',
        'If requested data is missing or the chart context is incomplete, verify the source chart before acting.',
    ];

    $draft['answer'] = 'Draft treatment plan for ' . $patientName . ': this summary is grounded in retrieved chart context and must be verified by a licensed clinician.';
    $draft['sections'] = [
        aiCopilotBuildSection('Draft Treatment Plan', $planItems),
        aiCopilotBuildSection('Monitoring and safety checks', $monitoringItems),
        aiCopilotBuildSection('Patient education considerations', $educationItems),
        aiCopilotBuildSection('Follow-up considerations', $followUpItems),
        aiCopilotBuildSection('Safety precautions', $safetyItems),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Follow-up', 'Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotEnsureRagChartContextResponse(array $draft, array $context, string $message): array
{
    $normalizedMessage = strtolower($message);

    if (preg_match('/what sources did you use|sources did you use|retrieval notes|what chart context did you use/', $normalizedMessage) === 1) {
        return aiCopilotBuildSourcesExplanationResponse($context, $draft);
    }

    if (preg_match('/summarize latest ambient encounter only|latest ambient encounter only|latest approved ambient encounter only/', $normalizedMessage) === 1) {
        return aiCopilotBuildLatestAmbientSummaryResponse($context, $draft, aiCopilotCleanText((string) ($context['role'] ?? 'doctor')));
    }

    if (preg_match('/what changed since the last visit|what changed since last visit/', $normalizedMessage) === 1) {
        return aiCopilotBuildWhatChangedSinceLastVisitResponse($context, $draft);
    }

    if (preg_match('/what changed since.*ai-assisted encounter|visit history/', $normalizedMessage) === 1 || aiCopilotAnswerLooksGeneric($draft, 'rag_chart_context', $context)) {
        return aiCopilotBuildFallbackRagVisitHistoryResponse($context, $draft);
    }

    return $draft;
}

function aiCopilotBuildMissingLabResultResponse(array $context, string $labName, array $draft): array
{
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $facts = aiCopilotExtractClinicalFacts($context);
    $availableLabContext = aiCopilotFallbackValue($facts['recent_labs'], 'No other recent lab summary was found in the retrieved chart context.');

    $draft['answer'] = $labName . ' was not found in the retrieved OpenEMR context for ' . $patientName . '. Draft only. Human review required.';
    $draft['sections'] = [
        aiCopilotBuildSection('Retrieved chart check', [
            $labName . ' was not found in the retrieved OpenEMR context for ' . $patientName . '.',
            'Other available lab context: ' . $availableLabContext,
        ]),
        aiCopilotBuildSection('Next verification step', [
            'Check the source chart or lab results to verify whether ' . strtolower($labName) . ' was ordered, resulted, or documented outside the retrieved demo context.',
        ]),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotBuildFallbackRagVisitHistoryResponse(array $context, array $draft): array
{
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $ambientVisit = is_array($context['approved_ambient_visit'] ?? null) ? $context['approved_ambient_visit'] : [];
    $facts = aiCopilotExtractClinicalFacts($context);

    if ($ambientVisit === []) {
        $draft['answer'] = 'No approved Ambient Encounter Capture visit-history record was found yet. Complete the consent-based listening workflow and approve the visit draft to make that context available for retrieval.';
        $draft['sections'] = [
            aiCopilotBuildSection('Retrieved chart context', [
                'Active medications in retrieved chart context: ' . aiCopilotFallbackValue($facts['medication_line'], 'No active medications were found.'),
                'Recent lab and vitals context: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$facts['recent_labs'], $facts['vitals_line']]), 'No recent vitals or lab summary was found.'),
                'Insurance / follow-up context: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$facts['billing_support'], $facts['follow_up_considerations']]), 'No extra billing or follow-up note was found.'),
            ]),
            aiCopilotBuildSection('Next retrieval step', [
                'Complete the ambient encounter capture flow, review the draft, and approve it to make the latest AI-assisted visit available in visit-history retrieval.',
            ]),
        ];
        $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Visit summary', 'Chart context', 'Review needed']));
        return $draft;
    }

    $approvedNotes = array_slice(array_map(static fn($item) => aiCopilotCleanText((string) $item), $ambientVisit['approved_notes'] ?? []), 0, 6);

    $draft['answer'] = 'Using Doctor-role chart retrieval, I found relevant context from ' . $patientName . '\'s medication history, lab follow-up, visit history, insurance note, immunization review, and care preferences. The latest approved Ambient Encounter Capture visit suggests the next clinical review should focus on medication adherence support, A1C/lipid follow-up, insurance verification, immunization status verification, and care coordination preferences.';
    $draft['sections'] = [
        aiCopilotBuildSection('Draft Clinical Summary', [
            $patientName . '\'s recent chart context indicates a routine follow-up pattern centered on medication adherence, lab follow-up, vitals review, insurance verification, immunization review, and care support.',
            'The most recent AI-assisted visit was clinician-reviewed and consent-confirmed before being added to the demo visit history.',
            $ambientVisit['summary'] ?? '',
        ]),
        aiCopilotBuildSection('Recommended clinician review points', [
            'Confirm current medication adherence and whether evening reminders are helping.',
            'Verify A1C and lipid panel follow-up status.',
            'Confirm whether insurance verification has been completed.',
            'Review immunization status before updating the record.',
            'Confirm care preferences and whether the daughter should be added as a care support contact.',
            'Review the latest Ambient Encounter Capture visit note in Visit History.',
        ]),
        aiCopilotBuildSection('Latest Approved Ambient Encounter Capture', $approvedNotes !== [] ? $approvedNotes : [
            'An approved ambient encounter capture record is present in the local demo visit-history state.',
        ]),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Visit summary', 'Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotBuildWhatChangedSinceLastVisitResponse(array $context, array $draft): array
{
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $facts = aiCopilotExtractClinicalFacts($context);
    $ambientVisit = is_array($context['approved_ambient_visit'] ?? null) ? $context['approved_ambient_visit'] : [];

    $changes = [];
    if (!empty($ambientVisit['summary'])) {
        $changes[] = 'The latest approved AI-assisted encounter adds clinician-reviewed detail about medication adherence, insurance verification, lab follow-up, and care coordination preferences.';
    } else {
        $changes[] = 'No approved AI-assisted encounter was found in the retrieved context, so the comparison relies on standard visit history and chart notes only.';
    }

    if ($facts['medication_concerns'] !== '') {
        $changes[] = 'Medication review context: ' . $facts['medication_concerns'];
    }
    if ($facts['recent_labs'] !== '') {
        $changes[] = 'Lab / vitals context to compare: ' . aiCopilotJoinParts([$facts['recent_labs'], $facts['vitals_line']]);
    }
    if ($facts['billing_support'] !== '') {
        $changes[] = 'Insurance or billing-related context now noted: ' . $facts['billing_support'];
    }
    if (aiCopilotContextContainsKeywords($context, ['written medication instructions', 'afternoon phone reminders', 'daughter'])) {
        $changes[] = 'Care preference and support-contact details are now part of the retrieved chart context.';
    }

    $missing = [];
    if (empty($ambientVisit['summary'])) {
        $missing[] = 'No approved Ambient Encounter Capture visit-history record was available for direct comparison.';
    }
    if ($facts['recent_note_subjective'] === '' && $facts['recent_note_assessment'] === '') {
        $missing[] = 'The retrieved context does not contain enough prior-note detail to compare every clinical element line by line.';
    }

    $draft['answer'] = 'RAG Visit History Summary for ' . $patientName . ': retrieved visit-history and chart context were compared before drafting this answer.';
    $draft['sections'] = [
        aiCopilotBuildSection('RAG Visit History Summary', $changes),
        aiCopilotBuildSection('Comparison gaps', $missing !== [] ? $missing : [
            'No major comparison gaps were detected in the retrieved visit-history context used for this draft.',
        ]),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Visit summary', 'Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotBuildSourcesExplanationResponse(array $context, array $draft): array
{
    $sources = aiCopilotBuildSources($context, 'rag_chart_context');
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');

    $draft['answer'] = 'RAG means the Co-Pilot retrieved role-appropriate chart context for ' . $patientName . ' before drafting the response. These are the source categories currently used for grounding.';
    $draft['sections'] = [
        aiCopilotBuildSection('Retrieved source categories', $sources),
        aiCopilotBuildSection('Retrieval note', [
            'The Co-Pilot uses retrieved chart context before drafting instead of answering from uncited memory.',
            'This explanation lists source categories only and does not dump the full chart.',
        ]),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotBuildLatestAmbientEncounterOnlyResponse(array $context, array $draft): array
{
    return aiCopilotBuildLatestAmbientSummaryResponse($context, $draft, aiCopilotCleanText((string) ($context['role'] ?? 'doctor')));
}

function aiCopilotLatestAmbientVisitFromContext(array $context): array
{
    if (is_array($context['latest_approved_ambient_encounter'] ?? null) && !empty($context['latest_approved_ambient_encounter'])) {
        return $context['latest_approved_ambient_encounter'];
    }

    return is_array($context['approved_ambient_visit'] ?? null) ? $context['approved_ambient_visit'] : [];
}

function aiCopilotAmbientNotesMatching(array $ambientVisit, array $keywords): array
{
    $matches = [];
    foreach (array_slice($ambientVisit['approved_notes'] ?? [], 0, 12) as $note) {
        $noteText = aiCopilotCleanText((string) $note);
        if ($noteText === '') {
            continue;
        }

        $normalizedNote = strtolower($noteText);
        foreach ($keywords as $keyword) {
            if (str_contains($normalizedNote, strtolower($keyword))) {
                $matches[] = $noteText;
                break;
            }
        }
    }

    return array_slice(array_values(array_unique($matches)), 0, 6);
}

function aiCopilotBuildLatestAmbientSummaryResponse(array $context, array $draft, string $role): array
{
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $ambientVisit = aiCopilotLatestAmbientVisitFromContext($context);
    $resolvedRole = in_array($role, ['doctor', 'nurse', 'billing', 'front_desk'], true) ? $role : 'doctor';

    if ($ambientVisit === []) {
        $draft['answer'] = 'No approved Ambient Encounter Capture visit-history record was found yet for ' . $patientName . '. Complete the consent-based listening workflow and approve the visit draft to make that context available for retrieval.';
        $draft['sections'] = [
            aiCopilotBuildSection('Latest Approved Ambient Encounter Capture', [
                'No approved ambient encounter summary is currently available in the retrieved context.',
            ]),
        ];
        $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Visit summary', 'Chart context', 'Review needed']));
        return $draft;
    }

    $approvedAt = aiCopilotFirstNonEmpty([
        aiCopilotCleanText((string) ($ambientVisit['approved_at_label'] ?? '')),
        aiCopilotCleanText((string) ($ambientVisit['approved_at'] ?? '')),
    ]);
    $visitType = aiCopilotFirstNonEmpty([
        aiCopilotCleanText((string) ($ambientVisit['visit_type'] ?? '')),
        'Ambient Encounter Capture',
    ]);
    $reviewStatus = aiCopilotFirstNonEmpty([
        aiCopilotCleanText((string) ($ambientVisit['review_status'] ?? '')),
        'Clinician Reviewed',
    ]);
    $summary = aiCopilotCleanText((string) ($ambientVisit['summary'] ?? ''));
    $approvedNotes = array_slice(array_values(array_filter(array_map('aiCopilotCleanText', $ambientVisit['approved_notes'] ?? []))), 0, 8);
    $tableRow = is_array($ambientVisit['table_row'] ?? null) ? $ambientVisit['table_row'] : [];

    if ($resolvedRole === 'front_desk') {
        $adminItems = array_values(array_filter(array_merge(
            aiCopilotAmbientNotesMatching($ambientVisit, ['insurance', 'care preference', 'appointment', 'reminder', 'outreach', 'written medication instructions', 'afternoon phone reminders']),
            [
                !empty($tableRow['insurance']) ? 'Insurance follow-up: ' . aiCopilotCleanText((string) $tableRow['insurance']) : '',
                !empty($tableRow['date']) ? 'Upcoming visit timing to confirm: ' . aiCopilotCleanText((string) $tableRow['date']) : '',
            ]
        )));

        $draft['answer'] = 'Administrative follow-up summary for the latest approved ambient encounter for ' . $patientName . '. Minimum necessary PHI only.';
        $draft['sections'] = [
            aiCopilotBuildSection('Latest Ambient Encounter Summary', array_values(array_filter([
                $approvedAt !== '' ? 'Approved date / time: ' . $approvedAt : '',
                'Review status: ' . $reviewStatus,
                $summary !== '' ? 'Administrative routing note: confirm follow-up tasks tied to the latest approved encounter.' : '',
            ]))),
            aiCopilotBuildSection('Administrative workflow only', $adminItems !== [] ? $adminItems : [
                'Confirm appointment reminders, insurance verification follow-up, and preferred outreach workflow before contacting the patient.',
                'Route clinical questions from the ambient encounter to clinical staff.',
            ]),
        ];
        $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Front desk', 'Minimum PHI', 'Review needed']));
        return $draft;
    }

    if ($resolvedRole === 'billing') {
        $billingItems = array_values(array_filter(array_merge(
            aiCopilotAmbientNotesMatching($ambientVisit, ['insurance', 'verification']),
            [
                !empty($tableRow['billing']) ? 'Billing follow-up: ' . aiCopilotCleanText((string) $tableRow['billing']) : '',
                !empty($tableRow['insurance']) ? 'Insurance follow-up: ' . aiCopilotCleanText((string) $tableRow['insurance']) : '',
                aiCopilotCleanText((string) ($ambientVisit['source'] ?? '')) !== '' ? 'Source: ' . aiCopilotCleanText((string) $ambientVisit['source']) : '',
            ]
        )));

        $draft['answer'] = 'Billing-safe summary of the latest approved ambient encounter for ' . $patientName . '. Billing draft only. Human review required.';
        $draft['sections'] = [
            aiCopilotBuildSection('Latest Ambient Encounter Summary', array_values(array_filter([
                $approvedAt !== '' ? 'Approved date / time: ' . $approvedAt : '',
                'Visit type: ' . $visitType,
                'Review status: ' . $reviewStatus,
            ]))),
            aiCopilotBuildSection('Billing and insurance follow-up', $billingItems !== [] ? $billingItems : [
                'Review insurance verification and any documentation gaps referenced in the approved ambient encounter before billing follow-up.',
            ]),
        ];
        $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Billing review', 'Chart context', 'Review needed']));
        return $draft;
    }

    if ($resolvedRole === 'nurse') {
        $nurseItems = array_values(array_filter(array_merge(
            aiCopilotAmbientNotesMatching($ambientVisit, ['medication support', 'care preference', 'care team', 'appointment', 'reminder', 'immunization', 'insurance']),
            [
                $summary !== '' ? 'Clinician-reviewed summary: ' . $summary : '',
            ]
        )));

        $draft['answer'] = 'Nursing follow-up summary for the latest approved ambient encounter for ' . $patientName . '. Draft only. Human review required.';
        $draft['sections'] = [
            aiCopilotBuildSection('Latest Ambient Encounter Summary', array_values(array_filter([
                $approvedAt !== '' ? 'Approved date / time: ' . $approvedAt : '',
                'Visit type: ' . $visitType,
                'Review status: ' . $reviewStatus,
            ]))),
            aiCopilotBuildSection('Care coordination and follow-up', $nurseItems !== [] ? $nurseItems : [
                'Use the latest approved ambient encounter to reinforce adherence questions, follow-up timing, and care coordination items without changing medications.',
            ]),
        ];
        $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Follow-up', 'Chart context', 'Review needed']));
        return $draft;
    }

    $reviewItems = array_values(array_filter(array_merge(
        $approvedNotes,
        aiCopilotAmbientNotesMatching($ambientVisit, ['medication support', 'labs', 'insurance', 'care preference', 'care team', 'appointment', 'reminder', 'immunization'])
    )));

    $draft['answer'] = 'Here is the latest approved Ambient Encounter Capture summary for ' . $patientName . '. Draft only. Clinician review required.';
    $draft['sections'] = [
        aiCopilotBuildSection('Latest Ambient Encounter Summary', array_values(array_filter([
            $approvedAt !== '' ? 'Approved date / time: ' . $approvedAt : '',
            'Visit type: ' . $visitType,
            'Review status: ' . $reviewStatus,
            $summary !== '' ? 'Clinician-reviewed summary: ' . $summary : '',
        ]))),
        aiCopilotBuildSection('Approved visit updates', $reviewItems !== [] ? $reviewItems : [
            'An approved ambient encounter record is present, but no additional approved note detail was found in the retrieved context.',
        ]),
        aiCopilotBuildSection('Clinician review focus', array_values(array_filter([
            aiCopilotAmbientNotesMatching($ambientVisit, ['medication support']) !== [] ? 'Medication adherence or support items were captured in the latest ambient encounter and should be verified before acting.' : '',
            aiCopilotAmbientNotesMatching($ambientVisit, ['labs', 'a1c', 'lipid']) !== [] ? 'Lab follow-up items were captured in the latest ambient encounter and should be reviewed against the source chart.' : '',
            aiCopilotAmbientNotesMatching($ambientVisit, ['insurance']) !== [] ? 'Insurance verification was mentioned in the latest ambient encounter and may affect follow-up coordination.' : '',
            aiCopilotAmbientNotesMatching($ambientVisit, ['appointment', 'reminder']) !== [] ? 'Follow-up appointment or reminder planning was captured in the latest ambient encounter.' : '',
        ]))),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Visit summary', 'Chart context', 'Review needed']));

    return $draft;
}

function aiCopilotBuildBillingPaymentDueResponse(array $context, array $draft): array
{
    $patientName = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $claim = $context['billing']['claim'] ?? [];
    $insurance = $context['primary_insurance'] ?? [];
    $paymentSummary = is_array($context['billing']['payment_summary'] ?? null) ? $context['billing']['payment_summary'] : [];
    $nextPaymentDueDate = aiCopilotCleanText((string) ($paymentSummary['next_payment_due_date'] ?? ''));
    $payer = aiCopilotFirstNonEmpty([
        aiCopilotCleanText((string) ($paymentSummary['payer'] ?? '')),
        aiCopilotCleanText((string) ($insurance['carrier'] ?? '')),
        aiCopilotCleanText((string) ($insurance['plan_name'] ?? '')),
    ]);
    $planName = aiCopilotFirstNonEmpty([
        aiCopilotCleanText((string) ($paymentSummary['plan_name'] ?? '')),
        aiCopilotCleanText((string) ($insurance['plan_name'] ?? '')),
    ]);
    $patientBalanceDue = aiCopilotCleanText((string) ($paymentSummary['patient_balance_due'] ?? ''));
    $insuranceBalanceDue = aiCopilotCleanText((string) ($paymentSummary['insurance_balance_due'] ?? ''));
    $totalBalanceDue = aiCopilotCleanText((string) ($paymentSummary['total_balance_due'] ?? ''));
    $billingProvider = aiCopilotFallbackValue(aiCopilotCleanText((string) ($paymentSummary['billing_provider'] ?? '')), 'No billing provider was found in the retrieved billing context.');
    $paymentNote = aiCopilotFallbackValue(aiCopilotCleanText((string) ($paymentSummary['payment_note'] ?? '')), 'Verify the billing ledger before collection.');
    $insuranceNote = aiCopilotCleanText((string) ($paymentSummary['insurance_note'] ?? ''));
    $status = aiCopilotFallbackValue(aiCopilotCleanText((string) ($claim['status'] ?? '')), 'No claim status was found in the retrieved billing context.');

    if ($nextPaymentDueDate !== '') {
        $draft['answer'] = 'The next patient payment due date for ' . $patientName . ' is ' . $nextPaymentDueDate . '. Billing draft only. Human review required.';
        $draft['sections'] = [
            aiCopilotBuildSection('Billing payment snapshot', [
                'Next patient payment due date: ' . $nextPaymentDueDate,
                'Patient balance due: ' . aiCopilotFallbackValue($patientBalanceDue, 'Not found in retrieved billing context.'),
                'Insurance balance due: ' . aiCopilotFallbackValue($insuranceBalanceDue, 'Not found in retrieved billing context.'),
                'Total balance due: ' . aiCopilotFallbackValue($totalBalanceDue, 'Not found in retrieved billing context.'),
                'Payer / plan: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$payer, $planName]), 'No payer or plan was found in the retrieved billing context.'),
                'Billing provider / facility: ' . $billingProvider,
            ]),
            aiCopilotBuildSection('Billing review reminders', array_values(array_filter([
                'Payment note: ' . $paymentNote,
                $insuranceNote !== '' ? 'Insurance note: ' . $insuranceNote : '',
                'This is synthetic demo billing data. Verify the billing ledger and payer workflow before collection or patient outreach.',
            ]))),
        ];
        $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Billing review', 'Chart context', 'Review needed']));
        return $draft;
    }

    $draft['answer'] = 'The next payment due date was not found in the retrieved OpenEMR billing context. This is a billing-support draft only and still requires human billing review.';
    $draft['sections'] = [
        aiCopilotBuildSection('Available billing context', [
            'Primary payer / plan context: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$payer, $planName]), 'No primary payer was found in the retrieved billing context.'),
            'Claim status context: ' . $status,
        ]),
        aiCopilotBuildSection('What to verify next', [
            'Check the billing ledger, payer portal, or the source claim workflow to confirm whether a payment due date exists outside the retrieved demo context.',
        ]),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Billing review', 'Review needed']));

    return $draft;
}

function aiCopilotBuildFrontDeskContactContextResponse(array $context, array $draft): array
{
    $name = aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient');
    $email = aiCopilotFallbackValue($context['patient']['email'] ?? '', 'No demo email on file');
    $phone = aiCopilotFallbackValue($context['patient']['phone'] ?? '', 'No demo phone on file');
    $appointment = $context['next_appointment'] ?? [];
    $formattedDateTime = aiCopilotFormatAppointmentDateTime($appointment['date'] ?? '', $appointment['start_time'] ?? '');
    $provider = aiCopilotFallbackValue($appointment['provider_name'] ?? '', 'the assigned provider');
    $location = aiCopilotFallbackValue($appointment['location'] ?? '', 'the clinic');
    $checkIn = aiCopilotFallbackValue($appointment['check_in_instructions'] ?? '', 'Please verify current check-in instructions before outreach.');

    $draft['answer'] = $name . '\'s minimum necessary outreach details are shown below. Verify contact details before outreach.';
    $draft['sections'] = [
        aiCopilotBuildSection('Contact details', [
            'Phone: ' . $phone,
            'Email: ' . $email,
        ]),
        aiCopilotBuildSection('Appointment / outreach context', [
            'Next appointment: ' . aiCopilotFallbackValue($formattedDateTime, 'No future appointment was found in the retrieved front-desk context.'),
            'Provider / location: ' . $provider . ' at ' . $location,
            'Check-in / outreach reminder: ' . $checkIn,
        ]),
    ];
    $draft['tags'] = aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Front desk', 'Contact', 'Minimum PHI']));

    return $draft;
}

function aiCopilotExtractSectionItemsByKeywords(array $sections, array $keywords): array
{
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }

        $title = strtolower(aiCopilotCleanText((string) ($section['title'] ?? '')));
        if ($title === '') {
            continue;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($title, strtolower($keyword))) {
                $items = [];
                foreach (($section['items'] ?? []) as $item) {
                    $itemText = aiCopilotCleanText((string) $item);
                    if ($itemText !== '') {
                        $items[] = $itemText;
                    }
                }
                return array_slice($items, 0, 6);
            }
        }
    }

    return [];
}

function aiCopilotBuildMedicationInformationRows(array $context): array
{
    if (empty($context['medications'])) {
        return [
            'No active medication records were found in the retrieved OpenEMR context for ' . aiCopilotCleanText($context['patient']['name'] ?? 'the selected patient') . '.',
        ];
    }

    $rows = [];
    foreach ($context['medications'] as $medication) {
        $segments = [];
        $drug = aiCopilotCleanText((string) ($medication['drug'] ?? 'Medication'));
        $dosage = aiCopilotCleanText((string) ($medication['dosage'] ?? ''));
        $quantity = aiCopilotCleanText((string) ($medication['quantity'] ?? ''));
        $startDate = aiCopilotCleanText((string) ($medication['start_date'] ?? ''));
        $instructions = aiCopilotCleanText((string) ($medication['note'] ?? ''));

        $segments[] = $drug !== '' ? $drug : 'Medication';
        $segments[] = 'Dosage: ' . aiCopilotFallbackValue($dosage, 'Not documented');
        $segments[] = 'Quantity: ' . aiCopilotFallbackValue($quantity, 'Not documented');
        $segments[] = 'Start date: ' . aiCopilotFallbackValue($startDate, 'Not documented');
        if ($instructions !== '') {
            $segments[] = 'Instructions: ' . $instructions;
        }

        $rows[] = implode(' | ', $segments);
    }

    return array_slice($rows, 0, 12);
}

function aiCopilotBuildMedicationClinicalReviewItems(array $context, array $facts): array
{
    $items = [
        'Verify that the active medication list matches what the patient is actually taking, including any missed doses or refill gaps.',
        'Review interaction and safety questions against the documented problem list, allergies, and current symptoms before making any clinician-directed decisions.',
    ];

    if ($facts['medication_concerns'] !== '') {
        $items[] = 'Charted medication concern to verify: ' . $facts['medication_concerns'];
    }

    if ($facts['allergy_line'] !== '') {
        $items[] = 'Documented allergy context: ' . $facts['allergy_line'] . '.';
    }

    return array_slice($items, 0, 6);
}

function aiCopilotBuildMedicationMonitoringItems(array $context, array $facts): array
{
    $items = [];

    if ($facts['vitals_line'] !== '') {
        $items[] = 'Recent vitals to review with medications: ' . $facts['vitals_line'];
    }

    if ($facts['recent_labs'] !== '') {
        $items[] = 'Recent lab context to review with medications: ' . $facts['recent_labs'];
    }

    if ($facts['follow_up_considerations'] !== '') {
        $items[] = 'Follow-up consideration already documented in chart context: ' . $facts['follow_up_considerations'];
    }

    if ($items === []) {
        $items[] = 'Review available vitals, labs, and current symptom severity before relying on the medication list alone.';
    }

    return array_slice($items, 0, 6);
}

function aiCopilotBuildMedicationCounselingItems(array $context, array $facts): array
{
    $items = [
        'Confirm how the patient is taking each medication, whether evening doses are being missed, and whether written instructions would help adherence.',
        'Review when the patient should contact the care team sooner for worsening symptoms, medication side effects, or new adherence barriers.',
    ];

    if ($facts['follow_up_considerations'] !== '') {
        $items[] = 'Use the existing follow-up context when reinforcing next steps: ' . $facts['follow_up_considerations'];
    }

    return array_slice($items, 0, 6);
}

function aiCopilotGenerateFallbackDraft(string $role, string $mode, string $message, array $context): array
{
    if ($role === 'front_desk') {
        return aiCopilotBuildFrontDeskFallbackResponse($mode, $message, $context);
    }

    if ($role === 'billing') {
        return aiCopilotBuildBillingStaffFallbackResponse($mode, $message, $context);
    }

    $normalized = strtolower($message);

    if (!empty($context['attached_lab_pdf_tool_output']) && aiCopilotLabPdfPromptRequestsRetrieval($message, $mode)) {
        return aiCopilotBuildLabPdfIngestionResponse($context);
    }

    if (preg_match('/patient-friendly/', $normalized)) {
        return aiCopilotBuildPatientFriendlyResponse($context);
    }

    if (preg_match('/visit summary|summary of visit/', $normalized)) {
        return aiCopilotBuildVisitSummaryResponse($context);
    }

    if (preg_match('/30 seconds|30-second|doctor who has 30 seconds|summarize this chart/', $normalized)) {
        return aiCopilotBuildChartSummaryResponse($context);
    }

    if ($mode === 'general_assistant') {
        return aiCopilotBuildGeneralFallbackResponse($role, $message, $context);
    }

    return match ($mode) {
        'differential_diagnosis' => aiCopilotBuildDifferentialResponse($context),
        'medication_info' => aiCopilotBuildMedicationResponse($context),
        'clinical_notes' => aiCopilotBuildClinicalNotesResponse($context),
        'treatment_plan' => aiCopilotBuildTreatmentPlanResponse($context),
        'billing' => aiCopilotBuildBillingSupportResponse($context),
        'follow_up' => aiCopilotBuildFollowUpResponse($context),
        'rag_chart_context' => aiCopilotBuildFallbackRagVisitHistoryResponse($context, aiCopilotBuildChartSummaryResponse($context)),
        'lab_pdf_ingestion' => aiCopilotBuildLabPdfIngestionResponse($context),
        'latest_ambient_summary' => aiCopilotBuildLatestAmbientSummaryResponse($context, aiCopilotBuildChartSummaryResponse($context), $role),
        'visit_summary' => aiCopilotBuildVisitSummaryResponse($context),
        'patient_education' => aiCopilotBuildPatientFriendlyResponse($context),
        'physician_summary' => aiCopilotBuildChartSummaryResponse($context),
        'ma_rooming' => aiCopilotBuildRoomingResponse($context),
        'billing_review' => aiCopilotBuildBillingReviewResponse($context),
        default => aiCopilotBuildGeneralFallbackResponse($role, $message, $context),
    };
}

function aiCopilotBuildGeneralFallbackResponse(string $role, string $message, array $context): array
{
    $normalized = strtolower($message);
    $inferredMode = aiCopilotInferModeFromMessage($message);
    if ($inferredMode !== 'general_assistant') {
        return aiCopilotGenerateFallbackDraft($role, $inferredMode, $message, $context);
    }

    if (preg_match('/patient-friendly/', $normalized)) {
        return aiCopilotBuildPatientFriendlyResponse($context);
    }

    return aiCopilotBuildChartSummaryResponse($context);
}

function aiCopilotBuildChartSummaryResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'Here is a general chart-summary framework. Select a demo patient for patient-specific support.',
            [
                aiCopilotBuildSection('Situation', ['Clarify the chief complaint, symptom timing, and active safety concerns.']),
                aiCopilotBuildSection('Key chart data', ['Review medications, allergies, recent vitals, recent labs, and the latest encounter note.']),
                aiCopilotBuildSection('What to clarify next', ['Ask what changed recently and whether any urgent red flags are active right now.']),
            ],
            ['general summary']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);

    return aiCopilotBuildResponse(
        'Here is a concise beta chart summary for ' . $context['patient']['name'] . '. This draft still needs licensed-clinician review.',
        [
            aiCopilotBuildSection('Situation', [
                aiCopilotFallbackValue($facts['chief_complaint'], 'Recent clinical concern needs review.') . ' ' . aiCopilotFallbackValue($facts['history_of_present_illness'], ''),
            ]),
            aiCopilotBuildSection('Key chart data', [
                'Problems: ' . aiCopilotFallbackValue(aiCopilotJoinList($facts['conditions']), 'No structured problems were found.'),
                'Medications: ' . aiCopilotFallbackValue($facts['medication_line'], 'No active medications were found.'),
                'Allergies: ' . aiCopilotFallbackValue($facts['allergy_line'], 'No allergy list was found in structured data.'),
                'Vitals/labs: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$facts['vitals_line'], $facts['recent_labs']]), 'Recent vitals or labs were not found.'),
            ]),
            aiCopilotBuildSection('What to clarify next', [
                aiCopilotFallbackValue($facts['follow_up_considerations'], 'Confirm what is active right now, what has changed, and what needs urgent escalation.'),
            ]),
        ],
        aiCopilotScenarioTags($context, ['chart summary'])
    );
}

function aiCopilotBuildDifferentialResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general differential framework only.',
            [
                aiCopilotBuildSection('Likely considerations', ['Start with the symptom pattern, timing, comorbidities, medications, and recent vitals/labs.']),
                aiCopilotBuildSection('Red flags', ['Escalate for unstable vitals, severe pain, respiratory distress, syncope, focal neurologic changes, or rapidly worsening infection signs.'], 'red'),
                aiCopilotBuildSection('Key gaps', ['Clarify what symptoms are active now, what changed recently, and what objective data are still missing.'], 'yellow'),
                aiCopilotBuildSection('Next steps / clarification', ['Select a demo patient to generate a chart-specific beta differential draft.']),
            ],
            ['general differential']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);
    $key = $facts['patient_key'];

    return match ($key) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            $context['patient']['name'] . ' has exertional chest pressure, dyspnea, and several cardiovascular risk factors, so urgent cardiopulmonary causes need to stay high on the list. This beta draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Likely considerations', [
                    'Acute coronary syndrome or unstable angina/NSTEMI because the pain is pressure-like, worse with exertion, and occurs in the setting of diabetes, hypertension, and hyperlipidemia.',
                    'Pulmonary embolism if shortness of breath becomes disproportionate, pleuritic symptoms emerge, or additional clotting risk factors are uncovered.',
                    'Aortic dissection is less supported by the current note but still important if pain becomes tearing, radiates to the back, or neurologic deficits appear.',
                    'GERD or musculoskeletal chest pain remain lower-acuity alternatives if the urgent cardiac workup is reassuring.',
                ]),
                aiCopilotBuildSection('Red flags', [
                    'Ongoing or worsening chest pressure, increasing shortness of breath, diaphoresis, syncope, or new neurologic symptoms.',
                    'Current charted vitals show BP 162/96 and pulse 104, which add concern in this symptom context.',
                    'No troponin result is documented yet, so an ACS rule-out is incomplete.',
                ], 'red'),
                aiCopilotBuildSection('Key gaps', [
                    'Whether the chest pressure is active right now, whether it radiates, and whether it is pleuritic or reproducible.',
                    'ECG findings, troponin status, and whether there are leg symptoms, immobilization, or other PE risk factors.',
                    'Medication adherence just before symptom onset, especially lisinopril, atorvastatin, aspirin, and diabetes therapy.',
                ], 'yellow'),
                aiCopilotBuildSection('Next steps / clarification', [
                    'Clarify whether the patient needs immediate escalation or ED evaluation now.',
                    'Consider ECG, troponin, and close vitals monitoring per clinician judgment.',
                    'After urgent issues are addressed, revisit chronic BP, diabetes, and lipid control.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['chest pressure', 'exertional dyspnea', 'ACS risk', 'cardiometabolic risk'])
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            $context['patient']['name'] . ' has a diabetic foot wound with drainage, redness, pain, and neuropathy, so infection-related and structural foot complications need to stay high on the list. This beta draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Likely considerations', [
                    'Diabetic foot ulcer with surrounding cellulitis because the note describes a plantar wound with erythema and drainage.',
                    'Localized abscess if there is fluctuance, increasing pain, or a deeper pocket not yet documented.',
                    'Osteomyelitis risk because the wound has drainage in a patient with poor glycemic control and neuropathy.',
                    'Neuropathic injury with delayed recognition and possible vascular insufficiency contributing to poor healing.',
                ]),
                aiCopilotBuildSection('Red flags', [
                    'Rapidly spreading redness, severe swelling, crepitus, foul odor, systemic symptoms, or rising temperature.',
                    'Hyperglycemia plus infection concern, especially with WBC 11.8 and A1C 9.6 in the chart context.',
                    'Reduced pulses or concern for deep tissue involvement could change urgency quickly.',
                ], 'red'),
                aiCopilotBuildSection('Key gaps', [
                    'Exact wound depth, size, probe-to-bone status, and whether there is fluctuance or necrosis.',
                    'Current glucose trend, foot pulses, offloading status, and whether the patient has fever, chills, or streaking redness.',
                    'Recent footwear trauma, home wound care, and any prior diabetic foot infections or vascular workup.',
                ], 'yellow'),
                aiCopilotBuildSection('Next steps / clarification', [
                    'Clarify infection severity and document a focused foot exam.',
                    'Consider wound culture or imaging if deeper infection or osteomyelitis is a concern.',
                    'Review need for offloading, wound care follow-up, and urgent escalation if infection is spreading.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['diabetic foot wound', 'cellulitis risk', 'osteomyelitis risk', 'neuropathy'])
        ),
        default => aiCopilotBuildResponse(
            $context['patient']['name'] . ' has respiratory symptoms in the setting of asthma and allergy overlap, so airway and trigger-related causes lead the differential from the current chart context. This beta draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Likely considerations', [
                    'Asthma symptom flare because the patient reports cough, wheezing, nocturnal symptoms, and increased rescue inhaler use.',
                    'Viral upper respiratory infection if there is a concurrent infectious trigger not yet fully documented.',
                    'Allergic rhinitis/postnasal drip and GERD overlap because both are already part of the chart context and can worsen cough or chest tightness.',
                    'Pneumonia is less supported by the current note but would move up if fever, focal findings, or hypoxia appear.',
                ]),
                aiCopilotBuildSection('Red flags', [
                    'Increasing work of breathing, inability to speak full sentences, hypoxia, cyanosis, or rapidly worsening chest tightness.',
                    'Rising rescue inhaler use with poor relief or new chest pain out of proportion to the current chart.',
                    'Severe distress is not documented now, but respiratory symptoms can worsen quickly if control is poor.',
                ], 'red'),
                aiCopilotBuildSection('Key gaps', [
                    'Exact rescue inhaler frequency, controller adherence, inhaler technique, and recent trigger exposure.',
                    'Presence of fever, sputum, sick contacts, chest imaging, or objective peak-flow data.',
                    'Whether anxiety is secondary to dyspnea or an overlapping driver of symptoms.',
                ], 'yellow'),
                aiCopilotBuildSection('Next steps / clarification', [
                    'Clarify symptom severity, nighttime frequency, and trigger pattern.',
                    'Review inhaler technique, controller adherence, and whether a peak flow or additional respiratory assessment is needed.',
                    'Recheck for urgent breathing red flags if symptoms worsen or oxygenation declines.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['asthma flare', 'wheezing', 'allergic triggers', 'respiratory symptoms'])
        ),
    };
}

function aiCopilotBuildMedicationResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general medication-review framework only.',
            [
                aiCopilotBuildSection('Current medication picture', ['Review the active list, allergies, what the patient is actually taking, and any recent medication changes.']),
                aiCopilotBuildSection('Safety checks', ['Look for missed doses, interaction risks, renal dosing concerns, and duplicate therapy.']),
                aiCopilotBuildSection('Monitoring considerations', ['Tie medication review to vitals, recent labs, and symptom severity.']),
                aiCopilotBuildSection('Patient counseling points', ['Confirm understanding, adherence barriers, and what warning symptoms should prompt faster follow-up.']),
            ],
            ['general medication review']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);
    $allergies = aiCopilotFallbackValue($facts['allergy_line'], 'No structured allergy list found.');

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'The following medication information for ' . $context['patient']['name'] . ' must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Current Medication Information', aiCopilotBuildMedicationInformationRows($context)),
                aiCopilotBuildSection('Clinical review considerations', [
                    'Confirm adherence to metformin, lisinopril, atorvastatin, and aspirin, especially around the time symptoms began.',
                    'Aspirin use should remain clinician-directed in the chest-pain context rather than self-adjusted from this beta draft.',
                    'Allergy status is documented as: ' . $allergies,
                ]),
                aiCopilotBuildSection('Monitoring considerations', [
                    'Recheck blood pressure, pulse, glucose, renal function, and potassium when reviewing the ACE inhibitor and diabetes therapy.',
                    'LDL and A1C remain above goal in the seeded chart context and support chronic risk review after urgent issues are addressed.',
                ]),
                aiCopilotBuildSection('Patient counseling points', [
                    'Tell the clinician if any doses were missed, if chest symptoms are active now, or if there was any self-treatment before the visit.',
                    'Bring the actual medication list or bottles if available because adherence details matter in this scenario.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['metformin', 'lisinopril', 'atorvastatin', 'aspirin', 'adherence'])
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'The following medication information for ' . $context['patient']['name'] . ' must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Current Medication Information', aiCopilotBuildMedicationInformationRows($context)),
                aiCopilotBuildSection('Clinical review considerations', [
                    'Glipizide raises hypoglycemia risk if meal timing is inconsistent or intake drops because of illness.',
                    'Metformin and gabapentin both deserve a renal-function check in the infection and wound-healing context.',
                    'Allergy status is documented as: ' . $allergies . ' That matters if antibiotics are being considered.',
                ]),
                aiCopilotBuildSection('Monitoring considerations', [
                    'The seeded chart shows A1C 9.6, glucose 248, and WBC 11.8, so poor control and infection burden both need monitoring.',
                    'Review home glucose trends, wound progression, and whether pain or numbness is changing.',
                ]),
                aiCopilotBuildSection('Patient counseling points', [
                    'Ask what the patient is actually taking, whether any doses were missed, and whether there were recent lows or dizziness.',
                    'Reinforce foot protection, wound-care follow-up, and the need to report spreading redness, fever, or worsening drainage quickly.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['metformin', 'glipizide', 'gabapentin', 'wound care', 'hypoglycemia risk'])
        ),
        default => aiCopilotBuildResponse(
            'The following medication information for ' . $context['patient']['name'] . ' must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Current Medication Information', aiCopilotBuildMedicationInformationRows($context)),
                aiCopilotBuildSection('Clinical review considerations', [
                    'Frequent albuterol use can signal poor control and can contribute to tachycardia or shakiness.',
                    'Review controller adherence and whether the patient is using any duplicate inhalers or old steroid prescriptions.',
                    'Prednisone side effects such as insomnia, mood changes, and glucose effects should be reviewed if the recent course is active.',
                    'Allergy status is documented as: ' . $allergies,
                ]),
                aiCopilotBuildSection('Monitoring considerations', [
                    'Track rescue inhaler frequency, nocturnal symptoms, pulse, oxygen saturation, and response to controller therapy.',
                    'If symptoms are not improving, consider whether additional respiratory evaluation is needed rather than relying only on repeated rescue medication.',
                ]),
                aiCopilotBuildSection('Patient counseling points', [
                    'Review inhaler technique and remind the patient to rinse after inhaled steroid use.',
                    'Clarify trigger exposures, when to seek urgent breathing evaluation, and whether the patient is taking the controller every day as directed.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['albuterol', 'controller inhaler', 'prednisone', 'inhaler technique'])
        ),
    };
}

function aiCopilotBuildClinicalNotesResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general clinical-note structure only.',
            [
                aiCopilotBuildSection('Draft note', ['Select a demo patient to generate a patient-specific beta note draft.']),
                aiCopilotBuildSection('Subjective', ['Capture the chief complaint, symptom timing, key symptoms, and relevant history.']),
                aiCopilotBuildSection('Objective', ['Include recent vitals, exam findings, medications, allergies, and helpful labs.']),
                aiCopilotBuildSection('Assessment', ['Summarize the working clinical concerns without claiming final certainty.']),
                aiCopilotBuildSection('Plan', ['Outline monitoring, follow-up, patient education, and safety checks.']),
            ],
            ['general note draft']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'Here is a beta draft note summary for ' . $context['patient']['name'] . '. This draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Draft note', [
                    'Adult male with hypertension, diabetes, and hyperlipidemia presenting with 2 days of intermittent exertional chest pressure, mild shortness of breath, and nausea; urgent cardiac causes remain important to exclude.',
                ]),
                aiCopilotBuildSection('Subjective', [
                    aiCopilotFallbackValue($facts['history_of_present_illness'], 'Chest-pressure history needs further clarification.'),
                    'Symptoms documented: ' . aiCopilotFallbackValue($facts['symptoms'], 'Chest pressure symptoms noted.'),
                ]),
                aiCopilotBuildSection('Objective', [
                    'Vitals: ' . aiCopilotFallbackValue($facts['vitals_line'], 'No recent vitals found.'),
                    'Recent labs: ' . aiCopilotFallbackValue($facts['recent_labs'], 'No recent labs found.'),
                    'Medication/allergy context: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$facts['medication_line'], $facts['allergy_line']]), 'Medication or allergy details were limited.'),
                ]),
                aiCopilotBuildSection('Assessment', [
                    'Exertional chest pressure with cardiometabolic risk factors raises concern for ACS while lower-acuity GI or musculoskeletal causes remain possible.',
                    'No troponin is documented yet, so the chart does not show a complete rule-out.',
                ]),
                aiCopilotBuildSection('Plan', [
                    'Clarify whether symptoms are active now and assess need for urgent escalation.',
                    'Consider ECG, troponin review, vitals monitoring, and chronic disease follow-up after the acute risk question is addressed.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['beta note', 'chest pain', 'ACS risk', 'cardiac workup'])
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'Here is a beta draft note summary for ' . $context['patient']['name'] . '. This draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Draft note', [
                    'Adult male with diabetes, obesity, and neuropathy presenting with a right plantar foot wound, new redness, drainage, and increasing pain concerning for diabetic foot infection risk.',
                ]),
                aiCopilotBuildSection('Subjective', [
                    aiCopilotFallbackValue($facts['history_of_present_illness'], 'Foot wound history needs further clarification.'),
                    'Symptoms documented: ' . aiCopilotFallbackValue($facts['symptoms'], 'Foot wound symptoms noted.'),
                ]),
                aiCopilotBuildSection('Objective', [
                    'Vitals: ' . aiCopilotFallbackValue($facts['vitals_line'], 'No recent vitals found.'),
                    'Recent labs: ' . aiCopilotFallbackValue($facts['recent_labs'], 'No recent labs found.'),
                    'Exam context: ' . aiCopilotFallbackValue($facts['recent_exam'], 'No recent exam summary found.'),
                ]),
                aiCopilotBuildSection('Assessment', [
                    'Diabetic foot ulcer with concern for cellulitis and deeper infection risk in the setting of poor glycemic control and neuropathy.',
                    'Osteomyelitis and vascular insufficiency should be kept in mind if the exam suggests depth, poor perfusion, or poor healing.',
                ]),
                aiCopilotBuildSection('Plan', [
                    'Clarify wound severity, depth, pulses, and offloading status.',
                    'Review infection workup, glycemic management, wound care follow-up, and urgent precautions for worsening infection.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['beta note', 'diabetic foot wound', 'infection risk', 'wound care'])
        ),
        default => aiCopilotBuildResponse(
            'Here is a beta draft note summary for ' . $context['patient']['name'] . '. This draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Draft note', [
                    'Adult female with asthma and seasonal allergy overlap presenting with cough, wheezing, nocturnal chest tightness, and increased rescue inhaler use without severe distress documented.',
                ]),
                aiCopilotBuildSection('Subjective', [
                    aiCopilotFallbackValue($facts['history_of_present_illness'], 'Respiratory history needs further clarification.'),
                    'Symptoms documented: ' . aiCopilotFallbackValue($facts['symptoms'], 'Respiratory symptoms noted.'),
                ]),
                aiCopilotBuildSection('Objective', [
                    'Vitals: ' . aiCopilotFallbackValue($facts['vitals_line'], 'No recent vitals found.'),
                    'Recent respiratory context: ' . aiCopilotFallbackValue($facts['recent_exam'], 'No recent respiratory exam summary found.'),
                    'Medication/allergy context: ' . aiCopilotFallbackValue(aiCopilotJoinParts([$facts['medication_line'], $facts['allergy_line']]), 'Medication or allergy details were limited.'),
                ]),
                aiCopilotBuildSection('Assessment', [
                    'Current chart context is most consistent with asthma symptom flare with allergic-trigger overlap, while infection or reflux overlap still needs clarification.',
                    'No severe hypoxia or severe distress is documented in the seeded chart context.',
                ]),
                aiCopilotBuildSection('Plan', [
                    'Review inhaler technique, controller adherence, trigger exposure, and whether peak-flow or additional respiratory evaluation is needed.',
                    'Reinforce urgent precautions for worsening breathing symptoms or poor response to rescue medication.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['beta note', 'asthma symptoms', 'wheezing', 'inhaler review'])
        ),
    };
}

function aiCopilotBuildTreatmentPlanResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general treatment-planning framework only.',
            [
                aiCopilotBuildSection('Immediate priorities', ['Identify any active red flags and whether urgent escalation is needed.']),
                aiCopilotBuildSection('Suggested workup or monitoring', ['Tie the plan to recent vitals, labs, medications, and the latest encounter note.']),
                aiCopilotBuildSection('Patient education', ['Explain the plan in plain language and review warning symptoms.']),
                aiCopilotBuildSection('Follow-up', ['Define what needs short-interval review vs routine follow-up.']),
                aiCopilotBuildSection('Safety precautions', ['Keep the plan read-only and clinician-verified.']),
            ],
            ['general treatment plan']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'The treatment-planning draft should prioritize urgent symptom triage before chronic disease follow-up. This beta draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Immediate priorities', [
                    'Clarify whether chest pressure or dyspnea is active now and whether the patient needs urgent escalation or ED evaluation.',
                    'Recheck vitals and symptom severity in real time because the seeded chart already shows elevated BP and pulse.',
                ]),
                aiCopilotBuildSection('Suggested workup or monitoring', [
                    'Consider ECG and troponin review, ongoing vitals monitoring, and focused cardiopulmonary reassessment per clinician judgment.',
                    'After the acute risk question is addressed, revisit diabetes, BP, and lipid control because the chart shows elevated A1C, LDL, and glucose.',
                ]),
                aiCopilotBuildSection('Patient education', [
                    'Advise the patient to report worsening chest pressure, shortness of breath, diaphoresis, or syncope immediately.',
                    'Review the importance of bringing an accurate medication list and sharing whether any doses were missed.',
                ]),
                aiCopilotBuildSection('Follow-up', [
                    'Short-interval follow-up is reasonable after urgent evaluation to revisit cardiometabolic risk management.',
                    'Medication adherence, blood pressure, and diabetes monitoring should be reassessed after the acute issue is clarified.',
                ]),
                aiCopilotBuildSection('Safety precautions', [
                    'Do not treat this beta draft as a final diagnosis or an order set.',
                    'Escalate faster if symptoms are ongoing or if the workup raises concern for ACS or another cardiopulmonary emergency.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['urgent triage', 'ECG/troponin consideration', 'blood pressure', 'diabetes follow-up'])
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'The treatment-planning draft should prioritize wound severity assessment, infection risk stratification, and glycemic control. This beta draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Immediate priorities', [
                    'Assess wound depth, drainage, surrounding erythema, pulses, and whether the infection appears superficial or deeper.',
                    'Escalate quickly if redness is spreading, systemic symptoms appear, or there is concern for deep tissue involvement.',
                ]),
                aiCopilotBuildSection('Suggested workup or monitoring', [
                    'Review need for wound culture, imaging, or additional labs if osteomyelitis or abscess is a concern.',
                    'Monitor glucose control and renal function because the wound is occurring with A1C 9.6 and glucose 248 in the seeded chart context.',
                ]),
                aiCopilotBuildSection('Patient education', [
                    'Review foot protection, offloading, daily wound observation, and the importance of reporting worsening drainage or redness.',
                    'Reinforce adherence to diabetes medications and when to report low or high glucose concerns.',
                ]),
                aiCopilotBuildSection('Follow-up', [
                    'Consider close wound follow-up and referral needs such as podiatry or wound care depending on severity.',
                    'Reassess infection trend, pain, numbness, and home wound care within a short interval if the patient is managed outpatient.',
                ]),
                aiCopilotBuildSection('Safety precautions', [
                    'Escalate sooner for fever, rapidly spreading redness, worsening pain, foul odor, or concern for deep infection.',
                    'This beta draft should not be treated as an antibiotic order or a final wound-management decision.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['wound assessment', 'infection severity', 'offloading', 'podiatry follow-up'])
        ),
        default => aiCopilotBuildResponse(
            'The treatment-planning draft should focus on asthma control assessment, trigger review, and urgent breathing precautions. This beta draft must be verified by a licensed clinician.',
            [
                aiCopilotBuildSection('Immediate priorities', [
                    'Assess current respiratory effort, rescue inhaler response, and whether symptoms are worsening or interfering with speech or sleep.',
                    'Check whether nocturnal symptoms and frequent rescue use suggest poor control that needs more urgent review.',
                ]),
                aiCopilotBuildSection('Suggested workup or monitoring', [
                    'Review inhaler technique, controller adherence, trigger exposure, and whether peak flow or additional respiratory evaluation is needed.',
                    'Recheck pulse, respirations, oxygen saturation, and symptom trend if symptoms do not improve.',
                ]),
                aiCopilotBuildSection('Patient education', [
                    'Explain the difference between rescue and controller inhalers, and reinforce rinsing after inhaled steroid use.',
                    'Review common triggers and what symptoms should prompt same-day or urgent follow-up.',
                ]),
                aiCopilotBuildSection('Follow-up', [
                    'Arrange close follow-up if rescue use remains high or nighttime symptoms continue.',
                    'Revisit controller adherence, trigger reduction, and whether the recent steroid burst changed symptoms.',
                ]),
                aiCopilotBuildSection('Safety precautions', [
                    'Escalate urgently for worsening shortness of breath, poor relief from rescue medication, hypoxia, or inability to speak full sentences.',
                    'This beta draft should not be treated as a final asthma action plan without clinician review.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['asthma control', 'inhaler technique', 'trigger review', 'urgent breathing precautions'])
        ),
    };
}

function aiCopilotBuildBillingSupportResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general billing-support framework only.',
            [
                aiCopilotBuildSection('Billing documentation summary', ['Summarize the visit reason, symptom severity, comorbidities, decision-making, and what the clinician actually reviewed or reassessed.']),
                aiCopilotBuildSection('Possible coding considerations', ['Keep any coding discussion provisional and tied to documented history, exam, medical decision-making, and diagnosis linkage.']),
                aiCopilotBuildSection('Missing documentation', ['Check for missing symptom detail, exam findings, diagnosis linkage, and incomplete plan or follow-up documentation.']),
                aiCopilotBuildSection('Risk / compliance reminders', ['Use this only as billing-support guidance. Do not submit claims, do not suggest upcoding, and keep coder/clinician review in the loop.']),
            ],
            ['Billing review', 'Review needed']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);
    $visitType = aiCopilotFallbackValue($facts['visit_type'], 'established outpatient visit');
    $billingSupport = aiCopilotFallbackValue($facts['billing_support'], 'Document the symptom story, clinician assessment, and why the documented plan was appropriate for the visit context.');

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'This billing-support draft is chart-based and not a final coding decision. Coder and clinician review are still required before billing action.',
            [
                aiCopilotBuildSection('Billing documentation summary', [
                    'Visit context reads like a ' . $visitType . ' for exertional chest pressure with mild dyspnea and nausea in a patient with hypertension, diabetes, and hyperlipidemia.',
                    'The chart should clearly show symptom timing, exertional trigger, associated symptoms, current severity, vitals reassessment, and the clinician\'s urgency assessment.',
                ]),
                aiCopilotBuildSection('Possible coding considerations', [
                    'Established outpatient E/M support depends on documented history, exam, and medical decision-making rather than this beta draft.',
                    'If no definitive diagnosis is established, symptom-based diagnosis linkage and risk-based decision-making should be documented carefully for human billing review.',
                ]),
                aiCopilotBuildSection('Missing documentation', [
                    'Clarify whether chest pressure was active during the visit, whether it radiated, and whether there were diaphoresis, syncope, or pleuritic features.',
                    'Document ECG or troponin review status, ED referral discussion if relevant, and medication-adherence context because those details affect billing-support review.',
                    aiCopilotFallbackValue($billingSupport, 'Document the clinician rationale for urgent evaluation or close follow-up.'),
                ]),
                aiCopilotBuildSection('Risk / compliance reminders', [
                    'Do not treat any CPT or ICD suggestion as definitive without full documentation support.',
                    'Do not submit or resubmit claims from this beta draft, and do not suggest upcoding.',
                ]),
            ],
            ['Billing review', 'Chart context', 'Review needed']
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'This billing-support draft is chart-based and not a final coding decision. Coder and clinician review are still required before billing action.',
            [
                aiCopilotBuildSection('Billing documentation summary', [
                    'Visit context reads like a ' . $visitType . ' for a diabetic right foot wound with redness, drainage, pain, neuropathy, and poor glycemic control.',
                    'Documentation should show wound location, size or depth if assessed, surrounding erythema or drainage, neuropathy findings, and the clinician\'s infection-severity assessment.',
                ]),
                aiCopilotBuildSection('Possible coding considerations', [
                    'E/M support should follow the documented history, focused foot exam, infection-risk assessment, and complexity of decision-making.',
                    'Problem-list and diagnosis linkage should reflect the wound, diabetes context, neuropathy, and any infection concerns only when they are actually documented.',
                ]),
                aiCopilotBuildSection('Missing documentation', [
                    'Add foot-exam details such as pulses, depth, drainage amount, probe-to-bone concern, and offloading or wound-care counseling if reviewed.',
                    'If imaging, labs, referral, or escalation were discussed, that reasoning should be documented because it strengthens billing-support clarity.',
                    aiCopilotFallbackValue($billingSupport, 'Document wound severity, diabetic risk, and why close follow-up or referral was recommended.'),
                ]),
                aiCopilotBuildSection('Risk / compliance reminders', [
                    'Use this only as a documentation checklist for coder and clinician review.',
                    'Do not submit claims or assume a specific billed service is supported until the signed note is complete.',
                ]),
            ],
            ['Billing review', 'Chart context', 'Review needed']
        ),
        default => aiCopilotBuildResponse(
            'This billing-support draft is chart-based and not a final coding decision. Coder and clinician review are still required before billing action.',
            [
                aiCopilotBuildSection('Billing documentation summary', [
                    'Visit context reads like a ' . $visitType . ' for cough, wheezing, nocturnal chest tightness, and increased rescue inhaler use in a patient with asthma and allergy overlap.',
                    'Documentation should show symptom frequency, rescue inhaler use, controller adherence, respiratory exam context, and how symptom control was assessed.',
                ]),
                aiCopilotBuildSection('Possible coding considerations', [
                    'E/M support should reflect the documented respiratory history, assessment of control or flare severity, and any change in risk-based decision-making.',
                    'Diagnosis linkage should stay consistent with what is documented, such as asthma symptoms, allergic overlap, or other clearly supported concerns.',
                ]),
                aiCopilotBuildSection('Missing documentation', [
                    'Clarify nighttime symptom frequency, recent trigger exposure, controller adherence, and whether the patient improved or worsened after recent therapy.',
                    'Respiratory findings, oxygenation review, and inhaler-technique counseling help support the visit story before billing review.',
                    aiCopilotFallbackValue($billingSupport, 'Document symptom control assessment, medication review, and follow-up planning in the signed note.'),
                ]),
                aiCopilotBuildSection('Risk / compliance reminders', [
                    'Keep billing discussion provisional and documentation-based.',
                    'Do not submit claims, do not suggest upcoding, and keep coder/clinician review required.',
                ]),
            ],
            ['Billing review', 'Chart context', 'Review needed']
        ),
    };
}

function aiCopilotBuildFollowUpResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general follow-up framework only.',
            [
                aiCopilotBuildSection('Follow-up timeframe', ['Tie timing to the severity of symptoms, active red flags, and whether urgent reassessment is needed.']),
                aiCopilotBuildSection('What to monitor', ['Track symptoms, recent vitals or labs, medication adherence, and whether the patient is improving or worsening.']),
                aiCopilotBuildSection('Patient instructions', ['Explain what to watch at home, how to use medications as directed, and when to contact the clinic sooner.']),
                aiCopilotBuildSection('Escalation precautions', ['Escalate for worsening symptoms, unstable vitals, or any urgent red flags.']),
                aiCopilotBuildSection('Care coordination', ['Identify whether PCP, specialty, wound care, or urgent care coordination is needed.']),
            ],
            ['Follow-up', 'Review needed']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'This follow-up draft should stay clinician-directed because the symptom profile includes cardiopulmonary red flags. Human review is required.',
            [
                aiCopilotBuildSection('Follow-up timeframe', [
                    'If chest pressure or dyspnea is active now, same-day urgent evaluation or ED escalation should stay on the table.',
                    'If urgent evaluation is completed and the patient is stable, short-interval follow-up over the next few days can support BP, diabetes, and symptom reassessment.',
                ]),
                aiCopilotBuildSection('What to monitor', [
                    'Monitor recurrence of chest pressure, worsening shortness of breath, syncope, diaphoresis, blood pressure, and glucose trends.',
                    'Confirm whether ECG or troponin review occurred and whether medication adherence changed around symptom onset.',
                ]),
                aiCopilotBuildSection('Patient instructions', [
                    'Ask the patient to report worsening chest pain, trouble breathing, fainting, or new neurologic symptoms immediately.',
                    'Bring an updated medication list and home BP or glucose readings to the next review if available.',
                ]),
                aiCopilotBuildSection('Escalation precautions', [
                    'Escalate faster for active chest pressure, increasing dyspnea, abnormal vitals, or concern for ACS or another cardiopulmonary emergency.',
                ], 'red'),
                aiCopilotBuildSection('Care coordination', [
                    'Coordinate with the evaluating clinician regarding urgent testing review and whether cardiology or higher-acuity follow-up is needed.',
                ]),
            ],
            ['Follow-up', 'Red flags', 'Review needed']
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'This follow-up draft should stay clinician-directed because the wound may worsen quickly if infection deepens. Human review is required.',
            [
                aiCopilotBuildSection('Follow-up timeframe', [
                    'Short-interval wound follow-up within 24 to 72 hours is reasonable if the patient is managed outpatient.',
                    'Earlier reassessment is needed if the foot exam suggests deeper infection, poor perfusion, or rapidly changing symptoms.',
                ]),
                aiCopilotBuildSection('What to monitor', [
                    'Monitor wound drainage, erythema spread, pain, numbness, glucose control, and any fever or systemic symptoms.',
                    'Track whether offloading and home wound care are actually happening between visits.',
                ]),
                aiCopilotBuildSection('Patient instructions', [
                    'Review daily wound observation, clean dressing care as directed by the clinician, and foot protection or offloading instructions.',
                    'Advise the patient to bring home glucose information and report missed diabetes medication doses or hypoglycemia concerns.',
                ]),
                aiCopilotBuildSection('Escalation precautions', [
                    'Escalate for spreading redness, foul odor, more drainage, fever, worsening pain, or concern for deep infection.',
                ], 'red'),
                aiCopilotBuildSection('Care coordination', [
                    'Coordinate wound care or podiatry follow-up if the clinician thinks the wound needs closer specialty review.',
                ]),
            ],
            ['Follow-up', 'Red flags', 'Review needed']
        ),
        default => aiCopilotBuildResponse(
            'This follow-up draft should stay clinician-directed because respiratory symptoms can change quickly if control worsens. Human review is required.',
            [
                aiCopilotBuildSection('Follow-up timeframe', [
                    'Close follow-up within days to a couple of weeks is reasonable depending on symptom burden and rescue-inhaler use.',
                    'Escalate sooner if symptoms are worsening or the patient is not improving after recent therapy.',
                ]),
                aiCopilotBuildSection('What to monitor', [
                    'Monitor rescue inhaler frequency, nighttime symptoms, wheezing, oxygenation if available, trigger exposure, and controller adherence.',
                    'Check whether the recent prednisone burst changed symptoms and whether side effects are becoming a problem.',
                ]),
                aiCopilotBuildSection('Patient instructions', [
                    'Review inhaler technique, controller use every day as prescribed, and rinsing after steroid inhaler use.',
                    'Ask the patient to note triggers, nighttime symptoms, and when rescue medication is needed.',
                ]),
                aiCopilotBuildSection('Escalation precautions', [
                    'Escalate urgently for worsening breathing, poor rescue-inhaler response, cyanosis, or inability to speak comfortably.',
                ], 'red'),
                aiCopilotBuildSection('Care coordination', [
                    'Coordinate follow-up with the primary clinician and consider whether additional asthma education or respiratory follow-up is needed.',
                ]),
            ],
            ['Follow-up', 'Red flags', 'Review needed']
        ),
    };
}

function aiCopilotBuildVisitSummaryResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general visit-summary framework only.',
            [
                aiCopilotBuildSection('Visit summary', ['Summarize why the patient was seen, the biggest concerns addressed, and the draft plan reviewed.']),
                aiCopilotBuildSection('Key concerns addressed', ['List the main symptoms, comorbidities, and safety issues that shaped the visit.']),
                aiCopilotBuildSection('Plan discussed', ['Describe the draft workup, monitoring, treatment-plan themes, and follow-up needs.']),
                aiCopilotBuildSection('Follow-up instructions', ['State when the patient should follow up and what warning symptoms should prompt faster contact.']),
                aiCopilotBuildSection('Patient-friendly explanation', ['Translate the plan into short plain-language takeaways.']),
            ],
            ['Visit summary', 'Review needed']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'Here is a draft summary of the visit for review by the clinical team. This beta summary should not be written into the chart automatically.',
            [
                aiCopilotBuildSection('Visit summary', [
                    'Adult male with hypertension, diabetes, and hyperlipidemia seen for intermittent exertional chest pressure with mild shortness of breath and nausea over 2 days.',
                ]),
                aiCopilotBuildSection('Key concerns addressed', [
                    'Urgent cardiopulmonary causes remained important to exclude because of exertional symptoms and cardiometabolic risk factors.',
                    'Recent vitals and labs also supported follow-up for BP, diabetes, and lipid control once urgent risk was reviewed.',
                ]),
                aiCopilotBuildSection('Plan discussed', [
                    'Draft planning centered on real-time symptom reassessment, possible ECG or troponin review, vitals monitoring, and clinician-directed escalation if symptoms were active.',
                ]),
                aiCopilotBuildSection('Follow-up instructions', [
                    'Follow up promptly after urgent evaluation and return sooner for worsening chest pain, dyspnea, diaphoresis, syncope, or new neurologic symptoms.',
                ]),
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'The visit focused on making sure the chest symptoms were not missing a serious heart or lung problem and on planning close follow-up for chronic risk factors.',
                ]),
            ],
            ['Visit summary', 'Red flags', 'Review needed']
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'Here is a draft summary of the visit for review by the clinical team. This beta summary should not be written into the chart automatically.',
            [
                aiCopilotBuildSection('Visit summary', [
                    'Adult male with diabetes, obesity, and neuropathy seen for a right foot wound with redness, drainage, numbness, and increasing pain.',
                ]),
                aiCopilotBuildSection('Key concerns addressed', [
                    'The visit addressed diabetic foot infection risk, wound severity, neuropathy, and the effect of poor glycemic control on healing.',
                ]),
                aiCopilotBuildSection('Plan discussed', [
                    'Draft planning centered on foot exam clarification, infection-severity review, possible labs or imaging if deeper infection was suspected, and wound-care follow-up.',
                ]),
                aiCopilotBuildSection('Follow-up instructions', [
                    'Follow up within a short interval and seek faster care for spreading redness, fever, more drainage, foul odor, or worsening pain.',
                ]),
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'The visit focused on checking whether the foot sore was getting infected, protecting the foot, and tightening follow-up so the wound does not worsen.',
                ]),
            ],
            ['Visit summary', 'Follow-up', 'Review needed']
        ),
        default => aiCopilotBuildResponse(
            'Here is a draft summary of the visit for review by the clinical team. This beta summary should not be written into the chart automatically.',
            [
                aiCopilotBuildSection('Visit summary', [
                    'Adult female with asthma and allergy overlap seen for cough, wheezing, nocturnal chest tightness, and increased rescue inhaler use.',
                ]),
                aiCopilotBuildSection('Key concerns addressed', [
                    'The visit addressed asthma symptom control, rescue-inhaler overuse, controller adherence, and potential trigger overlap from allergies or GERD.',
                ]),
                aiCopilotBuildSection('Plan discussed', [
                    'Draft planning centered on inhaler technique review, controller adherence, trigger assessment, symptom monitoring, and urgent precautions for worsening breathing.',
                ]),
                aiCopilotBuildSection('Follow-up instructions', [
                    'Follow up if nighttime symptoms or frequent rescue use continue, and seek urgent care sooner for worsening shortness of breath or poor rescue response.',
                ]),
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'The visit focused on understanding why breathing symptoms were flaring and on making sure the inhaler plan and follow-up steps were clear.',
                ]),
            ],
            ['Visit summary', 'Follow-up', 'Review needed']
        ),
    };
}

function aiCopilotBuildRoomingResponse(array $context): array
{
    $summary = aiCopilotBuildChartSummaryResponse($context);
    $summary['answer'] = 'The current demo is optimized for clinical support rather than a rooming-only workflow, but here is a concise prep summary. This draft still needs human review.';
    return $summary;
}

function aiCopilotBuildBillingReviewResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general billing-review framework only.',
            [
                aiCopilotBuildSection('Plain-language issue', ['Look for missing diagnosis-to-procedure linkage, payer mismatches, or documentation gaps.']),
                aiCopilotBuildSection('What to check first', ['Compare the billed CPT line to the diagnosis list, encounter note, and payer/member details.']),
                aiCopilotBuildSection('Guardrails', ['Keep the review read-only and do not submit or upcode from this beta draft.']),
            ],
            ['general billing review']
        );
    }

    $claimIssue = aiCopilotClaimIssueFromContext($context);
    $claimChecks = aiCopilotClaimChecksFromContext($context);

    return aiCopilotBuildResponse(
        'The claim review still looks read-only and beta-safe: the main issue is a missing or unsupported diagnosis linkage. This draft must still be verified by a human billing reviewer.',
        [
            aiCopilotBuildSection('Plain-language issue', [
                aiCopilotFallbackValue($claimIssue, 'The claim appears to need diagnosis-to-procedure review before any resubmission.'),
            ]),
            aiCopilotBuildSection('What to check first', $claimChecks !== [] ? $claimChecks : [
                'Confirm that the diagnosis is linked to the CPT line item.',
                'Verify that the encounter documentation supports the billed service.',
                'Recheck payer and member details before any human resubmission decision.',
            ]),
            aiCopilotBuildSection('Guardrails', [
                'Do not submit, resubmit, or re-code from this beta draft.',
                'Do not suggest upcoding or unsupported diagnosis changes.',
            ]),
        ],
        aiCopilotScenarioTags($context, ['billing review', 'diagnosis link', 'claim support'])
    );
}

function aiCopilotBuildPatientFriendlyResponse(array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'No demo patient is selected, so this is a general patient-friendly explanation framework only.',
            [
                aiCopilotBuildSection('Patient-friendly explanation', ['Restate the plan in plain language, what needs follow-up, and what warning symptoms should prompt earlier contact.']),
                aiCopilotBuildSection('Safety reminders', ['Keep the explanation supportive but avoid presenting a final diagnosis or treatment order.']),
            ],
            ['general patient education']
        );
    }

    $facts = aiCopilotExtractClinicalFacts($context);

    return match ($facts['patient_key']) {
        'DEMO-BILL-1003' => aiCopilotBuildResponse(
            'Here is a patient-friendly beta explanation draft that still needs clinician review.',
            [
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'Your chart suggests that your chest pressure and shortness of breath need careful review because some urgent heart-related causes still need to be ruled out.',
                    'We would want to check how you are feeling right now, review your heart-risk history, and make sure important testing and monitoring are considered quickly if symptoms continue.',
                ]),
                aiCopilotBuildSection('Safety reminders', [
                    'Tell the care team right away if the chest pressure gets worse, breathing becomes harder, or you feel faint.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['patient education', 'chest pain precautions'])
        ),
        'DEMO-PCP-1001' => aiCopilotBuildResponse(
            'Here is a patient-friendly beta explanation draft that still needs clinician review.',
            [
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'Your foot sore is concerning because diabetes and numbness can make wounds harder to notice and slower to heal.',
                    'We would want to look closely at the wound, make sure the redness and drainage are not getting worse, and talk about wound care plus blood sugar control.',
                ]),
                aiCopilotBuildSection('Safety reminders', [
                    'Please report fever, worsening redness, more drainage, or quickly increasing pain right away.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['patient education', 'foot wound precautions'])
        ),
        default => aiCopilotBuildResponse(
            'Here is a patient-friendly beta explanation draft that still needs clinician review.',
            [
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'Your symptoms suggest that your asthma may not be fully controlled right now, especially because you are coughing, wheezing, and needing the rescue inhaler more often.',
                    'We would want to review how you are using your inhalers, what might be triggering symptoms, and what warning signs mean you need faster care.',
                ]),
                aiCopilotBuildSection('Safety reminders', [
                    'Please get urgent help if breathing becomes much harder, the rescue inhaler is not helping, or you cannot speak comfortably.',
                ]),
            ],
            aiCopilotScenarioTags($context, ['patient education', 'asthma precautions'])
        ),
    };
}

function aiCopilotBuildNoPatientResponse(string $answer, array $sections, array $tags): array
{
    return aiCopilotBuildResponse(AI_COPILOT_NO_PATIENT_NOTE . ' ' . $answer, $sections, $tags);
}

function aiCopilotBuildResponse(string $answer, array $sections = [], array $tags = []): array
{
    return [
        'answer' => aiCopilotCleanText($answer),
        'sections' => array_values(array_filter($sections, static fn($section) => is_array($section))),
        'tags' => aiCopilotFinalizeTags($tags),
    ];
}

function aiCopilotBuildSection(string $title, array $items, string $tone = 'neutral'): array
{
    $cleanItems = [];
    foreach ($items as $item) {
        $itemText = aiCopilotCleanText((string) $item);
        if ($itemText !== '') {
            $cleanItems[] = $itemText;
        }
    }

    return [
        'title' => $title,
        'tone' => in_array($tone, ['neutral', 'red', 'yellow'], true) ? $tone : 'neutral',
        'items' => $cleanItems,
    ];
}

function aiCopilotRoleAllowsMode(string $role, string $mode): bool
{
    $roleCatalog = aiCopilotRoleCatalog();
    return in_array($mode, $roleCatalog[$role]['allowed_modes'] ?? [], true);
}

function aiCopilotRoleSafetyNote(string $role): string
{
    return match ($role) {
        'front_desk' => 'Administrative workflow only. Minimum necessary PHI. Human review required before any outreach is sent.',
        'billing' => 'Billing-support only. Human billing and compliance review required. No claims are submitted automatically.',
        'nurse' => 'Draft support only. Human nursing and clinician review required. Medication changes and orders are restricted.',
        default => AI_COPILOT_SAFETY_NOTE,
    };
}

function aiCopilotRoleSystemInstruction(string $role): string
{
    return match ($role) {
        'nurse' => 'Nurse role: you may help with education, symptom-triage support, follow-up reminders, care instructions, medication education based on the current plan, note support, and escalation red flags. Never change medication plans, place orders, or finalize treatment.',
        'billing' => 'Billing Staff role: you may help with billing-documentation review, missing documentation checklists, coding-support suggestions, and billing-facing visit summaries. Never submit claims automatically, never assign final codes with certainty, and avoid unnecessary deep clinical reasoning.',
        'front_desk' => 'Front Desk role: use minimum necessary PHI only. You may help with appointment information, contact confirmation, reminder drafting, and general administrative workflow. Do not disclose diagnoses, medication details, labs, treatment plans, or deep clinical notes.',
        default => 'Doctor role: provide clinical reasoning support only. Use language such as consider, possible, review, or clinician should verify. Never claim final diagnosis certainty, place orders, prescribe, or write back to the chart automatically.',
    };
}

function aiCopilotMaybeBuildRolePermissionResponse(string $role, string $mode, string $message, array $context): array
{
    $value = strtolower($message);

    if (!aiCopilotRoleAllowsMode($role, $mode)) {
        return match ($role) {
            'front_desk' => array_merge(aiCopilotBuildResponse(
                'I can help with scheduling and basic administrative information, but clinical details are restricted for this role.',
                [],
                ['Front desk', 'Minimum PHI', 'Review needed']
            ), ['restriction_type' => 'front_desk_phi_limit']),
            'billing' => array_merge(aiCopilotBuildResponse(
                'I can help with billing review, but I cannot submit claims automatically or expose unnecessary clinical detail in this role.',
                [],
                ['Billing review', 'Review needed']
            ), ['restriction_type' => 'billing_role_scope_limit']),
            'nurse' => array_merge(aiCopilotBuildResponse(
                'I can help with education, follow-up, and escalation support, but that request is outside the nurse role in this demo.',
                [],
                ['Review needed', 'Follow-up']
            ), ['restriction_type' => 'nurse_role_scope_limit']),
            default => array_merge(aiCopilotBuildResponse(
                'I can help with clinical support and review-oriented draft guidance, but that request is outside the configured doctor role workflows in this demo.',
                [],
                ['Review needed', 'Chart context']
            ), ['restriction_type' => 'doctor_role_scope_limit']),
        };
    }

    if ($role === 'nurse' && preg_match('/(change|start|stop|increase|decrease|switch).*(medication|drug|dose|insulin|inhaler|pill)|medication change/', $value)) {
        return array_merge(aiCopilotBuildResponse(
            'I can help explain the current medication plan and flag items to review, but medication changes should be handled by the prescribing clinician.',
            [],
            ['Medication review', 'Review needed']
        ), ['restriction_type' => 'nurse_medication_change_block']);
    }

    if ($role === 'billing' && preg_match('/submit.*claim|resubmit.*claim|send.*claim/', $value)) {
        return array_merge(aiCopilotBuildResponse(
            'I can help prepare a billing review checklist, but I cannot submit claims automatically.',
            [],
            ['Billing review', 'Review needed']
        ), ['restriction_type' => 'billing_claim_submission_block']);
    }

    if ($role === 'billing' && preg_match('/medication|lab|diagnosis|differential|a1c|ldl|creatinine|egfr|treatment|soap|clinical note|plan|prescri/', $value)) {
        return array_merge(aiCopilotBuildResponse(
            'Detailed clinical interpretation is not available for the Billing Staff role. I can help with insurance context, claim workflow, payment status, or route this for clinician review instead.',
            [],
            ['Billing review', 'Review needed']
        ), ['restriction_type' => 'billing_clinical_scope_block']);
    }

    if ($role === 'front_desk' && preg_match('/medication|lab|diagnosis|differential|a1c|treatment|soap|clinical note|plan|prescri/', $value)) {
        return array_merge(aiCopilotBuildResponse(
            'This role only has access to scheduling and basic contact workflows. Medication details are restricted.',
            [],
            ['Front desk', 'Minimum PHI', 'Review needed']
        ), ['restriction_type' => 'front_desk_phi_limit']);
    }

    if ($role === 'front_desk' && preg_match('/everything about|show me everything|all chart data|entire chart|full history/', $value)) {
        return array_merge(aiCopilotBuildResponse(
            'Clinical chart details are restricted for the Front Desk role. I can help with appointment information, contact confirmation, reminder drafting, or routing this to clinical staff.',
            [],
            ['Front desk', 'Minimum PHI', 'Review needed']
        ), ['restriction_type' => 'front_desk_phi_limit']);
    }

    return [];
}

function aiCopilotFilterContextForRole(array $context, string $role, string $mode): array
{
    $context['role'] = $role;

    if (!aiCopilotContextHasPatient($context)) {
        return $context;
    }

    return match ($role) {
        'nurse' => aiCopilotBuildNurseRoleContext($context),
        'billing' => aiCopilotBuildBillingRoleContext($context),
        'front_desk' => aiCopilotBuildFrontDeskRoleContext($context),
        default => $context,
    };
}

function aiCopilotBuildNurseRoleContext(array $context): array
{
    $facts = aiCopilotExtractClinicalFacts($context);
    $notes = [
        [
            'date' => $context['notes'][0]['date'] ?? '',
            'title' => 'DEMO AI - Clinical Context',
            'body' => implode("\n", array_filter([
                'Chief complaint: ' . aiCopilotFallbackValue($facts['chief_complaint'], 'Current visit concern needs review.'),
                $facts['history_of_present_illness'] !== '' ? 'History of present illness: ' . $facts['history_of_present_illness'] : '',
                $facts['symptoms'] !== '' ? 'Symptoms: ' . $facts['symptoms'] : '',
                $facts['allergy_line'] !== '' ? 'Allergies: ' . $facts['allergy_line'] : '',
                $facts['recent_exam'] !== '' ? 'Recent exam: ' . $facts['recent_exam'] : '',
                $facts['medication_concerns'] !== '' ? 'Medication concerns: ' . $facts['medication_concerns'] : '',
                $facts['follow_up_considerations'] !== '' ? 'Follow-up considerations: ' . $facts['follow_up_considerations'] : '',
            ])),
        ],
    ];

    foreach ($context['notes'] as $note) {
        if (($note['title'] ?? '') === 'DEMO AI - Recent Encounter Note') {
            $notes[] = $note;
            break;
        }
    }

    $medications = [];
    foreach ($context['medications'] as $medication) {
        $medications[] = [
            'drug' => $medication['drug'] ?? '',
            'dosage' => $medication['dosage'] ?? '',
            'start_date' => $medication['start_date'] ?? '',
        ];
    }

    $context['notes'] = $notes;
    $context['medications'] = $medications;
    $context['billing'] = ['encounter' => [], 'rows' => [], 'claim' => [], 'payment_summary' => []];
    $context['primary_insurance'] = [];

    return $context;
}

function aiCopilotBuildBillingRoleContext(array $context): array
{
    $facts = aiCopilotExtractClinicalFacts($context);
    $problemTitles = [];
    foreach (array_slice($context['problems'], 0, 4) as $problem) {
        if (!empty($problem['title'])) {
            $problemTitles[] = $problem['title'];
        }
    }

    return [
        'role' => 'billing',
        'mode' => $context['mode'] ?? '',
        'scenario' => $context['scenario'] ?? '',
        'patient_selected' => $context['patient_selected'] ?? false,
        'patient' => [
            'pid' => $context['patient']['pid'] ?? null,
            'pubpid' => $context['patient']['pubpid'] ?? '',
            'name' => $context['patient']['name'] ?? '',
        ],
        'next_appointment' => [],
        'appointments' => [],
        'encounters' => array_slice($context['encounters'], 0, 1),
        'notes' => [],
        'problems' => [],
        'allergies' => [],
        'medications' => [],
        'latest_vitals' => [],
        'primary_insurance' => $context['primary_insurance'],
        'billing' => $context['billing'],
        'billing_summary' => [
            'visit_type' => $facts['visit_type'],
            'chief_complaint' => $facts['chief_complaint'],
            'documentation_summary' => $facts['billing_support'],
            'problems_addressed' => $problemTitles,
            'follow_up_considerations' => $facts['follow_up_considerations'],
            'encounter_reason' => $context['encounters'][0]['reason'] ?? '',
            'payment_summary' => $context['billing']['payment_summary'] ?? [],
        ],
    ];
}

function aiCopilotBuildFrontDeskRoleContext(array $context): array
{
    $appointment = $context['next_appointment'];
    $location = implode(', ', array_values(array_filter([
        aiCopilotCleanText($appointment['location'] ?? ''),
        aiCopilotCleanText($appointment['room'] ?? ''),
    ], static fn($value) => $value !== '')));
    $phone = aiCopilotFirstNonEmpty([
        $context['patient']['phone_cell'] ?? '',
        $context['patient']['phone_home'] ?? '',
    ]);

    return [
        'role' => 'front_desk',
        'mode' => $context['mode'] ?? '',
        'scenario' => $context['scenario'] ?? '',
        'patient_selected' => $context['patient_selected'] ?? false,
        'patient' => [
            'pid' => $context['patient']['pid'] ?? null,
            'pubpid' => $context['patient']['pubpid'] ?? '',
            'name' => $context['patient']['name'] ?? '',
            'fname' => $context['patient']['fname'] ?? '',
            'lname' => $context['patient']['lname'] ?? '',
            'email' => $context['patient']['email'] ?? '',
            'phone' => $phone,
        ],
        'next_appointment' => [
            'date' => $appointment['date'] ?? '',
            'start_time' => $appointment['start_time'] ?? '',
            'end_time' => $appointment['end_time'] ?? '',
            'appointment_type' => $appointment['appointment_type'] ?? ($appointment['title'] ?? ''),
            'provider_name' => $appointment['provider_name'] ?? '',
            'location' => $location,
            'check_in_instructions' => $appointment['check_in_instructions'] ?? '',
            'check_in_status' => $appointment['check_in_status'] ?? '',
        ],
        'appointments' => [],
        'encounters' => [],
        'notes' => [],
        'problems' => [],
        'allergies' => [],
        'medications' => [],
        'latest_vitals' => [],
        'primary_insurance' => [],
        'billing' => ['encounter' => [], 'rows' => [], 'claim' => [], 'payment_summary' => []],
    ];
}

function aiCopilotBuildFrontDeskFallbackResponse(string $mode, string $message, array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'Select a demo patient to use front-desk workflows like appointment lookup, contact confirmation, or reminder drafting.',
            [
                aiCopilotBuildSection('Administrative summary', ['Pick a demo patient to see appointment, contact, and check-in details.']),
            ],
            ['Front desk', 'Minimum PHI']
        );
    }

    $appointment = $context['next_appointment'];
    $formattedDateTime = aiCopilotFormatAppointmentDateTime($appointment['date'] ?? '', $appointment['start_time'] ?? '');
    $type = aiCopilotFallbackValue($appointment['appointment_type'] ?? '', 'scheduled visit');
    $provider = aiCopilotFallbackValue($appointment['provider_name'] ?? '', 'the assigned provider');
    $location = aiCopilotFallbackValue($appointment['location'] ?? '', 'the clinic');
    $email = aiCopilotFallbackValue($context['patient']['email'] ?? '', 'no demo email on file');
    $phone = aiCopilotFallbackValue($context['patient']['phone'] ?? '', 'no demo phone on file');
    $instructions = aiCopilotFallbackValue($appointment['check_in_instructions'] ?? '', 'Please arrive 15 minutes early and bring any required documents.');
    $status = aiCopilotFallbackValue($appointment['check_in_status'] ?? '', 'Not checked in');
    $firstName = aiCopilotFallbackValue($context['patient']['fname'] ?? '', $context['patient']['name'] ?? 'the patient');

    return match ($mode) {
        'patient_contact' => aiCopilotBuildResponse(
            $context['patient']['name'] . '\'s demo contact information is ' . $email . ' and ' . $phone . '.',
            [
                aiCopilotBuildSection('Contact details', [
                    'Email: ' . $email,
                    'Phone: ' . $phone,
                ]),
                aiCopilotBuildSection('Contact workflow reminders', [
                    'Use minimum necessary PHI when confirming contact details.',
                    'Confirm the patient is still using this email and phone number before outreach.',
                ]),
            ],
            ['Front desk', 'Contact', 'Minimum PHI']
        ),
        'send_reminder' => aiCopilotBuildResponse(
            'I drafted a reminder for ' . $context['patient']['name'] . ' using scheduling details only.',
            [
                aiCopilotBuildSection('Reminder draft', [
                    'Subject: Appointment Reminder from OpenEMR Demo Clinic',
                    'Hello ' . $firstName . ', this is a reminder that your next appointment is scheduled for ' . $formattedDateTime . ' with ' . $provider . ' for ' . $type . ' at ' . $location . '. Please arrive 15 minutes early and bring any required documents. Thank you, OpenEMR Demo Clinic.',
                ]),
                aiCopilotBuildSection('Delivery details', [
                    'Recipient: ' . $email,
                    'Check-in instructions: ' . $instructions,
                    'Status: ' . $status,
                ]),
            ],
            ['Front desk', 'Reminder', 'Minimum PHI']
        ),
        'front_desk_summary' => aiCopilotBuildResponse(
            $context['patient']['name'] . ' has a demo appointment workflow ready for front-desk review.',
            [
                aiCopilotBuildSection('Administrative summary', [
                    'Contact on file: ' . $email . ' / ' . $phone,
                    'Check-in status: ' . $status,
                ]),
                aiCopilotBuildSection('Next appointment', [
                    $formattedDateTime . ' with ' . $provider . ' for ' . $type . ' at ' . $location . '.',
                ]),
                aiCopilotBuildSection('Contact details', [
                    'Check-in instructions: ' . $instructions,
                ]),
            ],
            ['Front desk', 'Appointment', 'Minimum PHI']
        ),
        default => aiCopilotBuildResponse(
            $context['patient']['name'] . '\'s next appointment is scheduled for ' . $formattedDateTime . ' with ' . $provider . ' for ' . $type . ' at ' . $location . '.',
            [
                aiCopilotBuildSection('Appointment details', [
                    'Appointment type: ' . $type,
                    'Provider: ' . $provider,
                    'Location: ' . $location,
                ]),
                aiCopilotBuildSection('Check-in instructions', [
                    $instructions,
                    'Check-in status: ' . $status,
                ]),
            ],
            ['Front desk', 'Appointment', 'Minimum PHI']
        ),
    };
}

function aiCopilotBuildBillingStaffFallbackResponse(string $mode, string $message, array $context): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return aiCopilotBuildNoPatientResponse(
            'Select a demo patient to use billing-review workflows.',
            [
                aiCopilotBuildSection('Billing documentation summary', ['Choose a demo patient to review visit context, missing documentation, or claim-support details.']),
            ],
            ['Billing review', 'Review needed']
        );
    }

    $summary = $context['billing_summary'] ?? [];
    $visitType = aiCopilotFallbackValue($summary['visit_type'] ?? '', 'established outpatient visit');
    $complaint = aiCopilotFallbackValue($summary['chief_complaint'] ?? '', 'the documented visit concern');
    $encounterReason = aiCopilotFallbackValue($summary['encounter_reason'] ?? '', 'documented encounter context');
    $documentationSummary = aiCopilotFallbackValue($summary['documentation_summary'] ?? '', 'Document the core symptom story, assessment, and plan reviewed during the visit.');
    $claimIssue = aiCopilotClaimIssueFromContext($context);
    $claimChecks = aiCopilotClaimChecksFromContext($context);

    if ($mode === 'visit_summary') {
        return aiCopilotBuildResponse(
            'Here is a billing-facing visit summary draft for coder and clinician review.',
            [
                aiCopilotBuildSection('Visit summary', [
                    'Visit type: ' . $visitType,
                    'Encounter reason: ' . $encounterReason,
                    'High-level concern: ' . $complaint,
                ]),
                aiCopilotBuildSection('Key concerns addressed', [
                    'Problems addressed: ' . aiCopilotFallbackValue(aiCopilotJoinList($summary['problems_addressed'] ?? []), 'See the signed encounter note for the full problem list.'),
                ]),
                aiCopilotBuildSection('Plan discussed', [
                    $documentationSummary,
                ]),
                aiCopilotBuildSection('Follow-up instructions', [
                    aiCopilotFallbackValue($summary['follow_up_considerations'] ?? '', 'Review what follow-up or escalation instructions were documented in the final note.'),
                ]),
                aiCopilotBuildSection('Patient-friendly explanation', [
                    'This summary is for billing-support review only and does not replace the signed chart note.',
                ]),
            ],
            ['Billing review', 'Visit summary', 'Review needed']
        );
    }

    if ($mode === 'billing_review') {
        return aiCopilotBuildResponse(
            'I can help with billing review, but I cannot submit claims automatically. Here is the claim-support issue to review first.',
            [
                aiCopilotBuildSection('Plain-language issue', [
                    aiCopilotFallbackValue($claimIssue, 'The claim appears to need diagnosis-to-procedure review before any resubmission.'),
                ]),
                aiCopilotBuildSection('What to check first', $claimChecks !== [] ? $claimChecks : [
                    'Compare the billed service to the encounter documentation and diagnosis linkage.',
                    'Confirm payer/member details before any coder action.',
                ]),
                aiCopilotBuildSection('Guardrails', [
                    'Do not submit or resubmit claims from this demo assistant.',
                    'Do not assign final codes with certainty unless the signed documentation supports them.',
                ]),
            ],
            ['Billing review', 'Review needed', 'Chart context']
        );
    }

    return aiCopilotBuildResponse(
        'This is a billing-support suggestion only. Coder and clinician review are still required.',
        [
            aiCopilotBuildSection('Billing documentation summary', [
                'Visit type: ' . $visitType,
                'Chief complaint: ' . $complaint,
                'Encounter reason: ' . $encounterReason,
            ]),
            aiCopilotBuildSection('Possible coding considerations', [
                'Keep coding discussion tied to the documented history, exam, and decision-making rather than this beta draft.',
                'Use diagnosis linkage only if the encounter documentation clearly supports it.',
            ]),
            aiCopilotBuildSection('Missing documentation', [
                $documentationSummary,
                aiCopilotFallbackValue($summary['follow_up_considerations'] ?? '', 'Clarify any follow-up or escalation instructions that were part of the visit.'),
            ]),
            aiCopilotBuildSection('Risk / compliance reminders', [
                'No automatic claim submission is allowed.',
                'No definitive coding assignment should be made without human review.',
            ]),
        ],
        ['Billing review', 'Review needed', 'Chart context']
    );
}

function aiCopilotSendReminderEmailAction(string $role, array $context): array
{
    if ($role !== 'front_desk') {
        return [
            'ok' => false,
            'sent' => false,
            'demo' => true,
            'message' => 'Only the Front Desk role can send appointment reminder emails in this demo.',
            'tags' => ['Front desk', 'Minimum PHI', 'Review needed'],
            'sources' => aiCopilotBuildSources($context, 'send_reminder'),
            'safety_note' => aiCopilotRoleSafetyNote($role),
        ];
    }

    if (!aiCopilotContextHasPatient($context)) {
        return [
            'ok' => false,
            'sent' => false,
            'demo' => true,
            'message' => 'Select a demo patient before sending a reminder email.',
            'tags' => ['Front desk', 'Reminder', 'Minimum PHI'],
            'sources' => aiCopilotBuildSources($context, 'send_reminder'),
            'safety_note' => aiCopilotRoleSafetyNote($role),
        ];
    }

    $email = aiCopilotCleanText($context['patient']['email'] ?? '');
    $appointment = $context['next_appointment'] ?? [];
    if ($email === '' || ($appointment['date'] ?? '') === '') {
        return [
            'ok' => false,
            'sent' => false,
            'demo' => true,
            'message' => 'A demo email address and upcoming appointment are required before sending a reminder.',
            'tags' => ['Front desk', 'Reminder', 'Minimum PHI'],
            'sources' => aiCopilotBuildSources($context, 'send_reminder'),
            'safety_note' => aiCopilotRoleSafetyNote($role),
        ];
    }

    $subject = 'Appointment Reminder from OpenEMR Demo Clinic';
    $body = aiCopilotBuildReminderEmailBody($context);
    $sender = aiCopilotResolveReminderSender();

    $sent = false;
    if ($sender !== '' && filter_var($sender, FILTER_VALIDATE_EMAIL) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer(true);
        try {
            $mail->setFrom($sender, 'OpenEMR Demo Clinic');
            $mail->addAddress($email, $context['patient']['name'] ?? '');
            $mail->isMail();
            $mail->Subject = $subject;
            $mail->Body = $body;
            $sent = $mail->send();
        } catch (\Throwable) {
            $sent = false;
        }
    }

    return [
        'ok' => true,
        'sent' => $sent,
        'demo' => !$sent,
        'message' => $sent
            ? 'Reminder email sent to ' . $email . '.'
            : 'Demo reminder prepared for ' . $email . '. Email sending is not configured in this environment.',
        'tags' => ['Front desk', 'Reminder', 'Minimum PHI'],
        'sources' => aiCopilotBuildSources($context, 'send_reminder'),
        'safety_note' => aiCopilotRoleSafetyNote($role),
    ];
}

function aiCopilotResolveReminderSender(): string
{
    $sender = OEGlobalsBag::getInstance()->getString('patient_reminder_sender_email');
    if ($sender === '') {
        $sender = OEGlobalsBag::getInstance()->getString('practice_return_email_path');
    }

    return aiCopilotCleanText($sender);
}

function aiCopilotBuildReminderEmailBody(array $context): string
{
    $appointment = $context['next_appointment'] ?? [];
    $location = aiCopilotFallbackValue($appointment['location'] ?? '', 'OpenEMR Demo Clinic');

    return "Hello " . aiCopilotFallbackValue($context['patient']['fname'] ?? '', 'Patient') . ",\n\n"
        . 'This is a reminder that your next appointment is scheduled for '
        . aiCopilotFormatAppointmentDateTime($appointment['date'] ?? '', $appointment['start_time'] ?? '')
        . ' with ' . aiCopilotFallbackValue($appointment['provider_name'] ?? '', 'your provider')
        . ' for ' . aiCopilotFallbackValue($appointment['appointment_type'] ?? '', 'your scheduled visit')
        . ' at ' . $location . ".\n\n"
        . "Please arrive 15 minutes early and bring any required documents.\n\n"
        . "Thank you,\nOpenEMR Demo Clinic";
}

function aiCopilotFormatAppointmentDateTime(string $date, string $time): string
{
    if ($date === '') {
        return 'the scheduled appointment time';
    }

    $timestamp = strtotime(trim($date . ' ' . $time));
    if ($timestamp === false) {
        return trim($date . ' ' . $time);
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function aiCopilotExtractClinicalFacts(array $context): array
{
    $clinicalNote = aiCopilotFindNoteBody($context['notes'], 'DEMO AI - Clinical Context');
    $recentEncounterNote = aiCopilotFindNoteBody($context['notes'], 'DEMO AI - Recent Encounter Note');
    $clinicalMap = aiCopilotParseLabeledNote($clinicalNote);
    $recentMap = aiCopilotParseLabeledNote($recentEncounterNote);

    $conditions = [];
    foreach ($context['problems'] as $problem) {
        if (!empty($problem['title'])) {
            $conditions[] = $problem['title'];
        }
    }

    return [
        'patient_key' => $context['patient']['pubpid'] ?? '',
        'chief_complaint' => aiCopilotMapValue($clinicalMap, 'chief complaint'),
        'visit_type' => aiCopilotMapValue($clinicalMap, 'visit type'),
        'history_of_present_illness' => aiCopilotMapValue($clinicalMap, 'history of present illness'),
        'symptoms' => aiCopilotMapValue($clinicalMap, 'symptoms'),
        'past_medical_history' => aiCopilotMapValue($clinicalMap, 'past medical history'),
        'risk_factors' => aiCopilotMapValue($clinicalMap, 'risk factors'),
        'recent_labs' => aiCopilotMapValue($clinicalMap, 'recent labs'),
        'recent_exam' => aiCopilotFirstNonEmpty([
            aiCopilotMapValue($clinicalMap, 'recent exam'),
            aiCopilotMapValue($recentMap, 'objective'),
        ]),
        'medication_concerns' => aiCopilotMapValue($clinicalMap, 'medication concerns'),
        'billing_support' => aiCopilotMapValue($clinicalMap, 'billing support'),
        'follow_up_considerations' => aiCopilotMapValue($clinicalMap, 'follow-up considerations'),
        'recent_note_subjective' => aiCopilotMapValue($recentMap, 'subjective'),
        'recent_note_assessment' => aiCopilotMapValue($recentMap, 'assessment'),
        'recent_note_plan' => aiCopilotMapValue($recentMap, 'plan'),
        'conditions' => $conditions,
        'medication_line' => aiCopilotFormatMedicationList($context['medications']),
        'allergy_line' => aiCopilotFormatConditionList($context['allergies']),
        'vitals_line' => aiCopilotFormatVitalsSummary($context['latest_vitals']),
    ];
}

function aiCopilotParseLabeledNote(string $body): array
{
    $map = [];
    if ($body === '') {
        return $map;
    }

    $lines = preg_split('/\R+/', $body) ?: [];
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$label, $value] = explode(':', $line, 2);
        $label = strtolower(trim($label));
        $value = aiCopilotCleanText($value);
        if ($label !== '' && $value !== '') {
            $map[$label] = $value;
        }
    }

    return $map;
}

function aiCopilotMapValue(array $map, string $key): string
{
    return aiCopilotCleanText($map[strtolower($key)] ?? '');
}

function aiCopilotScenarioTags(array $context, array $extraTags = []): array
{
    return aiCopilotFinalizeTags(array_merge($extraTags, ['Chart context', 'Review needed']));
}

function aiCopilotFinalizeTags(array $tags): array
{
    $preferredOrder = [
        'Front desk',
        'Appointment',
        'Contact',
        'Reminder',
        'Minimum PHI',
        'Billing review',
        'Visit summary',
        'Draft note',
        'Medication review',
        'Follow-up',
        'Red flags',
        'Patient education',
        'Chart context',
        'Review needed',
        'Beta',
    ];

    $normalized = [];
    foreach ($tags as $tag) {
        $tagText = aiCopilotNormalizePrompt($tag);
        if ($tagText === '') {
            continue;
        }

        $normalized[] = aiCopilotCanonicalTagLabel($tagText);
    }

    if ($normalized === []) {
        $normalized = ['Chart context', 'Review needed'];
    }

    $normalized = array_values(array_unique(array_filter($normalized, static fn($tag) => $tag !== '')));

    usort($normalized, static function (string $left, string $right) use ($preferredOrder): int {
        $leftIndex = array_search($left, $preferredOrder, true);
        $rightIndex = array_search($right, $preferredOrder, true);
        $leftIndex = $leftIndex === false ? 999 : $leftIndex;
        $rightIndex = $rightIndex === false ? 999 : $rightIndex;

        if ($leftIndex === $rightIndex) {
            return strcmp($left, $right);
        }

        return $leftIndex <=> $rightIndex;
    });

    return array_slice($normalized, 0, 3);
}

function aiCopilotCanonicalTagLabel(string $tag): string
{
    $value = strtolower($tag);

    return match (true) {
        preg_match('/front desk/', $value) === 1 => 'Front desk',
        preg_match('/appointment|schedule/', $value) === 1 => 'Appointment',
        preg_match('/contact/', $value) === 1 => 'Contact',
        preg_match('/reminder|outreach|email/', $value) === 1 => 'Reminder',
        preg_match('/minimum.*phi|minimum phi/', $value) === 1 => 'Minimum PHI',
        preg_match('/billing|claim|coding|cpt|icd|payer|diagnosis link/', $value) === 1 => 'Billing review',
        preg_match('/follow[- ]?up|monitoring|care coordination|offloading|podiatry|trigger review/', $value) === 1 => 'Follow-up',
        preg_match('/red flag|acs|urgent|triage|infection risk|osteomyelitis|dyspnea|precaution/', $value) === 1 => 'Red flags',
        preg_match('/medication|metformin|lisinopril|atorvastatin|aspirin|glipizide|gabapentin|albuterol|prednisone|inhaler|adherence/', $value) === 1 => 'Medication review',
        preg_match('/note|soap|documentation/', $value) === 1 => 'Draft note',
        preg_match('/visit summary|chart summary|summary/', $value) === 1 => 'Visit summary',
        preg_match('/patient education|patient-friendly/', $value) === 1 => 'Patient education',
        preg_match('/review/', $value) === 1 => 'Review needed',
        preg_match('/beta/', $value) === 1 => 'Beta',
        preg_match('/chart|context/', $value) === 1 => 'Chart context',
        default => ucfirst(substr(aiCopilotCleanText($tag), 0, 28)),
    };
}

function aiCopilotClaimIssueFromContext(array $context): string
{
    $billingNote = aiCopilotFindNoteBody($context['notes'], 'DEMO AI - Billing Claim Source');
    $issue = '';
    if ($billingNote !== '') {
        $issue = aiCopilotMapValue(aiCopilotParseLabeledNote(str_replace('. ', "\n", $billingNote)), 'plain-language issue');
    }

    $missingLinkRow = aiCopilotFindBillingRowMissingDiagnosisLink($context['billing']['rows']);
    if ($missingLinkRow !== []) {
        $issue = trim(($missingLinkRow['code_type'] ?? 'Procedure') . ' ' . ($missingLinkRow['code'] ?? '') . ' is missing a diagnosis link on the encounter.');
    }

    if ($issue === '') {
        $issue = 'The chart suggests a missing or unsupported diagnosis link on the claim.';
    }

    return $issue;
}

function aiCopilotClaimChecksFromContext(array $context): array
{
    $billingNote = aiCopilotFindNoteBody($context['notes'], 'DEMO AI - Billing Claim Source');
    if ($billingNote === '') {
        return [];
    }

    preg_match('/What to check first:\s*(.+?)\.\s*AI safety:/i', $billingNote, $matches);
    $raw = aiCopilotCleanText($matches[1] ?? '');
    return aiCopilotSplitChecklist($raw);
}

function aiCopilotBuildBillingPaymentSummary(array $notes, array $primaryInsurance, array $billingEncounter): array
{
    $billingNote = aiCopilotFindNoteBody($notes, 'DEMO AI - Marcus Billing Payment Source');
    if ($billingNote === '') {
        return [];
    }

    $billingMap = aiCopilotParseLabeledNote($billingNote);

    return [
        'patient_balance_due' => aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'patient balance due')),
        'insurance_balance_due' => aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'insurance balance due')),
        'total_balance_due' => aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'total balance due')),
        'next_payment_due_date' => aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'next patient payment due date')),
        'payer' => aiCopilotFirstNonEmpty([
            aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'payer')),
            aiCopilotCleanText($primaryInsurance['carrier'] ?? ''),
        ]),
        'plan_name' => aiCopilotFirstNonEmpty([
            aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'plan')),
            aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'plan name')),
            aiCopilotCleanText($primaryInsurance['plan_name'] ?? ''),
        ]),
        'billing_provider' => aiCopilotFirstNonEmpty([
            aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'billing provider')),
            aiCopilotCleanText($billingEncounter['location'] ?? ''),
        ]),
        'payment_note' => aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'payment note')),
        'insurance_note' => aiCopilotTrimSentenceValue(aiCopilotMapValue($billingMap, 'insurance note')),
        'source_title' => 'DEMO AI - Marcus Billing Payment Source',
        'synthetic_demo_data' => true,
    ];
}

function aiCopilotAttachClientLabPdfContext(array $context, mixed $value, string $role): array
{
    $toolOutput = aiCopilotNormalizeLabPdfContext($value);
    if ($toolOutput === []) {
        return $context;
    }

    $context['attached_lab_pdf_tool_output'] = $toolOutput;
    return $context;
}

function aiCopilotAttachUploadedLabPdfContext(
    array $context,
    ?array $uploadedLabPdf,
    bool $useSeededLabPdf,
    array $payload,
    string $role,
    string $mode,
    string $message,
    string $requestId
): array {
    if (!$useSeededLabPdf && $uploadedLabPdf === null) {
        return $context;
    }

    $patientKey = aiCopilotCleanText((string) ($context['patient']['pubpid'] ?? ''));
    $patientName = aiCopilotCleanText((string) ($context['patient']['name'] ?? ''));
    $toolOutput = attach_and_vectorize_lab_pdf([
        'request_id' => $requestId,
        'role' => $role,
        'mode' => $mode,
        'prompt' => $message,
        'patient_key' => $patientKey,
        'patient_name' => $patientName,
        'file' => $uploadedLabPdf,
        'use_seeded_demo' => $useSeededLabPdf,
        'attachment_purpose' => aiCopilotCleanText((string) ($payload['attachment_purpose'] ?? 'lab_pdf_ingestion')),
    ]);

    if ($toolOutput !== []) {
        $context['attached_lab_pdf_tool_output'] = $toolOutput;
    }

    return $context;
}

function aiCopilotNormalizeLabPdfContext(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $toolOutput = is_array($value['toolOutput'] ?? null)
        ? $value['toolOutput']
        : $value;
    if (!is_array($toolOutput)) {
        return [];
    }

    $documentMetadata = is_array($toolOutput['documentMetadata'] ?? null) ? $toolOutput['documentMetadata'] : [];
    $extractedFacts = [];
    foreach (($toolOutput['extractedFacts'] ?? []) as $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $name = aiCopilotCleanText((string) ($fact['name'] ?? ''));
        $valueText = aiCopilotCleanText((string) ($fact['value'] ?? ''));
        $interpretation = aiCopilotCleanText((string) ($fact['interpretation'] ?? ''));
        if ($name === '' && $valueText === '' && $interpretation === '') {
            continue;
        }

        $extractedFacts[] = [
            'name' => $name,
            'value' => $valueText,
            'interpretation' => $interpretation,
            'source_label' => aiCopilotCleanText((string) ($fact['sourceLabel'] ?? 'Attached Lab PDF')),
        ];
    }

    $missingData = [];
    foreach (($toolOutput['missingData'] ?? []) as $item) {
        $itemText = aiCopilotCleanText((string) $item);
        if ($itemText !== '') {
            $missingData[] = $itemText;
        }
    }

    $sourceMetadata = is_array($toolOutput['sourceMetadata'] ?? null) ? $toolOutput['sourceMetadata'] : [];
    $retrieval = is_array($toolOutput['retrieval'] ?? null) ? $toolOutput['retrieval'] : [];
    $retrievalChunks = [];
    foreach (($retrieval['chunks'] ?? []) as $chunk) {
        if (!is_array($chunk)) {
            continue;
        }

        $chunkId = aiCopilotCleanText((string) ($chunk['id'] ?? ''));
        $chunkText = aiCopilotCleanText((string) ($chunk['chunkText'] ?? $chunk['chunk_text'] ?? ''));
        if ($chunkId === '' && $chunkText === '') {
            continue;
        }

        $retrievalChunks[] = [
            'id' => $chunkId,
            'chunk_text' => $chunkText,
            'file_name' => aiCopilotCleanText((string) ($chunk['fileName'] ?? $chunk['file_name'] ?? '')),
            'source_page' => isset($chunk['sourcePage']) && is_numeric($chunk['sourcePage'])
                ? (int) $chunk['sourcePage']
                : (isset($chunk['source_page']) && is_numeric($chunk['source_page']) ? (int) $chunk['source_page'] : null),
            'score' => isset($chunk['score']) && is_numeric($chunk['score']) ? (float) $chunk['score'] : null,
            'uploaded_at' => aiCopilotCleanText((string) ($chunk['uploadedAt'] ?? $chunk['uploaded_at'] ?? '')),
            'extraction_method' => aiCopilotCleanText((string) ($chunk['extractionMethod'] ?? $chunk['extraction_method'] ?? '')),
            'chunk_index' => isset($chunk['chunkIndex']) && is_numeric($chunk['chunkIndex'])
                ? (int) $chunk['chunkIndex']
                : (isset($chunk['chunk_index']) && is_numeric($chunk['chunk_index']) ? (int) $chunk['chunk_index'] : null),
            'embedding' => array_values(array_map(static fn($value) => (float) $value, is_array($chunk['embedding'] ?? null) ? $chunk['embedding'] : [])),
        ];
    }

    $vectorizedResult = [];
    foreach (($toolOutput['vectorizedResult'] ?? $toolOutput['vectorized_result'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $vectorizedResult[] = [
            'id' => aiCopilotCleanText((string) ($item['id'] ?? '')),
            'file_name' => aiCopilotCleanText((string) ($item['fileName'] ?? $item['file_name'] ?? '')),
            'chunk_index' => isset($item['chunkIndex']) && is_numeric($item['chunkIndex'])
                ? (int) $item['chunkIndex']
                : (isset($item['chunk_index']) && is_numeric($item['chunk_index']) ? (int) $item['chunk_index'] : null),
            'source_page' => isset($item['sourcePage']) && is_numeric($item['sourcePage'])
                ? (int) $item['sourcePage']
                : (isset($item['source_page']) && is_numeric($item['source_page']) ? (int) $item['source_page'] : null),
            'text_preview' => aiCopilotCleanText((string) ($item['textPreview'] ?? $item['text_preview'] ?? '')),
            'embedding' => array_values(array_map(static fn($value) => (float) $value, is_array($item['embedding'] ?? null) ? $item['embedding'] : [])),
            'score' => isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
        ];
    }

    $safetyMetadata = is_array($toolOutput['safetyMetadata'] ?? null) ? $toolOutput['safetyMetadata'] : [];

    return [
        'tool' => aiCopilotCleanText((string) ($toolOutput['tool'] ?? AI_COPILOT_LAB_PDF_TOOL_NAME)),
        'status' => aiCopilotCleanText((string) ($toolOutput['status'] ?? '')),
        'ingestion_status' => aiCopilotCleanText((string) ($toolOutput['ingestionStatus'] ?? $toolOutput['ingestion_status'] ?? '')),
        'safe_message' => aiCopilotCleanText((string) ($toolOutput['safeMessage'] ?? '')),
        'extraction_method' => aiCopilotCleanText((string) ($toolOutput['extractionMethod'] ?? '')),
        'extracted_text_preview' => aiCopilotCleanText((string) ($toolOutput['extractedTextPreview'] ?? $toolOutput['extracted_text_preview'] ?? '')),
        'number_of_chunks' => isset($toolOutput['numberOfChunks']) && is_numeric($toolOutput['numberOfChunks'])
            ? (int) $toolOutput['numberOfChunks']
            : (isset($toolOutput['number_of_chunks']) && is_numeric($toolOutput['number_of_chunks']) ? (int) $toolOutput['number_of_chunks'] : 0),
        'document_metadata' => [
            'title' => aiCopilotCleanText((string) ($documentMetadata['title'] ?? '')),
            'mime_type' => aiCopilotCleanText((string) ($documentMetadata['mimeType'] ?? 'application/pdf')),
            'size' => isset($documentMetadata['size']) && is_numeric($documentMetadata['size']) ? (int) $documentMetadata['size'] : null,
            'seeded_demo' => !empty($documentMetadata['seededDemo']),
            'patient_key' => aiCopilotCleanText((string) ($documentMetadata['patientKey'] ?? '')),
            'patient_name' => aiCopilotCleanText((string) ($documentMetadata['patientName'] ?? '')),
            'uploaded_at' => aiCopilotCleanText((string) ($documentMetadata['uploadedAt'] ?? '')),
        ],
        'source_metadata' => [
            'file_name' => aiCopilotCleanText((string) ($sourceMetadata['fileName'] ?? $sourceMetadata['file_name'] ?? '')),
            'uploaded_at' => aiCopilotCleanText((string) ($sourceMetadata['uploadedAt'] ?? $sourceMetadata['uploaded_at'] ?? '')),
            'source_type' => aiCopilotCleanText((string) ($sourceMetadata['sourceType'] ?? $sourceMetadata['source_type'] ?? 'lab_pdf')),
            'source_label' => aiCopilotCleanText((string) ($sourceMetadata['sourceLabel'] ?? $sourceMetadata['source_label'] ?? 'Uploaded lab PDF')),
            'chunk_count' => isset($sourceMetadata['chunkCount']) && is_numeric($sourceMetadata['chunkCount'])
                ? (int) $sourceMetadata['chunkCount']
                : (isset($sourceMetadata['chunk_count']) && is_numeric($sourceMetadata['chunk_count']) ? (int) $sourceMetadata['chunk_count'] : 0),
            'request_id' => aiCopilotCleanText((string) ($sourceMetadata['requestId'] ?? $sourceMetadata['request_id'] ?? '')),
        ],
        'extracted_facts' => array_slice($extractedFacts, 0, 12),
        'missing_data' => array_slice(array_values(array_unique($missingData)), 0, 10),
        'missing_data_flags' => array_slice(array_values(array_unique($missingData)), 0, 10),
        'retrieval' => [
            'chunk_ids' => array_values(array_filter(array_map(static fn($item) => aiCopilotCleanText((string) $item), $retrieval['chunkIds'] ?? $retrieval['chunk_ids'] ?? []), static fn($item) => $item !== '')),
            'chunk_count' => isset($retrieval['chunkCount']) && is_numeric($retrieval['chunkCount'])
                ? (int) $retrieval['chunkCount']
                : (isset($retrieval['chunk_count']) && is_numeric($retrieval['chunk_count']) ? (int) $retrieval['chunk_count'] : count($retrievalChunks)),
            'chunks' => $retrievalChunks,
        ],
        'vectorized_result' => $vectorizedResult,
        'safety' => [
            'draft_only' => !empty($toolOutput['safety']['draftOnly']),
            'review_required' => !empty($toolOutput['safety']['reviewRequired']),
        ],
        'safety_metadata' => [
            'prompt_injection_detected' => !empty($safetyMetadata['promptInjectionDetected']) || !empty($safetyMetadata['prompt_injection_detected']),
            'prompt_injection_matches' => array_values(array_filter(array_map('aiCopilotCleanText', $safetyMetadata['promptInjectionMatches'] ?? $safetyMetadata['prompt_injection_matches'] ?? []), static fn($item) => $item !== '')),
            'untrusted_document_text' => !empty($safetyMetadata['untrustedDocumentText']) || !empty($safetyMetadata['untrusted_document_text']),
            'no_chart_write' => !empty($safetyMetadata['noChartWrite']) || !empty($safetyMetadata['no_chart_write']),
            'ocr_required' => !empty($safetyMetadata['ocrRequired']) || !empty($safetyMetadata['ocr_required']),
        ],
    ];
}

function aiCopilotLabPdfToolOutputForClient(array $toolOutput): array
{
    if ($toolOutput === []) {
        return [];
    }

    $extractedFacts = [];
    foreach (($toolOutput['extracted_facts'] ?? []) as $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $extractedFacts[] = [
            'name' => aiCopilotCleanText((string) ($fact['name'] ?? '')),
            'value' => aiCopilotCleanText((string) ($fact['value'] ?? '')),
            'interpretation' => aiCopilotCleanText((string) ($fact['interpretation'] ?? '')),
            'sourceLabel' => aiCopilotCleanText((string) ($fact['source_label'] ?? 'Attached Lab PDF')),
        ];
    }

    return [
        'tool' => aiCopilotCleanText((string) ($toolOutput['tool'] ?? AI_COPILOT_LAB_PDF_TOOL_NAME)),
        'status' => aiCopilotCleanText((string) ($toolOutput['status'] ?? '')),
        'ingestionStatus' => aiCopilotCleanText((string) ($toolOutput['ingestion_status'] ?? '')),
        'safeMessage' => aiCopilotCleanText((string) ($toolOutput['safe_message'] ?? '')),
        'extractionMethod' => aiCopilotCleanText((string) ($toolOutput['extraction_method'] ?? '')),
        'extractedTextPreview' => aiCopilotCleanText((string) ($toolOutput['extracted_text_preview'] ?? '')),
        'numberOfChunks' => $toolOutput['number_of_chunks'] ?? 0,
        'documentMetadata' => [
            'title' => aiCopilotCleanText((string) ($toolOutput['document_metadata']['title'] ?? '')),
            'mimeType' => aiCopilotCleanText((string) ($toolOutput['document_metadata']['mime_type'] ?? 'application/pdf')),
            'size' => $toolOutput['document_metadata']['size'] ?? null,
            'seededDemo' => !empty($toolOutput['document_metadata']['seeded_demo']),
            'patientKey' => aiCopilotCleanText((string) ($toolOutput['document_metadata']['patient_key'] ?? '')),
            'patientName' => aiCopilotCleanText((string) ($toolOutput['document_metadata']['patient_name'] ?? '')),
            'uploadedAt' => aiCopilotCleanText((string) ($toolOutput['document_metadata']['uploaded_at'] ?? '')),
        ],
        'sourceMetadata' => [
            'fileName' => aiCopilotCleanText((string) ($toolOutput['source_metadata']['file_name'] ?? '')),
            'uploadedAt' => aiCopilotCleanText((string) ($toolOutput['source_metadata']['uploaded_at'] ?? '')),
            'sourceType' => aiCopilotCleanText((string) ($toolOutput['source_metadata']['source_type'] ?? 'lab_pdf')),
            'sourceLabel' => aiCopilotCleanText((string) ($toolOutput['source_metadata']['source_label'] ?? 'Uploaded lab PDF')),
            'chunkCount' => $toolOutput['source_metadata']['chunk_count'] ?? 0,
            'requestId' => aiCopilotCleanText((string) ($toolOutput['source_metadata']['request_id'] ?? '')),
        ],
        'extractedFacts' => $extractedFacts,
        'abnormalFindings' => array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $toolOutput['abnormal_findings'] ?? []), static fn($item) => $item !== ''))),
        'missingData' => array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $toolOutput['missing_data'] ?? []), static fn($item) => $item !== ''))),
        'missingDataFlags' => array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $toolOutput['missing_data_flags'] ?? []), static fn($item) => $item !== ''))),
        'retrieval' => [
            'chunkIds' => array_values(array_filter(array_map('aiCopilotCleanText', $toolOutput['retrieval']['chunk_ids'] ?? []), static fn($item) => $item !== '')),
            'chunkCount' => $toolOutput['retrieval']['chunk_count'] ?? 0,
            'chunks' => array_map(static function ($chunk): array {
                return [
                    'id' => aiCopilotCleanText((string) ($chunk['id'] ?? '')),
                    'chunkText' => aiCopilotCleanText((string) ($chunk['chunk_text'] ?? '')),
                    'fileName' => aiCopilotCleanText((string) ($chunk['file_name'] ?? '')),
                    'sourcePage' => $chunk['source_page'] ?? null,
                    'score' => $chunk['score'] ?? null,
                    'uploadedAt' => aiCopilotCleanText((string) ($chunk['uploaded_at'] ?? '')),
                    'extractionMethod' => aiCopilotCleanText((string) ($chunk['extraction_method'] ?? '')),
                    'chunkIndex' => $chunk['chunk_index'] ?? null,
                    'embedding' => array_values(array_map(static fn($value) => (float) $value, is_array($chunk['embedding'] ?? null) ? $chunk['embedding'] : [])),
                ];
            }, array_values(array_filter($toolOutput['retrieval']['chunks'] ?? [], 'is_array'))),
        ],
        'vectorizedResult' => array_map(static function ($item): array {
            return [
                'id' => aiCopilotCleanText((string) ($item['id'] ?? '')),
                'fileName' => aiCopilotCleanText((string) ($item['file_name'] ?? '')),
                'chunkIndex' => $item['chunk_index'] ?? null,
                'sourcePage' => $item['source_page'] ?? null,
                'textPreview' => aiCopilotCleanText((string) ($item['text_preview'] ?? '')),
                'embedding' => array_values(array_map(static fn($value) => (float) $value, is_array($item['embedding'] ?? null) ? $item['embedding'] : [])),
                'score' => $item['score'] ?? null,
            ];
        }, array_values(array_filter($toolOutput['vectorized_result'] ?? [], 'is_array'))),
        'safety' => [
            'draftOnly' => !empty($toolOutput['safety']['draft_only']),
            'reviewRequired' => !empty($toolOutput['safety']['review_required']),
        ],
        'safetyMetadata' => [
            'promptInjectionDetected' => !empty($toolOutput['safety_metadata']['prompt_injection_detected']),
            'promptInjectionMatches' => array_values(array_filter(array_map('aiCopilotCleanText', $toolOutput['safety_metadata']['prompt_injection_matches'] ?? []), static fn($item) => $item !== '')),
            'untrustedDocumentText' => !empty($toolOutput['safety_metadata']['untrusted_document_text']),
            'noChartWrite' => !empty($toolOutput['safety_metadata']['no_chart_write']),
            'ocrRequired' => !empty($toolOutput['safety_metadata']['ocr_required']),
        ],
    ];
}

function aiCopilotAgentToolCatalog(): array
{
    return [
        'retrieve_chart_context' => [
            'name' => 'retrieve_chart_context',
            'description' => 'Retrieve role-appropriate OpenEMR chart context as structured facts with source labels.',
            'worker' => 'chart_retrieval_worker',
            'input_schema' => [
                'type' => 'object',
                'required' => ['role', 'mode', 'prompt'],
                'properties' => [
                    'patient_id' => ['type' => ['integer', 'null']],
                    'role' => ['type' => 'string'],
                    'mode' => ['type' => 'string'],
                    'prompt' => ['type' => 'string'],
                    'requested_domains' => ['type' => 'array'],
                    'include_latest_ambient' => ['type' => 'boolean'],
                    'minimum_necessary' => ['type' => 'boolean'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'facts' => ['type' => 'array'],
                    'sources' => ['type' => 'array'],
                    'missing_data' => ['type' => 'array'],
                    'grounded' => ['type' => 'boolean'],
                ],
            ],
        ],
        'attach_and_extract' => [
            'name' => 'attach_and_extract',
            'description' => 'Validate an attached demo lab PDF payload and return structured extraction facts for clinician review.',
            'worker' => 'chart_retrieval_worker',
            'input_schema' => [
                'type' => 'object',
                'required' => ['role'],
                'properties' => [
                    'role' => ['type' => 'string'],
                    'patient_key' => ['type' => 'string'],
                    'use_demo_seed' => ['type' => 'boolean'],
                    'tool_output' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'tool_output' => ['type' => 'object'],
                    'sources' => ['type' => 'array'],
                    'missing_data' => ['type' => 'array'],
                ],
            ],
        ],
        'retrieve_guideline_evidence' => [
            'name' => 'retrieve_guideline_evidence',
            'description' => 'Return internal demo workflow and role-policy evidence used to ground safe responses.',
            'worker' => 'chart_retrieval_worker',
            'input_schema' => [
                'type' => 'object',
                'required' => ['role', 'mode', 'prompt'],
                'properties' => [
                    'role' => ['type' => 'string'],
                    'mode' => ['type' => 'string'],
                    'prompt' => ['type' => 'string'],
                    'intent' => ['type' => 'string'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'evidence' => ['type' => 'array'],
                    'sources' => ['type' => 'array'],
                    'policy_flags' => ['type' => 'array'],
                ],
            ],
        ],
        'validate_citations' => [
            'name' => 'validate_citations',
            'description' => 'Validate role boundaries, source grounding, citations, missing data, and safe refusal conditions.',
            'worker' => 'evidence_safety_worker',
            'input_schema' => [
                'type' => 'object',
                'required' => ['role', 'mode', 'prompt', 'draft'],
                'properties' => [
                    'role' => ['type' => 'string'],
                    'mode' => ['type' => 'string'],
                    'prompt' => ['type' => 'string'],
                    'draft' => ['type' => 'object'],
                    'chart_context_result' => ['type' => 'object'],
                    'guideline_evidence_result' => ['type' => 'object'],
                    'attachment_result' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'allowed' => ['type' => 'boolean'],
                    'blocked_reason' => ['type' => 'string'],
                    'safe_refusal' => ['type' => 'string'],
                    'citation_gaps' => ['type' => 'array'],
                    'missing_data' => ['type' => 'array'],
                    'validated_sources' => ['type' => 'array'],
                    'draft_only_note' => ['type' => 'string'],
                ],
            ],
        ],
        'draft_grounded_answer' => [
            'name' => 'draft_grounded_answer',
            'description' => 'Draft a structured, source-grounded response with summary, key findings, uncertainty, and review language.',
            'worker' => 'clinical_workflow_supervisor',
            'input_schema' => [
                'type' => 'object',
                'required' => ['role', 'mode', 'prompt'],
                'properties' => [
                    'request_id' => ['type' => 'string'],
                    'role' => ['type' => 'string'],
                    'mode' => ['type' => 'string'],
                    'prompt' => ['type' => 'string'],
                    'chat_history' => ['type' => 'array'],
                    'chart_context_result' => ['type' => 'object'],
                    'guideline_evidence_result' => ['type' => 'object'],
                    'attachment_result' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'draft' => ['type' => 'object'],
                    'meta' => ['type' => 'object'],
                    'tool_output' => ['type' => 'object'],
                ],
            ],
        ],
    ];
}

function aiCopilotExecuteAgentTool(
    string $toolName,
    array $toolInput,
    string $requestId,
    string $role,
    string $mode,
    string $prompt,
    array $chatHistory,
    array $context,
    array $validModes,
    bool $openAiConfigured,
    float $requestStartedAt
): array {
    $catalog = aiCopilotAgentToolCatalog();
    if (!isset($catalog[$toolName])) {
        return [
            'ok' => false,
            'error' => 'Unsupported tool request.',
            'error_category' => 'unsupported_tool',
        ];
    }

    $result = match ($toolName) {
        'retrieve_chart_context' => aiCopilotAgentRetrieveChartContextTool($toolInput, $role, $mode, $prompt, $context),
        'attach_and_extract' => aiCopilotAgentAttachAndExtractTool($toolInput, $role, $mode, $prompt, $context),
        'retrieve_guideline_evidence' => aiCopilotAgentRetrieveGuidelineEvidenceTool($toolInput, $role, $mode, $prompt, $context),
        'validate_citations' => aiCopilotAgentValidateCitationsTool($toolInput, $role, $mode, $prompt, $context),
        'draft_grounded_answer' => aiCopilotAgentDraftGroundedAnswerTool($toolInput, $requestId, $role, $mode, $prompt, $chatHistory, $context, $validModes, $openAiConfigured, $requestStartedAt),
        default => [],
    };

    return [
        'ok' => true,
        'result' => $result,
        'meta' => [
            'request_id' => $requestId,
            'tool_name' => $toolName,
            'role' => $role,
            'mode' => $mode,
            'tool_schema' => $catalog[$toolName],
        ],
    ];
}

function aiCopilotAgentToolSource(string $id, string $title, string $category): array
{
    return [
        'id' => aiCopilotCleanText($id),
        'title' => aiCopilotCleanText($title),
        'label' => aiCopilotCleanText($title),
        'category' => aiCopilotCleanText($category),
    ];
}

function aiCopilotAgentToolFact(string $domain, string $label, string $value, string $sourceId, string $sourceLabel): array
{
    return [
        'domain' => aiCopilotCleanText($domain),
        'label' => aiCopilotCleanText($label),
        'value' => aiCopilotCleanText($value),
        'source_id' => aiCopilotCleanText($sourceId),
        'source_label' => aiCopilotCleanText($sourceLabel),
    ];
}

function aiCopilotAgentNormalizeSources(array $sources): array
{
    $normalized = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $title = aiCopilotCleanText((string) ($source['title'] ?? $source['label'] ?? ''));
        if ($title === '') {
            continue;
        }

        $category = aiCopilotCleanText((string) ($source['category'] ?? $source['id'] ?? 'source'));
        $id = aiCopilotCleanText((string) ($source['id'] ?? $category));
        $key = $category . '::' . $title;
        $normalized[$key] = [
            'id' => $id !== '' ? $id : $category,
            'title' => $title,
            'label' => $title,
            'category' => $category !== '' ? $category : 'source',
        ];
    }

    return array_values($normalized);
}

function aiCopilotAgentRequestedDomains(array $toolInput, string $role, string $mode, string $prompt): array
{
    $allowedDomains = [
        'medications',
        'allergies',
        'labs',
        'encounters',
        'visit_history',
        'documents',
        'insurance',
        'care_team',
        'immunizations',
        'problem_list',
        'appointments',
        'patient_contact',
    ];

    $requested = [];
    foreach (($toolInput['requested_domains'] ?? []) as $domain) {
        $domainText = aiCopilotCleanText((string) $domain);
        if ($domainText !== '' && in_array($domainText, $allowedDomains, true)) {
            $requested[] = $domainText;
        }
    }

    if ($requested !== []) {
        return array_values(array_unique($requested));
    }

    $normalizedPrompt = strtolower($prompt);
    if ($role === 'front_desk' || in_array($mode, ['appointment_info', 'patient_contact', 'send_reminder', 'front_desk_summary'], true)) {
        return ['appointments', 'patient_contact'];
    }

    if ($role === 'billing' || in_array($mode, ['billing', 'billing_review'], true)) {
        return ['insurance', 'visit_history', 'documents'];
    }

    $requested = ['encounters', 'visit_history', 'documents'];
    if (preg_match('/medication|dose|interaction|refill/', $normalizedPrompt) === 1 || $mode === 'medication_info') {
        $requested[] = 'medications';
    }
    if (preg_match('/allerg/', $normalizedPrompt) === 1) {
        $requested[] = 'allergies';
    }
    if (preg_match('/lab|troponin|a1c|glucose|ldl|creatinine|wbc|pdf/', $normalizedPrompt) === 1 || in_array($mode, ['treatment_plan', 'follow_up', 'rag_chart_context', 'clinical_notes', 'visit_summary', 'lab_pdf_ingestion'], true)) {
        $requested[] = 'labs';
    }
    if (preg_match('/insurance|billing|claim|payer|payment|policy/', $normalizedPrompt) === 1 || in_array($mode, ['billing', 'billing_review'], true)) {
        $requested[] = 'insurance';
    }
    if (preg_match('/care team|daughter|care support/', $normalizedPrompt) === 1 || in_array($mode, ['rag_chart_context', 'latest_ambient_summary'], true)) {
        $requested[] = 'care_team';
    }
    if (preg_match('/immunization|vaccine|flu/', $normalizedPrompt) === 1 || in_array($mode, ['rag_chart_context', 'visit_summary', 'patient_education'], true)) {
        $requested[] = 'immunizations';
    }
    if (preg_match('/problem|condition|diagnosis|summary/', $normalizedPrompt) === 1 || in_array($mode, ['clinical_notes', 'treatment_plan', 'differential_diagnosis'], true)) {
        $requested[] = 'problem_list';
    }

    return array_values(array_unique($requested));
}

function aiCopilotAgentRetrieveChartContextTool(array $toolInput, string $role, string $mode, string $prompt, array $context): array
{
    $requestedDomains = aiCopilotAgentRequestedDomains($toolInput, $role, $mode, $prompt);
    $sources = [];
    $factsOutput = [];
    $missingData = [];

    if (!aiCopilotContextHasPatient($context)) {
        return [
            'tool' => 'retrieve_chart_context',
            'worker' => 'chart_retrieval_worker',
            'patient' => [],
            'role_scope' => aiCopilotContextScope($role, $context),
            'domains' => $requestedDomains,
            'facts' => [],
            'sources' => [aiCopilotAgentToolSource('general_prompt_context', 'General Prompt Context', 'general_prompt_context')],
            'missing_data' => ['No demo patient is selected, so chart-specific retrieval is unavailable.'],
            'grounded' => false,
        ];
    }

    $facts = aiCopilotExtractClinicalFacts($context);
    $ambientVisit = aiCopilotLatestAmbientVisitFromContext($context);
    $medicationRows = aiCopilotBuildMedicationInformationRows($context);
    $allergyLine = aiCopilotFormatConditionList($context['allergies'] ?? []);
    $encounterLines = [];
    foreach (array_slice($context['encounters'] ?? [], 0, 2) as $encounter) {
        $line = aiCopilotSummarizeEncounterForLlm($encounter);
        if ($line !== '') {
            $encounterLines[] = $line;
        }
    }

    foreach ($requestedDomains as $domain) {
        switch ($domain) {
            case 'medications':
                if ($medicationRows !== []) {
                    $sources[] = aiCopilotAgentToolSource('medications', 'Medications', 'medications');
                    foreach (array_slice($medicationRows, 0, 6) as $row) {
                        $factsOutput[] = aiCopilotAgentToolFact('medications', 'Active medication', $row, 'medications', 'Medications');
                    }
                } else {
                    $missingData[] = 'No active medications were available in the role-appropriate retrieved context.';
                }
                break;
            case 'allergies':
                if ($allergyLine !== '') {
                    $sources[] = aiCopilotAgentToolSource('allergies', 'Allergies', 'allergies');
                    $factsOutput[] = aiCopilotAgentToolFact('allergies', 'Documented allergies', $allergyLine, 'allergies', 'Allergies');
                } else {
                    $missingData[] = 'No allergy list was available in the role-appropriate retrieved context.';
                }
                break;
            case 'labs':
                $labFacts = [];
                if (aiCopilotCleanText($facts['recent_labs'] ?? '') !== '') {
                    $labFacts[] = aiCopilotCleanText($facts['recent_labs']);
                }
                foreach (($context['attached_lab_pdf_tool_output']['extracted_facts'] ?? []) as $fact) {
                    if (!is_array($fact)) {
                        continue;
                    }

                    $line = aiCopilotJoinParts([
                        aiCopilotCleanText((string) ($fact['name'] ?? '')),
                        aiCopilotCleanText((string) ($fact['value'] ?? '')),
                        aiCopilotCleanText((string) ($fact['interpretation'] ?? '')),
                    ]);
                    if ($line !== '') {
                        $labFacts[] = $line;
                    }
                }

                if ($labFacts !== []) {
                    $sources[] = aiCopilotAgentToolSource('labs', 'Vitals / Labs', 'vitals_labs');
                    foreach (array_slice(array_values(array_unique($labFacts)), 0, 6) as $line) {
                        $factsOutput[] = aiCopilotAgentToolFact('labs', 'Lab context', $line, 'labs', 'Vitals / Labs');
                    }
                } else {
                    $missingData[] = 'No recent lab results were available in the retrieved context.';
                }
                break;
            case 'encounters':
                if ($encounterLines !== []) {
                    $sources[] = aiCopilotAgentToolSource('encounters', 'Encounter History', 'visit_history');
                    foreach ($encounterLines as $line) {
                        $factsOutput[] = aiCopilotAgentToolFact('encounters', 'Encounter summary', $line, 'encounters', 'Encounter History');
                    }
                } else {
                    $missingData[] = 'No recent encounter summaries were available in the retrieved context.';
                }
                break;
            case 'visit_history':
                if ($ambientVisit !== []) {
                    $sources[] = aiCopilotAgentToolSource('latest_approved_ambient_encounter', 'Latest Approved Ambient Encounter Capture', 'ambient_encounter_capture');
                    $factsOutput[] = aiCopilotAgentToolFact(
                        'visit_history',
                        'Latest approved ambient encounter',
                        aiCopilotCleanText((string) ($ambientVisit['summary'] ?? 'Clinician-reviewed ambient encounter available.')),
                        'latest_approved_ambient_encounter',
                        'Latest Approved Ambient Encounter Capture'
                    );
                } elseif (!empty($context['approved_ambient_visit'])) {
                    $missingData[] = 'Ambient encounter context was present but did not contain a retrievable approved summary.';
                } else {
                    $missingData[] = 'No approved ambient encounter summary was available for visit-history retrieval.';
                }

                if ($encounterLines !== []) {
                    $sources[] = aiCopilotAgentToolSource('visit_history', 'Visit History', 'visit_history');
                    foreach ($encounterLines as $line) {
                        $factsOutput[] = aiCopilotAgentToolFact('visit_history', 'Visit-history summary', $line, 'visit_history', 'Visit History');
                    }
                }
                break;
            case 'documents':
                $documentLines = [];
                foreach (array_slice($context['notes'] ?? [], 0, 4) as $note) {
                    $title = aiCopilotCleanText((string) ($note['title'] ?? ''));
                    if ($title !== '') {
                        $documentLines[] = $title;
                    }
                }
                $pdfTitle = aiCopilotCleanText((string) ($context['attached_lab_pdf_tool_output']['document_metadata']['title'] ?? ''));
                if ($pdfTitle !== '') {
                    $documentLines[] = $pdfTitle;
                }

                if ($documentLines !== []) {
                    $sources[] = aiCopilotAgentToolSource('documents', 'Documents / Notes', 'documents');
                    foreach (array_slice(array_values(array_unique($documentLines)), 0, 6) as $line) {
                        $factsOutput[] = aiCopilotAgentToolFact('documents', 'Document reference', $line, 'documents', 'Documents / Notes');
                    }
                } else {
                    $missingData[] = 'No recent chart documents or notes were available in the retrieved context.';
                }
                break;
            case 'insurance':
                $insuranceLines = [];
                if (!empty($context['primary_insurance'])) {
                    $insuranceLines[] = aiCopilotJoinParts([
                        aiCopilotCleanText((string) ($context['primary_insurance']['carrier'] ?? '')),
                        aiCopilotCleanText((string) ($context['primary_insurance']['plan_name'] ?? '')),
                        aiCopilotCleanText((string) ($context['primary_insurance']['policy_number'] ?? '')),
                    ]);
                }
                if (!empty($context['billing']['payment_summary'])) {
                    $paymentSummary = $context['billing']['payment_summary'];
                    $insuranceLines[] = aiCopilotJoinParts([
                        aiCopilotCleanText((string) ($paymentSummary['payer'] ?? '')),
                        aiCopilotCleanText((string) ($paymentSummary['plan_name'] ?? '')),
                        aiCopilotCleanText((string) ($paymentSummary['payment_note'] ?? '')),
                    ]);
                }

                $insuranceLines = array_values(array_filter(array_map('aiCopilotCleanText', $insuranceLines), static fn($item) => $item !== ''));
                if ($insuranceLines !== []) {
                    $sources[] = aiCopilotAgentToolSource('insurance', 'Insurance / Billing Context', 'insurance');
                    foreach (array_slice($insuranceLines, 0, 4) as $line) {
                        $factsOutput[] = aiCopilotAgentToolFact('insurance', 'Insurance context', $line, 'insurance', 'Insurance / Billing Context');
                    }
                } else {
                    $missingData[] = 'No insurance context was available in the retrieved role-appropriate data.';
                }
                break;
            case 'care_team':
                $careTeamItems = aiCopilotExtractCarePreferencesForLlm($context);
                if (aiCopilotContextContainsKeywords($context, ['daughter', 'care support contact'])) {
                    $careTeamItems[] = 'Requested daughter as a care support contact.';
                }
                $careTeamItems = array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $careTeamItems), static fn($item) => $item !== '')));
                if ($careTeamItems !== []) {
                    $sources[] = aiCopilotAgentToolSource('care_team', 'Care Team', 'care_team');
                    foreach (array_slice($careTeamItems, 0, 4) as $item) {
                        $factsOutput[] = aiCopilotAgentToolFact('care_team', 'Care-team context', $item, 'care_team', 'Care Team');
                    }
                } else {
                    $missingData[] = 'No care-team update was available in the retrieved context.';
                }
                break;
            case 'immunizations':
                $immunizationLine = aiCopilotBuildImmunizationReviewForLlm($context);
                if ($immunizationLine !== '') {
                    $sources[] = aiCopilotAgentToolSource('immunizations', 'Immunization Review', 'immunizations');
                    $factsOutput[] = aiCopilotAgentToolFact('immunizations', 'Immunization review', $immunizationLine, 'immunizations', 'Immunization Review');
                } else {
                    $missingData[] = 'No immunization review note was available in the retrieved context.';
                }
                break;
            case 'problem_list':
                $conditions = $facts['conditions'] ?? [];
                $conditions = array_values(array_filter(array_map('aiCopilotCleanText', $conditions), static fn($item) => $item !== ''));
                if ($conditions !== []) {
                    $sources[] = aiCopilotAgentToolSource('problem_list', 'Issues / Problem List', 'problem_list');
                    $factsOutput[] = aiCopilotAgentToolFact('problem_list', 'Problem-list context', aiCopilotJoinList(array_slice($conditions, 0, 6)), 'problem_list', 'Issues / Problem List');
                } else {
                    $missingData[] = 'No active problem list summary was available in the retrieved context.';
                }
                break;
            case 'appointments':
                $appointment = $context['next_appointment'] ?? [];
                $appointmentLine = aiCopilotJoinParts([
                    aiCopilotFormatAppointmentDateTime($appointment['date'] ?? '', $appointment['start_time'] ?? ''),
                    aiCopilotCleanText((string) ($appointment['appointment_type'] ?? '')),
                    aiCopilotCleanText((string) ($appointment['provider_name'] ?? '')),
                    aiCopilotCleanText((string) ($appointment['location'] ?? '')),
                ]);
                if ($appointmentLine !== '') {
                    $sources[] = aiCopilotAgentToolSource('appointments', 'Appointment Context', 'appointments');
                    $factsOutput[] = aiCopilotAgentToolFact('appointments', 'Upcoming appointment', $appointmentLine, 'appointments', 'Appointment Context');
                } else {
                    $missingData[] = 'No upcoming appointment details were available in the retrieved context.';
                }
                break;
            case 'patient_contact':
                $contactLine = aiCopilotJoinParts([
                    aiCopilotCleanText((string) ($context['patient']['email'] ?? '')),
                    aiCopilotCleanText((string) ($context['patient']['phone'] ?? $context['patient']['phone_cell'] ?? $context['patient']['phone_home'] ?? '')),
                ]);
                if ($contactLine !== '') {
                    $sources[] = aiCopilotAgentToolSource('patient_contact', 'Patient Contact', 'patient_contact');
                    $factsOutput[] = aiCopilotAgentToolFact('patient_contact', 'Contact details', $contactLine, 'patient_contact', 'Patient Contact');
                } else {
                    $missingData[] = 'No patient contact details were available in the retrieved context.';
                }
                break;
        }
    }

    return [
        'tool' => 'retrieve_chart_context',
        'worker' => 'chart_retrieval_worker',
        'patient' => [
            'pid' => $context['patient']['pid'] ?? null,
            'pubpid' => $context['patient']['pubpid'] ?? '',
            'name' => $context['patient']['name'] ?? '',
        ],
        'role_scope' => aiCopilotContextScope($role, $context),
        'domains' => $requestedDomains,
        'facts' => $factsOutput,
        'sources' => aiCopilotAgentNormalizeSources($sources),
        'missing_data' => array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $missingData), static fn($item) => $item !== ''))),
        'grounded' => $factsOutput !== [],
    ];
}

function aiCopilotAgentAttachAndExtractTool(array $toolInput, string $role, string $mode, string $prompt, array $context): array
{
    $toolOutput = aiCopilotNormalizeLabPdfContext($toolInput['tool_output'] ?? []);
    if ($toolOutput === []) {
        $toolOutput = is_array($context['attached_lab_pdf_tool_output'] ?? null) ? $context['attached_lab_pdf_tool_output'] : [];
    }

    $sources = [];
    if ($toolOutput !== []) {
        $sources[] = aiCopilotAgentToolSource('attached_lab_pdf', 'Attached Lab PDF', 'documents');
    }

    return [
        'tool' => 'attach_and_extract',
        'worker' => 'chart_retrieval_worker',
        'tool_output' => aiCopilotLabPdfToolOutputForClient($toolOutput),
        'sources' => aiCopilotAgentNormalizeSources($sources),
        'missing_data' => array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $toolOutput['missing_data'] ?? []), static fn($item) => $item !== ''))),
    ];
}

function aiCopilotAgentRetrieveGuidelineEvidenceTool(array $toolInput, string $role, string $mode, string $prompt, array $context): array
{
    $sources = [
        aiCopilotAgentToolSource('policy_draft_only', 'OpenEMR AI Copilot Draft-Only Policy', 'policy'),
        aiCopilotAgentToolSource('policy_role_scope', 'OpenEMR AI Copilot Role Policy', 'policy'),
    ];
    $evidence = [
        [
            'statement' => 'Responses remain draft-only and require human review before any clinical, billing, or administrative action.',
            'source_label' => 'OpenEMR AI Copilot Draft-Only Policy',
        ],
        [
            'statement' => 'No direct chart writes occur without clinician approval in this demo workflow.',
            'source_label' => 'OpenEMR AI Copilot Draft-Only Policy',
        ],
    ];
    $policyFlags = ['draft_only', 'no_direct_chart_write'];

    if ($role === 'front_desk') {
        $evidence[] = [
            'statement' => 'Front Desk responses must use minimum necessary PHI and avoid diagnoses, medications, labs, and treatment details.',
            'source_label' => 'OpenEMR AI Copilot Role Policy',
        ];
        $policyFlags[] = 'minimum_necessary_phi';
    } elseif ($role === 'billing') {
        $evidence[] = [
            'statement' => 'Billing role responses may cover insurance, payment, and claim-review workflow but not clinical treatment details.',
            'source_label' => 'OpenEMR AI Copilot Role Policy',
        ];
        $policyFlags[] = 'billing_scope_only';
    } elseif ($role === 'nurse') {
        $evidence[] = [
            'statement' => 'Nurse role responses may support education and follow-up, but medication changes and prescribing remain restricted.',
            'source_label' => 'OpenEMR AI Copilot Role Policy',
        ];
        $policyFlags[] = 'nurse_scope_only';
    } else {
        $evidence[] = [
            'statement' => 'Doctor role responses may summarize chart context and draft treatment considerations, but never finalize diagnosis or autonomous treatment decisions.',
            'source_label' => 'OpenEMR AI Copilot Role Policy',
        ];
        $policyFlags[] = 'doctor_draft_only';
    }

    if (in_array($mode, ['rag_chart_context', 'latest_ambient_summary'], true)) {
        $sources[] = aiCopilotAgentToolSource('workflow_retrieval_first', 'OpenEMR Retrieval-First Workflow', 'workflow');
        $evidence[] = [
            'statement' => 'Chart-context responses should retrieve role-appropriate sources before drafting and should not answer from uncited memory.',
            'source_label' => 'OpenEMR Retrieval-First Workflow',
        ];
        $policyFlags[] = 'retrieval_first';
    }

    if ($mode === 'lab_pdf_ingestion') {
        $sources[] = aiCopilotAgentToolSource('workflow_attachment_review', 'OpenEMR Attachment Review Workflow', 'workflow');
        $evidence[] = [
            'statement' => 'Attached lab PDF extraction remains draft-only and may still require OCR or manual verification against the source document.',
            'source_label' => 'OpenEMR Attachment Review Workflow',
        ];
        $policyFlags[] = 'attachment_review_required';
    }

    return [
        'tool' => 'retrieve_guideline_evidence',
        'worker' => 'chart_retrieval_worker',
        'evidence' => $evidence,
        'sources' => aiCopilotAgentNormalizeSources($sources),
        'policy_flags' => array_values(array_unique($policyFlags)),
    ];
}

function aiCopilotAgentDraftOnlyNote(string $role): string
{
    return match ($role) {
        'front_desk' => 'Administrative draft only. Human review required. Minimum necessary PHI only.',
        'billing' => 'Draft only. Human billing and compliance review required. No automatic claim actions occur.',
        'nurse' => 'Draft only. Human nursing and clinician review required. Medication changes and orders are restricted.',
        default => 'Draft only. Human clinician review required. No direct chart writes occur without clinician approval.',
    };
}

function aiCopilotAgentFlattenDraftItems(array $sections, array $ignoredTitles = []): array
{
    $ignored = array_map(static fn($value) => strtolower(aiCopilotCleanText((string) $value)), $ignoredTitles);
    $items = [];
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }

        $title = strtolower(aiCopilotCleanText((string) ($section['title'] ?? '')));
        if (in_array($title, $ignored, true)) {
            continue;
        }

        foreach (($section['items'] ?? []) as $item) {
            $itemText = aiCopilotCleanText((string) $item);
            if ($itemText !== '') {
                $items[] = $itemText;
            }
        }
    }

    return array_values(array_unique($items));
}

function aiCopilotAgentBuildStructuredDraft(
    array $draft,
    string $role,
    string $mode,
    string $prompt,
    array $context,
    array $chartContextResult,
    array $guidelineEvidenceResult,
    array $attachmentResult,
    array $meta
): array {
    $sources = aiCopilotAgentNormalizeSources(array_merge(
        is_array($draft['sources'] ?? null) ? $draft['sources'] : [],
        is_array($chartContextResult['sources'] ?? null) ? $chartContextResult['sources'] : [],
        is_array($guidelineEvidenceResult['sources'] ?? null) ? $guidelineEvidenceResult['sources'] : [],
        is_array($attachmentResult['sources'] ?? null) ? $attachmentResult['sources'] : []
    ));
    $missingData = array_values(array_unique(array_filter(array_map('aiCopilotCleanText', array_merge(
        is_array($chartContextResult['missing_data'] ?? null) ? $chartContextResult['missing_data'] : [],
        is_array($attachmentResult['missing_data'] ?? null) ? $attachmentResult['missing_data'] : [],
        is_array($draft['missing_data'] ?? null) ? $draft['missing_data'] : []
    )), static fn($item) => $item !== '')));

    $keyFindings = aiCopilotAgentFlattenDraftItems($draft['sections'] ?? [], [
        'summary',
        'missing data / uncertainty',
        'sources used',
        'draft-only clinician review',
    ]);
    if ($keyFindings === []) {
        $keyFindings[] = aiCopilotCleanText((string) ($draft['answer'] ?? 'Draft response prepared for review.'));
    }

    $summaryItems = [];
    $answer = aiCopilotCleanText((string) ($draft['answer'] ?? 'Draft response prepared for review.'));
    if ($answer !== '') {
        $summaryItems[] = $answer;
    }

    $sections = [
        aiCopilotBuildSection('Summary', $summaryItems !== [] ? $summaryItems : ['Draft response prepared for review.']),
        aiCopilotBuildSection('Key findings', array_slice($keyFindings, 0, 8)),
        aiCopilotBuildSection(
            'Missing data / uncertainty',
            $missingData !== [] ? $missingData : ['No major chart-grounding gaps were identified in the retrieved context used for this draft.'],
            $missingData !== [] ? 'yellow' : 'neutral'
        ),
        aiCopilotBuildSection(
            'Sources Used',
            $sources !== [] ? array_map(static fn($source) => aiCopilotCleanText((string) ($source['title'] ?? '')), $sources) : ['No source labels were returned.'],
            'neutral'
        ),
        aiCopilotBuildSection('Draft-only clinician review', [
            aiCopilotAgentDraftOnlyNote($role),
            'No direct chart writes occur without clinician approval.',
        ]),
    ];

    return [
        'answer' => $answer,
        'sections' => $sections,
        'tags' => aiCopilotFinalizeTags(array_merge($draft['tags'] ?? [], ['Chart context', 'Review needed'])),
        'sources' => $sources,
        'safety_note' => aiCopilotAgentDraftOnlyNote($role),
        'missing_data' => $missingData,
        'meta' => $meta,
        'tool_output' => $attachmentResult['tool_output'] ?? null,
    ];
}

function aiCopilotBuildLabPdfIngestionResponse(array $context): array
{
    $toolOutput = is_array($context['attached_lab_pdf_tool_output'] ?? null) ? $context['attached_lab_pdf_tool_output'] : [];
    $role = aiCopilotCleanText((string) ($context['role'] ?? 'doctor'));
    if ($toolOutput === []) {
        return aiCopilotBuildResponse(
            'Lab PDF Ingestion — Clinician Review Required',
            [
                aiCopilotBuildSection('Extracted Lab Facts', ['No lab PDF context was available for this request. Attach a PDF or use the seeded demo lab document before retrying the workflow.'], 'yellow'),
                aiCopilotBuildSection('Missing or Ambiguous Data', ['No attachment context was available, so no lab values were extracted or retrieved.'], 'yellow'),
                aiCopilotBuildSection('Safety Notice', [AI_COPILOT_LAB_PDF_REVIEW_NOTICE]),
            ],
            ['Draft note', 'Review needed']
        );
    }

    if (($toolOutput['status'] ?? '') === 'role_blocked') {
        return aiCopilotBuildResponse(
            'Lab PDF Ingestion — Clinician Review Required',
            [
                aiCopilotBuildSection('Extracted Lab Facts', [aiCopilotCleanText((string) ($toolOutput['safe_message'] ?? 'Lab PDF ingestion is restricted in this workflow.'))], 'yellow'),
                aiCopilotBuildSection('Missing or Ambiguous Data', ['The attachment workflow was blocked before any chart write, order, or diagnosis action could occur.'], 'yellow'),
                aiCopilotBuildSection('Safety Notice', [AI_COPILOT_LAB_PDF_REVIEW_NOTICE]),
            ],
            ['Draft note', 'Review needed']
        );
    }

    $documentTitle = aiCopilotCleanText((string) ($toolOutput['document_metadata']['title'] ?? 'Attached Lab PDF'));
    $findingItems = [];
    foreach (($toolOutput['extracted_facts'] ?? []) as $fact) {
        if (!is_array($fact)) {
            continue;
        }

        $findingItems[] = aiCopilotJoinParts([
            aiCopilotCleanText((string) ($fact['name'] ?? '')),
            aiCopilotCleanText((string) ($fact['value'] ?? '')),
            aiCopilotCleanText((string) ($fact['interpretation'] ?? '')),
        ]);
    }
    $findingItems = array_values(array_filter(array_map('aiCopilotCleanText', $findingItems), static fn($item) => $item !== ''));

    $abnormalItems = array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $toolOutput['abnormal_findings'] ?? []), static fn($item) => $item !== '')));
    $missingItems = array_values(array_unique(array_filter(array_map('aiCopilotCleanText', $toolOutput['missing_data'] ?? []), static fn($item) => $item !== '')));
    $retrievalChunks = array_values(array_filter($toolOutput['retrieval']['chunks'] ?? [], 'is_array'));
    $retrievalChunkIds = array_values(array_filter(array_map('aiCopilotCleanText', $toolOutput['retrieval']['chunk_ids'] ?? []), static fn($item) => $item !== ''));
    $uploadedAt = aiCopilotCleanText((string) ($toolOutput['document_metadata']['uploaded_at'] ?? $toolOutput['source_metadata']['uploaded_at'] ?? ''));
    $extractionMethod = aiCopilotCleanText((string) ($toolOutput['extraction_method'] ?? ''));
    $status = aiCopilotCleanText((string) ($toolOutput['status'] ?? ''));
    $promptInjectionDetected = !empty($toolOutput['safety_metadata']['prompt_injection_detected']);

    if ($promptInjectionDetected) {
        $missingItems[] = 'Instruction-like text inside the PDF was ignored as untrusted source content and was not treated as system or workflow instructions.';
    }

    $sourceItems = [];
    $sourceItems[] = 'File: ' . aiCopilotFallbackValue($documentTitle, 'Uploaded lab PDF');
    if ($retrievalChunkIds !== []) {
        $sourceItems[] = 'Chunk ids: ' . aiCopilotJoinList($retrievalChunkIds);
    } else {
        $sourceItems[] = 'Chunk ids: No retrieved chunk ids were available.';
    }

    $pageReferences = [];
    foreach ($retrievalChunks as $chunk) {
        if (isset($chunk['source_page']) && is_numeric($chunk['source_page'])) {
            $pageReferences[] = 'Page ' . (int) $chunk['source_page'];
        }
    }
    $pageReferences = array_values(array_unique($pageReferences));
    if ($pageReferences !== []) {
        $sourceItems[] = 'Page references: ' . aiCopilotJoinList($pageReferences);
    }

    $sourceItems[] = 'Extraction method: ' . aiCopilotFallbackValue($extractionMethod, 'not reported');
    $sourceItems[] = 'Uploaded timestamp: ' . aiCopilotFallbackValue($uploadedAt, 'not reported');
    if ($status !== '') {
        $sourceItems[] = 'Ingestion status: ' . $status;
    }

    $draftSummaryItems = [];
    if ($role === 'nurse') {
        $draftSummaryItems[] = 'Limited nursing review summary only. Escalate abnormal or unclear values to the supervising clinician for diagnosis, treatment decisions, medication changes, or orders.';
        $draftSummaryItems[] = 'Use the extracted lab facts to support follow-up preparation and reinforce that the original PDF still requires clinician verification.';
    } else {
        $draftSummaryItems[] = 'Draft clinical summary for clinician review only. Verify the extracted values, abnormal flags, and missing metadata directly against the original lab PDF before acting.';
        if ($abnormalItems !== []) {
            $draftSummaryItems[] = 'Priority review items include: ' . aiCopilotJoinList(array_slice($abnormalItems, 0, 4));
        }
        if ($findingItems !== []) {
            $draftSummaryItems[] = 'Extracted lab facts were grounded to the uploaded PDF chunks listed in Sources Used.';
        }
    }

    if ($status === 'ocr_required') {
        $draftSummaryItems = [
            'The attached PDF appears scanned or text-light. OCR or manual clinician verification of the original document is required before relying on extracted facts.',
        ];
    } elseif ($status === 'invalid_file_type') {
        $draftSummaryItems = [
            aiCopilotCleanText((string) ($toolOutput['safe_message'] ?? 'Please attach a PDF file for this workflow.')),
        ];
    }

    if ($findingItems === []) {
        $findingItems[] = $status === 'ocr_required'
            ? 'No structured lab facts were extracted because OCR or manual review is still required.'
            : 'No structured lab facts were extracted or retrieved from the uploaded document.';
    }

    if ($abnormalItems === []) {
        $abnormalItems[] = 'No explicit abnormal lab values were extracted or retrieved from the document context.';
    }

    if ($missingItems === []) {
        $missingItems[] = 'No additional missing lab metadata was detected in the retrieved document context.';
    }

    $status = $toolOutput['status'] ?? '';
    return aiCopilotBuildResponse(
        'Lab PDF Ingestion — Clinician Review Required',
        [
            aiCopilotBuildSection('Extracted Lab Facts', array_slice($findingItems, 0, 10), $status === 'ocr_required' ? 'yellow' : 'neutral'),
            aiCopilotBuildSection('Abnormal / Attention Needed', array_slice($abnormalItems, 0, 8), $abnormalItems !== [] ? 'yellow' : 'neutral'),
            aiCopilotBuildSection('Missing or Ambiguous Data', array_slice($missingItems, 0, 8), 'yellow'),
            aiCopilotBuildSection('Draft Clinical Summary', $draftSummaryItems),
            aiCopilotBuildSection('Sources Used', $sourceItems),
            aiCopilotBuildSection('Safety Notice', [AI_COPILOT_LAB_PDF_REVIEW_NOTICE]),
        ],
        ['Draft note', 'Chart context', 'Review needed']
    );
}

function aiCopilotAgentDraftGroundedAnswerTool(
    array $toolInput,
    string $requestId,
    string $role,
    string $mode,
    string $prompt,
    array $chatHistory,
    array $context,
    array $validModes,
    bool $openAiConfigured,
    float $requestStartedAt
): array {
    $chartContextResult = is_array($toolInput['chart_context_result'] ?? null) ? $toolInput['chart_context_result'] : [];
    $guidelineEvidenceResult = is_array($toolInput['guideline_evidence_result'] ?? null) ? $toolInput['guideline_evidence_result'] : [];
    $attachmentResult = is_array($toolInput['attachment_result'] ?? null) ? $toolInput['attachment_result'] : [];

    $draft = $mode === 'lab_pdf_ingestion'
        ? aiCopilotBuildLabPdfIngestionResponse($context)
        : aiCopilotGenerateDraft($requestId, $role, $mode, $prompt, $chatHistory, $context, $validModes[$mode]);

    $meta = aiCopilotBuildResponseMeta(
        $requestId,
        $role,
        $mode,
        $context,
        $draft,
        [
            'restricted_by_role' => false,
            'fallback_used' => ($draft['engine'] ?? 'fallback') === 'fallback',
            'fallback_reason' => $draft['fallback_reason'] ?? ($mode === 'lab_pdf_ingestion' ? 'attachment_review_workflow' : null),
            'engine' => $draft['engine'] ?? 'fallback',
            'provider' => $draft['provider'] ?? (($draft['engine'] ?? '') === 'openai' ? 'openai' : 'local_fallback'),
            'model' => $draft['model'] ?? null,
            'openai_configured' => (bool) ($draft['openai_configured'] ?? $openAiConfigured),
            'error_category' => $draft['error_category'] ?? null,
            'openai_error_category' => $draft['openai_error_category'] ?? null,
            'openai_http_status' => $draft['openai_http_status'] ?? null,
            'openai_error_message_safe' => $draft['openai_error_message_safe'] ?? null,
            'rag_grounded' => aiCopilotShouldMarkRagGroundedForRequest($mode, $prompt, $context) || !empty($attachmentResult['tool_output']),
        ],
        $requestStartedAt
    );

    $structuredDraft = aiCopilotAgentBuildStructuredDraft(
        $draft,
        $role,
        $mode,
        $prompt,
        $context,
        $chartContextResult,
        $guidelineEvidenceResult,
        $attachmentResult,
        $meta
    );

    return [
        'tool' => 'draft_grounded_answer',
        'worker' => 'clinical_workflow_supervisor',
        'draft' => [
            'answer' => $structuredDraft['answer'],
            'sections' => $structuredDraft['sections'],
            'tags' => $structuredDraft['tags'],
            'sources' => $structuredDraft['sources'],
            'safety_note' => $structuredDraft['safety_note'],
            'missing_data' => $structuredDraft['missing_data'],
        ],
        'meta' => $structuredDraft['meta'],
        'tool_output' => $structuredDraft['tool_output'],
    ];
}

function aiCopilotAgentPromptInjectionReason(string $prompt): string
{
    $patterns = [
        '/\bignore (all|any|previous|prior) instructions\b/i',
        '/\bignore (your|the) rules\b/i',
        '/\bbypass (role|guardrail|restriction|policy|safety)\b/i',
        '/\bshow (me )?(the )?full chart\b/i',
        '/\breveal (the )?(hidden|restricted|internal) (context|notes|data|prompt)\b/i',
        '/\bact as (an )?admin\b/i',
        '/\boverride (hipaa|role restrictions|privacy rules|safety rules)\b/i',
        '/\bsystem prompt\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $prompt) === 1) {
            return 'prompt_injection_block';
        }
    }

    return '';
}

function aiCopilotAgentValidateCitationsTool(array $toolInput, string $role, string $mode, string $prompt, array $context): array
{
    $draft = is_array($toolInput['draft'] ?? null) ? $toolInput['draft'] : [];
    $chartContextResult = is_array($toolInput['chart_context_result'] ?? null) ? $toolInput['chart_context_result'] : [];
    $guidelineEvidenceResult = is_array($toolInput['guideline_evidence_result'] ?? null) ? $toolInput['guideline_evidence_result'] : [];
    $attachmentResult = is_array($toolInput['attachment_result'] ?? null) ? $toolInput['attachment_result'] : [];
    $sources = aiCopilotAgentNormalizeSources(array_merge(
        is_array($draft['sources'] ?? null) ? $draft['sources'] : [],
        is_array($chartContextResult['sources'] ?? null) ? $chartContextResult['sources'] : [],
        is_array($guidelineEvidenceResult['sources'] ?? null) ? $guidelineEvidenceResult['sources'] : [],
        is_array($attachmentResult['sources'] ?? null) ? $attachmentResult['sources'] : []
    ));
    $missingData = array_values(array_unique(array_filter(array_map('aiCopilotCleanText', array_merge(
        is_array($draft['missing_data'] ?? null) ? $draft['missing_data'] : [],
        is_array($chartContextResult['missing_data'] ?? null) ? $chartContextResult['missing_data'] : [],
        is_array($attachmentResult['missing_data'] ?? null) ? $attachmentResult['missing_data'] : []
    )), static fn($item) => $item !== '')));

    $blockedReason = aiCopilotAgentPromptInjectionReason($prompt);
    $safeRefusal = $blockedReason !== ''
        ? 'I can\'t bypass role restrictions or reveal hidden chart context. Please use a prompt that matches the selected role and approved workflow.'
        : '';
    $permissionResponse = $blockedReason === ''
        ? aiCopilotMaybeBuildRolePermissionResponse($role, $mode, $prompt, $context)
        : [];
    if ($permissionResponse !== []) {
        $blockedReason = aiCopilotCleanText((string) ($permissionResponse['restriction_type'] ?? 'role_guardrail'));
        $safeRefusal = aiCopilotCleanText((string) ($permissionResponse['answer'] ?? 'This request is outside the allowed workflow.'));
    }

    $citationGaps = [];
    if (aiCopilotShouldMarkRagGroundedForRequest($mode, $prompt, $context) && $sources === []) {
        $citationGaps[] = 'Chart grounding was required for this request, but no source labels were returned.';
        if ($blockedReason === '') {
            $blockedReason = 'missing_citations';
            $safeRefusal = 'I need retrieved chart sources before I can provide a grounded draft for this request.';
        }
    }

    if ($mode === 'lab_pdf_ingestion' && empty($attachmentResult['tool_output'])) {
        $missingData[] = 'No lab PDF extraction payload was available for evidence review.';
    }

    return [
        'tool' => 'validate_citations',
        'worker' => 'evidence_safety_worker',
        'allowed' => $blockedReason === '',
        'blocked_reason' => $blockedReason,
        'safe_refusal' => $safeRefusal,
        'citation_gaps' => $citationGaps,
        'missing_data' => $missingData,
        'unsupported_claims' => [],
        'validated_sources' => $sources,
        'draft_only_note' => aiCopilotAgentDraftOnlyNote($role),
        'policy_flags' => array_values(array_unique(array_filter(array_merge(
            is_array($guidelineEvidenceResult['policy_flags'] ?? null) ? $guidelineEvidenceResult['policy_flags'] : [],
            [$blockedReason !== '' ? 'blocked' : 'validated']
        )))),
    ];
}

function aiCopilotBuildSources(array $context, string $mode = 'general_assistant'): array
{
    if (!aiCopilotContextHasPatient($context)) {
        return ['General Prompt Context'];
    }

    if ($mode === 'lab_pdf_ingestion') {
        $sources = ['Patient Chart Context'];
        if (!empty($context['attached_lab_pdf_tool_output'])) {
            $sources[] = 'Attached Lab PDF';
            $sources[] = 'Uploaded Lab PDF';
        }
        if (!empty($context['medications'])) {
            $sources[] = 'Medications';
        }
        if (!empty($context['notes'])) {
            $sources[] = 'Documents / Notes';
        }

        return array_values(array_unique($sources));
    }

    if ($mode === 'latest_ambient_summary') {
        $role = aiCopilotCleanText((string) ($context['role'] ?? 'doctor'));
        $sources = ['Patient Chart Context'];

        if (!empty($context['approved_ambient_visit']) || !empty($context['latest_approved_ambient_encounter'])) {
            $sources[] = 'Latest Approved Ambient Encounter Capture';
            $sources[] = 'AI-Assisted Visit Review';
            $sources[] = 'Ambient Encounter Capture';
        }

        if ($role === 'billing' && (!empty($context['billing']['payment_summary']) || !empty($context['primary_insurance']))) {
            $sources[] = 'Insurance / Billing Context';
        }

        return array_values(array_unique($sources));
    }

    if (in_array($mode, ['billing', 'billing_review'], true)) {
        $sources = ['Patient Chart Context'];

        if (!empty($context['primary_insurance']) || !empty($context['billing']['payment_summary'])) {
            $sources[] = 'Insurance / Billing Context';
        }

        if (!empty($context['billing']['payment_summary'])) {
            $sources[] = 'Payment Due Source';
        }

        if (!empty($context['billing']['payment_summary']['insurance_note']) || !empty($context['primary_insurance'])) {
            $sources[] = 'Insurance Note';
        }

        if (!empty($context['billing']['rows']) || !empty($context['billing']['claim']) || !empty($context['billing']['payment_summary'])) {
            $sources[] = 'Billing / Claim Context';
        }

        return array_values(array_unique($sources));
    }

    $sources = ['Patient Chart Context'];

    if (!empty($context['attached_lab_pdf_tool_output']) || !empty($context['retrieved_lab_pdf_context'])) {
        $sources[] = 'Uploaded Lab PDF';
    }

    if (!empty($context['medications'])) {
        $sources[] = 'Medications';
    }

    if (!empty($context['appointments']) || !empty($context['next_appointment']) || !empty($context['encounters'])) {
        $sources[] = 'Visit History';
    }

    if (!empty($context['notes'])) {
        $sources[] = 'Patient Chart Context';
    }

    if (!empty($context['approved_ambient_visit'])) {
        $sources[] = 'Latest Approved Ambient Encounter Capture';
    }

    if (!empty($context['problems']) || !empty($context['allergies'])) {
        $sources[] = 'Issues / Problem List';
    }

    if (!empty($context['latest_vitals']) || aiCopilotContextHasRecentLabs($context)) {
        $sources[] = 'Vitals / Labs';
    }

    if (!empty($context['primary_insurance']) || aiCopilotContextContainsKeywords($context, ['insurance', 'coverage', 'payer', 'plan'])) {
        $sources[] = 'Insurance Note';
    }

    if (in_array($mode, ['medication_info', 'treatment_plan', 'clinical_notes', 'follow_up', 'visit_summary', 'patient_education', 'rag_chart_context'], true) && aiCopilotContextContainsKeywords($context, ['vaccine', 'immunization', 'flu', 'seasonal vaccine'])) {
        $sources[] = 'Immunization Review';
    }

    if (in_array($mode, ['medication_info', 'treatment_plan', 'clinical_notes', 'follow_up', 'visit_summary', 'patient_education', 'rag_chart_context'], true) && aiCopilotContextContainsKeywords($context, ['prefers afternoon phone reminders', 'written medication instructions', 'care support contact', 'daughter'])) {
        $sources[] = 'Care Preferences';
    }

    if (in_array($mode, ['medication_info', 'treatment_plan', 'clinical_notes', 'follow_up', 'visit_summary', 'patient_education', 'rag_chart_context'], true) && aiCopilotContextContainsKeywords($context, ['care support contact', 'daughter'])) {
        $sources[] = 'Care Team';
    }

    if (!empty($context['primary_insurance']) || !empty($context['billing']['payment_summary'])) {
        $sources[] = 'Insurance / Billing Context';
    }

    if (!empty($context['billing']['rows']) || !empty($context['billing']['claim']) || !empty($context['billing']['payment_summary'])) {
        $sources[] = 'Billing / Claim Context';
    }

    return array_values(array_unique($sources));
}

function aiCopilotContextHasRecentLabs(array $context): bool
{
    if (!aiCopilotContextHasPatient($context)) {
        return false;
    }

    $facts = aiCopilotExtractClinicalFacts($context);
    return aiCopilotCleanText($facts['recent_labs'] ?? '') !== '';
}

function aiCopilotContextContainsKeywords(array $context, array $keywords): bool
{
    $haystacks = [];

    foreach (($context['notes'] ?? []) as $note) {
        $haystacks[] = $note['title'] ?? '';
        $haystacks[] = $note['body'] ?? '';
    }

    foreach (($context['encounters'] ?? []) as $encounter) {
        $haystacks[] = $encounter['reason'] ?? '';
        $haystacks[] = $encounter['billing_note'] ?? '';
    }

    $haystacks[] = $context['next_appointment']['summary'] ?? '';
    $haystacks[] = $context['patient']['scenario'] ?? '';
    $combined = strtolower(aiCopilotJoinParts($haystacks));

    foreach ($keywords as $keyword) {
        if ($keyword !== '' && str_contains($combined, strtolower($keyword))) {
            return true;
        }
    }

    return false;
}

function aiCopilotContextHasPatient(array $context): bool
{
    return !empty($context['patient_selected']) && !empty($context['patient']['name']);
}

function aiCopilotFetchAll(string $sql, array $binds = []): array
{
    $result = sqlStatement($sql, $binds);
    $rows = [];
    while ($row = sqlFetchArray($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function aiCopilotNormalizeAppointment(array $appointment): array
{
    if (empty($appointment)) {
        return [];
    }

    $summary = aiCopilotCleanMultilineText($appointment['pc_hometext'] ?? '');
    $summaryMap = aiCopilotParseLabeledNote($summary);

    return [
        'title' => aiCopilotCleanText($appointment['pc_title'] ?? ''),
        'date' => $appointment['pc_eventDate'] ?? '',
        'start_time' => $appointment['pc_startTime'] ?? '',
        'end_time' => $appointment['pc_endTime'] ?? '',
        'status' => $appointment['pc_apptstatus'] ?? '',
        'room' => aiCopilotCleanText($appointment['pc_room'] ?? ''),
        'location' => aiCopilotCleanText($appointment['pc_location'] ?? ''),
        'summary' => $summary,
        'appointment_type' => aiCopilotFirstNonEmpty([
            rtrim(aiCopilotMapValue($summaryMap, 'appointment type'), '.'),
            aiCopilotCleanText($appointment['pc_title'] ?? ''),
        ]),
        'provider_name' => rtrim(aiCopilotMapValue($summaryMap, 'provider'), '.'),
        'check_in_instructions' => aiCopilotMapValue($summaryMap, 'check-in instructions'),
        'check_in_status' => rtrim(aiCopilotMapValue($summaryMap, 'check-in status'), '.'),
    ];
}

function aiCopilotNormalizeEncounter(array $encounter): array
{
    if (empty($encounter)) {
        return [];
    }

    return [
        'date' => $encounter['date'] ?? '',
        'reason' => aiCopilotCleanText($encounter['reason'] ?? ''),
        'encounter' => (string) ($encounter['encounter'] ?? ''),
        'billing_note' => aiCopilotCleanText($encounter['billing_note'] ?? ''),
    ];
}

function aiCopilotNormalizeNote(array $note): array
{
    return [
        'date' => $note['date'] ?? '',
        'title' => aiCopilotCleanText($note['title'] ?? ''),
        'body' => aiCopilotCleanMultilineText($note['body'] ?? ''),
    ];
}

function aiCopilotNormalizeProblem(array $problem): array
{
    return [
        'title' => aiCopilotCleanText($problem['title'] ?? ''),
        'diagnosis' => aiCopilotCleanText($problem['diagnosis'] ?? ''),
        'comments' => aiCopilotCleanText($problem['comments'] ?? ''),
        'begdate' => $problem['begdate'] ?? '',
    ];
}

function aiCopilotNormalizeMedication(array $medication): array
{
    return [
        'drug' => aiCopilotCleanText($medication['drug'] ?? ''),
        'dosage' => aiCopilotCleanText($medication['dosage'] ?? ''),
        'quantity' => aiCopilotCleanText($medication['quantity'] ?? ''),
        'note' => aiCopilotCleanText($medication['note'] ?? ''),
        'start_date' => $medication['start_date'] ?? '',
    ];
}

function aiCopilotNormalizeVitals(array $vitals): array
{
    if (empty($vitals)) {
        return [];
    }

    return [
        'date' => $vitals['date'] ?? '',
        'bps' => aiCopilotCleanText((string) ($vitals['bps'] ?? '')),
        'bpd' => aiCopilotCleanText((string) ($vitals['bpd'] ?? '')),
        'weight' => aiCopilotCleanText((string) ($vitals['weight'] ?? '')),
        'height' => aiCopilotCleanText((string) ($vitals['height'] ?? '')),
        'temperature' => aiCopilotCleanText((string) ($vitals['temperature'] ?? '')),
        'pulse' => aiCopilotCleanText((string) ($vitals['pulse'] ?? '')),
        'respiration' => aiCopilotCleanText((string) ($vitals['respiration'] ?? '')),
        'note' => aiCopilotCleanText($vitals['note'] ?? ''),
        'bmi' => aiCopilotCleanText((string) ($vitals['BMI'] ?? '')),
        'oxygen_saturation' => aiCopilotCleanText((string) ($vitals['oxygen_saturation'] ?? '')),
    ];
}

function aiCopilotNormalizeInsurance(array $insurance): array
{
    if (empty($insurance)) {
        return [];
    }

    $labelParts = array_filter([
        aiCopilotCleanText($insurance['carrier'] ?? ''),
        aiCopilotCleanText($insurance['plan_name'] ?? ''),
    ]);

    return [
        'type' => $insurance['type'] ?? '',
        'carrier' => aiCopilotCleanText($insurance['carrier'] ?? ''),
        'plan_name' => aiCopilotCleanText($insurance['plan_name'] ?? ''),
        'policy_number' => aiCopilotCleanText($insurance['policy_number'] ?? ''),
        'copay' => aiCopilotCleanText($insurance['copay'] ?? ''),
        'label' => implode(' / ', $labelParts),
    ];
}

function aiCopilotNormalizeBillingRow(array $row): array
{
    return [
        'code_type' => aiCopilotCleanText($row['code_type'] ?? ''),
        'code' => aiCopilotCleanText($row['code'] ?? ''),
        'code_text' => aiCopilotCleanText($row['code_text'] ?? ''),
        'fee' => (string) ($row['fee'] ?? ''),
        'justify' => aiCopilotCleanText($row['justify'] ?? ''),
        'billed' => (string) ($row['billed'] ?? ''),
        'activity' => (string) ($row['activity'] ?? ''),
    ];
}

function aiCopilotNormalizeClaim(array $claim): array
{
    if (empty($claim)) {
        return [];
    }

    return [
        'version' => (string) ($claim['version'] ?? ''),
        'payer_id' => (string) ($claim['payer_id'] ?? ''),
        'status' => isset($claim['status']) ? (int) $claim['status'] : null,
        'bill_time' => $claim['bill_time'] ?? '',
        'process_time' => $claim['process_time'] ?? '',
        'process_file' => aiCopilotCleanText($claim['process_file'] ?? ''),
        'submitted_claim' => aiCopilotCleanText($claim['submitted_claim'] ?? ''),
    ];
}

function aiCopilotReadEnv(string $key): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) ? trim($value) : '';
}

function aiCopilotCleanText(string $text): string
{
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function aiCopilotCleanMultilineText(string $text): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($normalized === '') {
        return '';
    }

    $lines = preg_split('/\n+/', $normalized) ?: [];
    $cleanLines = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? '');
        if ($line !== '') {
            $cleanLines[] = $line;
        }
    }

    return implode("\n", $cleanLines);
}

function aiCopilotFindNoteBody(array $notes, string $title): string
{
    foreach ($notes as $note) {
        if (($note['title'] ?? '') === $title) {
            return $note['body'] ?? '';
        }
    }
    return '';
}

function aiCopilotFormatMedicationList(array $medications): string
{
    $parts = [];
    foreach ($medications as $medication) {
        $drug = $medication['drug'] ?? '';
        if ($drug === '') {
            continue;
        }
        $parts[] = trim($drug . (!empty($medication['dosage']) ? ' ' . $medication['dosage'] : ''));
    }

    return implode('; ', $parts);
}

function aiCopilotFormatConditionList(array $conditions): string
{
    $parts = [];
    foreach ($conditions as $condition) {
        $title = $condition['title'] ?? '';
        if ($title !== '') {
            $parts[] = $title;
        }
    }

    return implode('; ', $parts);
}

function aiCopilotFormatVitalsSummary(array $vitals): string
{
    if ($vitals === []) {
        return '';
    }

    $parts = [];
    if (($vitals['bps'] ?? '') !== '' && ($vitals['bpd'] ?? '') !== '') {
        $parts[] = 'BP ' . $vitals['bps'] . '/' . $vitals['bpd'];
    }
    if (($vitals['pulse'] ?? '') !== '') {
        $parts[] = 'pulse ' . $vitals['pulse'];
    }
    if (($vitals['respiration'] ?? '') !== '') {
        $parts[] = 'respirations ' . $vitals['respiration'];
    }
    if (($vitals['temperature'] ?? '') !== '') {
        $parts[] = 'temp ' . $vitals['temperature'] . ' F';
    }
    if (($vitals['oxygen_saturation'] ?? '') !== '') {
        $parts[] = 'SpO2 ' . $vitals['oxygen_saturation'] . '%';
    }

    return implode(', ', $parts);
}

function aiCopilotSplitChecklist(string $rawValue): array
{
    if ($rawValue === '') {
        return [];
    }

    $normalized = str_replace(';', ',', $rawValue);
    $parts = array_map('trim', explode(',', $normalized));
    return array_values(array_filter(array_map('aiCopilotUcfirst', $parts), static fn($item) => $item !== ''));
}

function aiCopilotFallbackValue(string $value, string $fallback): string
{
    return $value !== '' ? $value : $fallback;
}

function aiCopilotUcfirst(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return strtoupper(substr($value, 0, 1)) . substr($value, 1);
}

function aiCopilotJoinList(array $values): string
{
    return implode(', ', array_values(array_filter(array_map('aiCopilotCleanText', $values), static fn($value) => $value !== '')));
}

function aiCopilotJoinParts(array $values): string
{
    return implode('; ', array_values(array_filter(array_map(static fn($value) => aiCopilotCleanText((string) $value), $values), static fn($value) => $value !== '')));
}

function aiCopilotFindBillingRowMissingDiagnosisLink(array $rows): array
{
    foreach ($rows as $row) {
        $codeType = strtoupper((string) ($row['code_type'] ?? ''));
        if (!in_array($codeType, ['CPT4', 'HCPCS', 'CPT'], true)) {
            continue;
        }
        if (trim((string) ($row['justify'] ?? '')) === '') {
            return $row;
        }
    }
    return [];
}

function aiCopilotFirstNonEmpty(array $values): string
{
    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

function aiCopilotTrimSentenceValue(string $value): string
{
    return rtrim(aiCopilotCleanText($value), '. ');
}
