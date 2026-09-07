import React from 'react';
import { Card } from '@/components/common/Card';
import { Loader2, Sparkles } from 'lucide-react';

export const AnalysisLoading: React.FC<{ isProcessing?: boolean }> = ({ isProcessing = false }) => {
  if (isProcessing) {
    return (
      <Card className="p-12 text-center bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-6 max-w-xl mx-auto">
        <div className="w-16 h-16 rounded-2xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center mx-auto text-[#111111] dark:text-white">
          <Loader2 className="w-8 h-8 animate-spin text-[#111111] dark:text-white" />
        </div>

        <div className="space-y-2">
          <h2 className="text-xl font-bold text-[#111111] dark:text-white flex items-center justify-center gap-2">
            <Sparkles className="w-5 h-5 text-amber-500 animate-pulse" />
            <span>AI Analysis in Progress...</span>
          </h2>
          <p className="text-xs text-[#737373] max-w-md mx-auto leading-relaxed">
            FacultyLens is executing all NLP pipelines for this assessment:
          </p>
        </div>

        <div className="p-4 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-left text-xs text-[#737373] space-y-2 font-mono">
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            <span>Analyzing individual question difficulty & Bloom levels</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            <span>Evaluating semantic alignment against Course Learning Outcomes</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            <span>Comparing questions against historical assessment repository</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            <span>Calculating 6-dimensional Assessment Quality Engine metrics</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            <span>Generating evidence-based actionable recommendations</span>
          </div>
        </div>
      </Card>
    );
  }

  // Generic skeleton loader
  return (
    <div className="space-y-6 animate-pulse">
      {/* Header skeleton */}
      <div className="h-32 bg-[#E5E5E5] dark:bg-[#2C2C2E] rounded-2xl" />

      {/* Overall score skeleton */}
      <div className="h-44 bg-[#E5E5E5] dark:bg-[#2C2C2E] rounded-2xl" />

      {/* Metric cards skeleton */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {[1, 2, 3, 4, 5, 6].map((i) => (
          <div key={i} className="h-28 bg-[#E5E5E5] dark:bg-[#2C2C2E] rounded-xl" />
        ))}
      </div>

      {/* 2-column charts skeleton */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="h-64 bg-[#E5E5E5] dark:bg-[#2C2C2E] rounded-xl" />
        <div className="h-64 bg-[#E5E5E5] dark:bg-[#2C2C2E] rounded-xl" />
      </div>
    </div>
  );
};

