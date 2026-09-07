export type UserRole = 'faculty' | 'department_head' | 'dean' | 'admin';

export interface User {
  id: number | string;
  name: string;
  fullName?: string;
  email: string;
  department: string;
  designation: string;
  role?: UserRole;
  avatarUrl?: string;
  createdAt?: string;
}

export type CognitiveLevel = 'Remember' | 'Understand' | 'Apply' | 'Analyze' | 'Evaluate' | 'Create';

export interface LearningOutcome {
  id: number | string;
  course_id?: number | string;
  code: string; // e.g. "CLO-1", "CLO-2"
  description: string;
  cognitive_level?: CognitiveLevel;
  bloomLevel?: CognitiveLevel;
  sort_order?: number;
  targetScorePercent?: number;
  created_at?: string;
  updated_at?: string;
}

export interface CourseMaterial {
  id: number | string;
  course_id: number | string;
  title: string;
  description?: string | null;
  file_name: string;
  file_path?: string;
  file_type?: string;
  file_size?: number;
  uploaded_by?: number | string;
  created_at?: string;
  updated_at?: string;
}

export interface TopicCoverage {
  topic: string;
  coveragePercent: number;
  questionCount: number;
  weightPercent: number;
  status: 'Good' | 'Attention' | 'Critical';
}

export interface Course {
  id: number | string;
  user_id?: number | string;
  course_code?: string;
  course_name?: string;
  description?: string | null;
  semester: string;
  academic_year?: string;
  credits?: number;
  status?: 'active' | 'archived' | 'draft' | string;
  
  // Legacy / convenience mappings
  code?: string;
  title?: string;
  year?: number;
  section?: string;
  creditHours?: number;
  department?: string;
  studentsCount?: number;
  assessmentCount?: number;
  learningOutcomesCount?: number;
  
  learning_outcomes?: LearningOutcome[];
  learning_outcomes_count?: number;
  learningOutcomes?: LearningOutcome[];
  
  materials?: CourseMaterial[];
  materials_count?: number;
  
  assessments?: any[];
  assessments_count?: number;
  
  created_at?: string;
  updated_at?: string;
  createdAt?: string;
  updatedAt?: string;
}

export type AssessmentType = 'midterm' | 'final' | 'quiz' | 'assignment' | 'class_test' | 'project' | 'other' | 'Midterm' | 'Final' | 'Quiz' | 'Assignment' | 'Project' | string;
export type AssessmentStatus = 'draft' | 'published' | 'completed' | 'Analyzed' | 'Pending' | 'Draft' | 'Needs Review' | string;

export interface AssessmentQuestionPaper {
  id: number | string;
  assessment_id: number | string;
  uploaded_by: number | string;
  file_name: string;
  file_path?: string;
  file_type?: string;
  file_size?: number;
  created_at?: string;
  updated_at?: string;
}

