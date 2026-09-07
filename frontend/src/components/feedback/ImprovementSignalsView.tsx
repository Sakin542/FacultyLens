import React, { useState } from 'react';
import { AiImprovementSignalItem, ImprovementSignalsResponse } from '@/types/feedback';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import {
  Sparkles,
  TrendingUp,
  AlertTriangle,
  Info,
  CheckCircle2,
  Filter,
  Layers,
} from 'lucide-react';

interface ImprovementSignalsViewProps {
  summary: ImprovementSignalsResponse['summary'] | null;
  signals: AiImprovementSignalItem[];
  isLoading?: boolean;
}

export const ImprovementSignalsView: React.FC<ImprovementSignalsViewProps> = ({
  summary,
  signals,
  isLoading = false,
}) => {
  const [filterValue, setFilterValue] = useState<string>('all');

  const filteredSignals = signals.filter((sig) => {
    if (filterValue === 'all') return true;
    return sig.signal_value === filterValue;
  });

  const getSignalBadge = (val: 'positive' | 'negative' | 'neutral') => {
    switch (val) {
      case 'positive':
        return (
          <Badge variant="Good" size="sm" className="gap-1">
            <CheckCircle2 className="w-3 h-3" />
            Positive Signal
          </Badge>
        );
      case 'negative':
        return (
          <Badge variant="Critical" size="sm" className="gap-1">
            <AlertTriangle className="w-3 h-3" />
            Needs Review
          </Badge>
        );
      case 'neutral':
      default:
        return (
          <Badge variant="neutral" size="sm" className="gap-1">
            <Info className="w-3 h-3" />
            Neutral / Context
          </Badge>
        );
    }
  };

  const formatSignalType = (type: string) => {
    return type
      .replace(/_/g, ' ')
      .toLowerCase()
      .replace(/\b\w/g, (l) => l.toUpperCase());
  };

  return (
    <div className="space-y-4">
      {/* Summary KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Card className="p-3.5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs">
          <div className="flex items-center justify-between text-xs text-[#737373]">
            <span>Total Signals</span>
            <Layers className="w-4 h-4 text-[#737373]" />
          </div>
          <p className="text-xl font-bold font-mono text-[#111111] dark:text-white mt-2">
            {summary?.total_signals ?? signals.length}
          </p>
          <span className="text-[10px] text-[#737373]">Aggregated feedback vectors</span>
        </Card>

        <Card className="p-3.5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs">
          <div className="flex items-center justify-between text-xs text-[#737373]">
            <span>Positive Signals</span>
            <TrendingUp className="w-4 h-4 text-emerald-500" />
          </div>
          <p className="text-xl font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-2">
            {summary?.positive_signals ?? signals.filter((s) => s.signal_value === 'positive').length}
          </p>
          <span className="text-[10px] text-[#737373]">Useful & accepted prompts</span>
        </Card>

        <Card className="p-3.5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs">
          <div className="flex items-center justify-between text-xs text-[#737373]">
            <span>Needs Review</span>
            <AlertTriangle className="w-4 h-4 text-amber-500" />
          </div>
          <p className="text-xl font-bold font-mono text-amber-600 dark:text-amber-400 mt-2">
            {summary?.needs_review_signals ?? signals.filter((s) => s.signal_value === 'negative').length}
          </p>
          <span className="text-[10px] text-[#737373]">Prompt/context adjustment cues</span>
        </Card>

        <Card className="p-3.5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs">
          <div className="flex items-center justify-between text-xs text-[#737373]">
            <span>Context / Neutral</span>
            <Info className="w-4 h-4 text-neutral-400" />
          </div>
          <p className="text-xl font-bold font-mono text-[#737373] mt-2">
            {summary?.context_neutral_signals ?? signals.filter((s) => s.signal_value === 'neutral').length}
          </p>
          <span className="text-[10px] text-[#737373]">Domain-specific baseline data</span>
        </Card>
      </div>

      {/* Assistive Transparency Disclaimer */}
      <div className="p-3.5 bg-blue-50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/40 rounded-xl flex items-start gap-2.5 text-xs text-blue-900 dark:text-blue-200">
        <Sparkles className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
        <div className="space-y-0.5">
          <p className="font-semibold">AI Improvement Telemetry</p>
          <p className="text-[11px] text-blue-700 dark:text-blue-300 leading-relaxed">
            {summary?.disclaimer ||
              'These telemetry signals capture faculty decisions to calibrate recommendation heuristics and prompt parameters. Signals never alter historical assessment evaluations or execute automated parameter reweighting without explicit review.'}
          </p>
        </div>
      </div>

      {/* Signals List Card */}
      <Card className="p-4 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs space-y-3">
        {/* Header & Filter */}
        <div className="flex items-center justify-between gap-2 pb-2 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <Filter className="w-3.5 h-3.5 text-[#737373]" />
            <span className="text-xs font-semibold text-[#111111] dark:text-white">
              Signal Feed ({filteredSignals.length})
            </span>
          </div>

          <div className="flex items-center gap-1">
            {(['all', 'positive', 'negative', 'neutral'] as const).map((val) => (
              <button
                key={val}
                type="button"
                onClick={() => setFilterValue(val)}
                className={`px-2.5 py-1 rounded-md text-[11px] font-medium transition-colors capitalize ${
                  filterValue === val
                    ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111]'
                    : 'text-[#737373] hover:bg-neutral-100 dark:hover:bg-neutral-800'
                }`}
              >
                {val === 'negative' ? 'Needs Review' : val}
              </button>
            ))}
          </div>
        </div>

        {/* List items */}
        {isLoading ? (
          <div className="space-y-2 py-4">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-16 rounded-xl bg-neutral-100 dark:bg-neutral-800 animate-pulse" />
            ))}
          </div>
        ) : filteredSignals.length === 0 ? (
          <div className="py-8 text-center text-xs text-[#737373] italic">
            No improvement signals generated yet. Feedback recorded on recommendations will populate this stream.
          </div>
        ) : (
          <div className="space-y-2.5">
            {filteredSignals.map((sig) => (
              <div
                key={sig.id}
                  className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-xs space-y-2"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex items-center gap-2 flex-wrap">
                      {getSignalBadge(sig.signal_value)}
                      <span className="font-bold text-[#111111] dark:text-white font-mono text-[11px]">
                        {formatSignalType(sig.signal_type)}
                      </span>
                      <span className="text-[10px] text-[#737373]">
                        src: {sig.source}
                      </span>
                    </div>

                    <span className="text-[11px] font-mono text-[#737373] shrink-0">
                      {new Date(sig.created_at).toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      })}
                    </span>
                  </div>

                  {/* Metadata Context */}
                  {sig.metadata && (
                    <div className="space-y-1 pt-1 border-t border-[#E5E5E5] dark:border-[#3A3A3C]">
                      {sig.metadata.problem_title && (
                        <p className="font-medium text-[#111111] dark:text-white">
                          Target: {sig.metadata.problem_title}
                        </p>
                      )}

                      <div className="flex items-center gap-3 text-[11px] text-[#737373] flex-wrap">
                        {sig.metadata.category && (
                          <span>Category: <strong className="text-[#111111] dark:text-white uppercase font-mono">{sig.metadata.category}</strong></span>
                        )}
                        {sig.metadata.priority && (
                          <span>Priority: <strong className="text-[#111111] dark:text-white">{sig.metadata.priority}</strong></span>
                        )}
                        {sig.metadata.decision && (
                          <span>Decision: <strong className="text-[#111111] dark:text-white">{sig.metadata.decision}</strong></span>
                        )}
                        {sig.metadata.usefulness_rating !== undefined && (
                          <span>Rating: <strong className="text-amber-600">{sig.metadata.usefulness_rating}/5</strong></span>
                        )}
                        {sig.metadata.reason && (
                          <span>Reason: <strong className="text-[#111111] dark:text-white">{sig.metadata.reason}</strong></span>
                        )}
                      </div>

                      {sig.metadata.comment_preview && (
                        <p className="text-[11px] text-[#737373] italic bg-white dark:bg-[#1C1C1E] p-2 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
                          &ldquo;{sig.metadata.comment_preview}&rdquo;
                        </p>
                      )}
                    </div>
                  )}
                </div>
              ))}
          </div>
        )}
      </Card>
    </div>
  );
};
