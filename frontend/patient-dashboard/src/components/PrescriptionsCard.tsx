import type { UseQueryResult } from '@tanstack/react-query';
import type { DashboardPayload, PrescriptionSummary } from '../types/dashboard';
import { ClinicalCard } from './ClinicalCard';

interface PrescriptionsCardProps {
  query: UseQueryResult<DashboardPayload<PrescriptionSummary[]>, Error>;
}

export function PrescriptionsCard({ query }: PrescriptionsCardProps) {
  const items = query.data?.data ?? [];

  return (
    <ClinicalCard
      title="Prescriptions"
      subtitle="FHIR MedicationRequest"
      source={query.data?.source}
      notice={query.data?.notice}
      isLoading={query.isLoading}
      isError={query.isError}
      errorMessage={query.error?.message}
      isEmpty={!items.length}
      emptyMessage="No prescriptions were returned for this patient."
    >
      <ul className="detail-list">
        {items.map((item) => (
          <li key={item.id}>
            <div className="detail-list__title">{item.name}</div>
            <div className="detail-list__meta">
              <span>Authored: {item.authoredOn}</span>
              <span>Status: {item.status}</span>
              <span>Requester: {item.requester}</span>
            </div>
            <p>{item.dosage}</p>
          </li>
        ))}
      </ul>
    </ClinicalCard>
  );
}
