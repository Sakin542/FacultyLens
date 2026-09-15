import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, act, fireEvent, within } from '@testing-library/react';
import React from 'react';
import { MemoryRouter } from 'react-router-dom';
import { NotificationProvider, useNotificationContext } from '@/context/NotificationContext';
import { NotificationBell } from '@/components/notifications/NotificationBell';
import { NotificationToastContainer } from '@/components/notifications/NotificationToast';
import { AuthContext } from '@/context/AuthContext';
import type { Notification } from '@/types/notification';
import type { User } from '@/types';

// Mock notificationService
vi.mock('@/services/notificationService', () => ({
  notificationService: {
    getNotifications: vi.fn(),
    getUnreadCount: vi.fn(),
    markAsRead: vi.fn(),
    markAllAsRead: vi.fn(),
    dismissNotification: vi.fn(),
    deleteNotification: vi.fn(),
  },
}));

// Mock echo service
let mockNotificationHandler: ((n: Notification) => void) | null = null;
let mockConnectionChangeHandler: ((status: string) => void) | null = null;
const mockDisconnectRealtime = vi.fn();

vi.mock('@/services/echo', () => ({
  subscribeToUserNotifications: vi.fn((_userId: number, onNotification: (n: Notification) => void, onConnectionChange: (s: string) => void) => {
    mockNotificationHandler = onNotification;
    mockConnectionChangeHandler = onConnectionChange;
    onConnectionChange?.('connected');
    return vi.fn();
  }),
  disconnectRealtime: () => mockDisconnectRealtime(),
  getEcho: vi.fn(() => null),
}));

import { notificationService } from '@/services/notificationService';
const svc = notificationService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const createSampleNotification = (over: Partial<Notification> = {}): Notification => ({
  id: over.id ?? 'sample-notif-001',
  type: over.type ?? 'AI_ANALYSIS_COMPLETED',
  category: over.category ?? 'AI',
  severity: over.severity ?? 'SUCCESS',
  title: over.title ?? 'Assessment analysis completed',
  message: over.message ?? 'AI analysis for Midterm is ready for review.',
  data: over.data ?? { assessment_id: 12, analysis_id: 45 },
  action_url: over.action_url ?? '/assessments/12/analysis',
  entity_type: over.entity_type ?? 'analysis_report',
  entity_id: over.entity_id ?? 45,
  read_at: over.read_at ?? null,
  dismissed_at: over.dismissed_at ?? null,
  expires_at: over.expires_at ?? null,
  created_at: over.created_at ?? new Date().toISOString(),
});

const mockUser: User = {
  id: 1,
  name: 'Dr. Alan Turing',
  email: 'turing@facultylens.edu',
  role: 'faculty',
  department: 'Computer Science',
  designation: 'Professor',
};

const renderTestApp = (initialUnreadCount = 3) => {
  svc.getUnreadCount.mockResolvedValue({
    status: 'success',
    data: { unread_count: initialUnreadCount, poll_interval_seconds: 45 },
  });
  svc.getNotifications.mockResolvedValue({
    status: 'success',
    data: [
      createSampleNotification({ id: 'existing-1', read_at: null }),
      createSampleNotification({ id: 'existing-2', read_at: null }),
      createSampleNotification({ id: 'existing-3', read_at: null }),
    ],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 3, unread_count: initialUnreadCount },
  });
  svc.markAsRead.mockImplementation((_id: string) =>
    Promise.resolve({ status: 'success', data: null, meta: { unread_count: 2 } }),
  );
  svc.markAllAsRead.mockImplementation(() =>
    Promise.resolve({ status: 'success', data: { updated: 3 }, meta: { unread_count: 0 } }),
  );
  svc.dismissNotification.mockImplementation((_id: string) =>
    Promise.resolve({ status: 'success', data: null, meta: { unread_count: 2 } }),
  );

  const TestApp: React.FC = () => {
    const { toasts, dismissToast } = useNotificationContext();
    return (
      <>
        <NotificationBell />
        <NotificationToastContainer toasts={toasts} onDismiss={dismissToast} />
      </>
    );
  };

  const authValue = {
    user: mockUser,
    isAuthenticated: true,
    loading: false,
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    refreshUser: vi.fn(),
    updateUser: vi.fn(),
  };

  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter initialEntries={['/']}>
        <NotificationProvider>
          <TestApp />
        </NotificationProvider>
      </MemoryRouter>
    </AuthContext.Provider>,
  );
};

