import React, { useState } from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import {
  Lightbulb,
  CheckCircle2,
  XCircle,
  Eye,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import { EvidenceBasedRecommendation, RecommendationStatus } from '@/types';
import { DecisionType } from '@/types/feedback';
import { FeedbackModal } from '@/components/feedback/FeedbackModal';

interface RecommendationsSectionProps {
  recommendations: EvidenceBasedRecommendation[];
  onStatusUpdate: (id: number | string, status: RecommendationStatus, notes?: string) => void;
  isUpdatingStatus?: boolean;
}

export const RecommendationsSection: React.FC<RecommendationsSectionProps> = ({
  recommendations,
  onStatusUpdate,
  isUpdatingStatus = false,
}) => {
  const [priorityFilter, setPriorityFilter] = useState<string>('all');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [activeNoteId, setActiveNoteId] = useState<number | string | null>(null);
  const [noteText, setNoteText] = useState<string>('');
  const [expandedEvidenceId, setExpandedEvidenceId] = useState<number | string | null>(null);

  const [modalConfig, setModalConfig] = useState<{
    isOpen: boolean;
    recommendation: EvidenceBasedRecommendation | null;
    decision: DecisionType;
  }>({
    isOpen: false,
    recommendation: null,
    decision: 'ACCEPTED',
  });

  // Extract unique categories
  const categories = Array.from(
    new Set(recommendations.map((r) => r.category).filter(Boolean))
  );

  const filteredRecs = recommendations.filter((r) => {
    const p = (r.priority || '').toUpperCase();
    const s = (r.status || '').toLowerCase();
    const c = (r.category || '').toLowerCase();

    const matchesPriority = priorityFilter === 'all' || p === priorityFilter.toUpperCase();
    const matchesStatus = statusFilter === 'all' || s === statusFilter.toLowerCase();
    const matchesCategory = categoryFilter === 'all' || c === categoryFilter.toLowerCase();

    return matchesPriority && matchesStatus && matchesCategory;
  });

  const getPriorityBadge = (p: string) => {
    const up = p.toUpperCase();
    if (up === 'HIGH') return <Badge variant="Critical" size="sm" dot>HIGH PRIORITY</Badge>;
    if (up === 'MEDIUM') return <Badge variant="Attention" size="sm" dot>MEDIUM</Badge>;
    return <Badge variant="neutral" size="sm">LOW</Badge>;
  };

  const getStatusBadge = (s: string) => {
    const ls = s.toLowerCase();
    if (ls === 'accepted') return <Badge variant="Good" size="sm">Accepted</Badge>;
    if (ls === 'dismissed') return <Badge variant="neutral" size="sm">Dismissed</Badge>;
    if (ls === 'reviewed') return <Badge variant="Attention" size="sm">Reviewed</Badge>;
    return <Badge variant="neutral" size="sm">Pending</Badge>;
  };

  const handleSaveNotes = (id: number | string) => {
    onStatusUpdate(id, 'reviewed', noteText);
    setActiveNoteId(null);
    setNoteText('');
  };

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-5">
      {/* Header & Filter Controls */}
      <div className="space-y-4 pb-4 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
              <Lightbulb className="w-4 h-4 text-amber-500" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-[#111111] dark:text-white">
                AI Recommendations
              </h3>
              <p className="text-xs text-[#737373]">
                Actionable, evidence-based recommendations generated for faculty decision
              </p>
            </div>
          </div>

          <span className="font-mono text-xs font-bold text-[#111111] dark:text-white">
            {filteredRecs.length} of {recommendations.length}
          </span>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-2 text-xs">
          {/* Priority filter */}
          <div className="flex items-center gap-1">
            <span className="text-[#737373] font-medium">Priority:</span>
            <select
              value={priorityFilter}
              onChange={(e) => setPriorityFilter(e.target.value)}
              className="px-2 py-1 rounded-md border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-xs text-[#111111] dark:text-white"
            >
              <option value="all">All Priorities</option>
              <option value="HIGH">High</option>
              <option value="MEDIUM">Medium</option>
              <option value="LOW">Low</option>
            </select>
          </div>

          {/* Status filter */}
          <div className="flex items-center gap-1">
            <span className="text-[#737373] font-medium">Status:</span>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-2 py-1 rounded-md border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-xs text-[#111111] dark:text-white"
            >
              <option value="all">All Statuses</option>
              <option value="pending">Pending</option>
              <option value="reviewed">Reviewed</option>
              <option value="accepted">Accepted</option>
              <option value="dismissed">Dismissed</option>
            </select>
          </div>

          {/* Category filter */}
          {categories.length > 0 && (
            <div className="flex items-center gap-1">
              <span className="text-[#737373] font-medium">Category:</span>
              <select
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                className="px-2 py-1 rounded-md border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-xs text-[#111111] dark:text-white capitalize"
              >
                <option value="all">All Categories</option>
                {categories.map((c, i) => (
                  <option key={i} value={c} className="capitalize">
                    {c.replace('_', ' ')}
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>
      </div>

      {/* Recommendations List */}
      {filteredRecs.length === 0 ? (
        <div className="p-6 text-center text-xs text-[#737373] italic">
          {recommendations.length === 0
            ? 'No recommendations generated yet. Assessment quality profile currently meets balanced targets.'
            : 'No recommendations matching the active filters.'}
        </div>
      ) : (
        <div className="space-y-4">
          {filteredRecs.map((rec) => {
            const isEvidenceOpen = expandedEvidenceId === rec.id;
            const currentStatus = (rec.status || 'pending').toLowerCase();

            return (
              <div
                key={rec.id}
                className="p-4 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-3"
              >
                {/* Top Badge Row */}
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2">
                    {getPriorityBadge(rec.priority)}
                    <span className="text-[11px] font-semibold text-[#737373] capitalize">
                      {rec.category?.replace('_', ' ')}
                    </span>
                    {rec.source_metric && (
                      <span className="text-[10px] text-[#737373]">
                        • {rec.source_metric}
                      </span>
                    )}
                  </div>
                  {getStatusBadge(rec.status)}
                </div>

                {/* Title / Problem */}
                <div>
                  <h4 className="text-sm font-bold text-[#111111] dark:text-white">
                    {rec.problem || rec.title}
                  </h4>
                  {rec.explanation && (
                    <p className="text-xs text-[#737373] mt-1 leading-relaxed">
                      <strong>Why this matters:</strong> {rec.explanation}
                    </p>
                  )}
                </div>

                {/* Actionable Suggestion */}
                <div className="p-3 bg-white dark:bg-[#1C1C1E] rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] text-xs">
                  <span className="font-semibold text-[#111111] dark:text-white block">
                    Recommendation:
                  </span>
                  <p className="text-[#262626] dark:text-[#D4D4D4] mt-0.5 leading-relaxed">
                    {rec.recommendation || rec.description}
                  </p>
                </div>

                {/* Evidence accordion if present */}
                {rec.evidence && Object.keys(rec.evidence).length > 0 && (
                  <div>
                    <button
                      type="button"
                      onClick={() => setExpandedEvidenceId(isEvidenceOpen ? null : rec.id)}
                      className="text-[11px] font-semibold text-[#737373] hover:text-[#111111] dark:hover:text-white flex items-center gap-1"
                    >
                      <span>Evidence Details</span>
                      {isEvidenceOpen ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                    </button>

                    {isEvidenceOpen && (
                      <pre className="mt-1.5 p-2.5 rounded-lg bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#3A3A3C] text-[11px] font-mono text-[#737373] overflow-x-auto">
                        {JSON.stringify(rec.evidence, null, 2)}
                      </pre>
                    )}
                  </div>
                )}

                {/* Faculty Notes display */}
                {rec.faculty_notes && (
                  <div className="text-[11px] text-[#737373] bg-white dark:bg-[#1C1C1E] p-2 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
                    <strong>Faculty Note:</strong> {rec.faculty_notes}
                  </div>
                )}

                {/* Note input field if reviewing */}
                {activeNoteId === rec.id && (
                  <div className="space-y-2 pt-2 border-t border-[#E5E5E5] dark:border-[#3A3A3C]">
                    <textarea
                      rows={2}
                      placeholder="Add faculty notes or review comments..."
                      value={noteText}
                      onChange={(e) => setNoteText(e.target.value)}
                      className="w-full text-xs p-2 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] text-[#111111] dark:text-white"
                    />
                    <div className="flex items-center gap-2 justify-end">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setActiveNoteId(null);
                          setNoteText('');
                        }}
                      >
                        Cancel
                      </Button>
                      <Button
                        variant="primary"
                        size="sm"
                        onClick={() => handleSaveNotes(rec.id)}
                      >
                        Save Note & Mark Reviewed
                      </Button>
                    </div>
                  </div>
                )}

                {/* Faculty Decision Action Buttons */}
                <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-[#E5E5E5] dark:border-[#3A3A3C]">
                  <span className="text-[11px] text-[#737373] italic">
                    Faculty decision support — decisions do not automatically alter assessment questions.
                  </span>

                  <div className="flex items-center gap-1.5">
                    <Button
                      variant={currentStatus === 'reviewed' ? 'primary' : 'outline'}
                      size="sm"
                      leftIcon={<Eye className="w-3.5 h-3.5" />}
                      disabled={isUpdatingStatus}
                      onClick={() =>
                        setModalConfig({
                          isOpen: true,
                          recommendation: rec,
                          decision: 'REVIEWED',
                        })
                      }
                    >
                      Review
                    </Button>

                    <Button
                      variant={currentStatus === 'accepted' ? 'primary' : 'outline'}
                      size="sm"
                      className={currentStatus === 'accepted' ? 'bg-[#16A34A] hover:bg-[#15803D] text-white' : ''}
                      leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}
                      disabled={isUpdatingStatus}
                      onClick={() =>
                        setModalConfig({
                          isOpen: true,
                          recommendation: rec,
                          decision: 'ACCEPTED',
                        })
                      }
                    >
                      Accept
                    </Button>

                    <Button
                      variant={currentStatus === 'dismissed' ? 'secondary' : 'ghost'}
                      size="sm"
                      leftIcon={<XCircle className="w-3.5 h-3.5" />}
                      disabled={isUpdatingStatus}
                      onClick={() =>
                        setModalConfig({
                          isOpen: true,
                          recommendation: rec,
                          decision: 'DISMISSED',
                        })
                      }
                    >
                      Dismiss
                    </Button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Feedback Modal for Decision & Rating */}
      <FeedbackModal
        isOpen={modalConfig.isOpen}
        recommendation={modalConfig.recommendation}
        decision={modalConfig.decision}
        onClose={() => setModalConfig((prev) => ({ ...prev, isOpen: false }))}
        onSuccess={(updatedRec) => {
          onStatusUpdate(
            updatedRec.id,
            updatedRec.status.toLowerCase() as RecommendationStatus,
            updatedRec.faculty_notes || undefined
          );
        }}
      />
    </Card>
  );
};
