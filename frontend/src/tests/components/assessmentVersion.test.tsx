import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AssessmentVersions } from '@/pages/AssessmentVersions';
import { AssessmentVersionDetail } from '@/pages/AssessmentVersionDetail';
import { AssessmentVersionCompare } from '@/pages/AssessmentVersionCompare';
import { VersionEmptyState } from '@/components/assessmentVersion/VersionEmptyState';
import { VersionError } from '@/components/assessmentVersion/VersionError';
import { VersionLoading } from '@/components/assessmentVersion/VersionLoading';
import { VersionTimeline, toTimeline } from '@/components/assessmentVersion/VersionTimeline';
import { VersionStatusBadge } from '@/components/assessmentVersion/VersionStatusBadge';
import { QuestionChangeList } from '@/components/assessmentVersion/QuestionChangeList';
import { BlueprintChangeList } from '@/components/assessmentVersion/BlueprintChangeList';
import { getVersionErrorMessage } from '@/components/assessmentVersion/versionUtils';
import { ApiError } from '@/services/api';
import { AssessmentVersion, AssessmentVersionSummary, VersionComparison, VersionListResponse, VersionResponse } from '@/types/assessmentVersion';

vi.mock('@/services/assessmentVersionService', () => ({
  assessmentVersionService: {
    getVersions: vi.fn(), getVersion: vi.fn(), createVersion: vi.fn(), updateVersion: vi.fn(), submitForReview: vi.fn(), approveVersion: vi.fn(), finalizeVersion: vi.fn(), archiveVersion: vi.fn(),
    restoreVersion: vi.fn(), validateVersion: vi.fn(), compareVersions: vi.fn(), getAnalysis: vi.fn(), getBlueprint: vi.fn(),
  },
}));
vi.mock('@/services/learningOutcomeService', () => ({ learningOutcomeService: { getByCourse: vi.fn() } }));
vi.mock('@/services/coPoMappingService', () => ({ coPoMappingService: { getCourseMapping: vi.fn() } }));
vi.mock('@/context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, name: 'Dr. A' }, loading: false }) }));

import { assessmentVersionService } from '@/services/assessmentVersionService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { coPoMappingService } from '@/services/coPoMappingService';
const svc = assessmentVersionService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const m = (s: unknown) => s as unknown as Record<string, ReturnType<typeof vi.fn>>;
const ok = <T,>(data: T, message = 'ok') => ({ status: 'success', message, data });

const permissions = { view: true, edit: true, approve: true, finalize: true, archive: true, restore: true, view_analysis: true };
const readOnly = { ...permissions, edit: false, approve: false, finalize: false, archive: false, restore: false };

const summary = (o: Partial<AssessmentVersionSummary>): AssessmentVersionSummary => ({
  id: 1, assessment_id: 10, version_number: 1, version_label: 'v1.0', version_type: 'MAJOR', status: 'DRAFT', title: 'Midterm Examination', assessment_type: 'midterm', total_marks: 30, duration_minutes: 90, question_count: 3,
  change_summary: null, based_on_version_id: null, based_on_version: null, created_by: { id: 1, name: 'Dr. A' }, validation_status: null, has_submissions: false, is_editable: true,
  created_at: '2026-09-01T10:00:00Z', updated_at: '2026-09-01T10:00:00Z', submitted_at: null, approved_at: null, finalized_at: null, archived_at: null, ...o,
});
const q = (n: number, text: string, extra: Partial<AssessmentVersion['questions'][number]> = {}) => ({
  id: 100 + n, original_question_id: 20 + n, question_number: n, section_name: null, question_text: text, question_type: 'descriptive', marks: 10, difficulty_level: 'medium', cognitive_level: 'Apply', topic: null,
  learning_outcome_id: 1, learning_outcome_code: 'CO1', program_outcome_id: null, program_outcome_code: null, expected_answer: null, rubric_snapshot: null, sort_order: n, ...extra,
});
const full = (o: Partial<AssessmentVersion> = {}): AssessmentVersion => ({
  ...summary({}), description: null, instructions: null, content_hash: 'abc', validation: null,
  assessment: { id: 10, title: 'Midterm Examination', type: 'midterm', status: 'draft' }, course: { id: 1, code: 'CSE101', name: 'Database Systems', program_id: null },
  questions: [q(1, 'Explain SQL.'), q(2, 'Explain normalization.'), q(3, 'Design a schema.')], blueprint: null, ...o,
});
const v1 = summary({ id: 1, status: 'FINALIZED', is_editable: false, finalized_at: '2026-09-02T10:00:00Z' });
const v2 = summary({ id: 2, version_number: 2, version_label: 'v2.0', status: 'DRAFT', based_on_version_id: 1, based_on_version: { id: 1, version_number: 1, version_label: 'v1.0' }, change_summary: 'Updated Q3 for CO2 coverage', created_at: '2026-09-03T10:00:00Z' });
const list = (versions: AssessmentVersionSummary[], perms = permissions): VersionListResponse => ({
  assessment: { id: 10, title: 'Midterm Examination', type: 'midterm', status: 'draft', course_id: 1, total_marks: 30, question_count: 3 }, versions, current_version_id: versions.find((v) => v.status === 'FINALIZED')?.id ?? null,
  working_version_id: versions[0]?.id ?? null, total_versions: versions.length, permissions: perms,
});
const detail = (version: AssessmentVersion, perms = permissions): VersionResponse => ({ version, permissions: perms, analysis: { assessment_version_id: version.id, version_label: version.version_label, status: 'NONE', latest: null, reports: [], note: '' } });

const comparison: VersionComparison = {
  from: v1, to: { ...v2, total_marks: 40, question_count: 4 },
  metadata: [{ field: 'title', label: 'Title', from: 'Midterm Examination', to: 'Midterm Examination', changed: false }, { field: 'total_marks', label: 'Total marks', from: 30, to: 40, changed: true }],
  questions: {
    items: [
      { status: 'UNCHANGED', replaced: false, question_number: 1, from: q(1, 'Explain SQL.'), to: q(1, 'Explain SQL.'), changes: [] },
      { status: 'MODIFIED', replaced: false, question_number: 3, from: q(3, 'Explain normalization.'), to: q(3, 'Explain 2NF and 3NF with suitable examples.', { marks: 15, difficulty_level: 'hard' }), changes: [{ field: 'question_text', from: 'Explain normalization.', to: 'Explain 2NF and 3NF with suitable examples.' }, { field: 'marks', from: 10, to: 15 }, { field: 'difficulty_level', from: 'medium', to: 'hard' }] },
      { status: 'REMOVED', replaced: false, question_number: 2, from: q(2, 'Design a schema.'), to: null, changes: [] },
      { status: 'ADDED', replaced: false, question_number: 4, from: null, to: q(4, 'Define a transaction.', { original_question_id: null }), changes: [] },
    ],
    summary: { added: 1, removed: 1, modified: 1, unchanged: 1, total_from: 3, total_to: 3 },
  },
  marks: { total: { from: 30, to: 40, difference: 10 }, questions_sum: { from: 30, to: 40 }, items: [{ question_number: 3, status: 'MODIFIED', from: 10, to: 15, difference: 5 }] },
  blueprint: {
    changed: true, threshold_pp: 0.5,
    profile: { difficulty: { label: 'Difficulty', rows: [{ key: 'easy', label: 'Easy', from: 30, to: 20, difference: -10, changed: true }, { key: 'medium', label: 'Medium', from: 50, to: 50, difference: 0, changed: false }, { key: 'hard', label: 'Hard', from: 20, to: 30, difference: 10, changed: true }] }, structure: { label: 'Structure', rows: [{ key: 'question_count', label: 'Question count', from: 3, to: 3, difference: 0, changed: false }] } },
    planned: { configured: false, changed: false, from: null, to: null, dimensions: {} },
  },
  mappings: { learning_outcomes: { from: ['CO1'], to: ['CO1', 'CO2'], added: ['CO2'], removed: [], unchanged: ['CO1'] }, program_outcomes: { from: [], to: [], added: [], removed: [], unchanged: [] }, items: [] },
  analysis: { available: true, from: null, to: null, metrics: [{ key: 'overall_score', label: 'Assessment quality', from: 76, to: 84, difference: 8 }], note: 'Metrics come from STEP 13.' },
  summary: { added: 1, removed: 1, modified: 1, unchanged: 1, total_from: 3, total_to: 3, metadata_changes: 1, marks_difference: 10, question_count_difference: 0, blueprint_changed: true, detected_change_type: 'MAJOR' },
  compared_at: '2026-09-04T10:00:00Z',
};

const renderAt = (path: string) => render(
  <MemoryRouter initialEntries={[path]}>
    <Routes>
      <Route path="/assessments/:assessmentId/versions" element={<AssessmentVersions />} />
      <Route path="/assessments/:assessmentId/versions/:versionId" element={<AssessmentVersionDetail />} />
      <Route path="/assessments/:assessmentId/versions/:versionId/compare" element={<AssessmentVersionCompare />} />
    </Routes>
  </MemoryRouter>,
);

beforeEach(() => {
  vi.clearAllMocks();
  m(learningOutcomeService).getByCourse.mockResolvedValue(ok([{ id: 1, code: 'CO1', description: 'x' }, { id: 2, code: 'CO2', description: 'y' }]));
  m(coPoMappingService).getCourseMapping.mockResolvedValue(ok({ program: null, program_outcomes: [] }));
  svc.getBlueprint.mockResolvedValue(ok({ assessment_version_id: 1, version_label: 'v1.0', blueprint: null, profile: { question_count: 3, total_marks: 30, difficulty: { medium: { label: 'Medium', count: 3, percentage: 100 } }, cognitive: {}, question_types: {}, learning_outcomes: {}, program_outcomes: {}, topics: {} } }));
  vi.spyOn(window, 'confirm').mockReturnValue(true);
});

describe('AssessmentVersions page', () => {
  it('shows loading then the empty state and creates v1.0', async () => {
    svc.getVersions.mockResolvedValue(ok(list([])));
    svc.createVersion.mockResolvedValue(ok(detail(full()), 'Version v1.0 created as a draft.'));
    renderAt('/assessments/10/versions');
    expect(screen.getByTestId('version-loading')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId('version-empty-state')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Create v1.0' }));
    const dialog = screen.getByTestId('version-create-dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create v1.0' }));
    await waitFor(() => expect(svc.createVersion).toHaveBeenCalledWith('10', expect.objectContaining({ version_type: 'MAJOR', based_on_version_id: null })));
  });

  it('lists versions, renders the timeline and status badges, and requires a change summary for new versions', async () => {
    svc.getVersions.mockResolvedValue(ok(list([v2, v1])));
    svc.createVersion.mockResolvedValue(ok(detail(full({ id: 3, version_number: 3, version_label: 'v3.0' }))));
    renderAt('/assessments/10/versions');
    await waitFor(() => expect(screen.getByTestId('version-list')).toBeInTheDocument());
    expect(screen.getByTestId('version-card-1')).toHaveTextContent('Finalized');
    expect(screen.getByTestId('version-card-1')).toHaveTextContent('Current');
    expect(screen.getByTestId('version-card-2')).toHaveTextContent('Updated Q3 for CO2 coverage');
    const timeline = screen.getByTestId('version-timeline');
    const items = within(timeline).getAllByRole('listitem');
    expect(items[0]).toHaveTextContent('v1.0');
    expect(items[1]).toHaveTextContent('v2.0');
    expect(items[1]).toHaveTextContent('from v1.0');

    fireEvent.click(screen.getByRole('button', { name: 'Create new version' }));
    const dialog = screen.getByTestId('version-create-dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create draft version' }));
    expect(svc.createVersion).not.toHaveBeenCalled();
    expect(within(dialog).getByText(/Describe what will change/)).toBeInTheDocument();
    fireEvent.change(within(dialog).getByPlaceholderText(/Updated Q3 and Q6/), { target: { value: 'Adjusted hard-question distribution.' } });
    fireEvent.click(within(dialog).getByLabelText(/Minor/));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create draft version' }));
    await waitFor(() => expect(svc.createVersion).toHaveBeenCalledWith('10', { based_on_version_id: null, version_type: 'MINOR', change_summary: 'Adjusted hard-question distribution.' }));
  });

  it('enables compare only when two versions are selected and hides create for read-only members', async () => {
    svc.getVersions.mockResolvedValue(ok(list([v2, v1], readOnly)));
    renderAt('/assessments/10/versions');
    await waitFor(() => expect(screen.getByTestId('version-list')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Create new version' })).toBeNull();
    const compareBtn = screen.getByRole('button', { name: /Compare selected/ });
    expect(compareBtn).toBeDisabled();
    fireEvent.click(screen.getByLabelText('Select v1.0 for comparison'));
    fireEvent.click(screen.getByLabelText('Select v2.0 for comparison'));
    expect(screen.getByRole('button', { name: /Compare selected \(2\/2\)/ })).toBeEnabled();
  });

  it('shows an error state with retry on unauthorized access', async () => {
    svc.getVersions.mockRejectedValueOnce(new ApiError(403, 'Unauthorized access to this assessment.')).mockResolvedValueOnce(ok(list([])));
    renderAt('/assessments/10/versions');
    await waitFor(() => expect(screen.getByTestId('version-error')).toHaveTextContent('Unauthorized access to this assessment.'));
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByTestId('version-empty-state')).toBeInTheDocument());
  });
});

