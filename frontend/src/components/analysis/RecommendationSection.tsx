import React, { useState } from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { EvidenceBasedRecommendation, RecommendationPriority, RecommendationStatus } from '@/types';
import {
  AlertTriangle,
  CheckCircle2,
  XCircle,
  HelpCircle,
  Search,
  Sparkles,
  BookOpen,
  Target,
  BarChart3,
  Layers,
  Shapes,
  Percent,
  CopyCheck,
  Award,
  MessageSquare,
  Loader2,
} from 'lucide-react';

interface RecommendationSectionProps {
  recommendations: EvidenceBasedRecommendation[];
  onStatusChange?: (id: number | string, status: RecommendationStatus, notes?: string) => void;
  isLoading?: boolean;
}

export const RecommendationSection: React.FC<RecommendationSectionProps> = ({
  recommendations: initialRecommendations,
  onStatusChange,
  isLoading = false,
}) => {
  const [localRecs, setLocalRecs] = useState<EvidenceBasedRecommendation[]>(initialRecommendations);
  const [selectedPriority, setSelectedPriority] = useState<string>('all');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [selectedStatus, setSelectedStatus] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [activeNoteId, setActiveNoteId] = useState<number | string | null>(null);
  const [noteText, setNoteText] = useState<string>('');

  // Keep local state synced if parent updates
  React.useEffect(() => {
    setLocalRecs(initialRecommendations);
  }, [initialRecommendations]);

  const handleStatusUpdate = (id: number | string, newStatus: RecommendationStatus, notes?: string) => {
    setLocalRecs((prev) =>
      prev.map((r) => (r.id === id ? { ...r, status: newStatus, faculty_notes: notes ?? r.faculty_notes } : r))
    );
    if (onStatusChange) {
      onStatusChange(id, newStatus, notes);
    }
  };

  const highCount = localRecs.filter((r) => r.priority.toUpperCase() === 'HIGH').length;
  const medCount = localRecs.filter((r) => r.priority.toUpperCase() === 'MEDIUM').length;
  const lowCount = localRecs.filter((r) => r.priority.toUpperCase() === 'LOW').length;

  const acceptedCount = localRecs.filter((r) => r.status?.toLowerCase() === 'accepted').length;
  const dismissedCount = localRecs.filter((r) => r.status?.toLowerCase() === 'dismissed').length;
  const pendingCount = localRecs.filter((r) => !r.status || r.status.toLowerCase() === 'pending').length;

  // Filtered recommendations
  const filtered = localRecs.filter((rec) => {
    if (selectedPriority !== 'all' && rec.priority.toUpperCase() !== selectedPriority.toUpperCase()) {
      return false;
    }
    if (selectedCategory !== 'all' && rec.category.toLowerCase() !== selectedCategory.toLowerCase()) {
      return false;
    }
    if (selectedStatus !== 'all') {
      const st = (rec.status || 'pending').toLowerCase();
      if (st !== selectedStatus.toLowerCase()) return false;
    }
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchProb = rec.problem?.toLowerCase().includes(q);
      const matchExpl = rec.explanation?.toLowerCase().includes(q);
      const matchRec = rec.recommendation?.toLowerCase().includes(q);
      const matchCat = rec.category?.toLowerCase().includes(q);
      if (!matchProb && !matchExpl && !matchRec && !matchCat) return false;
    }
    return true;
  });

  const getPriorityBadge = (priority: RecommendationPriority) => {
    const p = (priority || '').toUpperCase();
    if (p === 'HIGH') {
      return (
        <span className="px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#FEF2F2] text-[#DC2626] border border-[#FECACA] flex items-center gap-1">
          <AlertTriangle className="w-3 h-3 text-[#DC2626]" />
          HIGH PRIORITY
        </span>
      );
    }
    if (p === 'MEDIUM') {
      return (
        <span className="px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#FFFBEB] text-[#D97706] border border-[#FDE68A] flex items-center gap-1">
          <AlertTriangle className="w-3 h-3 text-[#D97706]" />
          MEDIUM PRIORITY
        </span>
      );
    }
    return (
      <span className="px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#F0FDF4] text-[#16A34A] border border-[#BBF7D0] flex items-center gap-1">
        <CheckCircle2 className="w-3 h-3 text-[#16A34A]" />
        LOW PRIORITY
      </span>
    );
  };

  const getCategoryIcon = (category: string) => {
    switch (category.toLowerCase()) {
      case 'topic_coverage':
        return <BookOpen className="w-3.5 h-3.5 text-[#2563EB]" />;
      case 'learning_outcome':
        return <Target className="w-3.5 h-3.5 text-[#9333EA]" />;
      case 'difficulty':
        return <BarChart3 className="w-3.5 h-3.5 text-[#EA580C]" />;
      case 'cognitive_level':
        return <Layers className="w-3.5 h-3.5 text-[#0D9488]" />;
      case 'question_diversity':
        return <Shapes className="w-3.5 h-3.5 text-[#4F46E5]" />;
      case 'marks_distribution':
        return <Percent className="w-3.5 h-3.5 text-[#D97706]" />;
      case 'semantic_similarity':
        return <CopyCheck className="w-3.5 h-3.5 text-[#DC2626]" />;
      default:
        return <Award className="w-3.5 h-3.5 text-[#4B5563]" />;
    }
  };

  const formatCategoryName = (cat: string) => {
    return cat
      .split('_')
      .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ');
  };

  return (
    <div className="space-y-6">
      {/* KPI & Summary Ribbon */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <Card className="p-3.5 bg-white border-sage-200">
          <span className="text-[10px] font-bold uppercase tracking-wider text-sage-500">Total</span>
          <div className="text-2xl font-extrabold text-sage-800 mt-0.5 font-mono">{localRecs.length}</div>
          <span className="text-[10px] text-sage-500">Suggestions</span>
        </Card>

        <Card className="p-3.5 bg-[#FEF2F2]/40 border-[#FECACA]">
          <span className="text-[10px] font-bold uppercase tracking-wider text-[#DC2626]">High Priority</span>
          <div className="text-2xl font-extrabold text-[#DC2626] mt-0.5 font-mono">{highCount}</div>
          <span className="text-[10px] text-[#991B1B]">Action advised</span>
        </Card>

        <Card className="p-3.5 bg-[#FFFBEB]/40 border-[#FDE68A]">
          <span className="text-[10px] font-bold uppercase tracking-wider text-[#D97706]">Medium Priority</span>
          <div className="text-2xl font-extrabold text-[#D97706] mt-0.5 font-mono">{medCount}</div>
          <span className="text-[10px] text-[#B45309]">Review balance</span>
        </Card>

        <Card className="p-3.5 bg-[#F0FDF4]/40 border-[#BBF7D0]">
          <span className="text-[10px] font-bold uppercase tracking-wider text-[#16A34A]">Low Priority</span>
          <div className="text-2xl font-extrabold text-[#16A34A] mt-0.5 font-mono">{lowCount}</div>
          <span className="text-[10px] text-[#15803D]">Optional polish</span>
        </Card>

        <Card className="p-3.5 bg-[#F0FDF4] border-[#86EFAC]">
          <span className="text-[10px] font-bold uppercase tracking-wider text-[#15803D]">Accepted</span>
          <div className="text-2xl font-extrabold text-[#15803D] mt-0.5 font-mono">{acceptedCount}</div>
          <span className="text-[10px] text-[#166534]">Agreed</span>
        </Card>

        <Card className="p-3.5 bg-sage-100 border-sage-200">
          <span className="text-[10px] font-bold uppercase tracking-wider text-sage-500">Dismissed</span>
          <div className="text-2xl font-extrabold text-sage-500 mt-0.5 font-mono">{dismissedCount}</div>
          <span className="text-[10px] text-sage-500">Intended design</span>
        </Card>
      </div>

      {/* Filter and Search Bar */}
      <Card className="p-4 bg-white border-sage-200">
        <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
          {/* Status Tabs */}
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0">
            {[
              { id: 'all', label: `All (${localRecs.length})` },
              { id: 'pending', label: `Pending (${pendingCount})` },
              { id: 'accepted', label: `Accepted (${acceptedCount})` },
              { id: 'dismissed', label: `Dismissed (${dismissedCount})` },
            ].map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => setSelectedStatus(tab.id)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors shrink-0 ${
                  selectedStatus === tab.id
                    ? 'bg-sage-700 text-white shadow-subtle'
                    : 'text-sage-500 hover:text-sage-800 hover:bg-sage-100'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {/* Search and Priority / Category dropdowns */}
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-[180px]">
              <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-sage-500" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search problem, LO, topic..."
                className="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-sage-200 bg-sage-100 focus:outline-none focus:border-sage-700"
              />
            </div>

            <select
              aria-label="Filter recommendations by priority"
              value={selectedPriority}
              onChange={(e) => setSelectedPriority(e.target.value)}
              className="text-xs px-2.5 py-1.5 rounded-lg border border-sage-200 bg-white font-medium text-sage-800 focus:outline-none focus:border-sage-700"
            >
              <option value="all">All Priorities</option>
              <option value="HIGH">High Priority</option>
              <option value="MEDIUM">Medium Priority</option>
              <option value="LOW">Low Priority</option>
            </select>

            <select
              aria-label="Filter recommendations by category"
              value={selectedCategory}
              onChange={(e) => setSelectedCategory(e.target.value)}
              className="text-xs px-2.5 py-1.5 rounded-lg border border-sage-200 bg-white font-medium text-sage-800 focus:outline-none focus:border-sage-700"
            >
              <option value="all">All Categories</option>
              <option value="topic_coverage">Topic Coverage</option>
              <option value="learning_outcome">Learning Outcome</option>
              <option value="difficulty">Difficulty</option>
              <option value="cognitive_level">Cognitive Level</option>
              <option value="question_diversity">Question Diversity</option>
              <option value="marks_distribution">Marks Distribution</option>
              <option value="semantic_similarity">Semantic Similarity</option>
              <option value="assessment_quality">Assessment Quality</option>
            </select>
          </div>
        </div>
      </Card>

      {/* Recommendation Cards List */}
      <div className="space-y-4">
        {isLoading ? (
          <Card className="p-12 text-center bg-white border-sage-200">
            <Loader2 className="w-8 h-8 text-sage-800 animate-spin mx-auto mb-2" />
            <p className="text-xs text-sage-500">Evaluating assessment dimensions & updating recommendations...</p>
          </Card>
        ) : filtered.length === 0 ? (
          <Card className="p-8 text-center bg-white border-dashed border-sage-200">
            <CheckCircle2 className="w-10 h-10 text-[#16A34A] mx-auto mb-3" />
            <h4 className="text-sm font-bold text-sage-800">No Recommendations in this View</h4>
            <p className="text-xs text-sage-500 max-w-md mx-auto mt-1">
              {searchQuery || selectedPriority !== 'all' || selectedCategory !== 'all' || selectedStatus !== 'all'
                ? 'Try clearing active filters to view all recommendations.'
                : 'All evaluation dimensions meet the required academic quality criteria without detected problems.'}
            </p>
          </Card>
        ) : (
          filtered.map((rec) => {
            const status = (rec.status || 'pending').toLowerCase();
            const isAccepted = status === 'accepted';
            const isDismissed = status === 'dismissed';
            const isEditingNote = activeNoteId === rec.id;

            return (
              <Card
                key={rec.id}
                className={`p-5 transition-all space-y-4 ${
                  isAccepted
                    ? 'border-[#86EFAC] bg-[#F0FDF4]/30'
                    : isDismissed
                    ? 'border-sage-200 bg-sage-100/60 opacity-80'
                    : 'border-sage-200 bg-white hover:border-sage-700'
                }`}
              >
                {/* Header: Priority, Category, Status */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-2 border-b border-sage-200">
                  <div className="flex flex-wrap items-center gap-2">
                    {getPriorityBadge(rec.priority)}
                    <span className="px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-sage-100 border border-sage-200 text-sage-800 flex items-center gap-1.5">
                      {getCategoryIcon(rec.category)}
                      {formatCategoryName(rec.category)}
                    </span>
                    <span className="text-[10px] text-sage-500 font-mono">Source: {rec.source_metric}</span>
                  </div>

                  {/* Status Indicator */}
                  <div className="flex items-center gap-2">
                    {isAccepted && (
                      <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-[#DCFCE7] text-[#15803D] flex items-center gap-1">
                        <CheckCircle2 className="w-3.5 h-3.5 text-[#16A34A]" />
                        Accepted for Revision
                      </span>
                    )}
                    {isDismissed && (
                      <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-[#F3F4F6] text-[#6B7280] flex items-center gap-1">
                        <XCircle className="w-3.5 h-3.5 text-[#9CA3AF]" />
                        Dismissed (Retained)
                      </span>
                    )}
                    {!isAccepted && !isDismissed && (
                      <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-[#FEF3C7] text-[#92400E] flex items-center gap-1">
                        <HelpCircle className="w-3.5 h-3.5 text-[#D97706]" />
                        Pending Faculty Decision
                      </span>
                    )}
                  </div>
                </div>

                {/* Problem Statement */}
                <div>
                  <h4 className="text-base font-bold text-sage-800 flex items-start gap-2">
                    <span>{rec.problem}</span>
                  </h4>
                  <p className="text-xs text-sage-500 mt-1 leading-relaxed">
                    <span className="font-semibold text-sage-800">Why this matters: </span>
                    {rec.explanation}
                  </p>
                </div>

                {/* Evidence Section */}
                {rec.evidence && Object.keys(rec.evidence).length > 0 && (
                  <div className="p-3.5 rounded-lg border border-sage-200 bg-sage-100 space-y-2 text-xs">
                    <div className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-sage-500">
                      <BarChart3 className="w-3.5 h-3.5 text-sage-800" />
                      <span>Empirical Analysis Evidence</span>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 pt-1 font-mono text-[11px]">
                      {Object.entries(rec.evidence).map(([key, val]) => {
                        const label = key.replace(/_/g, ' ');
                        const displayVal = typeof val === 'object' ? JSON.stringify(val) : String(val);
                        return (
                          <div key={key} className="p-2 rounded bg-white border border-sage-200">
                            <span className="text-sage-500 text-[10px] uppercase block">{label}</span>
                            <span className="font-bold text-sage-800 truncate block">{displayVal}</span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Actionable Recommendation */}
                <div className="p-3.5 rounded-lg border border-[#BBF7D0] bg-[#F0FDF4] space-y-1.5">
                  <div className="flex items-center gap-2 text-xs font-bold text-[#15803D]">
                    <Sparkles className="w-4 h-4 text-[#16A34A]" />
                    <span>Actionable Faculty Recommendation</span>
                  </div>
                  <p className="text-xs text-[#166534] leading-relaxed font-medium">
                    {rec.recommendation}
                  </p>
                </div>

                {/* Optional Faculty Notes */}
                {rec.faculty_notes && (
                  <div className="p-3 rounded-lg border border-sage-200 bg-white text-xs space-y-1">
                    <span className="font-semibold text-sage-500 flex items-center gap-1.5 text-[11px]">
                      <MessageSquare className="w-3.5 h-3.5 text-sage-800" />
                      Faculty Notes:
                    </span>
                    <p className="text-xs text-sage-800 italic">{rec.faculty_notes}</p>
                  </div>
                )}

                {/* Faculty Decision Controls */}
                <div className="pt-2 flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-2">
                    <Button
                      variant={isAccepted ? 'primary' : 'outline'}
                      size="sm"
                      leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}
                      onClick={() => handleStatusUpdate(rec.id, 'accepted')}
                    >
                      {isAccepted ? 'Accepted' : 'Accept Suggestion'}
                    </Button>

                    <Button
                      variant={isDismissed ? 'primary' : 'outline'}
                      size="sm"
                      leftIcon={<XCircle className="w-3.5 h-3.5" />}
                      onClick={() => handleStatusUpdate(rec.id, 'dismissed')}
                    >
                      {isDismissed ? 'Dismissed' : 'Dismiss'}
                    </Button>

                    {(isAccepted || isDismissed) && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleStatusUpdate(rec.id, 'pending')}
                        className="text-xs text-sage-500"
                      >
                        Reset to Pending
                      </Button>
                    )}
                  </div>

                  <button
                    type="button"
                    onClick={() => {
                      if (isEditingNote) {
                        setActiveNoteId(null);
                      } else {
                        setActiveNoteId(rec.id);
                        setNoteText(rec.faculty_notes || '');
                      }
                    }}
                    className="text-xs text-sage-500 hover:text-sage-800 flex items-center gap-1 font-medium"
                  >
                    <MessageSquare className="w-3.5 h-3.5" />
                    {isEditingNote ? 'Close Note' : rec.faculty_notes ? 'Edit Note' : 'Add Note'}
                  </button>
                </div>

                {/* Inline Note Editor */}
                {isEditingNote && (
                  <div className="p-3 rounded-lg border border-sage-200 bg-white space-y-2 pt-2">
                    <label className="text-[11px] font-semibold text-sage-500 block">
                      Faculty Decision Rationale / Annotation:
                    </label>
                    <textarea
                      value={noteText}
                      onChange={(e) => setNoteText(e.target.value)}
                      placeholder="e.g. Will add an applied recurrence relation question in Section B..."
                      rows={2}
                      className="w-full p-2 text-xs rounded border border-sage-200 bg-sage-100 focus:outline-none focus:border-sage-700"
                    />
                    <div className="flex justify-end gap-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setActiveNoteId(null)}
                      >
                        Cancel
                      </Button>
                      <Button
                        variant="primary"
                        size="sm"
                        onClick={() => {
                          handleStatusUpdate(rec.id, (rec.status || 'pending') as RecommendationStatus, noteText);
                          setActiveNoteId(null);
                        }}
                      >
                        Save Note
                      </Button>
                    </div>
                  </div>
                )}
              </Card>
            );
          })
        )}
      </div>
    </div>
  );
};
