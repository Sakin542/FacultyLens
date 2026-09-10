import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ChatInput, ChatMessage, ChatWindow } from '@/components/chat/ChatWindow';
import { ChatSourceCard, ChatSourceList, formatSourceLocation } from '@/components/chat/ChatSources';
import { ChatEmptyState, ChatError, ChatLoading, GroundingDisclaimer, getChatErrorMessage } from '@/components/chat/ChatStates';
import { ChatHeader, ChatSessionList, DocumentScopeSelector, IndexStatusBadge, describeScope } from '@/components/chat/ChatSidebar';
import { AcademicChat } from '@/pages/AcademicChat';
import { ApiError } from '@/services/api';
import { ChatMessage as ChatMessageType, ChatScopeType, ChatSession, ChatSessionDetail, ChatSource } from '@/types/chat';

vi.mock('@/services/academicChatService', () => ({
  academicChatService: { listSessions: vi.fn(), createSession: vi.fn(), getSession: vi.fn(), deleteSession: vi.fn(), sendMessage: vi.fn() },
}));
vi.mock('@/services/courseService', () => ({ courseService: { getAll: vi.fn() } }));
vi.mock('@/services/documentService', () => ({ documentService: { getAll: vi.fn() } }));

import { academicChatService } from '@/services/academicChatService';
import { courseService } from '@/services/courseService';
import { documentService } from '@/services/documentService';

const chat = academicChatService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const courses = courseService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const docs = documentService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const source: ChatSource = {
  id: 1, document_id: 10, chunk_id: 100, document_name: 'syllabus.pdf', document_type: 'syllabus',
  similarity_score: 0.82, page_number: 3, section_title: 'Unit 2 Normalization', excerpt: 'Normalization reduces redundancy.', source_order: 1,
};

const assistant: ChatMessageType = {
  id: 2, role: 'ASSISTANT', content: 'Normalization reduces redundancy. [S1]', grounded: true, generation_method: 'extractive',
  generation_model: 'engine', embedding_model: 'MiniLM', prompt_version: '1.0.0', retrieved_count: 2, used_count: 1,
  disclaimer: 'Verify against the original documents.', sources: [source], created_at: null,
};
const userMsg: ChatMessageType = { ...assistant, id: 1, role: 'USER', content: 'What does normalization do?', grounded: false, sources: [], disclaimer: null };
const ungrounded: ChatMessageType = { ...assistant, id: 3, grounded: false, generation_method: 'insufficient_evidence', sources: [], content: "I couldn't find enough information about that in the documents available to this chat." };

const session: ChatSession = {
  id: 7, title: 'Chat: Database Systems', scope_type: 'COURSE', course_id: 1, document_id: null, assessment_id: null,
  course: { id: 1, course_name: 'Database Systems', course_code: 'CSE101' }, document: null, assessment: null,
  status: 'ACTIVE', message_count: 0, last_message_at: null, created_at: null, updated_at: null,
  index: { total: 1, indexed: 1, indexing: 0, failed: 0, documents: [] },
};

