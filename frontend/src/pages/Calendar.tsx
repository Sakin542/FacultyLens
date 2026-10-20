import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Plus, CalendarDays, Download, Bell, Trash2, X, MapPin } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { planningService, EventPayload } from '@/services/planningService';
import { courseService } from '@/services/courseService';
import type { CalendarEvent, EventType } from '@/types/planning';
import { cn } from '@/utils/cn';

const TYPES: { value: EventType; label: string; chip: string }[] = [
  { value: 'exam', label: 'Exam', chip: 'bg-sage-700 text-white' },
  { value: 'quiz', label: 'Quiz', chip: 'bg-sage-500 text-white' },
  { value: 'assignment_deadline', label: 'Assignment deadline', chip: 'bg-[#FFFBEB] text-[#92400E] border border-[#FDE68A]' },
  { value: 'grading_deadline', label: 'Grading deadline', chip: 'bg-[#FEF2F2] text-[#991B1B] border border-[#FECACA]' },
  { value: 'class', label: 'Class', chip: 'bg-sage-100 text-sage-800 border border-sage-200' },
  { value: 'meeting', label: 'Meeting', chip: 'bg-white text-sage-800 border border-sage-300' },
  { value: 'other', label: 'Other', chip: 'bg-white text-sage-600 border border-sage-200' },
];
const REMINDERS = [{ v: 10080, l: '1 week before' }, { v: 1440, l: '1 day before' }, { v: 60, l: '1 hour before' }, { v: 15, l: '15 minutes before' }];
const typeMeta = (t: EventType) => TYPES.find((x) => x.value === t) ?? TYPES[TYPES.length - 1];
const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const field = 'w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-600';

interface Draft { id?: number; title: string; type: EventType; date: string; time: string; all_day: boolean; location: string; notes: string; course_id: string; reminders: number[] }

