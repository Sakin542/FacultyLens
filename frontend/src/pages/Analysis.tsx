import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import { mockDetailedAnalysis } from '@/utils/mockData';
import { LearningOutcomeAlignmentSection } from '@/components/analysis/LearningOutcomeAlignmentSection';
import { SemanticSimilaritySection } from '@/components/analysis/SemanticSimilaritySection';
import { AssessmentQualitySection } from '@/components/analysis/AssessmentQualitySection';
import { RecommendationSection } from '@/components/analysis/RecommendationSection';
import {
  AlignmentAnalysisResult,
  SimilarityAnalysisResult,
  AssessmentQualityResult,
  EvidenceBasedRecommendation,
} from '@/types';
import {
  Sparkles,
  AlertTriangle,
  CopyCheck,
} from 'lucide-react';

const mockAlignmentData: AlignmentAnalysisResult = {
  status: 'success',
  method: 'sentence-transformers/all-MiniLM-L6-v2 + cosine_similarity',
  overall_alignment_score: 82.5,
  aligned_questions_count: 5,
  total_questions: 6,
  total_learning_outcomes: 5,
  covered_learning_outcomes_count: 4,
  thresholds: { strong: 0.70, weak: 0.50 },
  findings: [
    'Marginal Coverage: CLO-4 (Concurrency control) is only weakly assessed across questions.',
    'Imbalanced Assessment: 50% of questions map to CLO-2 (SQL and Querying), indicating heavy focus on querying over transaction design.',
    'Question Q5 has no strong semantic match to defined learning outcomes (similarity < 0.50).',
  ],
  learning_outcome_coverage: [
    {
      code: 'CLO-1',
      description: 'Design conceptual and logical relational data models using ER diagrams.',
      coverage_status: 'COVERED',
      matching_questions_count: 1,
      matching_question_numbers: [1],
      max_similarity: 0.88,
    },
    {
      code: 'CLO-2',
      description: 'Formulate complex queries using Relational Algebra and structured SQL.',
      coverage_status: 'COVERED',
      matching_questions_count: 3,
      matching_question_numbers: [2, 3, 6],
      max_similarity: 0.91,
    },
    {
      code: 'CLO-3',
      description: 'Evaluate schema designs and apply normalization rules (1NF to BCNF).',
      coverage_status: 'COVERED',
      matching_questions_count: 1,
      matching_question_numbers: [4],
      max_similarity: 0.84,
    },
    {
      code: 'CLO-4',
      description: 'Analyze concurrency control protocols and crash recovery algorithms.',
      coverage_status: 'WEAKLY_COVERED',
      matching_questions_count: 0,
      matching_question_numbers: [],
      max_similarity: 0.46,
    },
    {
      code: 'CLO-5',
      description: 'Implement indexing and query optimization strategies for performance tuning.',
      coverage_status: 'COVERED',
      matching_questions_count: 1,
      matching_question_numbers: [6],
      max_similarity: 0.76,
    },
  ],
  question_alignment: [
    {
      question_number: 1,
      question_text: 'Draw an Entity-Relationship (ER) diagram for a hospital management database system.',
      matched_learning_outcome: {
        code: 'CLO-1',
        description: 'Design conceptual and logical relational data models using ER diagrams.',
        similarity: 0.88,
        alignment_level: 'STRONG',
      },
      alternative_matches: [],
      alignment_status: 'STRONG',
      similarity_score: 0.88,
      reasoning: 'Strong semantic match to CLO-1 (ER diagrams and conceptual modeling).',
    },
    {
      question_number: 2,
      question_text: 'Write SQL statements to retrieve top 5 departments with highest student enrollments.',
      matched_learning_outcome: {
        code: 'CLO-2',
        description: 'Formulate complex queries using Relational Algebra and structured SQL.',
        similarity: 0.91,
        alignment_level: 'STRONG',
      },
      alternative_matches: [],
      alignment_status: 'STRONG',
      similarity_score: 0.91,
      reasoning: 'Strong semantic match to CLO-2 (SQL queries).',
    },
    {
      question_number: 3,
      question_text: 'Translate the following SQL query into equivalent relational algebra expression.',
      matched_learning_outcome: {
        code: 'CLO-2',
        description: 'Formulate complex queries using Relational Algebra and structured SQL.',
        similarity: 0.89,
        alignment_level: 'STRONG',
      },
      alternative_matches: [],
      alignment_status: 'STRONG',
      similarity_score: 0.89,
      reasoning: 'Direct alignment to relational algebra and SQL querying in CLO-2.',
    },
    {
      question_number: 4,
      question_text: 'Decompose the given table into Third Normal Form (3NF) and identify candidate keys.',
      matched_learning_outcome: {
        code: 'CLO-3',
        description: 'Evaluate schema designs and apply normalization rules (1NF to BCNF).',
        similarity: 0.84,
        alignment_level: 'STRONG',
      },
      alternative_matches: [],
      alignment_status: 'STRONG',
      similarity_score: 0.84,
      reasoning: 'Strong match to CLO-3 (database normalization rules).',
    },
    {
      question_number: 5,
      question_text: 'Explain the difference between RAID 0, RAID 1, and RAID 5 storage configurations.',
      matched_learning_outcome: {
        code: 'CLO-5',
        description: 'Implement indexing and query optimization strategies for performance tuning.',
        similarity: 0.42,
        alignment_level: 'NOT_ALIGNED',
      },
      alternative_matches: [],
      alignment_status: 'NOT_ALIGNED',
      similarity_score: 0.42,
      reasoning: 'No clear match to course LOs. Topic relates to physical hardware storage rather than core DB syllabus.',
    },
    {
      question_number: 6,
      question_text: 'Explain B+ tree indexing and how clustered indexes improve range query performance.',
      matched_learning_outcome: {
        code: 'CLO-5',
        description: 'Implement indexing and query optimization strategies for performance tuning.',
        similarity: 0.76,
        alignment_level: 'STRONG',
      },
      alternative_matches: [
        {
          code: 'CLO-2',
          description: 'Formulate complex queries using Relational Algebra and structured SQL.',
          similarity: 0.54,
          alignment_level: 'WEAK',
        },
      ],
      alignment_status: 'STRONG',
      similarity_score: 0.76,
      reasoning: 'Strong semantic match to indexing in CLO-5.',
    },
  ],
};

