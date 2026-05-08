import type { DashboardPayload, PatientHeaderData } from '../types/dashboard';

interface PatientHeaderProps {
  data?: DashboardPayload<PatientHeaderData>;
  isLoading: boolean;
  isError: boolean;
  errorMessage?: string;
}

function renderValue(value?: string): string {
  return value && value.trim() ? value : 'Not available';
}

export function PatientHeader(props: PatientHeaderProps) {
  if (props.isLoading) {
    return <section className="patient-header patient-header--state">Loading patient header…</section>;
  }

  if (props.isError || !props.data) {
    return (
      <section className="patient-header patient-header--state patient-header--error">
        {props.errorMessage || 'Unable to load patient header.'}
      </section>
    );
  }

  const patient = props.data.data;

  return (
    <section className="patient-header">
      <div className="patient-header__identity">
        <div>
          <p className="eyebrow">Patient dashboard</p>
          <h1>{patient.name}</h1>
        </div>
        <div className="patient-header__status">
          {props.data.source === 'demo-fallback' ? <span className="status-pill status-pill--fallback">Demo fallback</span> : null}
          <span className="status-pill">{renderValue(patient.activeStatus)}</span>
        </div>
      </div>

      {props.data.notice ? <p className="patient-header__notice">{props.data.notice}</p> : null}

      <dl className="patient-header__facts">
        <div>
          <dt>Date of birth</dt>
          <dd>{renderValue(patient.dateOfBirth)}</dd>
        </div>
        <div>
          <dt>Sex</dt>
          <dd>{renderValue(patient.sex)}</dd>
        </div>
        <div>
          <dt>MRN</dt>
          <dd>{renderValue(patient.mrn)}</dd>
        </div>
        <div>
          <dt>FHIR patient ID</dt>
          <dd>{renderValue(patient.id)}</dd>
        </div>
      </dl>
    </section>
  );
}
