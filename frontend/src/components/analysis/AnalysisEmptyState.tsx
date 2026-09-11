import React from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Sparkles, BrainCircuit } from 'lucide-react';

interface AnalysisEmptyStateProps {
  assessmentTitle: string;
  courseCode: string;
  onRunAnalysis: () => void;
  isRunningAnalysis?: boolean;
}

export const AnalysisEmptyState: React.FC<AnalysisEmptyStateProps> = ({
  assessmentTitle,
  courseCode,
  onRunAnalysis,
  isRunningAnalysis = false,
}) => {
  return (
    <Card className="p-12 text-center bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-6 max-w-2xl mx-auto">
      <div className="w-16 h-16 rounded-2xl bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center mx-auto text-sage-800 dark:text-white shadow-inner">
        <BrainCircuit className="w-8 h-8 text-sage-800 dark:text-white" />
      </div>

      <div className="space-y-2">
        <h2 className="text-xl font-bold text-sage-800 dark:text-white">
          AI Analysis Has Not Been Run Yet
        </h2>
        <p className="text-sm text-sage-500 max-w-md mx-auto leading-relaxed">
          Analyze <strong className="text-sage-800 dark:text-white">{assessmentTitle}</strong> ({courseCode}) to audit evaluation rigor, learning outcome coverage, and item originality.
        </p>
      </div>

      <div className="p-5 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] text-left text-xs text-sage-500 space-y-2">
        <span className="font-semibold text-sage-800 dark:text-white block">
          Running AI Analysis will compute:
        </span>
        <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2 list-disc list-inside">
          <li>Overall Assessment Quality Score</li>
          <li>Topic Coverage against Syllabus</li>
          <li>Learning Outcome Semantic Alignment</li>
          <li>Difficulty Distribution (Easy/Med/Hard)</li>
          <li>Bloom's Cognitive Diversity Spread</li>
          <li>Question Bank Duplicate Detection</li>
          <li>AI Findings & Evidence</li>
          <li>Actionable Recommendations</li>
        </ul>
      </div>

      <Button
        variant="primary"
        size="md"
        className="bg-sage-700 text-white hover:bg-black dark:bg-white dark:text-sage-800 dark:hover:bg-neutral-200 gap-2 mx-auto"
        onClick={onRunAnalysis}
        disabled={isRunningAnalysis}
      >
        <Sparkles className="w-4 h-4 text-amber-400" />
        <span>{isRunningAnalysis ? 'Analyzing Complete Assessment...' : 'Run AI Analysis'}</span>
      </Button>
    </Card>
  );
};