describe('Real-Time Notification System (End-to-End State Synchronization)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockNotificationHandler = null;
    mockConnectionChangeHandler = null;
  });

  it('initial unread count = 3 renders 🔔 3 without page reload', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
      expect(screen.getByRole('button', { name: /Notifications, 3 unread/i })).toBeInTheDocument();
    });
  });

  it('receiving real-time unread notification increments bell count from 3 to 4 and shows toast', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    // Simulate real-time notification arrival over WebSocket
    const newNotif = createSampleNotification({
      id: 'realtime-notif-001',
      title: 'AI Grading Completed',
      message: 'New grades are ready.',
      read_at: null,
    });

    act(() => {
      mockNotificationHandler?.(newNotif);
    });

    // Count updates automatically without page reload: 3 -> 4
    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('4');
      expect(screen.getByRole('button', { name: /Notifications, 4 unread/i })).toBeInTheDocument();
    });

    // Toast appears
    expect(screen.getByTestId('notification-toast-realtime-notif-001')).toBeInTheDocument();
    expect(screen.getByText('AI Grading Completed')).toBeInTheDocument();
    expect(screen.getByText('New grades are ready.')).toBeInTheDocument();
  });

  it('duplicate real-time event does not increment count a second time', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    const notif = createSampleNotification({
      id: 'dup-001',
      title: 'Assessment Version Approved',
      read_at: null,
    });

    // First arrival
    act(() => {
      mockNotificationHandler?.(notif);
    });
    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('4');
    });

    // Replay of same notification ID
    act(() => {
      mockNotificationHandler?.(notif);
    });

    // Count must stay 4, not increment to 5
    expect(screen.getByTestId('unread-count')).toHaveTextContent('4');
  });

  it('already-read real-time notification does not increment unread count', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    const alreadyReadNotif = createSampleNotification({
      id: 'read-001',
      title: 'Security Notice',
      read_at: new Date().toISOString(),
    });

    act(() => {
      mockNotificationHandler?.(alreadyReadNotif);
    });

    // Count stays 3
    expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
  });

  it('clicking dropdown notification marks it as read and decreases bell count', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    // Open dropdown
    const bell = screen.getByTestId('notification-bell');
    const bellButton = within(bell).getByRole('button');
    fireEvent.click(bellButton);

    await waitFor(() => {
      expect(screen.getByTestId('notification-dropdown')).toBeInTheDocument();
    });

    // Click first item to open/read
    await waitFor(() => {
      expect(screen.getByTestId('notification-existing-1')).toBeInTheDocument();
    });
    const item = screen.getByTestId('notification-existing-1');
    const openButton = within(item).getByRole('button', { name: /Unread: Assessment analysis completed/i });
    await act(async () => {
      fireEvent.click(openButton);
    });

    expect(svc.markAsRead).toHaveBeenCalledWith('existing-1');

    // Count decreased
    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('2');
    });
  });

  it('mark all as read sets unread count to 0 immediately', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    // Open dropdown
    const bell = screen.getByTestId('notification-bell');
    fireEvent.click(within(bell).getByRole('button'));

    await waitFor(() => {
      expect(screen.getByTestId('dropdown-mark-all-read')).toBeInTheDocument();
    });

    // Click mark all read
    await act(async () => {
      fireEvent.click(screen.getByTestId('dropdown-mark-all-read'));
    });

    expect(svc.markAllAsRead).toHaveBeenCalled();

    // Count becomes 0 and badge disappears
    await waitFor(() => {
      expect(screen.queryByTestId('unread-count')).not.toBeInTheDocument();
      const bellButton = within(screen.getByTestId('notification-bell')).getAllByRole('button')[0];
      expect(bellButton).toHaveAttribute('aria-label', 'Notifications');
    });
  });

  it('dismissing a toast removes it from screen without losing unread count', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    await act(async () => {
      mockNotificationHandler?.(createSampleNotification({ id: 'toast-1', title: 'Rubric Draft Ready', read_at: null }));
    });

    await waitFor(() => {
      expect(screen.getByTestId('notification-toast-toast-1')).toBeInTheDocument();
      expect(screen.getByTestId('unread-count')).toHaveTextContent('4');
    });

    // Dismiss toast
    const dismissBtn = within(screen.getByTestId('notification-toast-toast-1')).getByRole('button', {
      name: /Dismiss notification/i,
    });
    fireEvent.click(dismissBtn);

    expect(screen.queryByTestId('notification-toast-toast-1')).not.toBeInTheDocument();
    // Count remains 4
    expect(screen.getByTestId('unread-count')).toHaveTextContent('4');
  });

  it('reconnecting to realtime server resynchronizes unread count', async () => {
    renderTestApp(3);

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('3');
    });

    // Backend unread count changed while disconnected
    svc.getUnreadCount.mockResolvedValueOnce({
      status: 'success',
      data: { unread_count: 5, poll_interval_seconds: 45 },
    });

    // Trigger reconnect
    act(() => {
      mockConnectionChangeHandler?.('connected');
    });

    await waitFor(() => {
      expect(screen.getByTestId('unread-count')).toHaveTextContent('5');
    });
  });
});
