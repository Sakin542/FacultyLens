import {
  Activity, BellRing, BookOpenCheck, Bot, Building2, CalendarClock, ClipboardList, Cpu, DatabaseBackup, FileSearch, Gauge,
  HelpCircle, KeyRound, LayoutDashboard, ListChecks, Mail, Settings2, ShieldAlert, ShieldCheck, Siren, UserCog, Users, Workflow,
} from 'lucide-react';

export interface NavItem {
  key: string;
  label: string;
  path: string;
  icon: React.ElementType;
  description: string;
  keywords?: string;
}
export interface NavGroup { key: string; label: string; items: NavItem[] }

/**
 * Admin information architecture. Every item is a working page backed by real endpoints; modules that are not built
 * are listed in Help → Coverage instead of appearing as empty pages.
 */
export const ADMIN_NAV: NavGroup[] = [
  {
    key: 'overview', label: 'Overview', items: [
      { key: 'dashboard', label: 'Dashboard', path: '/admin', icon: LayoutDashboard, description: 'KPIs, trends, service status and recent activity', keywords: 'home overview kpi' },
      { key: 'tasks', label: 'Task center', path: '/admin/tasks', icon: ListChecks, description: 'Imports, exports, scans and checks with their outcomes', keywords: 'operations jobs exports downloads' },
      { key: 'schedule', label: 'Calendar & schedule', path: '/admin/schedule', icon: CalendarClock, description: 'Maintenance windows and scheduled tasks', keywords: 'maintenance cron calendar' },
    ],
  },
  {
    key: 'people', label: 'People & access', items: [
      { key: 'users', label: 'Users', path: '/admin/users', icon: Users, description: 'Accounts, invitations, suspension, bulk changes and CSV import', keywords: 'faculty accounts invite import' },
      { key: 'access', label: 'Roles & permissions', path: '/admin/access', icon: KeyRound, description: 'Role matrix, privileged access and role history', keywords: 'rbac permission privileged' },
      { key: 'security', label: 'Security center', path: '/admin/security', icon: ShieldAlert, description: 'Failed sign-ins, posture and security events', keywords: 'login 2fa sessions' },
      { key: 'audit', label: 'Audit log', path: '/admin/audit', icon: FileSearch, description: 'Every recorded administrative and security event', keywords: 'history compliance log' },
    ],
  },
  {
    key: 'institution', label: 'Institution', items: [
      { key: 'institution', label: 'Academic structure', path: '/admin/institution', icon: Building2, description: 'Departments, programs, terms and course metadata', keywords: 'departments programs courses semesters' },
    ],
  },
  {
    key: 'ops', label: 'Operations', items: [
      { key: 'health', label: 'System health', path: '/admin/health', icon: Activity, description: 'Service states, latency and availability history', keywords: 'status uptime monitoring environment' },
      { key: 'queues', label: 'Queues & jobs', path: '/admin/queues', icon: Workflow, description: 'Queue depth, failed jobs and safe retries', keywords: 'horizon failed jobs worker' },
      { key: 'email', label: 'E-mail & notifications', path: '/admin/email', icon: Mail, description: 'Delivery status, failures and latency', keywords: 'smtp delivery notifications' },
      { key: 'alerts', label: 'Alerts', path: '/admin/alerts', icon: BellRing, description: 'Threshold alerts with acknowledgement and resolution', keywords: 'alarm warning' },
      { key: 'incidents', label: 'Incidents', path: '/admin/incidents', icon: Siren, description: 'Incident timeline, root cause and corrective actions', keywords: 'outage postmortem' },
      { key: 'backups', label: 'Backup & recovery', path: '/admin/backups', icon: DatabaseBackup, description: 'Backups, integrity verification and restore procedure', keywords: 'restore disaster recovery' },
    ],
  },
  {
    key: 'governance', label: 'Data & AI', items: [
      { key: 'data-quality', label: 'Data quality', path: '/admin/data-quality', icon: BookOpenCheck, description: 'Read-only integrity checks and findings', keywords: 'integrity findings scan' },
      { key: 'ai', label: 'AI governance', path: '/admin/ai', icon: Bot, description: 'Model registry, evaluations and faculty overrides', keywords: 'model evaluation accuracy' },
      { key: 'ai-evaluation', label: 'AI evaluation', path: '/admin/ai-evaluation', icon: Gauge, description: 'Evaluation datasets, runs, metrics, quality gates and regressions', keywords: 'accuracy f1 dataset benchmark model performance' },
      { key: 'ai-usage', label: 'AI usage', path: '/admin/ai-usage', icon: Cpu, description: 'Calls, latency and failures per operation and user', keywords: 'tokens metering' },
      { key: 'reports', label: 'Reports & exports', path: '/admin/reports', icon: ClipboardList, description: 'Formula-safe CSV exports with expiring downloads', keywords: 'export csv download' },
    ],
  },
  {
    key: 'settings', label: 'Settings', items: [
      { key: 'settings', label: 'Platform settings', path: '/admin/settings', icon: Settings2, description: 'Feature flags, announcement banner and change history', keywords: 'feature flags toggles' },
      { key: 'environment', label: 'Environment', path: '/admin/environment', icon: Gauge, description: 'Version, drivers, configuration status and migrations', keywords: 'config version migrations' },
      { key: 'account', label: 'My account', path: '/admin/account', icon: UserCog, description: 'Your profile, password, two-factor authentication and notification preferences', keywords: 'profile password 2fa two-factor' },
      { key: 'help', label: 'Help & coverage', path: '/admin/help', icon: HelpCircle, description: 'How the panel works and what is not built', keywords: 'docs documentation' },
    ],
  },
];

export const ALL_NAV_ITEMS = ADMIN_NAV.flatMap((g) => g.items.map((i) => ({ ...i, group: g.label })));

/** Longest matching nav item for a path (so /admin/users/12 resolves to Users). */
export const navItemFor = (pathname: string) =>
  [...ALL_NAV_ITEMS].sort((a, b) => b.path.length - a.path.length).find((i) => (i.path === '/admin' ? pathname === '/admin' : pathname.startsWith(i.path)));

export { ShieldCheck };
