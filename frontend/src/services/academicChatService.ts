import { apiClient } from './api';
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

  sendMessage: (sessionId: number | string, message: string): Promise<Envelope<SendMessageResult>> =>
    apiClient(`/academic-chat/sessions/${sessionId}/messages`, { method: 'POST', body: JSON.stringify({ message }) }),
};
