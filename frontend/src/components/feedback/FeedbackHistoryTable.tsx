import React, { useState } from 'react';
import { RecommendationFeedbackItem, DecisionType } from '@/types/feedback';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import {
  CheckCircle2,
  XCircle,
  Eye,
  Star,
  Search,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import { HistoryPaginationMeta } from '@/types/analysisHistory';

interface FeedbackHistoryTableProps {
  items: RecommendationFeedbackItem[];
  meta: HistoryPaginationMeta;
  isLoading?: boolean;
  onPageChange: (page: number) => void;
  decisionFilter: string;
  onDecisionFilterChange: (val: string) => void;
  ratingFilter: string;
  onRatingFilterChange: (val: string) => void;
  searchQuery: string;
  onSearchChange: (val: string) => void;
}

export const FeedbackHistoryTable: React.FC<FeedbackHistoryTableProps> = ({
  items,
  meta,
  isLoading = false,
  onPageChange,
  decisionFilter,
  onDecisionFilterChange,
  ratingFilter,
  onRatingFilterChange,
  searchQuery,
  onSearchChange,
}) => {
  const [expandedCommentId, setExpandedCommentId] = useState<number | null>(null);

  const getDecisionBadge = (decision: DecisionType) => {
    switch (decision) {
      case 'ACCEPTED':
        return (
          <Badge variant="Good" size="sm" className="gap-1">
            <CheckCircle2 className="w-3 h-3" />
            Accepted
          </Badge>
        );
      case 'DISMISSED':
        return (
          <Badge variant="neutral" size="sm" className="gap-1">
            <XCircle className="w-3 h-3" />
            Dismissed
          </Badge>
        );
      case 'REVIEWED':
        return (
          <Badge variant="Attention" size="sm" className="gap-1">
            <Eye className="w-3 h-3" />
            Reviewed
          </Badge>
        );
      default:
        return <Badge variant="neutral" size="sm">{decision}</Badge>;
    }
  };

  const formatReason = (reason: string | null) => {
    if (!reason) return null;
    return reason
      .replace(/_/g, ' ')
      .toLowerCase()
      .replace(/\b\w/g, (l) => l.toUpperCase());
  };

  const renderStars = (rating: number | null) => {
    if (!rating) return <span className="text-sage-500 text-[11px] italic">Not rated</span>;
    return (
      <div className="flex items-center gap-0.5">
        {[1, 2, 3, 4, 5].map((s) => (
          <Star
            key={s}
            className={`w-3.5 h-3.5 ${
              s <= rating
                ? 'text-amber-500 fill-amber-400'
                : 'text-neutral-300 dark:text-neutral-700'
            }`}
          />
        ))}
        <span className="text-[11px] font-mono text-sage-500 ml-1">({rating})</span>
      </div>
    );
  };

  return (
    <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-sage-200 dark:border-[#2C2C2E] shadow-xs overflow-hidden">
      {/* Search & Filter Header */}
      <div className="p-4 border-b border-sage-200 dark:border-[#2C2C2E] flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
        {/* Search */}
        <div className="relative flex-1 max-w-md">
          <Search className="w-4 h-4 text-sage-500 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Search feedback notes, reasons, or recommendations..."
            value={searchQuery}
            onChange={(e) => onSearchChange(e.target.value)}
            className="w-full pl-9 pr-3 py-1.5 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] text-xs text-sage-800 dark:text-white placeholder-[#8E8E93] focus:outline-none focus:ring-1 focus:ring-black dark:focus:ring-white"
          />
        </div>

        {/* Filter Selects */}
        <div className="flex items-center gap-2">
          <select
            value={decisionFilter}
            onChange={(e) => onDecisionFilterChange(e.target.value)}
            className="px-2.5 py-1.5 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-xs text-sage-800 dark:text-white"
          >
            <option value="all">All Decisions</option>
            <option value="ACCEPTED">Accepted</option>
            <option value="DISMISSED">Dismissed</option>
            <option value="REVIEWED">Reviewed</option>
          </select>

          <select
            value={ratingFilter}
            onChange={(e) => onRatingFilterChange(e.target.value)}
            className="px-2.5 py-1.5 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-xs text-sage-800 dark:text-white"
          >
            <option value="all">All Ratings</option>
            <option value="5">5 Stars</option>
            <option value="4">4 Stars</option>
            <option value="3">3 Stars</option>
            <option value="2">2 Stars</option>
            <option value="1">1 Star</option>
          </select>
        </div>
      </div>

      {/* Table Content */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead className="bg-sage-100 dark:bg-[#2C2C2E] text-sage-500 border-b border-sage-200 dark:border-[#3A3A3C]">
            <tr>
              <th className="py-3 px-4 font-semibold">Recommendation</th>
              <th className="py-3 px-4 font-semibold">Assessment / Course</th>
              <th className="py-3 px-4 font-semibold">Decision</th>
              <th className="py-3 px-4 font-semibold">Rating</th>
              <th className="py-3 px-4 font-semibold">Reason</th>
              <th className="py-3 px-4 font-semibold">Comments</th>
              <th className="py-3 px-4 font-semibold">Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-sage-200 dark:divide-[#2C2C2E]">
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i} className="animate-pulse">
                  <td colSpan={7} className="py-4 px-4">
                    <div className="h-4 bg-neutral-100 dark:bg-neutral-800 rounded-md w-3/4" />
                  </td>
                </tr>
              ))
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={7} className="py-12 text-center text-sage-500 italic">
                  No feedback entries match the criteria.
                </td>
              </tr>
            ) : (
              items.map((item) => {
                const isCommentOpen = expandedCommentId === item.id;
                return (
                  <tr
                    key={item.id}
                    className="hover:bg-neutral-50 dark:hover:bg-neutral-800/40 transition-colors"
                  >
                    {/* Recommendation details */}
                    <td className="py-3.5 px-4 max-w-xs">
                      <div className="space-y-1">
                        <div className="flex items-center gap-1.5 flex-wrap">
                          {item.recommendation?.priority && (
                            <Badge variant="neutral" size="sm" className="text-[10px]">
                              {item.recommendation.priority}
                            </Badge>
                          )}
                          <span className="text-[10px] uppercase font-mono text-sage-500">
                            {item.recommendation?.category?.replace(/_/g, ' ')}
                          </span>
                        </div>
                        <p className="font-medium text-sage-800 dark:text-white line-clamp-2">
                          {item.recommendation?.problem || 'Recommendation #' + item.recommendation_id}
                        </p>
                      </div>
                    </td>

                    {/* Assessment / Course */}
                    <td className="py-3.5 px-4 max-w-[180px]">
                      <div className="space-y-0.5">
                        <p className="font-semibold text-sage-800 dark:text-white truncate">
                          {item.assessment?.title || 'Assessment'}
                        </p>
                        <p className="text-[11px] text-sage-500 font-mono truncate">
                          {item.course?.code || ''} {item.course?.name ? `• ${item.course.name}` : ''}
                        </p>
                      </div>
                    </td>

                    {/* Decision */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {getDecisionBadge(item.decision)}
                    </td>

                    {/* Rating */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {renderStars(item.usefulness_rating)}
                    </td>

                    {/* Reason */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {item.reason ? (
                        <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-neutral-100 dark:bg-neutral-800 text-sage-500">
                          {formatReason(item.reason)}
                        </span>
                      ) : (
                        <span className="text-sage-500 text-[11px] italic">—</span>
                      )}
                    </td>

                    {/* Comments */}
                    <td className="py-3.5 px-4 max-w-xs">
                      {item.comment ? (
                        <div>
                          <p
                            className={`text-sage-500 text-[11px] leading-relaxed ${
                              !isCommentOpen ? 'line-clamp-2' : ''
                            }`}
                          >
                            {item.comment}
                          </p>
                          {item.comment.length > 80 && (
                            <button
                              type="button"
                              onClick={() => setExpandedCommentId(isCommentOpen ? null : item.id)}
                              className="text-[10px] text-blue-600 dark:text-blue-400 mt-0.5 flex items-center gap-0.5 font-medium"
                            >
                              {isCommentOpen ? (
                                <>
                                  Less <ChevronUp className="w-3 h-3" />
                                </>
                              ) : (
                                <>
                                  More <ChevronDown className="w-3 h-3" />
                                </>
                              )}
                            </button>
                          )}
                        </div>
                      ) : (
                        <span className="text-sage-500 text-[11px] italic">No comment</span>
                      )}
                    </td>

                    {/* Date */}
                    <td className="py-3.5 px-4 whitespace-nowrap font-mono text-[11px] text-sage-500">
                      {new Date(item.created_at).toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      })}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination Footer */}
      {meta.total > 0 && (
        <div className="p-3.5 border-t border-sage-200 dark:border-[#2C2C2E] flex items-center justify-between text-xs text-sage-500">
          <div>
            Showing <span className="font-mono font-medium text-sage-800 dark:text-white">
              {Math.min((meta.current_page - 1) * meta.per_page + 1, meta.total)}
            </span> to <span className="font-mono font-medium text-sage-800 dark:text-white">
              {Math.min(meta.current_page * meta.per_page, meta.total)}
            </span> of <span className="font-mono font-medium text-sage-800 dark:text-white">{meta.total}</span> entries
          </div>

          <div className="flex items-center gap-1.5">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1 || isLoading}
              onClick={() => onPageChange(meta.current_page - 1)}
            >
              <ChevronLeft className="w-3.5 h-3.5" />
            </Button>
            <span className="px-2 font-mono">
              Page {meta.current_page} of {meta.last_page || 1}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page || isLoading}
              onClick={() => onPageChange(meta.current_page + 1)}
            >
              <ChevronRight className="w-3.5 h-3.5" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
};
