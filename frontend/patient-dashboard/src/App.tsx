import { useEffect, useState } from 'react';
import { AllergiesCard } from './components/AllergiesCard';
import { CareTeamCard } from './components/CareTeamCard';
import { DashboardShell } from './components/DashboardShell';
import { EncounterHistoryCard } from './components/EncounterHistoryCard';
import { MedicationsCard } from './components/MedicationsCard';
import { PatientHeader } from './components/PatientHeader';
import { PrescriptionsCard } from './components/PrescriptionsCard';
import { ProblemListCard } from './components/ProblemListCard';
import {
  beginOpenEmrLogin,
  clearStoredAuthSession,
  completeOpenEmrLogin,
  getOpenEmrRuntimeConfig,
  loadStoredAuthSession
} from './auth/openemrAuth';
import { usePatientDashboard } from './hooks/usePatientDashboard';
import type { AuthSession } from './types/dashboard';

export default function App() {
  const [config] = useState(() => getOpenEmrRuntimeConfig());
  const [authSession, setAuthSession] = useState<AuthSession | null>(() => loadStoredAuthSession());
  const [authBusy, setAuthBusy] = useState(false);
  const [authError, setAuthError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function hydrateAuthFromCallback() {
      if (!window.location.search.includes('code=')) {
        return;
      }

      setAuthBusy(true);
      try {
        const session = await completeOpenEmrLogin(config);
        if (!cancelled) {
          setAuthSession(session);
          setAuthError(null);
        }
      } catch (error) {
        if (!cancelled) {
          setAuthError(error instanceof Error ? error.message : 'OpenEMR login could not be completed.');
        }
      } finally {
        if (!cancelled) {
          setAuthBusy(false);
        }
      }
    }

    void hydrateAuthFromCallback();
    return () => {
      cancelled = true;
    };
  }, [config]);

  async function handleConnect() {
    setAuthBusy(true);
    setAuthError(null);
    try {
      await beginOpenEmrLogin(config);
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'OpenEMR login could not be started.');
      setAuthBusy(false);
    }
  }

  function handleDisconnect() {
    clearStoredAuthSession();
    setAuthSession(null);
    setAuthError(null);
  }

  const queries = usePatientDashboard({
    patientId: config.patientId,
    fhirBaseUrl: config.fhirBaseUrl,
    accessToken: authSession?.accessToken ?? null,
    demoFallbackEnabled: config.demoFallbackEnabled
  });

  return (
    <DashboardShell
      patientId={config.patientId}
      authConfigured={Boolean(config.clientId)}
      authConnected={Boolean(authSession?.accessToken)}
      authBusy={authBusy}
      authError={authError}
      onConnect={handleConnect}
      onDisconnect={handleDisconnect}
    >
      <PatientHeader
        data={queries.patientHeader.data}
        isLoading={queries.patientHeader.isLoading}
        isError={queries.patientHeader.isError}
        errorMessage={queries.patientHeader.error?.message}
      />

      <section className="dashboard-grid" aria-label="Clinical dashboard cards">
        <AllergiesCard query={queries.allergies} />
        <ProblemListCard query={queries.problemList} />
        <MedicationsCard query={queries.medications} />
        <PrescriptionsCard query={queries.prescriptions} />
        <CareTeamCard query={queries.careTeam} />
        <EncounterHistoryCard query={queries.encounters} />
      </section>
    </DashboardShell>
  );
}
