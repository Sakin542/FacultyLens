import React from 'react';
import { AnswerPayload, StudentAnswer, SubmissionQuestion } from '@/types/submission';
import { StudentAnswerCard } from './StudentAnswerCard';

interface StudentAnswerListProps {
  questions: SubmissionQuestion[];
  readOnly?: boolean;
  onAdd?: (questionId: number, data: AnswerPayload, file: File | null) => Promise<void>;
  onUpdate?: (answer: StudentAnswer, data: AnswerPayload, file: File | null) => Promise<void>;
  onDelete?: (answer: StudentAnswer) => Promise<void>;
  onDownload?: (answer: StudentAnswer) => Promise<void>;
  onViewRubric?: (rubricId: number) => void;
}

export const StudentAnswerList: React.FC<StudentAnswerListProps> = ({ questions, ...handlers }) => {
  if (questions.length === 0) {
    return (
      <p className="text-xs text-[#737373] italic p-4 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#3A3A3C]">
        This assessment has no questions yet. Add questions before recording answers.
      </p>
    );
  }
  return (
    <div className="space-y-3" data-testid="student-answer-list">
      {questions.map((q) => (
        <StudentAnswerCard key={`${q.id}-${q.answer?.id ?? 'none'}-${q.answer?.updated_at ?? ''}`} question={q} {...handlers} />
      ))}
    </div>
  );
};
