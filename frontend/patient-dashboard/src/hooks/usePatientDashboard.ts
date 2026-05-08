import { useQuery, type UseQueryResult } from '@tanstack/react-query';
import {
  codeableConceptToText,
  codingListToText,
  fetchAllergies,
  fetchCareTeams,
  fetchConditions,
  fetchEncounters,
  fetchMedicationRequests,
  fetchMedications,
  fetchPatient,
  humanNameToText,
  identifierToMrn,
  isApiUnavailable,
  referenceToId,
  referenceToText,
  type FhirClientConfig
} from '../api/fhirClient';
import type {
  AllergySummary,
  CareTeamSummary,
  DashboardPayload,
  EncounterSummary,
  MedicationSummary,
  PatientHeaderData,
  ProblemSummary,
  PrescriptionSummary
} from '../types/dashboard';
import type { FhirMedication, FhirMedicationRequest, FhirPatient } from '../types/fhir';

interface UsePatientDashboardOptions {
  patientId: string;
  fhirBaseUrl: string;
  accessToken?: string | null;
  demoFallbackEnabled: boolean;
}

interface PatientDashboardQueries {
  patientHeader: UseQueryResult<DashboardPayload<PatientHeaderData>, Error>;
  allergies: UseQueryResult<DashboardPayload<AllergySummary[]>, Error>;
  problemList: UseQueryResult<DashboardPayload<ProblemSummary[]>, Error>;
  medications: UseQueryResult<DashboardPayload<MedicationSummary[]>, Error>;
  prescriptions: UseQueryResult<DashboardPayload<PrescriptionSummary[]>, Error>;
  careTeam: UseQueryResult<DashboardPayload<CareTeamSummary[]>, Error>;
  encounters: UseQueryResult<DashboardPayload<EncounterSummary[]>, Error>;
}

function createDemoPayload<T>(data: T): DashboardPayload<T> {
  return {
    data,
    source: 'demo-fallback',
    notice: 'Demo fallback — OpenEMR API unavailable. Backend data remains read-only and untouched.'
  };
}

function formatDate(value?: string): string {
  if (!value) {
    return 'Not available';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  }).format(date);
}

function formatPeriod(start?: string, end?: string): string {
  if (!start && !end) {
    return 'Not available';
  }

  if (start && end) {
    return `${formatDate(start)} to ${formatDate(end)}`;
  }

  return start ? `Started ${formatDate(start)}` : `Until ${formatDate(end)}`;
}

function buildPatientHeader(patient: FhirPatient): PatientHeaderData {
  return {
    id: patient.id || '',
    name: humanNameToText(patient.name?.[0]) || 'Unknown patient',
    dateOfBirth: formatDate(patient.birthDate),
    sex: patient.gender ? patient.gender.replace(/^\w/, (match) => match.toUpperCase()) : 'Unknown',
    mrn: identifierToMrn(patient.identifier) || 'Not available',
    activeStatus: patient.active ? 'Active' : 'Inactive'
  };
}

function resolveMedicationName(request: FhirMedicationRequest, medicationMap: Map<string, FhirMedication>): string {
  const directName = codeableConceptToText(request.medicationCodeableConcept);
  if (directName) {
    return directName;
  }

  const medicationId = referenceToId(request.medicationReference);
  if (medicationId) {
    const medication = medicationMap.get(medicationId);
    const resolved = codeableConceptToText(medication?.code);
    if (resolved) {
      return resolved;
    }
  }

  return referenceToText(request.medicationReference) || 'Unspecified medication';
}

function buildFallbackPatient(patientId: string): PatientHeaderData {
  return {
    id: patientId || 'demo-patient',
    name: 'Marcus Johnson',
    dateOfBirth: 'Apr 18, 1977',
    sex: 'Male',
    mrn: 'MRN-100045',
    activeStatus: 'Active'
  };
}

