import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { NotificationBell } from '@/components/notifications/NotificationBell';
import { NotificationBadge } from '@/components/notifications/NotificationBadge';
import { NotificationItem } from '@/components/notifications/NotificationItem';
import { NotificationList } from '@/components/notifications/NotificationList';
import { NotificationFilters } from '@/components/notifications/NotificationFilters';
import { NotificationPreferences } from '@/components/notifications/NotificationPreferences';
import { NotificationEmptyState } from '@/components/notifications/NotificationEmptyState';
import { NotificationSkeleton } from '@/components/notifications/NotificationSkeleton';
import { formatRelativeTime, resolveActionPath } from '@/components/notifications/notificationUi';
import { Notifications } from '@/pages/Notifications';
import { useNotifications, NOTIFICATIONS_CHANGED_EVENT } from '@/hooks/useNotifications';
import { ApiError } from '@/services/api';
import type { Notification, NotificationPreference } from '@/types/notification';

vi.mock('@/services/notificationService', () => ({
  notificationService: {
    getNotifications: vi.fn(), getUnreadCount: vi.fn(), markAsRead: vi.fn(), markAllAsRead: vi.fn(), dismissNotification: vi.fn(), deleteNotification: vi.fn(), getPreferences: vi.fn(), updatePreferences: vi.fn(),
  },
}));
import { notificationService } from '@/services/notificationService';
const svc = notificationService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const n = (over: Partial<Notification> = {}): Notification => ({
  id: over.id ?? 'a1b2c3d4-0000-4000-8000-000000000001', type: 'AI_ANALYSIS_COMPLETED', category: 'AI', severity: 'SUCCESS',
  title: 'Assessment analysis completed', message: 'AI analysis for Midterm Examination is ready for review.',
  data: { assessment_id: 15, analysis_id: 82, action_label: 'Open Analysis' }, action_url: '/assessments/15/analysis', entity_type: 'analysis_report', entity_id: 82,
  read_at: null, dismissed_at: null, expires_at: null, created_at: new Date(Date.now() - 5 * 60_000).toISOString(), ...over,
});
const list = (data: Notification[], unread = data.filter((x) => !x.read_at).length, meta: Partial<Record<string, number>> = {}) => ({
  status: 'success', data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, unread_count: unread, poll_interval_seconds: 45, ...meta },
});
const count = (unread_count: number) => ({ status: 'success', data: { unread_count, poll_interval_seconds: 45 } });

const Probe: React.FC = () => <div data-testid="analysis-page">Analysis page</div>;
const renderWithRoutes = (ui: React.ReactNode, path = '/', persistent = false) => render(
  <MemoryRouter initialEntries={[path]}>
    {persistent && ui}
    <Routes>
      <Route path="/" element={persistent ? null : ui} />
      <Route path="/notifications" element={persistent ? null : ui} />
      <Route path="/assessments/:id/analysis" element={<Probe />} />
      <Route path="/reports/:id" element={<div data-testid="report-page">Report page</div>} />
    </Routes>
  </MemoryRouter>,
);

describe('STEP 47 notification helpers', () => {
  it('formats relative time and never trusts foreign action URLs', () => {
    const now = new Date('2026-09-14T12:00:00Z');
    expect(formatRelativeTime(new Date(now.getTime() - 10_000).toISOString(), now)).toBe('just now');
    expect(formatRelativeTime(new Date(now.getTime() - 5 * 60_000).toISOString(), now)).toBe('5 min ago');
    expect(formatRelativeTime(new Date(now.getTime() - 3 * 3_600_000).toISOString(), now)).toBe('3 h ago');
    expect(formatRelativeTime(new Date(now.getTime() - 2 * 86_400_000).toISOString(), now)).toBe('2 d ago');
    expect(formatRelativeTime(null, now)).toBe('');
    expect(resolveActionPath('/assessments/1/analysis')).toBe('/assessments/1/analysis');
    expect(resolveActionPath(`${window.location.origin}/courses/5/collaboration?comment=9`)).toBe('/courses/5/collaboration?comment=9');
    expect(resolveActionPath('https://evil.example/phish')).toBeNull();
    expect(resolveActionPath('//evil.example')).toBeNull();
    expect(resolveActionPath('javascript:alert(1)')).toBeNull();
    expect(resolveActionPath(null)).toBeNull();
  });
});