const mockSimilarityData: SimilarityAnalysisResult = {
  status: 'success',
  method: 'sentence-transformers/all-MiniLM-L6-v2 + cosine_similarity',
  model: 'sentence-transformers/all-MiniLM-L6-v2',
  thresholds: {
    potential_duplicate: 0.85,
    high_similarity: 0.70,
    moderate_similarity: 0.50,
  },
  total_current_questions: 6,
  total_previous_questions: 24,
  potential_duplicates_count: 1,
  highly_similar_count: 1,
  somewhat_similar_count: 2,
  average_similarity_score: 61.6,
  findings: [
    'Potential Duplicate Flagged: Question Q2 shows 92.4% semantic overlap with Midterm Spring 2025 Q3. Recommend faculty review to avoid verbatim reuse.',
    'High Similarity Detected: Question Q4 shares 78.1% cosine similarity with Final Fall 2024 Q5 on Normalization algorithms.',
    '4 out of 6 questions exhibit distinct or novel formulation (< 70% similarity with previous assessment bank).',
  ],
  results: [
    {
      current_question_number: 1,
      current_question_text: 'Draw an Entity-Relationship (ER) diagram for a hospital management database system.',
      current_question_id: 101,
      max_similarity_score: 0.64,
      max_similarity_status: 'SOMEWHAT_SIMILAR',
      matches: [
        {
          previous_question_id: 201,
          previous_question_text: 'Construct an ER model for an airline reservation database system identifying all entities and relationships.',
          source_assessment: 'Spring 2024 Midterm',
          similarity_score: 0.64,
          similarity_status: 'SOMEWHAT_SIMILAR',
          cognitive_level: 'Apply',
          question_type: 'Design',
        },
      ],
      reasoning: 'Conceptual domain varies (hospital vs airline). Good variation of ER modeling skills.',
    },
    {
      current_question_number: 2,
      current_question_text: 'Write SQL statements to retrieve top 5 departments with highest student enrollments.',
      current_question_id: 102,
      max_similarity_score: 0.924,
      max_similarity_status: 'POTENTIAL_DUPLICATE',
      matches: [
        {
          previous_question_id: 205,
          previous_question_text: 'Write an SQL query to find the top 5 departments with the highest total student enrollments.',
          source_assessment: 'Spring 2025 Midterm Exam',
          similarity_score: 0.924,
          similarity_status: 'POTENTIAL_DUPLICATE',
          cognitive_level: 'Apply',
          question_type: 'SQL',
        },
        {
          previous_question_id: 206,
          previous_question_text: 'Retrieve the top 3 highest paid instructors using SQL group by and order by clauses.',
          source_assessment: 'Fall 2024 Quiz 2',
          similarity_score: 0.68,
          similarity_status: 'SOMEWHAT_SIMILAR',
          cognitive_level: 'Apply',
          question_type: 'SQL',
        },
      ],
      reasoning: 'Near-verbatim match with Spring 2025 Midterm Exam Q3. Consider adjusting schema attributes or aggregation filters.',
    },
    {
      current_question_number: 3,
      current_question_text: 'Translate the following SQL query into equivalent relational algebra expression.',
      current_question_id: 103,
      max_similarity_score: 0.58,
      max_similarity_status: 'SOMEWHAT_SIMILAR',
      matches: [
        {
          previous_question_id: 210,
          previous_question_text: 'Convert relational algebra projection and selection into an equivalent SQL select query.',
          source_assessment: 'Fall 2024 Midterm Exam',
          similarity_score: 0.58,
          similarity_status: 'SOMEWHAT_SIMILAR',
          cognitive_level: 'Analyze',
          question_type: 'Theoretical',
        },
      ],
      reasoning: 'Moderate similarity due to shared topic (Relational Algebra/SQL conversion). Question phrasing is sufficiently unique.',
    },
    {
      current_question_number: 4,
      current_question_text: 'Decompose the given table into Third Normal Form (3NF) and identify candidate keys.',
      current_question_id: 104,
      max_similarity_score: 0.781,
      max_similarity_status: 'HIGHLY_SIMILAR',
      matches: [
        {
          previous_question_id: 215,
          previous_question_text: 'Given the relation R(A, B, C, D, E) and functional dependencies, decompose R into 3NF and verify lossless join.',
          source_assessment: 'Final Fall 2024 Exam',
          similarity_score: 0.781,
          similarity_status: 'HIGHLY_SIMILAR',
          cognitive_level: 'Analyze',
          question_type: 'Problem Solving',
        },
      ],
      reasoning: 'Similar normalization decomposition problem. Ensure candidate key sets and functional dependencies are distinct.',
    },
    {
      current_question_number: 5,
      current_question_text: 'Explain the difference between RAID 0, RAID 1, and RAID 5 storage configurations.',
      current_question_id: 105,
      max_similarity_score: 0.35,
      max_similarity_status: 'NOT_SIMILAR',
      matches: [],
      reasoning: 'Distinct question with no significant overlap found in the past question bank.',
    },
    {
      current_question_number: 6,
      current_question_text: 'Explain B+ tree indexing and how clustered indexes improve range query performance.',
      current_question_id: 106,
      max_similarity_score: 0.42,
      max_similarity_status: 'NOT_SIMILAR',
      matches: [],
      reasoning: 'Novel question formulation for storage indexing.',
    },
  ],
};