export interface PreviousQuestion {
  id: number | string;
  user_id?: number | string;
  course_id: number | string;
  course?: Course;
  question_text: string;
  question_type?: 'mcq' | 'short_answer' | 'descriptive' | 'problem_solving' | 'true_false' | 'other' | string;
  marks?: number | null;
  difficulty_level?: 'easy' | 'medium' | 'hard' | string;
  cognitive_level?: CognitiveLevel | string;
  source?: 'previous_exam' | 'question_bank' | 'uploaded_document' | 'manual' | 'other' | string;
  source_year?: string | null;
  source_assessment?: string | null;
  file_name?: string | null;
  file_path?: string | null;
  file_type?: string | null;
  file_size?: number | null;
  created_at?: string;
  updated_at?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface QuestionDetail {
  id: string | number;
  questionNumber?: number;
  question_number?: number;
  text?: string;
  question_text?: string;
  maxMarks?: number;
  marks?: number;
  topic?: string;
  learningOutcomeCode?: string;
  learning_outcome_id?: number | string;
  learningOutcome?: LearningOutcome;
  learning_outcome?: LearningOutcome;
  cognitiveLevel?: CognitiveLevel | string;
  cognitive_level?: CognitiveLevel | string;
  difficulty?: 'Easy' | 'Medium' | 'Hard' | 'easy' | 'medium' | 'hard' | string;
  difficulty_level?: 'Easy' | 'Medium' | 'Hard' | 'easy' | 'medium' | 'hard' | string;
  expected_answer?: string;
  
  // AI Question Analysis Fields (Step 10)
  ai_question_type?: string;
  ai_difficulty_level?: 'easy' | 'medium' | 'hard' | string;
  ai_cognitive_level?: CognitiveLevel | string;
  ai_topics?: Array<{ topic: string; confidence: number }> | string[];
  ai_analysis_status?: 'pending' | 'completed' | 'failed' | string;
  ai_analyzed_at?: string;

  similarityFlag?: {
    isSimilar: boolean;
    matchedAssessment: string;
    similarityScore: number;
    notes: string;
  };
}

export interface AiTopicPrediction {
  topic: string;
  confidence: number;
}

export interface AiQuestionAnalysisResult {
  question_id?: string | number;
  question_text: string;
  question_type: string;
  difficulty_level: string;
  cognitive_level: string;
  matched_topics: AiTopicPrediction[];
  confidence_scores: {
    type?: number;
    difficulty?: number;
    cognitive?: number;
    topics?: number;
  };
  explanation?: string;
  features?: {
    word_count?: number;
    has_options?: boolean;
    has_math?: boolean;
    has_code?: boolean;
    detected_verbs?: string[];
  };
}

export interface AiBatchQuestionAnalysisResult {
  total_questions: number;
  results: AiQuestionAnalysisResult[];
  summary?: {
    type_distribution?: Record<string, number>;
    difficulty_distribution?: Record<string, number>;
    cognitive_distribution?: Record<string, number>;
    top_topics?: string[];
  };
}

export interface DifficultyDistribution {
  easyPercent: number;
  mediumPercent: number;
  hardPercent: number;
}

export interface CognitiveDistribution {
  lowerOrderPercent: number; // Remember, Understand
  mediumOrderPercent: number; // Apply, Analyze
  higherOrderPercent: number; // Evaluate, Create
}

export interface QualityMetrics {
  overallQualityScore: number; // 0-100
  topicCoverageScore: number; // 0-100
  learningOutcomeScore: number; // 0-100
  difficultyBalanceScore: number; // 0-100
  similarQuestionsCount: number;
  totalQuestions: number;
  totalMarks: number;
}

export type FindingSeverity = 'Good' | 'Attention' | 'Critical' | 'Neutral';

export interface AnalysisFinding {
  id: string;
  title: string;
  description: string;
  severity: FindingSeverity;
  category: 'coverage' | 'alignment' | 'similarity' | 'difficulty' | 'structure';
  relatedQuestions?: number[];
  relatedOutcome?: string;
}

export type RecommendationPriority = 'High' | 'Medium' | 'Low';
export type RecommendationCategory = 'Learning Outcome' | 'Question Design' | 'Topic Balance' | 'Difficulty' | 'Integrity';

export interface Recommendation {
  id: string;
  title: string;
  description: string;
  action: string;
  priority: RecommendationPriority;
  category: RecommendationCategory;
  applied?: boolean;
}

export interface AssessmentAnalysis {
  id: string;
  assessmentId: string;
  courseCode: string;
  courseTitle: string;
  assessmentTitle: string;
  semester: string;
  analysisDate: string;
  status: AssessmentStatus;
  metrics: QualityMetrics;
  difficultyDistribution: DifficultyDistribution;
  cognitiveDistribution: CognitiveDistribution;
  topicCoverages: TopicCoverage[];
  findings: AnalysisFinding[];
  recommendations: Recommendation[];
  questions: QuestionDetail[];
  summaryNote: string;
}

export interface Assessment {
  id: number | string;
  course_id?: number | string;
  title: string;
  type: AssessmentType;
  description?: string | null;
  assessment_date?: string | null;
  total_marks?: number;
  duration_minutes?: number | null;
  status: AssessmentStatus;

  course?: Course;
  courseId?: string | number;
  courseCode?: string;
  courseTitle?: string;
  semester?: string;

  questionPaper?: AssessmentQuestionPaper | null;
  question_paper?: AssessmentQuestionPaper | null;
  questions?: QuestionDetail[];
  questions_count?: number;
  totalQuestions?: number;
  totalMarks?: number;