describe('STEP 47 NotificationBadge / states', () => {
  it('renders the true count accessibly and caps the visible value at 99+', () => {
    const { rerender } = render(<NotificationBadge count={0} />);
    expect(screen.queryByTestId('unread-count')).not.toBeInTheDocument();
    rerender(<NotificationBadge count={3} />);
    expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    expect(screen.getByText('3 unread notifications')).toBeInTheDocument();
    rerender(<NotificationBadge count={1} />);
    expect(screen.getByText('1 unread notification')).toBeInTheDocument();
    rerender(<NotificationBadge count={250} />);
    expect(screen.getByTestId('unread-count')).toHaveAttribute('data-count', '250');
    expect(screen.getByText('99+')).toBeInTheDocument();
    expect(screen.getByText('250 unread notifications')).toBeInTheDocument();
  });

  it('empty and skeleton states are accessible and contain no fake data', () => {
    render(<><NotificationEmptyState /><NotificationSkeleton rows={2} /></>);
    expect(screen.getByText("You're all caught up.")).toBeInTheDocument();
    expect(screen.getByText('Important FacultyLens activity will appear here.')).toBeInTheDocument();
    expect(screen.getByRole('status', { name: 'Loading notifications' })).toBeInTheDocument();
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
  });
});

describe('STEP 47 NotificationItem / NotificationList', () => {
  it('shows icon, title, message, time, category, severity and unread state; click opens and per-row actions fire', () => {
    const onOpen = vi.fn(); const onMarkRead = vi.fn(); const onDismiss = vi.fn(); const onDelete = vi.fn();
    render(<ul><NotificationItem notification={n()} onOpen={onOpen} onMarkRead={onMarkRead} onDismiss={onDismiss} onDelete={onDelete} /></ul>);
    const item = screen.getByTestId('notification-a1b2c3d4-0000-4000-8000-000000000001');
    expect(item).toHaveAttribute('data-unread', 'true');
    expect(within(item).getByText('Assessment analysis completed')).toBeInTheDocument();
    expect(within(item).getByText(/ready for review/)).toBeInTheDocument();
    expect(within(item).getByText('5 min ago')).toBeInTheDocument();
    expect(within(item).getByText('AI')).toBeInTheDocument();
    expect(within(item).getByText('Success')).toBeInTheDocument();
    expect(within(item).getByText(/Open Analysis/)).toBeInTheDocument();
    fireEvent.click(within(item).getByRole('button', { name: /Unread: Assessment analysis completed/ }));
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ id: 'a1b2c3d4-0000-4000-8000-000000000001' }), '/assessments/15/analysis');
    fireEvent.click(within(item).getByRole('button', { name: /Mark "Assessment analysis completed" as read/ }));
    expect(onMarkRead).toHaveBeenCalledWith('a1b2c3d4-0000-4000-8000-000000000001');
    fireEvent.click(within(item).getByRole('button', { name: /Dismiss/ }));
    expect(onDismiss).toHaveBeenCalled();
    fireEvent.click(within(item).getByRole('button', { name: /Delete/ }));
    expect(onDelete).toHaveBeenCalled();
  });

  it('read items have no unread marker or mark-read action; foreign URLs are not navigable', () => {
    const onOpen = vi.fn();
    render(<ul><NotificationItem notification={n({ read_at: new Date().toISOString(), action_url: 'https://evil.example/x' })} onOpen={onOpen} onMarkRead={vi.fn()} /></ul>);
    const item = screen.getByRole('listitem');
    expect(item).toHaveAttribute('data-unread', 'false');
    expect(within(item).queryByRole('button', { name: /Mark .* as read/ })).not.toBeInTheDocument();
    fireEvent.click(within(item).getByRole('button', { name: 'Assessment analysis completed' }));
    expect(onOpen).toHaveBeenCalledWith(expect.anything(), null);
  });

  it('list renders loading, error with retry, empty and populated states', () => {
    const onRetry = vi.fn();
    const { rerender } = render(<NotificationList notifications={[]} loading error={null} onRetry={onRetry} onOpen={vi.fn()} />);
    expect(screen.getByTestId('notification-skeleton')).toBeInTheDocument();
    rerender(<NotificationList notifications={[]} loading={false} error="Network down" onRetry={onRetry} onOpen={vi.fn()} />);
    expect(screen.getByRole('alert')).toHaveTextContent('Unable to load notifications.');
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(onRetry).toHaveBeenCalled();
    rerender(<NotificationList notifications={[]} loading={false} error={null} onRetry={onRetry} onOpen={vi.fn()} />);
    expect(screen.getByTestId('notification-empty')).toBeInTheDocument();
    rerender(<NotificationList notifications={[n(), n({ id: 'b', title: 'Report ready', category: 'REPORT' })]} loading={false} error={null} onRetry={onRetry} onOpen={vi.fn()} />);
    expect(screen.getByRole('list', { name: 'Notifications' })).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
  });
});

