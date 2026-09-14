/**
 * STEP 47: notification system types. Mirrors backend/config/notifications.php — keep both in sync.
 */

export type NotificationCategory =
  | 'AI' | 'ASSESSMENT' | 'COLLABORATION' | 'REVIEW' | 'GRADING' | 'PERFORMANCE' | 'REPORT' | 'FEEDBACK' | 'SECURITY' | 'SYSTEM';

export type NotificationSeverity = 'INFO' | 'SUCCESS' | 'WARNING' | 'ERROR' | 'CRITICAL';

export type NotificationType =
  | 'AI_ANALYSIS_COMPLETED' | 'AI_ANALYSIS_FAILED' | 'AI_RECOMMENDATION_CREATED'
  | 'RUBRIC_GENERATED' | 'RUBRIC_GENERATION_FAILED'
  | 'QUESTION_GENERATION_COMPLETED' | 'QUESTION_GENERATION_FAILED'
  | 'ASSESSMENT_VERSION_CREATED' | 'ASSESSMENT_VERSION_APPROVED' | 'ASSESSMENT_VERSION_FINALIZED' | 'ASSESSMENT_VERSION_ARCHIVED' | 'ASSESSMENT_VERSION_RESTORED'
  | 'COLLABORATION_INVITATION' | 'COLLABORATION_ACCEPTED' | 'COLLABORATION_REJECTED' | 'COLLABORATION_REMOVED' | 'COLLABORATION_ROLE_CHANGED'
  | 'COMMENT_CREATED' | 'MENTION_RECEIVED'
  | 'REVIEW_ASSIGNED' | 'REVIEW_COMPLETED'
  | 'GRADING_COMPLETED' | 'GRADING_FAILED' | 'INTER_GRADER_REVIEW_REQUIRED'
  | 'PERFORMANCE_ANALYSIS_COMPLETED' | 'PERFORMANCE_ANALYSIS_FAILED' | 'LEARNING_GAP_DETECTED'
  | 'REPORT_GENERATED' | 'REPORT_GENERATION_FAILED'
  | 'FACULTY_FEEDBACK_RECEIVED'
  | 'SECURITY_ALERT' | 'SYSTEM_ALERT';

export const NOTIFICATION_CATEGORIES: NotificationCategory[] = ['AI', 'ASSESSMENT', 'COLLABORATION', 'REVIEW', 'GRADING', 'PERFORMANCE', 'REPORT', 'FEEDBACK', 'SECURITY', 'SYSTEM'];

export const CATEGORY_LABELS: Record<NotificationCategory, string> = {
  AI: 'AI', ASSESSMENT: 'Assessment', COLLABORATION: 'Collaboration', REVIEW: 'Review', GRADING: 'Grading',
  PERFORMANCE: 'Performance', REPORT: 'Reports', FEEDBACK: 'Feedback', SECURITY: 'Security', SYSTEM: 'System',
};

export const SEVERITY_LABELS: Record<NotificationSeverity, string> = {
  INFO: 'Info', SUCCESS: 'Success', WARNING: 'Warning', ERROR: 'Error', CRITICAL: 'Critical',
};

/** `all` | `unread` | a category. Applied server-side. */
export type NotificationFilter = 'all' | 'unread' | NotificationCategory;

export interface Notification {
  id: string;
  type: NotificationType | string;
  category: NotificationCategory;
  severity: NotificationSeverity;
  title: string | null;
  message: string | null;
  /** Non-sensitive identifiers only (assessment_id, report_id, action_label, …). */
  data: Record<string, unknown>;
  /** App-relative path (e.g. /assessments/15/analysis) or null. Legacy rows may hold an absolute URL. */
  action_url: string | null;
  entity_type: string | null;
  entity_id: number | null;
  read_at: string | null;
  dismissed_at: string | null;
  expires_at: string | null;
  created_at: string | null;
}

export interface NotificationPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  unread_count: number;
  poll_interval_seconds?: number;
}

export interface NotificationListResponse {
  status: 'success' | 'error';
  message?: string;
  data: Notification[];
  meta: NotificationPagination;
}

export interface UnreadCountResponse {
  status: 'success' | 'error';
  data: { unread_count: number; poll_interval_seconds?: number };
}

export interface NotificationMutationResponse {
  status: 'success' | 'error';
  message?: string;
  data: Notification | { updated: number } | null;
  meta?: { unread_count: number };
}

export interface NotificationPreference {
  notification_type: NotificationType | string;
  category: NotificationCategory;
  label: string;
  in_app_enabled: boolean;
  email_enabled: boolean;
  mandatory: boolean;
}

export interface NotificationPreferencesResponse {
  status: 'success' | 'error';
  data: {
    preferences: NotificationPreference[];
    categories: NotificationCategory[];
    mandatory_categories: NotificationCategory[];
    email_available: boolean;
  };
}

export interface NotificationPreferenceUpdate {
  notification_type: string;
  in_app_enabled: boolean;
  email_enabled?: boolean;
}
