import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import { mockDetailedAnalysis } from '@/utils/mockData';
import {
  Sparkles,
  AlertTriangle,
  CheckCircle2,
  CopyCheck,
} from 'lucide-react';

export const Analysis: React.FC = () => {
  const [analysis] = useState(mockDetailedAnalysis);
  const [selectedTab, setSelectedTab] = useState<'overview' | 'findings' | 'recommendations' | 'questions'>('overview');
  const [acceptedRecs, setAcceptedRecs] = useState<Record<string, boolean>>({});

  const toggleAcceptRec = (id: string) => {
    setAcceptedRecs((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  const getSeverityBadge = (severity: string) => {
    switch (severity) {
      case 'Good':
        return <Badge variant="Good" dot>Good</Badge>;
      case 'Attention':
        return <Badge variant="Attention" dot>Attention</Badge>;
      case 'Critical':
        return <Badge variant="Critical" dot>Critical</Badge>;
      default:
        return <Badge variant="neutral">Info</Badge>;
    }
  };

  return (
    <div className="space-y-6">
      {/* Demo Watermark Banner */}
      <div className="p-3.5 bg-white rounded-xl border border-[#E5E5E5] flex items-center justify-between text-xs text-[#737373] shadow-subtle">
        <div className="flex items-center gap-2">
          <Sparkles className="w-4 h-4 text-[#111111]" />
          <span>
            Viewing AI Assessment Report for <strong className="text-[#111111] font-mono">{analysis.courseCode}</strong> — <strong>{analysis.assessmentTitle}</strong>
          </span>
        </div>
        <div className="flex items-center gap-2">
          <span className="font-mono text-[11px] px-2 py-0.5 rounded bg-[#F7F7F5] border border-[#E5E5E5]">
            Mock AI Analysis Data
          </span>
          <span className="text-[11px] text-[#737373] hidden sm:inline">{analysis.analysisDate}</span>
        </div>
      </div>

      {/* Main KPI Quality Ribbon */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#737373] tracking-wider">Overall Quality</span>
              <div className="text-3xl font-extrabold text-[#111111] mt-1 font-mono">
                {analysis.metrics.overallQualityScore}%
              </div>
            </div>
            <Badge variant="Good">High Rigor</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={analysis.metrics.overallQualityScore} showValue={false} size="sm" />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#737373] tracking-wider">Topic Coverage</span>
              <div className="text-3xl font-extrabold text-[#111111] mt-1 font-mono">
                {analysis.metrics.topicCoverageScore}%
              </div>
            </div>
            <Badge variant="Good">4 / 4 Topics</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={analysis.metrics.topicCoverageScore} showValue={false} size="sm" />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#737373] tracking-wider">LO Alignment</span>
              <div className="text-3xl font-extrabold text-[#111111] mt-1 font-mono">
                {analysis.metrics.learningOutcomeScore}%
              </div>
            </div>
            <Badge variant="Attention">CLO-4 Low</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={analysis.metrics.learningOutcomeScore} showValue={false} size="sm" />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between bg-white border-[#FECACA]">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#991B1B] tracking-wider">Similar Questions</span>
              <div className="text-3xl font-extrabold text-[#DC2626] mt-1 font-mono">
                {analysis.metrics.similarQuestionsCount}
              </div>
            </div>
            <Badge variant="Critical" dot>Flagged</Badge>
          </div>
          <p className="text-[11px] text-[#991B1B] pt-2">
            2 questions share &gt;80% similarity with Spring 2025 exam
          </p>
        </Card>
      </div>

      {/* Navigation Tabs */}
      <div className="flex items-center gap-2 border-b border-[#E5E5E5] pb-2 text-xs">
        {[
          { id: 'overview', label: 'Executive Summary' },
          { id: 'findings', label: `AI Findings (${analysis.findings.length})` },
          { id: 'recommendations', label: `Recommendations (${analysis.recommendations.length})` },
          { id: 'questions', label: `Question Breakdown (${analysis.questions.length})` },
        ].map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setSelectedTab(tab.id as any)}
            className={`px-3.5 py-2 rounded-lg font-medium transition-colors ${
              selectedTab === tab.id
                ? 'bg-[#111111] text-white shadow-subtle'
                : 'text-[#737373] hover:text-[#111111] hover:bg-[#E5E5E5]'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab: Overview */}
      {selectedTab === 'overview' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {/* Left: Syllabus Topic Coverage */}
            <div className="lg:col-span-7 space-y-6">
              <Card>
                <CardHeader>
                  <CardTitle>Syllabus Topic Balance</CardTitle>
                  <CardDescription>Evaluation weight assigned per curriculum module</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  {analysis.topicCoverages.map((topic) => (
                    <div key={topic.topic} className="space-y-1.5 p-3 rounded-lg border border-[#E5E5E5] bg-[#F7F7F5]">
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-semibold text-[#111111]">{topic.topic}</span>
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-[#737373]">{topic.questionCount} Questions ({topic.weightPercent}% weight)</span>
                          {getSeverityBadge(topic.status)}
                        </div>
                      </div>
                      <ProgressBar value={topic.coveragePercent} size="sm" showValue />
                    </div>
                  ))}
                </CardContent>
              </Card>

              {/* Cognitive Taxonomy Level Distribution */}
              <Card>
                <CardHeader>
                  <CardTitle>Cognitive Complexity (Bloom&apos;s Taxonomy)</CardTitle>
                  <CardDescription>Distribution across cognitive domain levels</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  <ProgressBar
                    label="Lower Order (Remember, Understand)"
                    sublabel="Recall & Definition"
                    value={analysis.cognitiveDistribution.lowerOrderPercent}
                    size="sm"
                    statusColor={false}
                  />
                  <ProgressBar
                    label="Medium Order (Apply, Analyze)"
                    sublabel="Calculation, Queries & Proofs"
                    value={analysis.cognitiveDistribution.mediumOrderPercent}
                    size="sm"
                    statusColor={false}
                  />
                  <ProgressBar
                    label="Higher Order (Evaluate, Create)"
                    sublabel="Architecture Design & Synthesis"
                    value={analysis.cognitiveDistribution.higherOrderPercent}
                    size="sm"
                    statusColor={false}
                  />
                </CardContent>
              </Card>
            </div>

            {/* Right: Difficulty & Quick Findings */}
            <div className="lg:col-span-5 space-y-6">
              <Card>
                <CardHeader>
                  <CardTitle>Difficulty Balance</CardTitle>
                  <CardDescription>Estimated student effort distribution</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="grid grid-cols-3 gap-2 text-center">
                    <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5]">
                      <span className="text-[10px] uppercase font-semibold text-[#166534] block">Easy</span>
                      <span className="text-xl font-bold text-[#111111] font-mono">
                        {analysis.difficultyDistribution.easyPercent}%
                      </span>
                    </div>
                    <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5]">
                      <span className="text-[10px] uppercase font-semibold text-[#111111] block">Medium</span>
                      <span className="text-xl font-bold text-[#111111] font-mono">
                        {analysis.difficultyDistribution.mediumPercent}%
                      </span>
                    </div>
                    <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5]">
                      <span className="text-[10px] uppercase font-semibold text-[#991B1B] block">Hard</span>
                      <span className="text-xl font-bold text-[#111111] font-mono">
                        {analysis.difficultyDistribution.hardPercent}%
                      </span>
                    </div>
                  </div>

                  <div className="h-3.5 w-full bg-[#E5E5E5] rounded-full overflow-hidden flex shadow-inner">
                    <div className="bg-[#16A34A] h-full" style={{ width: `${analysis.difficultyDistribution.easyPercent}%` }} />
                    <div className="bg-[#111111] h-full" style={{ width: `${analysis.difficultyDistribution.mediumPercent}%` }} />
                    <div className="bg-[#DC2626] h-full" style={{ width: `${analysis.difficultyDistribution.hardPercent}%` }} />
                  </div>

                  <p className="text-xs text-[#737373] leading-relaxed pt-2">
                    Difficulty distribution adheres to the standard university recommendation of 60% average baseline difficulty with 20% distinguishing harder questions.
                  </p>
                </CardContent>
              </Card>

              {/* Assessment Summary Note */}
              <Card className="bg-[#111111] text-white">
                <CardHeader className="border-[#262626]">
                  <CardTitle className="text-white flex items-center gap-2">
                    <Sparkles className="w-4 h-4 text-white" /> AI Executive Synthesis
                  </CardTitle>
                </CardHeader>
                <CardContent className="text-xs text-[#A3A3A3] leading-relaxed space-y-3">
                  <p>{analysis.summaryNote}</p>
                  <div className="pt-2 border-t border-[#262626] flex items-center justify-between">
                    <span className="text-[11px] text-white font-medium">Faculty Action Recommended:</span>
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => setSelectedTab('recommendations')}
                    >
                      Review Suggestions
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </div>
          </div>
        </div>
      )}

      {/* Tab: Findings */}
      {selectedTab === 'findings' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>AI Key Findings</CardTitle>
              <CardDescription>Systematic analysis of assessment strengths and potential risks</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {analysis.findings.map((fnd) => (
                <div
                  key={fnd.id}
                  className="p-4 rounded-xl border border-[#E5E5E5] bg-white hover:border-[#111111] transition-colors space-y-2"
                >
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                      <h4 className="text-sm font-bold text-[#111111]">{fnd.title}</h4>
                      {fnd.relatedOutcome && (
                        <Badge variant="outline" className="font-mono text-[10px]">{fnd.relatedOutcome}</Badge>
                      )}
                    </div>
                    {getSeverityBadge(fnd.severity)}
                  </div>
                  <p className="text-xs text-[#737373] leading-relaxed">{fnd.description}</p>
                  {fnd.relatedQuestions && (
                    <div className="pt-2 flex items-center gap-2 text-[11px] text-[#DC2626] font-medium">
                      <CopyCheck className="w-3.5 h-3.5" />
                      <span>Affected Questions: #{fnd.relatedQuestions.join(', #')}</span>
                    </div>
                  )}
                </div>
              ))}
            </CardContent>
          </Card>
        </div>
      )}

      {/* Tab: Recommendations */}
      {selectedTab === 'recommendations' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Actionable AI Recommendations</CardTitle>
              <CardDescription>Faculty-in-the-loop decision suggestions to elevate assessment quality</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {analysis.recommendations.map((rec) => {
                const isAccepted = acceptedRecs[rec.id];
                return (
                  <div
                    key={rec.id}
                    className={`p-5 rounded-xl border transition-all space-y-3 ${
                      isAccepted
                        ? 'border-[#16A34A] bg-[#F0FDF4]'
                        : 'border-[#E5E5E5] bg-white hover:border-[#111111]'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <Badge variant={rec.priority === 'High' ? 'Critical' : 'Attention'} className="text-[10px]">
                            {rec.priority} Priority
                          </Badge>
                          <Badge variant="neutral" className="text-[10px]">{rec.category}</Badge>
                        </div>
                        <h4 className="text-sm font-bold text-[#111111] pt-1">{rec.title}</h4>
                      </div>

                      <Button
                        variant={isAccepted ? 'primary' : 'outline'}
                        size="sm"
                        leftIcon={isAccepted ? <CheckCircle2 className="w-3.5 h-3.5" /> : undefined}
                        onClick={() => toggleAcceptRec(rec.id)}
                      >
                        {isAccepted ? 'Marked for Action' : 'Accept Suggestion'}
                      </Button>
                    </div>

                    <p className="text-xs text-[#737373] leading-relaxed">{rec.description}</p>

                    <div className="pt-2 flex items-center gap-2 text-[11px] font-mono text-[#111111]">
                      <span className="font-semibold text-[#737373]">Action:</span>
                      <span className="px-2 py-0.5 rounded bg-[#F7F7F5] border border-[#E5E5E5]">
                        {rec.action}
                      </span>
                    </div>
                  </div>
                );
              })}
            </CardContent>
          </Card>
        </div>
      )}

      {/* Tab: Question Breakdown */}
      {selectedTab === 'questions' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Question-by-Question Audit</CardTitle>
              <CardDescription>Individual cognitive ratings, learning outcome mappings, and similarity flags</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {analysis.questions.map((q) => (
                <div
                  key={q.id}
                  className={`p-4 rounded-xl border transition-colors space-y-3 ${
                    q.similarityFlag
                      ? 'border-[#FECACA] bg-[#FEF2F2]/30'
                      : 'border-[#E5E5E5] bg-white'
                  }`}
                >
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                      <span className="w-6 h-6 rounded-md bg-[#111111] text-white flex items-center justify-center font-bold text-xs">
                        {q.questionNumber}
                      </span>
                      <span className="text-xs font-bold text-[#111111]">{q.maxMarks} Marks</span>
                      <Badge variant="outline" className="font-mono text-[10px]">{q.learningOutcomeCode}</Badge>
                      <Badge variant="neutral" className="text-[10px]">{q.difficulty}</Badge>
                      <Badge variant="neutral" className="text-[10px]">Bloom: {q.cognitiveLevel}</Badge>
                    </div>

                    <span className="text-xs text-[#737373] font-medium">{q.topic}</span>
                  </div>

                  <p className="text-xs text-[#111111] font-mono bg-[#F7F7F5] p-3 rounded-lg border border-[#E5E5E5]">
                    {q.text}
                  </p>

                  {q.similarityFlag && (
                    <div className="p-3 bg-[#FEF2F2] rounded-lg border border-[#FECACA] text-xs text-[#991B1B] space-y-1">
                      <div className="flex items-center justify-between font-semibold">
                        <span className="flex items-center gap-1.5">
                          <AlertTriangle className="w-4 h-4 text-[#DC2626]" />
                          Historical Question Similarity Detected ({q.similarityFlag.similarityScore}% match)
                        </span>
                        <span className="font-mono text-[10px]">{q.similarityFlag.matchedAssessment}</span>
                      </div>
                      <p className="text-[11px] text-[#991B1B]/90">{q.similarityFlag.notes}</p>
                    </div>
                  )}
                </div>
              ))}
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
};