const mockQualityData: AssessmentQualityResult = {
  status: 'success',
  method: 'assessment_quality_engine',
  overall_quality_score: 84.5,
  rating: 'GOOD',
  weights_applied: {
    topic: 20,
    learning_outcome: 20,
    difficulty: 15,
    cognitive: 15,
    question_diversity: 15,
    marks: 15,
  },
  excluded_components: [],
  components: {
    topic_coverage: 87.5,
    learning_outcome_coverage: 80.0,
    difficulty_balance: 88.0,
    cognitive_diversity: 82.5,
    question_diversity: 85.0,
    marks_distribution: 84.0,
  },
  topic_analysis: {
    status: 'AVAILABLE',
    score: 87.5,
    methodology: 'Unweighted syllabus topic representation across 4 modules.',
    total_topics_defined: 4,
    covered_topics_count: 4,
    topics: [
      { topic: 'Relational Model & ER Design', question_count: 1, marks: 15, coverage_percentage: 15.0, coverage_status: 'ADEQUATE' },
      { topic: 'SQL & Relational Algebra', question_count: 2, marks: 35, coverage_percentage: 35.0, coverage_status: 'COVERED' },
      { topic: 'Schema Normalization (1NF-BCNF)', question_count: 1, marks: 20, coverage_percentage: 20.0, coverage_status: 'ADEQUATE' },
      { topic: 'Storage & Indexing Optimization', question_count: 2, marks: 30, coverage_percentage: 30.0, coverage_status: 'COVERED' },
    ],
  },
  learning_outcome_analysis: {
    status: 'AVAILABLE',
    score: 80.0,
    methodology: 'Equal-weight learning outcome representation.',
    total_los_defined: 5,
    covered_los_count: 4,
    learning_outcomes: [
      { code: 'CLO-1', description: 'Design conceptual and logical relational data models using ER diagrams.', question_count: 1, marks: 15, strongly_aligned_questions: 1, weakly_aligned_questions: 0, coverage_percentage: 15.0, coverage_status: 'ADEQUATE' },
      { code: 'CLO-2', description: 'Formulate complex queries using Relational Algebra and structured SQL.', question_count: 2, marks: 35, strongly_aligned_questions: 2, weakly_aligned_questions: 0, coverage_percentage: 35.0, coverage_status: 'COVERED' },
      { code: 'CLO-3', description: 'Evaluate schema designs and apply normalization rules (1NF to BCNF).', question_count: 1, marks: 20, strongly_aligned_questions: 1, weakly_aligned_questions: 0, coverage_percentage: 20.0, coverage_status: 'ADEQUATE' },
      { code: 'CLO-4', description: 'Analyze concurrency control protocols and crash recovery algorithms.', question_count: 0, marks: 0, strongly_aligned_questions: 0, weakly_aligned_questions: 0, coverage_percentage: 0.0, coverage_status: 'NOT_COVERED' },
      { code: 'CLO-5', description: 'Implement indexing and query optimization strategies for performance tuning.', question_count: 2, marks: 30, strongly_aligned_questions: 2, weakly_aligned_questions: 0, coverage_percentage: 30.0, coverage_status: 'COVERED' },
    ],
  },
  difficulty_analysis: {
    status: 'AVAILABLE',
    score: 88.0,
    methodology: 'Marks-weighted deviation from targets (Easy 30%, Med 50%, Hard 20%).',
    total_deviation: 24.0,
    distribution: [
      { level: 'Easy', question_count: 2, question_percentage: 33.3, marks: 25.0, marks_percentage: 25.0, target_percentage: 30.0, deviation: 5.0 },
      { level: 'Medium', question_count: 3, question_percentage: 50.0, marks: 55.0, marks_percentage: 55.0, target_percentage: 50.0, deviation: 5.0 },
      { level: 'Hard', question_count: 1, question_percentage: 16.7, marks: 20.0, marks_percentage: 20.0, target_percentage: 20.0, deviation: 0.0 },
    ],
  },
  cognitive_analysis: {
    status: 'AVAILABLE',
    score: 82.5,
    methodology: 'Normalized Shannon entropy H / ln(6) across Bloom taxonomy tiers.',
    shannon_entropy: 1.478,
    max_possible_entropy: 1.7918,
    dominant_level: 'Apply',
    dominant_percentage: 35.0,
    distribution: [
      { level: 'Remember', question_count: 1, question_percentage: 16.7, marks: 10.0, marks_percentage: 10.0 },
      { level: 'Understand', question_count: 1, question_percentage: 16.7, marks: 15.0, marks_percentage: 15.0 },
      { level: 'Apply', question_count: 2, question_percentage: 33.3, marks: 35.0, marks_percentage: 35.0 },
      { level: 'Analyze', question_count: 1, question_percentage: 16.7, marks: 20.0, marks_percentage: 20.0 },
      { level: 'Evaluate', question_count: 1, question_percentage: 16.7, marks: 20.0, marks_percentage: 20.0 },
      { level: 'Create', question_count: 0, question_percentage: 0.0, marks: 0.0, marks_percentage: 0.0 },
    ],
  },
  question_diversity_analysis: {
    status: 'AVAILABLE',
    score: 85.0,
    methodology: 'Normalized Shannon entropy over 4 distinct question formats.',
    unique_types_count: 4,
    shannon_entropy: 1.18,
    dominant_type: 'Problem Solving',
    dominant_percentage: 35.0,
    distribution: [
      { question_type: 'Descriptive', question_count: 2, question_percentage: 33.3, marks: 30.0, marks_percentage: 30.0 },
      { question_type: 'Problem Solving', question_count: 2, question_percentage: 33.3, marks: 35.0, marks_percentage: 35.0 },
      { question_type: 'Analytical', question_count: 1, question_percentage: 16.7, marks: 20.0, marks_percentage: 20.0 },
      { question_type: 'Conceptual', question_count: 1, question_percentage: 16.7, marks: 15.0, marks_percentage: 15.0 },
    ],
  },
  marks_analysis: {
    status: 'AVAILABLE',
    score: 84.0,
    methodology: 'Assessment marks summation and single-question concentration checks.',
    total_question_marks: 100.0,
    assessment_expected_marks: 100.0,
    marks_match_assessment: true,
    average_marks: 16.67,
    min_marks: 10.0,
    max_marks: 25.0,
    median_marks: 17.5,
    high_concentration_detected: false,
    highest_single_question_share: 25.0,
    highest_single_question_number: 3,
    marks_by_topic: {},
    marks_by_lo: {},
    marks_by_difficulty: {},
    marks_by_cognitive: {},
  },
  findings: [
    'Assessment Rigor: Overall quality score is 84.5% (GOOD), reflecting strong topic coverage and balanced difficulty spread.',
    'LO Coverage: CLO-4 (Concurrency Control) is not evaluated in the current assessment paper.',
    'Cognitive Spread: Well-balanced higher-order evaluation (40% of marks in Analyze and Evaluate tiers).',
    'Question Types: Diverse composition featuring Descriptive, Problem Solving, Analytical, and Conceptual items.',
  ],
};

