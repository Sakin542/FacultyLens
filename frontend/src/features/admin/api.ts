import { apiClient, API_BASE_URL } from '@/services/api';
import type {
  AccessData, AdminAlert, AdminOperation, AdminUser, AdminUserDetail, AiGovernanceData, AiUsageReport, AssignableRole, AuditEntry,
  BackupMeta, BackupRecord, DashboardData, EmailDeliveryRow, EmailSummary, EnvironmentData, FailedJob, Finding, FindingMeta, HealthData,
  ImportPreview, Incident, IncidentDetail, InstitutionCourse, InstitutionData, MaintenanceWindow, Paged, QueueSummary, ScheduledTask,
  SearchGroup, SecurityData, ServiceCheck, SettingsData,
} from './types';

interface Envelope<T> { status: string; message?: string; data: T }
type Query = Record<string, string | number | boolean | undefined | null>;

export const qs = (params: Query = {}) => {
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '');
  return entries.length ? `?${new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString()}` : '';
};

const get = <T>(path: string, params?: Query) => apiClient<Envelope<T>>(`/admin${path}${qs(params)}`).then((r) => r.data);
const page = <T, M = Record<string, unknown>>(path: string, params?: Query) => apiClient<Paged<T, M>>(`/admin${path}${qs(params)}`);
const send = <T>(method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', path: string, body?: unknown) =>
  apiClient<Envelope<T>>(`/admin${path}`, { method, body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body) });

