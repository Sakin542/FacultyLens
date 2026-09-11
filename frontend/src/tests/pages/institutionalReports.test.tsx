import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { InstitutionalReports } from '@/pages/InstitutionalReports';
import { ReportBuilder } from '@/pages/ReportBuilder';
import { ReportDetails } from '@/pages/ReportDetails';
import { institutionalReportService } from '@/services/institutionalReportService';
import { ApiError } from '@/services/api';
import { InstitutionalReport, ReportFilterOptions, ReportPreview, ReportTypesResponse } from '@/types/report';

vi.mock('@/services/institutionalReportService', () => ({
  institutionalReportService: {
    getReportTypes: vi.fn(),
    getReportFilters: vi.fn(),
    previewReport: vi.fn(),
    createReport: vi.fn(),
    getReport: vi.fn(),
    getReports: vi.fn(),
    deleteReport: vi.fn(),
    downloadReport: vi.fn(),
    downloadPdf: vi.fn(),
    exportCsv: vi.fn(),
    exportXlsx: vi.fn(),
  },
}));

const svc = vi.mocked(institutionalReportService);

const registry: ReportTypesResponse = {
  types: [
    { key: 'ASSESSMENT_QUALITY', label: 'Assessment Quality Report', description: 'STEP 13 quality dimensions.', scopes: ['ASSESSMENT', 'ASSESSMENT_VERSION', 'COURSE', 'FACULTY'], student_data: false, formats: ['PDF', 'CSV', 'XLSX'] },
    { key: 'STUDENT_PERFORMANCE', label: 'Student Performance Report', description: 'Aggregated performance.', scopes: ['ASSESSMENT', 'COURSE', 'FACULTY'], student_data: true, formats: ['PDF', 'CSV', 'XLSX'] },
  ],
  scopes: ['FACULTY', 'COURSE', 'ASSESSMENT', 'ASSESSMENT_VERSION'],
  formats: ['PDF', 'CSV', 'XLSX'],
  scope_filters: {
    FACULTY: ['semester', 'academic_year', 'assessment_type', 'start_date', 'end_date', 'status'],
    COURSE: ['course_id', 'assessment_type', 'start_date', 'end_date', 'status'],
    ASSESSMENT: ['course_id', 'assessment_id'],
    ASSESSMENT_VERSION: ['course_id', 'assessment_id', 'assessment_version_id'],
    DEPARTMENT: ['department'],
    INSTITUTION: ['semester'],
  },
  expiration_days: 7,
  async_threshold_records: 2000,
};

const filterOptions: ReportFilterOptions = {
  applicable: [],
  options: {
    courses: [{ id: 10, code: 'CSE101', name: 'Database Systems', semester: 'Fall', academic_year: '2026' }],
    assessments: [{ id: 5, course_id: 10, title: 'Midterm', type: 'midterm', date: '2026-10-01' }],
    assessment_versions: [{ id: 3, assessment_id: 5, version_number: 3, version_label: 'v3.0', status: 'FINALIZED', total_marks: 100, question_count: 10 }],
    semesters: ['Fall'], academic_years: ['2026'], assessment_types: ['midterm'], statuses: ['draft', 'published'], departments: [], programs: [],
  },
};