function buildFallbackAllergies(): AllergySummary[] {
  return [
    {
      id: 'allergy-demo-1',
      substance: 'Penicillin',
      clinicalStatus: 'Active',
      verificationStatus: 'Confirmed',
      criticality: 'High',
      reaction: 'Rash'
    }
  ];
}

function buildFallbackProblems(): ProblemSummary[] {
  return [
    {
      id: 'problem-demo-1',
      name: 'Type 2 diabetes mellitus',
      clinicalStatus: 'Active',
      verificationStatus: 'Confirmed',
      recordedDate: 'Feb 11, 2025'
    },
    {
      id: 'problem-demo-2',
      name: 'Hyperlipidemia',
      clinicalStatus: 'Active',
      verificationStatus: 'Confirmed',
      recordedDate: 'Feb 11, 2025'
    }
  ];
}

function buildFallbackMedications(): MedicationSummary[] {
  return [
    {
      id: 'med-demo-1',
      name: 'Metformin 1000 mg tablet',
      dosage: 'Take 1 tablet by mouth twice daily',
      status: 'Active',
      intent: 'Order'
    }
  ];
}

function buildFallbackPrescriptions(): PrescriptionSummary[] {
  return [
    {
      id: 'rx-demo-1',
      name: 'Atorvastatin 20 mg tablet',
      authoredOn: 'Mar 20, 2026',
      status: 'Active',
      requester: 'Dr. Rivera',
      dosage: 'Take 1 tablet by mouth nightly'
    }
  ];
}

function buildFallbackCareTeam(): CareTeamSummary[] {
  return [
    {
      id: 'care-demo-1',
      member: 'Dr. Elena Rivera',
      role: 'Primary care physician',
      period: 'Started Jan 12, 2025',
      status: 'Active'
    }
  ];
}

function buildFallbackEncounters(): EncounterSummary[] {
  return [
    {
      id: 'enc-demo-1',
      type: 'Follow-up visit',
      className: 'Outpatient',
      status: 'Finished',
      start: 'Apr 03, 2026',
      end: 'Apr 03, 2026'
    }
  ];
}

async function withFallback<T>(
  enabled: boolean,
  fallbackFactory: () => T,
  task: () => Promise<T>
): Promise<DashboardPayload<T>> {
  try {
    return {
      data: await task(),
      source: 'live'
    };
  } catch (error) {
    if (enabled && isApiUnavailable(error)) {
      return createDemoPayload(fallbackFactory());
    }
    throw error instanceof Error ? error : new Error('Unknown dashboard request failed.');
  }
}

