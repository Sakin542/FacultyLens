import { apiClient, API_BASE_URL } from './api';
import { AssessmentReportData, GeneratedPdfReportInfo } from '../types/report';

export interface GenerateReportResponse {
  status: string;
  message: string;
  data: {
    report: GeneratedPdfReportInfo;
    download_url: string;
  };
}

export interface ShareReportResponse {
  status: string;
  message: string;
  data: {
    share_token: string;
    share_url: string;
    api_share_url: string;
    is_shareable: boolean;
  };
}

export const reportService = {
  /**
   * Fetch complete, authoritative academic report data for an assessment.
   */
  async getAssessmentReport(assessmentId: number | string): Promise<AssessmentReportData> {
    const response = await apiClient<{ status: string; data: AssessmentReportData }>(
      `/assessments/${assessmentId}/report`
    );
    return response.data;
  },

  /**
   * Request authoritative PDF report generation.
   */
  async generatePdfReport(assessmentId: number | string): Promise<GenerateReportResponse['data']> {
    const response = await apiClient<GenerateReportResponse>(
      `/assessments/${assessmentId}/report/generate`,
      {
        method: 'POST',
      }
    );
    return response.data;
  },

  /**
   * Download the generated PDF report via authenticated endpoint and trigger browser save.
   */
  async downloadReportPdf(reportId: number | string, fileName: string = 'FacultyLens_Assessment_Report.pdf'): Promise<void> {
    const url = `${API_BASE_URL}/assessment-reports/${reportId}/download`;
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
    });

    if (!response.ok) {
      const errorText = await response.text();
      let errorMsg = 'Failed to download report PDF.';
      try {
        const parsed = JSON.parse(errorText);
        errorMsg = parsed.message || errorMsg;
      } catch {
        // use default
      }
      throw new Error(errorMsg);
    }

    const blob = await response.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(downloadUrl);
  },

  /**
   * Generate or retrieve shareable link token for an assessment report.
   */
  async shareReport(reportId: number | string): Promise<ShareReportResponse['data']> {
    const response = await apiClient<ShareReportResponse>(
      `/assessment-reports/${reportId}/share`,
      {
        method: 'POST',
      }
    );
    return response.data;
  },

  /**
   * Revoke public shareable link for an assessment report.
   */
  async revokeShareReport(reportId: number | string): Promise<void> {
    await apiClient<{ status: string; message: string }>(
      `/assessment-reports/${reportId}/revoke-share`,
      {
        method: 'POST',
      }
    );
  },

  /**
   * Public view of a shared assessment report via unique token.
   */
  async getSharedReport(token: string): Promise<AssessmentReportData> {
    const response = await apiClient<{ status: string; data: AssessmentReportData }>(
      `/shared/reports/${token}`
    );
    return response.data;
  },

  /**
   * Public download of a shared report PDF via unique token.
   */
  async downloadSharedReportPdf(token: string, fileName: string = 'FacultyLens_Assessment_Report.pdf'): Promise<void> {
    const url = `${API_BASE_URL}/shared/reports/${token}/download`;
    const response = await fetch(url, {
      method: 'GET',
    });

    if (!response.ok) {
      throw new Error('Failed to download shared report. Link may have expired or been revoked.');
    }

    const blob = await response.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(downloadUrl);
  },
};

