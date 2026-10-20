export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Paged<T, M = Record<string, unknown>> {
  status: string;
  data: T[];
  meta: PageMeta & M;
}

export type ServiceState = 'healthy' | 'degraded' | 'unavailable' | 'unconfigured' | 'unknown';
export interface UserRef { id: number; name: string }
export interface Period { current: number; previous: number }
export interface DailyPoint { date: string; total: number; ok?: number; failed?: number }

// ---------------------------------------------------------------- dashboard
export interface DashboardData {
  range: { days: number; start: string; end: string; previous_start: string };
  kpis: {
    users_total: { value: number };
    active_faculty: { value: number };
    suspended_accounts: { value: number };
    new_users: Period;
    unverified_accounts: { value: number };
    departments: { value: number };
    courses: { value: number; new: Period };
    assessments: { value: number; new: Period };
    analyses_completed: Period;
    analyses_failed: Period;
    ai_calls: Period;
    ai_availability: { value: number | null; checks: number };
    documents: { by_status: Record<'uploaded' | 'processing' | 'completed' | 'failed', number> };
    pending_jobs: { value: number | null; as_of: string | null };
    failed_jobs: Period;
    email_failures: Period;
    unread_alerts: { value: number };
    open_alerts: { value: number };
    open_incidents: { value: number };
    data_quality_open: { value: number; last_scan_at: string | null };
    last_backup: { value: string | null; verification_status: string | null };
    integrations_healthy: { value: number; of: number };
  };
  series: Record<'new_users' | 'ai_calls' | 'analyses' | 'emails' | 'failed_jobs' | 'admin_activity', DailyPoint[]>;
  account_status: { role: string; active: number; deactivated: number }[];
  services: { service: string; label: string; status: ServiceState; response_ms: number | null }[];
  critical_alerts: AdminAlert[];
  recent_activity: { id: number; action: string; entity_type: string; entity_id: number | null; user: UserRef | null; created_at: string | null }[];
  freshness: { generated_at: string; health_snapshot_at: string | null; data_quality_scan_at: string | null; scheduler_heartbeat_at: string | null };
}

// ---------------------------------------------------------------- users
export type AssignableRole = 'FACULTY' | 'DEPARTMENT_HEAD';
export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: 'ADMIN' | AssignableRole;
  is_admin: boolean;
  is_active: boolean;
  department: string | null;
  designation: string | null;
  courses_count: number;
  two_factor_enabled: boolean;
  google_linked: boolean;
  email_verified: boolean;
  last_login_at: string | null;
  created_at: string | null;
  profile_picture_url: string | null;
}
export interface AdminUserDetail extends AdminUser {
  collaborations_count: number;
  email_verified_at: string | null;
  sessions: { tracked: boolean; driver: string; active: number | null; last_activity_at: string | null; api_tokens: number };
  history: { id: number; action: string; actor: UserRef | null; metadata: Record<string, unknown> | null; created_at: string | null }[];
}
export interface ImportPreviewRow { line: number; name: string; email: string; department: string | null; designation: string | null; role: string; errors: string[] }
export interface ImportPreview { columns: string[]; missing_columns: string[]; rows: ImportPreviewRow[]; valid: number; invalid: number; truncated: boolean }

// ---------------------------------------------------------------- access + audit + security
export interface AccessData {
  roles: { key: string; label: string; description: string; assignable: boolean; protected: boolean; members: number }[];
  modules: { module: string; capabilities: { key: string; label: string; roles: Record<string, string>; enforced_by: string }[] }[];
  privileged: { id: number; name: string; email: string; role: string; department: string | null; is_active: boolean; effective: boolean; two_factor_enabled: boolean; last_login_at: string | null; note: string | null }[];
  history: { id: number; action: string; actor: UserRef | null; target_id: number | null; target_name: string | null; from: string | null; to: string | null; reason: string | null; created_at: string | null }[];
  admin_lock: { email_configured: boolean; description: string };
}
export interface AuditEntry {
  id: number;
  action: string;
  actor: UserRef | null;
  entity_type: string;
  entity_id: number | null;
  course_id: number | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  outcome: 'success' | 'failure';
  created_at: string | null;
}
export interface SecurityData {
  days: number;
  facts: Record<string, number>;
  failed_trend: DailyPoint[];
  targeted_accounts: { user_id: number; name: string | null; is_active: boolean | null; failures: number; addresses: number; last_at: string }[];
  sources: { ip_address: string; failures: number; accounts: number }[];
  posture: { active_accounts: number; two_factor_accounts: number; two_factor_percent: number | null; admin_two_factor: boolean; active_sessions_2h: number | null; session_driver: string; never_signed_in: number };
  signals: { severity: 'info' | 'warning' | 'critical'; title: string; detail: string }[];
  events: { id: number; action: string; actor: UserRef | null; entity_type: string; entity_id: number | null; ip_address: string | null; created_at: string | null }[];
  dependency_scanning: string;
}

