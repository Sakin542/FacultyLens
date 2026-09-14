import React from 'react';
import {
  AlertOctagon, AlertTriangle, BarChart3, Bell, BrainCircuit, CheckCircle2, ClipboardCheck, FileBarChart2, FileCheck2, Info, MessageSquare, ShieldAlert, Users, XCircle,
} from 'lucide-react';
import type { Notification, NotificationCategory, NotificationSeverity } from '@/types/notification';
import { CATEGORY_LABELS, SEVERITY_LABELS } from '@/types/notification';

/** STEP 47: presentation helpers shared by the bell, dropdown, list and page. Calm, academic palette (white + cream). */

export const CATEGORY_ICONS: Record<NotificationCategory, React.ElementType> = {
  AI: BrainCircuit,
  ASSESSMENT: FileCheck2,
  COLLABORATION: Users,
  REVIEW: ClipboardCheck,
  GRADING: CheckCircle2,
  PERFORMANCE: BarChart3,
  REPORT: FileBarChart2,
  FEEDBACK: MessageSquare,
  SECURITY: ShieldAlert,
  SYSTEM: Bell,
};

/** Severity is conveyed by icon + text label, never by colour alone. */
export const SEVERITY_META: Record<NotificationSeverity, { icon: React.ElementType; className: string; label: string }> = {
  INFO: { icon: Info, className: 'text-sage-500 bg-sage-100 border-sage-200', label: SEVERITY_LABELS.INFO },
  SUCCESS: { icon: CheckCircle2, className: 'text-[#166534] bg-[#F0FDF4] border-[#BBF7D0]', label: SEVERITY_LABELS.SUCCESS },
  WARNING: { icon: AlertTriangle, className: 'text-[#92400E] bg-[#FFFBEB] border-[#FDE68A]', label: SEVERITY_LABELS.WARNING },
  ERROR: { icon: XCircle, className: 'text-[#991B1B] bg-[#FEF2F2] border-[#FECACA]', label: SEVERITY_LABELS.ERROR },
  CRITICAL: { icon: AlertOctagon, className: 'text-[#7F1D1D] bg-[#FEF2F2] border-[#FCA5A5]', label: SEVERITY_LABELS.CRITICAL },
};

export const categoryLabel = (c: NotificationCategory | string): string => CATEGORY_LABELS[c as NotificationCategory] ?? String(c);

export const CategoryIcon: React.FC<{ category: NotificationCategory | string; className?: string }> = ({ category, className }) => {
  const Icon = CATEGORY_ICONS[category as NotificationCategory] ?? Bell;
  return <Icon className={className} aria-hidden="true" strokeWidth={1.8} />;
};

/** "just now", "5 min ago", "3 h ago", "2 d ago", else a short date. */
export function formatRelativeTime(iso: string | null, now: Date = new Date()): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const diff = Math.max(0, now.getTime() - date.getTime());
  const min = Math.floor(diff / 60_000);
  if (min < 1) return 'just now';
  if (min < 60) return `${min} min ago`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h} h ago`;
  const d = Math.floor(h / 24);
  if (d < 7) return `${d} d ago`;
  return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
}

/**
 * A notification may only open an in-app path. Relative paths are returned as-is; absolute URLs are accepted only
 * when they point at this origin (legacy STEP 34 rows). Anything else yields null and the item is not navigable.
 */
export function resolveActionPath(url: string | null | undefined): string | null {
  if (!url) return null;
  const trimmed = url.trim();
  if (trimmed.startsWith('/') && !trimmed.startsWith('//')) return trimmed;
  try {
    const parsed = new URL(trimmed);
    if (typeof window !== 'undefined' && parsed.origin === window.location.origin) {
      return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }
  } catch {
    // not a URL
  }
  return null;
}

export const actionLabel = (n: Notification): string => {
  const label = n.data?.action_label;
  return typeof label === 'string' && label.trim() !== '' ? label : 'Open';
};

export const isUnread = (n: Notification): boolean => !n.read_at;
