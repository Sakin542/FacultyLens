import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { academicChatService } from '@/services/academicChatService';
import { courseService } from '@/services/courseService';
import { documentService } from '@/services/documentService';
import { ChatMessage, ChatScopeType, ChatSession, ChatSessionDetail } from '@/types/chat';
import { Course, DocumentProcessing } from '@/types';
import { ChatHeader, ChatSessionList, DocumentScopeSelector } from '@/components/chat/ChatSidebar';
import { ChatInput, ChatWindow } from '@/components/chat/ChatWindow';
import { ChatError, getChatErrorMessage } from '@/components/chat/ChatStates';

/**
 * STEP 32: Document-grounded academic chat. Routes: /academic-chat and /courses/:courseId/chat.
 * The page never renders document text as HTML and never shows file paths — only names/pages/sections.
 */
export const AcademicChat: React.FC = () => {
  const { courseId: routeCourseId } = useParams<{ courseId: string }>();
  const [searchParams, setSearchParams] = useSearchParams();

  const [sessions, setSessions] = useState<ChatSession[]>([]);
  const [active, setActive] = useState<ChatSessionDetail | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [documents, setDocuments] = useState<DocumentProcessing[]>([]);

  const [scopeType, setScopeType] = useState<ChatScopeType>('COURSE');
  const [courseId, setCourseId] = useState(routeCourseId ?? '');
  const [documentId, setDocumentId] = useState('');
  const [creating, setCreating] = useState(false);
  const [composing, setComposing] = useState(!searchParams.get('session'));

  const [loadingSessions, setLoadingSessions] = useState(true);
  const [loadingSession, setLoadingSession] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastQuestion, setLastQuestion] = useState<string | null>(null);

  const disclaimer = useMemo(() => messages.find((m) => m.role === 'ASSISTANT' && m.disclaimer)?.disclaimer ?? null, [messages]);

  const loadSessions = useCallback(async () => {
    setLoadingSessions(true);
    try {
      const res = await academicChatService.listSessions(routeCourseId ? { course_id: routeCourseId } : undefined);
      setSessions(res.data);
    } catch (e) {
      setError(getChatErrorMessage(e));
    } finally {
      setLoadingSessions(false);
    }
  }, [routeCourseId]);

  useEffect(() => {
    void loadSessions();
    courseService.getAll().then((r) => setCourses(r.data)).catch(() => undefined);
  }, [loadSessions]);

  useEffect(() => {
    if (!courseId) {
      setDocuments([]);
      return;
    }
    documentService.getAll({ course_id: courseId }).then((r) => setDocuments(r.data)).catch(() => setDocuments([]));
  }, [courseId]);

  const openSession = useCallback(async (id: number | string) => {
    setLoadingSession(true);
    setError(null);
    setComposing(false);
    try {
      const res = await academicChatService.getSession(id);
      setActive(res.data);
      setMessages(res.data.messages);
      setSearchParams({ session: String(id) }, { replace: true });
    } catch (e) {
      setError(getChatErrorMessage(e));
      setActive(null);
      setMessages([]);
    } finally {
      setLoadingSession(false);
    }
  }, [setSearchParams]);

  useEffect(() => {
    const id = searchParams.get('session');
    if (id && (!active || String(active.id) !== id)) void openSession(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams.get('session')]);

  const startSession = async () => {
    setCreating(true);
    setError(null);
    try {
      const res = await academicChatService.createSession(
        scopeType === 'DOCUMENT' ? { scope_type: 'DOCUMENT', document_id: documentId } : { scope_type: 'COURSE', course_id: courseId }
      );
      setSessions((prev) => [res.data, ...prev]);
      await openSession(res.data.id);
    } catch (e) {
      setError(getChatErrorMessage(e));
    } finally {
      setCreating(false);
    }
  };

  const send = async (text: string) => {
    if (!active) return;
    setSending(true);
    setError(null);
    setLastQuestion(text);
    try {
      const res = await academicChatService.sendMessage(active.id, text);
      const additions = [res.data.user_message, res.data.assistant_message].filter(Boolean) as ChatMessage[];
      setMessages((prev) => [...prev, ...additions]);
      setActive((prev) => (prev ? { ...prev, ...res.data.session, index: prev.index, messages: [] } : prev));
      setSessions((prev) => prev.map((s) => (s.id === res.data.session.id ? { ...s, ...res.data.session } : s)));
      setLastQuestion(null);
    } catch (e) {
      setError(getChatErrorMessage(e));
    } finally {
      setSending(false);
    }
  };

  const remove = async (session: ChatSession) => {
    if (!window.confirm(`Delete "${session.title}"? This cannot be undone.`)) return;
    try {
      await academicChatService.deleteSession(session.id);
      setSessions((prev) => prev.filter((s) => s.id !== session.id));
      if (active?.id === session.id) {
        setActive(null);
        setMessages([]);
        setComposing(true);
        setSearchParams({}, { replace: true });
      }
    } catch (e) {
      setError(getChatErrorMessage(e));
    }
  };

  const noIndexed = active?.index ? active.index.indexed === 0 : false;
  const backLink = routeCourseId ? `/courses/${routeCourseId}` : '/dashboard';

  return (
    <div className="flex flex-col h-[calc(100vh-7rem)] min-h-[520px]" data-testid="academic-chat-page">
      <div className="flex items-center gap-2 text-xs text-sage-500 mb-3">
        <Link to={backLink} className="inline-flex items-center gap-1 hover:text-sage-800 dark:hover:text-white">
          <ArrowLeft className="w-3.5 h-3.5" /> {routeCourseId ? 'Back to course' : 'Dashboard'}
        </Link>
      </div>
      <div className="flex-1 grid grid-cols-1 md:grid-cols-[260px_1fr] rounded-xl border border-sage-200 dark:border-[#2A2A2A] overflow-hidden bg-white dark:bg-sage-700">
        <ChatSessionList
          sessions={sessions}
          activeId={active?.id ?? null}
          onSelect={(s) => void openSession(s.id)}
          onDelete={(s) => void remove(s)}
          onNew={() => {
            setComposing(true);
            setActive(null);
            setMessages([]);
            setError(null);
            setSearchParams({}, { replace: true });
          }}
        />
        <section className="flex flex-col min-h-0">
          <ChatHeader session={active} onDelete={active ? () => void remove(active) : undefined} />
          {error && <ChatError message={error} className="m-3" onRetry={lastQuestion && active ? () => void send(lastQuestion) : undefined} />}
          {composing || !active ? (
            <div className="flex-1 flex items-center justify-center p-6 overflow-y-auto">
              {loadingSession || loadingSessions ? null : (
                <DocumentScopeSelector
                  courses={courses}
                  documents={documents}
                  scopeType={scopeType}
                  courseId={courseId}
                  documentId={documentId}
                  onScopeTypeChange={setScopeType}
                  onCourseChange={(id) => { setCourseId(id); setDocumentId(''); }}
                  onDocumentChange={setDocumentId}
                  onStart={() => void startSession()}
                  starting={creating}
                />
              )}
            </div>
          ) : (
            <>
              <ChatWindow
                messages={messages}
                loading={sending || loadingSession}
                disclaimer={disclaimer}
                emptyDescription={noIndexed ? 'No indexed documents are available in this scope yet. Upload documents to the course and wait for indexing to finish.' : undefined}
              />
              {noIndexed && active.index && active.index.total > 0 && active.index.indexing > 0 && (
                <p className="px-4 pb-2 text-xs text-sage-500" data-testid="indexing-hint">
                  Documents are still being indexed. Refresh in a moment.{' '}
                  <button type="button" className="underline" onClick={() => void openSession(active.id)}>Refresh</button>
                </p>
              )}
              <ChatInput onSend={send} disabled={sending || noIndexed} />
            </>
          )}
        </section>
      </div>
    </div>
  );
};
