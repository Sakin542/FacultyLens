import { apiClient, API_BASE_URL, ApiError } from './api';
import {
  ExportFormat,
  InstitutionalReport,
  ReportFilterOptions,
  ReportListResponse,
  ReportPreview,
  ReportRequest,
  ReportScope,
  ReportTypeKey,
  ReportTypesResponse,
} from '@/types/report';

interface Envelope<T> { status: 'success' | 'error'; message?: string; data: T }

const qs = (params: Record<string, string | number | undefined | null>): string => {
  const q = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.append(k, String(v)); });
  const s = q.toString();
  return s ? `?${s}` : '';
};

/** Authenticated download from the private endpoint; the server checks the policy and audits the download. */
async function downloadFile(reportId: number | string, fallbackName: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/reports/${reportId}/download`, { method: 'GET', credentials: 'include', headers: { Accept: '*/*' } });
  if (!response.ok) {
    let message = 'The report could not be downloaded.';
    try {
      const body = (await response.json()) as { message?: string };
      if (body.message) message = body.message;
    } catch {
      // non-JSON error body; keep the safe default
    }
    throw new ApiError(response.status, message);
  }
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^";]+)"?/.exec(disposition);
  const blob = await response.blob();
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = match?.[1] ?? fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

/**
 * STEP 39: Institutional Export & Reporting API.
 * Authorization, scoping and aggregation happen on the server — the client only chooses a configuration.
 */
export const institutionalReportService = {
  getReportTypes: (): Promise<Envelope<ReportTypesResponse>> => apiClient('/reports/types', { method: 'GET' }),

  getReportFilters: (reportType?: ReportTypeKey, scope?: ReportScope): Promise<Envelope<ReportFilterOptions>> =>
    apiClient(`/reports/filters${qs({ report_type: reportType, scope_type: scope })}`, { method: 'GET' }),

  previewReport: (request: ReportRequest): Promise<Envelope<ReportPreview>> =>
    apiClient('/reports/preview', { method: 'POST', body: JSON.stringify({ report_type: request.report_type, scope_type: request.scope_type, filters: request.filters }) }),

  createReport: (request: ReportRequest & { format: ExportFormat }): Promise<Envelope<InstitutionalReport>> =>
    apiClient('/reports', { method: 'POST', body: JSON.stringify(request) }),

  getReport: (reportId: number | string): Promise<Envelope<InstitutionalReport>> => apiClient(`/reports/${reportId}`, { method: 'GET' }),

  getReports: (params: { status?: string; report_type?: string; page?: number; per_page?: number } = {}): Promise<Envelope<ReportListResponse>> =>
    apiClient(`/reports${qs(params)}`, { method: 'GET' }),

  deleteReport: (reportId: number | string): Promise<Envelope<null>> => apiClient(`/reports/${reportId}`, { method: 'DELETE' }),

  downloadReport: (report: Pick<InstitutionalReport, 'id' | 'format' | 'file_name'>): Promise<void> =>
    downloadFile(report.id, report.file_name ?? `FacultyLens_Report_${report.id}.${report.format.toLowerCase()}`),

  downloadPdf: (reportId: number | string): Promise<void> => downloadFile(reportId, `FacultyLens_Report_${reportId}.pdf`),

  exportCsv: (reportId: number | string): Promise<void> => downloadFile(reportId, `FacultyLens_Report_${reportId}.csv`),

  exportXlsx: (reportId: number | string): Promise<void> => downloadFile(reportId, `FacultyLens_Report_${reportId}.xlsx`),
};
