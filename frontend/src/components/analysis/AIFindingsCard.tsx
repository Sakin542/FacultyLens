import React from 'react';
import { Card } from '@/components/common/Card';
import { Sparkles } from 'lucide-react';

interface AIFindingsCardProps {
  findings: string[];
}

export const AIFindingsCard: React.FC<AIFindingsCardProps> = ({ findings }) => {
  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4">
      <div className="flex items-center gap-2 pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
          <Sparkles className="w-4 h-4" />
        </div>
        <div>
          <h3 className="text-sm font-bold text-[#111111] dark:text-white">AI Findings</h3>
          <p className="text-xs text-[#737373]">Synthesis of assessment strengths and potential risks</p>
        </div>
      </div>

      {findings.length === 0 ? (
        <div className="p-4 text-center text-xs text-[#737373] italic">
          No critical AI findings surfaced for this assessment.
        </div>
      ) : (
        <div className="space-y-2.5">
          {findings.map((fnd, idx) => (
            <div
              key={idx}
              className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-start gap-3 text-xs"
            >
              <div className="w-5 h-5 rounded-full bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white shrink-0 mt-0.5 font-mono text-[10px] font-bold">
                {idx + 1}
              </div>
              <p className="text-[#262626] dark:text-[#D4D4D4] leading-relaxed flex-1">
                {fnd}
              </p>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
};
