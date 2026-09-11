import React from 'react';
import { History } from 'lucide-react';
import { Button } from '@/components/common/Button';

export const VersionEmptyState: React.FC<{ canEdit: boolean; onCreate: () => void; questionCount?: number }> = ({ canEdit, onCreate, questionCount }) => (
  <div data-testid="version-empty-state" className="flex flex-col items-center justify-center text-center py-12 px-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#2A2A2A]">
    <div className="w-12 h-12 rounded-full bg-[#F7F7F5] dark:bg-[#1F1F1F] border border-[#E5E5E5] dark:border-[#2A2A2A] flex items-center justify-center text-[#737373] mb-4"><History className="w-6 h-6" aria-hidden="true" /></div>
    <h4 className="text-base font-semibold text-[#111111] dark:text-white mb-1">No versions yet</h4>
    <p className="text-sm text-[#737373] max-w-md">
      Create version 1.0 to snapshot the current assessment{questionCount !== undefined ? ` (${questionCount} question${questionCount === 1 ? '' : 's'})` : ''}, its blueprint and approved rubrics. Later changes become new versions; history is never overwritten.
    </p>
    {canEdit && <Button className="mt-4" size="sm" onClick={onCreate}>Create v1.0</Button>}
  </div>
);
