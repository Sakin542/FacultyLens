import { API_BASE_URL, ApiError, apiClient } from './api';
import { ChatSession, ChatSessionDetail, CreateChatSessionInput, SendMessageResult } from '@/types/chat';

interface Envelope<T> { status: string; message?: string; data: T; }

/**
 * STEP 32: Academic document chat API. Retrieval + authorization run in Laravel; the AI service only
 * answers over the chunks it is handed.
 */
export const academicChatService = {
  listSessions: (params?: { course_id?: number | string; document_id?: number | string }): Promise<Envelope<ChatSession[]>> => {
    const query = new URLSearchParams();
    if (params?.course_id) query.append('course_id', String(params.course_id));
    if (params?.document_id) query.append('document_id', String(params.document_id));
    const qs = query.toString();
    return apiClient(`/academic-chat/sessions${qs ? `?${qs}` : ''}`, { method: 'GET' });
  },

  createSession: (input: CreateChatSessionInput): Promise<Envelope<ChatSession>> =>
    apiClient('/academic-chat/sessions', { method: 'POST', body: JSON.stringify(input) }),

  getSession: (sessionId: number | string): Promise<Envelope<ChatSessionDetail>> =>
    apiClient(`/academic-chat/sessions/${sessionId}`, { method: 'GET' }),

  deleteSession: (sessionId: number | string): Promise<Envelope<null>> =>
    apiClient(`/academic-chat/sessions/${sessionId}`, { method: 'DELETE' }),

  updateSession: (sessionId: number | string, input: { title?: string; status?: 'ACTIVE' | 'ARCHIVED' }): Promise<Envelope<ChatSession>> =>
    apiClient(`/academic-chat/sessions/${sessionId}`, { method: 'PATCH', body: JSON.stringify(input) }),

  /** Downloads the transcript (Markdown or JSON) through the cookie-authenticated API. */
  exportSession: async (sessionId: number | string, format: 'markdown' | 'json' = 'markdown', title?: string | null): Promise<void> => {
    const res = await fetch(`${API_BASE_URL}/academic-chat/sessions/${sessionId}/export?format=${format}`, {
      credentials: 'include',
      headers: { Accept: format === 'json' ? 'application/json' : 'text/markdown' },
    });
    if (!res.ok) throw new ApiError(res.status, 'Could not export this chat. Please try again.');
    // Content-Disposition is not readable cross-origin (not CORS-exposed), so derive the name client-side
    const slug = (title ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
    const name = `chat-${sessionId}${slug ? `-${slug}` : ''}.${format === 'json' ? 'json' : 'md'}`;
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

  sendMessage: (sessionId: number | string, message: string): Promise<Envelope<SendMessageResult>> =>
    apiClient(`/academic-chat/sessions/${sessionId}/messages`, { method: 'POST', body: JSON.stringify({ message }) }),
};
