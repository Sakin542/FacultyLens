import React, { useContext, useEffect, useMemo, useState } from 'react';
import { AlertCircle, Bell, CheckCircle2, Lock, Mail, RefreshCw } from 'lucide-react';
import { cn } from '@/utils/cn';
import { Button } from '@/components/common/Button';
import { notificationService } from '@/services/notificationService';
import { ApiError } from '@/services/api';
import { AuthContext } from '@/context/AuthContext';
import type { NotificationCategory, NotificationPreference } from '@/types/notification';
import { NOTIFICATION_CATEGORIES, CATEGORY_LABELS } from '@/types/notification';
import { CategoryIcon } from './notificationUi';

const CATEGORY_HELP: Record<NotificationCategory, string> = {
  AI: 'Analysis results, recommendations, rubric drafts and generated questions — always for your review, never applied automatically.',
  ASSESSMENT: 'Version created, approved, finalized, archived or restored on your courses.',
  COLLABORATION: 'Invitations, role changes, comments and mentions.',
  REVIEW: 'Review assignments and completed reviews on assessment versions.',
  GRADING: 'AI grading suggestions and grading consistency reviews.',
  PERFORMANCE: 'Student performance analyses and learning-outcome gaps (aggregate only).',
  REPORT: 'Institutional reports ready to download, or that failed.',
  FEEDBACK: 'Feedback recorded by other faculty on AI recommendations.',
  SECURITY: 'Account-safety alerts. Always delivered in-app and by e-mail.',
  SYSTEM: 'Platform incidents and maintenance notices. Always delivered in-app.',
};

/** Friendly names for the e-mail summary ("AI Updates [ON/OFF]"). */
const EMAIL_CATEGORY_LABELS: Record<NotificationCategory, string> = {
  AI: 'AI Updates', ASSESSMENT: 'Assessment Versions', COLLABORATION: 'Collaboration', REVIEW: 'Reviews', GRADING: 'Grading',
  PERFORMANCE: 'Performance', REPORT: 'Reports', FEEDBACK: 'Feedback', SECURITY: 'Security Alerts', SYSTEM: 'System Notices',
};

type Channel = 'in_app' | 'email';
type Draft = Record<string, { in_app: boolean; email: boolean }>;

const toDraft = (prefs: NotificationPreference[]): Draft =>
  Object.fromEntries(prefs.map((p) => [p.notification_type, { in_app: p.in_app_enabled, email: p.email_enabled }]));

const emailLocked = (p: NotificationPreference) => Boolean(p.email_mandatory) || p.email_available === false;

/**
 * STEP 47 + e-mail system: per-type preference matrix with two independent channels (in-app, e-mail) grouped by
 * category. SECURITY/SYSTEM in-app and SECURITY e-mail are mandatory (rendered locked). Authentication e-mails such as
 * password reset are not notifications and cannot be switched off here. Saves as one PUT; the response is the
 * effective matrix from the server.
 */
