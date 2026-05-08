import type { UseQueryResult } from '@tanstack/react-query';
import type { AllergySummary, DashboardPayload } from '../types/dashboard';
import { ClinicalCard } from './ClinicalCard';

interface AllergiesCardProps {
  query: UseQueryResult<DashboardPayload<AllergySummary[]>, Error>;
}

export function AllergiesCard({ query }: AllergiesCardProps) {
  const items = query.data?.data ?? [];

  return (
    <ClinicalCard
      title="Allergies"
      subtitle="FHIR AllergyIntolerance"
      source={query.data?.source}
      notice={query.data?.notice}
      isLoading={query.isLoading}
      isError={query.isError}
      errorMessage={query.error?.message}
      isEmpty={!items.length}
      emptyMessage="No allergies were returned for this patient."
    >
      <ul className="detail-list">
        {items.map((item) => (
          <li key={item.id}>
            <div className="detail-list__title">{item.substance}</div>
            <div className="detail-list__meta">
              <span>Status: {item.clinicalStatus}</span>
              <span>Verification: {item.verificationStatus}</span>
              <span>Criticality: {item.criticality}</span>
            </div>
            <p>{item.reaction}</p>
          </li>
        ))}
      </ul>
    </ClinicalCard>
  );
}
