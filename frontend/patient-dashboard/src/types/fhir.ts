export interface FhirBundle<T> {
  resourceType: 'Bundle';
  entry?: Array<{
    resource?: T;
  }>;
  total?: number;
}

export interface FhirIdentifier {
  use?: string;
  system?: string;
  value?: string;
  type?: FhirCodeableConcept;
}

export interface FhirHumanName {
  text?: string;
  family?: string;
  given?: string[];
}

export interface FhirCoding {
  system?: string;
  code?: string;
  display?: string;
}

export interface FhirCodeableConcept {
  text?: string;
  coding?: FhirCoding[];
}

export interface FhirReference {
  reference?: string;
  display?: string;
}

export interface FhirPeriod {
  start?: string;
  end?: string;
}

export interface FhirPatient {
  resourceType: 'Patient';
  id?: string;
  active?: boolean;
  identifier?: FhirIdentifier[];
  name?: FhirHumanName[];
  birthDate?: string;
  gender?: string;
}

export interface FhirAllergyIntolerance {
  resourceType: 'AllergyIntolerance';
  id?: string;
  clinicalStatus?: {
    coding?: FhirCoding[];
  };
  verificationStatus?: {
    coding?: FhirCoding[];
  };
  code?: FhirCodeableConcept;
  criticality?: string;
  reaction?: Array<{
    manifestation?: FhirCodeableConcept[];
  }>;
}

export interface FhirCondition {
  resourceType: 'Condition';
  id?: string;
  clinicalStatus?: {
    coding?: FhirCoding[];
  };
  verificationStatus?: {
    coding?: FhirCoding[];
  };
  code?: FhirCodeableConcept;
  recordedDate?: string;
}

export interface FhirMedication {
  resourceType: 'Medication';
  id?: string;
  code?: FhirCodeableConcept;
}

export interface FhirMedicationRequest {
  resourceType: 'MedicationRequest';
  id?: string;
  status?: string;
  intent?: string;
  authoredOn?: string;
  medicationCodeableConcept?: FhirCodeableConcept;
  medicationReference?: FhirReference;
  requester?: FhirReference;
  dosageInstruction?: Array<{
    text?: string;
  }>;
}

export interface FhirCareTeam {
  resourceType: 'CareTeam';
  id?: string;
  status?: string;
  name?: string;
  period?: FhirPeriod;
  participant?: Array<{
    role?: FhirCodeableConcept[];
    member?: FhirReference;
  }>;
}

export interface FhirEncounter {
  resourceType: 'Encounter';
  id?: string;
  status?: string;
  class?: FhirCoding;
  type?: FhirCodeableConcept[];
  period?: FhirPeriod;
  serviceType?: FhirCodeableConcept;
}

export interface SmartConfiguration {
  authorization_endpoint: string;
  token_endpoint: string;
  registration_endpoint?: string;
  issuer?: string;
  scopes_supported?: string[];
  token_endpoint_auth_methods_supported?: string[];
  code_challenge_methods_supported?: string[];
}
