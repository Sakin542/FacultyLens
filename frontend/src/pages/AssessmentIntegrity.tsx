import React from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, BarChartHorizontal, ScanSearch } from 'lucide-react';
import { ItemAnalysisView } from '@/components/exam/ItemAnalysisView';
import { PlagiarismView } from '@/components/exam/PlagiarismView';
import { cn } from '@/utils/cn';

/** /assessments/:assessmentId/integrity — post-exam item analysis and answer plagiarism review. */
export const AssessmentIntegrity: React.FC = () => {
  const { assessmentId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'plagiarism' ? 'plagiarism' : 'items';

  return (
    <div className="space-y-5 page-enter" data-testid="assessment-integrity-page">
      <Link to={`/assessments/${assessmentId}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />Back to assessment</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">After the exam</h2>
          <p className="text-sm text-sage-500">How each question performed, and whether any answers look copied.</p>
        </div>
        <div role="tablist" aria-label="Sections" className="inline-flex rounded-lg border border-sage-200 bg-white p-0.5">
          {([['items', 'Item analysis', BarChartHorizontal], ['plagiarism', 'Plagiarism check', ScanSearch]] as const).map(([key, label, Icon]) => (
            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setParams(key === 'items' ? {} : { tab: key }, { replace: true })}
              className={cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium', tab === key ? 'bg-sage-700 text-white' : 'text-sage-800 hover:bg-sage-100')}>
              <Icon className="w-3.5 h-3.5" aria-hidden="true" />{label}
            </button>
          ))}
        </div>
      </div>
      {tab === 'items' ? <ItemAnalysisView assessmentId={assessmentId} /> : <PlagiarismView assessmentId={assessmentId} />}
    </div>
  );
};
