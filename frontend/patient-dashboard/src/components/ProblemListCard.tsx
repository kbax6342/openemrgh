import type { UseQueryResult } from '@tanstack/react-query';
import type { DashboardPayload, ProblemSummary } from '../types/dashboard';
import { ClinicalCard } from './ClinicalCard';

interface ProblemListCardProps {
  query: UseQueryResult<DashboardPayload<ProblemSummary[]>, Error>;
}

export function ProblemListCard({ query }: ProblemListCardProps) {
  const items = query.data?.data ?? [];

  return (
    <ClinicalCard
      title="Problem List"
      subtitle="FHIR Condition"
      source={query.data?.source}
      notice={query.data?.notice}
      isLoading={query.isLoading}
      isError={query.isError}
      errorMessage={query.error?.message}
      isEmpty={!items.length}
      emptyMessage="No active problems were returned for this patient."
    >
      <ul className="detail-list">
        {items.map((item) => (
          <li key={item.id}>
            <div className="detail-list__title">{item.name}</div>
            <div className="detail-list__meta">
              <span>Clinical status: {item.clinicalStatus}</span>
              <span>Verification: {item.verificationStatus}</span>
              <span>Recorded: {item.recordedDate}</span>
            </div>
          </li>
        ))}
      </ul>
    </ClinicalCard>
  );
}
