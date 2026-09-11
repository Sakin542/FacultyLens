import React from 'react';
import { Eye, FilePlus2 } from 'lucide-react';
import { Button } from '@/components/common/Button';

interface ReportGenerationButtonProps {
  onPreview: () => void;
  onGenerate: () => void;
  canPreview: boolean;
  canGenerate: boolean;
  previewing?: boolean;
  generating?: boolean;
  willQueue?: boolean;
}

export const ReportGenerationButton: React.FC<ReportGenerationButtonProps> = ({ onPreview, onGenerate, canPreview, canGenerate, previewing, generating, willQueue }) => (
  <div className="flex flex-col sm:flex-row sm:items-center gap-2">
    <Button type="button" variant="outline" size="md" onClick={onPreview} disabled={!canPreview || generating} isLoading={previewing} leftIcon={<Eye className="w-4 h-4" />}>
      {previewing ? 'Previewing…' : 'Preview Report'}
    </Button>
    <Button type="button" variant="primary" size="md" onClick={onGenerate} disabled={!canGenerate || previewing} isLoading={generating} leftIcon={<FilePlus2 className="w-4 h-4" />} title={canGenerate ? undefined : 'Preview the report first'}>
      {generating ? (willQueue ? 'Queuing…' : 'Generating…') : 'Generate Report'}
    </Button>
  </div>
);
