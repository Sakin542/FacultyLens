import { Card } from '@/components/common/Card';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import {
  BookOpen,
  Target,
  BarChart3,
  BrainCircuit,
  Layers,
  Percent,
} from 'lucide-react';

interface QualityComponentCardsProps {
  scores: {
    topicCoverage?: number | null;
    learningOutcomeCoverage?: number | null;
    difficultyBalance?: number | null;
    cognitiveDiversity?: number | null;
    questionDiversity?: number | null;
    marksDistribution?: number | null;
  };
}

export const QualityComponentCards: React.FC<QualityComponentCardsProps> = ({ scores }) => {
  const cards = [
    {
      name: 'Topic Coverage',
      score: scores.topicCoverage,
      icon: BookOpen,
      explanation: 'Syllabus modules and key concept representation in assessment items.',
    },
    {
      name: 'LO Coverage',
      score: scores.learningOutcomeCoverage,
      icon: Target,
      explanation: 'Direct and semantic alignment to defined Course Learning Outcomes.',
    },
    {
      name: 'Difficulty Balance',
      score: scores.difficultyBalance,
      icon: BarChart3,
      explanation: 'Question distribution across Easy, Medium, and Hard tiers vs. target profile.',
    },
    {
      name: 'Cognitive Diversity',
      score: scores.cognitiveDiversity,
      icon: BrainCircuit,
      explanation: "Spread across Bloom's levels from foundational to higher-order critique.",
    },
    {
      name: 'Question Diversity',
      score: scores.questionDiversity,
      icon: Layers,
      explanation: 'Variety of evaluation formats: conceptual, descriptive, and problem-solving.',
    },
    {
      name: 'Marks Distribution',
      score: scores.marksDistribution,
      icon: Percent,
      explanation: 'Allocation parity ensuring no single problem dominates the total examination score.',
    },
  ];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      {cards.map((card, idx) => {
        const Icon = card.icon;
        const val = card.score !== null && card.score !== undefined ? Math.round(card.score) : null;

        return (
          <Card
            key={idx}
            className="p-4 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm flex flex-col justify-between space-y-3 hover:border-[#111111] dark:hover:border-white transition-colors"
          >
            <div className="space-y-2">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-2">
                  <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
                    <Icon className="w-4 h-4" />
                  </div>
                  <div>
                    <h3 className="text-xs font-bold text-[#111111] dark:text-white leading-tight">
                      {card.name}
                    </h3>
                  </div>
                </div>

                <span className="font-mono text-base font-extrabold text-[#111111] dark:text-white">
                  {val !== null ? `${val}%` : '—'}
                </span>
              </div>

              <p className="text-[11px] text-[#737373] leading-relaxed line-clamp-2">
                {card.explanation}
              </p>
            </div>

            <div className="pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
              {val !== null ? (
                <ProgressBar value={val} showValue={false} size="sm" />
              ) : (
                <span className="text-[10px] text-[#737373] italic">Analysis pending</span>
              )}
            </div>
          </Card>
        );
      })}
    </div>
  );
};