const mockRecommendationData: EvidenceBasedRecommendation[] = [
  {
    id: 'rec_lo_101',
    category: 'learning_outcome',
    problem: 'CLO-4 (Concurrency Control) is only weakly assessed',
    explanation: 'CLO-4 lacks any strongly aligned examination questions and max semantic similarity is only 0.46.',
    evidence: {
      lo_code: 'CLO-4',
      description: 'Analyze concurrency control protocols and crash recovery algorithms.',
      aligned_questions_count: 0,
      strong_matches_count: 0,
      weak_matches_count: 0,
      max_similarity_score: 0.46,
    },
    recommendation: 'Consider adding a dedicated question on two-phase locking (2PL) or write-ahead logging (WAL) protocols to evaluate CLO-4 directly.',
    priority: 'HIGH',
    source_metric: 'Learning Outcome Alignment',
    status: 'pending',
  },
  {
    id: 'rec_sim_102',
    category: 'semantic_similarity',
    problem: 'Question #2 is potentially duplicated from Spring 2025 Midterm Exam (92.4% similarity)',
    explanation: 'Question #2 shares 92.4% semantic similarity with "Write an SQL query to find the top 5 departments with the highest total student enrollments" from Spring 2025.',
    evidence: {
      current_question_number: 2,
      current_question_text: 'Write SQL statements to retrieve top 5 departments with highest student enrollments.',
      similarity_score: 0.924,
      matched_source: 'Spring 2025 Midterm Exam Q3',
      status: 'POTENTIAL_DUPLICATE',
    },
    recommendation: 'Review the question formulation and consider modifying the schema context, grouping conditions, or aggregation criteria to preserve originality.',
    priority: 'HIGH',
    source_metric: 'Semantic Similarity Engine',
    status: 'pending',
  },
  {
    id: 'rec_cog_103',
    category: 'cognitive_level',
    problem: 'Cognitive concentration at Apply level (66.7% of marks)',
    explanation: 'Two-thirds of total marks are concentrated in Apply-tier queries and calculations, with limited representation in Analyze or Evaluate domains.',
    evidence: {
      level: 'Apply',
      percentage: 66.7,
      threshold: 60.0,
    },
    recommendation: 'Consider incorporating higher-order questions (e.g. comparing query execution plans or critiquing transaction schedules) to stimulate analytical reasoning.',
    priority: 'MEDIUM',
    source_metric: 'Bloom Cognitive Distribution',
    status: 'pending',
  },
  {
    id: 'rec_dif_104',
    category: 'difficulty',
    problem: 'Slight deviation from difficulty target profile (Medium-order skew)',
    explanation: 'The actual assessment features 65% Medium difficulty vs. target of 50%, with lower Easy foundational questions (15% vs 30%).',
    evidence: {
      actual_distribution: { easy: 15.0, medium: 65.0, hard: 20.0 },
      target_distribution: { easy: 30.0, medium: 50.0, hard: 20.0 },
      total_deviation: 30.0,
    },
    recommendation: 'Consider introducing a foundational definition or concept verification question to align closer with the intended 30% / 50% / 20% profile.',
    priority: 'MEDIUM',
    source_metric: 'Difficulty Balance Analysis',
    status: 'accepted',
    faculty_notes: 'Will replace Q1 sub-part with a 5-mark foundational ER terminology check.',
  },
  {
    id: 'rec_top_105',
    category: 'topic_coverage',
    problem: 'Topic "Transaction Recovery & Logging" is not represented in the question paper',
    explanation: 'The current assessment contains 0 questions mapped to this core database management syllabus module.',
    evidence: {
      topic_name: 'Transaction Recovery & Logging',
      question_count: 0,
      coverage_percentage: 0.0,
      status: 'NOT_COVERED',
    },
    recommendation: 'Consider adding a question evaluating checkpointing or ARIES recovery to ensure comprehensive module evaluation.',
    priority: 'MEDIUM',
    source_metric: 'Topic Coverage Analysis',
    status: 'pending',
  },
  {
    id: 'rec_div_106',
    category: 'question_diversity',
    problem: 'High reliance on Descriptive / Problem-Solving format (83.3%)',
    explanation: '5 of 6 questions are descriptive problem statements with no structured scenario or design walkthrough items.',
    evidence: {
      question_type: 'Descriptive / Problem Solving',
      percentage: 83.3,
    },
    recommendation: 'Consider blending question formats (e.g. structured case scenario or schema critique) to provide varied student evaluation modalities.',
    priority: 'LOW',
    source_metric: 'Question Format Diversity',
    status: 'dismissed',
    faculty_notes: 'Retaining descriptive format as this is a technical written midterm.',
  },
];

