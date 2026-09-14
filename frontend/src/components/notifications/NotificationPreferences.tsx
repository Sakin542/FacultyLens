import React, { useEffect, useMemo, useState } from 'react';
import { AlertCircle, CheckCircle2, Lock, RefreshCw } from 'lucide-react';
import { cn } from '@/utils/cn';
import { Button } from '@/components/common/Button';
import { notificationService } from '@/services/notificationService';
import { ApiError } from '@/services/api';
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
  SECURITY: 'Account-safety alerts. Always delivered.',
  SYSTEM: 'Platform incidents and maintenance notices. Always delivered.',
};

/**
 * STEP 47: per-type in-app preference matrix grouped by category. SECURITY and SYSTEM are mandatory
 * (rendered as locked). Saves as one PUT; the response is the effective matrix from the server.
 */
export const NotificationPreferences: React.FC<{ className?: string }> = ({ className }) => {
  const [prefs, setPrefs] = useState<NotificationPreference[] | null>(null);
  const [draft, setDraft] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await notificationService.getPreferences();
      setPrefs(res.data.preferences);
      setDraft(Object.fromEntries(res.data.preferences.map((p) => [p.notification_type, p.in_app_enabled])));
    } catch (e) {
      setError(e instanceof ApiError || e instanceof Error ? e.message : 'Unable to load notification preferences.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const grouped = useMemo(() => {
    const map = new Map<NotificationCategory, NotificationPreference[]>();
    for (const c of NOTIFICATION_CATEGORIES) map.set(c, []);
    for (const p of prefs ?? []) map.get(p.category)?.push(p);
    return map;
  }, [prefs]);

  const dirty = useMemo(() => (prefs ?? []).some((p) => !p.mandatory && draft[p.notification_type] !== p.in_app_enabled), [prefs, draft]);

  const toggle = (type: string, value: boolean) => { setSaved(false); setDraft((d) => ({ ...d, [type]: value })); };
  const setCategory = (category: NotificationCategory, value: boolean) => {
    setSaved(false);
    setDraft((d) => {
      const next = { ...d };
      for (const p of grouped.get(category) ?? []) if (!p.mandatory) next[p.notification_type] = value;
      return next;
    });
  };

  const save = async () => {
    if (!prefs) return;
    setSaving(true);
    setError(null);
    try {
      const res = await notificationService.updatePreferences(prefs.filter((p) => !p.mandatory).map((p) => ({ notification_type: p.notification_type, in_app_enabled: draft[p.notification_type] ?? true })));
      setPrefs(res.data.preferences);
      setDraft(Object.fromEntries(res.data.preferences.map((p) => [p.notification_type, p.in_app_enabled])));
      setSaved(true);
    } catch (e) {
      setError(e instanceof ApiError || e instanceof Error ? e.message : 'Unable to save notification preferences.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div role="status" aria-label="Loading notification preferences" data-testid="preferences-loading" className={cn('rounded-xl border border-[#E7E2D8] bg-white p-6 text-sm text-[#6B6B63]', className)}>Loading preferences…</div>;
  }
  if (error && !prefs) {
    return (
      <div role="alert" className={cn('rounded-xl border border-[#E7E2D8] bg-white p-6 text-center', className)}>
        <AlertCircle className="w-6 h-6 text-[#991B1B] mx-auto mb-2" aria-hidden="true" />
        <p className="text-sm font-medium text-[#171717]">Unable to load notification preferences.</p>
        <Button type="button" variant="outline" size="sm" className="mt-3" leftIcon={<RefreshCw className="w-3.5 h-3.5" />} onClick={() => { void load(); }}>Retry</Button>
      </div>
    );
  }

  return (
    <form className={cn('space-y-4', className)} onSubmit={(e) => { e.preventDefault(); void save(); }} noValidate data-testid="notification-preferences">
      <p className="text-sm text-[#6B6B63]">Choose which in-app notifications you receive. Security and system notices are always delivered. Turning a notification off never changes the underlying academic workflow — analyses, reports and reviews continue exactly as before.</p>

      {NOTIFICATION_CATEGORIES.map((category) => {
        const rows = grouped.get(category) ?? [];
        if (rows.length === 0) return null;
        const mandatory = rows.every((r) => r.mandatory);
        const enabledCount = rows.filter((r) => draft[r.notification_type] ?? r.in_app_enabled).length;
        return (
          <fieldset key={category} className="rounded-xl border border-[#E7E2D8] bg-white overflow-hidden" data-testid={`pref-group-${category}`}>
            <legend className="sr-only">{CATEGORY_LABELS[category]} notifications</legend>
            <div className="flex items-start justify-between gap-3 px-4 py-3 bg-[#F7F4EE] border-b border-[#E7E2D8]">
              <div className="flex items-start gap-3 min-w-0">
                <span className="w-8 h-8 rounded-full bg-white border border-[#E7E2D8] flex items-center justify-center text-[#4C6B62] shrink-0"><CategoryIcon category={category} className="w-4 h-4" /></span>
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-[#171717] flex items-center gap-1.5">{CATEGORY_LABELS[category]}{mandatory && <Lock className="w-3.5 h-3.5 text-[#6B6B63]" aria-label="Mandatory" />}</p>
                  <p className="text-xs text-[#6B6B63]">{CATEGORY_HELP[category]}</p>
                </div>
              </div>
              {!mandatory && (
                <div className="flex items-center gap-1 shrink-0 text-xs">
                  <button type="button" onClick={() => setCategory(category, true)} className="rounded-md px-2 py-1 text-[#1E6F5C] hover:bg-white focus-visible:outline-2 focus-visible:outline-[#1E6F5C]" aria-label={`Enable all ${CATEGORY_LABELS[category]} notifications`}>All</button>
                  <span aria-hidden="true" className="text-[#E7E2D8]">|</span>
                  <button type="button" onClick={() => setCategory(category, false)} className="rounded-md px-2 py-1 text-[#6B6B63] hover:bg-white focus-visible:outline-2 focus-visible:outline-[#1E6F5C]" aria-label={`Disable all ${CATEGORY_LABELS[category]} notifications`}>None</button>
                  <span className="sr-only">{enabledCount} of {rows.length} enabled</span>
                </div>
              )}
            </div>
            <ul className="divide-y divide-[#E7E2D8]">
              {rows.map((p) => {
                const checked = p.mandatory ? true : (draft[p.notification_type] ?? p.in_app_enabled);
                const inputId = `pref-${p.notification_type}`;
                return (
                  <li key={p.notification_type} className="flex items-center justify-between gap-3 px-4 py-2.5">
                    <label htmlFor={inputId} className="text-sm text-[#171717] cursor-pointer flex-1">{p.label}</label>
                    <span className="flex items-center gap-2">
                      {p.mandatory && <span className="text-[10px] uppercase tracking-wide text-[#6B6B63]">Always on</span>}
                      <input
                        id={inputId}
                        type="checkbox"
                        role="switch"
                        aria-checked={checked}
                        checked={checked}
                        disabled={p.mandatory}
                        onChange={(e) => toggle(p.notification_type, e.target.checked)}
                        className="h-4 w-4 rounded border-[#E7E2D8] text-[#1E6F5C] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E6F5C] disabled:opacity-60"
                      />
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