const preview: ReportPreview = {
  report_type: 'ASSESSMENT_QUALITY', report_label: 'Assessment Quality Report', scope_type: 'ASSESSMENT_VERSION', scope_description: 'CSE101 · Midterm · v3.0',
  filters: { course_id: 10, assessment_id: 5, assessment_version_id: 3 }, record_count: 7, estimated_size: '12 KB', will_queue: false, contains_student_data: false, has_data: true,
  metadata: {
    product: 'FacultyLens', note: 'Generated from FacultyLens', report_type: 'ASSESSMENT_QUALITY', report_label: 'Assessment Quality Report', scope: 'ASSESSMENT_VERSION', scope_description: 'CSE101 · Midterm · v3.0',
    filters: { course_id: 10, assessment_id: 5, assessment_version_id: 3 }, generated_by: { id: 1, name: 'Dr. Rashid', department: 'CSE', designation: 'Assistant Professor' },
    generated_at: '2026-09-11T10:00:00Z', data_as_of: '2026-09-10T12:00:00Z', course: { id: 10, code: 'CSE101', name: 'Database Systems', semester: 'Fall', academic_year: '2026' },
    assessment: { id: 5, title: 'Midterm', type: 'midterm' }, assessment_version: { id: 3, version_label: 'v3.0', status: 'FINALIZED' }, academic_year: '2026', semester: 'Fall', data_period: null,
    course_count: 1, assessment_count: 1, contains_student_data: false, privacy: null,
  },
  summary: [{ label: 'Average Overall Quality', value: 84 }, { label: 'Analyzed Assessments', value: 1 }],
  sections: [],
  tables: [{ key: 'quality', title: 'Assessment Quality (STEP 13)', columns: [{ key: 'assessment', label: 'Assessment' }, { key: 'overall_quality', label: 'Overall Quality' }, { key: 'rating', label: 'Rating' }], rows: [{ assessment: 'Midterm', overall_quality: 84, rating: 'GOOD' }], total_rows: 1, note: null }],
  warnings: ['1 assessment(s) in scope have no completed analysis and are excluded.'],
};

const report = (overrides: Partial<InstitutionalReport> = {}): InstitutionalReport => ({
  id: 42, uuid: 'u-42', title: 'Assessment Quality Report — CSE101 · Midterm · v3.0', report_type: 'ASSESSMENT_QUALITY', report_label: 'Assessment Quality Report', scope_type: 'ASSESSMENT_VERSION',
  course: { id: 10, code: 'CSE101', name: 'Database Systems' }, assessment: { id: 5, title: 'Midterm' }, assessment_version: { id: 3, version_label: 'v3.0', status: 'FINALIZED' }, department: null, program_id: null,
  filters: { course_id: 10, assessment_id: 5, assessment_version_id: 3 }, format: 'PDF', status: 'COMPLETED', is_async: false, contains_student_data: false, file_name: 'FacultyLens_Quality.pdf', file_size: 20480, record_count: 7,
  summary: { items: [{ label: 'Average Overall Quality', value: 84 }], warnings: [], tables: [{ key: 'quality', title: 'Assessment Quality (STEP 13)', rows: 1 }] },
  data_as_of: '2026-09-10T12:00:00Z', started_at: '2026-09-11T10:00:00Z', generated_at: '2026-09-11T10:00:05Z', expires_at: '2026-09-18T10:00:05Z', is_expired: false, downloadable: true, file_deleted_at: null, error_message: null,
  created_by: { id: 1, name: 'Dr. Rashid' }, created_at: '2026-09-11T10:00:00Z', updated_at: '2026-09-11T10:00:05Z', ...overrides,
});

const renderBuilder = () => render(<MemoryRouter initialEntries={['/reports/create']}><Routes><Route path="/reports/create" element={<ReportBuilder />} /><Route path="/reports/:reportId" element={<div>Details page</div>} /></Routes></MemoryRouter>);

