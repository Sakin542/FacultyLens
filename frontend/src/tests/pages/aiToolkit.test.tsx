import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, cleanup } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AiToolkit } from '@/pages/AiToolkit';

vi.mock('@/services/aiToolkitService', () => ({
  aiToolkitService: {
    overview: vi.fn(), translate: vi.fn(), ocr: vi.fn(), transcribe: vi.fn(), job: vi.fn(), remove: vi.fn(),
    studyPack: vi.fn(), questionQuality: vi.fn(), simplify: vi.fn(), bloomRewrite: vi.fn(), feedbackDraft: vi.fn(), compareAnswers: vi.fn(), paperReview: vi.fn(), attach: vi.fn(), saveToQuestionBank: vi.fn(), speak: vi.fn(),
    downloadUrl: (id: number) => `/api/ai-toolkit/jobs/${id}/download`,
  },
}));
vi.mock('@/services/api', async (orig) => ({ ...(await orig<typeof import('@/services/api')>()), apiClient: vi.fn() }));

import { aiToolkitService } from '@/services/aiToolkitService';
import { apiClient, ApiError } from '@/services/api';
const svc = aiToolkitService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const api = apiClient as unknown as ReturnType<typeof vi.fn>;

const ALL_TOOLS = { translation: true, ocr: true, transcription: true, study_pack: true, speech: true, writing: true };
const overview = (over: Record<string, unknown> = {}) => ({
  tools: ALL_TOOLS,
  provider: 'local', limits: { translation_chars: 5000, ocr_mb: 20, transcription_mb: 100 }, jobs: [], ...over,
});
const jobRow = (over: Record<string, unknown>) => ({
  input_bytes: 2048, language: null, error: null, model: 'm', duration_seconds: null, line_count: null, preview: null, created_at: null, started_at: null, completed_at: null, ...over,
});
const detail = (over: Record<string, unknown>) => ({
  input_bytes: 2048, model: 'm', duration_seconds: null, line_count: null, text: '', segments: [], pages: [], note: null,
  summary: null, summary_method: null, key_points: [], quiz: [], quiz_method: null, quiz_rejected: null, ...over,
});

const renderPage = () => render(<MemoryRouter><AiToolkit /></MemoryRouter>);

