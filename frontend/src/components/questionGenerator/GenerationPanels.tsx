import React, { useState } from 'react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { FEEDBACK_REASONS, FeedbackInput, FeedbackReason, GeneratedQuestion, GenerationRequest, QUESTION_TYPE_LABELS, UpdateGeneratedQuestionInput } from '@/types/questionGeneration';
import { GeneratedQuestionCard } from './GeneratedQuestionCard';
import { GenerationEmptyState } from './GenerationStates';
import { cn } from '@/utils/cn';

/** STEP 33: list / summary / history / modals. */

const fmt = (v: string | null | undefined) => (v ? v.replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase()) : '—');

export const GenerationRequestSummary: React.FC<{ request: GenerationRequest }> = ({ request: r }) => {
  const statusVariant = r.generation_status === 'COMPLETED' ? 'Good' : r.generation_status === 'FAILED' ? 'Critical' : 'Pending';
  const s = r.set_summary;
  const dist = (m: Record<string, number> | undefined) => m ? Object.entries(m).map(([k, v]) => `${fmt(k)} ${v}`).join(' · ') : '—';
  return (
    <section data-testid="generation-request-summary" className="rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4 text-sm space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold text-[#111111] dark:text-white">Request #{r.id} <span className="text-[#737373] font-normal">· {[r.course?.course_code, r.course?.course_name].filter(Boolean).join(' — ')}{r.assessment ? ` · ${r.assessment.title}` : ''}</span></h3>
        <Badge variant={statusVariant} data-testid="generation-status">{fmt(r.generation_status)}</Badge>
      </div>
      <dl className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-1 text-xs text-[#525252] dark:text-[#A3A3A3]">
        <div><dt className="text-[#A3A3A3]">Topic</dt><dd>{r.topic || 'Any'}</dd></div>
        <div><dt className="text-[#A3A3A3]">CO / PO</dt><dd>{r.learning_outcome?.code || '—'}{r.program_outcome ? ` / ${r.program_outcome.code}` : ''}</dd></div>
        <div><dt className="text-[#A3A3A3]">Constraints</dt><dd>{QUESTION_TYPE_LABELS[r.question_type] ?? r.question_type} · {fmt(r.difficulty_level) || 'Any'} · {r.cognitive_level || 'Any'} · {r.marks} marks × {r.number_of_questions}</dd></div>
        <div><dt className="text-[#A3A3A3]">Models</dt><dd className="font-mono break-all">{r.models.generation || '—'}{r.models.prompt_version ? ` · prompt v${r.models.prompt_version}` : ''}</dd></div>
        <div><dt className="text-[#A3A3A3]">Grounding</dt><dd>{r.retrieved_chunks} document passage{r.retrieved_chunks === 1 ? '' : 's'} · {r.existing_questions_count} existing question{r.existing_questions_count === 1 ? '' : 's'} checked</dd></div>
        <div><dt className="text-[#A3A3A3]">Regenerations</dt><dd>{r.regeneration_count} / {r.max_regenerations}</dd></div>
        {r.assessment?.remaining_marks != null && <div><dt className="text-[#A3A3A3]">Assessment marks remaining</dt><dd>{r.assessment.remaining_marks} of {r.assessment.total_marks}</dd></div>}
      </dl>
      {r.warnings.length > 0 && (
        <ul className="text-xs text-amber-800 dark:text-amber-300 space-y-0.5" data-testid="request-warnings">{r.warnings.map((w, i) => <li key={i}>⚠ {w}</li>)}</ul>
      )}
      {r.error_message && <p className="text-xs text-red-600" data-testid="request-error">{r.error_message}</p>}
      {s && s.total > 0 && (
        <div className="rounded-lg bg-[#F7F7F5] dark:bg-[#1F1F1F] p-3 text-xs space-y-1" data-testid="set-summary">
          <p className="font-semibold text-[#111111] dark:text-white">Generated set overview <span className="font-normal text-[#737373]">(review aid, not a quality verdict)</span></p>
          <p>Difficulty: {dist(s.difficulty)}</p>
          <p>Bloom: {dist(s.cognitive_level)}</p>
          <p>Validation: {dist(s.validation)} · Potential duplicates: {s.potential_duplicates} · Weak/no CO alignment: {s.weak_alignment} · Total marks: {s.total_marks}</p>
          {r.blueprint_summary && (
            <p className={cn(!r.blueprint_summary.matches && 'text-amber-800 dark:text-amber-300')} data-testid="blueprint-summary">
              {r.blueprint_summary.matches ? 'Generated distribution matches the requested blueprint.' : 'Difficulty/Bloom distribution does not fully match the requested blueprint — consider regenerating.'}
            </p>
          )}
        </div>
      )}
    </section>
  );
};

export interface GeneratedQuestionListProps {
  questions: GeneratedQuestion[];
  outcomes: { id: number | string; code: string; description: string }[];
  regenerationsLeft: number;
  hasAssessment: boolean;
  onEdit: (q: GeneratedQuestion, data: UpdateGeneratedQuestionInput) => Promise<void>;
  onApprove: (q: GeneratedQuestion) => void;
  onReject: (q: GeneratedQuestion) => void;
  onRegenerate: (q: GeneratedQuestion) => void;
  onAddToAssessment: (q: GeneratedQuestion) => void;
  onGenerateRubric?: (q: GeneratedQuestion) => void;
  onViewSimilar?: (existingId: number, source: string) => void;
}

export const GeneratedQuestionList: React.FC<GeneratedQuestionListProps> = ({ questions, ...rest }) => {
  const [showRejected, setShowRejected] = useState(false);
  const visible = questions.filter((q) => showRejected || q.review_status !== 'REJECTED');
  const rejected = questions.length - questions.filter((q) => q.review_status !== 'REJECTED').length;
  if (questions.length === 0) return <GenerationEmptyState />;
  return (
    <div data-testid="generated-question-list" className="space-y-3">
      <div className="flex items-center justify-between text-xs text-[#737373]">
        <span>{visible.length} draft{visible.length === 1 ? '' : 's'}</span>
        {rejected > 0 && <button type="button" className="underline" onClick={() => setShowRejected((s) => !s)}>{showRejected ? 'Hide' : 'Show'} {rejected} rejected</button>}
      </div>
      {visible.map((q) => <GeneratedQuestionCard key={q.id} question={q} {...rest} />)}
    </div>
  );
};

export const QuestionGenerationHistory: React.FC<{ requests: GenerationRequest[]; activeId: number | null; onSelect: (r: GenerationRequest) => void }> = ({ requests, activeId, onSelect }) => (
  <aside data-testid="generation-history" className="rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616]">
    <div className="px-3 py-2 border-b border-[#E5E5E5] dark:border-[#2A2A2A] text-sm font-semibold text-[#111111] dark:text-white">Generation history</div>
    {requests.length === 0 ? <p className="px-3 py-4 text-xs text-[#737373]">No previous generation requests.</p> : (
      <ul className="max-h-[420px] overflow-y-auto">
        {requests.map((r) => (
          <li key={r.id}>
            <button type="button" onClick={() => onSelect(r)} data-testid={`history-item-${r.id}`}
              className={cn('w-full text-left px-3 py-2 border-b border-[#F0F0F0] dark:border-[#1F1F1F] text-xs', activeId === r.id ? 'bg-[#F7F7F5] dark:bg-[#1F1F1F]' : 'hover:bg-[#FAFAFA] dark:hover:bg-[#161616]')}>
              <span className="block font-medium text-[#111111] dark:text-white truncate">{r.topic || 'Any topic'} · {QUESTION_TYPE_LABELS[r.question_type] ?? r.question_type} × {r.number_of_questions}</span>
              <span className="block text-[#737373] truncate">{r.course?.course_code}{r.assessment ? ` · ${r.assessment.title}` : ''}{r.learning_outcome ? ` · ${r.learning_outcome.code}` : ''}</span>
              <span className="block text-[#A3A3A3]">{fmt(r.generation_status)} · {r.approved_count ?? 0}/{r.generated_questions_count ?? 0} approved</span>
            </button>
          </li>
        ))}
      </ul>
    )}
  </aside>
);

const Modal: React.FC<{ title: string; onClose: () => void; children: React.ReactNode; testId: string }> = ({ title, onClose, children, testId }) => (
  <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label={title} data-testid={testId}>
    <div className="w-full max-w-lg rounded-xl bg-white dark:bg-[#161616] border border-[#E5E5E5] dark:border-[#2A2A2A] p-5 space-y-4">
      <div className="flex items-center justify-between"><h3 className="text-base font-semibold text-[#111111] dark:text-white">{title}</h3><button type="button" aria-label="Close" onClick={onClose} className="text-[#737373]">✕</button></div>
      {children}
    </div>
  </div>
);

export const AddToAssessmentModal: React.FC<{
  question: GeneratedQuestion;
  assessments: { id: number | string; title: string; total_marks?: number }[];
  defaultAssessmentId: string;
  outcomeCode?: string;
  onConfirm: (assessmentId: string) => Promise<void> | void;
  onClose: () => void;
  submitting?: boolean;
}> = ({ question, assessments, defaultAssessmentId, outcomeCode, onConfirm, onClose, submitting }) => {
  const [assessmentId, setAssessmentId] = useState(defaultAssessmentId);
  return (
    <Modal title="Add to assessment" onClose={onClose} testId="add-to-assessment-modal">
      <p className="text-sm text-[#525252] dark:text-[#A3A3A3]">This action will create an <strong>official assessment question</strong>. The draft itself stays in the generator history.</p>
      <div className="rounded-lg bg-[#F7F7F5] dark:bg-[#1F1F1F] p-3 text-sm space-y-1">
        <p className="text-[#111111] dark:text-white whitespace-pre-wrap">{question.question_text}</p>
        <p className="text-xs text-[#737373]">Marks: {question.marks} · CO: {outcomeCode || '—'} · Difficulty: {fmt(question.difficulty_level)} · Bloom: {question.cognitive_level || '—'}</p>
      </div>
      <label className="block text-xs font-medium text-[#525252] dark:text-[#A3A3A3]">
        Assessment
        <select aria-label="Target assessment" className="mt-1 w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm" value={assessmentId} onChange={(e) => setAssessmentId(e.target.value)}>
          <option value="">Select an assessment…</option>
          {assessments.map((a) => <option key={a.id} value={String(a.id)}>{a.title}{a.total_marks != null ? ` (${a.total_marks} marks)` : ''}</option>)}
        </select>
      </label>
      <div className="flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button>
        <Button size="sm" disabled={!assessmentId} isLoading={submitting} onClick={() => void onConfirm(assessmentId)} data-testid="confirm-add-question">Add question</Button>
      </div>
    </Modal>
  );
};

export const RegenerateQuestionModal: React.FC<{
  title?: string;
  onConfirm: (feedback: FeedbackInput) => Promise<void> | void;
  onClose: () => void;
  submitting?: boolean;
  regenerationsLeft: number;
}> = ({ title = 'Regenerate', onConfirm, onClose, submitting, regenerationsLeft }) => {
  const [reasons, setReasons] = useState<FeedbackReason[]>([]);
  const [note, setNote] = useState('');
  return (
    <Modal title={title} onClose={onClose} testId="regenerate-modal">
      <p className="text-sm text-[#525252] dark:text-[#A3A3A3]">Tell the generator what to change. The current draft is kept in history as rejected. {regenerationsLeft} regeneration{regenerationsLeft === 1 ? '' : 's'} left for this request.</p>
      <div className="grid grid-cols-2 gap-2 text-sm">
        {FEEDBACK_REASONS.map((r) => (
          <label key={r.value} className="inline-flex items-center gap-2">
            <input type="checkbox" checked={reasons.includes(r.value)} onChange={(e) => setReasons((prev) => e.target.checked ? [...prev, r.value] : prev.filter((x) => x !== r.value))} /> {r.label}
          </label>
        ))}
      </div>
      <textarea aria-label="Feedback note" value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={500} placeholder="Optional note for the generator"
        className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white" />
      <div className="flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button>
        <Button size="sm" isLoading={submitting} disabled={regenerationsLeft <= 0} onClick={() => void onConfirm({ feedback: reasons, feedback_note: note.trim() || undefined })} data-testid="confirm-regenerate">Regenerate</Button>
      </div>
    </Modal>
  );
};
