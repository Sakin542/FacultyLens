/**
 * STEP 32: AI Chat with Academic Documents (RAG) types.
 */
export type ChatScopeType = 'COURSE' | 'DOCUMENT' | 'ASSESSMENT';
export type ChatRole = 'USER' | 'ASSISTANT';
export type IndexingStatus = 'NOT_INDEXED' | 'INDEXING' | 'INDEXED' | 'FAILED' | 'STALE';

export interface ChatScopeDocument {
  id: number;
  name: string;
  document_type: string | null;
  processing_status: string;
  indexing_status: IndexingStatus;
  chunk_count: number;
}

export interface ChatIndexSummary {
  total: number;
  indexed: number;
  indexing: number;
  failed: number;
  documents: ChatScopeDocument[];
}

export interface ChatSource {
  id: number;
  document_id: number | null;
  chunk_id: number | null;
  document_name: string;
  document_type: string | null;
  similarity_score: number | null;
  page_number: number | null;
  section_title: string | null;
  excerpt: string | null;
  source_order: number;
}

export interface ChatMessage {
  id: number;
  role: ChatRole;
  content: string;
  grounded: boolean;
  generation_method: string | null;
  generation_model: string | null;
  embedding_model: string | null;
  prompt_version: string | null;
  retrieved_count: number | null;
  used_count: number | null;
  disclaimer: string | null;
  sources: ChatSource[];
  created_at: string | null;
}

export interface ChatSession {
  id: number;
  title: string;
  scope_type: ChatScopeType;
  course_id: number | null;
  document_id: number | null;
  assessment_id: number | null;
  course: { id: number; course_name: string | null; course_code: string | null } | null;
  document: { id: number; name: string; document_type: string | null } | null;
  assessment: { id: number; title: string } | null;
  status: string;
  message_count: number;
  last_message_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  index?: ChatIndexSummary;
}

export interface ChatSessionDetail extends ChatSession {
  messages: ChatMessage[];
}

export interface CreateChatSessionInput {
  scope_type: ChatScopeType;
  course_id?: number | string | null;
  document_id?: number | string | null;
  assessment_id?: number | string | null;
  title?: string | null;
}

export interface SendMessageResult {
  user_message: ChatMessage | null;
  assistant_message: ChatMessage;
  session: ChatSession;
}
