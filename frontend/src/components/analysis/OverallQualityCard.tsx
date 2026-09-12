import React from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { ShieldCheck, Calculator } from 'lucide-react';

interface OverallQualityCardProps {
  score: number | null;
  rating?: string | null;
  totalQuestions?: number;
  /** STEP 45: opens "How this score is calculated" (weights, normalization, dimension evidence). */
  onExplain?: () => void;
}

export const OverallQualityCard: React.FC<OverallQualityCardProps> = ({
  score,
  rating,
  totalQuestions = 0,
  onExplain,
}) => {
  const getRatingMeta = (rat?: string | null, sc?: number | null) => {
    const s = sc ?? 0;
    const r = (rat || '').toUpperCase();

    if (r === 'EXCELLENT' || s >= 90) {
      return {
        label: 'EXCELLENT',
        description: 'Exemplary alignment, balanced cognitive spread, and thorough syllabus coverage.',
        badgeVariant: 'Good' as const,
        strokeColor: '#16A34A',
      };
    }
    if (r === 'GOOD' || s >= 80) {
      return {
        label: 'GOOD',
        description: 'Strong quality profile across core learning outcomes with minor balance opportunities.',
        badgeVariant: 'Good' as const,
        strokeColor: '#2F3E2E',
      };
    }
    if (r === 'FAIR' || s >= 70) {
      return {
        label: 'FAIR',
        description: 'Acceptable baseline evaluation; review identified learning outcome or difficulty gaps.',
        badgeVariant: 'neutral' as const,
        strokeColor: '#D97706',
      };
    }
    if (r === 'NEEDS_REVIEW' || s >= 60) {
      return {
        label: 'NEEDS REVIEW',
        description: 'Noticeable skew in cognitive tiers, unassessed outcomes, or duplicate formulation.',
        badgeVariant: 'Attention' as const,
        strokeColor: '#D97706',
      };
    }
    return {
      label: 'REQUIRES ATTENTION',
      description: 'Significant imbalance detected across key syllabus modules or evaluation domains.',
      badgeVariant: 'Critical' as const,
      strokeColor: '#DC2626',
    };
  };

  const meta = getRatingMeta(rating, score);
  const displayScore = score !== null ? Math.round(score) : null;

  // SVG circular gauge geometry
  const radius = 54;
  const strokeWidth = 10;
  const circumference = 2 * Math.PI * radius;
  const strokeDashoffset = displayScore !== null
    ? circumference - (displayScore / 100) * circumference
    : circumference;

  return (
    <Card className="p-6 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm">
      <div className="flex flex-col md:flex-row items-center justify-between gap-6">
        {/* Left: Prominent Radial Score */}
        <div className="flex items-center gap-6">
          <div className="relative w-32 h-32 flex items-center justify-center shrink-0">
            <svg className="w-full h-full transform -rotate-90" viewBox="0 0 130 130">
              {/* Background circle */}
              <circle
                cx="65"
                cy="65"
                r={radius}
                className="stroke-[#F0F0ED] dark:stroke-[#2C2C2E]"
                strokeWidth={strokeWidth}
                fill="transparent"
              />
              {/* Progress circle */}
              {displayScore !== null && (
                <circle
                  cx="65"
                  cy="65"
                  r={radius}
                  stroke={meta.strokeColor}
                  strokeWidth={strokeWidth}
                  strokeDasharray={circumference}
                  strokeDashoffset={strokeDashoffset}
                  strokeLinecap="round"
                  fill="transparent"
                  className="transition-all duration-700 ease-out"
                />
              )}
            </svg>

            {/* Centered Score */}
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
              <span className="text-3xl font-extrabold font-mono text-sage-800 dark:text-white tracking-tight">
                {displayScore !== null ? displayScore : '—'}
              </span>
              <span className="text-[10px] uppercase font-bold text-sage-500 tracking-wider">
                / 100
              </span>
            </div>
          </div>

          {/* Score Interpretation */}
          <div className="space-y-1.5 text-left">
            <div className="flex items-center gap-2">
              <span className="text-xs uppercase font-bold text-sage-500 tracking-wider">
                Overall Quality Score
              </span>
              <Badge variant={meta.badgeVariant} dot>
                {meta.label}
              </Badge>
            </div>
            <h2 className="text-lg font-bold text-sage-800 dark:text-white">
              {meta.label === 'EXCELLENT' ? 'Exemplary Assessment Rigor' : `${meta.label} Assessment Rigor`}
            </h2>
            <p className="text-xs text-sage-500 max-w-md leading-relaxed">
              {meta.description}
            </p>
          </div>
        </div>

        {/* Right: Academic Context Note */}
        <div className="w-full md:w-72 p-3.5 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] space-y-2 text-xs">
          <div className="flex items-center gap-1.5 font-semibold text-sage-800 dark:text-white">
            <ShieldCheck className="w-4 h-4 text-sage-800 dark:text-white" />
            <span>Academic Decision Support</span>
          </div>
          <p className="text-[11px] text-sage-500 leading-relaxed">
            Initial assessment-quality indicator based on FacultyLens analysis of six academic dimensions across {totalQuestions} questions.
          </p>
          {onExplain && (
            <button
              type="button"
              onClick={onExplain}
              data-testid="quality-how-calculated"
              className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-sage-800 dark:text-white underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700 rounded"
            >
              <Calculator className="w-3.5 h-3.5" aria-hidden="true" />
              How this score is calculated
            </button>
          )}
        </div>
      </div>
    </Card>
  );
};