/** /calendar — exams, deadlines and meetings with reminders; dated assessments appear automatically. */
export const Calendar: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const initial = params.get('date') ? new Date(`${params.get('date')}T00:00:00`) : new Date();
  const [month, setMonth] = useState(new Date(initial.getFullYear(), initial.getMonth(), 1));
  const [selected, setSelected] = useState(ymd(initial));
  const [events, setEvents] = useState<CalendarEvent[]>([]);
  const [courses, setCourses] = useState<{ id: number; course_code: string }[]>([]);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const gridStart = useMemo(() => { const d = new Date(month); d.setDate(1 - ((d.getDay() + 6) % 7)); return d; }, [month]);
  const days = useMemo(() => Array.from({ length: 42 }, (_, i) => { const d = new Date(gridStart); d.setDate(gridStart.getDate() + i); return d; }), [gridStart]);

  const load = useCallback(() => {
    planningService.events(ymd(days[0]), ymd(days[41])).then(setEvents).catch((e) => setError(e instanceof Error ? e.message : 'Could not load events.'));
  }, [days]);
  useEffect(() => { load(); }, [load]);
  useEffect(() => { courseService.getAll().then((r) => setCourses(r.data.map((c) => ({ id: Number(c.id), course_code: c.course_code ?? '' })))).catch(() => setCourses([])); }, []);

  const byDay = useMemo(() => {
    const map: Record<string, CalendarEvent[]> = {};
    for (const e of events) {
      const key = e.starts_at.slice(0, 10);
      (map[key] ??= []).push(e);
    }
    return map;
  }, [events]);

  const openNew = (date = selected) => setDraft({ title: '', type: 'exam', date, time: '10:00', all_day: false, location: '', notes: '', course_id: '', reminders: [1440, 60] });
  const openEdit = (e: CalendarEvent) => {
    const d = new Date(e.starts_at);
    setDraft({ id: Number(e.id), title: e.title, type: e.type, date: ymd(d), time: `${pad(d.getHours())}:${pad(d.getMinutes())}`, all_day: e.all_day, location: e.location ?? '', notes: e.notes ?? '', course_id: e.course ? String(e.course.id) : '', reminders: e.reminder_offsets });
  };

  const save = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!draft) return;
    if (!draft.title.trim()) { setError('Give the event a title.'); return; }
    setBusy(true);
    setError(null);
    const payload: EventPayload = {
      title: draft.title.trim(), type: draft.type, all_day: draft.all_day,
      starts_at: draft.all_day ? `${draft.date} 00:00:00` : `${draft.date} ${draft.time}:00`,
      location: draft.location || null, notes: draft.notes || null,
      course_id: draft.course_id ? Number(draft.course_id) : null, reminder_offsets: draft.reminders,
    };
    try {
      if (draft.id) await planningService.updateEvent(draft.id, payload); else await planningService.createEvent(payload);
      setDraft(null);
      setSelected(draft.date);
      load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not save the event.');
    } finally {
      setBusy(false);
    }
  };

  const today = ymd(new Date());
  const dayEvents = byDay[selected] ?? [];
  const upcoming = events.filter((e) => e.starts_at.slice(0, 10) >= today).slice(0, 8);

  return (
    <div className="space-y-5 page-enter" data-testid="calendar-page">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><CalendarDays className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
          <div>
            <h2 className="text-xl font-semibold tracking-tight text-sage-800">Academic calendar</h2>
            <p className="text-sm text-sage-500">Exams, deadlines and meetings with reminders by notification and e-mail. Dated assessments appear automatically.</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" leftIcon={<Download className="w-3.5 h-3.5" />} onClick={() => planningService.downloadIcs().catch((e) => setError(e.message))}>Export to Google/Outlook (.ics)</Button>
          <Button size="sm" leftIcon={<Plus className="w-3.5 h-3.5" />} onClick={() => openNew()} data-testid="new-event">New event</Button>
        </div>
      </div>

      {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]">{error}</p>}

      <div className="grid gap-5 xl:grid-cols-[1fr,320px]">
        <section className="rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Month">
          <header className="flex items-center justify-between border-b border-sage-200 px-4 py-3">
            <button type="button" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))} className="rounded-md p-1.5 text-sage-600 hover:bg-sage-100" aria-label="Previous month"><ChevronLeft className="w-4 h-4" /></button>
            <h3 className="text-sm font-semibold text-sage-800" data-testid="calendar-month">{month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</h3>
            <button type="button" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))} className="rounded-md p-1.5 text-sage-600 hover:bg-sage-100" aria-label="Next month"><ChevronRight className="w-4 h-4" /></button>
          </header>
          <div className="grid grid-cols-7 border-b border-sage-100 text-center text-[11px] uppercase tracking-[0.12em] text-sage-500">
            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="py-2">{d}</div>)}
          </div>
          <div className="grid grid-cols-7" role="grid">
            {days.map((d) => {
              const key = ymd(d);
              const list = byDay[key] ?? [];
              const inMonth = d.getMonth() === month.getMonth();
              return (
                <button key={key} type="button" role="gridcell" aria-selected={selected === key} aria-label={`${d.toDateString()}, ${list.length} event(s)`}
                  onClick={() => { setSelected(key); setParams({ date: key }, { replace: true }); }} onDoubleClick={() => openNew(key)}
                  className={cn('min-h-[92px] border-b border-r border-sage-100 p-1.5 text-left align-top transition-colors hover:bg-sage-50', !inMonth && 'bg-sage-50/60 text-sage-400', selected === key && 'ring-2 ring-inset ring-sage-600')}>
                  <span className={cn('inline-flex h-6 w-6 items-center justify-center rounded-full text-xs', key === today ? 'bg-sage-700 font-semibold text-white' : 'text-sage-700')}>{d.getDate()}</span>
                  <span className="mt-1 block space-y-0.5">
                    {list.slice(0, 3).map((e) => <span key={e.id} className={cn('block truncate rounded px-1 py-0.5 text-[10px] font-medium', typeMeta(e.type).chip)}>{e.course && !e.title.startsWith(e.course.course_code) ? `${e.course.course_code} ` : ''}{e.title}</span>)}
                    {list.length > 3 && <span className="block text-[10px] text-sage-500">+{list.length - 3} more</span>}
                  </span>
                </button>
              );
            })}
          </div>
        </section>

        <aside className="space-y-4">
          <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Selected day" data-testid="day-events">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-sage-800">{new Date(`${selected}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}</h3>
              <button type="button" onClick={() => openNew()} className="text-xs font-medium text-sage-700 hover:underline">Add</button>
            </div>
            {dayEvents.length === 0 ? <p className="mt-2 text-sm text-sage-500">Nothing scheduled.</p> : (
              <ul className="mt-2 space-y-2">
                {dayEvents.map((e) => (
                  <li key={e.id} className="rounded-lg border border-sage-200 p-2.5">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <span className={cn('inline-block rounded px-1.5 py-0.5 text-[10px] font-medium', typeMeta(e.type).chip)}>{typeMeta(e.type).label}</span>
                        <p className="mt-1 text-sm font-medium text-sage-800">{e.title}</p>
                        <p className="text-xs text-sage-500">{e.all_day ? 'All day' : new Date(e.starts_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}{e.course ? ` · ${e.course.course_code}` : ''}</p>
                        {e.location && <p className="flex items-center gap-1 text-xs text-sage-500"><MapPin className="w-3 h-3" aria-hidden="true" />{e.location}</p>}
                        {e.reminder_offsets.length > 0 && <p className="flex items-center gap-1 text-[11px] text-sage-500"><Bell className="w-3 h-3" aria-hidden="true" />{e.reminder_offsets.map((o) => REMINDERS.find((r) => r.v === o)?.l ?? `${o} min before`).join(', ')}</p>}
                        {e.source === 'assessment' && <p className="text-[11px] text-sage-500">From the assessment date</p>}
                      </div>
                      {e.editable && <button type="button" onClick={() => openEdit(e)} className="text-xs text-sage-700 hover:underline">Edit</button>}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Upcoming">
            <h3 className="text-sm font-semibold text-sage-800">Coming up</h3>
            {upcoming.length === 0 ? <p className="mt-2 text-sm text-sage-500">No upcoming items this month.</p> : (
              <ul className="mt-2 space-y-1.5">
                {upcoming.map((e) => (
                  <li key={e.id} className="flex items-center justify-between gap-2 text-sm">
                    <span className="truncate text-sage-800">{e.title}</span>
                    <span className="shrink-0 text-xs text-sage-500">{new Date(e.starts_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </aside>
      </div>

      {draft && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label={draft.id ? 'Edit event' : 'New event'}>
          <div className="absolute inset-0 bg-sage-800/50" onClick={() => setDraft(null)} aria-hidden="true" />
          <form onSubmit={save} className="relative w-full max-w-lg space-y-3 rounded-xl bg-white p-5 shadow-elevated" data-testid="event-form">
            <div className="flex items-center justify-between">
              <h3 className="text-base font-semibold text-sage-800">{draft.id ? 'Edit event' : 'New event'}</h3>
              <button type="button" onClick={() => setDraft(null)} className="rounded-md p-1 text-sage-500 hover:bg-sage-100" aria-label="Close"><X className="w-4 h-4" /></button>
            </div>
            <label className="block text-xs font-medium text-sage-700">Title<input autoFocus className={cn(field, 'mt-1')} value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} placeholder="e.g. CSE311 final exam" /></label>
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="text-xs font-medium text-sage-700">Type<select className={cn(field, 'mt-1')} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value as EventType })}>{TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select></label>
              <label className="text-xs font-medium text-sage-700">Course (optional)<select className={cn(field, 'mt-1')} value={draft.course_id} onChange={(e) => setDraft({ ...draft, course_id: e.target.value })}><option value="">None</option>{courses.map((c) => <option key={c.id} value={c.id}>{c.course_code}</option>)}</select></label>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
              <label className="text-xs font-medium text-sage-700">Date<input type="date" className={cn(field, 'mt-1')} value={draft.date} onChange={(e) => setDraft({ ...draft, date: e.target.value })} required /></label>
              <label className="text-xs font-medium text-sage-700">Time<input type="time" disabled={draft.all_day} className={cn(field, 'mt-1')} value={draft.time} onChange={(e) => setDraft({ ...draft, time: e.target.value })} /></label>
              <label className="mt-6 flex items-center gap-2 text-xs text-sage-700"><input type="checkbox" checked={draft.all_day} onChange={(e) => setDraft({ ...draft, all_day: e.target.checked })} className="rounded border-sage-300" />All day</label>
            </div>
            <label className="block text-xs font-medium text-sage-700">Location<input className={cn(field, 'mt-1')} value={draft.location} onChange={(e) => setDraft({ ...draft, location: e.target.value })} placeholder="e.g. Room 7A04" /></label>
            <fieldset>
              <legend className="text-xs font-medium text-sage-700">Remind me</legend>
              <div className="mt-1 flex flex-wrap gap-2">
                {REMINDERS.map((r) => (
                  <label key={r.v} className={cn('cursor-pointer rounded-lg border px-2.5 py-1 text-xs', draft.reminders.includes(r.v) ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 text-sage-700')}>
                    <input type="checkbox" className="sr-only" checked={draft.reminders.includes(r.v)} onChange={(e) => setDraft({ ...draft, reminders: e.target.checked ? [...draft.reminders, r.v] : draft.reminders.filter((x) => x !== r.v) })} />{r.l}
                  </label>
                ))}
              </div>
            </fieldset>
            <label className="block text-xs font-medium text-sage-700">Notes<textarea rows={2} className={cn(field, 'mt-1')} value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} /></label>
            <div className="flex items-center gap-2 pt-1">
              <Button type="submit" isLoading={busy} data-testid="save-event">Save</Button>
              <Button type="button" variant="ghost" onClick={() => setDraft(null)}>Cancel</Button>
              {draft.id && (
                <Button type="button" variant="danger" className="ml-auto" leftIcon={<Trash2 className="w-3.5 h-3.5" />}
                  onClick={async () => { if (draft.id && window.confirm('Delete this event?')) { await planningService.deleteEvent(draft.id); setDraft(null); load(); } }}>Delete</Button>
              )}
            </div>
          </form>
        </div>
      )}
    </div>
  );
};
