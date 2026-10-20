import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { FileText, Wand2, ArrowLeft, Layers, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { examService, BuildPayload } from '@/services/examService';
import type { ExamPaperSummary } from '@/types/exam';
import { BLOOM_LEVELS, DIFFICULTY_LEVELS } from '@/types/exam';
import { cn } from '@/utils/cn';

const DEFAULT_BLOOM: Record<string, number> = { Remember: 10, Understand: 20, Apply: 30, Analyze: 25, Evaluate: 10, Create: 5 };
const DEFAULT_DIFFICULTY: Record<string, number> = { easy: 30, medium: 50, hard: 20 };

const inputClass = 'w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-600';

/** /assessments/:assessmentId/exam-papers — existing papers and the "build a paper" form. */
export const ExamPapers: React.FC = () => {
  const { assessmentId = '' } = useParams();
  const navigate = useNavigate();
  const [papers, setPapers] = useState<ExamPaperSummary[] | null>(null);
  const [poolSize, setPoolSize] = useState<number | null>(null);
  const [mode, setMode] = useState<BuildPayload['mode']>('blueprint');
  const [title, setTitle] = useState('');
  const [duration, setDuration] = useState('');
  const [count, setCount] = useState('10');
  const [totalMarks, setTotalMarks] = useState('50');
  const [bloom, setBloom] = useState<Record<string, number>>(DEFAULT_BLOOM);
  const [difficulty, setDifficulty] = useState<Record<string, number>>(DEFAULT_DIFFICULTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    examService.papers(assessmentId).then(setPapers).catch((e) => setError(e instanceof Error ? e.message : 'Could not load papers.'));
    examService.pool(assessmentId).then((r) => setPoolSize(r.meta.total)).catch(() => setPoolSize(null));
  }, [assessmentId]);

  const bloomTotal = Object.values(bloom).reduce((a, b) => a + b, 0);
  const diffTotal = Object.values(difficulty).reduce((a, b) => a + b, 0);

  const build = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    const payload: BuildPayload = { mode, title: title.trim() || undefined, duration_minutes: duration ? Number(duration) : undefined };
    if (mode === 'targets') {
      if (bloomTotal <= 0) { setError('Give at least one Bloom level a share.'); return; }
      payload.targets = { question_count: Number(count), total_marks: Number(totalMarks), cognitive_mix: bloom, difficulty_mix: difficulty };
    }
    setBusy(true);
    try {
      const res = await examService.build(assessmentId, payload);
      navigate(`/exam-papers/${res.data.id}`, { state: { notice: res.message } });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The paper could not be built.');
      setBusy(false);
    }
  };

  return (
    <div className="space-y-5 page-enter" data-testid="exam-papers-page">
      <Link to={`/assessments/${assessmentId}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />Back to assessment</Link>

      <div className="flex items-start gap-3">
        <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><FileText className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
        <div>
          <h2 className="text-xl font-semibold text-sage-800 tracking-tight">Exam paper builder</h2>
          <p className="text-sm text-sage-500">Pick questions from the course pool to hit your blueprint's marks, Bloom mix and CO coverage, then export a print-ready paper with an answer key.{poolSize !== null && ` ${poolSize} question(s) available in the pool.`}</p>
        </div>
      </div>

      <div className="grid gap-5 lg:grid-cols-[1fr,1.1fr]">
        <section className="rounded-xl border border-sage-200 bg-white p-5 shadow-subtle" aria-label="Build a new paper">
          <h3 className="text-sm font-semibold text-sage-800">Build a new paper</h3>
          <form className="mt-4 space-y-4" onSubmit={build} data-testid="build-paper-form">
            <div role="radiogroup" aria-label="Build from" className="grid grid-cols-3 gap-2">
              {([['blueprint', 'Blueprint'], ['targets', 'Custom targets'], ['empty', 'Empty paper']] as const).map(([value, label]) => (
                <button key={value} type="button" role="radio" aria-checked={mode === value} onClick={() => setMode(value)} data-testid={`build-mode-${value}`}
                  className={cn('rounded-lg border px-3 py-2 text-sm font-medium', mode === value ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 text-sage-700 hover:bg-sage-100')}>{label}</button>
              ))}
            </div>
            <p className="text-xs text-sage-500">
              {mode === 'blueprint' && 'Fills every blueprint item (topic, CO, Bloom level, difficulty, marks) with the best-matching questions. Slots the pool cannot fill are reported, never padded.'}
              {mode === 'targets' && 'Set a question count, total marks and the Bloom and difficulty mix you want.'}
              {mode === 'empty' && 'Start blank and add questions by hand or from the pool.'}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
              <label className="text-xs font-medium text-sage-700">Paper title<input className={cn(inputClass, 'mt-1')} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Defaults to the assessment title" /></label>
              <label className="text-xs font-medium text-sage-700">Duration (minutes)<input type="number" min={5} max={600} className={cn(inputClass, 'mt-1')} value={duration} onChange={(e) => setDuration(e.target.value)} placeholder="From blueprint/assessment" /></label>
            </div>

            {mode === 'targets' && (
              <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                  <label className="text-xs font-medium text-sage-700">Number of questions<input type="number" min={1} max={80} required className={cn(inputClass, 'mt-1')} value={count} onChange={(e) => setCount(e.target.value)} data-testid="target-count" /></label>
                  <label className="text-xs font-medium text-sage-700">Total marks<input type="number" min={1} max={1000} required className={cn(inputClass, 'mt-1')} value={totalMarks} onChange={(e) => setTotalMarks(e.target.value)} /></label>
                </div>
                <fieldset>
                  <legend className="text-xs font-medium text-sage-700">Bloom mix (% of questions) <span className={cn('ml-1', bloomTotal === 100 ? 'text-sage-500' : 'text-[#92400E]')}>total {bloomTotal}%</span></legend>
                  <div className="mt-1 grid grid-cols-3 gap-2 sm:grid-cols-6">
                    {BLOOM_LEVELS.map((l) => (
                      <label key={l} className="text-[11px] text-sage-600">{l}
                        <input type="number" min={0} max={100} className={cn(inputClass, 'mt-0.5 px-2 py-1')} value={bloom[l] ?? 0} onChange={(e) => setBloom({ ...bloom, [l]: Math.max(0, Number(e.target.value)) })} />
                      </label>
                    ))}
                  </div>
                </fieldset>
                <fieldset>
                  <legend className="text-xs font-medium text-sage-700">Difficulty mix (%) <span className={cn('ml-1', diffTotal === 100 ? 'text-sage-500' : 'text-[#92400E]')}>total {diffTotal}%</span></legend>
                  <div className="mt-1 grid grid-cols-3 gap-2">
                    {DIFFICULTY_LEVELS.map((l) => (
                      <label key={l} className="text-[11px] capitalize text-sage-600">{l}
                        <input type="number" min={0} max={100} className={cn(inputClass, 'mt-0.5 px-2 py-1')} value={difficulty[l] ?? 0} onChange={(e) => setDifficulty({ ...difficulty, [l]: Math.max(0, Number(e.target.value)) })} />
                      </label>
                    ))}
                  </div>
                </fieldset>
              </div>
            )}

            {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-xs text-[#991B1B]">{error}</p>}
            <Button type="submit" isLoading={busy} disabled={busy} leftIcon={<Wand2 className="w-4 h-4" />} data-testid="build-paper-submit">{mode === 'empty' ? 'Create paper' : 'Build paper'}</Button>
          </form>
        </section>

        <section className="rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Papers">
          <header className="border-b border-sage-200 px-5 py-3"><h3 className="text-sm font-semibold text-sage-800">Papers for this assessment</h3></header>
          {papers === null ? <p className="p-5 text-sm text-sage-500">Loading…</p> : papers.length === 0 ? (
            <p className="p-5 text-sm text-sage-500">No papers yet. Build the first one on the left.</p>
          ) : (
            <ul className="divide-y divide-sage-100" data-testid="paper-list">
              {papers.map((p) => (
                <li key={p.id} className="px-5 py-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link to={`/exam-papers/${p.id}`} className="font-medium text-sage-800 hover:underline">{p.title}</Link>
                    <span className="flex items-center gap-1.5">
                      <Badge variant={p.status === 'final' ? 'Good' : 'neutral'}>{p.status === 'final' ? 'Final' : 'Draft'}</Badge>
                      {p.applied_at && <Badge variant="outline">Added to assessment</Badge>}
                    </span>
                  </div>
                  <p className="mt-0.5 text-xs text-sage-500">{p.items_count} question(s) · {p.total_marks} marks · built from {p.build_mode}{p.creator ? ` by ${p.creator.name}` : ''}</p>
                  <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="inline-flex items-center gap-1 text-sage-500"><Layers className="w-3.5 h-3.5" aria-hidden="true" />Sets:</span>
                    <Link to={`/exam-papers/${p.id}`} className="rounded border border-sage-200 px-1.5 py-0.5 text-sage-700 hover:bg-sage-100">A</Link>
                    {p.sets.map((s) => <Link key={s.id} to={`/exam-papers/${s.id}`} className="rounded border border-sage-200 px-1.5 py-0.5 text-sage-700 hover:bg-sage-100">{s.set_label}</Link>)}
                  </div>
                  {p.shortfalls.length > 0 && (
                    <p className="mt-1.5 inline-flex items-center gap-1 text-xs text-[#92400E]"><AlertTriangle className="w-3.5 h-3.5" aria-hidden="true" />{p.shortfalls.reduce((n, s) => n + s.missing, 0)} slot(s) unfilled</p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  );
};
