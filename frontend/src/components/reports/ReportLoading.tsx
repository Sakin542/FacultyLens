import React from 'react';
import { Loader2 } from 'lucide-react';

export const ReportLoading: React.FC<{ message?: string }> = ({
  message = 'Loading official academic assessment report...',
}) => {
  return (
    <div className="min-h-[450px] flex flex-col items-center justify-center p-8 bg-white border border-sage-200 rounded-xl shadow-subtle text-center">
      <Loader2 className="w-8 h-8 text-sage-800 animate-spin mb-4" />
      <h3 className="text-base font-bold text-sage-800">{message}</h3>
      <p className="text-xs text-sage-500 max-w-sm mt-1">
        Compiling syllabus coverage metrics, learning outcome alignment vectors, and authoritative quality indices.
      </p>
    </div>
  );
};

