import React from 'react';
import { Loader2 } from 'lucide-react';

export const VersionLoading: React.FC<{ label?: string }> = ({ label = 'Loading versions…' }) => (
  <div data-testid="version-loading" role="status" className="flex items-center gap-2 rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-6 text-sm text-sage-600 dark:text-sage-400">
    <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" /><span>{label}</span>
  </div>
);