// ---------------------------------------------------------------- institution
export interface InstitutionData {
  totals: Record<'departments' | 'programs' | 'courses' | 'active_courses' | 'learning_outcomes' | 'assessments' | 'users_without_department', number>;
  departments: { name: string; members: number; heads: number; courses: number; programs: number; without_head: boolean }[];
  programs: { id: number; code: string; name: string; department: string | null; status: string; courses: number; outcomes: number }[];
  terms: { academic_year: string; semester: string; courses: number; active: number }[];
}
export interface InstitutionCourse {
  id: number; course_code: string; course_name: string; semester: string; academic_year: string; credits: number; status: string;
  owner: UserRef; department: string | null; program_code: string | null; outcomes_count: number; assessments_count: number; collaborators_count: number; gaps: string[];
}

// ---------------------------------------------------------------- operations
export interface ServiceCheck { service: string; label: string; group: string; status: ServiceState; response_ms: number | null; detail: Record<string, unknown>; summary: string | null }
export interface ServiceHistory { checks: number; failures: number; last_success_at: string | null; last_failure_at: string | null; avg_response_ms: number | null; series: { hour: string; checks: number; healthy: number }[] }
export interface HealthData { checked_at: string | null; live: boolean; services: ServiceCheck[]; history: Record<string, ServiceHistory>; hours: number; scheduler_heartbeat_at: string | null }
export interface EnvironmentData {
  application: { name: string; environment: string; version: string; debug: boolean; debug_warning: boolean; url: string; timezone: string; locale: string; php: string; laravel: string };
  drivers: Record<string, string>;
  configuration: { key: string; label: string; configured: boolean }[];
  ai: { request_timeout_seconds: number; connect_timeout_seconds: number };
  migrations: { ran?: number; files?: number; pending?: string[]; last_batch?: number; latest?: string; error?: string };
}
export interface ScheduledTask { command: string; description: string | null; expression: string; timezone: string; without_overlapping: boolean; next_run_at: string | null }

export interface QueueSummary {
  driver: string;
  horizon_available: boolean;
  horizon_path: string;
  queues: { queue: string; pending: number | null; error?: string }[];
  failed_total: number;
  failed_24h: number;
  failed_trend: DailyPoint[];
  failed_by_job: { job: string; count: number }[];
  retry_policy: { job: string; retryable: boolean; reason: string }[];
}
export interface FailedJob { id: number; uuid: string; job: string; job_name: string; queue: string; connection: string; attempts: number | null; exception: string; failed_at: string | null; retryable: boolean; retry_policy: string }

export interface EmailSummary {
  days: number;
  mailer: string;
  delivering: boolean;
  broadcasting: string;
  by_status: Record<string, number>;
  stuck: number;
  by_template: { template: string; total: number; failed: number }[];
  errors: { error_code: string; count: number }[];
  trend: DailyPoint[];
  latency_seconds: { median: number; p95: number; sample: number } | null;
  notifications: { created: number; unread: number; by_type: { type: string; count: number }[] };
  retry_note: string;
}
export interface EmailDeliveryRow { id: number; type: string; template: string; recipient: string; subject: string; status: string; attempts: number; error_code: string | null; queued_at: string | null; sent_at: string | null; failed_at: string | null; created_at: string | null }

export interface AdminAlert {
  id: number; rule: string; severity: 'info' | 'warning' | 'critical'; service: string | null; title: string; message: string;
  status: 'open' | 'acknowledged' | 'resolved'; occurrences: number; last_value: Record<string, unknown> | null; suppressed: boolean; notified: boolean;
  first_seen_at: string | null; last_seen_at: string | null; acknowledged_by: UserRef | null; acknowledged_at: string | null;
  resolved_by: UserRef | null; resolved_at: string | null; resolution_note: string | null; incident_id: number | null;
}
export interface Incident {
  id: number; reference: string; title: string; category: string; severity: 'critical' | 'high' | 'medium' | 'low';
  status: 'open' | 'investigating' | 'mitigated' | 'resolved' | 'closed'; service: string | null;
  opened_by: UserRef | null; assignee: UserRef | null; opened_at: string | null; resolved_at: string | null; updated_at: string | null; alerts_count?: number;
}
export interface IncidentDetail extends Incident {
  summary: string | null; root_cause: string | null; corrective_actions: string | null;
  events: { id: number; type: string; body: string | null; metadata: Record<string, unknown> | null; user: UserRef | null; created_at: string | null }[];
  alerts: AdminAlert[];
  audit: { id: number; action: string; user: UserRef | null; created_at: string | null }[];
}
export interface AdminOperation {
  id: number; type: string; label: string; status: 'running' | 'completed' | 'failed'; initiated_by: UserRef | null;
  total_count: number | null; success_count: number | null; failure_count: number | null; summary: Record<string, unknown> | null; error: string | null;
  result_filename: string | null; result_available: boolean; result_expires_at: string | null; started_at: string | null; completed_at: string | null; duration_ms: number | null;
}
export interface MaintenanceWindow { id: number; title: string; description: string | null; services: string[]; starts_at: string; ends_at: string; suppress_alerts: boolean; state: 'scheduled' | 'in_progress' | 'completed' | 'cancelled'; created_by: UserRef | null; cancelled_at: string | null }

