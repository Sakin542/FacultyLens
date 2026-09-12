import React from 'react';
import { Cpu, ShieldCheck } from 'lucide-react';
import type { AiExplanation } from '@/types/explainability';

interface MethodInfoProps {
  method: AiExplanation['method'];
  model: AiExplanation['model'];
  evaluation: AiExplanation['evaluation'];
  version: AiExplanation['version'];
  related: AiExplanation['related'];
}

const methodDescriptions: Record<string, string> = {
  RULE_BASED: 'Deterministic FacultyLens rules — no machine-learning model decides this result.',
  MODEL_BASED: 'A classification model produced this result.',
  EMBEDDING_BASED: 'Sentence embeddings compared with cosine similarity and configured thresholds.',
  HYBRID: 'AI signals combined with FacultyLens deterministic rules.',
  GENERATIVE: 'A generative model drafted this output; the output was validated against structured facts.',
  HUMAN_CONFIRMED: 'Confirmed or authored by faculty.',
};

const Row: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) =>
  value === null || value === undefined || value === '' ? null : (
    <div className="flex justify-between gap-3 py-1 border-b border-sage-200/60 dark:border-[#3A3A3C] last:border-0">
      <dt className="text-sage-500">{label}</dt>
      <dd className="font-mono text-right text-sage-800 dark:text-white break-all">{value}</dd>
    </div>
  );

/** Level 2 — Method (rule/model/embedding/hybrid/generative), model metadata, evaluation status, version context. */
export const MethodInfo: React.FC<MethodInfoProps> = ({ method, model, evaluation, version, related }) => (
  <section aria-labelledby="explanation-method-heading" className="space-y-3 text-xs" data-testid="method-info">
    <div>
      <h4 id="explanation-method-heading" className="text-[11px] font-semibold uppercase tracking-wide text-sage-500 flex items-center gap-1.5">
        <Cpu className="w-3.5 h-3.5" aria-hidden="true" /> Method
      </h4>
      <p className="mt-1 font-semibold text-sage-800 dark:text-white" data-testid="method-type">
        {method.label ?? method.type} <span className="font-mono text-[10px] text-sage-500 ml-1">({method.type})</span>
      </p>
      <p className="text-sage-700 dark:text-sage-300 mt-0.5">{method.description || methodDescriptions[method.type]}</p>
      {method.components.length > 0 && (
        <ul className="mt-1.5 list-disc list-inside text-sage-700 dark:text-sage-300 space-y-0.5" aria-label="Method components">
          {method.components.map((c) => (
            <li key={c}>{c}</li>
          ))}
        </ul>
      )}
    </div>

    <dl className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-3 py-1" data-testid="model-info">
      <Row label="Model" value={model.name} />
      <Row label="Model version" value={model.version} />
      <Row label="Prompt version" value={model.prompt_version} />
      <Row label="Embedding model" value={model.embedding_model} />
      <Row label="Rule version" value={model.rule_version} />
      <Row label="Explanation version" value={version.explanation_version} />
      <Row
        label="Analysis version"
        value={related.analysis_version !== null ? `v${related.analysis_version}${related.is_current === false ? ' (historical)' : related.is_current ? ' (current)' : ''}` : null}
      />
    </dl>

    <div className="flex items-start gap-2 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] px-3 py-2" data-testid="evaluation-status">
      <ShieldCheck className="w-3.5 h-3.5 mt-0.5 shrink-0 text-sage-500" aria-hidden="true" />
      <div>
        <p className="font-semibold text-sage-800 dark:text-white">
          Evaluation status: <span className="uppercase">{evaluation.label}</span>
        </p>
        {evaluation.headline_metric && evaluation.headline_value !== null && evaluation.headline_value !== undefined && (
          <p className="text-sage-700 dark:text-sage-300 font-mono">
            {evaluation.headline_metric} = {evaluation.headline_value.toFixed(3)}
            {evaluation.example_count ? ` · n=${evaluation.example_count}` : ''}
          </p>
        )}
        {evaluation.status === 'NOT_EVALUATED' && <p className="text-sage-500">No measured evaluation exists for this component yet.</p>}
      </div>
    </div>
  </section>
);