export const Analysis: React.FC = () => {
  const [analysis] = useState(mockDetailedAnalysis);
  const [selectedTab, setSelectedTab] = useState<'overview' | 'quality' | 'alignment' | 'similarity' | 'findings' | 'recommendations' | 'questions'>('overview');

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
      <div className="flex items-center gap-2 border-b border-[#E5E5E5] pb-2 text-xs overflow-x-auto">
        {[
          { id: 'overview', label: 'Executive Summary' },
          { id: 'quality', label: `Quality Engine (${mockQualityData.overall_quality_score}%)` },
          { id: 'alignment', label: `LO Alignment (${mockAlignmentData.covered_learning_outcomes_count}/${mockAlignmentData.total_learning_outcomes})` },
          { id: 'similarity', label: `Semantic Similarity (${mockSimilarityData.potential_duplicates_count + mockSimilarityData.highly_similar_count} flags)` },
          { id: 'findings', label: `AI Findings (${analysis.findings.length})` },
          { id: 'recommendations', label: `Recommendations (${mockRecommendationData.length})` },
          { id: 'questions', label: `Question Breakdown (${analysis.questions.length})` },
        ].map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setSelectedTab(tab.id as any)}
            className={`px-3.5 py-2 rounded-lg font-medium transition-colors shrink-0 ${
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

      {/* Tab: Quality Engine (Step 13) */}
      {selectedTab === 'quality' && (
        <AssessmentQualitySection qualityData={mockQualityData} />
      )}

      {/* Tab: LO Alignment (Step 11) */}
      {selectedTab === 'alignment' && (
        <LearningOutcomeAlignmentSection alignmentData={mockAlignmentData} />
      )}

      {/* Tab: Semantic Similarity (Step 12) */}
      {selectedTab === 'similarity' && (
        <SemanticSimilaritySection similarityData={mockSimilarityData} />
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

      {/* Tab: Recommendations (Step 14) */}
      {selectedTab === 'recommendations' && (
        <RecommendationSection recommendations={mockRecommendationData} />
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