// ---------------------------------------------------------------- data + AI
export interface Finding {
  id: number; check: string; category: string; severity: 'high' | 'medium' | 'low'; entity_type: string; entity_id: number | null; entity_label: string | null;
  explanation: string; remediation: string; status: 'open' | 'acknowledged' | 'resolved' | 'ignored'; auto_resolved: boolean;
  first_detected_at: string | null; last_detected_at: string | null; resolved_by: UserRef | null; resolved_at: string | null; resolution_note: string | null;
}
export interface FindingMeta {
  summary: { by_status: Record<string, number>; by_severity: Record<string, number>; by_check: Record<string, number> };
  checks: { key: string; label: string; category: string; severity: string }[];
  last_scan: AdminOperation | null;
}
export interface AiGovernanceData {
  provider: ProviderStatus;
  service: { status: ServiceState; checked_at: string | null; model: string | null; model_loaded: boolean | null; response_ms: number | null };
  configuration: { service_url_configured: boolean; api_key_configured: boolean; request_timeout_seconds: number; connect_timeout_seconds: number; feature_flags: FeatureSetting[]; change_note: string };
  models: { id: number; model_name: string; provider: string; model_type: string; task: string; version: string; is_active: boolean; configuration: Record<string, unknown>; evaluation_runs: number; last_evaluation: { id: number; completed_at: string | null; gate_status: string | null; headline_metric: string | null; headline_value: number | null } | null; registered_at: string | null }[];
  evaluations: { id: number; task: string; status: string; gate_status: string | null; model: { id: number; model_name: string; version: string } | null; dataset: { id: number; name: string; version: string } | null; examples: number | null; processed: number | null; inference_ms: number | null; headline_metric: string | null; headline_value: number | null; metrics: Record<string, number>; regression: boolean | null; size_category: string | null; failure_reason: string | null; completed_at: string | null; created_at: string | null }[];
  evaluation_totals: { completed: number; failed: number; gate_failed: number };
  reviews_90d: { type: string; total: number; accepted: number; rejected: number; overridden: number; reviewed: number; override_rate: number | null }[];
  override_reasons_90d: { reason: string; count: number }[];
  principle: string;
}
export interface AiUsageReport {
  days: number;
  totals: { calls: number; failed: number; avg_ms: number; tokens: number };
  by_operation: { operation: string; calls: number; failed: number; avg_ms: number; tokens: number }[];
  by_user: { user_id: number | null; name: string; email: string | null; calls: number; tokens: number; last_used_at: string | null }[];
  recent: { id: number; operation: string; user: UserRef | null; status_code: number | null; success: boolean; duration_ms: number; estimated_tokens: number; created_at: string | null }[];
}

// ---------------------------------------------------------------- settings + backups
export interface FeatureSetting {
  key: string; label: string; description: string; type: 'boolean' | 'string' | 'select'; value: boolean | string; default: boolean | string;
  options?: { value: string; label: string }[] | null;
}
export interface ToolkitToolStatus { model: string; loaded: boolean; dependencies: Record<string, boolean>; ready: boolean }
/** Local model memory in the AI service: idle models are unloaded, and the process is kept within the budget */
export interface ToolkitMemory { process_mb: number; budget_mb: number; idle_unload_seconds: number; loaded: { name: string; idle_seconds: number; in_use: boolean }[] }
export interface ProviderStatus {
  selected: 'local' | 'hosted';
  toolkit: {
    available: boolean;
    error?: string;
    generation: { local: { configured: boolean; model: string | null; loaded: boolean }; hosted: { configured: boolean; model: string | null; fallback_models?: string[] } } | null;
    tools: Partial<Record<'ocr' | 'transcription' | 'translation' | 'speech' | 'similarity' | 'writing', ToolkitToolStatus>>;
    memory?: ToolkitMemory | null;
  };
  tool_jobs_30d: Record<string, Record<string, number>>;
}
export interface SettingsData {
  features: FeatureSetting[];
  google_configured: boolean;
  history: { id: number; actor: UserRef | null; changed: Record<string, unknown>; previous: Record<string, unknown> | null; reason: string | null; created_at: string | null }[];
}
export interface BackupRecord {
  id: number; filename: string; size_bytes: number; checksum_sha256: string | null; verification_status: 'verified' | 'failed' | null; verified_at: string | null;
  status: 'running' | 'completed' | 'failed'; trigger: 'manual' | 'scheduled'; driver: string | null; error: string | null;
  triggered_by: UserRef | null; started_at: string | null; completed_at: string | null;
}
export interface BackupMeta {
  keep: number; schedule_time: string; last_completed: BackupRecord | null; disk: string; driver: string; failed_30d: number;
  restore: { available_in_panel: boolean; command: string; reason: string };
}
export interface SearchGroup { group: string; items: { id: number | string; title: string; subtitle: string; url: string }[] }
