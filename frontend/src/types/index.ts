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

export interface LearningOutcome {
  id: string;
  code: string; // e.g. "CLO-1", "CLO-2"
  description: string;
  bloomLevel: 'Remember' | 'Understand' | 'Apply' | 'Analyze' | 'Evaluate' | 'Create';
  targetScorePercent?: number;
}

export interface TopicCoverage {
  topic: string;
  coveragePercent: number;
  questionCount: number;
  weightPercent: number;
  status: 'Good' | 'Attention' | 'Critical';
}

export interface Course {
  id: string;
  code: string;
  title: string;
  semester: string;
  year: number;
  section?: string;
  creditHours: number;
  department: string;
  studentsCount: number;
  assessmentCount: number;
  learningOutcomesCount: number;
  learningOutcomes: LearningOutcome[];
  createdAt: string;
  updatedAt: string;
}

export type AssessmentType = 'Midterm' | 'Final' | 'Quiz' | 'Assignment' | 'Project';
export type AssessmentStatus = 'Analyzed' | 'Pending' | 'Draft' | 'Needs Review';

export interface QuestionDetail {
  id: string;
  questionNumber: number;
  text: string;
  maxMarks: number;
  topic: string;
  learningOutcomeCode: string;
  cognitiveLevel: 'Remember' | 'Understand' | 'Apply' | 'Analyze' | 'Evaluate' | 'Create';
  difficulty: 'Easy' | 'Medium' | 'Hard';
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
  id: string;
  courseId: string;
  courseCode: string;
  courseTitle: string;
  title: string;
  type: AssessmentType;
  semester: string;
  status: AssessmentStatus;
  qualityScore?: number;
  totalQuestions: number;
  totalMarks: number;
  uploadedAt: string;
  lastAnalyzedAt?: string;
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