describe('STEP 32 chat components', () => {
  it('renders user and assistant messages with grounded badge and sources', () => {
    render(<><ChatMessage message={userMsg} /><ChatMessage message={assistant} /></>);
    expect(screen.getByTestId('chat-message-user')).toHaveTextContent('What does normalization do?');
    expect(screen.getByTestId('grounded-badge')).toBeInTheDocument();
    expect(screen.getByTestId('chat-source-list')).toHaveTextContent('syllabus.pdf');
    expect(screen.getByText('Page 3 · Unit 2 Normalization')).toBeInTheDocument();
  });

  it('marks ungrounded answers and shows no sources', () => {
    render(<ChatMessage message={ungrounded} />);
    expect(screen.getByTestId('ungrounded-badge')).toBeInTheDocument();
    expect(screen.queryByTestId('chat-source-list')).toBeNull();
    expect(screen.getByText(/couldn't find enough information/)).toBeInTheDocument();
  });

  it('renders document content as text, never HTML', () => {
    render(<ChatMessage message={{ ...assistant, content: '<img src=x onerror=alert(1)>', sources: [] }} />);
    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('source card toggles excerpt and formats location without inventing pages', () => {
    render(<ChatSourceCard source={source} index={1} />);
    expect(screen.queryByText('Normalization reduces redundancy.')).toBeNull();
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByText('Normalization reduces redundancy.')).toBeInTheDocument();
    expect(formatSourceLocation({ ...source, page_number: null, section_title: null })).toBe('');
    render(<ChatSourceList sources={[{ ...source, id: 9, page_number: null, section_title: null }]} />);
    expect(screen.getByText('Location not available')).toBeInTheDocument();
  });

  it('chat input trims, enforces max length and sends on Enter', async () => {
    const onSend = vi.fn();
    render(<ChatInput onSend={onSend} maxLength={20} />);
    const box = screen.getByLabelText('Chat message');
    expect(screen.getByTestId('chat-send')).toBeDisabled();
    fireEvent.change(box, { target: { value: '  hello  ' } });
    expect(screen.getByTestId('chat-send')).not.toBeDisabled();
    fireEvent.keyDown(box, { key: 'Enter' });
    await waitFor(() => expect(onSend).toHaveBeenCalledWith('hello'));
    expect((box as HTMLTextAreaElement).value).toBe('');
  });

  it('chat window shows empty state, disclaimer and loading', () => {
    const { rerender } = render(<ChatWindow messages={[]} />);
    expect(screen.getByTestId('chat-empty-state')).toBeInTheDocument();
    rerender(<ChatWindow messages={[userMsg, assistant]} loading disclaimer="Custom disclaimer" />);
    expect(screen.getByTestId('grounding-disclaimer')).toHaveTextContent('Custom disclaimer');
    expect(screen.getByTestId('chat-loading')).toBeInTheDocument();
  });

  it('state components and error mapping', () => {
    render(<><ChatEmptyState /><ChatLoading /><ChatError message="Boom" onRetry={() => undefined} /><GroundingDisclaimer /></>);
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    expect(screen.getByText('Try again')).toBeInTheDocument();
    expect(getChatErrorMessage(new ApiError(429, 'x'))).toMatch(/too quickly/);
    expect(getChatErrorMessage(new ApiError(403, 'x'))).toMatch(/do not have access/);
    expect(getChatErrorMessage(new ApiError(409, 'Still indexing'))).toBe('Still indexing');
    expect(getChatErrorMessage(new ApiError(503, ''))).toMatch(/not saved/);
    expect(getChatErrorMessage(new Error('plain'))).toBe('plain');
  });

  it('session list, header, index badge and scope description', () => {
    const onSelect = vi.fn();
    const onDelete = vi.fn();
    render(<><ChatSessionList sessions={[session]} activeId={7} onSelect={onSelect} onDelete={onDelete} onNew={() => undefined} /><ChatHeader session={session} /></>);
    fireEvent.click(screen.getByTestId('chat-session-7'));
    expect(onSelect).toHaveBeenCalledWith(session);
    fireEvent.click(screen.getByLabelText('Delete chat Chat: Database Systems'));
    expect(onDelete).toHaveBeenCalled();
    expect(screen.getByTestId('index-status')).toHaveTextContent('1/1 indexed');
    expect(describeScope(session)).toBe('Course · CSE101 Database Systems');
    expect(describeScope({ scope_type: 'DOCUMENT', course: null, assessment: null, document: { id: 1, name: 'a.pdf', document_type: null } })).toBe('Document · a.pdf');
  });

  it('index badge covers indexing / empty / failed states', () => {
    const { rerender } = render(<IndexStatusBadge index={{ total: 2, indexed: 0, indexing: 2, failed: 0, documents: [] }} />);
    expect(screen.getByTestId('index-status')).toHaveTextContent('Indexing 2 documents');
    rerender(<IndexStatusBadge index={{ total: 0, indexed: 0, indexing: 0, failed: 0, documents: [] }} />);
    expect(screen.getByTestId('index-status')).toHaveTextContent('No documents');
    rerender(<IndexStatusBadge index={{ total: 1, indexed: 0, indexing: 0, failed: 1, documents: [] }} />);
    expect(screen.getByTestId('index-status')).toHaveTextContent('No indexed documents');
  });

  it('scope selector requires a document for document scope', () => {
    const onStart = vi.fn();
    const Wrapper = () => {
      const [scope, setScope] = React.useState<ChatScopeType>('COURSE');
      const [docId, setDocId] = React.useState('');
      return (
        <DocumentScopeSelector courses={[{ id: 1, course_code: 'CSE101', course_name: 'DB' }]} documents={[{ id: 5, original_file_name: 'notes.pdf', indexing_status: 'INDEXING' }]}
          scopeType={scope} courseId="1" documentId={docId} onScopeTypeChange={setScope} onCourseChange={() => undefined} onDocumentChange={setDocId} onStart={onStart} />
      );
    };
    render(<Wrapper />);
    expect(screen.getByTestId('start-chat')).not.toBeDisabled();
    fireEvent.click(screen.getByTestId('scope-document'));
    expect(screen.getByTestId('start-chat')).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Document'), { target: { value: '5' } });
    expect(screen.getByText('notes.pdf (indexing)')).toBeInTheDocument();
    expect(screen.getByTestId('start-chat')).not.toBeDisabled();
    fireEvent.click(screen.getByTestId('start-chat'));
    expect(onStart).toHaveBeenCalled();
  });
});

const renderPage = (path = '/academic-chat') =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/academic-chat" element={<AcademicChat />} />
        <Route path="/courses/:courseId/chat" element={<AcademicChat />} />
      </Routes>
    </MemoryRouter>
  );

describe('STEP 32 AcademicChat page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    courses.getAll.mockResolvedValue({ data: [{ id: 1, course_code: 'CSE101', course_name: 'Database Systems' }] });
    docs.getAll.mockResolvedValue({ data: [] });
    chat.listSessions.mockResolvedValue({ status: 'success', data: [] });
    Element.prototype.scrollIntoView = vi.fn();
  });

  it('creates a session from the scope selector and sends a grounded question', async () => {
    const detail: ChatSessionDetail = { ...session, messages: [] };
    chat.createSession.mockResolvedValue({ status: 'success', data: session });
    chat.getSession.mockResolvedValue({ status: 'success', data: detail });
    chat.sendMessage.mockResolvedValue({ status: 'success', data: { user_message: userMsg, assistant_message: assistant, session: { ...session, message_count: 2 } } });

    renderPage('/courses/1/chat');
    await waitFor(() => expect(screen.getByTestId('scope-selector')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('start-chat'));
    await waitFor(() => expect(chat.createSession).toHaveBeenCalledWith({ scope_type: 'COURSE', course_id: '1' }));
    await waitFor(() => expect(screen.getByTestId('chat-window')).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText('Chat message'), { target: { value: 'What does normalization do?' } });
    fireEvent.click(screen.getByTestId('chat-send'));
    await waitFor(() => expect(chat.sendMessage).toHaveBeenCalledWith(7, 'What does normalization do?'));
    await waitFor(() => expect(screen.getByTestId('chat-message-assistant')).toBeInTheDocument());
    expect(screen.getByTestId('grounded-badge')).toBeInTheDocument();
    expect(screen.getByTestId('chat-source-list')).toHaveTextContent('syllabus.pdf');
    expect(screen.getByTestId('grounding-disclaimer')).toBeInTheDocument();
  });

  it('shows an error and keeps the input when the assistant fails', async () => {
    chat.listSessions.mockResolvedValue({ status: 'success', data: [session] });
    chat.getSession.mockResolvedValue({ status: 'success', data: { ...session, messages: [] } });
    chat.sendMessage.mockRejectedValue(new ApiError(503, 'The assistant could not answer right now.'));

    renderPage('/academic-chat?session=7');
    await waitFor(() => expect(screen.getByTestId('chat-window')).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText('Chat message'), { target: { value: 'Hello there' } });
    fireEvent.click(screen.getByTestId('chat-send'));
    await waitFor(() => expect(screen.getByTestId('chat-error')).toHaveTextContent('could not answer'));
    expect(screen.queryByTestId('chat-message-user')).toBeNull();
  });

  it('disables input when the scope has no indexed documents', async () => {
    const pending: ChatSessionDetail = { ...session, index: { total: 1, indexed: 0, indexing: 1, failed: 0, documents: [] }, messages: [] };
    chat.listSessions.mockResolvedValue({ status: 'success', data: [session] });
    chat.getSession.mockResolvedValue({ status: 'success', data: pending });

    renderPage('/academic-chat?session=7');
    await waitFor(() => expect(screen.getByTestId('indexing-hint')).toBeInTheDocument());
    expect(screen.getByLabelText('Chat message')).toBeDisabled();
    expect(screen.getByTestId('index-status')).toHaveTextContent('Indexing 1 document');
  });

  it('maps 403 on session load to an access error', async () => {
    chat.getSession.mockRejectedValue(new ApiError(403, 'Unauthorized'));
    renderPage('/academic-chat?session=99');
    await waitFor(() => expect(screen.getByTestId('chat-error')).toHaveTextContent('do not have access'));
  });
});
