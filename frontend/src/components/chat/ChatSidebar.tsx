import React from 'react';
import { BookOpen, FileText, MessageSquareText, Plus, Trash2 } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { ChatIndexSummary, ChatScopeType, ChatSession } from '@/types/chat';
import { cn } from '@/utils/cn';

/**
 * STEP 32: Session list, header, and scope selector.
 */

export function describeScope(session: Pick<ChatSession, 'scope_type' | 'course' | 'document' | 'assessment'>): string {
  if (session.scope_type === 'DOCUMENT' && session.document) return `Document · ${session.document.name}`;
  if (session.scope_type === 'ASSESSMENT' && session.assessment) return `Assessment · ${session.assessment.title}`;
  if (session.course) return `Course · ${session.course.course_code || ''} ${session.course.course_name || ''}`.trim();
  return session.scope_type;
}

export const ChatSessionList: React.FC<{
  sessions: ChatSession[];
  activeId: number | null;
  onSelect: (session: ChatSession) => void;
  onDelete: (session: ChatSession) => void;
  onNew: () => void;
}> = ({ sessions, activeId, onSelect, onDelete, onNew }) => (
  <aside data-testid="chat-session-list" className="flex flex-col h-full border-r border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#111111]">
    <div className="flex items-center justify-between px-3 py-3 border-b border-[#E5E5E5] dark:border-[#2A2A2A]">
      <span className="text-sm font-semibold text-[#111111] dark:text-white">Chats</span>
      <Button size="sm" variant="outline" onClick={onNew} leftIcon={<Plus className="w-3.5 h-3.5" />} data-testid="new-chat-button">
        New
      </Button>
    </div>
    <div className="flex-1 overflow-y-auto">
      {sessions.length === 0 ? (
        <p className="px-3 py-6 text-xs text-[#737373] text-center">No chats yet. Start one to ask questions about your documents.</p>
      ) : (
        <ul>
          {sessions.map((s) => (
            <li key={s.id} className={cn('group flex items-start gap-2 px-3 py-2.5 border-b border-[#F0F0F0] dark:border-[#1F1F1F] cursor-pointer', activeId === s.id ? 'bg-[#F7F7F5] dark:bg-[#1F1F1F]' : 'hover:bg-[#FAFAFA] dark:hover:bg-[#161616]')}>
              <button type="button" className="flex-1 min-w-0 text-left" onClick={() => onSelect(s)} data-testid={`chat-session-${s.id}`}>
                <span className="block text-sm font-medium text-[#111111] dark:text-white truncate">{s.title}</span>
                <span className="block text-[11px] text-[#737373] truncate">{describeScope(s)}</span>
                <span className="block text-[11px] text-[#A3A3A3]">{s.message_count} message{s.message_count === 1 ? '' : 's'}</span>
              </button>
              <button
                type="button"
                aria-label={`Delete chat ${s.title}`}
                onClick={() => onDelete(s)}
                className="opacity-0 group-hover:opacity-100 focus:opacity-100 text-[#A3A3A3] hover:text-red-600 p-1"
              >
                <Trash2 className="w-3.5 h-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  </aside>
);

export const IndexStatusBadge: React.FC<{ index?: ChatIndexSummary }> = ({ index }) => {
  if (!index) return null;
  if (index.total === 0) return <Badge variant="neutral" data-testid="index-status">No documents</Badge>;
  if (index.indexed === 0 && index.indexing > 0) return <Badge variant="Pending" data-testid="index-status">Indexing {index.indexing} document{index.indexing === 1 ? '' : 's'}…</Badge>;
  if (index.indexed === 0) return <Badge variant="Critical" data-testid="index-status">No indexed documents</Badge>;
  return (
    <Badge variant="Good" data-testid="index-status">
      {index.indexed}/{index.total} indexed{index.indexing > 0 ? ` · ${index.indexing} pending` : ''}{index.failed > 0 ? ` · ${index.failed} failed` : ''}
    </Badge>
  );
};

export const ChatHeader: React.FC<{ session: ChatSession | null; onDelete?: () => void }> = ({ session, onDelete }) => (
  <header data-testid="chat-header" className="flex items-center justify-between gap-3 px-4 py-3 border-b border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#111111]">
    <div className="flex items-center gap-3 min-w-0">
      <div className="w-9 h-9 rounded-lg bg-[#111111] dark:bg-white text-white dark:text-[#111111] flex items-center justify-center flex-shrink-0">
        <MessageSquareText className="w-4 h-4" />
      </div>
      <div className="min-w-0">
        <h2 className="text-sm font-semibold text-[#111111] dark:text-white truncate">{session ? session.title : 'Academic Document Chat'}</h2>
        <p className="text-xs text-[#737373] truncate">{session ? describeScope(session) : 'Grounded answers from your uploaded course documents'}</p>
      </div>
    </div>
    <div className="flex items-center gap-2">
      {session && <IndexStatusBadge index={session.index} />}
      {session && onDelete && (
        <Button size="sm" variant="ghost" onClick={onDelete} leftIcon={<Trash2 className="w-3.5 h-3.5" />} aria-label="Delete this chat">
          Delete
        </Button>
      )}
    </div>
  </header>
);

export interface ScopeCourseOption { id: number | string; course_code?: string; course_name?: string }
export interface ScopeDocumentOption { id: number | string; original_file_name: string; indexing_status?: string; course_id?: number | string }

export const DocumentScopeSelector: React.FC<{
  courses: ScopeCourseOption[];
  documents: ScopeDocumentOption[];
  scopeType: ChatScopeType;
  courseId: string;
  documentId: string;
  onScopeTypeChange: (t: ChatScopeType) => void;
  onCourseChange: (id: string) => void;
  onDocumentChange: (id: string) => void;
  onStart: () => void;
  starting?: boolean;
}> = ({ courses, documents, scopeType, courseId, documentId, onScopeTypeChange, onCourseChange, onDocumentChange, onStart, starting }) => {
  const canStart = scopeType === 'COURSE' ? !!courseId : !!documentId;
  const selectClass = 'w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white';

  return (
    <div data-testid="scope-selector" className="max-w-lg mx-auto w-full rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-5 space-y-4">
      <div>
        <h3 className="text-base font-semibold text-[#111111] dark:text-white">Start a new chat</h3>
        <p className="text-xs text-[#737373]">Choose what the assistant may read. Answers are grounded only in documents inside this scope.</p>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <button type="button" onClick={() => onScopeTypeChange('COURSE')} className={cn('flex items-center gap-2 rounded-lg border px-3 py-2 text-sm', scopeType === 'COURSE' ? 'border-[#111111] dark:border-white bg-[#F7F7F5] dark:bg-[#1F1F1F]' : 'border-[#E5E5E5] dark:border-[#2A2A2A]')} data-testid="scope-course">
          <BookOpen className="w-4 h-4" /> Whole course
        </button>
        <button type="button" onClick={() => onScopeTypeChange('DOCUMENT')} className={cn('flex items-center gap-2 rounded-lg border px-3 py-2 text-sm', scopeType === 'DOCUMENT' ? 'border-[#111111] dark:border-white bg-[#F7F7F5] dark:bg-[#1F1F1F]' : 'border-[#E5E5E5] dark:border-[#2A2A2A]')} data-testid="scope-document">
          <FileText className="w-4 h-4" /> Single document
        </button>
      </div>
      <label className="block text-xs font-medium text-[#525252] dark:text-[#A3A3A3]">
        Course
        <select aria-label="Course" className={cn(selectClass, 'mt-1')} value={courseId} onChange={(e) => onCourseChange(e.target.value)}>
          <option value="">Select a course…</option>
          {courses.map((c) => (
            <option key={c.id} value={String(c.id)}>{[c.course_code, c.course_name].filter(Boolean).join(' — ')}</option>
          ))}
        </select>
      </label>
      {scopeType === 'DOCUMENT' && (
        <label className="block text-xs font-medium text-[#525252] dark:text-[#A3A3A3]">
          Document
          <select aria-label="Document" className={cn(selectClass, 'mt-1')} value={documentId} onChange={(e) => onDocumentChange(e.target.value)} disabled={!courseId}>
            <option value="">{courseId ? 'Select a document…' : 'Select a course first'}</option>
            {documents.map((d) => (
              <option key={d.id} value={String(d.id)}>
                {d.original_file_name}{d.indexing_status && d.indexing_status !== 'INDEXED' ? ` (${d.indexing_status.toLowerCase()})` : ''}
              </option>
            ))}
          </select>
        </label>
      )}
      <Button onClick={onStart} disabled={!canStart || starting} isLoading={starting} className="w-full" data-testid="start-chat">
        Start chat
      </Button>
    </div>
  );
};
