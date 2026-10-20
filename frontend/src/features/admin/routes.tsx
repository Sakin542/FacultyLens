import React, { lazy } from 'react';
import { Navigate, Route } from 'react-router-dom';
import { AdminRoute } from '@/components/common/AdminRoute';
import { AdminLayout } from './layout/AdminLayout';

const named = <K extends string>(loader: () => Promise<Record<K, React.ComponentType>>, name: K) =>
  lazy(() => loader().then((m) => ({ default: m[name] })));

const access = () => import('./pages/AccessPages');
const ops = () => import('./pages/OperationsPages');
const response = () => import('./pages/ResponsePages');
const gov = () => import('./pages/GovernancePages');

const DashboardPage = named(() => import('./pages/DashboardPage'), 'DashboardPage');
const UsersPage = named(() => import('./pages/UsersPage'), 'UsersPage');
const UserDetailPage = named(() => import('./pages/UserDetailPage'), 'UserDetailPage');
const AccessPage = named(access, 'AccessPage');
const AuditPage = named(access, 'AuditPage');
const SecurityPage = named(access, 'SecurityPage');
const InstitutionPage = named(ops, 'InstitutionPage');
const HealthPage = named(ops, 'HealthPage');
const EnvironmentPage = named(ops, 'EnvironmentPage');
const QueuesPage = named(ops, 'QueuesPage');
const EmailPage = named(ops, 'EmailPage');
const AlertsPage = named(response, 'AlertsPage');
const IncidentsPage = named(response, 'IncidentsPage');
const IncidentDetailPage = named(response, 'IncidentDetailPage');
const TasksPage = named(response, 'TasksPage');
const ReportsPage = named(response, 'ReportsPage');
const SchedulePage = named(response, 'SchedulePage');
const BackupsPage = named(response, 'BackupsPage');
const DataQualityPage = named(gov, 'DataQualityPage');
const AiGovernancePage = named(gov, 'AiGovernancePage');
const AiUsagePage = named(gov, 'AiUsagePage');
const SettingsPage = named(gov, 'SettingsPage');
const HelpPage = named(gov, 'HelpPage');
const AiEvaluationPage = named(() => import('@/pages/AiEvaluation'), 'AiEvaluation');
// The administrator's own profile, password, two-factor and notification preferences (shared account settings page)
const AccountPage = named(() => import('@/pages/Settings'), 'Settings');

/** /admin/* — guarded by AdminRoute (UX) and the server-side `admin` middleware (authority). */
export const adminRoutes = (
  <Route element={<AdminRoute />}>
    <Route path="/admin" element={<AdminLayout />}>
      <Route index element={<DashboardPage />} />
      <Route path="users" element={<UsersPage />} />
      <Route path="users/:userId" element={<UserDetailPage />} />
      <Route path="access" element={<AccessPage />} />
      <Route path="security" element={<SecurityPage />} />
      <Route path="audit" element={<AuditPage />} />
      <Route path="institution" element={<InstitutionPage />} />
      <Route path="health" element={<HealthPage />} />
      <Route path="environment" element={<EnvironmentPage />} />
      <Route path="queues" element={<QueuesPage />} />
      <Route path="email" element={<EmailPage />} />
      <Route path="alerts" element={<AlertsPage />} />
      <Route path="incidents" element={<IncidentsPage />} />
      <Route path="incidents/:incidentId" element={<IncidentDetailPage />} />
      <Route path="tasks" element={<TasksPage />} />
      <Route path="reports" element={<ReportsPage />} />
      <Route path="schedule" element={<SchedulePage />} />
      <Route path="backups" element={<BackupsPage />} />
      <Route path="data-quality" element={<DataQualityPage />} />
      <Route path="ai" element={<AiGovernancePage />} />
      <Route path="ai-evaluation" element={<AiEvaluationPage />} />
      <Route path="ai-usage" element={<AiUsagePage />} />
      <Route path="settings" element={<SettingsPage />} />
      <Route path="help" element={<HelpPage />} />
      <Route path="account" element={<AccountPage />} />
      {/* Paths from the previous single-page admin panel */}
      <Route path="overview" element={<Navigate to="/admin" replace />} />
      <Route path="*" element={<Navigate to="/admin" replace />} />
    </Route>
  </Route>
);
