import React, { useEffect, useRef, useState } from 'react';
import { Bot, SendHorizontal, User } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { ChatMessage as ChatMessageType } from '@/types/chat';
import { ChatSourceList } from './ChatSources';
import { ChatEmptyState, ChatLoading, GroundingDisclaimer } from './ChatStates';
import { cn } from '@/utils/cn';
import { WhyButton } from '@/components/explainability/WhyButton';
import { useExplanationModal } from '@/hooks/useExplanationModal';

/**
 * STEP 32: Message rendering + input. Answers are rendered as plain text (no HTML) — document content
 * is untrusted and must never be interpreted as markup.
 */

export const ChatMessage: React.FC<{ message: ChatMessageType }> = ({ message }) => {
  const isUser = message.role === 'USER';
  const { explain, modal } = useExplanationModal();
  return (
    <div data-testid={`chat-message-${message.role.toLowerCase()}`} className={cn('flex gap-3', isUser ? 'flex-row-reverse' : 'flex-row')}>
      <div
        className={cn(
          'w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0',
          isUser ? 'bg-sage-700 dark:bg-white text-white dark:text-sage-800' : 'bg-sage-100 dark:bg-[#1F1F1F] border border-sage-200 dark:border-[#2A2A2A] text-sage-600 dark:text-sage-400'
        )}
      >
        {isUser ? <User className="w-4 h-4" /> : <Bot className="w-4 h-4" />}
      </div>
      <div className={cn('max-w-[85%] space-y-2', isUser ? 'items-end' : 'items-start')}>
        <div
          className={cn(
            'rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap break-words',
            isUser
              ? 'bg-sage-700 dark:bg-white text-white dark:text-sage-800 rounded-tr-sm'
              : 'bg-sage-100 dark:bg-[#1F1F1F] text-sage-800 dark:text-white border border-sage-200 dark:border-[#2A2A2A] rounded-tl-sm'
          )}
        >
          {message.content}
        </div>
        {!isUser && (
          <div className="flex flex-wrap items-center gap-2 text-xs text-sage-500">
            {message.grounded ? (
              <Badge variant="Good" data-testid="grounded-badge">Grounded in documents</Badge>
            ) : (
              <Badge variant="Attention" data-testid="ungrounded-badge">No supporting evidence found</Badge>
            )}
            {message.generation_method && <span className="font-mono">{message.generation_method}</span>}
            {message.id > 0 && <WhyButton describes="how this answer was produced and its sources" onClick={() => explain('rag_answer', message.id, 'Answer sources & grounding')} />}
          </div>
        )}
        {!isUser && message.sources.length > 0 && <ChatSourceList sources={message.sources} />}
        {!isUser && modal}
      </div>
    </div>
  );
};

export interface ChatInputProps {
  onSend: (message: string) => Promise<void> | void;
  disabled?: boolean;
  maxLength?: number;
  placeholder?: string;
}

export const ChatInput: React.FC<ChatInputProps> = ({ onSend, disabled, maxLength = 5000, placeholder = 'Ask a question about the documents in this chat…' }) => {
  const [value, setValue] = useState('');
  const trimmed = value.trim();
  const canSend = !disabled && trimmed.length > 0 && trimmed.length <= maxLength;

  const submit = async () => {
    if (!canSend) return;
    const text = trimmed;
    setValue('');
    await onSend(text);
  };

  return (
    <form
      data-testid="chat-input"
      className="flex items-end gap-2 border-t border-sage-200 dark:border-[#2A2A2A] p-3 bg-white dark:bg-sage-700"
      onSubmit={(e) => {
        e.preventDefault();
        void submit();
      }}
    >
      <div className="flex-1">
        <textarea
          aria-label="Chat message"
          value={value}
          disabled={disabled}
          maxLength={maxLength}
          rows={2}
          placeholder={placeholder}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              void submit();
            }
          }}
          className="w-full resize-none rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-sage-800 dark:text-white placeholder:text-sage-400 focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white disabled:opacity-60"
        />
        <div className="mt-1 flex justify-between text-[11px] text-sage-400">
          <span>Enter to send · Shift+Enter for a new line</span>
          <span className={cn(trimmed.length > maxLength && 'text-red-600')}>{value.length}/{maxLength}</span>
        </div>
      </div>
      <Button type="submit" size="sm" disabled={!canSend} leftIcon={<SendHorizontal className="w-4 h-4" />} data-testid="chat-send">
        Send
      </Button>
    </form>
  );
};

export interface ChatWindowProps {
  messages: ChatMessageType[];
  loading?: boolean;
  disclaimer?: string | null;
  emptyTitle?: string;
  emptyDescription?: string;
}

export const ChatWindow: React.FC<ChatWindowProps> = ({ messages, loading, disclaimer, emptyTitle, emptyDescription }) => {
  const bottomRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    bottomRef.current?.scrollIntoView?.({ behavior: 'smooth' });
  }, [messages.length, loading]);

  return (
    <div data-testid="chat-window" className="flex-1 overflow-y-auto p-4 space-y-4">
      {messages.length === 0 && !loading ? (
        <ChatEmptyState title={emptyTitle} description={emptyDescription} />
      ) : (
        <>
          <GroundingDisclaimer text={disclaimer ?? undefined} />
          {messages.map((m) => <ChatMessage key={m.id} message={m} />)}
        </>
      )}
      {loading && <ChatLoading />}
      <div ref={bottomRef} />
    </div>
  );
};
