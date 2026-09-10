import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { AtSign, CheckCircle2, CornerDownRight, MessageSquare, Pencil, RotateCcw, Trash2 } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { collaborationService } from '@/services/collaborationService';
import { CollaborationComment as CommentType, CommentableType, MentionableUser } from '@/types/collaboration';
import { CollaborationEmptyState, CollaborationError, CollaborationLoading, getCollaborationErrorMessage } from './CollaborationStates';
import { cn } from '@/utils/cn';

/**
 * STEP 34: discussion threads. Bodies are rendered as text only (never HTML). Mentions are picked from
 * course members returned by the server, never from free user discovery.
 */

export const MentionSelector: React.FC<{ members: MentionableUser[]; selected: number[]; onChange: (ids: number[]) => void; currentUserId?: number }> = ({ members, selected, onChange, currentUserId }) => {
  const options = members.filter((m) => m.id !== currentUserId);
  if (options.length === 0) return null;
  return (
    <div data-testid="mention-selector" className="flex flex-wrap items-center gap-1.5 text-xs">
      <AtSign className="w-3.5 h-3.5 text-[#737373]" />
      {options.map((m) => {
        const on = selected.includes(m.id);
        return (
          <button key={m.id} type="button" aria-pressed={on} onClick={() => onChange(on ? selected.filter((x) => x !== m.id) : [...selected, m.id])}
            className={cn('rounded-full border px-2 py-0.5', on ? 'border-[#111111] dark:border-white bg-[#111111] dark:bg-white text-white dark:text-[#111111]' : 'border-[#E5E5E5] dark:border-[#2A2A2A] text-[#525252] dark:text-[#A3A3A3]')}>
            @{m.name}
          </button>
        );
      })}
    </div>
  );
};

export const CommentEditor: React.FC<{ onSubmit: (body: string, mentions: number[]) => Promise<void> | void; members?: MentionableUser[]; currentUserId?: number; placeholder?: string; initialValue?: string; submitLabel?: string; onCancel?: () => void; maxLength?: number; compact?: boolean }> = ({ onSubmit, members = [], currentUserId, placeholder = 'Start a discussion about this academic resource…', initialValue = '', submitLabel = 'Comment', onCancel, maxLength = 5000, compact }) => {
  const [body, setBody] = useState(initialValue);
  const [mentions, setMentions] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const canSend = body.trim().length > 0 && body.length <= maxLength && !busy;
  return (
    <form data-testid="comment-editor" className="space-y-2" onSubmit={async (e) => { e.preventDefault(); if (!canSend) return; setBusy(true); try { await onSubmit(body.trim(), mentions); setBody(''); setMentions([]); } finally { setBusy(false); } }}>
      <textarea aria-label="Comment" value={body} onChange={(e) => setBody(e.target.value)} rows={compact ? 2 : 3} maxLength={maxLength} placeholder={placeholder}
        className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white" />
      <MentionSelector members={members} selected={mentions} onChange={setMentions} currentUserId={currentUserId} />
      <div className="flex items-center justify-between">
        <span className="text-[11px] text-[#A3A3A3]">{body.length}/{maxLength}</span>
        <div className="flex gap-2">{onCancel && <Button type="button" size="sm" variant="ghost" onClick={onCancel}>Cancel</Button>}<Button type="submit" size="sm" disabled={!canSend} isLoading={busy} data-testid="comment-submit">{submitLabel}</Button></div>
      </div>
    </form>
  );
};

const CommentItem: React.FC<{ comment: CommentType; currentUserId?: number; canResolve: boolean; canComment: boolean; members: MentionableUser[]; onReply: (parentId: number, body: string, mentions: number[]) => Promise<void>; onEdit: (id: number, body: string) => Promise<void>; onDelete: (id: number) => Promise<void>; onResolve: (id: number) => Promise<void>; onReopen: (id: number) => Promise<void>; isReply?: boolean }> = ({ comment: c, currentUserId, canResolve, canComment, members, onReply, onEdit, onDelete, onResolve, onReopen, isReply }) => {
  const [replying, setReplying] = useState(false);
  const [editing, setEditing] = useState(false);
  const mine = c.author?.id === currentUserId;
  const resolved = c.status === 'RESOLVED';
  return (
    <div data-testid={`comment-${c.id}`} className={cn('rounded-lg border bg-white dark:bg-[#161616] p-3 space-y-2', resolved ? 'border-emerald-200 dark:border-emerald-900/50 opacity-90' : 'border-[#E5E5E5] dark:border-[#2A2A2A]', isReply && 'ml-6')}>
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-[#737373]">
        <span><strong className="text-[#111111] dark:text-white">{c.author?.name ?? 'Unknown'}</strong>{c.created_at ? ` · ${new Date(c.created_at).toLocaleString()}` : ''}{c.edited_at ? ' · edited' : ''}</span>
        <div className="flex items-center gap-1.5">
          {resolved && <Badge variant="Good" data-testid="resolved-badge">Resolved{c.resolved_by ? ` by ${c.resolved_by.name}` : ''}</Badge>}
          {mine && c.status !== 'DELETED' && <button type="button" aria-label="Edit comment" onClick={() => setEditing(true)} className="hover:text-[#111111]"><Pencil className="w-3.5 h-3.5" /></button>}
          {(mine || canResolve) && c.status !== 'DELETED' && <button type="button" aria-label="Delete comment" onClick={() => void onDelete(c.id)} className="hover:text-red-600"><Trash2 className="w-3.5 h-3.5" /></button>}
        </div>
      </div>
      {editing ? (
        <CommentEditor initialValue={c.body} submitLabel="Save" compact onCancel={() => setEditing(false)} onSubmit={async (b) => { await onEdit(c.id, b); setEditing(false); }} />
      ) : (
        <p className="text-sm text-[#111111] dark:text-white whitespace-pre-wrap break-words" data-testid="comment-body">{c.body}</p>
      )}
      {!isReply && (
        <div className="flex flex-wrap gap-2">
          {canComment && !resolved && <Button size="sm" variant="ghost" leftIcon={<CornerDownRight className="w-3.5 h-3.5" />} onClick={() => setReplying((r) => !r)} data-testid="reply-button">Reply</Button>}
          {(canResolve || mine) && !resolved && <Button size="sm" variant="ghost" leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />} onClick={() => void onResolve(c.id)} data-testid="resolve-button">Resolve</Button>}
          {(canResolve || mine) && resolved && <Button size="sm" variant="ghost" leftIcon={<RotateCcw className="w-3.5 h-3.5" />} onClick={() => void onReopen(c.id)} data-testid="reopen-button">Reopen</Button>}
        </div>
      )}
      {c.replies?.length > 0 && <div className="space-y-2">{c.replies.map((r) => <CommentItem key={r.id} comment={r} currentUserId={currentUserId} canResolve={canResolve} canComment={canComment} members={members} onReply={onReply} onEdit={onEdit} onDelete={onDelete} onResolve={onResolve} onReopen={onReopen} isReply />)}</div>}
      {replying && <div className="ml-6"><CommentEditor compact members={members} currentUserId={currentUserId} placeholder="Write a reply…" submitLabel="Reply" onCancel={() => setReplying(false)} onSubmit={async (b, m) => { await onReply(c.id, b, m); setReplying(false); }} /></div>}
    </div>
  );
};

