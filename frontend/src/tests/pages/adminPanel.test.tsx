import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AdminLogin } from '@/pages/admin/AdminLogin';
import { adminRoutes } from '@/features/admin/routes';
import { AuthContext } from '@/context/AuthContext';
import { ProtectedRoute } from '@/components/common/ProtectedRoute';
import { ApiError } from '@/services/api';
import type { DashboardData, HealthData } from '@/features/admin/types';
import type { User } from '@/types';

vi.mock('@/features/admin/api', () => ({
  qs: () => '',
  adminApi: new Proxy({} as Record<string, unknown>, {
    get: (target, key: string) => {
      if (!(key in target)) target[key] = key.endsWith('Url') ? (id: number) => `/download/${id}` : vi.fn();
      return target[key];
    },
  }),
}));
vi.mock('@/services/notificationService', () => ({
  notificationService: {
    getUnreadCount: vi.fn().mockResolvedValue({ status: 'success', data: { unread_count: 3 } }),
    getNotifications: vi.fn().mockResolvedValue({ status: 'success', data: [], meta: {} }),
    markAsRead: vi.fn(), markAllAsRead: vi.fn().mockResolvedValue({}),
  },
}));

import { adminApi } from '@/features/admin/api';
const api = adminApi as unknown as Record<string, ReturnType<typeof vi.fn>>;

const admin: User = { id: 1, name: 'Rashid', email: 'rashid.cse.20230104102@aust.edu', department: 'Administration', designation: 'System Administrator', role: 'ADMIN', is_admin: true };
const faculty: User = { id: 2, name: 'Dr. Doe', email: 'faculty@example.com', department: 'CSE', designation: 'Lecturer', role: 'FACULTY', is_admin: false };

const authValue = (user: User | null, extra: Record<string, unknown> = {}) => ({
  user, isAuthenticated: Boolean(user), loading: false,
  login: vi.fn(), adminLogin: vi.fn(), googleLogin: vi.fn(), completeTwoFactor: vi.fn(),
  register: vi.fn(), logout: vi.fn(), refreshUser: vi.fn(), updateUser: vi.fn(), ...extra,
});

const period = (current: number, previous = 0) => ({ current, previous });
const days = (n: number) => Array.from({ length: n }, (_, i) => ({ date: `2026-10-${String(10 + i).padStart(2, '0')}`, total: i, ok: i, failed: 0 }));

const dashboard: DashboardData = {
  range: { days: 30, start: '2026-09-19T00:00:00Z', end: '2026-10-19T00:00:00Z', previous_start: '2026-08-20T00:00:00Z' },
  kpis: {
    users_total: { value: 12 }, active_faculty: { value: 10 }, suspended_accounts: { value: 1 }, new_users: period(4, 2), unverified_accounts: { value: 2 },
    departments: { value: 3 }, courses: { value: 9, new: period(1) }, assessments: { value: 21, new: period(5) }, analyses_completed: period(7, 3),
    analyses_failed: period(1), ai_calls: period(48, 40), ai_availability: { value: null, checks: 0 },
    documents: { by_status: { uploaded: 1, processing: 0, completed: 12, failed: 1 } }, pending_jobs: { value: null, as_of: null },
    failed_jobs: period(2), email_failures: period(0), unread_alerts: { value: 3 }, open_alerts: { value: 1 }, open_incidents: { value: 0 },
    data_quality_open: { value: 5, last_scan_at: null }, last_backup: { value: null, verification_status: null }, integrations_healthy: { value: 0, of: 0 },
  },
  series: { new_users: days(8), ai_calls: days(8), analyses: days(8), emails: days(8), failed_jobs: days(8), admin_activity: days(8) },
  account_status: [{ role: 'FACULTY', active: 10, deactivated: 1 }],
  services: [],
  critical_alerts: [{ id: 9, rule: 'service_unavailable', severity: 'critical', service: 'ai_service', title: 'AI service (FastAPI) unavailable', message: 'Health endpoint returned HTTP 503.', status: 'open', occurrences: 2, last_value: null, suppressed: false, notified: true, first_seen_at: null, last_seen_at: null, acknowledged_by: null, acknowledged_at: null, resolved_by: null, resolved_at: null, resolution_note: null, incident_id: null }],
  recent_activity: [{ id: 1, action: 'ADMIN_USER_SUSPENDED', entity_type: 'User', entity_id: 5, user: { id: 1, name: 'Rashid' }, created_at: new Date().toISOString() }],
  freshness: { generated_at: new Date().toISOString(), health_snapshot_at: null, data_quality_scan_at: null, scheduler_heartbeat_at: null },
};

