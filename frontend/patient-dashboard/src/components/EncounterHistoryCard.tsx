import type { UseQueryResult } from '@tanstack/react-query';
import type { DashboardPayload, EncounterSummary } from '../types/dashboard';
import { ClinicalCard } from './ClinicalCard';

interface EncounterHistoryCardProps {
  query: UseQueryResult<DashboardPayload<EncounterSummary[]>, Error>;
}

export function EncounterHistoryCard({ query }: EncounterHistoryCardProps) {
  const items = query.data?.data ?? [];

  return (
    <ClinicalCard
      title="Encounter History"
      subtitle="FHIR Encounter"
      source={query.data?.source}
      notice={query.data?.notice}
      isLoading={query.isLoading}
      isError={query.isError}
      errorMessage={query.error?.message}
      isEmpty={!items.length}
      emptyMessage="No encounters were returned for this patient."
    >
      <ul className="detail-list">
        {items.map((item) => (
          <li key={item.id}>
            <div className="detail-list__title">{item.type}</div>
            <div className="detail-list__meta">
              <span>Class: {item.className}</span>
              <span>Status: {item.status}</span>
              <span>Start: {item.start}</span>
              <span>End: {item.end}</span>
            </div>
          </li>
        ))}
      </ul>
    </ClinicalCard>
  );
}
