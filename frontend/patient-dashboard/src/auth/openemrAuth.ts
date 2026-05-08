import type { AuthSession, DashboardRuntimeConfig } from '../types/dashboard';
import type { SmartConfiguration } from '../types/fhir';

const AUTH_SESSION_KEY = 'openemr_patient_dashboard_auth_session';
const PKCE_STATE_KEY = 'openemr_patient_dashboard_pkce_state';

const DEFAULT_SCOPE = [
  'openid',
  'fhirUser',
  'patient/Patient.rs',
  'patient/AllergyIntolerance.rs',
  'patient/Condition.rs',
  'patient/MedicationRequest.rs',
  'patient/Medication.rs',
  'patient/CareTeam.rs',
  'patient/Encounter.rs'
].join(' ');

function getSearchParam(name: string): string {
  const value = new URLSearchParams(window.location.search).get(name);
  return value?.trim() ?? '';
}

function toAbsoluteUrl(value: string, fallbackOrigin: string): string {
  if (!value) {
    return fallbackOrigin;
  }

  try {
    return new URL(value, fallbackOrigin).toString().replace(/\/$/, '');
  } catch {
    return fallbackOrigin;
  }
}

function randomString(length = 64): string {
  const array = new Uint8Array(length);
  window.crypto.getRandomValues(array);
  return Array.from(array, (value) => value.toString(16).padStart(2, '0')).join('').slice(0, length);
}

