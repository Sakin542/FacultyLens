import React from 'react';
import { ShieldAlert } from 'lucide-react';

interface ReportAccessNoticeProps {
  message?: string;
  detail?: string;
}

/** Shown when the server rejects a configuration (403) — the client never decides authorization itself. */
export const ReportAccessNotice: React.FC<ReportAccessNoticeProps> = ({ message = 'You are not authorized to generate this report.', detail }) => (
  <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 flex items-start gap-3" data-testid="report-access-notice">
    <ShieldAlert className="w-5 h-5 text-red-700 shrink-0 mt-0.5" />
    <div>
      <p className="text-sm font-semibold text-red-800">{message}</p>
      <p className="text-xs text-red-700 mt-0.5">{detail ?? 'Report scope is determined by your role and course access. Faculty can report on their own and shared courses; department and institution scopes require institutional authorization.'}</p>
    </div>
  </div>
);
