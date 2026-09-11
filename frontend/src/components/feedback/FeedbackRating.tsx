import React from 'react';
import { Star } from 'lucide-react';

interface FeedbackRatingProps {
  value: number | null;
  onChange: (val: number) => void;
  disabled?: boolean;
}

export const FeedbackRating: React.FC<FeedbackRatingProps> = ({
  value,
  onChange,
  disabled = false,
}) => {
  const ratingLabels: Record<number, string> = {
    1: 'Not relevant',
    2: 'Not useful',
    3: 'Neutral',
    4: 'Useful',
    5: 'Very useful',
  };

  return (
    <div className="space-y-2">
      <label className="text-xs font-semibold text-sage-800 dark:text-white block">
        How useful is this recommendation?
      </label>

      <div className="flex items-center gap-2">
        {[1, 2, 3, 4, 5].map((num) => {
          const isSelected = value !== null && value >= num;
          const isExact = value === num;

          return (
            <button
              key={num}
              type="button"
              disabled={disabled}
              onClick={() => onChange(num)}
              className={`flex flex-col items-center justify-center p-2 rounded-xl border transition-all text-xs focus:outline-none focus:ring-1 focus:ring-black dark:focus:ring-white ${
                isExact
                  ? 'bg-sage-700 text-white dark:bg-white dark:text-sage-800 border-sage-700 dark:border-white shadow-sm'
                  : isSelected
                  ? 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 border-amber-300 dark:border-amber-800'
                  : 'bg-sage-100 dark:bg-[#2C2C2E] text-sage-500 border-sage-200 dark:border-[#3A3A3C] hover:text-sage-800 dark:hover:text-white'
              }`}
            >
              <Star
                className={`w-4 h-4 mb-0.5 ${
                  isSelected ? 'fill-amber-400 text-amber-500' : 'text-sage-500'
                }`}
              />
              <span className="font-mono font-bold text-xs">{num}</span>
            </button>
          );
        })}

        {value !== null && (
          <span className="text-xs font-semibold text-sage-800 dark:text-white ml-2">
            {ratingLabels[value]}
          </span>
        )}
      </div>
    </div>
  );
};

