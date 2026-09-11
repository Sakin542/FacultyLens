import React from 'react';
import { Card } from '@/components/common/Card';
import { AssessmentVersionBlueprint, DistributionMap, ProfileMap, VersionProfile } from '@/types/assessmentVersion';
import { fmtMarks, humanize } from './versionUtils';

const DIMS: [keyof VersionProfile & string, keyof AssessmentVersionBlueprint & string, string][] = [
  ['difficulty', 'difficulty_distribution', 'Difficulty'], ['cognitive', 'cognitive_distribution', 'Bloom level'], ['question_types', 'question_type_distribution', 'Question type'],
  ['learning_outcomes', 'learning_outcome_distribution', 'Course outcomes'], ['program_outcomes', 'program_outcome_distribution', 'Program outcomes'], ['topics', 'topic_distribution', 'Topics'],
];

/** STEP 38: blueprint snapshot (planned %) next to the version's actual question profile. */
export const VersionBlueprintSummary: React.FC<{ blueprint: AssessmentVersionBlueprint | null; profile: VersionProfile | null }> = ({ blueprint, profile }) => (
  <Card data-testid="version-blueprint-summary" className="p-4 space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h3 className="text-sm font-semibold text-[#111111] dark:text-white">Blueprint snapshot</h3>
      {blueprint ? <span className="text-xs text-[#737373]">Blueprint v{blueprint.blueprint_version} · {humanize(blueprint.blueprint_status)} · {blueprint.question_count} questions / {fmtMarks(blueprint.total_marks)} marks</span> : <span className="text-xs text-[#737373]">No blueprint snapshot attached to this version.</span>}
    </div>
    {(blueprint || profile) && (
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        {DIMS.map(([pk, bk, label]) => {
          const planned = (blueprint?.[bk] as DistributionMap | undefined) ?? {};
          const actual = (profile?.[pk] as ProfileMap | undefined) ?? {};
          const keys = Array.from(new Set([...Object.keys(planned), ...Object.keys(actual)])).sort();
          if (keys.length === 0) return null;
          return (
            <table key={pk} className="w-full text-xs" data-testid={`blueprint-dim-${pk}`}>
              <caption className="text-left text-[11px] uppercase tracking-wide text-[#737373] mb-1">{label}</caption>
              <thead><tr className="text-[#737373]"><th className="text-left font-medium py-0.5">Key</th><th className="text-right font-medium">Planned</th><th className="text-right font-medium">Actual</th></tr></thead>
              <tbody>
                {keys.map((k) => (
                  <tr key={k} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
                    <td className="py-0.5 text-[#262626] dark:text-[#D4D4D4]">{planned[k]?.label ?? actual[k]?.label ?? k}</td>
                    <td className="text-right tabular-nums">{planned[k] ? `${planned[k].percentage}%` : '—'}</td>
                    <td className="text-right tabular-nums">{actual[k] ? `${actual[k].percentage}%` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          );
        })}
      </div>
    )}
    {blueprint && blueprint.sections.length > 0 && (
      <p className="text-xs text-[#737373]">Sections: {blueprint.sections.map((s) => `${s.title} (${s.question_count} × ${fmtMarks(s.marks_per_question)})`).join(' · ')}</p>
    )}
  </Card>
);