export function usePatientDashboard(options: UsePatientDashboardOptions): PatientDashboardQueries {
  const client: FhirClientConfig = {
    fhirBaseUrl: options.fhirBaseUrl,
    accessToken: options.accessToken
  };

  const authMode = options.accessToken ? 'oauth' : 'session';
  const baseKey = ['patient-dashboard', options.patientId, authMode];

  const patientHeader = useQuery({
    queryKey: [...baseKey, 'patient-header'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, () => buildFallbackPatient(options.patientId), async () =>
        buildPatientHeader(await fetchPatient(client, options.patientId))
      ),
    staleTime: 60_000
  });

  const allergies = useQuery({
    queryKey: [...baseKey, 'allergies'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, buildFallbackAllergies, async () => {
        const resources = await fetchAllergies(client, options.patientId);
        return resources.map((resource) => ({
          id: resource.id || '',
          substance: codeableConceptToText(resource.code) || 'Unspecified allergy',
          clinicalStatus: codingListToText(resource.clinicalStatus?.coding) || 'Unknown',
          verificationStatus: codingListToText(resource.verificationStatus?.coding) || 'Unknown',
          criticality: resource.criticality || 'Unknown',
          reaction:
            resource.reaction
              ?.flatMap((reaction) => reaction.manifestation ?? [])
              .map((manifestation) => codeableConceptToText(manifestation))
              .filter(Boolean)
              .join(', ') || 'Not documented'
        }));
      }),
    staleTime: 60_000
  });

  const problemList = useQuery({
    queryKey: [...baseKey, 'problem-list'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, buildFallbackProblems, async () => {
        const resources = await fetchConditions(client, options.patientId);
        return resources.map((resource) => ({
          id: resource.id || '',
          name: codeableConceptToText(resource.code) || 'Unspecified condition',
          clinicalStatus: codingListToText(resource.clinicalStatus?.coding) || 'Unknown',
          verificationStatus: codingListToText(resource.verificationStatus?.coding) || 'Unknown',
          recordedDate: formatDate(resource.recordedDate)
        }));
      }),
    staleTime: 60_000
  });

  const medications = useQuery({
    queryKey: [...baseKey, 'medications'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, buildFallbackMedications, async () => {
        const requests = await fetchMedicationRequests(client, options.patientId);
        const referencedMedicationIds = requests.map((request) => referenceToId(request.medicationReference)).filter(Boolean);
        const medicationsById = new Map(
          (await fetchMedications(client, referencedMedicationIds)).map((medication) => [medication.id || '', medication])
        );
        return requests.map((request) => ({
          id: request.id || '',
          name: resolveMedicationName(request, medicationsById),
          dosage: request.dosageInstruction?.[0]?.text || 'Not documented',
          status: request.status || 'Unknown',
          intent: request.intent || 'Unknown'
        }));
      }),
    staleTime: 60_000
  });

  const prescriptions = useQuery({
    queryKey: [...baseKey, 'prescriptions'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, buildFallbackPrescriptions, async () => {
        const requests = await fetchMedicationRequests(client, options.patientId);
        const referencedMedicationIds = requests.map((request) => referenceToId(request.medicationReference)).filter(Boolean);
        const medicationsById = new Map(
          (await fetchMedications(client, referencedMedicationIds)).map((medication) => [medication.id || '', medication])
        );
        return requests.map((request) => ({
          id: request.id || '',
          name: resolveMedicationName(request, medicationsById),
          authoredOn: formatDate(request.authoredOn),
          status: request.status || 'Unknown',
          requester: referenceToText(request.requester) || 'Not documented',
          dosage: request.dosageInstruction?.[0]?.text || 'Not documented'
        }));
      }),
    staleTime: 60_000
  });

  const careTeam = useQuery({
    queryKey: [...baseKey, 'care-team'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, buildFallbackCareTeam, async () => {
        const resources = await fetchCareTeams(client, options.patientId);
        return resources.flatMap((resource) =>
          (resource.participant ?? []).map((participant, index) => ({
            id: `${resource.id || 'care-team'}-${index}`,
            member: referenceToText(participant.member) || resource.name || 'Unknown team member',
            role: participant.role?.map((role) => codeableConceptToText(role)).filter(Boolean).join(', ') || 'Not documented',
            period: formatPeriod(resource.period?.start, resource.period?.end),
            status: resource.status || 'Unknown'
          }))
        );
      }),
    staleTime: 60_000
  });

  const encounters = useQuery({
    queryKey: [...baseKey, 'encounters'],
    enabled: Boolean(options.patientId),
    queryFn: () =>
      withFallback(options.demoFallbackEnabled, buildFallbackEncounters, async () => {
        const resources = await fetchEncounters(client, options.patientId);
        return resources.map((resource) => ({
          id: resource.id || '',
          type:
            resource.type?.map((item) => codeableConceptToText(item)).filter(Boolean).join(', ') ||
            codeableConceptToText(resource.serviceType) ||
            'Unspecified encounter',
          className: resource.class?.display || resource.class?.code || 'Unknown',
          status: resource.status || 'Unknown',
          start: formatDate(resource.period?.start),
          end: formatDate(resource.period?.end)
        }));
      }),
    staleTime: 60_000
  });

  return {
    patientHeader,
    allergies,
    problemList,
    medications,
    prescriptions,
    careTeam,
    encounters
  };
}
