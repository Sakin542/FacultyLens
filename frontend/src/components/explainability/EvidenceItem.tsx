import React from 'react';
import { FileText, Hash, Quote, ListChecks, AlertTriangle, BookOpen, Link2 } from 'lucide-react';
import type { ExplanationEvidence } from '@/types/explainability';
import { SourceCitation } from './SourceCitation';

interface EvidenceItemProps {
  item: ExplanationEvidence;
  onOpenSource?: (item: ExplanationEvidence) => void;
}

const iconFor = (type: string) => {
  switch (type) {
    case 'question_text':
    case 'analysis_note':
    case 'generation_note':
      return Quote;
    case 'score':
    case 'metric':
      return Hash;
    case 'document_chunk':
    case 'source':
      return FileText;
    case 'criterion':
    case 'constraint':
    case 'dimension':
      return ListChecks;
    case 'warning':
    case 'gap':
      return AlertTriangle;
    case 'learning_outcome':
    case 'co_po_link':
      return BookOpen;
    default:
      return Link2;
  }
};

const formatScore = (score: number | null | undefined) => {
  if (score === null || score === undefined) return null;
  return Math.abs(score) <= 1 ? `${score.toFixed(2)} / 1.00` : `${score}`;
};

const renderMetaValue = (value: unknown): React.ReactNode => {
  if (value === null || value === undefined || value === '') return null;
  if (Array.isArray(value)) {
    if (value.length === 0) return null;
    return (
      <ul className="list-disc list-inside space-y-0.5">
        {value.map((v, i) => (
          <li key={i}>{typeof v === 'string' ? v : JSON.stringify(v)}</li>
        ))}
      </ul>
    );
  }
  if (typeof value === 'boolean') return value ? 'yes' : 'no';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};

/** One piece of evidence: label, excerpt/text, score, optional source citation and structured meta. */
export const EvidenceItem: React.FC<EvidenceItemProps> = ({ item, onOpenSource }) => {
  const Icon = iconFor(item.type);
  const score = formatScore(item.score);
  const isSource = item.type === 'document_chunk' || (item.document_id !== undefined && item.document_id !== null);
  const status = typeof item.meta?.status === 'string' ? (item.meta.status as string) : null;

  return (
    <li className="flex gap-2.5 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] p-2.5 text-xs" data-testid="evidence-item" data-evidence-type={item.type}>
      <Icon className="w-3.5 h-3.5 mt-0.5 shrink-0 text-sage-500" aria-hidden="true" />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="font-semibold text-sage-800 dark:text-white">{item.label}</span>
          <span className="flex items-center gap-2 font-mono text-[11px] text-sage-500">
            {status && <span className="uppercase font-semibold">{status}</span>}
            {score && <span title="Score as reported by the underlying analysis">{score}</span>}
          </span>
        </div>
        {isSource ? (
          <SourceCitation item={item} onOpen={onOpenSource} />
        ) : (
          item.text && <p className="text-sage-700 dark:text-sage-300 leading-relaxed break-words">{item.text}</p>
        )}
        {item.meta &&
          Object.entries(item.meta)
            .filter(([k]) => !['status', 'relevance'].includes(k))
            .map(([k, v]) => {
              const rendered = renderMetaValue(v);
              return rendered ? (
                <div key={k} className="text-[11px] text-sage-500">
                  <span className="font-medium text-sage-700 dark:text-sage-200">{k.replace(/_/g, ' ')}: </span>
                  {rendered}
                </div>
              ) : null;
            })}
      </div>
    </li>
  );
};