describe('STEP 47 NotificationFilters', () => {
  it('exposes tabs for all, unread and every category with keyboard navigation', () => {
    const onChange = vi.fn();
    render(<NotificationFilters value="all" onChange={onChange} unreadCount={4} />);
    const tabs = screen.getAllByRole('tab');
    expect(tabs.map((t) => t.textContent)).toEqual(['All', 'Unread4', 'AI', 'Assessment', 'Collaboration', 'Review', 'Grading', 'Performance', 'Reports', 'Feedback', 'Security', 'System']);
    expect(screen.getByRole('tab', { name: /All/ })).toHaveAttribute('aria-selected', 'true');
    fireEvent.click(screen.getByTestId('notification-filter-REPORT'));
    expect(onChange).toHaveBeenCalledWith('REPORT');
    fireEvent.keyDown(tabs[0], { key: 'ArrowRight' });
    expect(onChange).toHaveBeenCalledWith('unread');
    fireEvent.keyDown(tabs[0], { key: 'End' });
    expect(onChange).toHaveBeenCalledWith('SYSTEM');
  });
});

describe('STEP 47 useNotifications hook', () => {
  beforeEach(() => { vi.clearAllMocks(); vi.useFakeTimers({ shouldAdvanceTime: true }); });
  afterEach(() => { vi.useRealTimers(); });

  const HookProbe: React.FC<{ poll?: boolean; loadList?: boolean; enabled?: boolean }> = ({ poll = true, loadList = false, enabled = true }) => {
    const h = useNotifications({ poll, loadList, enabled, pollIntervalMs: 30_000 });
    return <div><span data-testid="count">{h.unreadCount}</span><span data-testid="err">{h.error ?? ''}</span><button type="button" onClick={() => { void h.markAllAsRead(); }}>all</button></div>;
  };

  it('polls the unread count on the interval, pauses while hidden, refreshes on visibility, and cleans up on unmount', async () => {
    svc.getUnreadCount.mockResolvedValue(count(2));
    const { unmount } = render(<HookProbe />);
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('2'));
    expect(svc.getUnreadCount).toHaveBeenCalledTimes(1);

    svc.getUnreadCount.mockResolvedValue(count(5));
    await act(async () => { await vi.advanceTimersByTimeAsync(30_000); });
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('5'));
    expect(svc.getUnreadCount).toHaveBeenCalledTimes(2);

    Object.defineProperty(document, 'hidden', { value: true, configurable: true });
    await act(async () => { await vi.advanceTimersByTimeAsync(30_000); });
    expect(svc.getUnreadCount).toHaveBeenCalledTimes(2); // paused

    Object.defineProperty(document, 'hidden', { value: false, configurable: true });
    await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    await waitFor(() => expect(svc.getUnreadCount).toHaveBeenCalledTimes(3));

    unmount();
    await act(async () => { await vi.advanceTimersByTimeAsync(90_000); });
    expect(svc.getUnreadCount).toHaveBeenCalledTimes(3); // interval cleared
  });

  it('does nothing while disabled, keeps the last good count on a failed poll, and syncs across instances', async () => {
    svc.getUnreadCount.mockResolvedValue(count(1));
    const { rerender } = render(<HookProbe enabled={false} />);
    await act(async () => { await vi.advanceTimersByTimeAsync(100); });
    expect(svc.getUnreadCount).not.toHaveBeenCalled();
    rerender(<HookProbe enabled />);
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('1'));

    svc.getUnreadCount.mockRejectedValueOnce(new ApiError(503, 'down'));
    await act(async () => { await vi.advanceTimersByTimeAsync(30_000); });
    expect(screen.getByTestId('count')).toHaveTextContent('1');

    svc.getUnreadCount.mockResolvedValue(count(0));
    await act(async () => { window.dispatchEvent(new CustomEvent(NOTIFICATIONS_CHANGED_EVENT, { detail: { unreadCount: 0 } })); });
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('0'));
  });
  it('a filter change while a fetch is in flight always issues the new request and drops the stale response', async () => {
    let resolveAll: (v: unknown) => void = () => undefined;
    svc.getUnreadCount.mockResolvedValue(count(1));
    svc.getNotifications.mockImplementation(({ filter }: { filter: string }) => {
      if (filter === 'all') return new Promise((r) => { resolveAll = r; });
      return Promise.resolve(list([n({ id: 'rep', type: 'REPORT_GENERATED', category: 'REPORT', title: 'Report ready' })], 0));
    });
    const FilterProbe: React.FC<{ filter: 'all' | 'REPORT' }> = ({ filter }) => {
      const h = useNotifications({ filter, poll: false });
      return <ul>{h.notifications.map((x) => <li key={x.id}>{x.title}</li>)}</ul>;
    };
    const { rerender } = render(<FilterProbe filter="all" />);
    await waitFor(() => expect(svc.getNotifications).toHaveBeenCalledWith({ page: 1, perPage: 20, filter: 'all' }));
    rerender(<FilterProbe filter="REPORT" />);
    await waitFor(() => expect(svc.getNotifications).toHaveBeenCalledWith({ page: 1, perPage: 20, filter: 'REPORT' }));
    await screen.findByText('Report ready');
    await act(async () => { resolveAll(list([n({ id: 'old', title: 'Stale all-filter row' })], 1)); });
    expect(screen.queryByText('Stale all-filter row')).not.toBeInTheDocument();
    expect(screen.getByText('Report ready')).toBeInTheDocument();
  });
});

