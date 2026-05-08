import type { UseQueryResult } from '@tanstack/react-query';
import type { CareTeamSummary, DashboardPayload } from '../types/dashboard';
import { ClinicalCard } from './ClinicalCard';

interface CareTeamCardProps {
  query: UseQueryResult<DashboardPayload<CareTeamSummary[]>, Error>;
}

export function CareTeamCard({ query }: CareTeamCardProps) {
  const items = query.data?.data ?? [];

  return (
    <ClinicalCard
      title="Care Team"
      subtitle="FHIR CareTeam"
      source={query.data?.source}
      notice={query.data?.notice}
      isLoading={query.isLoading}
      isError={query.isError}
      errorMessage={query.error?.message}
      isEmpty={!items.length}
      emptyMessage="No care team entries were returned for this patient."
    >
      <ul className="detail-list">
        {items.map((item) => (
          <li key={item.id}>
            <div className="detail-list__title">{item.member}</div>
            <div className="detail-list__meta">
              <span>Role: {item.role}</span>
              <span>Period: {item.period}</span>
              <span>Status: {item.status}</span>
            </div>
          </li>
        ))}
      </ul>
    </ClinicalCard>
  );
}
