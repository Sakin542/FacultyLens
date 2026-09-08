import { describe, it, expect, vi, beforeEach } from 'vitest';
import { authService } from '@/services/authService';
import { courseService } from '@/services/courseService';
import { reportService } from '@/services/reportService';
import { feedbackService } from '@/services/feedbackService';
import { ApiError } from '@/services/api';

const mockFetch = (response: object) =>
  vi.fn().mockResolvedValue(response);

describe('Frontend API Services', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  describe('authService', () => {
    it('successfully fetches authenticated current user', async () => {
      const mockUser = {
        id: 1,
        name: 'Prof. Ada Lovelace',
        email: 'ada@computing.edu',
        role: 'FACULTY' as const,
      };

      const fetchSpy = mockFetch({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: async () => ({ status: 'success', user: mockUser }),
      });
      vi.stubGlobal('fetch', fetchSpy);

      const res = await authService.getCurrentUser();
      expect(res.user).toEqual(mockUser);
      expect(fetchSpy).toHaveBeenCalled();
    });

    it('throws ApiError on 401 unauthenticated request', async () => {
      vi.stubGlobal('fetch', mockFetch({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        headers: { get: () => 'application/json' },
        json: async () => ({ message: 'Unauthenticated.' }),
      }));

      await expect(authService.getCurrentUser()).rejects.toThrow(ApiError);
    });
  });

  describe('courseService', () => {
    it('retrieves courses array for authenticated faculty', async () => {
      const mockCourses = [
        { id: 1, course_code: 'CSE101', course_name: 'Database Systems' },
      ];

      vi.stubGlobal('fetch', mockFetch({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: async () => ({ data: mockCourses }),
      }));

      const courses = await courseService.getAll();
      expect(courses.data).toEqual(mockCourses);
    });

    it('handles 403 Forbidden without crashing', async () => {
      vi.stubGlobal('fetch', mockFetch({
        ok: false,
        status: 403,
        headers: { get: () => 'application/json' },
        json: async () => ({ message: 'Forbidden' }),
      }));

      await expect(courseService.getById(999)).rejects.toThrow(ApiError);
    });
  });

  describe('feedbackService', () => {
    it('submits recommendation feedback decision correctly', async () => {
      const mockResult = {
        status: 'success',
        success: true,
        data: {
          recommendation: { id: 10 },
          feedback: { id: 101, recommendation_id: 10, decision: 'ACCEPTED' as const, usefulness_rating: 5 },
        },
      };

      vi.stubGlobal('fetch', mockFetch({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: async () => mockResult,
      }));

      const res = await feedbackService.submitFeedback(10, {
        decision: 'ACCEPTED',
        usefulness_rating: 5,
        reason: 'WILL_IMPLEMENT',
        comment: 'Great suggestions on normalization.',
      });

      expect(res.feedback.decision).toBe('ACCEPTED');
      expect(res.feedback.usefulness_rating).toBe(5);
    });

    it('rejects with validation error message on 422 response', async () => {
      vi.stubGlobal('fetch', mockFetch({
        ok: false,
        status: 422,
        headers: { get: () => 'application/json' },
        json: async () => ({
          message: 'The usefulness_rating field must not be greater than 5.',
          errors: { usefulness_rating: ['The usefulness_rating field must not be greater than 5.'] },
        }),
      }));

      await expect(
        feedbackService.submitFeedback(10, { decision: 'ACCEPTED', usefulness_rating: 10 })
      ).rejects.toThrow(ApiError);
    });
  });

  describe('reportService', () => {
    it('generates assessment PDF report', async () => {
      const mockReportData = {
        status: 'success',
        message: 'Report generated successfully.',
        data: {
          report: { report_uuid: 'uuid-1234', file_name: 'report.pdf' },
          download_url: '/api/v1/assessments/1/report/download',
        },
      };

      vi.stubGlobal('fetch', mockFetch({
        ok: true,
        status: 201,
        headers: { get: () => 'application/json' },
        json: async () => mockReportData,
      }));

      const res = await reportService.generatePdfReport(1);
      expect(res.report.file_name).toBe('report.pdf');
    });
  });
});