describe('STEP 47 NotificationBell + dropdown', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('shows the backend unread count, opens an accessible dropdown, marks read on click and navigates', async () => {
    svc.getUnreadCount.mockResolvedValue(count(3));
    svc.getNotifications.mockResolvedValue(list([n(), n({ id: 'r1', type: 'REPORT_GENERATED', category: 'REPORT', title: 'Report ready', message: 'Your report is ready.', action_url: '/reports/9', read_at: new Date().toISOString() })], 3));
    svc.markAsRead.mockResolvedValue({ status: 'success', data: null, meta: { unread_count: 2 } });
    renderWithRoutes(<NotificationBell />, '/', true);

    const bell = await screen.findByRole('button', { name: 'Notifications, 3 unread' });
    expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    expect(svc.getNotifications).not.toHaveBeenCalled(); // list is fetched only when opened
    expect(bell).toHaveAttribute('aria-expanded', 'false');

    fireEvent.click(bell);
    expect(bell).toHaveAttribute('aria-expanded', 'true');
    const dialog = await screen.findByRole('dialog', { name: 'Notifications' });
    await waitFor(() => expect(within(dialog).getAllByRole('listitem')).toHaveLength(2));
    expect(svc.getNotifications).toHaveBeenCalledWith({ page: 1, perPage: 10, filter: 'all' });
    expect(within(dialog).getByText('(3 unread)')).toBeInTheDocument();
    expect(within(dialog).getByTestId('dropdown-view-all')).toHaveAttribute('href', '/notifications');

    fireEvent.click(within(dialog).getByRole('button', { name: /Unread: Assessment analysis completed/ }));
    await waitFor(() => expect(svc.markAsRead).toHaveBeenCalledWith('a1b2c3d4-0000-4000-8000-000000000001'));
    await screen.findByTestId('analysis-page');
    await waitFor(() => expect(screen.getByTestId('unread-count')).toHaveTextContent('2'));
  });

  it('mark all read from the dropdown clears the badge; Escape closes and returns focus', async () => {
    svc.getUnreadCount.mockResolvedValue(count(1));
    svc.getNotifications.mockResolvedValue(list([n()], 1));
    svc.markAllAsRead.mockResolvedValue({ status: 'success', data: { updated: 1 }, meta: { unread_count: 0 } });
    renderWithRoutes(<NotificationBell />, '/', true);
    const bell = await screen.findByRole('button', { name: 'Notifications, 1 unread' });
    fireEvent.click(bell);
    const dialog = await screen.findByRole('dialog', { name: 'Notifications' });
    fireEvent.click(await within(dialog).findByTestId('dropdown-mark-all-read'));
    await waitFor(() => expect(svc.markAllAsRead).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByTestId('unread-count')).not.toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Notifications' })).toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(document.activeElement).toBe(bell);
  });

  it('dropdown surfaces the empty state and the error/retry state without fake rows', async () => {
    svc.getUnreadCount.mockResolvedValue(count(0));
    svc.getNotifications.mockRejectedValueOnce(new ApiError(500, 'boom')).mockResolvedValueOnce(list([]));
    renderWithRoutes(<NotificationBell />, '/', true);
    fireEvent.click(await screen.findByRole('button', { name: 'Notifications' }));
    const dialog = await screen.findByRole('dialog', { name: 'Notifications' });
    await within(dialog).findByRole('alert');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Retry' }));
    await within(dialog).findByTestId('notification-empty');
    expect(within(dialog).queryByRole('listitem')).not.toBeInTheDocument();
  });
});

