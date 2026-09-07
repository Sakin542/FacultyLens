import React from 'react';
import { FeedbackReason } from '@/types/feedback';

interface FeedbackReasonSelectProps {
  value: FeedbackReason | string | null;
  onChange: (val: FeedbackReason) => void;
  decision: 'ACCEPTED' | 'DISMISSED' | 'REVIEWED';
  disabled?: boolean;
}

export const FeedbackReasonSelect: React.FC<FeedbackReasonSelectProps> = ({
  value,
  onChange,
  decision,
  disabled = false,
}) => {
  const reasonOptions: Array<{ value: FeedbackReason; label: string; context: string }> = [
    { value: 'USEFUL_INSIGHT', label: 'Useful Insight', context: 'Provides a helpful perspective or identifies a real gap' },
    { value: 'WILL_IMPLEMENT', label: 'Will Implement', context: 'Planning to apply this change to the assessment' },
    { value: 'ALREADY_ADDRESSED', label: 'Already Addressed', context: 'Already covered elsewhere or solved in recent syllabus' },
    { value: 'NOT_APPLICABLE', label: 'Not Applicable', context: 'Outside the deliberate scope of this specific assessment' },
    { value: 'INCORRECT_CONTEXT', label: 'Incorrect Context', context: 'Misunderstood problem difficulty or academic setting' },
    { value: 'NEEDS_MODIFICATION', label: 'Needs Modification', context: 'Good direction but requires faculty adjustment' },
    { value: 'DUPLICATE', label: 'Duplicate Suggestion', context: 'Overlaps with another recommendation' },
    { value: 'OTHER', label: 'Other Reason', context: 'Specific academic or institutional consideration' },
  ];

  const labelText =
    decision === 'DISMISSED'
      ? 'Why are you ignoring/dismissing this recommendation?'
      : decision === 'ACCEPTED'
      ? 'Primary reason for acceptance (Optional):'
      : 'Review rationale (Optional):';

  return (
    <div className="space-y-1.5">
      <label className="text-xs font-semibold text-[#111111] dark:text-white block">
        {labelText}
      </label>

      <select
        value={value || ''}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value as FeedbackReason)}
        className="w-full rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] p-2.5 text-xs text-[#111111] dark:text-white focus:outline-none focus:ring-1 focus:ring-black dark:focus:ring-white"
      >
        <option value="">-- Select structured reason --</option>
        {reasonOptions.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label} — {opt.context}
          </option>
        ))}
      </select>
    </div>
  );
};

