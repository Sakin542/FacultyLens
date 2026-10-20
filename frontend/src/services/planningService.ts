import { apiClient, API_BASE_URL, getCookie } from './api';
import type { CalendarEvent, DepartmentOverview, EventType, ObeSummary, QualityTrend, Workload } from '@/types/planning';

interface Envelope<T> { status: string; message?: string; data: T }

export interface EventPayload {
  title: string;
  type: EventType;
  starts_at: string;
  ends_at?: string | null;
  all_day?: boolean;
  location?: string | null;
  notes?: string | null;
  course_id?: number | null;
  assessment_id?: number | null;
  reminder_offsets?: number[];
}

/** Session-authenticated file download that keeps the server's filename and surfaces errors as messages. */
export async function downloadFile(path: string, fallbackName: string): Promise<void> {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'include',
    headers: { Accept: '*/*', ...(getCookie('XSRF-TOKEN') ? { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') as string } : {}) },
  });
  if (!res.ok) {
    let message = 'The download failed.';
    try { message = (await res.json()).message ?? message; } catch { /* not JSON */ }
    throw new Error(message);
  }
  const blob = await res.blob();
  const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export const planningService = {
  qualityTrend: (courseId: number | string) => apiClient<Envelope<QualityTrend>>(`/courses/${courseId}/quality-trend`).then((r) => r.data),
  obe: (courseId: number | string) => apiClient<Envelope<ObeSummary>>(`/courses/${courseId}/obe`).then((r) => r.data),
  downloadObePack: (courseId: number | string) => downloadFile(`/courses/${courseId}/obe-pack`, 'obe-pack.zip'),
  downloadArchive: (courseId: number | string) => downloadFile(`/courses/${courseId}/archive`, 'course-file.zip'),
  workload: () => apiClient<Envelope<Workload>>('/workload').then((r) => r.data),
  department: () => apiClient<Envelope<DepartmentOverview>>('/department/overview').then((r) => r.data),

  events: (from: string, to: string) => apiClient<Envelope<CalendarEvent[]>>(`/calendar/events?from=${from}&to=${to}`).then((r) => r.data),
  createEvent: (payload: EventPayload) => apiClient<Envelope<CalendarEvent>>('/calendar/events', { method: 'POST', body: JSON.stringify(payload) }).then((r) => r.data),
  updateEvent: (id: number, payload: Partial<EventPayload>) => apiClient<Envelope<CalendarEvent>>(`/calendar/events/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }).then((r) => r.data),
  deleteEvent: (id: number) => apiClient<{ message: string }>(`/calendar/events/${id}`, { method: 'DELETE' }),
  downloadIcs: () => downloadFile('/calendar/export.ics', 'facultylens-calendar.ics'),
};
