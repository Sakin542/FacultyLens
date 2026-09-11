import React from 'react';
import { Loader2 } from 'lucide-react';

export const VersionLoading: React.FC<{ label?: string }> = ({ label = 'Loading versions…' }) => (
  <div data-testid="version-loading" role="status" className="flex items-center gap-2 rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-6 text-sm text-[#525252] dark:text-[#A3A3A3]">
    <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" /><span>{label}</span>
  </div>
);
