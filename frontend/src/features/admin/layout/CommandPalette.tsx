import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Clock, CornerDownLeft, Loader2, Search, Trash2 } from 'lucide-react';
import { cn } from '@/utils/cn';
import { ALL_NAV_ITEMS } from '../nav';
import { adminApi } from '../api';
import { useDebounced } from '../hooks';
import type { SearchGroup } from '../types';

const RECENT_KEY = 'facultylens.admin.recent';

interface Entry { key: string; group: string; title: string; subtitle?: string; url: string; icon?: React.ElementType }

const readRecent = (): Entry[] => {
  try {
    return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').slice(0, 6);
  } catch {
    return [];
  }
};

export const rememberDestination = (entry: Omit<Entry, 'key' | 'icon'>) => {
  try {
    const next = [{ ...entry, key: `recent:${entry.url}` }, ...readRecent().filter((r) => r.url !== entry.url)].slice(0, 6);
    localStorage.setItem(RECENT_KEY, JSON.stringify(next));
  } catch {
    // private mode / blocked storage: recents are a convenience only
  }
};

/**
 * Ctrl/⌘+K command palette: admin pages, recent destinations (stored only in this browser, clearable) and
 * server-side search over names/codes/titles. It navigates; it never runs destructive actions.
 */
export const CommandPalette: React.FC<{ open: boolean; onClose: () => void }> = ({ open, onClose }) => {
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [active, setActive] = useState(0);
  const [remote, setRemote] = useState<SearchGroup[]>([]);
  const [searching, setSearching] = useState(false);
  const [recent, setRecent] = useState<Entry[]>([]);
  const input = useRef<HTMLInputElement>(null);
  const term = useDebounced(q.trim(), 250);

  useEffect(() => {
    if (open) {
      setQ('');
      setActive(0);
      setRemote([]);
      setRecent(readRecent());
      setTimeout(() => input.current?.focus(), 0);
    }
  }, [open]);

  useEffect(() => {
    if (!open || term.length < 2) { setRemote([]); return; }
    let cancelled = false;
    setSearching(true);
    adminApi.search(term).then((groups) => { if (!cancelled) setRemote(groups); }).catch(() => { if (!cancelled) setRemote([]); })
      .finally(() => { if (!cancelled) setSearching(false); });
    return () => { cancelled = true; };
  }, [term, open]);

  const entries = useMemo<Entry[]>(() => {
    const lower = q.trim().toLowerCase();
    const pages = ALL_NAV_ITEMS
      .filter((i) => !lower || `${i.label} ${i.description} ${i.keywords ?? ''} ${i.group}`.toLowerCase().includes(lower))
      .map((i) => ({ key: `page:${i.key}`, group: 'Pages', title: i.label, subtitle: i.description, url: i.path, icon: i.icon }));
    const results = remote.flatMap((g) => g.items.map((it) => ({ key: `${g.group}:${it.id}`, group: g.group, title: it.title, subtitle: it.subtitle, url: it.url })));
    return [...(lower ? [] : recent.map((r) => ({ ...r, group: 'Recent' }))), ...pages.slice(0, lower ? 6 : 8), ...results];
  }, [q, remote, recent]);

  useEffect(() => { setActive(0); }, [entries.length]);

  if (!open) return null;

  const go = (e: Entry) => {
    rememberDestination({ group: e.group === 'Recent' ? 'Recent' : e.group, title: e.title, subtitle: e.subtitle, url: e.url });
    onClose();
    navigate(e.url);
  };

  const onKey = (ev: React.KeyboardEvent) => {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); setActive((a) => Math.min(entries.length - 1, a + 1)); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
    else if (ev.key === 'Enter' && entries[active]) { ev.preventDefault(); go(entries[active]); }
    else if (ev.key === 'Escape') { ev.preventDefault(); onClose(); }
  };

  let lastGroup = '';
  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center bg-sage-800/60 p-3 pt-[10vh]" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div role="dialog" aria-modal="true" aria-label="Search the admin panel" className="w-full max-w-xl overflow-hidden rounded-2xl border border-adm-line bg-adm-surface shadow-2xl" onKeyDown={onKey}>
        <div className="flex items-center gap-2 border-b border-adm-line px-4">
          <Search className="h-4 w-4 text-adm-muted" aria-hidden="true" />
          {/* autoFocus: focus on mount, so keys typed right after Ctrl+K are not lost (the effect below is a fallback) */}
          <input ref={input} autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search pages, users, courses, incidents…"
            role="combobox" aria-expanded="true" aria-controls="admin-palette-list" aria-activedescendant={entries[active] ? `pal-${active}` : undefined}
            className="h-12 flex-1 bg-transparent text-sm text-adm-ink placeholder:text-sage-400 focus:outline-none" aria-label="Search" data-testid="palette-input" />
          {searching && <Loader2 className="h-4 w-4 animate-spin text-adm-muted" aria-label="Searching" />}
          <kbd className="rounded border border-adm-line px-1.5 text-[10px] text-adm-muted">Esc</kbd>
        </div>
        <ul id="admin-palette-list" role="listbox" className="max-h-[55vh] overflow-y-auto py-1">
          {entries.length === 0 && <li className="px-4 py-8 text-center text-sm text-adm-muted">{term.length >= 2 && !searching ? 'No matches.' : 'Type at least two characters to search records.'}</li>}
          {entries.map((e, i) => {
            const header = e.group !== lastGroup ? e.group : null;
            lastGroup = e.group;
            const Icon = e.icon ?? (e.group === 'Recent' ? Clock : undefined);
            return (
              <React.Fragment key={e.key}>
                {header && (
                  <li role="presentation" className="flex items-center justify-between px-4 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.1em] text-adm-muted">
                    {header}
                    {header === 'Recent' && (
                      <button type="button" className="inline-flex items-center gap-1 normal-case tracking-normal hover:text-adm-ink"
                        onClick={() => { try { localStorage.removeItem(RECENT_KEY); } catch { /* ignore */ } setRecent([]); }}>
                        <Trash2 className="h-3 w-3" aria-hidden="true" />Clear history
                      </button>
                    )}
                  </li>
                )}
                <li id={`pal-${i}`} role="option" aria-selected={i === active} onMouseEnter={() => setActive(i)} onMouseDown={(ev) => { ev.preventDefault(); go(e); }}
                  className={cn('mx-1 flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2', i === active ? 'bg-adm-cream' : '')}>
                  {Icon ? <Icon className="h-4 w-4 shrink-0 text-adm-muted" aria-hidden="true" /> : <span className="h-4 w-4 shrink-0" />}
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-adm-ink">{e.title}</span>
                    {e.subtitle && <span className="block truncate text-xs text-adm-muted">{e.subtitle}</span>}
                  </span>
                  {i === active && <CornerDownLeft className="h-3.5 w-3.5 text-adm-muted" aria-hidden="true" />}
                </li>
              </React.Fragment>
            );
          })}
        </ul>
        <p className="border-t border-adm-line bg-adm-warm px-4 py-2 text-[11px] text-adm-muted">↑↓ to move · Enter to open · Results show names and titles only; recents stay in this browser.</p>
      </div>
    </div>
  );
};
