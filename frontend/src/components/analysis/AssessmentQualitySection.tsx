import React, { useState } from 'react';
import { Card, CardContent } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import { Button } from '@/components/common/Button';
import {
  Sparkles,
  BookOpen,
  Target,
  BarChart3,
  BrainCircuit,
  Layers,
  Info,
  Percent,
} from 'lucide-react';
import { AssessmentQualityResult, QualityRating } from '@/types';

interface AssessmentQualitySectionProps {
  qualityData?: AssessmentQualityResult | null;
  onTriggerAnalysis?: () => void;
  isLoading?: boolean;
}

export const AssessmentQualitySection: React.FC<AssessmentQualitySectionProps> = ({
  qualityData,
  onTriggerAnalysis,
  isLoading = false,
}) => {
  const [activeDimensionTab, setActiveDimensionTab] = useState<
    'topics' | 'los' | 'difficulty' | 'cognitive' | 'questions' | 'marks'
  >('topics');

  const getRatingBadge = (rating: QualityRating) => {
    switch (rating) {
      case 'EXCELLENT':
        return <Badge variant="Good" dot>Excellent Quality</Badge>;
      case 'GOOD':
        return <Badge variant="Good" dot>Good Quality</Badge>;
      case 'FAIR':
        return <Badge variant="neutral" dot>Fair Quality</Badge>;
      case 'NEEDS_REVIEW':
        return <Badge variant="Attention" dot>Needs Review</Badge>;
      case 'REQUIRES_ATTENTION':
        return <Badge variant="Critical" dot>Requires Attention</Badge>;
      default:
        return <Badge variant="neutral">Unavailable</Badge>;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'COVERED':
        return <Badge variant="Good">Covered</Badge>;
      case 'ADEQUATE':
        return <Badge variant="Good">Adequate</Badge>;
      case 'WEAK':
      case 'LOW':
        return <Badge variant="Attention">Marginal</Badge>;
      case 'NOT_COVERED':
        return <Badge variant="Critical">Not Covered</Badge>;
      default:
        return <Badge variant="neutral">{status}</Badge>;
    }
  };

  if (!qualityData) {
    return (
      <Card className="p-6 text-center space-y-3">
        <div className="w-12 h-12 rounded-2xl bg-sage-100 border border-sage-200 flex items-center justify-center mx-auto text-sage-500">
          <BarChart3 className="w-6 h-6" />
        </div>
        <div>
          <h3 className="text-sm font-bold text-sage-800">No Assessment Quality Audit Generated</h3>
          <p className="text-xs text-sage-500 mt-1 max-w-md mx-auto">
            Evaluate exam rigor across Topic Representation, Learning Outcome Coverage, Difficulty Balance, Bloom's Cognitive Diversity, Question Formats, and Marks Integrity.
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
            <span>Run Quality Engine</span>
          </Button>
        )}
      </Card>
    );
  }

  const {
    overall_quality_score,
    rating,
    components,
    topic_analysis,
    learning_outcome_analysis,
    difficulty_analysis,
    cognitive_analysis,
    question_diversity_analysis,
    marks_analysis,
    findings,
    weights_applied,
    excluded_components,
  } = qualityData;

  return (
    <div className="space-y-6">
      {/* Hero Overall Quality Card */}
      <Card className="p-6 bg-gradient-to-r from-white via-[#FBFBFA] to-sage-100 border-sage-200 shadow-subtle">
        <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
          <div className="space-y-2 flex-1">
            <div className="flex items-center gap-2">
              <span className="text-xs font-bold uppercase tracking-wider text-sage-500">Holistic Assessment Rigor</span>
              {getRatingBadge(rating)}
            </div>
            <div className="flex items-baseline gap-3">
              <div className="text-4xl sm:text-5xl font-extrabold text-sage-800 font-mono tracking-tight">
                {overall_quality_score !== null ? `${overall_quality_score}%` : 'N/A'}
              </div>
              <span className="text-xs text-sage-500">
                Weighted combination of 6 pedagogical quality dimensions
              </span>
            </div>
            <div className="pt-2 flex flex-wrap items-center gap-3 text-[11px] text-sage-600">
              <span className="font-semibold text-sage-800">Weights Applied:</span>
              {Object.entries(weights_applied || {}).map(([dim, w]) => (
                <span key={dim} className="px-2 py-0.5 rounded bg-white border border-sage-200 font-mono">
                  {dim.replace('_', ' ')}: {w}%
                </span>
              ))}
              {excluded_components && excluded_components.length > 0 && (
                <span className="text-[#DC2626] font-medium">
                  Excluded: {excluded_components.join(', ')}
                </span>
              )}
            </div>
          </div>

          <div className="p-4 bg-white rounded-xl border border-sage-200 text-xs text-sage-500 max-w-xs space-y-1.5 shadow-xs">
            <div className="flex items-center gap-1.5 text-sage-800 font-semibold">
              <Info className="w-3.5 h-3.5" />
              <span>Decision Support Notice</span>
            </div>
            <p className="text-[11px] leading-relaxed">
              Scores reflect structural and cognitive balance metrics according to configured weights. Faculty review is essential for final academic assessment decisions.
            </p>
          </div>
        </div>
      </Card>

      {/* Six Component Quality Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {/* 1. Topic Coverage */}
        <Card
          className={`p-5 flex flex-col justify-between cursor-pointer transition-all ${
            activeDimensionTab === 'topics' ? 'ring-2 ring-sage-600 bg-white' : 'hover:border-sage-700'
          }`}
          onClick={() => setActiveDimensionTab('topics')}
        >
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 text-xs uppercase font-semibold text-sage-500">
                <BookOpen className="w-3.5 h-3.5 text-sage-800" />
                <span>Topic Coverage</span>
              </div>
              <div className="text-3xl font-extrabold text-sage-800 font-mono">
                {components.topic_coverage !== null && components.topic_coverage !== undefined ? `${components.topic_coverage}%` : 'N/A'}
              </div>
            </div>
            <Badge variant={topic_analysis.status === 'AVAILABLE' ? 'Good' : 'neutral'}>
              {topic_analysis.status === 'AVAILABLE'
                ? `${topic_analysis.covered_topics_count}/${topic_analysis.total_topics_defined} Topics`
                : 'Unavailable'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={components.topic_coverage || 0} showValue={false} size="sm" />
          </div>
        </Card>

        {/* 2. LO Coverage */}
        <Card
          className={`p-5 flex flex-col justify-between cursor-pointer transition-all ${
            activeDimensionTab === 'los' ? 'ring-2 ring-sage-600 bg-white' : 'hover:border-sage-700'
          }`}
          onClick={() => setActiveDimensionTab('los')}
        >
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 text-xs uppercase font-semibold text-sage-500">
                <Target className="w-3.5 h-3.5 text-sage-800" />
                <span>LO Coverage</span>
              </div>
              <div className="text-3xl font-extrabold text-sage-800 font-mono">
                {components.learning_outcome_coverage !== null && components.learning_outcome_coverage !== undefined ? `${components.learning_outcome_coverage}%` : 'N/A'}
              </div>
            </div>
            <Badge variant={learning_outcome_analysis.status === 'AVAILABLE' ? 'Good' : 'neutral'}>
              {learning_outcome_analysis.status === 'AVAILABLE'
                ? `${learning_outcome_analysis.covered_los_count}/${learning_outcome_analysis.total_los_defined} LOs`
                : 'Unavailable'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={components.learning_outcome_coverage || 0} showValue={false} size="sm" />
          </div>
        </Card>

        {/* 3. Difficulty Balance */}
        <Card
          className={`p-5 flex flex-col justify-between cursor-pointer transition-all ${
            activeDimensionTab === 'difficulty' ? 'ring-2 ring-sage-600 bg-white' : 'hover:border-sage-700'
          }`}
          onClick={() => setActiveDimensionTab('difficulty')}
        >
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 text-xs uppercase font-semibold text-sage-500">
                <BarChart3 className="w-3.5 h-3.5 text-sage-800" />
                <span>Difficulty Balance</span>
              </div>
              <div className="text-3xl font-extrabold text-sage-800 font-mono">
                {components.difficulty_balance !== null && components.difficulty_balance !== undefined ? `${components.difficulty_balance}%` : 'N/A'}
              </div>
            </div>
            <Badge variant={difficulty_analysis.status === 'AVAILABLE' ? 'Good' : 'neutral'}>
              {difficulty_analysis.status === 'AVAILABLE'
                ? `Dev: ${difficulty_analysis.total_deviation}%`
                : 'Unavailable'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={components.difficulty_balance || 0} showValue={false} size="sm" />
          </div>
        </Card>

        {/* 4. Cognitive Diversity */}
        <Card
          className={`p-5 flex flex-col justify-between cursor-pointer transition-all ${
            activeDimensionTab === 'cognitive' ? 'ring-2 ring-sage-600 bg-white' : 'hover:border-sage-700'
          }`}
          onClick={() => setActiveDimensionTab('cognitive')}
        >
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 text-xs uppercase font-semibold text-sage-500">
                <BrainCircuit className="w-3.5 h-3.5 text-sage-800" />
                <span>Cognitive Diversity</span>
              </div>
              <div className="text-3xl font-extrabold text-sage-800 font-mono">
                {components.cognitive_diversity !== null && components.cognitive_diversity !== undefined ? `${components.cognitive_diversity}%` : 'N/A'}
              </div>
            </div>
            <Badge variant="neutral">
              {cognitive_analysis.dominant_level ? `${cognitive_analysis.dominant_level} Dom.` : 'Bloom Tiers'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={components.cognitive_diversity || 0} showValue={false} size="sm" />
          </div>
        </Card>

        {/* 5. Question Diversity */}
        <Card
          className={`p-5 flex flex-col justify-between cursor-pointer transition-all ${
            activeDimensionTab === 'questions' ? 'ring-2 ring-sage-600 bg-white' : 'hover:border-sage-700'
          }`}
          onClick={() => setActiveDimensionTab('questions')}
        >
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 text-xs uppercase font-semibold text-sage-500">
                <Layers className="w-3.5 h-3.5 text-sage-800" />
                <span>Question Diversity</span>
              </div>
              <div className="text-3xl font-extrabold text-sage-800 font-mono">
                {components.question_diversity !== null && components.question_diversity !== undefined ? `${components.question_diversity}%` : 'N/A'}
              </div>
            </div>
            <Badge variant="neutral">
              {question_diversity_analysis.unique_types_count} Format(s)
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={components.question_diversity || 0} showValue={false} size="sm" />
          </div>
        </Card>

        {/* 6. Marks Distribution */}
        <Card
          className={`p-5 flex flex-col justify-between cursor-pointer transition-all ${
            activeDimensionTab === 'marks' ? 'ring-2 ring-sage-600 bg-white' : 'hover:border-sage-700'
          }`}
          onClick={() => setActiveDimensionTab('marks')}
        >
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 text-xs uppercase font-semibold text-sage-500">
                <Percent className="w-3.5 h-3.5 text-sage-800" />
                <span>Marks Distribution</span>
              </div>
              <div className="text-3xl font-extrabold text-sage-800 font-mono">
                {components.marks_distribution !== null && components.marks_distribution !== undefined ? `${components.marks_distribution}%` : 'N/A'}
              </div>
            </div>
            <Badge variant={marks_analysis.marks_match_assessment ? 'Good' : 'Critical'}>
              {marks_analysis.marks_match_assessment ? 'Sum Match' : 'Sum Mismatch'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={components.marks_distribution || 0} showValue={false} size="sm" />
          </div>
        </Card>
      </div>

      {/* Dimension Sub-Analysis Tabs */}
      <Card>
        <div className="border-b border-sage-200 px-6 pt-4 flex items-center gap-2 overflow-x-auto text-xs">
          {[
            { id: 'topics', label: 'Topic Breakdown', count: topic_analysis.topics.length },
            { id: 'los', label: 'Learning Outcomes', count: learning_outcome_analysis.learning_outcomes.length },
            { id: 'difficulty', label: 'Difficulty Spread' },
            { id: 'cognitive', label: 'Bloom Cognitive Tiers' },
            { id: 'questions', label: 'Question Formats' },
            { id: 'marks', label: 'Marks & Summary' },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveDimensionTab(tab.id as any)}
              className={`px-3.5 py-2.5 font-medium border-b-2 -mb-[1px] transition-colors whitespace-nowrap ${
                activeDimensionTab === tab.id
                  ? 'border-sage-700 text-sage-800 font-bold'
                  : 'border-transparent text-sage-500 hover:text-sage-800'
              }`}
            >
              {tab.label} {tab.count !== undefined ? `(${tab.count})` : ''}
            </button>
          ))}
        </div>

        <CardContent className="p-6">
          {/* Tab: Topic Breakdown */}
          {activeDimensionTab === 'topics' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between text-xs text-sage-500">
                <span>Methodology: {topic_analysis.methodology}</span>
                <span>Score: <strong className="text-sage-800 font-mono">{topic_analysis.score}%</strong></span>
              </div>
              {topic_analysis.topics.length === 0 ? (
                <p className="text-xs text-sage-500 italic">No course syllabus topics recorded for this course.</p>
              ) : (
                <div className="space-y-2.5">
                  {topic_analysis.topics.map((t) => (
                    <div
                      key={t.topic}
                      className="p-3.5 rounded-xl border border-sage-200 bg-sage-100 flex items-center justify-between gap-4 text-xs"
                    >
                      <div className="space-y-1">
                        <span className="font-semibold text-sage-800">{t.topic}</span>
                        <div className="text-[11px] text-sage-500">
                          {t.question_count} Question(s) • {t.marks} Marks
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="font-mono text-xs font-bold text-sage-800">{t.coverage_percentage}%</span>
                        {getStatusBadge(t.coverage_status)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Tab: Learning Outcomes */}
          {activeDimensionTab === 'los' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between text-xs text-sage-500">
                <span>Methodology: {learning_outcome_analysis.methodology}</span>
                <span>Score: <strong className="text-sage-800 font-mono">{learning_outcome_analysis.score}%</strong></span>
              </div>
              {learning_outcome_analysis.learning_outcomes.length === 0 ? (
                <p className="text-xs text-sage-500 italic">No learning outcomes configured for this course.</p>
              ) : (
                <div className="space-y-2.5">
                  {learning_outcome_analysis.learning_outcomes.map((lo) => (
                    <div
                      key={lo.code}
                      className="p-3.5 rounded-xl border border-sage-200 bg-sage-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs"
                    >
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <span className="px-2 py-0.5 rounded bg-white border border-sage-200 font-mono font-bold text-[11px]">
                            {lo.code}
                          </span>
                          <span className="text-sage-800 font-medium">{lo.description}</span>
                        </div>
                        <div className="text-[11px] text-sage-500">
                          {lo.question_count} Question(s) • {lo.marks} Marks
                        </div>
                      </div>
                      <div className="flex items-center gap-3 self-end sm:self-auto">
                        <span className="font-mono text-xs font-bold text-sage-800">{lo.coverage_percentage}%</span>
                        {getStatusBadge(lo.coverage_status)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Tab: Difficulty Spread */}
          {activeDimensionTab === 'difficulty' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between text-xs text-sage-500">
                <span>Methodology: {difficulty_analysis.methodology}</span>
                <span>Score: <strong className="text-sage-800 font-mono">{difficulty_analysis.score}%</strong></span>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {difficulty_analysis.distribution.map((d) => (
                  <div key={d.level} className="p-4 rounded-xl border border-sage-200 bg-sage-100 space-y-2 text-xs">
                    <div className="flex items-center justify-between">
                      <span className="font-bold text-sage-800">{d.level}</span>
                      <span className="text-[11px] text-sage-500">Target: {d.target_percentage}%</span>
                    </div>
                    <div className="text-2xl font-extrabold font-mono text-sage-800">
                      {d.marks_percentage}%
                    </div>
                    <div className="text-[11px] text-sage-500">
                      {d.marks} Marks ({d.question_count} Questions)
                    </div>
                    <div className="text-[10px] text-sage-500">
                      Deviation: <span className="font-mono text-sage-800 font-semibold">{d.deviation}%</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Tab: Bloom Cognitive Tiers */}
          {activeDimensionTab === 'cognitive' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between text-xs text-sage-500">
                <span>Entropy: {cognitive_analysis.shannon_entropy} / {cognitive_analysis.max_possible_entropy}</span>
                <span>Score: <strong className="text-sage-800 font-mono">{cognitive_analysis.score}%</strong></span>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                {cognitive_analysis.distribution.map((cog) => (
                  <div key={cog.level} className="p-3 rounded-xl border border-sage-200 bg-sage-100 space-y-1.5 text-xs text-center">
                    <span className="font-semibold text-sage-800 block">{cog.level}</span>
                    <div className="text-lg font-bold font-mono text-sage-800">
                      {cog.marks_percentage}%
                    </div>
                    <div className="text-[10px] text-sage-500">
                      {cog.marks} M ({cog.question_count} Q)
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Tab: Question Formats */}
          {activeDimensionTab === 'questions' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between text-xs text-sage-500">
                <span>{question_diversity_analysis.unique_types_count} Format(s) evaluated</span>
                <span>Score: <strong className="text-sage-800 font-mono">{question_diversity_analysis.score}%</strong></span>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                {question_diversity_analysis.distribution.map((qType) => (
                  <div key={qType.question_type} className="p-3.5 rounded-xl border border-sage-200 bg-sage-100 flex items-center justify-between gap-3 text-xs">
                    <div>
                      <span className="font-semibold text-sage-800 block">{qType.question_type}</span>
                      <span className="text-[11px] text-sage-500">{qType.question_count} Questions • {qType.marks} Marks</span>
                    </div>
                    <span className="text-sm font-bold font-mono text-sage-800">{qType.marks_percentage}%</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Tab: Marks & Summary */}
          {activeDimensionTab === 'marks' && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div className="p-3 bg-sage-100 rounded-xl border border-sage-200 text-center">
                  <span className="text-[10px] uppercase text-sage-500 font-semibold">Total Marks</span>
                  <div className="text-lg font-bold font-mono text-sage-800">{marks_analysis.total_question_marks}</div>
                </div>
                <div className="p-3 bg-sage-100 rounded-xl border border-sage-200 text-center">
                  <span className="text-[10px] uppercase text-sage-500 font-semibold">Average</span>
                  <div className="text-lg font-bold font-mono text-sage-800">{marks_analysis.average_marks}</div>
                </div>
                <div className="p-3 bg-sage-100 rounded-xl border border-sage-200 text-center">
                  <span className="text-[10px] uppercase text-sage-500 font-semibold">Median</span>
                  <div className="text-lg font-bold font-mono text-sage-800">{marks_analysis.median_marks}</div>
                </div>
                <div className="p-3 bg-sage-100 rounded-xl border border-sage-200 text-center">
                  <span className="text-[10px] uppercase text-sage-500 font-semibold">Min Mark</span>
                  <div className="text-lg font-bold font-mono text-sage-800">{marks_analysis.min_marks}</div>
                </div>
                <div className="p-3 bg-sage-100 rounded-xl border border-sage-200 text-center">
                  <span className="text-[10px] uppercase text-sage-500 font-semibold">Max Mark</span>
                  <div className="text-lg font-bold font-mono text-sage-800">{marks_analysis.max_marks}</div>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Quality Findings */}
      {findings && findings.length > 0 && (
        <Card className="p-5 bg-sage-100 border border-sage-200 space-y-3">
          <div className="flex items-center gap-2 text-xs font-bold text-sage-800 uppercase tracking-wider">
            <Info className="w-4 h-4 text-sage-800" />
            <span>Assessment Quality Observations</span>
          </div>
          <ul className="space-y-2 text-xs text-[#404040]">
            {findings.map((fnd, idx) => (
              <li key={idx} className="flex items-start gap-2.5">
                <span className="text-sage-800 font-bold font-mono text-sm leading-none">•</span>
                <span>{fnd}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
};
