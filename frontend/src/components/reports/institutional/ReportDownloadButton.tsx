import React, { useState } from 'react';
import { Download } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { institutionalReportService } from '@/services/institutionalReportService';
import { InstitutionalReport } from '@/types/report';

interface ReportDownloadButtonProps {
  report: InstitutionalReport;
  size?: 'sm' | 'md';
  variant?: 'primary' | 'outline' | 'ghost';
  onError?: (message: string) => void;
  onDownloaded?: () => void;
  className?: string;
}

/** Downloads through the authenticated endpoint (server enforces policy, expiry and audit). */
export const ReportDownloadButton: React.FC<ReportDownloadButtonProps> = ({ report, size = 'sm', variant = 'primary', onError, onDownloaded, className }) => {
  const [busy, setBusy] = useState(false);
  const disabled = !report.downloadable;
  const title = report.status !== 'COMPLETED' ? 'The report is not ready yet' : report.is_expired ? 'This report has expired' : `Download ${report.format}`;

  const handle = async () => {
    setBusy(true);
    try {
      await institutionalReportService.downloadReport(report);
      onDownloaded?.();
    } catch (err) {
      onError?.(err instanceof Error ? err.message : 'The report could not be downloaded.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Button type="button" size={size} variant={variant} onClick={handle} isLoading={busy} disabled={disabled} title={title} leftIcon={<Download className="w-4 h-4" />} className={className} aria-label={`Download ${report.format}`}>
      {report.format}
    </Button>
  );
};
