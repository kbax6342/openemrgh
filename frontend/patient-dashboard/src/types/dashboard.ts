export type DashboardSource = 'live' | 'demo-fallback';

export interface DashboardPayload<T> {
  data: T;
  source: DashboardSource;
  notice?: string;
}

export interface PatientHeaderData {
  id: string;
  name: string;
  dateOfBirth: string;
  sex: string;
  mrn: string;
  activeStatus: string;
}

export interface AllergySummary {
  id: string;
  substance: string;
  clinicalStatus: string;
  verificationStatus: string;
  criticality: string;
  reaction: string;
}

export interface ProblemSummary {
  id: string;
  name: string;
  clinicalStatus: string;
  verificationStatus: string;
  recordedDate: string;
}

export interface MedicationSummary {
  id: string;
  name: string;
  dosage: string;
  status: string;
  intent: string;
}

export interface PrescriptionSummary {
  id: string;
  name: string;
  authoredOn: string;
  status: string;
  requester: string;
  dosage: string;
}

export interface CareTeamSummary {
  id: string;
  member: string;
  role: string;
  period: string;
  status: string;
}

export interface EncounterSummary {
  id: string;
  type: string;
  className: string;
  status: string;
  start: string;
  end: string;
}

export interface DashboardRuntimeConfig {
  baseUrl: string;
  site: string;
  fhirBaseUrl: string;
  oauthBaseUrl: string;
  clientId: string;
  redirectUri: string;
  scope: string;
  patientId: string;
  demoFallbackEnabled: boolean;
}

export interface AuthSession {
  accessToken: string;
  tokenType: string;
  expiresAt: number;
  scope: string;
}