export interface CollaborationCommentsProps {
  courseId: number | string;
  commentableType: CommentableType;
  commentableId: number | string;
  currentUserId?: number;
  canComment?: boolean;
  canResolve?: boolean;
  title?: string;
  compact?: boolean;
  /** when true, hides the resolved threads by default */
  showResolvedToggle?: boolean;
}

export const CollaborationComments: React.FC<CollaborationCommentsProps> = ({ courseId, commentableType, commentableId, currentUserId, canComment: canCommentProp, canResolve = false, title = 'Discussion', compact, showResolvedToggle = true }) => {
  const [comments, setComments] = useState<CommentType[]>([]);
  const [members, setMembers] = useState<MentionableUser[]>([]);
  const [canComment, setCanComment] = useState(!!canCommentProp);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showResolved, setShowResolved] = useState(false);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const res = await collaborationService.getComments(courseId, { commentable_type: commentableType, commentable_id: commentableId, per_page: 50 });
      setComments(res.data);
      if (canCommentProp === undefined && res.meta?.can_comment !== undefined) setCanComment(!!res.meta.can_comment);
    } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setLoading(false); }
  }, [courseId, commentableType, commentableId, canCommentProp]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { if (canCommentProp !== undefined) setCanComment(canCommentProp); }, [canCommentProp]);
  useEffect(() => { collaborationService.getMembers(courseId).then((r) => setMembers(r.data)).catch(() => setMembers([])); }, [courseId]);

  const act = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); await load(); } catch (e) { setError(getCollaborationErrorMessage(e)); } };
  const visible = useMemo(() => comments.filter((c) => showResolved || c.status !== 'RESOLVED'), [comments, showResolved]);
  const resolvedCount = comments.length - comments.filter((c) => c.status !== 'RESOLVED').length;

  return (
    <section data-testid="collaboration-comments" className={cn('space-y-3', !compact && 'rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4')}>
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-[#111111] dark:text-white flex items-center gap-2"><MessageSquare className="w-4 h-4" /> {title} <span className="text-[#737373] font-normal">({comments.length})</span></h3>
        {showResolvedToggle && resolvedCount > 0 && <button type="button" className="text-xs underline text-[#737373]" onClick={() => setShowResolved((s) => !s)}>{showResolved ? 'Hide' : 'Show'} {resolvedCount} resolved</button>}
      </div>
      <p className="text-[11px] text-[#A3A3A3]">Discussion never changes the AI result or the official content; decisions are made through the normal approval actions.</p>
      {error && <CollaborationError message={error} onRetry={load} />}
      {loading ? <CollaborationLoading label="Loading discussion…" /> : visible.length === 0 ? (
        <CollaborationEmptyState title="No discussion yet" description={canComment ? 'Start a discussion about this academic resource.' : 'There is no discussion on this resource yet.'} icon={<MessageSquare className="w-6 h-6" />} className="py-6" />
      ) : (
        <div className="space-y-2">
          {visible.map((c) => (
            <CommentItem key={c.id} comment={c} currentUserId={currentUserId} canResolve={canResolve} canComment={canComment} members={members}
              onReply={(parentId, body, mentions) => act(() => collaborationService.createComment(courseId, { commentable_type: commentableType, commentable_id: commentableId, body, parent_id: parentId, mentions }))}
              onEdit={(id, body) => act(() => collaborationService.updateComment(id, body))}
              onDelete={(id) => act(() => collaborationService.deleteComment(id))}
              onResolve={(id) => act(() => collaborationService.resolveComment(id))}
              onReopen={(id) => act(() => collaborationService.reopenComment(id))} />
          ))}
        </div>
      )}
      {canComment && <CommentEditor members={members} currentUserId={currentUserId} compact={compact} onSubmit={(body, mentions) => act(() => collaborationService.createComment(courseId, { commentable_type: commentableType, commentable_id: commentableId, body, mentions }))} />}
    </section>
  );
};
