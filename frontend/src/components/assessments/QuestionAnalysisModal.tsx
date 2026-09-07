import React, { useState } from 'react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import {
  aiService,
  AiSingleAnalysisResponseData,
  AiBatchAnalysisResponseData,
  AnalyzedQuestionDetail,
} from '@/services/aiService';
import {
  X,
  Sparkles,
  Loader2,
  AlertCircle,
  BrainCircuit,
  Layers,
  GraduationCap,
  Target,
  BarChart3,
  HelpCircle,
} from 'lucide-react';

interface QuestionAnalysisModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessmentId?: string | number;
  assessmentTitle?: string;
  defaultTopics?: string[];
  onAnalysisCompleted?: () => void;
}

export const QuestionAnalysisModal: React.FC<QuestionAnalysisModalProps> = ({
  isOpen,
  onClose,
  assessmentId,
  assessmentTitle,
  defaultTopics = [],
  onAnalysisCompleted,
}) => {
  const [activeTab, setActiveTab] = useState<'assessment' | 'tester'>(assessmentId ? 'assessment' : 'tester');

  // Interactive Single Tester State
  const [testQuestion, setTestQuestion] = useState(
    'Explain the differences between 3NF and BCNF in relational database design with suitable examples.'
  );
  const [topicsInput, setTopicsInput] = useState(defaultTopics.join(', ') || 'Relational Design, Normalization, SQL, Transactions, Indexing');
  const [isAnalyzingSingle, setIsAnalyzingSingle] = useState(false);
  const [singleResult, setSingleResult] = useState<AiSingleAnalysisResponseData | null>(null);
  const [singleError, setSingleError] = useState<string | null>(null);

  // Batch Assessment Analysis State
  const [isAnalyzingBatch, setIsAnalyzingBatch] = useState(false);
  const [batchResult, setBatchResult] = useState<AiBatchAnalysisResponseData | null>(null);
  const [batchError, setBatchError] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleAnalyzeSingle = async () => {
    if (!testQuestion.trim()) {
      setSingleError('Please enter a question to analyze.');
      return;
    }

    try {
      setIsAnalyzingSingle(true);
      setSingleError(null);

      const parsedTopics = topicsInput
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean);

      const res = await aiService.analyzeQuestion({
        question: testQuestion,
        course_topics: parsedTopics.length > 0 ? parsedTopics : undefined,
      });

      setSingleResult(res.data);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setSingleError(err.message);
      } else {
        setSingleError('Failed to analyze question with Hugging Face service.');
      }
    } finally {
      setIsAnalyzingSingle(false);
    }
  };

  const handleAnalyzeAssessment = async () => {
    if (!assessmentId) return;

    try {
      setIsAnalyzingBatch(true);
      setBatchError(null);

      const parsedTopics = topicsInput
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean);

      const res = await aiService.analyzeAssessmentQuestions(assessmentId, {
        course_topics: parsedTopics.length > 0 ? parsedTopics : undefined,
      });

      setBatchResult(res.data);
      if (onAnalysisCompleted) {
        onAnalysisCompleted();
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        setBatchError(err.message);
      } else {
        setBatchError('Failed to run AI analysis on assessment questions.');
      }
    } finally {
      setIsAnalyzingBatch(false);
    }
  };

  const getDifficultyBadge = (level: string) => {
    switch (level?.toUpperCase()) {
      case 'EASY':
        return <Badge variant="Good">Easy</Badge>;
      case 'MEDIUM':
        return <Badge variant="Attention">Medium</Badge>;
      case 'HARD':
        return <Badge variant="Critical">Hard</Badge>;
      default:
        return <Badge variant="neutral">{level || 'Medium'}</Badge>;
    }
  };

  const getBloomBadge = (level: string) => {
    const l = level?.toUpperCase();
    let variant: 'neutral' | 'outline' | 'Good' | 'Attention' | 'Critical' = 'outline';
    if (['REMEMBER', 'UNDERSTAND'].includes(l)) variant = 'neutral';
    else if (['APPLY', 'ANALYZE'].includes(l)) variant = 'Attention';
    else if (['EVALUATE', 'CREATE'].includes(l)) variant = 'Critical';

    return <Badge variant={variant}>Bloom: {level}</Badge>;
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in">
      <div className="bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl bg-[#111111] dark:bg-white text-white dark:text-[#111111] flex items-center justify-center">
              <Sparkles className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-bold text-[#111111] dark:text-white">
                Hugging Face AI Question Analysis
              </h3>
              <p className="text-xs text-[#737373]">
                {assessmentTitle ? `Evaluating: ${assessmentTitle}` : 'Intelligent classification, Bloom taxonomy, difficulty & topic detection'}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-lg text-[#737373] hover:text-[#111111] dark:hover:text-white hover:bg-[#F7F7F5] dark:hover:bg-[#2C2C2E] transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Tab Selection */}
        <div className="flex items-center gap-2 px-6 pt-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E] text-xs">
          {assessmentId && (
            <button
              type="button"
              onClick={() => setActiveTab('assessment')}
              className={`px-4 py-2 font-semibold border-b-2 transition-colors ${
                activeTab === 'assessment'
                  ? 'border-[#111111] dark:border-white text-[#111111] dark:text-white'
                  : 'border-transparent text-[#737373] hover:text-[#111111] dark:hover:text-white'
              }`}
            >
              Assessment Analysis
            </button>
          )}
          <button
            type="button"
            onClick={() => setActiveTab('tester')}
            className={`px-4 py-2 font-semibold border-b-2 transition-colors ${
              activeTab === 'tester'
                ? 'border-[#111111] dark:border-white text-[#111111] dark:text-white'
                : 'border-transparent text-[#737373] hover:text-[#111111] dark:hover:text-white'
            }`}
          >
            Interactive Question Sandbox
          </button>
        </div>

        {/* Body Content */}
        <div className="p-6 overflow-y-auto space-y-6 flex-1">
          {/* Assessment Batch Analysis Tab */}
          {activeTab === 'assessment' && assessmentId && (
            <div className="space-y-5">
              <div className="p-4 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="space-y-1">
                  <h4 className="text-sm font-bold text-[#111111] dark:text-white flex items-center gap-2">
                    <BrainCircuit className="w-4 h-4" /> Run Full AI Analysis on Assessment
                  </h4>
                  <p className="text-xs text-[#737373]">
                    Analyzes all questions using Hugging Face NLP, tags Bloom&apos;s levels &amp; difficulty, and saves predictions into the database.
                  </p>
                </div>
                <Button
                  variant="primary"
                  size="sm"
                  leftIcon={isAnalyzingBatch ? <Loader2 className="w-4 h-4 animate-spin" /> : <Sparkles className="w-4 h-4" />}
                  onClick={handleAnalyzeAssessment}
                  disabled={isAnalyzingBatch}
                >
                  {isAnalyzingBatch ? 'Analyzing Questions...' : 'Run AI Analysis'}
                </Button>
              </div>

              {batchError && (
                <div className="p-3.5 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl text-red-700 dark:text-red-300 text-xs flex items-center gap-2 font-medium">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{batchError}</span>
                </div>
              )}

              {batchResult && (
                <div className="space-y-6 animate-in fade-in">
                  {/* Summary Metric Ribbon */}
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="p-4 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] space-y-1">
                      <span className="text-[10px] uppercase font-semibold text-[#737373] flex items-center gap-1">
                        <Layers className="w-3.5 h-3.5" /> Total Questions Analyzed
                      </span>
                      <span className="text-2xl font-bold font-mono text-[#111111] dark:text-white">
                        {batchResult.total_questions}
                      </span>
                    </div>

                    <div className="p-4 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] space-y-1">
                      <span className="text-[10px] uppercase font-semibold text-[#737373] flex items-center gap-1">
                        <Target className="w-3.5 h-3.5" /> Difficulty Spread
                      </span>
                      <div className="flex items-center gap-2 pt-1">
                        {Object.entries(batchResult.summary?.difficulty_distribution || {}).map(([diff, count]) => (
                          <span key={diff} className="text-xs font-mono font-semibold px-2 py-0.5 rounded bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C]">
                            {diff}: {count}
                          </span>
                        ))}
                      </div>
                    </div>

                    <div className="p-4 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] space-y-1">
                      <span className="text-[10px] uppercase font-semibold text-[#737373] flex items-center gap-1">
                        <GraduationCap className="w-3.5 h-3.5" /> Top Bloom Levels
                      </span>
                      <div className="flex flex-wrap items-center gap-1.5 pt-1">
                        {Object.entries(batchResult.summary?.cognitive_distribution || {}).slice(0, 3).map(([cog, count]) => (
                          <Badge key={cog} variant="outline" className="text-[10px]">
                            {cog} ({count})
                          </Badge>
                        ))}
                      </div>
                    </div>
                  </div>

                  {/* Question Breakdown List */}
                  <div className="space-y-3">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-[#737373] flex items-center gap-1.5">
                      <BarChart3 className="w-4 h-4" /> Analyzed Questions Detail
                    </h4>

                    <div className="space-y-3">
                      {batchResult.questions.map((q: AnalyzedQuestionDetail, idx: number) => (
                        <div
                          key={idx}
                          className="p-4 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-3"
                        >
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2 flex-wrap">
                              <span className="font-mono font-bold text-xs text-[#111111] dark:text-white">
                                Q{q.number || idx + 1}
                              </span>
                              <Badge variant="neutral" className="text-[10px] uppercase">
                                {q.classification?.question_type}
                              </Badge>
                              {getDifficultyBadge(q.difficulty?.level)}
                              {getBloomBadge(q.cognitive_level?.level)}
                            </div>

                            {q.topics && q.topics.length > 0 && (
                              <div className="flex items-center gap-1 text-[11px] text-[#737373]">
                                <span className="font-medium">Topic:</span>
                                <span className="font-semibold text-[#111111] dark:text-white">
                                  {q.topics[0].name} ({(q.topics[0].confidence * 100).toFixed(0)}%)
                                </span>
                              </div>
                            )}
                          </div>

                          <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed">
                            {q.question}
                          </p>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Interactive Question Sandbox Tab */}
          {activeTab === 'tester' && (
            <div className="space-y-5">
              <div className="space-y-3">
                <div className="space-y-1">
                  <label className="text-xs font-bold text-[#111111] dark:text-white">
                    Question Text
                  </label>
                  <textarea
                    rows={3}
                    value={testQuestion}
                    onChange={(e) => setTestQuestion(e.target.value)}
                    placeholder="Enter academic question to test NLP model..."
                    className="w-full rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3.5 py-2.5 text-xs text-[#111111] dark:text-white placeholder-[#737373] focus:outline-none focus:ring-2 focus:ring-[#111111]"
                  />
                </div>

                <div className="space-y-1">
                  <label className="text-xs font-bold text-[#111111] dark:text-white">
                    Course Topics / Syllabus Context (comma-separated)
                  </label>
                  <input
                    type="text"
                    value={topicsInput}
                    onChange={(e) => setTopicsInput(e.target.value)}
                    placeholder="e.g. Relational Design, Normalization, SQL, Transactions"
                    className="w-full rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-xs text-[#111111] dark:text-white placeholder-[#737373] focus:outline-none focus:ring-2 focus:ring-[#111111]"
                  />
                </div>

                <Button
                  variant="primary"
                  size="sm"
                  leftIcon={isAnalyzingSingle ? <Loader2 className="w-4 h-4 animate-spin" /> : <Sparkles className="w-4 h-4" />}
                  onClick={handleAnalyzeSingle}
                  disabled={isAnalyzingSingle}
                >
                  {isAnalyzingSingle ? 'Analyzing...' : 'Analyze Single Question'}
                </Button>
              </div>

              {singleError && (
                <div className="p-3.5 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl text-red-700 dark:text-red-300 text-xs flex items-center gap-2 font-medium">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{singleError}</span>
                </div>
              )}

              {singleResult && (
                <div className="p-5 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-4 animate-in fade-in">
                  <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#3A3A3C]">
                    <span className="text-xs font-bold uppercase tracking-wider text-[#737373]">
                      AI Prediction Results
                    </span>
                    <Badge variant="Good" dot>Model Inferred</Badge>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="p-3 bg-white dark:bg-[#1C1C1E] rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-1">
                      <span className="text-[10px] uppercase font-semibold text-[#737373]">Question Type</span>
                      <div className="flex items-center gap-1.5">
                        <span className="font-bold text-sm text-[#111111] dark:text-white">
                          {singleResult.classification?.question_type}
                        </span>
                        <span className="text-[10px] text-[#737373] font-mono">
                          ({((singleResult.classification?.confidence || 0) * 100).toFixed(0)}%)
                        </span>
                      </div>
                    </div>

                    <div className="p-3 bg-white dark:bg-[#1C1C1E] rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-1">
                      <span className="text-[10px] uppercase font-semibold text-[#737373]">Difficulty Level</span>
                      <div>{getDifficultyBadge(singleResult.difficulty?.level)}</div>
                    </div>

                    <div className="p-3 bg-white dark:bg-[#1C1C1E] rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-1">
                      <span className="text-[10px] uppercase font-semibold text-[#737373]">Cognitive Level</span>
                      <div>{getBloomBadge(singleResult.cognitive_level?.level)}</div>
                    </div>
                  </div>

                  {/* Topic Semantic Matches */}
                  {singleResult.topics && singleResult.topics.length > 0 && (
                    <div className="space-y-2 pt-2">
                      <span className="text-xs font-bold text-[#111111] dark:text-white flex items-center gap-1.5">
                        <Target className="w-3.5 h-3.5" /> Matched Syllabus Topics (Cosine Similarity)
                      </span>
                      <div className="flex flex-wrap gap-2">
                        {singleResult.topics.map((t: { name: string; confidence: number }, idx: number) => (
                          <div
                            key={idx}
                            className="px-3 py-1 bg-white dark:bg-[#1C1C1E] rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] text-xs flex items-center gap-2"
                          >
                            <span className="font-medium text-[#111111] dark:text-white">{t.name}</span>
                            <span className="font-mono text-[10px] text-emerald-600 dark:text-emerald-400 font-bold">
                              {(t.confidence * 100).toFixed(1)}%
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-3 border-t border-[#E5E5E5] dark:border-[#2C2C2E] flex items-center justify-between bg-[#FAFAFA] dark:bg-[#1C1C1E] text-xs text-[#737373]">
          <div className="flex items-center gap-1.5">
            <HelpCircle className="w-3.5 h-3.5" />
            <span>FastAPI + sentence-transformers/all-MiniLM-L6-v2 pipeline</span>
          </div>
          <Button variant="outline" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};