describe('STEP 39 — Institutional Reports', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    svc.getReportTypes.mockResolvedValue({ status: 'success', data: registry });
    svc.getReportFilters.mockResolvedValue({ status: 'success', data: filterOptions });
  });

  describe('Report history (/reports)', () => {
    it('shows loading, then the empty state when there are no reports', async () => {
      svc.getReports.mockResolvedValue({ status: 'success', data: { items: [], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } });
      render(<MemoryRouter><InstitutionalReports /></MemoryRouter>);
      expect(screen.getByTestId('report-loading')).toBeInTheDocument();
      await waitFor(() => expect(screen.getByTestId('report-empty-state')).toBeInTheDocument());
      expect(screen.getByText(/no reports yet/i)).toBeInTheDocument();
    });

    it('lists reports with scope, format, status, expiry and actions', async () => {
      svc.getReports.mockResolvedValue({ status: 'success', data: { items: [report(), report({ id: 43, status: 'FAILED', format: 'CSV', downloadable: false, error_message: 'Report generation failed. Please try again.', generated_at: null })], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 2 } } });
      render(<MemoryRouter><InstitutionalReports /></MemoryRouter>);
      await waitFor(() => expect(screen.getByTestId('report-history')).toBeInTheDocument());
      const row = screen.getByTestId('report-row-42');
      expect(within(row).getByText('Assessment Quality Report')).toBeInTheDocument();
      expect(within(row).getByText(/CSE101 · Midterm · v3.0/)).toBeInTheDocument();
      expect(within(row).getByText('PDF', { selector: 'td' })).toBeInTheDocument();
      expect(within(row).getByTestId('report-status')).toHaveTextContent('Completed');
      expect(within(row).getByRole('button', { name: /download pdf/i })).toBeEnabled();
      const failed = screen.getByTestId('report-row-43');
      expect(within(failed).getByTestId('report-status')).toHaveTextContent('Failed');
      expect(within(failed).getByRole('button', { name: /download csv/i })).toBeDisabled();
    });

    it('downloads through the service and asks for confirmation before deleting', async () => {
      svc.getReports.mockResolvedValue({ status: 'success', data: { items: [report()], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
      svc.downloadReport.mockResolvedValue(undefined);
      svc.deleteReport.mockResolvedValue({ status: 'success', data: null });
      render(<MemoryRouter><InstitutionalReports /></MemoryRouter>);
      await waitFor(() => expect(screen.getByTestId('report-row-42')).toBeInTheDocument());
      fireEvent.click(screen.getByRole('button', { name: /download pdf/i }));
      await waitFor(() => expect(svc.downloadReport).toHaveBeenCalledWith(expect.objectContaining({ id: 42, format: 'PDF' })));

      fireEvent.click(screen.getByRole('button', { name: /delete report 42/i }));
      expect(screen.getByRole('dialog')).toBeInTheDocument();
      fireEvent.click(screen.getByRole('button', { name: /no, keep it/i }));
      expect(svc.deleteReport).not.toHaveBeenCalled();
      fireEvent.click(screen.getByRole('button', { name: /delete report 42/i }));
      fireEvent.click(screen.getByRole('button', { name: /yes, delete/i }));
      await waitFor(() => expect(svc.deleteReport).toHaveBeenCalledWith(42));
    });

    it('shows a retryable error when loading fails', async () => {
      svc.getReports.mockRejectedValueOnce(new Error('Reports could not be loaded.')).mockResolvedValueOnce({ status: 'success', data: { items: [], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } });
      render(<MemoryRouter><InstitutionalReports /></MemoryRouter>);
      await waitFor(() => expect(screen.getByTestId('report-error')).toHaveTextContent('Reports could not be loaded.'));
      fireEvent.click(screen.getByRole('button', { name: /retry/i }));
      await waitFor(() => expect(screen.getByTestId('report-empty-state')).toBeInTheDocument());
    });
  });

  describe('Report builder (/reports/create)', () => {
    it('drives type → scope → filters → preview → format → generate', async () => {
      svc.previewReport.mockResolvedValue({ status: 'success', data: preview });
      svc.createReport.mockResolvedValue({ status: 'success', data: report() });
      renderBuilder();
      await waitFor(() => expect(screen.getByLabelText(/report type/i)).toBeInTheDocument());

      // Only authorized types are offered
      expect(screen.getAllByRole('option').map((o) => o.textContent)).not.toContain('Institutional Summary Report');
      const generate = screen.getByRole('button', { name: /generate report/i });
      expect(generate).toBeDisabled();

      fireEvent.change(screen.getByLabelText(/report type/i), { target: { value: 'ASSESSMENT_QUALITY' } });
      expect(screen.getByText(/STEP 13 quality dimensions/i)).toBeInTheDocument();
      fireEvent.change(screen.getByLabelText(/^scope/i), { target: { value: 'ASSESSMENT_VERSION' } });
      // Applicable filters only
      expect(screen.getByLabelText(/^course/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/^assessment\b/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/^version/i)).toBeInTheDocument();
      expect(screen.queryByLabelText(/semester/i)).not.toBeInTheDocument();

      // Client-side required validation before hitting the API
      fireEvent.click(screen.getByRole('button', { name: /preview report/i }));
      expect(screen.getAllByRole('alert').length).toBeGreaterThan(0);
      expect(svc.previewReport).not.toHaveBeenCalled();

      fireEvent.change(screen.getByLabelText(/^course/i), { target: { value: '10' } });
      fireEvent.change(screen.getByLabelText(/^assessment\b/i), { target: { value: '5' } });
      fireEvent.change(screen.getByLabelText(/^version/i), { target: { value: '3' } });
      fireEvent.click(screen.getByRole('button', { name: /preview report/i }));

      await waitFor(() => expect(screen.getByTestId('report-preview')).toBeInTheDocument());
      expect(svc.previewReport).toHaveBeenCalledWith({ report_type: 'ASSESSMENT_QUALITY', scope_type: 'ASSESSMENT_VERSION', filters: { course_id: '10', assessment_id: '5', assessment_version_id: '3' } });
      expect(screen.getByText(/7 records/)).toBeInTheDocument();
      expect(screen.getByTestId('report-metadata')).toHaveTextContent('v3.0');
      expect(screen.getByTestId('report-metadata')).toHaveTextContent('Dr. Rashid');
      expect(screen.getByTestId('report-summary')).toHaveTextContent('Average Overall Quality');
      expect(screen.getByTestId('report-table-quality')).toHaveTextContent('GOOD');
      expect(screen.getByText(/no completed analysis and are excluded/i)).toBeInTheDocument();

      fireEvent.click(screen.getByRole('radio', { name: /xlsx/i }));
      const gen = screen.getByRole('button', { name: /generate report/i });
      expect(gen).toBeEnabled();
      fireEvent.click(gen);
      await waitFor(() => expect(svc.createReport).toHaveBeenCalledWith({ report_type: 'ASSESSMENT_QUALITY', scope_type: 'ASSESSMENT_VERSION', filters: { course_id: '10', assessment_id: '5', assessment_version_id: '3' }, format: 'XLSX' }));
      await waitFor(() => expect(screen.getByText('Details page')).toBeInTheDocument());
    });

    it('blocks generation when the preview has no data', async () => {
      svc.previewReport.mockResolvedValue({ status: 'success', data: { ...preview, has_data: false, record_count: 0, tables: [], warnings: ['No completed quality analysis exists for the selected scope.'] } });
      renderBuilder();
      await waitFor(() => expect(screen.getByLabelText(/report type/i)).toBeInTheDocument());
      fireEvent.change(screen.getByLabelText(/report type/i), { target: { value: 'ASSESSMENT_QUALITY' } });
      fireEvent.change(screen.getByLabelText(/^scope/i), { target: { value: 'FACULTY' } });
      fireEvent.click(screen.getByRole('button', { name: /preview report/i }));
      await waitFor(() => expect(screen.getByText(/no data available for the selected filters/i)).toBeInTheDocument());
      expect(screen.getByRole('button', { name: /generate report/i })).toBeDisabled();
    });

    it('renders the unauthorized notice on 403 and safe errors on 422', async () => {
      svc.previewReport.mockRejectedValueOnce(new ApiError(403, 'You are not authorized to generate this report.'));
      renderBuilder();
      await waitFor(() => expect(screen.getByLabelText(/report type/i)).toBeInTheDocument());
      fireEvent.change(screen.getByLabelText(/report type/i), { target: { value: 'STUDENT_PERFORMANCE' } });
      expect(screen.getByText(/aggregated only; requires student-data access/i)).toBeInTheDocument();
      fireEvent.change(screen.getByLabelText(/^scope/i), { target: { value: 'COURSE' } });
      fireEvent.change(screen.getByLabelText(/^course/i), { target: { value: '10' } });
      fireEvent.click(screen.getByRole('button', { name: /preview report/i }));
      await waitFor(() => expect(screen.getByTestId('report-access-notice')).toHaveTextContent('You are not authorized to generate this report.'));

      svc.previewReport.mockRejectedValueOnce(new ApiError(422, 'The date range is invalid: the start date is after the end date.', {}, { start_date: ['Start date must be before the end date.'] }));
      fireEvent.change(screen.getByLabelText(/^scope/i), { target: { value: 'FACULTY' } });
      fireEvent.click(screen.getByRole('button', { name: /preview report/i }));
      await waitFor(() => expect(screen.getByTestId('report-error')).toHaveTextContent('The date range is invalid'));
      expect(screen.getByText('Start date must be before the end date.')).toBeInTheDocument();
    });
  });

  describe('Report details (/reports/:id)', () => {
    const renderDetails = () => render(<MemoryRouter initialEntries={['/reports/42']}><Routes><Route path="/reports/:reportId" element={<ReportDetails />} /><Route path="/reports" element={<div>List page</div>} /></Routes></MemoryRouter>);

    it('polls while processing, then shows the completed report with download', async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
      svc.getReport.mockResolvedValueOnce({ status: 'success', data: report({ status: 'PROCESSING', is_async: true, downloadable: false, generated_at: null, file_name: null, file_size: null, summary: null }) })
        .mockResolvedValue({ status: 'success', data: report() });
      renderDetails();
      await waitFor(() => expect(screen.getByText(/generating report/i)).toBeInTheDocument());
      expect(screen.getByRole('button', { name: /download pdf/i })).toBeDisabled();
      await vi.advanceTimersByTimeAsync(3100);
      await waitFor(() => expect(screen.getAllByTestId('report-status')[0]).toHaveTextContent('Completed'));
      expect(screen.getByRole('button', { name: /download pdf/i })).toBeEnabled();
      expect(screen.getByTestId('report-metadata')).toHaveTextContent('v3.0');
      expect(screen.getByText('FacultyLens_Quality.pdf')).toBeInTheDocument();
      vi.useRealTimers();
    });

    it('shows failed and expired states without exposing internals', async () => {
      svc.getReport.mockResolvedValue({ status: 'success', data: report({ status: 'FAILED', downloadable: false, error_message: 'Report generation failed. Please try again.' }) });
      renderDetails();
      await waitFor(() => expect(screen.getByTestId('report-error')).toHaveTextContent('Report generation failed. Please try again.'));
    });

    it('marks expired reports and offers regeneration', async () => {
      svc.getReport.mockResolvedValue({ status: 'success', data: report({ is_expired: true, downloadable: false }) });
      renderDetails();
      await waitFor(() => expect(screen.getAllByTestId('report-status')[0]).toHaveTextContent('Expired'));
      expect(screen.getByText(/this report expired on/i)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /download pdf/i })).toBeDisabled();
      expect(screen.getByRole('button', { name: /regenerate/i })).toBeInTheDocument();
    });

    it('deletes after confirmation and returns to the list', async () => {
      svc.getReport.mockResolvedValue({ status: 'success', data: report() });
      svc.deleteReport.mockResolvedValue({ status: 'success', data: null });
      renderDetails();
      await waitFor(() => expect(screen.getByRole('button', { name: /^delete$/i })).toBeInTheDocument());
      fireEvent.click(screen.getByRole('button', { name: /^delete$/i }));
      fireEvent.click(screen.getByRole('button', { name: /yes, delete/i }));
      await waitFor(() => expect(svc.deleteReport).toHaveBeenCalledWith(42));
      await waitFor(() => expect(screen.getByText('List page')).toBeInTheDocument());
    });
  });
});