describe('AssessmentVersionDetail page', () => {
  it('renders a draft, edits a question and saves the wholesale question list', async () => {
    svc.getVersion.mockResolvedValue(ok(detail(full({ id: 2, version_number: 2, version_label: 'v2.0', change_summary: 'Sharpen Q1', based_on_version: { id: 1, version_label: 'v1.0' }, based_on_version_id: 1 }))));
    svc.updateVersion.mockResolvedValue(ok(detail(full({ id: 2, version_label: 'v2.0', questions: [q(1, 'Explain SQL joins.'), q(2, 'Explain normalization.'), q(3, 'Design a schema.')] })), 'Draft version updated.'));
    renderAt('/assessments/10/versions/2');
    await waitFor(() => expect(screen.getByTestId('version-question-list')).toBeInTheDocument());
    expect(screen.getByTestId('version-change-summary')).toHaveTextContent('Sharpen Q1');
    expect(screen.getByTestId('version-summary')).toHaveTextContent('30');
    expect(screen.getByTestId('version-analysis-summary')).toHaveTextContent('No analysis');

    fireEvent.click(screen.getByRole('button', { name: 'Edit draft' }));
    const editor = screen.getByTestId('version-question-editor');
    fireEvent.change(within(editor).getByLabelText('Question 1 text'), { target: { value: 'Explain SQL joins.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    await waitFor(() => expect(svc.updateVersion).toHaveBeenCalled());
    const payload = svc.updateVersion.mock.calls[0][1];
    expect(payload.questions).toHaveLength(3);
    expect(payload.questions[0]).toEqual(expect.objectContaining({ question_text: 'Explain SQL joins.', original_question_id: 21, marks: 10 }));
    expect(payload.title).toBe('Midterm Examination');
    await waitFor(() => expect(screen.getByTestId('version-question-list')).toHaveTextContent('Explain SQL joins.'));
    expect(screen.getByRole('status')).toHaveTextContent('Draft version updated.');
  });

  it('confirms finalization and shows the immutable state afterwards', async () => {
    svc.getVersion.mockResolvedValue(ok(detail(full())));
    svc.finalizeVersion.mockResolvedValue(ok(detail(full({ status: 'FINALIZED', is_editable: false, finalized_at: '2026-09-04T10:00:00Z', validation_status: 'VALID' })), 'Version finalized. It is now an immutable historical record.'));
    renderAt('/assessments/10/versions/1');
    await waitFor(() => expect(screen.getByTestId('version-actions')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Finalize' }));
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Finalize v1.0?'));
    await waitFor(() => expect(svc.finalizeVersion).toHaveBeenCalledWith(1));
    await waitFor(() => expect(screen.getByTestId('version-actions')).toHaveTextContent('Finalized versions are immutable.'));
    expect(screen.queryByRole('button', { name: 'Edit draft' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Finalize' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Create new version' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore as new version' })).toBeInTheDocument();
  });

  it('surfaces finalization validation errors from the server', async () => {
    svc.getVersion.mockResolvedValue(ok(detail(full())));
    svc.finalizeVersion.mockRejectedValue(new ApiError(422, 'Assessment cannot be finalized. 1 critical validation error(s) remain.'));
    renderAt('/assessments/10/versions/1');
    await waitFor(() => expect(screen.getByTestId('version-actions')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Finalize' }));
    await waitFor(() => expect(screen.getByTestId('version-error')).toHaveTextContent('1 critical validation error(s) remain.'));
  });

  it('archives with confirmation and restores an archived version as a new draft', async () => {
    svc.getVersion.mockResolvedValue(ok(detail(full({ status: 'FINALIZED', is_editable: false }))));
    svc.archiveVersion.mockResolvedValue(ok(detail(full({ status: 'ARCHIVED', is_editable: false, archived_at: '2026-09-05T10:00:00Z' })), 'Version archived.'));
    svc.restoreVersion.mockResolvedValue(ok(detail(full({ id: 6, version_number: 6, version_label: 'v6.0', change_summary: 'Restored structure from v1.0' })), 'Restored v1.0 as new draft version v6.0.'));
    renderAt('/assessments/10/versions/1');
    await waitFor(() => expect(screen.getByTestId('version-actions')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Archive v1.0?'));
    await waitFor(() => expect(svc.archiveVersion).toHaveBeenCalledWith(1));
    await waitFor(() => expect(screen.getByTestId('version-header')).toHaveTextContent('Archived'));
    fireEvent.click(screen.getByRole('button', { name: 'Restore as new version' }));
    const dialog = screen.getByTestId('version-restore-dialog');
    expect(dialog).toHaveTextContent('Restore v1.0 as a new version');
    fireEvent.change(within(dialog).getByPlaceholderText('Restored structure from v1.0'), { target: { value: 'Bring back the v1 structure' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Restore as new version' }));
    await waitFor(() => expect(svc.restoreVersion).toHaveBeenCalledWith(1, { change_summary: 'Bring back the v1 structure' }));
  });

  it('hides all mutating actions for read-only collaborators and shows locked state for submissions', async () => {
    svc.getVersion.mockResolvedValue(ok(detail(full({ has_submissions: true, is_editable: false }), readOnly)));
    renderAt('/assessments/10/versions/1');
    await waitFor(() => expect(screen.getByTestId('version-actions')).toBeInTheDocument());
    ['Edit draft', 'Finalize', 'Archive', 'Approve', 'Submit for review', 'Create new version', 'Restore as new version'].forEach((n) => expect(screen.queryByRole('button', { name: n })).toBeNull());
    expect(screen.getByRole('button', { name: 'Compare' })).toBeInTheDocument();
    expect(screen.getByTestId('version-actions')).toHaveTextContent('student submissions reference this version');
  });
});

describe('AssessmentVersionCompare page', () => {
  it('renders question, marks, blueprint and analysis diffs', async () => {
    svc.getVersions.mockResolvedValue(ok(list([v2, v1])));
    svc.compareVersions.mockResolvedValue(ok(comparison));
    renderAt('/assessments/10/versions/1/compare?with=2');
    await waitFor(() => expect(screen.getByTestId('version-comparison')).toBeInTheDocument());
    expect(svc.compareVersions).toHaveBeenCalledWith('1', '2');
    expect(screen.getByTestId('comparison-summary')).toHaveTextContent('30 → 40 (+10)');
    expect(screen.getByTestId('version-comparison')).toHaveTextContent('major change');

    const changes = screen.getByTestId('question-change-list');
    expect(within(changes).getByRole('tab', { name: 'Modified (1)' })).toBeInTheDocument();
    const q3 = screen.getByTestId('question-change-3');
    expect(q3).toHaveTextContent('MODIFIED');
    expect(q3).toHaveTextContent('Explain normalization.');
    expect(q3).toHaveTextContent('Explain 2NF and 3NF with suitable examples.');
    expect(q3).toHaveTextContent('Marks: 10→15');
    expect(q3).toHaveTextContent('Difficulty: medium→hard');
    expect(screen.getByTestId('question-change-2')).toHaveTextContent('REMOVED');
    expect(screen.getByTestId('question-change-4')).toHaveTextContent('ADDED');
    fireEvent.click(within(changes).getByRole('tab', { name: 'Added (1)' }));
    expect(screen.queryByTestId('question-change-3')).toBeNull();
    expect(screen.getByTestId('question-change-4')).toBeInTheDocument();

    const difficulty = screen.getByTestId('profile-difficulty');
    expect(difficulty).toHaveTextContent('Easy');
    expect(difficulty).toHaveTextContent('-10 pp');
    expect(difficulty).toHaveTextContent('+10 pp');
    expect(screen.getByTestId('blueprint-change-list')).toHaveTextContent('Neither version has a blueprint snapshot.');
    expect(screen.getByTestId('mappings-comparison')).toHaveTextContent('added CO2');
    expect(screen.getByTestId('analysis-comparison')).toHaveTextContent('Assessment quality');
    expect(screen.getByTestId('analysis-comparison')).toHaveTextContent('+8');
    expect(screen.getByTestId('metadata-comparison')).toHaveTextContent('Total marks');
  });

  it('prompts for a second version when none is chosen', async () => {
    svc.getVersions.mockResolvedValue(ok(list([v2, v1])));
    renderAt('/assessments/10/versions/1/compare');
    await waitFor(() => expect(screen.getByTestId('compare-prompt')).toBeInTheDocument());
    expect(svc.compareVersions).not.toHaveBeenCalled();
  });
});

describe('assessmentVersion components', () => {
  it('renders state components and maps API errors to messages', () => {
    const { rerender } = render(<VersionLoading />);
    expect(screen.getByTestId('version-loading')).toBeInTheDocument();
    rerender(<VersionError message="Boom" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    rerender(<MemoryRouter><VersionEmptyState canEdit={false} onCreate={() => undefined} questionCount={4} /></MemoryRouter>);
    expect(screen.getByTestId('version-empty-state')).toHaveTextContent('4 questions');
    expect(screen.queryByRole('button')).toBeNull();
    rerender(<VersionStatusBadge status="IN_REVIEW" />);
    expect(screen.getByTestId('version-status-badge')).toHaveTextContent('In review');
    expect(getVersionErrorMessage(new ApiError(409, 'A FINALIZED version is immutable.'))).toBe('A FINALIZED version is immutable.');
    expect(getVersionErrorMessage(new ApiError(404, ''))).toBe('The assessment or version no longer exists.');
    expect(getVersionErrorMessage(new ApiError(500, ''))).toBe('The versioning service is temporarily unavailable.');
  });

  it('builds a chronological timeline and marks the current version', () => {
    const items = toTimeline([v2, v1], 1);
    expect(items.map((i) => i.version_label)).toEqual(['v1.0', 'v2.0']);
    expect(items[0].is_current).toBe(true);
    render(<MemoryRouter><VersionTimeline items={items} assessmentId={10} /></MemoryRouter>);
    expect(screen.getByTestId('timeline-item-1')).toHaveTextContent('Current');
    expect(screen.getByTestId('timeline-item-2')).toHaveTextContent('Updated Q3 for CO2 coverage');
  });

  it('renders standalone question and blueprint change lists', () => {
    render(<><QuestionChangeList items={comparison.questions.items} fromLabel="v1.0" toLabel="v2.0" /><BlueprintChangeList blueprint={comparison.blueprint} fromLabel="v1.0" toLabel="v2.0" /></>);
    expect(screen.getByRole('tab', { name: 'All (4)' })).toBeInTheDocument();
    expect(screen.getByTestId('question-change-1')).toHaveTextContent('UNCHANGED');
    expect(screen.getByTestId('profile-structure')).toHaveTextContent('Question count');
  });
});
