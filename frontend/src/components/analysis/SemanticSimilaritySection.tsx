import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import { Button } from '@/components/common/Button';
import {
  CopyCheck,
  AlertTriangle,
  Info,
  ChevronDown,
  ChevronUp,
  Sparkles,
  ArrowRight,
  Check,
} from 'lucide-react';
import { SimilarityAnalysisResult, SimilarityStatus } from '@/types';

interface SemanticSimilaritySectionProps {
  similarityData?: SimilarityAnalysisResult | null;
  onTriggerAnalysis?: () => void;
  isLoading?: boolean;
}

export const SemanticSimilaritySection: React.FC<SemanticSimilaritySectionProps> = ({
  similarityData,
  onTriggerAnalysis,
  isLoading = false,
}) => {
  const [expandedQuestions, setExpandedQuestions] = useState<Record<string | number, boolean>>({});
  const [reviewedDecisions, setReviewedDecisions] = useState<Record<string | number, 'accepted' | 'flagged'>>({});

  const toggleExpand = (id: string | number) => {
    setExpandedQuestions((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  const markDecision = (id: string | number, decision: 'accepted' | 'flagged') => {
    setReviewedDecisions((prev) => ({
      ...prev,
      [id]: prev[id] === decision ? undefined as any : decision,
    }));
  };

  const getStatusBadge = (status: SimilarityStatus) => {
    switch (status) {
      case 'POTENTIAL_DUPLICATE':
        return <Badge variant="Critical" dot>Potential Duplicate</Badge>;
      case 'HIGHLY_SIMILAR':
        return <Badge variant="Attention" dot>Highly Similar</Badge>;
      case 'SOMEWHAT_SIMILAR':
        return <Badge variant="neutral">Somewhat Similar</Badge>;
      default:
        return <Badge variant="Good" dot>Novel Question</Badge>;
    }
  };

  if (!similarityData) {
    return (
      <Card className="p-6 text-center space-y-3">
        <div className="w-12 h-12 rounded-2xl bg-sage-100 border border-sage-200 flex items-center justify-center mx-auto text-sage-500">
          <CopyCheck className="w-6 h-6" />
        </div>
        <div>
          <h3 className="text-sm font-bold text-sage-800">No Similarity Analysis Available</h3>
          <p className="text-xs text-sage-500 mt-1 max-w-md mx-auto">
            Cross-reference examination questions against historical archives using dense sentence embeddings to detect duplicate or repeated items.
          </p>
        </div>
        {onTriggerAnalysis && (
          <Button
            variant="primary"
            size="sm"
            onClick={onTriggerAnalysis}
            disabled={isLoading}
            className="gap-2 mx-auto text-xs"
          >
            <Sparkles className="w-3.5 h-3.5" />
            <span>Run Similarity Analysis</span>
          </Button>
        )}
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {/* Top Metric Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-sage-500 tracking-wider">Total Questions</span>
              <div className="text-3xl font-extrabold text-sage-800 mt-1 font-mono">
                {similarityData.total_current_questions}
              </div>
            </div>
            <Badge variant="neutral">{similarityData.total_previous_questions} in Bank</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={100} showValue={false} size="sm" />
          </div>
        </Card>

        <Card className={`p-5 flex flex-col justify-between ${similarityData.potential_duplicates_count > 0 ? 'border-[#FECACA] bg-[#FEF2F2]/40' : ''}`}>
          <div className="flex items-start justify-between">
            <div>
              <span className={`text-xs uppercase font-semibold tracking-wider ${similarityData.potential_duplicates_count > 0 ? 'text-[#991B1B]' : 'text-sage-500'}`}>
                Potential Duplicates
              </span>
              <div className={`text-3xl font-extrabold mt-1 font-mono ${similarityData.potential_duplicates_count > 0 ? 'text-[#DC2626]' : 'text-sage-800'}`}>
                {similarityData.potential_duplicates_count}
              </div>
            </div>
            {similarityData.potential_duplicates_count > 0 ? (
              <Badge variant="Critical" dot>Flagged</Badge>
            ) : (
              <Badge variant="Good" dot>None</Badge>
            )}
          </div>
          <div className="pt-3">
            <ProgressBar
              value={(similarityData.potential_duplicates_count / (similarityData.total_current_questions || 1)) * 100}
              showValue={false}
              size="sm"
            />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-sage-500 tracking-wider">Highly Similar</span>
              <div className="text-3xl font-extrabold text-sage-800 mt-1 font-mono">
                {similarityData.highly_similar_count}
              </div>
            </div>
            <Badge variant={similarityData.highly_similar_count > 0 ? 'Attention' : 'Good'}>
              {similarityData.highly_similar_count > 0 ? 'Review' : 'Clear'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar
              value={(similarityData.highly_similar_count / (similarityData.total_current_questions || 1)) * 100}
              showValue={false}
              size="sm"
            />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-sage-500 tracking-wider">Avg Similarity</span>
              <div className="text-3xl font-extrabold text-sage-800 mt-1 font-mono">
                {similarityData.average_similarity_score}%
              </div>
            </div>
            <Badge variant="neutral">Overlap</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={similarityData.average_similarity_score} showValue={false} size="sm" />
          </div>
        </Card>
      </div>

      {/* Findings */}
      {similarityData.findings && similarityData.findings.length > 0 && (
        <Card className="p-4 bg-sage-100 border border-sage-200 space-y-2">
          <div className="flex items-center gap-2 text-xs font-bold text-sage-800 uppercase tracking-wider">
            <Info className="w-4 h-4" />
            <span>Similarity & Repetition Findings</span>
          </div>
          <ul className="space-y-1.5 text-xs text-[#404040]">
            {similarityData.findings.map((finding, idx) => (
              <li key={idx} className="flex items-start gap-2">
                <span className="text-sage-800 font-bold font-mono">•</span>
                <span>{finding}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {/* Flagged Repetitions Side-by-Side Review */}
      {similarityData.results.some(r => r.max_similarity_status === 'POTENTIAL_DUPLICATE' || r.max_similarity_status === 'HIGHLY_SIMILAR') && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-[#D97706]" />
              <span>Flagged Potential Duplicates (Faculty Action Required)</span>
            </CardTitle>
            <CardDescription>
              Side-by-side comparison of current questions exhibiting high semantic similarity with historical exam papers.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {similarityData.results
              .filter(r => r.max_similarity_status === 'POTENTIAL_DUPLICATE' || r.max_similarity_status === 'HIGHLY_SIMILAR')
              .map((item, idx) => {
                const qKey = item.current_question_id || idx;
                const bestMatch = item.matches[0];
                const decision = reviewedDecisions[qKey];

                return (
                  <div
                    key={qKey}
                    className={`p-4 rounded-xl border transition-colors space-y-3 ${decision === 'accepted' ? 'bg-[#F0FDF4] border-[#86EFAC]' : item.max_similarity_status === 'POTENTIAL_DUPLICATE' ? 'bg-[#FEF2F2]/50 border-[#FECACA]' : 'bg-sage-50 border-sage-200'}`}
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div className="flex items-center gap-2">
                        <span className="px-2 py-0.5 bg-sage-700 text-white rounded text-xs font-mono font-bold">
                          Q{item.current_question_number}
                        </span>
                        {getStatusBadge(item.max_similarity_status)}
                      </div>

                      <Button
                        variant={decision === 'accepted' ? 'primary' : 'outline'}
                        size="sm"
                        onClick={() => markDecision(qKey, 'accepted')}
                        className="text-xs gap-1 py-1"
                      >
                        <Check className="w-3.5 h-3.5" />
                        <span>{decision === 'accepted' ? 'Marked as Reviewed' : 'Mark as Reviewed'}</span>
                      </Button>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                      <div className="p-3 bg-white rounded-lg border border-sage-200 space-y-1.5 shadow-2xs">
                        <div className="flex items-center justify-between text-[11px] text-sage-500 font-semibold">
                          <span>CURRENT QUESTION</span>
                          {item.current_cognitive_level && (
                            <span className="font-mono">Bloom: {item.current_cognitive_level}</span>
                          )}
                        </div>
                        <p className="text-xs text-sage-800 font-medium leading-relaxed">
                          {item.current_question_text}
                        </p>
                      </div>

                      {bestMatch && (
                        <div className="p-3 bg-sage-100 rounded-lg border border-sage-200 space-y-1.5 shadow-2xs">
                          <div className="flex items-center justify-between text-[11px] text-sage-500 font-semibold">
                            <span>HISTORICAL MATCH ({(bestMatch.similarity_score * 100).toFixed(1)}%)</span>
                            <span>{bestMatch.source_year || 'Past'} {bestMatch.source_assessment || 'Exam'}</span>
                          </div>
                          <p className="text-xs text-sage-600 leading-relaxed">
                            {bestMatch.previous_question_text}
                          </p>
                        </div>
                      )}
                    </div>

                    {item.reasoning && (
                      <p className="text-[11px] text-sage-500 italic">
                        {item.reasoning}
                      </p>
                    )}
                  </div>
                );
              })}
          </CardContent>
        </Card>
      )}

      {/* Full Audit List */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Comprehensive Question Similarity Matrix</CardTitle>
          <CardDescription>
            Detailed historical cosine similarity comparisons across all examination questions.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {similarityData.results.map((item, idx) => {
            const key = item.current_question_id || idx;
            const isExpanded = !!expandedQuestions[key];
            const bestMatch = item.matches[0];

            return (
              <div key={key} className="p-4 bg-sage-50 border border-sage-200 rounded-xl space-y-2">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-start gap-2.5 flex-1">
                    <span className="px-2 py-0.5 bg-white border border-sage-200 rounded text-xs font-mono font-bold text-sage-800 shrink-0">
                      Q{item.current_question_number}
                    </span>
                    <p className="text-xs font-medium text-sage-800 leading-relaxed">
                      {item.current_question_text}
                    </p>
                  </div>
                  <div className="shrink-0 flex items-center gap-2">
                    {getStatusBadge(item.max_similarity_status)}
                    <span className="font-mono text-xs font-bold text-sage-800">
                      {(item.max_similarity_score * 100).toFixed(1)}%
                    </span>
                  </div>
                </div>

                {bestMatch && bestMatch.similarity_score >= 0.50 && (
                  <div className="p-2.5 bg-white rounded-lg text-xs flex items-center justify-between gap-2 border border-sage-200">
                    <div className="flex items-center gap-2 truncate">
                      <ArrowRight className="w-3.5 h-3.5 text-sage-500 shrink-0" />
                      <span className="text-sage-500 shrink-0 font-medium">
                        [{bestMatch.source_year || 'Past'} {bestMatch.source_assessment || 'Exam'}]:
                      </span>
                      <span className="text-sage-600 truncate">{bestMatch.previous_question_text}</span>
                    </div>
                    <span className="font-mono text-[11px] font-semibold text-sage-800 shrink-0">
                      {(bestMatch.similarity_score * 100).toFixed(1)}%
                    </span>
                  </div>
                )}

                {item.matches && item.matches.length > 1 && (
                  <div className="pt-0.5">
                    <button
                      onClick={() => toggleExpand(key)}
                      className="text-[11px] text-sage-600 hover:text-sage-800 flex items-center gap-1 font-medium transition-colors"
                    >
                      <span>{item.matches.length - 1} more candidate match{item.matches.length > 2 ? 'es' : ''}</span>
                      {isExpanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
                    </button>

                    {isExpanded && (
                      <div className="mt-2 space-y-1 pl-3 border-l-2 border-sage-200">
                        {item.matches.slice(1).map((alt, altIdx) => (
                          <div key={altIdx} className="text-[11px] text-sage-600 flex items-center justify-between gap-2">
                            <span className="truncate">
                              <strong>{alt.source_year || 'Past'}:</strong> {alt.previous_question_text}
                            </span>
                            <span className="font-mono text-sage-500 shrink-0">
                              {(alt.similarity_score * 100).toFixed(1)}%
                            </span>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </CardContent>
      </Card>
    </div>
  );
};

