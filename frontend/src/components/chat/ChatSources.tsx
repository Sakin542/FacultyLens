import React, { useState } from 'react';
import { ChevronDown, ChevronUp, FileText } from 'lucide-react';
import { ChatSource } from '@/types/chat';
import { cn } from '@/utils/cn';

/**
 * STEP 32: Source citations for an assistant message. Only shows metadata the backend actually has —
 * no page/section is invented when missing.
 */

export function formatSourceLocation(source: ChatSource): string {
  const parts: string[] = [];
  if (source.page_number != null) parts.push(`Page ${source.page_number}`);
  if (source.section_title) parts.push(source.section_title);
  return parts.join(' · ');
}

export const ChatSourceCard: React.FC<{ source: ChatSource; index: number }> = ({ source, index }) => {
  const [open, setOpen] = useState(false);
  const location = formatSourceLocation(source);

  return (
    <div data-testid="chat-source-card" className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] text-sm">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="w-full flex items-start gap-2 px-3 py-2 text-left"
      >
        <span className="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full bg-[#111111] dark:bg-white text-white dark:text-[#111111] text-[10px] font-bold flex-shrink-0">
          {index}
        </span>
        <FileText className="w-4 h-4 mt-0.5 text-[#737373] flex-shrink-0" />
        <span className="flex-1 min-w-0">
          <span className="block font-medium text-[#111111] dark:text-white truncate">{source.document_name}</span>
          <span className="block text-xs text-[#737373]">
            {location || 'Location not available'}
            {source.similarity_score != null && (
              <span className="ml-2 font-mono">rel. {Math.round(source.similarity_score * 100)}%</span>
            )}
          </span>
        </span>
        {source.excerpt && (open ? <ChevronUp className="w-4 h-4 text-[#737373]" /> : <ChevronDown className="w-4 h-4 text-[#737373]" />)}
      </button>
      {open && source.excerpt && (
        <blockquote className="mx-3 mb-3 border-l-2 border-[#E5E5E5] dark:border-[#2A2A2A] pl-3 text-xs text-[#525252] dark:text-[#A3A3A3] whitespace-pre-wrap">
          {source.excerpt}
        </blockquote>
      )}
    </div>
  );
};

export const ChatSourceList: React.FC<{ sources: ChatSource[]; className?: string }> = ({ sources, className }) => {
  if (!sources.length) return null;
  return (
    <div data-testid="chat-source-list" className={cn('space-y-1.5', className)}>
      <p className="text-xs font-semibold uppercase tracking-wide text-[#737373]">Sources ({sources.length})</p>
      {sources.map((s, i) => (
        <ChatSourceCard key={s.id ?? `${s.chunk_id}-${i}`} source={s} index={s.source_order || i + 1} />
      ))}
    </div>
  );
};