export const NotificationPreferences: React.FC<{ className?: string }> = ({ className }) => {
  const auth = useContext(AuthContext);
  const [prefs, setPrefs] = useState<NotificationPreference[] | null>(null);
  const [emailAvailable, setEmailAvailable] = useState(true);
  const [draft, setDraft] = useState<Draft>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const load = async () => {
    if (auth && !auth.isAuthenticated) return;
    setLoading(true);
    setError(null);
    try {
      const res = await notificationService.getPreferences();
      setPrefs(res.data.preferences);
      setEmailAvailable(res.data.email_available !== false);
      setDraft(toDraft(res.data.preferences));
    } catch (e) {
      setError(e instanceof ApiError || e instanceof Error ? e.message : 'Unable to load notification preferences.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (auth && !auth.isAuthenticated) return;
    void load();
  }, [auth?.isAuthenticated]);

  const grouped = useMemo(() => {
    const map = new Map<NotificationCategory, NotificationPreference[]>();
    for (const c of NOTIFICATION_CATEGORIES) map.set(c, []);
    for (const p of prefs ?? []) map.get(p.category)?.push(p);
    return map;
  }, [prefs]);

  const effective = (p: NotificationPreference, channel: Channel): boolean => {
    if (channel === 'in_app') return p.mandatory ? true : (draft[p.notification_type]?.in_app ?? p.in_app_enabled);
    if (p.email_mandatory) return true;
    if (p.email_available === false) return false;
    return draft[p.notification_type]?.email ?? p.email_enabled;
  };

  const dirty = useMemo(() => (prefs ?? []).some((p) =>
    (!p.mandatory && (draft[p.notification_type]?.in_app ?? p.in_app_enabled) !== p.in_app_enabled)
    || (!emailLocked(p) && (draft[p.notification_type]?.email ?? p.email_enabled) !== p.email_enabled)), [prefs, draft]);

  const toggle = (type: string, channel: Channel, value: boolean) => {
    setSaved(false);
    setDraft((d) => ({ ...d, [type]: { ...(d[type] ?? { in_app: true, email: false }), [channel]: value } }));
  };
  const setCategory = (category: NotificationCategory, channel: Channel, value: boolean) => {
    setSaved(false);
    setDraft((d) => {
      const next = { ...d };
      for (const p of grouped.get(category) ?? []) {
        if (channel === 'in_app' ? p.mandatory : emailLocked(p)) continue;
        next[p.notification_type] = { ...(next[p.notification_type] ?? { in_app: p.in_app_enabled, email: p.email_enabled }), [channel]: value };
      }
      return next;
    });
  };

  const save = async () => {
    if (!prefs) return;
    setSaving(true);
    setError(null);
    try {
      const res = await notificationService.updatePreferences(
        prefs.filter((p) => !p.mandatory || !emailLocked(p)).map((p) => ({
          notification_type: p.notification_type,
          in_app_enabled: effective(p, 'in_app'),
          email_enabled: effective(p, 'email'),
        })),
      );
      setPrefs(res.data.preferences);
      setDraft(toDraft(res.data.preferences));
      setSaved(true);
    } catch (e) {
      setError(e instanceof ApiError || e instanceof Error ? e.message : 'Unable to save notification preferences.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div role="status" aria-label="Loading notification preferences" data-testid="preferences-loading" className={cn('rounded-xl border border-sage-200 bg-white p-6 text-sm text-sage-500', className)}>Loading preferences…</div>;
  }
  if (error && !prefs) {
    return (
      <div role="alert" className={cn('rounded-xl border border-sage-200 bg-white p-6 text-center', className)}>
        <AlertCircle className="w-6 h-6 text-[#991B1B] mx-auto mb-2" aria-hidden="true" />
        <p className="text-sm font-medium text-sage-800">Unable to load notification preferences.</p>
        <Button type="button" variant="outline" size="sm" className="mt-3" leftIcon={<RefreshCw className="w-3.5 h-3.5" />} onClick={() => { void load(); }}>Retry</Button>
      </div>
    );
  }

  const switchClass = 'h-4 w-4 rounded border-sage-200 text-sage-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700 disabled:opacity-60';

  return (
    <form className={cn('space-y-4', className)} onSubmit={(e) => { e.preventDefault(); void save(); }} noValidate data-testid="notification-preferences">
      <p className="text-sm text-sage-500">Choose which notifications you receive in the app and which are also sent to your account e-mail. Security notices are always delivered. Turning a notification off never changes the underlying academic workflow — analyses, reports and reviews continue exactly as before.</p>

      {/* E-mail summary: one switch per category (Settings → Notifications → Email Preferences) */}
      <section aria-labelledby="email-prefs-heading" className="rounded-xl border border-sage-200 bg-white overflow-hidden" data-testid="email-preferences">
        <div className="flex items-start gap-3 px-4 py-3 bg-sage-100 border-b border-sage-200">
          <span className="w-8 h-8 rounded-full bg-white border border-sage-200 flex items-center justify-center text-sage-500 shrink-0"><Mail className="w-4 h-4" aria-hidden="true" /></span>
          <div className="min-w-0">
            <h3 id="email-prefs-heading" className="text-sm font-semibold text-sage-800">Email Notifications</h3>
            <p className="text-xs text-sage-500">Sent from FacultyLens to your account address. Password-reset and other authentication e-mails are always sent and are not controlled here.</p>
            {!emailAvailable && <p role="note" className="mt-1 text-xs text-[#92400E]" data-testid="email-unavailable">E-mail delivery is not configured on this server; these settings are saved but no e-mail is sent.</p>}
          </div>
        </div>
        <ul className="divide-y divide-sage-200">
          {NOTIFICATION_CATEGORIES.map((category) => {
            const rows = (grouped.get(category) ?? []).filter((r) => r.email_available !== false);
            if (rows.length === 0) return null;
            const locked = rows.every((r) => r.email_mandatory);
            const on = rows.filter((r) => effective(r, 'email')).length;
            const all = on === rows.length;
            const inputId = `email-cat-${category}`;
            return (
              <li key={category} className="flex items-center justify-between gap-3 px-4 py-2.5">
                <label htmlFor={inputId} className="text-sm text-sage-800 cursor-pointer flex-1 flex items-center gap-2">
                  <CategoryIcon category={category} className="w-3.5 h-3.5 text-sage-500" />
                  {EMAIL_CATEGORY_LABELS[category]}
                  {locked && <Lock className="w-3.5 h-3.5 text-sage-500" aria-label="Mandatory" />}
                </label>
                <span className="flex items-center gap-2">
                  <span className="text-[10px] uppercase tracking-wide text-sage-500" data-testid={`email-cat-state-${category}`}>{locked ? 'Always on' : all ? 'On' : on === 0 ? 'Off' : `${on}/${rows.length}`}</span>
                  <input id={inputId} type="checkbox" role="switch" aria-checked={all} checked={all} disabled={locked} onChange={(e) => setCategory(category, 'email', e.target.checked)} className={switchClass} />
                </span>
              </li>
            );
          })}
        </ul>
      </section>

      {NOTIFICATION_CATEGORIES.map((category) => {
        const rows = grouped.get(category) ?? [];
        if (rows.length === 0) return null;
        const mandatory = rows.every((r) => r.mandatory);
        const enabledCount = rows.filter((r) => effective(r, 'in_app')).length;
        return (
          <fieldset key={category} className="rounded-xl border border-sage-200 bg-white overflow-hidden" data-testid={`pref-group-${category}`}>
            <legend className="sr-only">{CATEGORY_LABELS[category]} notifications</legend>
            <div className="flex items-start justify-between gap-3 px-4 py-3 bg-sage-100 border-b border-sage-200">
              <div className="flex items-start gap-3 min-w-0">
                <span className="w-8 h-8 rounded-full bg-white border border-sage-200 flex items-center justify-center text-sage-500 shrink-0"><CategoryIcon category={category} className="w-4 h-4" /></span>
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-sage-800 flex items-center gap-1.5">{CATEGORY_LABELS[category]}{mandatory && <Lock className="w-3.5 h-3.5 text-sage-500" aria-label="Mandatory" />}</p>
                  <p className="text-xs text-sage-500">{CATEGORY_HELP[category]}</p>
                </div>
              </div>
              {!mandatory && (
                <div className="flex items-center gap-1 shrink-0 text-xs">
                  <button type="button" onClick={() => setCategory(category, 'in_app', true)} className="rounded-md px-2 py-1 text-sage-700 hover:bg-white focus-visible:outline-2 focus-visible:outline-sage-700" aria-label={`Enable all ${CATEGORY_LABELS[category]} notifications`}>All</button>
                  <span aria-hidden="true" className="text-sage-200">|</span>
                  <button type="button" onClick={() => setCategory(category, 'in_app', false)} className="rounded-md px-2 py-1 text-sage-500 hover:bg-white focus-visible:outline-2 focus-visible:outline-sage-700" aria-label={`Disable all ${CATEGORY_LABELS[category]} notifications`}>None</button>
                  <span className="sr-only">{enabledCount} of {rows.length} enabled</span>
                </div>
              )}
            </div>
            <div className="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 px-4 py-1.5 text-[10px] uppercase tracking-wide text-sage-500 border-b border-sage-200" aria-hidden="true">
              <span>Notification</span>
              <span className="inline-flex items-center gap-1 w-14 justify-center"><Bell className="w-3 h-3" /> In-app</span>
              <span className="inline-flex items-center gap-1 w-14 justify-center"><Mail className="w-3 h-3" /> Email</span>
            </div>
            <ul className="divide-y divide-sage-200">
              {rows.map((p) => {
                const inApp = effective(p, 'in_app');
                const email = effective(p, 'email');
                const inputId = `pref-${p.notification_type}`;
                return (
                  <li key={p.notification_type} className="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 px-4 py-2.5">
                    <label htmlFor={inputId} className="text-sm text-sage-800 cursor-pointer">{p.label}</label>
                    <span className="flex items-center justify-center gap-1 w-14">
                      {p.mandatory && <><span className="sr-only">Always on</span><Lock className="w-3 h-3 text-sage-500" aria-hidden="true" /></>}
                      <input id={inputId} type="checkbox" role="switch" aria-checked={inApp} checked={inApp} disabled={p.mandatory} onChange={(e) => toggle(p.notification_type, 'in_app', e.target.checked)} className={switchClass} />
                    </span>
                    <span className="flex items-center justify-center gap-1 w-14">
                      {p.email_mandatory && <Lock className="w-3 h-3 text-sage-500" aria-hidden="true" />}
                      {p.email_available === false
                        ? <span className="text-[10px] text-sage-500" title="Not sent by e-mail">—</span>
                        : <input id={`pref-email-${p.notification_type}`} type="checkbox" role="switch" aria-label={`Email: ${p.label}`} aria-checked={email} checked={email} disabled={emailLocked(p)} onChange={(e) => toggle(p.notification_type, 'email', e.target.checked)} className={switchClass} />}
                    </span>
                  </li>
                );
              })}
            </ul>
          </fieldset>
        );
      })}

      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" size="sm" disabled={!dirty || saving} isLoading={saving} data-testid="save-preferences">Save preferences</Button>
        {saved && !dirty && <span role="status" className="inline-flex items-center gap-1.5 text-xs text-[#166534]"><CheckCircle2 className="w-4 h-4" aria-hidden="true" /> Preferences saved.</span>}
        {error && <span role="alert" className="inline-flex items-center gap-1.5 text-xs text-[#991B1B]"><AlertCircle className="w-4 h-4" aria-hidden="true" /> {error}</span>}
      </div>
    </form>
  );
};