  qualityScore?: number;
  uploadedAt?: string;
  lastAnalyzedAt?: string;
  created_at?: string;
  updated_at?: string;
}

export interface HistoryRecord {
  id: string;
  assessmentId: string;
  assessmentTitle: string;
  courseCode: string;
  courseTitle: string;
  qualityScore: number;
  analysisDate: string;
  status: AssessmentStatus;
  topicCoverageScore: number;
  outcomeAlignmentScore: number;
  similarQuestionsCount: number;
}

export interface DashboardStats {
  totalCourses: number;
  totalAssessments: number;
  analysesCompleted: number;
  recommendationsCount: number;
  averageQualityScore: number;
  activeSemester: string;
}

export type DocumentProcessingType = 'syllabus' | 'question_paper' | 'assignment' | 'previous_exam' | 'other';
export type DocumentProcessingStatus = 'uploaded' | 'processing' | 'completed' | 'failed';

export interface DocumentProcessing {
  id: number | string;
  user_id?: number | string;
  course_id: number | string;
  assessment_id?: number | string | null;
  document_type: DocumentProcessingType;
  original_file_name: string;
  stored_file_name?: string;
  file_path?: string;
  mime_type?: string;
  file_size: number;
  extracted_text?: string | null;
  cleaned_text?: string | null;
  processing_status: DocumentProcessingStatus;
  processing_error?: string | null;
  processed_at?: string | null;
  course?: {
    id: number | string;
    title?: string;
    course_name?: string;
    code?: string;
    course_code?: string;
  };
  assessment?: {
    id: number | string;
    title: string;
    type?: string;
  };
  created_at?: string;
  updated_at?: string;
}

export interface UploadDocumentPayload {
  course_id: number | string;
  assessment_id?: number | string | null;
  document_type: DocumentProcessingType;
  file: File;
}

// Learning Outcome Alignment Types (Step 11)
export type AlignmentLevel = 'STRONG' | 'WEAK' | 'NOT_ALIGNED';
export type LoCoverageStatus = 'COVERED' | 'WEAKLY_COVERED' | 'NOT_COVERED';

export interface MatchedLoDetail {
  id?: number | string;
  code?: string;
  description: string;
  similarity: number;
  alignment_level: AlignmentLevel;
}

export interface QuestionAlignmentDetail {
  question_id?: number | string;
  question_number?: number | string;
  question_text: string;
  matched_learning_outcome?: MatchedLoDetail;
  alternative_matches: MatchedLoDetail[];
  alignment_status: AlignmentLevel;
  similarity_score: number;
  reasoning?: string;
}

export interface LoCoverageDetail {
  learning_outcome_id?: number | string;
  code?: string;
  description: string;
  coverage_status: LoCoverageStatus;
  matching_questions_count: number;
  matching_question_numbers: Array<number | string>;
  max_similarity: number;
}

export interface AlignmentAnalysisResult {
  status: string;
  method: string;
  overall_alignment_score: number;
  aligned_questions_count: number;
  total_questions: number;
  total_learning_outcomes: number;
  covered_learning_outcomes_count: number;
  question_alignment: QuestionAlignmentDetail[];
  learning_outcome_coverage: LoCoverageDetail[];
  findings: string[];
  thresholds: {
    strong: number;
    weak: number;
  };
}

export interface AssessmentAlignmentResponseData {
  alignment: AlignmentAnalysisResult;
  report?: {
    id: number | string;
    assessment_id: number | string;
    learning_outcome_alignment_score: number;
    overall_score?: number;
    findings?: Record<string, unknown>;
    analysis_status?: string;
  };
}

// Semantic Similarity & Duplicate Detection Types (Step 12)
export type SimilarityStatus = 'POTENTIAL_DUPLICATE' | 'HIGHLY_SIMILAR' | 'SOMEWHAT_SIMILAR' | 'NOT_SIMILAR';

export interface MatchedPreviousQuestionDetail {
  previous_question_id?: number | string;
  previous_question_text: string;
  similarity_score: number;
  similarity_status: SimilarityStatus;
  source_year?: number | string;
  source_assessment?: string;
  question_type?: string;
  cognitive_level?: string;
}

export interface QuestionSimilarityResultDetail {
  current_question_id?: number | string;
  current_question_number?: number | string;
  current_question_text: string;
  current_question_type?: string;
  current_cognitive_level?: string;
  max_similarity_score: number;
  max_similarity_status: SimilarityStatus;
  matches: MatchedPreviousQuestionDetail[];
  reasoning?: string;
}

export interface SimilarityAnalysisResult {
  status: string;
  method: string;
  model: string;
  thresholds: {
    potential_duplicate: number;
    high_similarity: number;
    moderate_similarity: number;
  };
  total_current_questions: number;
  total_previous_questions: number;
  potential_duplicates_count: number;
  highly_similar_count: number;
  somewhat_similar_count: number;
  average_similarity_score: number;
  results: QuestionSimilarityResultDetail[];
  findings: string[];
}

export interface AssessmentSimilarityResponseData {
  similarity: SimilarityAnalysisResult;
  report?: {
    id: number | string;
    assessment_id: number | string;
    similarity_score: number;
    similar_questions_count: number;
    overall_score?: number;
    findings?: Record<string, unknown>;
    analysis_status?: string;
  };
}

// STEP 13: Assessment Quality Engine Types

export type QualityRating = 'EXCELLENT' | 'GOOD' | 'FAIR' | 'NEEDS_REVIEW' | 'REQUIRES_ATTENTION' | 'UNAVAILABLE';

export interface TopicCoverageItemData {
  topic: string;
  question_count: number;
  marks: number;
  coverage_percentage: number;
  coverage_status: 'COVERED' | 'ADEQUATE' | 'LOW' | 'NOT_COVERED' | string;
}

export interface TopicAnalysisData {
  status: 'AVAILABLE' | 'UNAVAILABLE' | string;
  score: number | null;
  methodology: string;
  total_topics_defined: number;
  covered_topics_count: number;
  topics: TopicCoverageItemData[];
}

export interface LoCoverageItemData {
  code: string;
  description?: string;
  question_count: number;
  marks: number;
  strongly_aligned_questions: number;
  weakly_aligned_questions: number;
  coverage_percentage: number;
  coverage_status: 'COVERED' | 'ADEQUATE' | 'WEAK' | 'NOT_COVERED' | string;
}

export interface LoAnalysisData {
  status: 'AVAILABLE' | 'UNAVAILABLE' | string;
  score: number | null;
  methodology: string;
  total_los_defined: number;
  covered_los_count: number;
  learning_outcomes: LoCoverageItemData[];
}

export interface DifficultyDistributionItemData {
  level: string;
  question_count: number;
  question_percentage: number;
  marks: number;
  marks_percentage: number;
  target_percentage: number;
  deviation: number;
}

export interface DifficultyAnalysisData {
  status: 'AVAILABLE' | 'UNAVAILABLE' | string;
  score: number | null;
  methodology: string;
  total_deviation: number;
  distribution: DifficultyDistributionItemData[];
}

export interface CognitiveLevelItemData {
  level: string;
  question_count: number;
  question_percentage: number;
  marks: number;
  marks_percentage: number;
}

export interface CognitiveAnalysisData {
  status: 'AVAILABLE' | 'UNAVAILABLE' | string;
  score: number | null;
  methodology: string;
  shannon_entropy: number;
  max_possible_entropy: number;
  dominant_level?: string | null;
  dominant_percentage: number;
  distribution: CognitiveLevelItemData[];
}

export interface QuestionTypeItemData {
  question_type: string;
  question_count: number;
  question_percentage: number;
  marks: number;
  marks_percentage: number;
}

export interface QuestionDiversityData {
  status: 'AVAILABLE' | 'UNAVAILABLE' | string;
  score: number | null;
  methodology: string;
  unique_types_count: number;
  shannon_entropy: number;
  dominant_type?: string | null;
  dominant_percentage: number;
  distribution: QuestionTypeItemData[];
}

export interface MarksAnalysisData {
  status: 'AVAILABLE' | 'UNAVAILABLE' | string;
  score: number | null;
  methodology: string;
  total_question_marks: number;
  assessment_expected_marks?: number | null;
  marks_match_assessment: boolean;
  average_marks: number;
  min_marks: number;
  max_marks: number;
  median_marks: number;
  high_concentration_detected: boolean;
  highest_single_question_share: number;
  highest_single_question_number?: number | null;
  marks_by_topic?: Record<string, number>;
  marks_by_lo?: Record<string, number>;
  marks_by_difficulty?: Record<string, number>;
  marks_by_cognitive?: Record<string, number>;
}

export interface QualityComponentScores {
  topic_coverage?: number | null;
  learning_outcome_coverage?: number | null;
  difficulty_balance?: number | null;
  cognitive_diversity?: number | null;
  question_diversity?: number | null;
  marks_distribution?: number | null;
}

export interface AssessmentQualityResult {
  status: string;
  method: string;
  overall_quality_score: number | null;
  rating: QualityRating;
  weights_applied: Record<string, number>;
  excluded_components: string[];
  components: QualityComponentScores;
  topic_analysis: TopicAnalysisData;
  learning_outcome_analysis: LoAnalysisData;
  difficulty_analysis: DifficultyAnalysisData;
  cognitive_analysis: CognitiveAnalysisData;
  question_diversity_analysis: QuestionDiversityData;
  marks_analysis: MarksAnalysisData;
  findings: string[];
}

export interface AssessmentQualityResponseData {
  quality: AssessmentQualityResult;
  report?: {
    id: number | string;
    assessment_id: number | string;
    overall_score?: number;
    topic_coverage_score?: number;
    learning_outcome_alignment_score?: number;
    difficulty_balance_score?: number;
    cognitive_level_balance_score?: number;
    findings?: Record<string, unknown>;
    analysis_status?: string;
  };
}





