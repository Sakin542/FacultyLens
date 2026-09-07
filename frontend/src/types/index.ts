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
  similarityFlag?: {
    isSimilar: boolean;
    matchedAssessment: string;
    similarityScore: number;
    notes: string;
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


