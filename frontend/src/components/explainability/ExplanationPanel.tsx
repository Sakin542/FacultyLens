import React, { useCallback, useEffect, useState } from 'react';
import { ChevronDown, ChevronUp, Loader2, RefreshCw, Sparkles, X } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { ApiError } from '@/services/api';
import { explainabilityService } from '@/services/explainabilityService';
import type { AiExplanation, AiResultType, ExplanationDetail, ExplanationEvidence } from '@/types/explainability';
import { ExplanationSummary } from './ExplanationSummary';
import { EvidenceList } from './EvidenceList';
import { MethodInfo } from './MethodInfo';
import { ConfidenceIndicator } from './ConfidenceIndicator';
import { LimitationsPanel } from './LimitationsPanel';
import { ReviewActions } from './ReviewActions';
import { OverrideDialog } from './OverrideDialog';

export interface ExplanationPanelProps {
  type: AiResultType;
  id: number | string;
  /** Optional heading (e.g. "Bloom level"). */
  title?: string;
  onClose?: () => void;
  /** Called after a review/override succeeded so parents can refresh their data. */
  onChanged?: (explanation: AiExplanation) => void;
  onOpenSource?: (item: ExplanationEvidence) => void;
  className?: string;
}

const DetailValue: React.FC<{ value: unknown }> = ({ value }) => {
  if (value === null || value === undefined || value === '') return <span className="text-sage-500">—</span>;
  if (Array.isArray(value)) {
    if (value.length === 0) return <span className="text-sage-500">—</span>;
    if (typeof value[0] === 'object' && value[0] !== null) {
      const cols = Object.keys(value[0] as Record<string, unknown>);
      return (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-[11px] border-collapse">
            <thead>
              <tr className="text-sage-500 border-b border-sage-200 dark:border-[#3A3A3C]">
                {cols.map((c) => (
                  <th key={c} className="py-1 pr-3 font-semibold capitalize">
                    {c.replace(/_/g, ' ')}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {(value as Record<string, unknown>[]).map((row, i) => (
                <tr key={i} className="border-b border-sage-200/60 dark:border-[#3A3A3C] last:border-0">
                  {cols.map((c) => (
                    <td key={c} className="py-1 pr-3 font-mono text-sage-800 dark:text-white">
                      {row[c] === null || row[c] === undefined ? '—' : String(row[c])}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      );
    }
    return (
      <ul className="list-disc list-inside">
        {value.map((v, i) => (
          <li key={i}>{String(v)}</li>
        ))}
      </ul>
    );
  }
  if (typeof value === 'object') {
    return (
      <dl className="grid grid-cols-[auto,1fr] gap-x-3 gap-y-0.5">
        {Object.entries(value as Record<string, unknown>).map(([k, v]) => (
          <React.Fragment key={k}>
            <dt className="text-sage-500">{k}</dt>
            <dd className="font-mono text-sage-800 dark:text-white">{v === null || v === undefined ? '—' : String(v)}</dd>
          </React.Fragment>
        ))}
      </dl>
    );
  }
  return <span className="font-mono text-sage-800 dark:text-white">{String(value)}</span>;
};

const Disclosure: React.FC<{ id: string; title: string; open: boolean; onToggle: () => void; children: React.ReactNode }> = ({ id, title, open, onToggle, children }) => (
  <div className="border-t border-sage-200 dark:border-[#2C2C2E] pt-2">
    <button
      type="button"
      onClick={onToggle}
      aria-expanded={open}
      aria-controls={id}
      className="flex w-full items-center justify-between py-1 text-xs font-semibold text-sage-800 dark:text-white hover:text-sage-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700 rounded"
    >
      <span>{title}</span>
      {open ? <ChevronUp className="w-4 h-4" aria-hidden="true" /> : <ChevronDown className="w-4 h-4" aria-hidden="true" />}
    </button>
    {open && (
      <div id={id} className="mt-2 space-y-3">
        {children}
      </div>
    )}
  </div>
);

/**
 * STEP 45 explanation panel with progressive disclosure:
 *   Level 1 — result, why, key evidence (default)
 *   Level 2 — method, model, thresholds, confidence, evaluation status
 *   Level 3 — full evidence, detailed calculation
 * Loaded lazily on mount (only rendered when faculty asks "Why?").
 */
export const ExplanationPanel: React.FC<ExplanationPanelProps> = ({ type, id, title, onClose, onChanged, onOpenSource, className }) => {
  const [explanation, setExplanation] = useState<AiExplanation | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [level2, setLevel2] = useState(false);
  const [level3, setLevel3] = useState(false);
  const [overrideOpen, setOverrideOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(
    async (force = false) => {
      setLoading(true);
      setError(null);
      try {
        const data = await explainabilityService.getExplanation(type, id, { force });
        setExplanation(data);
      } catch (e) {
        setError(e instanceof ApiError ? e.message : 'The explanation could not be loaded.');
      } finally {
        setLoading(false);
      }
    },
    [type, id]
  );

  useEffect(() => {
    void load();
  }, [load]);

  const openLevel3 = () => {
    const next = !level3;
    setLevel3(next);
    if (next) explainabilityService.recordEvent(type, id, 'AI_EVIDENCE_VIEWED', { level: 3 });
  };

  const handleReview = async (action: 'ACCEPTED' | 'REJECTED' | 'REVIEWED', comment?: string) => {
    setSubmitting(true);
    setNotice(null);
    try {
      const res = await explainabilityService.review(type, id, action, comment);
      setNotice(res.message ?? 'Your decision has been recorded.');
      const fresh = await explainabilityService.getExplanation(type, id, { force: true });
      setExplanation(fresh);
      onChanged?.(fresh);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'The decision could not be recorded.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleOverride = async (value: Record<string, unknown>, reason: string, comment: string) => {
    setSubmitting(true);
    setNotice(null);
    try {
      const res = await explainabilityService.override(type, id, value, reason, comment);
      setOverrideOpen(false);
      setNotice(res.message ?? 'Your override has been applied.');
      const fresh = await explainabilityService.getExplanation(type, id, { force: true });
      setExplanation(fresh);
      onChanged?.(fresh);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'The override could not be applied.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenSource = (item: ExplanationEvidence) => {
    explainabilityService.recordEvent(type, id, 'AI_SOURCE_OPENED', { document_id: item.document_id ?? undefined, chunk_id: item.chunk_id ?? undefined });
    onOpenSource?.(item);
  };

  const heading = title ?? 'AI explanation';

  return (
    <Card padding="sm" className={`space-y-3 dark:bg-[#1C1C1E] dark:border-[#2C2C2E] ${className ?? ''}`} data-testid="explanation-panel" aria-busy={loading}>
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          <Sparkles className="w-4 h-4 text-sage-500" aria-hidden="true" />
          <h2 className="text-sm font-bold text-sage-800 dark:text-white">{heading}</h2>
        </div>
        <div className="flex items-center gap-1">
          <button type="button" onClick={() => load(true)} aria-label="Reload explanation" className="p-1 rounded text-sage-500 hover:text-sage-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-sage-700">
            <RefreshCw className="w-3.5 h-3.5" aria-hidden="true" />
          </button>
          {onClose && (
            <button type="button" onClick={onClose} aria-label="Close explanation" className="p-1 rounded text-sage-500 hover:text-sage-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-sage-700">
              <X className="w-4 h-4" aria-hidden="true" />
            </button>
          )}
        </div>
      </div>

      {loading && (
        <div className="flex items-center gap-2 text-xs text-sage-500 py-4" role="status" data-testid="explanation-loading">
          <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" /> Loading explanation…
        </div>
      )}

      {!loading && error && (
        <div role="alert" className="text-xs rounded-lg border border-[#FECACA] bg-[#FEF2F2] text-[#991B1B] px-3 py-2 flex items-center justify-between gap-2" data-testid="explanation-error">
          <span>{error}</span>
          <Button type="button" variant="outline" size="sm" onClick={() => load(true)}>
            Retry
          </Button>
        </div>
      )}

      {!loading && explanation && (
        <>
          {notice && (
            <p role="status" className="text-xs rounded-lg border border-[#BBF7D0] bg-[#F0FDF4] text-[#166534] px-3 py-2" data-testid="explanation-notice">
              {notice}
            </p>
          )}

          <ExplanationSummary explanation={explanation} />

          <section aria-labelledby="explanation-evidence-heading">
            <h4 id="explanation-evidence-heading" className="text-[11px] font-semibold uppercase tracking-wide text-sage-500 mb-1.5">
              Evidence
            </h4>
            <EvidenceList evidence={explanation.evidence} onOpenSource={handleOpenSource} limit={level3 ? undefined : 4} />
          </section>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="text-xs">
              <h4 className="text-[11px] font-semibold uppercase tracking-wide text-sage-500">Method</h4>
              <p className="mt-1 font-semibold text-sage-800 dark:text-white">{explanation.method.label ?? explanation.method.type}</p>
            </div>
            <ConfidenceIndicator confidence={explanation.confidence} />
          </div>

          <LimitationsPanel limitations={explanation.limitations} disclaimer={explanation.disclaimer} />

          <Disclosure id={`explanation-technical-${type}-${id}`} title="Technical details" open={level2} onToggle={() => setLevel2(!level2)}>
            <MethodInfo method={explanation.method} model={explanation.model} evaluation={explanation.evaluation} version={explanation.version} related={explanation.related} />
            {explanation.explanation.details.length > 0 && (
              <dl className="space-y-2 text-xs" data-testid="explanation-details">
                {explanation.explanation.details.map((d: ExplanationDetail, i) => (
                  <div key={`${d.label}-${i}`}>
                    <dt className="font-semibold text-sage-800 dark:text-white">{d.label}</dt>
                    <dd className="text-sage-700 dark:text-sage-300 mt-0.5">
                      <DetailValue value={d.value} />
                    </dd>
                  </div>
                ))}
              </dl>
            )}
          </Disclosure>

          <Disclosure id={`explanation-evidence-full-${type}-${id}`} title={`Detailed evidence (${explanation.evidence.length})`} open={level3} onToggle={openLevel3}>
            <EvidenceList evidence={explanation.evidence} onOpenSource={handleOpenSource} />
            {explanation.related.links.filter((l) => l.id !== null).length > 0 && (
              <ul className="flex flex-wrap gap-2 text-[11px]" aria-label="Related data">
                {explanation.related.links
                  .filter((l) => l.id !== null)
                  .map((l) => (
                    <li key={`${l.type}-${l.id}`} className="rounded-full border border-sage-200 dark:border-[#3A3A3C] px-2 py-0.5 text-sage-700 dark:text-sage-300">
                      {l.label}: <span className="font-mono">{l.type} #{String(l.id)}</span>
                    </li>
                  ))}
              </ul>
            )}
          </Disclosure>

          <div className="border-t border-sage-200 dark:border-[#2C2C2E] pt-3">
            <ReviewActions explanation={explanation} isSubmitting={submitting} onReview={handleReview} onOverride={() => setOverrideOpen(true)} />
          </div>

          <OverrideDialog explanation={explanation} isOpen={overrideOpen} isSubmitting={submitting} onClose={() => setOverrideOpen(false)} onSubmit={handleOverride} />
        </>
      )}
    </Card>
  );
};