/** Typed client for /api/admin. Every endpoint is authorized server-side by the `admin` middleware. */
export const adminApi = {
  dashboard: (days: number, refresh = false) => get<DashboardData>('/overview', { days, refresh: refresh ? 1 : undefined }),
  search: (q: string) => get<SearchGroup[]>('/search', { q }),

  users: (params: Query) => page<AdminUser>('/users', params),
  user: (id: number) => get<AdminUserDetail>(`/users/${id}`),
  createUser: (body: { name: string; email: string; department?: string; designation?: string; role?: AssignableRole; send_invite?: boolean }) => send<AdminUser>('POST', '/users', body),
  updateUser: (id: number, body: Partial<{ role: AssignableRole; is_active: boolean; name: string; department: string | null; designation: string | null; reason: string }>) => send<AdminUser>('PATCH', `/users/${id}`, body),
  bulkUsers: (body: { ids: number[]; action: 'activate' | 'deactivate' | 'set_role' | 'set_department'; role?: AssignableRole; department?: string; reason?: string; confirm: true }) =>
    send<{ updated: number; unchanged: number; skipped: number[]; not_found: number[] }>('POST', '/users/bulk', body),
  revokeSessions: (id: number) => send<{ tokens: number; sessions: number }>('POST', `/users/${id}/revoke-sessions`),
  sendPasswordReset: (id: number) => send<null>('POST', `/users/${id}/password-reset`),
  resetTwoFactor: (id: number) => send<AdminUser>('DELETE', `/users/${id}/two-factor`),
  importPreview: (file: File) => { const f = new FormData(); f.append('file', file); return send<ImportPreview>('POST', '/users/import/preview', f); },
  importUsers: (file: File, sendInvites: boolean) => {
    const f = new FormData(); f.append('file', file); f.append('send_invites', sendInvites ? '1' : '0'); f.append('confirm', '1');
    return send<AdminOperation>('POST', '/users/import', f);
  },

  access: () => get<AccessData>('/access'),
  auditLogs: (params: Query) => page<AuditEntry>('/audit-logs', params),
  auditFacets: () => get<{ actions: string[]; entity_types: string[] }>('/audit-logs/facets'),
  security: (days: number) => get<SecurityData>('/security', { days }),

  institution: () => get<InstitutionData>('/institution'),
  institutionCourses: (params: Query) => page<InstitutionCourse>('/institution/courses', params),

  health: (hours: number, live = false) => get<HealthData>('/system/health', { hours, live: live ? 1 : undefined }),
  runHealthCheck: () => send<{ operation: AdminOperation; services: ServiceCheck[] }>('POST', '/system/health/check'),
  environment: () => get<EnvironmentData>('/system/environment'),
  schedule: () => get<{ tasks: ScheduledTask[]; heartbeat_at: string | null; timezone: string }>('/system/schedule'),

  queues: () => get<QueueSummary>('/queues'),
  failedJobs: (params: Query) => page<FailedJob>('/queues/failed', params),
  retryJob: (uuid: string) => send<null>('POST', `/queues/failed/${uuid}/retry`),
  forgetJob: (uuid: string) => send<null>('DELETE', `/queues/failed/${uuid}`),

  email: (days: number) => get<EmailSummary>('/email', { days }),
  emailDeliveries: (params: Query) => page<EmailDeliveryRow>('/email/deliveries', params),

  alerts: (params: Query) => page<AdminAlert, { counts: Record<string, number>; rules: Record<string, number> }>('/alerts', params),
  acknowledgeAlert: (id: number) => send<AdminAlert>('POST', `/alerts/${id}/acknowledge`),
  resolveAlert: (id: number, note: string) => send<AdminAlert>('POST', `/alerts/${id}/resolve`, { note }),
  escalateAlert: (id: number, incidentId?: number) => send<{ incident_id: number }>('POST', `/alerts/${id}/escalate`, incidentId ? { incident_id: incidentId } : {}),

  incidents: (params: Query) => page<Incident, { counts: Record<string, number>; mean_minutes_to_resolve_90d: number | null; options: { categories: string[]; severities: string[]; statuses: string[] } }>('/incidents', params),
  incident: (id: number) => get<IncidentDetail>(`/incidents/${id}`),
  createIncident: (body: { title: string; category: string; severity: string; service?: string; summary?: string }) => send<IncidentDetail>('POST', '/incidents', body),
  updateIncident: (id: number, body: Partial<Pick<IncidentDetail, 'title' | 'status' | 'severity' | 'category' | 'service' | 'summary' | 'root_cause' | 'corrective_actions'>>) => send<IncidentDetail>('PATCH', `/incidents/${id}`, body),
  addIncidentNote: (id: number, body: string) => send<IncidentDetail>('POST', `/incidents/${id}/notes`, { body }),

  operations: (params: Query) => page<AdminOperation, { types: Record<string, number>; datasets: { key: string; label: string }[]; ttl_hours: number }>('/operations', params),
  createExport: (dataset: string, from?: string, to?: string) => send<AdminOperation>('POST', '/exports', { dataset, from: from || undefined, to: to || undefined }),
  operationDownloadUrl: (id: number) => `${API_BASE_URL}/admin/operations/${id}/download`,

  maintenance: () => apiClient<{ data: MaintenanceWindow[]; meta: { services: { key: string; label: string }[]; timezone: string } }>('/admin/maintenance'),
  createMaintenance: (body: { title: string; description?: string; services: string[]; starts_at: string; ends_at: string; suppress_alerts: boolean }) => send<MaintenanceWindow>('POST', '/maintenance', body),
  cancelMaintenance: (id: number) => send<MaintenanceWindow>('POST', `/maintenance/${id}/cancel`),

  dataQuality: (params: Query) => page<Finding, FindingMeta>('/data-quality', params),
  runScan: () => send<AdminOperation>('POST', '/data-quality/scan'),
  updateFinding: (id: number, status: Finding['status'], note?: string) => send<Finding>('PATCH', `/data-quality/${id}`, { status, note }),

  ai: () => get<AiGovernanceData>('/ai'),
  aiUsage: (days: number) => get<AiUsageReport>('/ai-usage', { days }),

  settings: () => get<SettingsData>('/settings'),
  updateSettings: (features: Record<string, boolean | string>, reason?: string) => send<{ features: SettingsData['features'] }>('PUT', '/settings', { features, reason }),

  backups: () => apiClient<{ data: BackupRecord[]; meta: BackupMeta }>('/admin/backups'),
  createBackup: () => send<BackupRecord>('POST', '/backups'),
  verifyBackup: (id: number) => send<BackupRecord>('POST', `/backups/${id}/verify`),
  deleteBackup: (id: number) => send<null>('DELETE', `/backups/${id}`),
  backupDownloadUrl: (id: number) => `${API_BASE_URL}/admin/backups/${id}/download`,
};
