import { expect, type Page, type APIResponse } from '@playwright/test';

/**
 * Thin API helper that shares the browser context's cookie jar (page.request), so anything created or
 * authenticated here is immediately visible to the UI under test. Used for fixtures only — the behaviour
 * under test is always exercised through the UI.
 */
export const API_URL = process.env.E2E_API_URL || 'http://127.0.0.1:8080';
export const PASSWORD = 'E2E-Journey#2026';

export const uniqueEmail = (prefix: string) => `${prefix}.${Date.now()}.${Math.floor(Math.random() * 1e4)}@university.edu`;

export class Api {
  constructor(private readonly page: Page) {}

  private origin(): string {
    return new URL(this.page.url() === 'about:blank' ? (process.env.E2E_BASE_URL || 'http://127.0.0.1:4173') : this.page.url()).origin;
  }

  private async xsrf(): Promise<string> {
    const cookies = await this.page.context().cookies(API_URL);
    let token = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value;
    if (!token) {
      await this.page.request.get(`${API_URL}/sanctum/csrf-cookie`, { headers: { Accept: 'application/json', Origin: this.origin() } });
      token = (await this.page.context().cookies(API_URL)).find((c) => c.name === 'XSRF-TOKEN')?.value;
    }
    if (!token) throw new Error('Sanctum CSRF cookie was not issued — is the backend running on ' + API_URL + '?');
    return decodeURIComponent(token);
  }

  async call(method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data?: unknown, expected: number[] = [200, 201, 202]): Promise<any> {
    const headers: Record<string, string> = { Accept: 'application/json', Origin: this.origin(), Referer: this.origin() + '/' };
    if (method !== 'GET') headers['X-XSRF-TOKEN'] = await this.xsrf();
    const res: APIResponse = await this.page.request.fetch(`${API_URL}/api${path}`, { method, headers, data });
    if (!expected.includes(res.status())) {
      throw new Error(`${method} ${path} → ${res.status()}: ${(await res.text()).slice(0, 500)}`);
    }
    const text = await res.text();
    return text ? JSON.parse(text) : {};
  }

  /** Registers a fresh faculty account and leaves the browser context authenticated. */
  async registerAndLogin(prefix = 'e2e', name = 'Dr. E2E Faculty'): Promise<{ email: string; id: number }> {
    const email = uniqueEmail(prefix);
    const reg = await this.call('POST', '/auth/register', { name, email, department: 'CSE', designation: 'Assistant Professor', password: PASSWORD, password_confirmation: PASSWORD }, [201]);
    const me = await this.call('GET', '/auth/user');
    return { email, id: me.user?.id ?? reg.user?.id };
  }

  async logout(): Promise<void> {
    await this.call('POST', '/auth/logout', {}, [200, 204, 401]);
  }

  async createCourse(overrides: Record<string, unknown> = {}): Promise<any> {
    const stamp = Date.now().toString().slice(-6);
    const res = await this.call('POST', '/courses', { course_code: `CSE-${stamp}`, course_name: 'Artificial Intelligence', semester: 'Fall', academic_year: '2026', credits: 3, status: 'active', description: 'Search, knowledge representation and learning.', ...overrides }, [201]);
    return res.data;
  }

  async createLearningOutcomes(courseId: number): Promise<any[]> {
    const spec = [
      ['LO1', 'Explain uninformed and informed search strategies.', 'Understand'],
      ['LO2', 'Apply knowledge representation techniques to model a domain.', 'Apply'],
      ['LO3', 'Evaluate machine learning models using appropriate metrics.', 'Evaluate'],
    ];
    const out: any[] = [];
    for (const [i, [code, description, cognitive_level]] of spec.entries()) {
      out.push((await this.call('POST', `/courses/${courseId}/learning-outcomes`, { code, description, cognitive_level, sort_order: i + 1 }, [201])).data);
    }
    return out;
  }

  async createAssessment(courseId: number, overrides: Record<string, unknown> = {}): Promise<any> {
    return (await this.call('POST', `/courses/${courseId}/assessments`, { title: 'Midterm Examination', type: 'midterm', total_marks: 30, duration_minutes: 90, status: 'draft', assessment_date: '2026-10-15', ...overrides }, [201])).data;
  }

  /**
   * FacultyLens has no manual question CRUD: official questions enter through AI generation → faculty approval →
   * "add to assessment". This helper walks that real path (live AI service) and returns the official question ids.
   */
  async generateAndAddQuestions(courseId: number, assessmentId: number, learningOutcomeId: number, count = 3, marks = 10): Promise<number[]> {
    const gen = await this.call('POST', '/question-generation', {
      course_id: courseId, assessment_id: assessmentId, topic: 'Search algorithms', learning_outcome_id: learningOutcomeId,
      question_type: 'descriptive', difficulty_level: 'medium', cognitive_level: 'Understand', marks, number_of_questions: count, include_expected_answer: true,
    }, [200, 201, 202]);
    const requestId = gen.data.id;
    let detail = gen;
    for (let i = 0; i < 60 && detail.data.generation_status !== 'COMPLETED'; i++) {
      if (detail.data.generation_status === 'FAILED') throw new Error('Question generation failed: ' + detail.data.error_message);
      await this.page.waitForTimeout(1000);
      detail = await this.call('GET', `/question-generation/${requestId}`);
    }
    expect(detail.data.generation_status, 'AI question generation must complete').toBe('COMPLETED');
    const ids: number[] = [];
    for (const q of detail.data.questions.slice(0, count)) {
      await this.call('POST', `/generated-questions/${q.id}/approve`, { note: 'E2E approved' });
      const added = await this.call('POST', `/generated-questions/${q.id}/add-to-assessment`, {}, [201]);
      ids.push(added.data.question.id);
    }
    return ids;
  }

  async createStudent(identifier: string, name: string): Promise<any> {
    return (await this.call('POST', '/students', { student_identifier: identifier, name, section: 'A' }, [201])).data;
  }

  async createSubmissionWithAnswers(assessmentId: number, studentId: number, questionIds: number[]): Promise<{ id: number; answerIds: number[] }> {
    const sub = (await this.call('POST', `/assessments/${assessmentId}/submissions`, { student_id: studentId }, [201])).data;
    const answerIds: number[] = [];
    for (const [i, qid] of questionIds.entries()) {
      const ans = await this.call('POST', `/submissions/${sub.id}/answers`, { question_id: qid, answer_text: `Breadth-first search explores level by level and is complete; depth-first search uses less memory but may not terminate. (answer ${i + 1})` }, [201]);
      answerIds.push(ans.data.id);
    }
    return { id: sub.id, answerIds };
  }

  async runAnalysis(assessmentId: number): Promise<any> {
    return this.call('POST', '/ai/analyze-assessment', { assessment_id: assessmentId });
  }
}

/** Login through the real form; leaves the browser on /dashboard. */
export async function loginViaUi(page: Page, email: string, password = PASSWORD): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('University Email').fill(email);
  await page.locator('input#password').fill(password);
  await page.locator('form').getByRole('button', { name: 'Sign In' }).click();
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.getByTestId('dashboard-page')).toBeVisible();
}

/** Fails the test if any page-level error surface (error boundary / raw stack) is rendered. */
export async function expectNoCrash(page: Page): Promise<void> {
  const body = (await page.locator('body').innerText()).slice(0, 20000);
  for (const needle of ['Something went wrong', 'TypeError', 'ReferenceError', 'Unhandled', 'at Object.', 'Traceback', 'Stack trace', 'SQLSTATE']) {
    expect(body, `page must not show "${needle}"`).not.toContain(needle);
  }
}