function base64UrlEncode(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

async function createCodeChallenge(verifier: string): Promise<string> {
  const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return base64UrlEncode(digest);
}

function parseBooleanFlag(value: string | undefined, defaultValue: boolean): boolean {
  if (value == null || value === '') {
    return defaultValue;
  }

  return !['0', 'false', 'off', 'no'].includes(value.toLowerCase());
}

export function getOpenEmrRuntimeConfig(): DashboardRuntimeConfig {
  const env = import.meta.env;
  const searchBaseUrl = getSearchParam('baseUrl');
  const searchFhirBaseUrl = getSearchParam('fhirBaseUrl');
  const searchPatientId = getSearchParam('patient') || getSearchParam('pid');
  const site = getSearchParam('site') || env.VITE_OPENEMR_SITE || 'default';
  const originFallback = window.location.origin;
  const baseUrl = toAbsoluteUrl(searchBaseUrl || env.VITE_OPENEMR_BASE_URL || originFallback, originFallback);
  const fhirBaseUrl = toAbsoluteUrl(
    searchFhirBaseUrl || env.VITE_OPENEMR_FHIR_BASE_URL || `${baseUrl}/apis/${site}/fhir`,
    originFallback
  );

  return {
    baseUrl,
    site,
    fhirBaseUrl,
    oauthBaseUrl: `${baseUrl}/oauth2/${site}`,
    clientId: env.VITE_OPENEMR_CLIENT_ID || getSearchParam('clientId'),
    redirectUri: env.VITE_OPENEMR_REDIRECT_URI || `${window.location.origin}${window.location.pathname}`,
    scope: env.VITE_OPENEMR_SCOPE || DEFAULT_SCOPE,
    patientId: searchPatientId || env.VITE_OPENEMR_DEFAULT_PATIENT_ID || '',
    demoFallbackEnabled: parseBooleanFlag(
      getSearchParam('demoFallback') || env.VITE_OPENEMR_DEMO_FALLBACK,
      true
    )
  };
}

export function loadStoredAuthSession(): AuthSession | null {
  const raw = window.sessionStorage.getItem(AUTH_SESSION_KEY);
  if (!raw) {
    return null;
  }

  try {
    const parsed = JSON.parse(raw) as AuthSession;
    if (!parsed.accessToken || parsed.expiresAt <= Date.now()) {
      window.sessionStorage.removeItem(AUTH_SESSION_KEY);
      return null;
    }
    return parsed;
  } catch {
    window.sessionStorage.removeItem(AUTH_SESSION_KEY);
    return null;
  }
}

export function clearStoredAuthSession(): void {
  window.sessionStorage.removeItem(AUTH_SESSION_KEY);
  window.sessionStorage.removeItem(PKCE_STATE_KEY);
}

export async function discoverSmartConfiguration(fhirBaseUrl: string): Promise<SmartConfiguration> {
  const response = await fetch(`${fhirBaseUrl.replace(/\/$/, '')}/.well-known/smart-configuration`, {
    credentials: 'include',
    headers: {
      Accept: 'application/json'
    }
  });

  if (!response.ok) {
    throw new Error(`SMART discovery failed (${response.status}).`);
  }

  return (await response.json()) as SmartConfiguration;
}

export async function beginOpenEmrLogin(config: DashboardRuntimeConfig): Promise<void> {
  if (!config.clientId) {
    throw new Error('OpenEMR OAuth client ID is not configured.');
  }

  const smartConfig = await discoverSmartConfiguration(config.fhirBaseUrl);
  const state = randomString(32);
  const verifier = randomString(96);
  const challenge = await createCodeChallenge(verifier);

  window.sessionStorage.setItem(
    PKCE_STATE_KEY,
    JSON.stringify({
      state,
      verifier,
      redirectUri: config.redirectUri
    })
  );

  const authUrl = new URL(smartConfig.authorization_endpoint);
  authUrl.searchParams.set('response_type', 'code');
  authUrl.searchParams.set('client_id', config.clientId);
  authUrl.searchParams.set('redirect_uri', config.redirectUri);
  authUrl.searchParams.set('scope', config.scope);
  authUrl.searchParams.set('aud', config.fhirBaseUrl);
  authUrl.searchParams.set('state', state);
  authUrl.searchParams.set('code_challenge', challenge);
  authUrl.searchParams.set('code_challenge_method', 'S256');

  window.location.assign(authUrl.toString());
}

export async function completeOpenEmrLogin(config: DashboardRuntimeConfig): Promise<AuthSession | null> {
  const params = new URLSearchParams(window.location.search);
  const code = params.get('code');
  const state = params.get('state');
  const error = params.get('error');

  if (error) {
    throw new Error(`OpenEMR OAuth authorization failed: ${error}`);
  }

  if (!code) {
    return loadStoredAuthSession();
  }

  const rawPkce = window.sessionStorage.getItem(PKCE_STATE_KEY);
  if (!rawPkce) {
    throw new Error('Missing PKCE state. Start the OpenEMR login flow again.');
  }

  const pkceState = JSON.parse(rawPkce) as { state: string; verifier: string; redirectUri: string };
  if (state !== pkceState.state) {
    throw new Error('OAuth state mismatch. Start the OpenEMR login flow again.');
  }

  const smartConfig = await discoverSmartConfiguration(config.fhirBaseUrl);
  const tokenResponse = await fetch(smartConfig.token_endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code,
      client_id: config.clientId,
      redirect_uri: pkceState.redirectUri,
      code_verifier: pkceState.verifier
    }).toString()
  });

  if (!tokenResponse.ok) {
    throw new Error(`OpenEMR token exchange failed (${tokenResponse.status}).`);
  }

  const payload = (await tokenResponse.json()) as {
    access_token: string;
    token_type?: string;
    expires_in?: number;
    scope?: string;
  };

  const session: AuthSession = {
    accessToken: payload.access_token,
    tokenType: payload.token_type || 'Bearer',
    expiresAt: Date.now() + (payload.expires_in || 3600) * 1000,
    scope: payload.scope || config.scope
  };

  window.sessionStorage.setItem(AUTH_SESSION_KEY, JSON.stringify(session));
  window.sessionStorage.removeItem(PKCE_STATE_KEY);

  const cleanUrl = new URL(window.location.href);
  cleanUrl.searchParams.delete('code');
  cleanUrl.searchParams.delete('state');
  cleanUrl.searchParams.delete('session_state');
  window.history.replaceState({}, document.title, cleanUrl.toString());

  return session;
}
