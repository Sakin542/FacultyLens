import React from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { VersionAnalysis } from '@/types/assessmentVersion';
import { fmtDate, fmtMarks, statusVariant } from './versionUtils';

/** STEP 38: STEP 13 metrics recorded for this exact version; STALE analyses are never shown as current. */
export const VersionAnalysisSummary: React.FC<{ analysis: VersionAnalysis | null; assessmentId: number | string }> = ({ analysis, assessmentId }) => {
  const latest = analysis?.latest ?? null;
  return (
    <Card data-testid="version-analysis-summary" className="p-4 space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-[#111111] dark:text-white">Analysis for this version</h3>
        <Badge variant={statusVariant(analysis?.status ?? 'NONE')} dot>{analysis?.status === 'NONE' || !analysis ? 'No analysis' : analysis.status === 'STALE' ? 'Stale' : 'Current'}</Badge>
      </div>
      {!latest ? (
        <p className="text-sm text-[#737373]">No completed analysis is recorded for this version. <Link to={`/assessments/${assessmentId}/analysis`} className="underline underline-offset-2">Run an analysis</Link> to attach STEP 13 metrics to the current state.</p>
      ) : (
        <>
          {analysis?.status === 'STALE' && <p className="text-xs text-[#92400E]">The version content changed after this analysis ran (analysis v{latest.analysis_version}). Re-run the analysis to refresh; historical results are kept.</p>}
          <dl className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2 text-xs">
            {([
              ['Quality', latest.overall_score], ['Topic coverage', latest.topic_coverage_score], ['LO alignment', latest.learning_outcome_alignment_score], ['Difficulty balance', latest.difficulty_balance_score],
              ['Cognitive diversity', latest.cognitive_level_balance_score], ['Similarity', latest.similarity_score], ['Recommendations', latest.recommendations_count],
            ] as const).map(([l, v]) => (
              <div key={l} className="rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] px-2 py-1.5"><dt className="text-[#737373]">{l}</dt><dd className="font-semibold text-[#111111] dark:text-white tabular-nums">{fmtMarks(v)}</dd></div>
            ))}
          </dl>
          <p className="text-[11px] text-[#737373]">Analysis v{latest.analysis_version} · {fmtDate(latest.analyzed_at)} · {analysis?.reports.length} run{analysis?.reports.length === 1 ? '' : 's'} recorded for this version</p>
        </>
      )}
    </Card>
  );
};
