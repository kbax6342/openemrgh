(function () {
    'use strict';

    function createId(prefix) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return prefix + '_' + window.crypto.randomUUID();
        }

        return prefix + '_' + Date.now() + '_' + Math.random().toString(16).slice(2);
    }

    function hashIdentifier(value) {
        const input = String(value || '').trim();
        if (!input) {
            return null;
        }

        let hash = 2166136261;
        for (let index = 0; index < input.length; index += 1) {
            hash ^= input.charCodeAt(index);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }

        return 'h_' + (hash >>> 0).toString(16).padStart(8, '0');
    }

    function ensureTelemetryHost(targetWindow) {
        if (!targetWindow) {
            return null;
        }

        targetWindow.OpenEMRCopilotState = targetWindow.OpenEMRCopilotState || {
            role: 'doctor',
            mode: 'general_assistant',
            selectedPatientKey: null
        };

        if (targetWindow.CopilotTelemetry && targetWindow.CopilotMetrics && typeof targetWindow.printCopilotMetrics === 'function') {
            return targetWindow.CopilotTelemetry;
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
            'costNote',
            'claimCount',
            'citedClaimCount',
            'uncitedClaimCount',
            'invalidCitationCount',
            'blockedClaimCount',
            'citationContractStatus',
            'ragGrounded',
            'sourceCount',
            'sourceTitles',
            'sourceCategories',
            'decisionType',
            'nextWorker',
            'decisionCount',
            'handoffCount',
            'fromWorker',
            'toWorker',
            'docType',
            'hasAttachedFile',
            'needsExtraction',
            'needsEvidenceRetrieval',
            'patientIdPresent',
            'safeLog',
            'worker',
            'status',
            'latestAmbientVisitFound',
            'encounterId',
            'sessionIdHash',
            'stepName',
            'topK',
            'retrievalMode',
            'rerankProvider',
            'retrievalHitCount',
            'sparseHitCount',
            'denseHitCount',
            'hybridCandidateCount',
            'rerankedHitCount',
            'finalEvidenceCount',
            'topSourceTypes',
            'citationCount',
            'extractionStatus',
            'confidence',
            'schemaValid',
            'citationContractValid',
            'extractedFactCount',
            'missingDataCount',
            'evalCaseId',
            'evalPassed',
            'evalRubricFailures',
            'regressionGateStatus',
            'tokenUsageEstimated',
            'safeRefusal',
            'phiRedacted',
            'rawDocumentTextLogged',
            'rawScreenshotLogged',
            'screenshotCaptureAttempted',
            'screenshotBlockedReason',
            'frameStatus',
            'frameMode',
            'healthCheckStatus',
            'openemrAvailable',
            'requiresLogin',
            'targetOrigin',
            'targetPath',
            'unavailableReason'
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
            if (window.OpenEMRCopilotRedaction && typeof window.OpenEMRCopilotRedaction.sanitizeTelemetryPayload === 'function') {
                return window.OpenEMRCopilotRedaction.sanitizeTelemetryPayload(payload || {}, allowedKeys);
            }

            const safePayload = {};
            const patientKey = payload && payload.selectedPatientKey ? payload.selectedPatientKey : null;
            if (patientKey) {
                safePayload.patientContextPresent = true;
                safePayload.patientContextHash = hashIdentifier(patientKey);
                safePayload.patientIdentifierRedacted = true;
            }
            Object.keys(payload || {}).forEach(function (key) {
                if (!allowedKeys.has(key)) {
                    return;
                }

                const value = payload[key];
                if (value === undefined || value === null || value === '') {
                    return;
                }

                 if (key === 'selectedPatientKey') {
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

            const total = metrics.latencySamples.reduce(function (sum, value) {
                return sum + value;
            }, 0);

            return Math.round(total / metrics.latencySamples.length);
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
            log: function (eventName, payload) {
                const safePayload = sanitizePayload(payload || {});
                const event = Object.assign({
                    source: 'medical-copilot',
                    event: eventName,
                    timestamp: new Date().toISOString(),
                    sessionId: metrics.sessionId
                }, safePayload);

                updateMetrics(eventName, safePayload);

                const label = '[Medical Co-Pilot Audit] ' + eventName;
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

        return targetWindow.CopilotTelemetry;
    }

    function initWidget() {
        if (window.__openemrAICopilotWidgetLoaded) {
            return;
        }

        if (!window.OPENEMR_AI_COPILOT_URL || document.getElementById('openemr-ai-copilot-root')) {
            return;
        }

        window.__openemrAICopilotWidgetLoaded = true;

        const telemetry = ensureTelemetryHost(window);

        const state = {
            isOpen: false,
            iframeRequested: false,
            iframeReady: false,
            frameLoadInFlight: false,
            frameLoadTimer: null,
            frameStatus: 'idle',
            frameStatusMessage: '',
            lastFrameUrl: '',
            healthCheck: null
        };

        const root = document.createElement('div');
        root.id = 'openemr-ai-copilot-root';
        root.className = 'copilot-widget-root';

        const launcher = document.createElement('button');
        launcher.type = 'button';
        launcher.className = 'copilot-widget-launcher';
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-controls', 'openemr-ai-copilot-drawer');
        launcher.setAttribute('aria-label', 'Medical Co-Pilot');
        launcher.innerHTML =
            '<span class="copilot-widget-launcher-icon" aria-hidden="true">✦</span>' +
            '<span class="copilot-widget-launcher-label" aria-hidden="true">AI</span>';

        const drawer = document.createElement('section');
        drawer.id = 'openemr-ai-copilot-drawer';
        drawer.className = 'copilot-widget-drawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'false');
        drawer.setAttribute('aria-labelledby', 'openemr-ai-copilot-title');
        drawer.setAttribute('aria-hidden', 'true');

        const header = document.createElement('div');
        header.className = 'copilot-widget-header';
        header.innerHTML =
            '<div>' +
            '<h2 id="openemr-ai-copilot-title" class="copilot-widget-title">Medical Co-Pilot</h2>' +
            '<p class="copilot-widget-note">OpenEMR connection pending</p>' +
            '</div>';
        const headerNote = header.querySelector('.copilot-widget-note');

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'copilot-widget-close';
        closeButton.setAttribute('aria-label', 'Close Medical Co-Pilot');
        closeButton.innerHTML = '&times;';
        header.appendChild(closeButton);

        const body = document.createElement('div');
        body.className = 'copilot-widget-body';

        const statusPanel = document.createElement('section');
        statusPanel.className = 'copilot-widget-status-panel';
        statusPanel.setAttribute('aria-live', 'polite');

        const statusTitle = document.createElement('p');
        statusTitle.className = 'copilot-widget-status-title';
        statusTitle.textContent = 'OpenEMR connection pending';

        const statusMessage = document.createElement('p');
        statusMessage.className = 'copilot-widget-status-message';
        statusMessage.textContent = 'Open the copilot to verify the OpenEMR connection.';

        const statusActions = document.createElement('div');
        statusActions.className = 'copilot-widget-status-actions';

        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.className = 'copilot-widget-secondary-action';
        retryButton.textContent = 'Retry';
        retryButton.hidden = true;

        const openInNewTabLink = document.createElement('a');
        openInNewTabLink.className = 'copilot-widget-secondary-action copilot-widget-link-action';
        openInNewTabLink.target = '_blank';
        openInNewTabLink.rel = 'noopener noreferrer';
        openInNewTabLink.textContent = 'Open In New Tab';
        openInNewTabLink.hidden = true;

        statusActions.appendChild(retryButton);
        statusActions.appendChild(openInNewTabLink);
        statusPanel.appendChild(statusTitle);
        statusPanel.appendChild(statusMessage);
        statusPanel.appendChild(statusActions);
        body.appendChild(statusPanel);

        const frame = document.createElement('iframe');
        frame.className = 'copilot-widget-frame';
        frame.title = 'Medical Co-Pilot';
        frame.loading = 'lazy';
        frame.referrerPolicy = 'same-origin';
        frame.hidden = true;
        body.appendChild(frame);

        drawer.appendChild(header);
        drawer.appendChild(body);
        root.appendChild(launcher);
        root.appendChild(drawer);
        document.body.appendChild(root);

        function tryRestoreSession() {
            try {
                if (window.top && typeof window.top.restoreSession === 'function') {
                    window.top.restoreSession();
                }
            } catch (error) {
                logFrameEvent('openemr_cross_origin_access_blocked_prevented', {
                    frameStatus: 'guarded',
                    frameMode: 'restore_session_skipped',
                    unavailableReason: 'cross_origin_top_access_blocked',
                    openemrAvailable: false,
                    requiresLogin: false
                });
            }
        }

        function resolveCopilotUrl() {
            return typeof window.OPENEMR_AI_COPILOT_URL === 'string' ? window.OPENEMR_AI_COPILOT_URL : '';
        }

        function resolveHealthUrl() {
            if (typeof window.OPENEMR_AI_COPILOT_HEALTH_URL === 'string' && window.OPENEMR_AI_COPILOT_HEALTH_URL) {
                return window.OPENEMR_AI_COPILOT_HEALTH_URL;
            }

            const copilotUrl = resolveCopilotUrl();
            if (!copilotUrl) {
                return '';
            }

            try {
                const healthUrl = new URL(copilotUrl, window.location.href);
                healthUrl.searchParams.set('healthcheck', '1');
                return healthUrl.toString();
            } catch (error) {
                return copilotUrl;
            }
        }

        function resolveLoginUrl() {
            if (typeof window.OPENEMR_AI_COPILOT_LOGIN_URL === 'string' && window.OPENEMR_AI_COPILOT_LOGIN_URL) {
                return window.OPENEMR_AI_COPILOT_LOGIN_URL;
            }

            return '/interface/login/login.php';
        }

        function buildFrameUrl() {
            const copilotUrl = resolveCopilotUrl();
            if (!copilotUrl) {
                return '';
            }

            try {
                const url = new URL(copilotUrl, window.location.href);
                url.searchParams.set('frame_request_id', createId('copilot_frame'));
                return url.toString();
            } catch (error) {
                return copilotUrl;
            }
        }

        function buildSafeUrlMetadata(url) {
            try {
                const parsed = new URL(url, window.location.href);
                return {
                    targetOrigin: parsed.origin,
                    targetPath: parsed.pathname
                };
            } catch (error) {
                return {
                    targetOrigin: window.location.origin || '',
                    targetPath: ''
                };
            }
        }

        function clearFrameLoadTimer() {
            if (state.frameLoadTimer) {
                window.clearTimeout(state.frameLoadTimer);
                state.frameLoadTimer = null;
            }
        }

        function renderFrameState() {
            const isConnected = state.frameStatus === 'connected';
            const isLoading = state.frameStatus === 'checking' || state.frameStatus === 'loading';
            const isUnavailable = state.frameStatus === 'unavailable' || state.frameStatus === 'failed' || state.frameStatus === 'login_required';
            const noteText = isConnected
                ? 'OpenEMR connected'
                : (state.frameStatus === 'login_required'
                    ? 'OpenEMR session expired — reopen or log in'
                    : (isUnavailable ? 'OpenEMR unavailable — Copilot demo still running' : 'OpenEMR connection pending'));

            headerNote.textContent = noteText;
            statusTitle.textContent = noteText;
            statusMessage.textContent = state.frameStatusMessage || 'Open the copilot to verify the OpenEMR connection.';
            statusPanel.dataset.status = state.frameStatus;
            statusPanel.hidden = isConnected;
            retryButton.hidden = !(isUnavailable || state.frameStatus === 'idle');
            openInNewTabLink.hidden = !resolveCopilotUrl();
            openInNewTabLink.href = state.frameStatus === 'login_required' ? resolveLoginUrl() : resolveCopilotUrl();
            frame.hidden = !isConnected && !isLoading;

            body.classList.toggle('copilot-widget-body-loading', isLoading);
            body.classList.toggle('copilot-widget-body-frame-ready', isConnected);
            body.classList.toggle('copilot-widget-body-fallback', isUnavailable);
        }

        function logFrameEvent(eventName, payload) {
            if (!telemetry) {
                return;
            }

            telemetry.log(eventName, payload);
        }

        function markFrameFallback(message, details) {
            const payload = Object.assign({
                frameStatus: details.frameStatus || 'unavailable',
                healthCheckStatus: details.healthCheckStatus || details.frameStatus || 'unavailable',
                openemrAvailable: false,
                requiresLogin: Boolean(details.requiresLogin),
                unavailableReason: details.unavailableReason || 'frame_unavailable'
            }, details.urlMeta || {});

            state.iframeReady = false;
            state.iframeRequested = false;
            state.frameLoadInFlight = false;
            state.frameStatus = details.frameStatus || 'unavailable';
            state.frameStatusMessage = message;
            clearFrameLoadTimer();
            frame.removeAttribute('src');
            logFrameEvent('openemr_frame_load_failed', payload);
            if (payload.healthCheckStatus === 'failed' || payload.frameStatus === 'unavailable' || payload.frameStatus === 'login_required') {
                logFrameEvent('openemr_health_check_failed', payload);
            }
            logFrameEvent('copilot_demo_continued_without_openemr_frame', payload);
            renderFrameState();
        }

        async function runHealthCheck() {
            const healthUrl = resolveHealthUrl();
            const urlMeta = buildSafeUrlMetadata(resolveCopilotUrl());

            if (!healthUrl) {
                return {
                    ok: false,
                    frameStatus: 'unavailable',
                    healthCheckStatus: 'missing_url',
                    unavailableReason: 'missing_copilot_url',
                    requiresLogin: false,
                    urlMeta: urlMeta
                };
            }

            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const timeoutId = controller ? window.setTimeout(function () {
                controller.abort();
            }, 5000) : null;

            try {
                const response = await fetch(healthUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    signal: controller ? controller.signal : undefined
                });

                if (timeoutId) {
                    window.clearTimeout(timeoutId);
                }

                const redirectedToLogin = response.redirected && /\/interface\/login\/login\.php/i.test(response.url || '');
                if (redirectedToLogin) {
                    return {
                        ok: false,
                        frameStatus: 'login_required',
                        healthCheckStatus: 'login_required',
                        unavailableReason: 'session_expired',
                        requiresLogin: true,
                        urlMeta: urlMeta
                    };
                }

                if (!response.ok) {
                    return {
                        ok: false,
                        frameStatus: 'unavailable',
                        healthCheckStatus: 'http_' + response.status,
                        unavailableReason: 'health_http_error',
                        requiresLogin: false,
                        urlMeta: urlMeta
                    };
                }

                const contentType = (response.headers.get('content-type') || '').toLowerCase();
                if (contentType.indexOf('application/json') !== -1) {
                    const data = await response.json();
                    if (data && data.requiresLogin) {
                        return {
                            ok: false,
                            frameStatus: 'login_required',
                            healthCheckStatus: 'login_required',
                            unavailableReason: 'session_expired',
                            requiresLogin: true,
                            urlMeta: urlMeta
                        };
                    }
                }

                return {
                    ok: true,
                    frameStatus: 'available',
                    healthCheckStatus: 'ok',
                    unavailableReason: '',
                    requiresLogin: false,
                    urlMeta: urlMeta
                };
            } catch (error) {
                if (timeoutId) {
                    window.clearTimeout(timeoutId);
                }

                return {
                    ok: false,
                    frameStatus: 'unavailable',
                    healthCheckStatus: error && error.name === 'AbortError' ? 'timeout' : 'network_error',
                    unavailableReason: error && error.name === 'AbortError' ? 'health_timeout' : 'health_request_failed',
                    requiresLogin: false,
                    urlMeta: urlMeta
                };
            }
        }

        function syncOpenState() {
            root.classList.toggle('is-open', state.isOpen);
            launcher.setAttribute('aria-expanded', state.isOpen ? 'true' : 'false');
            drawer.setAttribute('aria-hidden', state.isOpen ? 'false' : 'true');
        }

        async function confirmFrameLoadSuccess() {
            state.healthCheck = await runHealthCheck();
            if (!state.healthCheck.ok) {
                markFrameFallback(
                    state.healthCheck.requiresLogin
                        ? 'OpenEMR session expired. Reopen or log in to continue.'
                        : 'OpenEMR is not reachable at localhost:8300. Start OpenEMR and refresh the demo.',
                    state.healthCheck
                );
                return;
            }

            state.iframeReady = true;
            state.frameLoadInFlight = false;
            state.frameStatus = 'connected';
            state.frameStatusMessage = 'OpenEMR connected';
            clearFrameLoadTimer();
            renderFrameState();
            logFrameEvent('openemr_frame_load_succeeded', Object.assign({
                frameStatus: 'connected',
                healthCheckStatus: 'ok',
                openemrAvailable: true,
                requiresLogin: false
            }, state.healthCheck.urlMeta || buildSafeUrlMetadata(state.lastFrameUrl || resolveCopilotUrl())));
        }

        async function ensureIframeLoaded(forceReload) {
            if (state.frameLoadInFlight) {
                return;
            }

            if (state.iframeReady && !forceReload) {
                state.frameStatus = 'connected';
                state.frameStatusMessage = 'OpenEMR connected';
                renderFrameState();
                return;
            }

            const frameUrl = buildFrameUrl();
            if (!frameUrl) {
                markFrameFallback('OpenEMR is not reachable at localhost:8300. Start OpenEMR and refresh the demo.', {
                    frameStatus: 'unavailable',
                    healthCheckStatus: 'missing_url',
                    unavailableReason: 'missing_copilot_url',
                    requiresLogin: false,
                    urlMeta: buildSafeUrlMetadata(window.location.href)
                });
                return;
            }

            state.frameLoadInFlight = true;
            state.frameStatus = 'checking';
            state.frameStatusMessage = 'Checking OpenEMR connection...';
            renderFrameState();

            const urlMeta = buildSafeUrlMetadata(frameUrl);
            logFrameEvent('openemr_cross_origin_access_blocked_prevented', Object.assign({
                frameStatus: 'guarded',
                frameMode: 'iframe_status_only',
                unavailableReason: 'cross_origin_dom_access_disabled'
            }, urlMeta));
            logFrameEvent('openemr_frame_load_started', Object.assign({
                frameStatus: 'checking',
                frameMode: 'embedded_iframe'
            }, urlMeta));

            state.healthCheck = await runHealthCheck();
            if (!state.healthCheck.ok) {
                markFrameFallback(
                    state.healthCheck.requiresLogin
                        ? 'OpenEMR session expired. Reopen or log in to continue.'
                        : 'OpenEMR is not reachable at localhost:8300. Start OpenEMR and refresh the demo.',
                    state.healthCheck
                );
                return;
            }

            state.iframeRequested = true;
            state.iframeReady = false;
            state.lastFrameUrl = frameUrl;
            state.frameStatus = 'loading';
            state.frameStatusMessage = 'Loading OpenEMR-connected copilot...';
            renderFrameState();
            clearFrameLoadTimer();
            state.frameLoadTimer = window.setTimeout(function () {
                markFrameFallback(
                    'OpenEMR did not finish loading in the embedded frame. You can retry or open the copilot in a new tab.',
                    {
                        frameStatus: 'failed',
                        healthCheckStatus: 'load_timeout',
                        unavailableReason: 'iframe_load_timeout',
                        requiresLogin: false,
                        urlMeta: urlMeta
                    }
                );
            }, 8000);
            frame.src = frameUrl;
        }

        function openDrawer() {
            tryRestoreSession();
            state.isOpen = true;
            syncOpenState();
            if (telemetry) {
                telemetry.log('copilot_open', {
                    role: window.OpenEMRCopilotState.role || 'doctor',
                    selectedPatientKey: window.OpenEMRCopilotState.selectedPatientKey || null
                });
            }
            ensureIframeLoaded(false);
            closeButton.focus();
        }

        function closeDrawer() {
            state.isOpen = false;
            syncOpenState();
            if (telemetry) {
                telemetry.log('copilot_close', {
                    role: window.OpenEMRCopilotState.role || 'doctor',
                    selectedPatientKey: window.OpenEMRCopilotState.selectedPatientKey || null
                });
            }
            launcher.focus();
        }

        function toggleDrawer() {
            if (state.isOpen) {
                closeDrawer();
            } else {
                openDrawer();
            }
        }

        retryButton.addEventListener('click', function () {
            ensureIframeLoaded(true);
        });
        openInNewTabLink.addEventListener('click', function () {
            if (telemetry) {
                telemetry.log('copilot_open', {
                    role: window.OpenEMRCopilotState.role || 'doctor',
                    selectedPatientKey: window.OpenEMRCopilotState.selectedPatientKey || null,
                    actionType: 'open_in_new_tab'
                });
            }
        });
        launcher.addEventListener('click', toggleDrawer);
        closeButton.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && state.isOpen) {
                event.preventDefault();
                closeDrawer();
            }
        });
        frame.addEventListener('load', function () {
            confirmFrameLoadSuccess();
        });
        frame.addEventListener('error', function () {
            markFrameFallback('OpenEMR is not reachable at localhost:8300. Start OpenEMR and refresh the demo.', {
                frameStatus: 'failed',
                healthCheckStatus: 'iframe_error',
                unavailableReason: 'iframe_load_error',
                requiresLogin: false,
                urlMeta: buildSafeUrlMetadata(state.lastFrameUrl || resolveCopilotUrl())
            });
        });

        syncOpenState();
        renderFrameState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget, { once: true });
    } else {
        initWidget();
    }
})();
