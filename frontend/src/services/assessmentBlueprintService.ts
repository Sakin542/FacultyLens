import { apiClient } from './api';
import { BlueprintComparison, BlueprintCoverage, BlueprintInput, BlueprintResponse, GenerationHandoff, QuestionValidationResponse } from '@/types/blueprint';

interface Envelope<T> { status: string; message?: string; data: T; }

/** STEP 37: Assessment Blueprint API (planning + validation; never finalizes the assessment). */
export const assessmentBlueprintService = {
  getBlueprint: (assessmentId: number | string): Promise<Envelope<BlueprintResponse>> => apiClient(`/assessments/${assessmentId}/blueprint`, { method: 'GET' }),

  createBlueprint: (assessmentId: number | string, data: BlueprintInput): Promise<Envelope<BlueprintResponse>> =>
    apiClient(`/assessments/${assessmentId}/blueprint`, { method: 'POST', body: JSON.stringify(data) }),

  updateBlueprint: (blueprintId: number | string, data: BlueprintInput): Promise<Envelope<BlueprintResponse>> =>
    apiClient(`/blueprints/${blueprintId}`, { method: 'PUT', body: JSON.stringify(data) }),

  deleteBlueprint: (blueprintId: number | string): Promise<Envelope<null>> => apiClient(`/blueprints/${blueprintId}`, { method: 'DELETE' }),

  validateBlueprint: (blueprintId: number | string): Promise<Envelope<BlueprintResponse>> => apiClient(`/blueprints/${blueprintId}/validate`, { method: 'POST' }),

  finalizeBlueprint: (blueprintId: number | string): Promise<Envelope<BlueprintResponse>> => apiClient(`/blueprints/${blueprintId}/finalize`, { method: 'POST' }),

  getCoverage: (blueprintId: number | string): Promise<Envelope<BlueprintCoverage>> => apiClient(`/blueprints/${blueprintId}/coverage`, { method: 'GET' }),

  compareWithQuestions: (blueprintId: number | string, syncRecommendations = false): Promise<Envelope<BlueprintComparison>> =>
    apiClient(`/blueprints/${blueprintId}/comparison${syncRecommendations ? '?sync_recommendations=1' : ''}`, { method: 'GET' }),

  generateQuestions: (blueprintId: number | string, options?: { language?: string; document_scope?: Record<string, unknown> | null }): Promise<Envelope<GenerationHandoff>> =>
    apiClient(`/blueprints/${blueprintId}/generate-questions`, { method: 'POST', body: JSON.stringify(options ?? {}) }),

  validateQuestions: (blueprintId: number | string, data: { question_ids?: number[]; previous_question_ids?: number[] }): Promise<Envelope<QuestionValidationResponse>> =>
    apiClient(`/blueprints/${blueprintId}/validate-questions`, { method: 'POST', body: JSON.stringify(data) }),
};
