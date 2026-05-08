import type { UseQueryResult } from '@tanstack/react-query';
import type { DashboardPayload, MedicationSummary } from '../types/dashboard';
import { ClinicalCard } from './ClinicalCard';

interface MedicationsCardProps {
  query: UseQueryResult<DashboardPayload<MedicationSummary[]>, Error>;
}

export function MedicationsCard({ query }: MedicationsCardProps) {
  const items = query.data?.data ?? [];

  return (
    <ClinicalCard
      title="Medications"
      subtitle="FHIR MedicationRequest / Medication"
      source={query.data?.source}
      notice={query.data?.notice}
      isLoading={query.isLoading}
      isError={query.isError}
      errorMessage={query.error?.message}
      isEmpty={!items.length}
      emptyMessage="No medications were returned for this patient."
    >
      <ul className="detail-list">
        {items.map((item) => (
          <li key={item.id}>
            <div className="detail-list__title">{item.name}</div>
            <div className="detail-list__meta">
              <span>Status: {item.status}</span>
              <span>Intent: {item.intent}</span>
            </div>
            <p>{item.dosage}</p>
          </li>
        ))}
      </ul>
    </ClinicalCard>
  );
}