describe('STEP 47 Notifications page', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('lists notifications with server-side filters, pagination, mark read, mark all read, dismiss, delete and navigation', async () => {
    const rows = [n(), n({ id: 'r1', type: 'REPORT_GENERATED', category: 'REPORT', severity: 'SUCCESS', title: 'Report ready', message: 'Your requested Assessment report is ready.', action_url: '/reports/9', read_at: new Date().toISOString() })];
    svc.getNotifications.mockResolvedValue(list(rows, 1, { last_page: 3, total: 41 }));
    svc.getUnreadCount.mockResolvedValue(count(1));
    // Mirror the server: once marked read, subsequent list fetches return the row as read.
    svc.markAsRead.mockImplementation(async () => { svc.getNotifications.mockResolvedValue(list(rows.map((r) => ({ ...r, read_at: r.read_at ?? new Date().toISOString() })), 0, { last_page: 3, total: 41 })); return { status: 'success', data: null, meta: { unread_count: 0 } }; });
    svc.markAllAsRead.mockResolvedValue({ status: 'success', data: { updated: 1 }, meta: { unread_count: 0 } });
    svc.dismissNotification.mockResolvedValue({ status: 'success', data: null, meta: { unread_count: 0 } });
    svc.deleteNotification.mockResolvedValue({ status: 'success', data: null, meta: { unread_count: 0 } });
    renderWithRoutes(<Notifications />, '/notifications');

    await screen.findByRole('list', { name: 'Notifications' });
    expect(svc.getNotifications).toHaveBeenCalledWith({ page: 1, perPage: 20, filter: 'all' });
    expect(screen.getByText('1 unread notification.', { exact: false })).toBeInTheDocument();
    expect(screen.getByText('Page 1 of 3 · 41 total')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('notification-filter-unread'));
    await waitFor(() => expect(svc.getNotifications).toHaveBeenLastCalledWith({ page: 1, perPage: 20, filter: 'unread' }));
    fireEvent.click(screen.getByTestId('notification-filter-REPORT'));
    await waitFor(() => expect(svc.getNotifications).toHaveBeenLastCalledWith({ page: 1, perPage: 20, filter: 'REPORT' }));
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    await waitFor(() => expect(svc.getNotifications).toHaveBeenLastCalledWith({ page: 2, perPage: 20, filter: 'REPORT' }));

    fireEvent.click(screen.getByRole('button', { name: /Mark "Assessment analysis completed" as read/ }));
    await waitFor(() => expect(svc.markAsRead).toHaveBeenCalledWith('a1b2c3d4-0000-4000-8000-000000000001'));
    await waitFor(() => expect(screen.getByTestId('notification-a1b2c3d4-0000-4000-8000-000000000001')).toHaveAttribute('data-unread', 'false'));

    fireEvent.click(screen.getByRole('button', { name: /Dismiss "Report ready"/ }));
    await waitFor(() => expect(svc.dismissNotification).toHaveBeenCalledWith('r1'));
    fireEvent.click(screen.getAllByRole('button', { name: /Delete "/ })[0]);
    await waitFor(() => expect(svc.deleteNotification).toHaveBeenCalled());

    svc.getNotifications.mockResolvedValue(list([n({ id: 'z', read_at: null })], 1));
    svc.markAllAsRead.mockImplementation(async () => { svc.getNotifications.mockResolvedValue(list([n({ id: 'z', read_at: new Date().toISOString() })], 0)); return { status: 'success', data: { updated: 1 }, meta: { unread_count: 0 } }; });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh notifications' }));
    await waitFor(() => expect(screen.getByTestId('mark-all-read')).not.toBeDisabled());
    fireEvent.click(screen.getByTestId('mark-all-read'));
    await waitFor(() => expect(svc.markAllAsRead).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByTestId('notification-z')).toHaveAttribute('data-unread', 'false'));

    fireEvent.click(screen.getByRole('button', { name: 'Assessment analysis completed. Open Analysis' }));
    await screen.findByTestId('analysis-page');
  });

  it('shows loading, then error with retry, then empty state; preferences view is reachable', async () => {
    svc.getUnreadCount.mockResolvedValue(count(0));
    svc.getNotifications.mockRejectedValueOnce(new ApiError(503, 'unavailable')).mockResolvedValueOnce(list([]));
    svc.getPreferences.mockResolvedValue({ status: 'success', data: { preferences: [], categories: [], mandatory_categories: ['SECURITY', 'SYSTEM'], email_available: false } });
    renderWithRoutes(<Notifications />, '/notifications');
    expect(screen.getByTestId('notification-skeleton')).toBeInTheDocument();
    await screen.findByRole('alert');
    expect(screen.getByText('Unable to load notifications.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await screen.findByTestId('notification-empty');
    fireEvent.click(screen.getByTestId('view-preferences'));
    await screen.findByTestId('notification-preferences');
  });
});

describe('STEP 47 NotificationPreferences', () => {
  beforeEach(() => { vi.clearAllMocks(); });
  const prefs: NotificationPreference[] = [
    { notification_type: 'AI_ANALYSIS_COMPLETED', category: 'AI', label: 'Assessment analysis completed', in_app_enabled: true, email_enabled: false, mandatory: false },
    { notification_type: 'AI_ANALYSIS_FAILED', category: 'AI', label: 'AI analysis failed', in_app_enabled: true, email_enabled: false, mandatory: false },
    { notification_type: 'REPORT_GENERATED', category: 'REPORT', label: 'Report ready', in_app_enabled: false, email_enabled: false, mandatory: false },
    { notification_type: 'SECURITY_ALERT', category: 'SECURITY', label: 'Security alert', in_app_enabled: true, email_enabled: false, mandatory: true },
  ];

  it('renders the matrix grouped by category, locks mandatory types, and saves only non-mandatory changes', async () => {
    svc.getPreferences.mockResolvedValue({ status: 'success', data: { preferences: prefs, categories: ['AI', 'REPORT', 'SECURITY'], mandatory_categories: ['SECURITY', 'SYSTEM'], email_available: false } });
    svc.updatePreferences.mockResolvedValue({ status: 'success', data: { preferences: prefs.map((p) => (p.notification_type === 'AI_ANALYSIS_COMPLETED' ? { ...p, in_app_enabled: false } : p)) } });
    render(<MemoryRouter><NotificationPreferences /></MemoryRouter>);

    await screen.findByTestId('notification-preferences');
    const analysis = screen.getByLabelText('Assessment analysis completed') as HTMLInputElement;
    const security = screen.getByLabelText('Security alert') as HTMLInputElement;
    expect(analysis).toBeChecked();
    expect(security).toBeChecked();
    expect(security).toBeDisabled();
    expect(screen.getByText('Always on')).toBeInTheDocument();
    expect((screen.getByLabelText('Report ready') as HTMLInputElement).checked).toBe(false);
    expect(screen.getByTestId('save-preferences')).toBeDisabled();

    fireEvent.click(analysis);
    expect(analysis).not.toBeChecked();
    expect(screen.getByTestId('save-preferences')).not.toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Disable all AI notifications' }));
    expect((screen.getByLabelText('AI analysis failed') as HTMLInputElement).checked).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Enable all AI notifications' }));
    fireEvent.click(analysis);

    fireEvent.click(screen.getByTestId('save-preferences'));
    await waitFor(() => expect(svc.updatePreferences).toHaveBeenCalledWith([
      { notification_type: 'AI_ANALYSIS_COMPLETED', in_app_enabled: false },
      { notification_type: 'AI_ANALYSIS_FAILED', in_app_enabled: true },
      { notification_type: 'REPORT_GENERATED', in_app_enabled: false },
    ]));
    await screen.findByText('Preferences saved.');
  });

  it('shows a retryable error when preferences cannot load', async () => {
    svc.getPreferences.mockRejectedValueOnce(new ApiError(500, 'boom')).mockResolvedValueOnce({ status: 'success', data: { preferences: prefs, categories: [], mandatory_categories: [], email_available: false } });
    render(<MemoryRouter><NotificationPreferences /></MemoryRouter>);
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await screen.findByTestId('notification-preferences');
  });
});
