import { apiClient } from './api';
import {
  CreateVersionInput, RestoreVersionInput, UpdateVersionInput, VersionAnalysis, VersionBlueprintResponse, VersionComparison, VersionListResponse, VersionResponse, VersionValidation,
} from '@/types/assessmentVersion';

interface Envelope<T> { status: string; message?: string; data: T; }

type Id = number | string;

/** STEP 38: Assessment Versioning API (immutable historical versions; all numbering happens server-side). */
export const assessmentVersionService = {
  getVersions: (assessmentId: Id): Promise<Envelope<VersionListResponse>> => apiClient(`/assessments/${assessmentId}/versions`, { method: 'GET' }),

  getVersion: (assessmentId: Id, versionId: Id): Promise<Envelope<VersionResponse>> => apiClient(`/assessments/${assessmentId}/versions/${versionId}`, { method: 'GET' }),

  createVersion: (assessmentId: Id, data: CreateVersionInput): Promise<Envelope<VersionResponse>> =>
    apiClient(`/assessments/${assessmentId}/versions`, { method: 'POST', body: JSON.stringify(data) }),

  updateVersion: (versionId: Id, data: UpdateVersionInput): Promise<Envelope<VersionResponse>> =>
    apiClient(`/assessment-versions/${versionId}`, { method: 'PUT', body: JSON.stringify(data) }),

  submitForReview: (versionId: Id): Promise<Envelope<VersionResponse>> => apiClient(`/assessment-versions/${versionId}/submit-review`, { method: 'POST' }),

  approveVersion: (versionId: Id): Promise<Envelope<VersionResponse>> => apiClient(`/assessment-versions/${versionId}/approve`, { method: 'POST' }),

  finalizeVersion: (versionId: Id): Promise<Envelope<VersionResponse>> => apiClient(`/assessment-versions/${versionId}/finalize`, { method: 'POST' }),

  archiveVersion: (versionId: Id): Promise<Envelope<VersionResponse>> => apiClient(`/assessment-versions/${versionId}/archive`, { method: 'POST' }),

  restoreVersion: (versionId: Id, data: RestoreVersionInput = {}): Promise<Envelope<VersionResponse>> =>
    apiClient(`/assessment-versions/${versionId}/restore`, { method: 'POST', body: JSON.stringify(data) }),

  validateVersion: (versionId: Id): Promise<Envelope<VersionValidation>> => apiClient(`/assessment-versions/${versionId}/validate`, { method: 'POST' }),

  compareVersions: (versionId: Id, otherVersionId: Id): Promise<Envelope<VersionComparison>> =>
    apiClient(`/assessment-versions/${versionId}/compare/${otherVersionId}`, { method: 'GET' }),

  getAnalysis: (versionId: Id): Promise<Envelope<VersionAnalysis>> => apiClient(`/assessment-versions/${versionId}/analysis`, { method: 'GET' }),

  getBlueprint: (versionId: Id): Promise<Envelope<VersionBlueprintResponse>> => apiClient(`/assessment-versions/${versionId}/blueprint`, { method: 'GET' }),
};
