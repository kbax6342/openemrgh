import type {
  FhirAllergyIntolerance,
  FhirBundle,
  FhirCareTeam,
  FhirCodeableConcept,
  FhirCondition,
  FhirEncounter,
  FhirHumanName,
  FhirIdentifier,
  FhirMedication,
  FhirMedicationRequest,
  FhirPatient,
  FhirReference
} from '../types/fhir';

export interface FhirClientConfig {
  fhirBaseUrl: string;
  accessToken?: string | null;
}

export class FhirClientError extends Error {
  public readonly status?: number;
  public readonly kind: 'config' | 'network' | 'http';
  public readonly operation: string;

  public constructor(message: string, operation: string, kind: 'config' | 'network' | 'http', status?: number) {
    super(message);
    this.name = 'FhirClientError';
    this.kind = kind;
    this.status = status;
    this.operation = operation;
  }
}

function buildUrl(baseUrl: string, path: string, search?: Record<string, string | undefined>): string {
  const url = new URL(path.replace(/^\//, ''), `${baseUrl.replace(/\/$/, '')}/`);
  Object.entries(search || {}).forEach(([key, value]) => {
    if (value) {
      url.searchParams.set(key, value);
    }
  });
  return url.toString();
}

async function requestJson<T>(
  client: FhirClientConfig,
  operation: string,
  path: string,
  search?: Record<string, string | undefined>
): Promise<T> {
  if (!client.fhirBaseUrl) {
    throw new FhirClientError('FHIR base URL is not configured.', operation, 'config');
  }

  const headers = new Headers({
    Accept: 'application/fhir+json, application/json'
  });
  if (client.accessToken) {
    headers.set('Authorization', `Bearer ${client.accessToken}`);
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(client.fhirBaseUrl, path, search), {
      method: 'GET',
      credentials: 'include',
      headers
    });
  } catch {
    throw new FhirClientError('Network request to OpenEMR failed.', operation, 'network');
  }

  if (!response.ok) {
    throw new FhirClientError(`FHIR request failed (${response.status}).`, operation, 'http', response.status);
  }

  return (await response.json()) as T;
}

export function isApiUnavailable(error: unknown): boolean {
  if (!(error instanceof FhirClientError)) {
    return false;
  }

  return error.kind === 'config' || error.kind === 'network' || (error.kind === 'http' && (error.status ?? 0) >= 500);
}

export function bundleResources<T>(bundle: FhirBundle<T>): T[] {
  return (bundle.entry ?? [])
    .map((entry) => entry.resource)
    .filter((resource): resource is T => Boolean(resource));
}

export function codeableConceptToText(concept?: FhirCodeableConcept): string {
  if (!concept) {
    return '';
  }

  if (concept.text) {
    return concept.text;
  }

  const display = concept.coding?.find((coding) => coding.display)?.display;
  return display || concept.coding?.find((coding) => coding.code)?.code || '';
}

export function codingListToText(codings?: Array<{ display?: string; code?: string }>): string {
  if (!codings || codings.length === 0) {
    return '';
  }

  return codings.find((coding) => coding.display)?.display || codings[0]?.code || '';
}

export function humanNameToText(name?: FhirHumanName): string {
  if (!name) {
    return '';
  }

  if (name.text) {
    return name.text;
  }

  return [name.given?.join(' '), name.family].filter(Boolean).join(' ').trim();
}

export function identifierToMrn(identifier?: FhirIdentifier[]): string {
  if (!identifier || identifier.length === 0) {
    return '';
  }

  const exactMrn = identifier.find((item) =>
    item.type?.coding?.some((coding) => coding.code === 'MR' || coding.display?.toLowerCase().includes('medical record'))
  );
  return exactMrn?.value || identifier.find((item) => item.use === 'usual')?.value || identifier[0]?.value || '';
}

export function referenceToId(reference?: FhirReference): string {
  const raw = reference?.reference || '';
  const segments = raw.split('/');
  return segments[segments.length - 1] || '';
}

export function referenceToText(reference?: FhirReference): string {
  return reference?.display || referenceToId(reference) || '';
}

export async function fetchPatient(client: FhirClientConfig, patientId: string): Promise<FhirPatient> {
  return requestJson<FhirPatient>(client, 'fetchPatient', `Patient/${encodeURIComponent(patientId)}`);
}

export async function fetchAllergies(client: FhirClientConfig, patientId: string): Promise<FhirAllergyIntolerance[]> {
  const bundle = await requestJson<FhirBundle<FhirAllergyIntolerance>>(
    client,
    'fetchAllergies',
    'AllergyIntolerance',
    { patient: patientId, _count: '20' }
  );
  return bundleResources(bundle);
}

export async function fetchConditions(client: FhirClientConfig, patientId: string): Promise<FhirCondition[]> {
  const bundle = await requestJson<FhirBundle<FhirCondition>>(
    client,
    'fetchConditions',
    'Condition',
    { patient: patientId, _count: '20' }
  );
  return bundleResources(bundle);
}

export async function fetchMedicationRequests(
  client: FhirClientConfig,
  patientId: string
): Promise<FhirMedicationRequest[]> {
  const bundle = await requestJson<FhirBundle<FhirMedicationRequest>>(
    client,
    'fetchMedicationRequests',
    'MedicationRequest',
    { patient: patientId, _count: '25' }
  );
  return bundleResources(bundle);
}

export async function fetchMedications(client: FhirClientConfig, medicationIds: string[]): Promise<FhirMedication[]> {
  const uniqueIds = [...new Set(medicationIds.filter(Boolean))];
  if (uniqueIds.length === 0) {
    return [];
  }

  const settled = await Promise.allSettled(
    uniqueIds.map((id) => requestJson<FhirMedication>(client, 'fetchMedication', `Medication/${encodeURIComponent(id)}`))
  );

  return settled
    .filter((result): result is PromiseFulfilledResult<FhirMedication> => result.status === 'fulfilled')
    .map((result) => result.value);
}

export async function fetchCareTeams(client: FhirClientConfig, patientId: string): Promise<FhirCareTeam[]> {
  const bundle = await requestJson<FhirBundle<FhirCareTeam>>(
    client,
    'fetchCareTeams',
    'CareTeam',
    { patient: patientId, _count: '20' }
  );
  return bundleResources(bundle);
}

export async function fetchEncounters(client: FhirClientConfig, patientId: string): Promise<FhirEncounter[]> {
  const bundle = await requestJson<FhirBundle<FhirEncounter>>(
    client,
    'fetchEncounters',
    'Encounter',
    { patient: patientId, _count: '15' }
  );
  return bundleResources(bundle);
}
