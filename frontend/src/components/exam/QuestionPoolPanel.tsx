import React, { useEffect, useState } from 'react';
import { Plus, Search } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { examService } from '@/services/examService';
import type { PoolCandidate } from '@/types/exam';
import { BLOOM_LEVELS, DIFFICULTY_LEVELS } from '@/types/exam';

interface Props {
  assessmentId: number;
  usedTexts: Set<string>;
  onAdd: (key: string) => Promise<void>;
}

/** Browse the course question pool (bank, earlier assessments, approved AI drafts) and add questions to the paper. */
export const QuestionPoolPanel: React.FC<Props> = ({ assessmentId, usedTexts, onAdd }) => {
  const [search, setSearch] = useState('');
  const [bloom, setBloom] = useState('');
  const [difficulty, setDifficulty] = useState('');
  const [source, setSource] = useState('');
  const [items, setItems] = useState<PoolCandidate[]>([]);
  const [loading, setLoading] = useState(true);
  const [adding, setAdding] = useState<string | null>(null);

  useEffect(() => {
    const t = window.setTimeout(() => {
      setLoading(true);
      examService.pool(assessmentId, { search, cognitive_level: bloom, difficulty_level: difficulty, source })
        .then((r) => setItems(r.data)).catch(() => setItems([])).finally(() => setLoading(false));
    }, 250);
    return () => window.clearTimeout(t);
  }, [assessmentId, search, bloom, difficulty, source]);

  const select = 'rounded-lg border border-sage-200 bg-white px-2 py-1.5 text-xs text-sage-800';

  return (
    <section className="rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Question pool" data-testid="question-pool">
      <header className="space-y-2 border-b border-sage-200 p-3">
        <h3 className="text-sm font-semibold text-sage-800">Question pool</h3>
        <label className="relative block">
          <span className="sr-only">Search the pool</span>
          <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-sage-400" aria-hidden="true" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search questions" className="w-full rounded-lg border border-sage-200 py-1.5 pl-8 pr-2 text-xs focus:outline-none focus:ring-2 focus:ring-sage-600" />
        </label>
        <div className="grid grid-cols-3 gap-1.5">
          <select aria-label="Bloom level" value={bloom} onChange={(e) => setBloom(e.target.value)} className={select}><option value="">Any Bloom</option>{BLOOM_LEVELS.map((l) => <option key={l}>{l}</option>)}</select>
          <select aria-label="Difficulty" value={difficulty} onChange={(e) => setDifficulty(e.target.value)} className={select}><option value="">Any difficulty</option>{DIFFICULTY_LEVELS.map((l) => <option key={l} value={l}>{l}</option>)}</select>
          <select aria-label="Source" value={source} onChange={(e) => setSource(e.target.value)} className={select}><option value="">All sources</option><option value="bank">Question bank</option><option value="question">Past assessments</option><option value="generated">AI drafts</option></select>
        </div>
      </header>
      <ul className="max-h-[520px] divide-y divide-sage-100 overflow-y-auto">
        {loading && <li className="p-3 text-xs text-sage-500">Loading…</li>}
        {!loading && items.length === 0 && <li className="p-3 text-xs text-sage-500">No questions match. Add questions to the course question bank or generate drafts.</li>}
        {!loading && items.map((c) => {
          const used = usedTexts.has(c.question_text);
          return (
            <li key={c.key} className="p-3">
              <p className="text-xs text-sage-800 line-clamp-3">{c.question_text}</p>
              <div className="mt-1.5 flex flex-wrap items-center gap-1">
                {c.cognitive_level && <Badge variant="neutral">{c.cognitive_level}</Badge>}
                {c.difficulty_level && <Badge variant="outline" className="capitalize">{c.difficulty_level}</Badge>}
                <span className="text-[11px] text-sage-500">{c.marks} marks · {c.origin}</span>
                <button type="button" disabled={used || adding === c.key}
                  onClick={async () => { setAdding(c.key); try { await onAdd(c.key); } finally { setAdding(null); } }}
                  className="ml-auto inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-0.5 text-xs text-sage-700 hover:bg-sage-100 disabled:opacity-40"
                  aria-label={used ? 'Already in the paper' : 'Add to paper'}>
                  <Plus className="w-3 h-3" aria-hidden="true" />{used ? 'In paper' : 'Add'}
                </button>
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
};
