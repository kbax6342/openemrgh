import type { ReactNode } from 'react';
import type { DashboardSource } from '../types/dashboard';

interface ClinicalCardProps {
  title: string;
  subtitle: string;
  source?: DashboardSource;
  notice?: string;
  isLoading: boolean;
  isError: boolean;
  errorMessage?: string;
  isEmpty: boolean;
  emptyMessage: string;
  children: ReactNode;
}

export function ClinicalCard(props: ClinicalCardProps) {
  return (
    <section className="clinical-card">
      <header className="clinical-card__header">
        <div>
          <h2>{props.title}</h2>
          <p>{props.subtitle}</p>
        </div>
        {props.source === 'demo-fallback' ? <span className="status-pill status-pill--fallback">Demo fallback</span> : null}
      </header>

      {props.notice ? <p className="clinical-card__notice">{props.notice}</p> : null}

      {props.isLoading ? <div className="card-state">Loading…</div> : null}
      {props.isError ? <div className="card-state card-state--error">{props.errorMessage || 'Unable to load this section.'}</div> : null}
      {!props.isLoading && !props.isError && props.isEmpty ? (
        <div className="card-state card-state--empty">{props.emptyMessage}</div>
      ) : null}
      {!props.isLoading && !props.isError && !props.isEmpty ? <div className="clinical-card__body">{props.children}</div> : null}
    </section>
  );
}