const renderAt = (path: string, user: User | null, extra: Record<string, unknown> = {}) => render(
  <AuthContext.Provider value={authValue(user, extra)}>
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/login" element={<AdminLogin />} />
        {adminRoutes}
        <Route path="/dashboard" element={<p>faculty dashboard</p>} />
      </Routes>
    </MemoryRouter>
  </AuthContext.Provider>,
);

const user = (over: Record<string, unknown> = {}) => ({
  id: 5, name: 'Dr. Karim', email: 'karim@aust.edu', role: 'FACULTY', is_admin: false, is_active: true, department: 'EEE', designation: 'Lecturer',
  courses_count: 2, two_factor_enabled: false, google_linked: false, email_verified: true, last_login_at: null, created_at: null, profile_picture_url: null, ...over,
});

describe('Admin control center', () => {
  beforeEach(() => { vi.clearAllMocks(); try { localStorage.clear(); } catch { /* ignore */ } });

  it('redirects faculty and guests to the admin sign-in', async () => {
    renderAt('/admin', faculty);
    expect(await screen.findByTestId('admin-login-faculty-notice')).toBeInTheDocument();
    expect(api.dashboard).not.toHaveBeenCalled();
  });

  it('renders its own shell with grouped navigation and honest dashboard figures', async () => {
    api.dashboard.mockResolvedValue(dashboard);
    renderAt('/admin', admin);

    expect(await screen.findByTestId('admin-layout')).toBeInTheDocument();
    const nav = screen.getAllByRole('navigation', { name: 'Admin sections' })[0];
    for (const group of ['People & access', 'Operations', 'Data & AI']) expect(within(nav).getByText(group)).toBeInTheDocument();
    // The admin sidebar carries no faculty workspace links
    for (const facultyItem of ['Analytics', 'Calendar', 'Workload', 'Courses', 'Assessments', 'Question Bank', 'Comment Bank', 'History',
      'Analysis', 'Question Generator', 'Document Chat', 'Research Papers', 'Collaboration', 'Notifications', 'Feedback', 'Department']) {
      expect(within(nav).queryByRole('link', { name: facultyItem })).not.toBeInTheDocument();
    }
    expect(screen.queryByRole('navigation', { name: 'Main' })).not.toBeInTheDocument(); // the faculty sidebar is not rendered
    expect(within(nav).getByRole('link', { name: 'AI evaluation' })).toHaveAttribute('href', '/admin/ai-evaluation');

    expect(await screen.findByTestId('kpi-users')).toHaveTextContent('12');
    expect(screen.getByTestId('kpi-users')).toHaveTextContent('+2 (100%)');
    expect(screen.getByText('Not measured')).toBeInTheDocument(); // AI availability without recorded checks is not shown as 100%
    expect(screen.getByText('AI service (FastAPI) unavailable')).toBeInTheDocument();
    expect(screen.getByTestId('dashboard-services')).toHaveTextContent('No health check recorded yet');
    expect(screen.getByTestId('chart-ai-calls')).toBeInTheDocument();
    expect(await screen.findByLabelText(/3 unread/)).toBeInTheDocument();
    expect(api.dashboard).toHaveBeenCalledWith(30, false);
  });

  it('changes the date range and re-queries', async () => {
    api.dashboard.mockResolvedValue(dashboard);
    renderAt('/admin', admin);
    await screen.findByTestId('kpi-users');
    fireEvent.click(screen.getByRole('radio', { name: '7 days' }));
    await waitFor(() => expect(api.dashboard).toHaveBeenCalledWith(7, false));
  });

  it('explains a 403 instead of showing a blank page', async () => {
    api.dashboard.mockRejectedValue(new ApiError(403, 'Forbidden'));
    renderAt('/admin', admin);
    expect(await screen.findByText('Not permitted')).toBeInTheDocument();
  });

  it('opens the command palette with Ctrl+K and filters pages', async () => {
    api.dashboard.mockResolvedValue(dashboard);
    api.search.mockResolvedValue([]);
    renderAt('/admin', admin);
    await screen.findByTestId('kpi-users');

    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const input = await screen.findByTestId('palette-input');
    fireEvent.change(input, { target: { value: 'backup' } });
    const list = screen.getByRole('listbox');
    expect(within(list).getByText('Backup & recovery')).toBeInTheDocument();
    expect(within(list).queryByText('Queues & jobs')).not.toBeInTheDocument();
  });

  it('requires a reason before bulk-suspending accounts', async () => {
    api.users.mockResolvedValue({ data: [user(), user({ id: 6, name: 'Dr. Lina', email: 'lina@aust.edu' })], meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 } });
    api.bulkUsers.mockResolvedValue({ status: 'success', message: '2 account(s) updated.', data: { updated: 2, unchanged: 0, skipped: [], not_found: [] } });
    renderAt('/admin/users', admin);

    fireEvent.click(await screen.findByLabelText('Select all rows on this page'));
    fireEvent.click(within(screen.getByTestId('bulk-bar')).getByRole('button', { name: 'Suspend' }));
    const confirm = screen.getByTestId('confirm-action');
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Reason for suspension'), { target: { value: 'Contract ended' } });
    fireEvent.click(confirm);

    await waitFor(() => expect(api.bulkUsers).toHaveBeenCalledWith(expect.objectContaining({ ids: [5, 6], action: 'deactivate', confirm: true, reason: 'Contract ended' })));
    expect(await screen.findByText('2 account(s) updated.')).toBeInTheDocument();
  });

  it('previews a CSV import before creating accounts', async () => {
    api.users.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });
    api.importPreview.mockResolvedValue({ status: 'success', data: { columns: ['name', 'email'], missing_columns: [], valid: 1, invalid: 1, truncated: false, rows: [
      { line: 2, name: 'Alice', email: 'alice@aust.edu', department: null, designation: null, role: 'FACULTY', errors: [] },
      { line: 3, name: 'Bob', email: 'bad', department: null, designation: null, role: 'FACULTY', errors: ['E-mail address is invalid.'] },
    ] } });
    api.importUsers.mockResolvedValue({ status: 'success', message: '1 account(s) created, 1 row(s) skipped.', data: {} });
    renderAt('/admin/users', admin);

    fireEvent.click(await screen.findByRole('button', { name: 'Import CSV' }));
    const file = new File(['name,email\nAlice,alice@aust.edu\nBob,bad'], 'staff.csv', { type: 'text/csv' });
    fireEvent.change(screen.getByTestId('import-file'), { target: { files: [file] } });

    expect(await screen.findByTestId('import-preview')).toHaveTextContent('E-mail address is invalid.');
    expect(api.importUsers).not.toHaveBeenCalled();
    fireEvent.click(screen.getByTestId('import-commit'));
    await waitFor(() => expect(api.importUsers).toHaveBeenCalledWith(file, true));
    expect(await screen.findByText(/1 account\(s\) created/)).toBeInTheDocument();
  });

  it('shows explicit service states on the health page', async () => {
    const health: HealthData = {
      checked_at: new Date().toISOString(), live: false, hours: 24, scheduler_heartbeat_at: null,
      services: [
        { service: 'database', label: 'Database', group: 'Core', status: 'healthy', response_ms: 3, detail: { driver: 'mysql' }, summary: null },
        { service: 'mail', label: 'E-mail delivery', group: 'Messaging', status: 'unconfigured', response_ms: null, detail: {}, summary: null },
        { service: 'scheduler', label: 'Task scheduler', group: 'Background processing', status: 'unknown', response_ms: null, detail: {}, summary: null },
      ],
      history: {},
    };
    api.health.mockResolvedValue(health);
    renderAt('/admin/health', admin);

    expect(await screen.findByTestId('service-mail')).toHaveTextContent('Unconfigured');
    expect(screen.getByTestId('service-scheduler')).toHaveTextContent('Unknown');
    expect(screen.getByTestId('service-database')).toHaveTextContent('Healthy');
  });

  it('saves only changed feature flags after confirmation', async () => {
    api.settings.mockResolvedValue({ google_configured: true, history: [], features: [
      { key: 'registration', label: 'Faculty self-registration', description: 'x', type: 'boolean', value: true, default: true },
      { key: 'announcement', label: 'Announcement banner', description: 'y', type: 'string', value: '', default: '' },
    ] });
    api.updateSettings.mockResolvedValue({ status: 'success', message: 'Settings saved.', data: { features: [] } });
    renderAt('/admin/settings', admin);

    fireEvent.click(await screen.findByTestId('flag-registration'));
    fireEvent.click(screen.getByRole('button', { name: /Save \(1\)/ }));
    fireEvent.click(screen.getByTestId('confirm-action'));

    await waitFor(() => expect(api.updateSettings).toHaveBeenCalledWith({ registration: false }, undefined));
    expect(await screen.findByText('Settings saved.')).toBeInTheDocument();
  });

  it('keeps the administrator out of the faculty workspace', async () => {
    api.dashboard.mockResolvedValue(dashboard);
    render(
      <AuthContext.Provider value={authValue(admin)}>
        <MemoryRouter initialEntries={['/courses']}>
          <Routes>
            {adminRoutes}
            <Route element={<ProtectedRoute />}>
              <Route path="/courses" element={<p>faculty courses</p>} />
              <Route path="/settings" element={<p>faculty settings</p>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>,
    );
    expect(await screen.findByTestId('admin-layout')).toBeInTheDocument();
    expect(screen.queryByText('faculty courses')).not.toBeInTheDocument();
    expect(await screen.findByTestId('kpi-users')).toBeInTheDocument();
  });

  it('lets faculty keep using faculty pages', async () => {
    render(
      <AuthContext.Provider value={authValue(faculty)}>
        <MemoryRouter initialEntries={['/courses']}>
          <Routes><Route element={<ProtectedRoute />}><Route path="/courses" element={<p>faculty courses</p>} /></Route></Routes>
        </MemoryRouter>
      </AuthContext.Provider>,
    );
    expect(await screen.findByText('faculty courses')).toBeInTheDocument();
  });

  it('lists modules that are intentionally not built', async () => {
    renderAt('/admin/help', admin);
    expect(await screen.findByText(/Approval workflows, access reviews/)).toBeInTheDocument();
  });

  it('admin sign-in asks for the 2FA code when enabled', async () => {
    const adminLogin = vi.fn().mockResolvedValue({ twoFactorRequired: true, challengeToken: 'tok' });
    renderAt('/admin/login', null, { adminLogin });

    fireEvent.change(screen.getByLabelText(/Administrator email/i), { target: { value: 'rashid.cse.20230104102@aust.edu' } });
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'Admin123!' } });
    fireEvent.click(screen.getByRole('button', { name: /Sign in to admin panel/i }));

    expect(await screen.findByTestId('two-factor-form')).toBeInTheDocument();
    expect(adminLogin).toHaveBeenCalledWith({ email: 'rashid.cse.20230104102@aust.edu', password: 'Admin123!' });
  });
});