describe('AI Toolkit page', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('translates English to Bangla without a model or disclaimer line', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.translate.mockResolvedValue({ text: 'শুভ সকাল', model: 'facebook/nllb-200-distilled-600M', provider: 'local', fallback_reason: null });
    renderPage();

    fireEvent.change(await screen.findByLabelText('English'), { target: { value: 'Good morning' } });
    fireEvent.click(screen.getByRole('button', { name: /Translate to Bangla/ }));

    expect(await screen.findByTestId('translation-output')).toHaveTextContent('শুভ সকাল');
    expect(svc.translate).toHaveBeenCalledWith('Good morning', 'en', 'bn');
    expect(screen.queryByText(/nllb-200-distilled-600M|Machine translation/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Listen' })).toBeInTheDocument();
  });

  it('swaps direction to Bangla → English', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.translate.mockResolvedValue({ text: 'Good morning', model: 'm', provider: 'hosted', fallback_reason: null });
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Swap languages' }));
    fireEvent.change(screen.getByLabelText('বাংলা (Bangla)'), { target: { value: 'শুভ সকাল' } });
    fireEvent.click(screen.getByRole('button', { name: /Translate to English/ }));
    await waitFor(() => expect(svc.translate).toHaveBeenCalledWith('শুভ সকাল', 'bn', 'en'));
    expect(await screen.findByTestId('translation-output')).toHaveTextContent('Good morning');
    expect(screen.queryByText(/Hosted model|Machine translation/)).not.toBeInTheDocument();
  });

  it('queues a handwriting OCR upload and opens a finished result', async () => {
    svc.overview.mockResolvedValueOnce(overview()).mockResolvedValue(overview({ jobs: [jobRow({ id: 7, tool: 'ocr', status: 'completed', input_filename: 'script.jpg', line_count: 2 })] }));
    svc.ocr.mockResolvedValue({ status: 'success', message: 'Handwriting OCR started.', data: {} });
    svc.job.mockResolvedValue(detail({ id: 7, tool: 'ocr', status: 'completed', input_filename: 'script.jpg', line_count: 2, text: 'Q1 BFS visits level by level', note: 'Draft transcription of handwriting.' }));
    renderPage();

    fireEvent.click(await screen.findByRole('tab', { name: /Handwriting OCR/ }));
    const file = new File(['img'], 'script.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByTestId('ocr-file'), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: /Read handwriting/ }));

    expect(await screen.findByText('Handwriting OCR started.')).toBeInTheDocument();
    expect(svc.ocr).toHaveBeenCalledWith(file);
    fireEvent.click(await screen.findByText('script.jpg'));
    expect(await screen.findByTestId('job-result')).toHaveTextContent('Q1 BFS visits level by level');
    expect(screen.getByText('Draft transcription of handwriting.')).toBeInTheDocument();
  });

  it('sends lecture key terms with a transcription and reports corrected words', async () => {
    svc.overview.mockResolvedValue(overview({ jobs: [jobRow({ id: 9, tool: 'transcription', status: 'completed', input_filename: 'lecture.mp3' })] }));
    svc.transcribe.mockResolvedValue({ status: 'success', message: 'Transcription started.', data: {} });
    svc.job.mockResolvedValue(detail({ id: 9, tool: 'transcription', status: 'completed', input_filename: 'lecture.mp3', text: 'BFS uses a queue.',
      model: 'openai/whisper-large-v3-turbo', duration_seconds: 3, corrected_lines: 1 }));
    renderPage();

    fireEvent.click(await screen.findByRole('tab', { name: /Lecture transcription/ }));
    const file = new File(['a'], 'lecture.mp3', { type: 'audio/mpeg' });
    fireEvent.change(screen.getByTestId('transcribe-file'), { target: { files: [file] } });
    fireEvent.change(screen.getByTestId('transcribe-terms'), { target: { value: 'queue, BFS' } });
    fireEvent.click(screen.getByRole('button', { name: /Transcribe recording/ }));
    await waitFor(() => expect(svc.transcribe).toHaveBeenCalledWith(file, undefined, 'queue, BFS'));

    fireEvent.click(await screen.findByText('lecture.mp3'));
    expect(await screen.findByTestId('job-result')).toHaveTextContent('1 misheard word(s) corrected');
  });

  it('says which model read the file and why the local model was used', async () => {
    svc.overview.mockResolvedValue(overview({ jobs: [jobRow({ id: 8, tool: 'ocr', status: 'completed', input_filename: 'page.jpg', line_count: 1 })] }));
    svc.job.mockResolvedValueOnce(detail({ id: 8, tool: 'ocr', status: 'completed', input_filename: 'page.jpg', line_count: 1, text: 'x',
      model: 'hosted:google/gemini-3.8-flash', provider: 'hosted', fallback_reason: null }));
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Handwriting OCR/ }));
    fireEvent.click(await screen.findByText('page.jpg'));
    expect(await screen.findByTestId('job-result')).toHaveTextContent('Hosted model · hosted:google/gemini-3.8-flash');
    expect(screen.queryByTestId('job-fallback')).not.toBeInTheDocument();

    cleanup();
    svc.job.mockResolvedValueOnce(detail({ id: 8, tool: 'ocr', status: 'completed', input_filename: 'page.jpg', line_count: 1, text: 'x',
      model: 'microsoft/trocr-base-handwritten', provider: 'local', fallback_reason: 'hosted provider returned HTTP 503' }));
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Handwriting OCR/ }));
    fireEvent.click(await screen.findByText('page.jpg'));
    expect(await screen.findByTestId('job-fallback')).toHaveTextContent('Read with the local model (hosted provider returned HTTP 503).');
  });

  it('respects tools the administrator turned off', async () => {
    svc.overview.mockResolvedValue(overview({ tools: { ...ALL_TOOLS, transcription: false } }));
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Lecture transcription/ }));
    expect(screen.getByText(/turned off by the administrator/)).toBeInTheDocument();
    expect(screen.queryByTestId('transcribe-form')).not.toBeInTheDocument();
  });

  it('shows question checks and the suggested rewrite', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.questionQuality.mockResolvedValue({ rewrites_available: true, model: 'Qwen', note: 'Checks are rule-based.', results: [
      { question: 'what is bfs and dfs explain', score: 76, bloom_level: 'REMEMBER', readability: { flesch_reading_ease: 90, grade_level: 2, words: 6 },
        issues: [{ code: 'unclear_task', severity: 'medium', message: 'Not phrased as a question or an instruction.' }],
        suggested_rewrite: 'Explain breadth-first and depth-first search.', rewrite_bloom_level: 'UNDERSTAND' },
    ] });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Question checker/ }));
    fireEvent.change(screen.getByLabelText('Questions'), { target: { value: 'what is bfs and dfs explain' } });
    fireEvent.click(screen.getByRole('button', { name: /Check 1 question/ }));
    expect(await screen.findByText('Explain breadth-first and depth-first search.')).toBeInTheDocument();
    expect(screen.getByText('76/100')).toBeInTheDocument();
    expect(svc.questionQuality).toHaveBeenCalledWith(['what is bfs and dfs explain'], true);
  });

  it('warns when a Bloom rewrite drifts off topic', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.bloomRewrite.mockResolvedValue({ original: 'Define BFS.', original_level: 'REMEMBER', target_level: 'CREATE', rewritten: 'Design a hotel booking system.',
      detected_level: 'CREATE', matches_target: true, on_topic: false, attempts: 3, model: 'Qwen', note: 'Classifier note.' });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Bloom rewriter/ }));
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Define breadth-first search.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create' }));
    fireEvent.click(screen.getByRole('button', { name: /Rewrite for Create/ }));
    expect(await screen.findByTestId('bloom-output')).toHaveTextContent('Design a hotel booking system.');
    expect(screen.getByText(/drifted away from the original topic/)).toBeInTheDocument();
    expect(svc.bloomRewrite).toHaveBeenCalledWith('Define breadth-first search.', 'CREATE');
  });

  it('shows study-pack evidence and saves only the selected, edited questions', async () => {
    svc.overview.mockResolvedValue(overview({ jobs: [jobRow({ id: 9, tool: 'study_pack', status: 'completed', input_filename: 'Graphs' })] }));
    svc.job.mockResolvedValue(detail({ id: 9, tool: 'study_pack', status: 'completed', input_filename: 'Graphs', text: 'SUMMARY',
      summary: 'BFS goes level by level.', summary_method: 'generative', key_points: ['BFS uses a queue.'], quiz_method: 'generative', quiz_rejected: 2,
      quiz: [
        { question: 'What data structure does BFS use?', answer: 'A queue.', type: 'short_answer', bloom_level: 'REMEMBER', evidence: 'BFS uses a queue.' },
        { question: 'Fill in the blank: _____ search explores level by level.', answer: 'Breadth-first', type: 'fill_in_blank', bloom_level: 'REMEMBER', evidence: 'Breadth-first search explores level by level.' },
      ] }));
    api.mockResolvedValue({ data: [{ id: 3, course_code: 'CSE220', course_name: 'Data Structures' }] });
    svc.saveToQuestionBank.mockResolvedValue({ status: 'success', message: '1 question(s) saved to the CSE220 question bank.', data: { ids: [1] } });
    renderPage();

    fireEvent.click(await screen.findByRole('tab', { name: /Notes & quiz/ }));
    fireEvent.click(await screen.findByText('Graphs'));
    expect(await screen.findByTestId('study-pack-result')).toHaveTextContent('2 AI question(s) removed: not supported by your text');
    expect(screen.getByText(/“BFS uses a queue\.”/)).toBeInTheDocument();

    fireEvent.click(screen.getByLabelText('Select question 1'));
    fireEvent.change(screen.getByLabelText('Edit question 1'), { target: { value: 'Which data structure does breadth-first search use?' } });
    await screen.findByRole('option', { name: /CSE220/ });
    fireEvent.change(screen.getByDisplayValue('Choose a course'), { target: { value: '3' } });
    fireEvent.click(screen.getByRole('button', { name: /Save 1 question/ }));
    await waitFor(() => expect(svc.saveToQuestionBank).toHaveBeenCalledWith(9, 3, [{ question: 'Which data structure does breadth-first search use?', bloom_level: 'REMEMBER' }]));
    expect(await screen.findByText(/saved to the CSE220 question bank/)).toBeInTheDocument();
  });

  it('turns a finished transcript into notes & quiz', async () => {
    svc.overview.mockResolvedValue(overview({ jobs: [jobRow({ id: 4, tool: 'transcription', status: 'completed', input_filename: 'week3.mp3', duration_seconds: 600 })] }));
    svc.job.mockResolvedValue(detail({ id: 4, tool: 'transcription', status: 'completed', input_filename: 'week3.mp3', duration_seconds: 600, text: 'Lecture text', segments: [{ start: 0, end: 5, text: 'Lecture text' }] }));
    svc.studyPack.mockResolvedValue({ status: 'success', message: 'Building notes and quiz.', data: {} });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Lecture transcription/ }));
    fireEvent.click(await screen.findByText('week3.mp3'));
    fireEvent.click(await screen.findByRole('button', { name: /Make notes & quiz from this/ }));
    await waitFor(() => expect(svc.studyPack).toHaveBeenCalledWith({ source_job_id: 4 }));
    expect(await screen.findByText('Building notes and quiz.')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Notes & quiz/ })).toHaveAttribute('aria-selected', 'true');
  });

  it("attaches reviewed OCR text and asks before replacing an existing answer", async () => {
    svc.overview.mockResolvedValue(overview({ jobs: [jobRow({ id: 7, tool: 'ocr', status: 'completed', input_filename: 'script.jpg', line_count: 1 })] }));
    svc.job.mockResolvedValue(detail({ id: 7, tool: 'ocr', status: 'completed', input_filename: 'script.jpg', line_count: 1, text: 'BFS visits level by levl', note: 'Draft' }));
    api.mockImplementation((path: string) => Promise.resolve(
      path === '/assessments' ? { data: [{ id: 5, title: 'Midterm', course: { course_code: 'CSE220' } }] }
        : path.startsWith('/assessments/5/submissions') ? { data: [{ id: 11, student: { name: 'Student One', student_identifier: '2023001' } }] }
          : { data: { questions: [{ id: 21, question_number: 1, question_text: 'Explain BFS.' }] } },
    ));
    svc.attach.mockRejectedValueOnce(new ApiError(409, 'This student already has an answer for that question.'))
      .mockResolvedValueOnce({ status: 'success', message: 'Answer text replaced.', data: {} });
    renderPage();

    fireEvent.click(await screen.findByRole('tab', { name: /Handwriting OCR/ }));
    fireEvent.click(await screen.findByText('script.jpg'));
    fireEvent.click(await screen.findByRole('button', { name: /Attach to a student's answer/ }));
    const dialog = await screen.findByTestId('attach-dialog');
    await within(dialog).findByRole('option', { name: /Midterm/ });
    const [assessmentSelect] = within(dialog).getAllByRole('combobox');
    fireEvent.change(assessmentSelect, { target: { value: '5' } });
    await within(dialog).findByRole('option', { name: /Student One/ });
    await within(dialog).findByRole('option', { name: /Explain BFS/ });
    const [, studentSelect, questionSelect] = within(dialog).getAllByRole('combobox');
    fireEvent.change(studentSelect, { target: { value: '11' } });
    fireEvent.change(questionSelect, { target: { value: '21' } });
    fireEvent.change(within(dialog).getByTestId('attach-text'), { target: { value: 'BFS visits level by level.' } });
    fireEvent.click(within(dialog).getByRole('button', { name: /Attach answer/ }));

    fireEvent.click(await within(dialog).findByRole('button', { name: /Replace existing answer text/ }));
    await waitFor(() => expect(svc.attach).toHaveBeenLastCalledWith(7, { submission_id: 11, question_id: 21, text: 'BFS visits level by level.', replace: true }));
    expect(await within(dialog).findByText('Answer text replaced.')).toBeInTheDocument();
  });

  it('drafts editable, marks-free feedback from pasted rubric criteria', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.feedbackDraft.mockResolvedValue({
      feedback: 'Good start: you explained the level-by-level order. To improve, cover shortest paths.', template_feedback: '', tone: 'neutral',
      improvements: ['Add: shortest paths'], next_step: 'Add a sentence on shortest paths.', method: 'rubric_template', model: null, note: 'Draft feedback only.',
      criteria: [
        { criterion: 'Traversal order', coverage: 'Addressed well', coverage_level: 'STRONG', evidence: ['BFS visits level by level.'], missing: [] },
        { criterion: 'Shortest path', coverage: 'Not addressed', coverage_level: 'NOT_ADDRESSED', evidence: [], missing: ['shortest paths'] },
      ],
    });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Feedback drafter/ }));

    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Explain BFS.' } });
    fireEvent.change(screen.getByLabelText('Rubric criteria'), { target: { value: 'Traversal order: level by level; uses a queue\n\nShortest path' } });
    fireEvent.change(screen.getByLabelText('Student answer'), { target: { value: 'BFS visits level by level.' } });
    fireEvent.change(screen.getByLabelText('Tone'), { target: { value: 'neutral' } });
    fireEvent.click(screen.getByRole('button', { name: /Draft feedback/ }));

    await waitFor(() => expect(svc.feedbackDraft).toHaveBeenCalledWith({
      question: 'Explain BFS.', answer: 'BFS visits level by level.', tone: 'neutral',
      criteria: [{ criterion: 'Traversal order', indicators: ['level by level', 'uses a queue'] }, { criterion: 'Shortest path', indicators: [] }],
    }));
    const out = await screen.findByTestId('feedback-output');
    expect(out).toHaveValue('Good start: you explained the level-by-level order. To improve, cover shortest paths.');
    fireEvent.change(out, { target: { value: 'Edited by me.' } });
    expect(out).toHaveValue('Edited by me.');
    expect(screen.getByText('What this draft is based on')).toBeInTheDocument();
    expect(screen.getByText('Not addressed')).toBeInTheDocument();
    expect(screen.queryByText(/marks? \d/i)).not.toBeInTheDocument();
  });

  it('reviews a pasted paper: parses marks and shows Bloom balance, coverage and duplicates', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.paperReview.mockResolvedValue({
      questions: [
        { number: '1', text: 'Explain how breadth-first search works.', marks: 10, bloom_level: 'UNDERSTAND', bloom_source: 'detected', band: 'lower', issues: [] },
        { number: '2', text: 'Describe how BFS traverses a graph.', marks: 10, bloom_level: 'UNDERSTAND', bloom_source: 'detected', band: 'lower', issues: [] },
      ],
      total_marks: 20, weighted_by: 'marks',
      bloom: {
        levels: {}, missing_levels: ['EVALUATE', 'CREATE'],
        bands: {
          lower: { label: 'Remember / Understand', share: 100, target: [20, 50], status: 'high' },
          middle: { label: 'Apply / Analyze', share: 0, target: [30, 60], status: 'low' },
          higher: { label: 'Evaluate / Create', share: 0, target: [10, 30], status: 'low' },
        },
      },
      duplicates: [{ a: '1', b: '2', similarity: 0.61, kind: 'overlap' }],
      topics: [{ topic: 'Graph traversal', covered: true, questions: ['2', '1'], share: 100 }, { topic: 'Hashing', covered: false, questions: [], share: 0 }],
      findings: [{ severity: 'high', message: '100% of the marks test Remember / Understand (target 20–50%).' }, { severity: 'high', message: 'Not covered by any question: Hashing.' }],
      similarity_method: 'semantic', topics_source: 'pasted', note: 'A checklist for the examiner.',
    });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Paper review/ }));

    fireEvent.change(screen.getByLabelText('Questions'), { target: { value: '1. Explain how breadth-first search works. [10]\nDescribe how BFS traverses a graph. (10 marks)\nDefine a graph.' } });
    fireEvent.change(screen.getByLabelText(/Topics or outcomes/), { target: { value: 'Graph traversal\nHashing\n' } });
    fireEvent.click(screen.getByRole('button', { name: /Review paper/ }));

    await waitFor(() => expect(svc.paperReview).toHaveBeenCalledWith({
      questions: [
        { text: '1. Explain how breadth-first search works.', marks: 10 },
        { text: 'Describe how BFS traverses a graph.', marks: 10 },
        { text: 'Define a graph.', marks: null },
      ],
      topics: ['Graph traversal', 'Hashing'],
    }));
    const result = screen.getByTestId('paper-review-result');
    expect(await within(result).findByText('Not covered by any question: Hashing.')).toBeInTheDocument();
    expect(within(result).getByText(/100% of the marks test Remember/)).toBeInTheDocument();
    expect(within(result).getByText('Overlaps with Q2')).toBeInTheDocument();
    expect(within(result).getByText('not covered')).toBeInTheDocument();
  });

  it('reviews a stored assessment chosen from the list', async () => {
    svc.overview.mockResolvedValue(overview());
    api.mockResolvedValue({ data: [{ id: 4, title: 'Midterm', course: { course_code: 'CSE220' } }] });
    svc.paperReview.mockResolvedValue({
      questions: [], total_marks: null, weighted_by: 'marks', duplicates: [], topics: null, findings: [], similarity_method: 'semantic', note: '',
      bloom: { levels: {}, missing_levels: [], bands: {
        lower: { label: 'Remember / Understand', share: 40, target: [20, 50], status: 'ok' },
        middle: { label: 'Apply / Analyze', share: 40, target: [30, 60], status: 'ok' },
        higher: { label: 'Evaluate / Create', share: 20, target: [10, 30], status: 'ok' },
      } },
    });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Paper review/ }));
    fireEvent.click(screen.getByRole('radio', { name: 'From an assessment' }));
    fireEvent.change(await screen.findByLabelText('Assessment'), { target: { value: '4' } });
    expect(screen.getByRole('option', { name: 'CSE220 · Midterm' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Review paper/ }));

    await waitFor(() => expect(svc.paperReview).toHaveBeenCalledWith({ assessment_id: 4 }));
    expect(await screen.findByText(/No problems found/)).toBeInTheDocument();
  });

  it('compares pasted answers with labels and highlights flagged pairs', async () => {
    svc.overview.mockResolvedValue(overview());
    svc.compareAnswers.mockResolvedValue({
      method: 'lexical', semantic_unavailable: true, threshold: 0.6, answers_compared: 3, too_short: [], min_words: 8,
      pairs_compared: 3, pairs_flagged: 1, note: 'High similarity is a reason to look closer, not proof of copying.',
      pairs: [
        { a: 'Rahim', b: 'Karim', lexical_score: 0.92, semantic_score: null, combined_score: 0.92, flagged: true, shared_excerpt: 'an index keeps a sorted structure' },
        { a: 'Rahim', b: 'Answer 3', lexical_score: 0.05, semantic_score: null, combined_score: 0.05, flagged: false, shared_excerpt: null },
      ],
    });
    renderPage();
    fireEvent.click(await screen.findByRole('tab', { name: /Compare answers/ }));

    fireEvent.change(screen.getByLabelText('Answers'), { target: { value: 'Rahim:\nAn index keeps a sorted structure.\n---\nKarim:\nIn short, an index keeps a sorted structure.\n---\nIndexes reduce disk reads.' } });
    expect(screen.getByText('3 answers (2–40)')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Compare/ }));

    await waitFor(() => expect(svc.compareAnswers).toHaveBeenCalledWith([
      { label: 'Rahim', text: 'An index keeps a sorted structure.' },
      { label: 'Karim', text: 'In short, an index keeps a sorted structure.' },
      { text: 'Indexes reduce disk reads.' },
    ], '', 0.6));
    const result = screen.getByTestId('compare-result');
    expect(await within(result).findByText('Rahim ↔ Karim')).toBeInTheDocument();
    expect(within(result).getByText('92% · review')).toBeInTheDocument();
    expect(within(result).getByText(/only shared wording was compared/)).toBeInTheDocument();
    expect(within(result).queryByText('Rahim ↔ Answer 3')).not.toBeInTheDocument(); // low pairs are hidden
  });
});
