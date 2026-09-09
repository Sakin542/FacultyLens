import { apiClient } from './api';
import {
  CoCoverage, CoPoMapping, CoPoMatrix, CoPoOverview, MappingAnalysisRun, MappingFinding, MappingLevel,
  PoEvidence, Program, ProgramOutcome, QuestionCoMappingReview,
} from '@/types/coPo';

interface Envelope<T> { status: string; message?: string; data: T; }
interface RunPart<T> { status: string; data: T; run: MappingAnalysisRun | null; }

/**
 * STEP 31: CO/PO Mapping Validator API. Deterministic Laravel calculations; AI is only used for
 * question->CO suggestions which faculty must confirm.
 */
export const coPoMappingService = {
  getCourseMapping: (courseId: number | string): Promise<Envelope<CoPoOverview>> =>
    apiClient(`/courses/${courseId}/co-po-mapping`, { method: 'GET' }),

  analyzeCourseMapping: (courseId: number | string, force = false): Promise<Envelope<MappingAnalysisRun>> =>
    apiClient(`/courses/${courseId}/co-po-mapping/analyze${force ? '?force=1' : ''}`, { method: 'POST' }),

  getMappingMatrix: (courseId: number | string): Promise<Envelope<CoPoMatrix>> =>
    apiClient(`/courses/${courseId}/co-po-mapping/matrix`, { method: 'GET' }),

  getMappingFindings: (courseId: number | string): Promise<RunPart<MappingFinding[]>> =>
    apiClient(`/courses/${courseId}/co-po-mapping/findings`, { method: 'GET' }),

  getCoPerformance: (courseId: number | string): Promise<RunPart<CoCoverage[]>> =>
    apiClient(`/courses/${courseId}/co-po-mapping/co-performance`, { method: 'GET' }),

  getPoEvidence: (courseId: number | string): Promise<RunPart<PoEvidence[]>> =>
    apiClient(`/courses/${courseId}/co-po-mapping/po-evidence`, { method: 'GET' }),

  getQuestionMappings: (courseId: number | string): Promise<Envelope<QuestionCoMappingReview[]>> =>
    apiClient(`/courses/${courseId}/co-po-mapping/question-mappings`, { method: 'GET' }),

  createMapping: (courseId: number | string, data: { learning_outcome_id: number; program_outcome_id: number; mapping_level: MappingLevel; justification?: string | null }): Promise<Envelope<CoPoMapping>> =>
    apiClient(`/courses/${courseId}/co-po-mappings`, { method: 'POST', body: JSON.stringify(data) }),

  updateMapping: (mappingId: number | string, data: { mapping_level: MappingLevel; justification?: string | null }): Promise<Envelope<CoPoMapping>> =>
    apiClient(`/co-po-mappings/${mappingId}`, { method: 'PUT', body: JSON.stringify(data) }),

  deleteMapping: (mappingId: number | string): Promise<{ status: string; message: string }> =>
    apiClient(`/co-po-mappings/${mappingId}`, { method: 'DELETE' }),

  confirmQuestionCoMapping: (questionId: number | string, learningOutcomeId: number): Promise<Envelope<{ status: string; mapping_source: string }>> =>
    apiClient(`/questions/${questionId}/co-mappings/confirm`, { method: 'POST', body: JSON.stringify({ learning_outcome_id: learningOutcomeId }) }),

  rejectQuestionCoMapping: (questionId: number | string, learningOutcomeId: number): Promise<Envelope<{ status: string; mapping_source: string }>> =>
    apiClient(`/questions/${questionId}/co-mappings/reject`, { method: 'POST', body: JSON.stringify({ learning_outcome_id: learningOutcomeId }) }),

  // Programs & outcomes
  getPrograms: (): Promise<Envelope<Program[]>> => apiClient('/programs', { method: 'GET' }),
  createProgram: (data: { code: string; name: string; description?: string; department?: string }): Promise<Envelope<Program>> =>
    apiClient('/programs', { method: 'POST', body: JSON.stringify(data) }),
  createProgramOutcome: (programId: number | string, data: { code: string; title: string; description?: string }): Promise<Envelope<ProgramOutcome>> =>
    apiClient(`/programs/${programId}/outcomes`, { method: 'POST', body: JSON.stringify(data) }),
  updateProgramOutcome: (id: number | string, data: Partial<{ code: string; title: string; description: string; status: string }>): Promise<Envelope<ProgramOutcome>> =>
    apiClient(`/program-outcomes/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  deleteProgramOutcome: (id: number | string): Promise<{ status: string }> =>
    apiClient(`/program-outcomes/${id}`, { method: 'DELETE' }),
};
