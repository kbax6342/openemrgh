import type { ReactNode } from 'react';

interface DashboardShellProps {
  patientId: string;
  authConfigured: boolean;
  authConnected: boolean;
  authBusy: boolean;
  authError?: string | null;
  onConnect: () => void;
  onDisconnect: () => void;
  children: ReactNode;
}

export function DashboardShell(props: DashboardShellProps) {
  return (
    <div className="dashboard-shell">
      <header className="dashboard-topbar">
        <div>
          <p className="eyebrow">OpenEMR patient dashboard migration</p>
          <p className="dashboard-topbar__subtitle">
            React + TypeScript + Vite frontend consuming the existing OpenEMR FHIR and OAuth stack.
          </p>
        </div>

        <div className="dashboard-topbar__actions">
          <span className="status-pill">{props.authConnected ? 'OAuth connected' : 'Read-only mode'}</span>
          {props.authConfigured && !props.authConnected ? (
            <button className="action-button" type="button" onClick={props.onConnect} disabled={props.authBusy}>
              {props.authBusy ? 'Connecting…' : 'Connect to OpenEMR'}
            </button>
          ) : null}
          {props.authConnected ? (
            <button className="action-button action-button--secondary" type="button" onClick={props.onDisconnect}>
              Disconnect
            </button>
          ) : null}
        </div>
      </header>

      {props.authError ? <div className="banner banner--error">{props.authError}</div> : null}

      {!props.patientId ? (
        <div className="banner">
          No patient context was supplied. Add <code>?patient=&lt;FHIR patient id&gt;</code> or set{' '}
          <code>VITE_OPENEMR_DEFAULT_PATIENT_ID</code>.
        </div>
      ) : null}

      <main className="dashboard-content">{props.children}</main>
    </div>
  );
}
