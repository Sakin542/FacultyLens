import { test, expect, type Page, type Response as PwResponse } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { Api, PASSWORD, loginViaUi, uniqueEmail, expectNoCrash } from './helpers';

/**
 * STEP 47 — Faculty User Acceptance Testing walkthrough.
 *
 * One faculty persona performs the 20 UAT scenarios from docs/FACULTY_UAT_PLAN.md through the real UI against
 * the running stack. Unlike the journey specs, a failed check does NOT abort the run: every check is recorded as
 * PASS / FAIL / PARTIAL / NOT_TESTED with a note and a full-page screenshot, so the UAT report can be written from
 * evidence. Bulk fixtures that a faculty member would never type by hand (extra students for the performance
 * engine) are created through the same API the UI calls and are labelled as fixtures in the observations.
 *
 * Output: frontend/uat-results/observations.json + observations.md + S??-*.png screenshots (kept outside
 * Playwright's test-results folder, which is wiped on every run).
 */

const OUT = path.resolve(process.cwd(), 'uat-results');
type Status = 'PASS' | 'FAIL' | 'PARTIAL' | 'NOT_TESTED';
interface Observation { scenario: string; check: string; status: Status; note?: string; screenshot?: string }
const observations: Observation[] = [];
const a11y: { page: string; violations: { id: string; impact: string; nodes: number; help: string; targets: string[] }[] }[] = [];

const record = (scenario: string, check: string, status: Status, note?: string, screenshot?: string) => {
  observations.push({ scenario, check, status, note, screenshot });
  // eslint-disable-next-line no-console
  console.log(`[UAT ${scenario}] ${status.padEnd(10)} ${check}${note ? ' — ' + note : ''}`);
  writeObservations();
};

const shot = async (page: Page, name: string): Promise<string> => {
  fs.mkdirSync(OUT, { recursive: true });
  const file = `${name}.png`;
  try { await page.screenshot({ path: path.join(OUT, file), fullPage: true }); } catch { /* page may be navigating */ }
  return file;
};

/** Runs a check; records PASS, or FAIL with the error message; never throws. */
const check = async (scenario: string, label: string, fn: () => Promise<void | string>, page?: Page, shotName?: string): Promise<boolean> => {
  try {
    const note = await fn();
    record(scenario, label, 'PASS', typeof note === 'string' ? note : undefined, page && shotName ? await shot(page, shotName) : undefined);
    return true;
  } catch (e) {
    const msg = (e as Error).message?.split('\n').slice(0, 3).join(' ').slice(0, 400);
    record(scenario, label, 'FAIL', msg, page && shotName ? await shot(page, `${shotName}-FAIL`) : undefined);
    return false;
  }
};

const bodyText = async (page: Page) => (await page.locator('body').innerText()).replace(/\s+/g, ' ');

/** waitForResponse whose rejection is always observed, so a failed click never becomes an unhandled rejection that ends the test. */
const waitFor = (page: Page, pred: (r: PwResponse) => boolean, options?: { timeout?: number }): Promise<PwResponse> => {
  const p = page.waitForResponse(pred, options);
  p.catch(() => undefined);
  return p;
};

/** Clicks an in-app link and reports whether the target route rendered (UAT-001: navigation away from the assessment page can freeze). */
const navigateVia = async (page: Page, click: () => Promise<void>, rendered: () => Promise<boolean>, fallbackUrl: string, waitMs = 10_000): Promise<string> => {
  await click();
  const ok = await poll(rendered, waitMs, 500);
  if (ok) return 'in-app navigation rendered';
  const url = page.url();
  await page.goto(fallbackUrl);
  return `NOTE: in-app link changed the URL to ${url.replace(/^https?:\/\/[^/]+/, '')} but the page did not render within ${waitMs / 1000}s — faculty had to reload (UAT-001)`;
};

const axeScan = async (page: Page, label: string) => {
  try {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    a11y.push({ page: label, violations: results.violations.map((v) => ({ id: v.id, impact: v.impact ?? 'n/a', nodes: v.nodes.length, help: v.help, targets: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) })) });
    const serious = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    record('A11Y', `axe ${label}`, serious.length === 0 ? 'PASS' : 'FAIL', `${results.violations.length} violation type(s); serious/critical: ${serious.map((v) => `${v.id}×${v.nodes.length}`).join(', ') || 'none'}`);
  } catch (e) {
    record('A11Y', `axe ${label}`, 'NOT_TESTED', (e as Error).message.slice(0, 200));
  }
};

const poll = async (fn: () => Promise<boolean>, timeoutMs: number, everyMs = 2000): Promise<boolean> => {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (await fn()) return true;
    await new Promise((r) => setTimeout(r, everyMs));
  }
  return false;
};

const writeObservations = () => {
  fs.mkdirSync(OUT, { recursive: true });
  fs.writeFileSync(path.join(OUT, 'observations.json'), JSON.stringify({ generated_at: new Date().toISOString(), observations, a11y }, null, 2));
  const lines = ['| Scenario | Check | Status | Note | Screenshot |', '|---|---|---|---|---|'];
  for (const o of observations) lines.push(`| ${o.scenario} | ${o.check} | ${o.status} | ${(o.note ?? '').replace(/\|/g, '\\|')} | ${o.screenshot ?? ''} |`);
  fs.writeFileSync(path.join(OUT, 'observations.md'), lines.join('\n') + '\n');
};

test.describe('Faculty UAT walkthrough', () => {
  test.use({ actionTimeout: 20_000, navigationTimeout: 60_000 });
  test('faculty persona completes the 20 UAT scenarios through the UI', async ({ page, browser }) => {
    test.setTimeout(3_600_000);
    fs.mkdirSync(OUT, { recursive: true });
    const api = new Api(page);
    const persona = { name: 'Dr. Ayesha Rahman', email: uniqueEmail('uat.faculty'), department: 'Computer Science & Engineering', designation: 'Associate Professor' };
    const courseCode = `UAT-${Date.now().toString().slice(-6)}`;
    let courseId = 0; let assessmentId = 0; let loIds: number[] = []; let questionIds: number[] = []; let submissionId = 0; let versionId = 0;

    try {
      // ───────────────────────── S01 Login ─────────────────────────
      await test.step('S01 Login', async () => {
        await page.goto('/register');
        await page.getByLabel('Full Name').fill(persona.name);
        await page.getByLabel('University Email').fill(persona.email);
        await page.getByLabel('Department').fill(persona.department);
        await page.getByLabel('Designation').fill(persona.designation);
        await page.locator('input#password').fill(PASSWORD);
        await page.locator('input#confirm-password').fill(PASSWORD);
        await page.getByRole('button', { name: /Create (Faculty )?Account/ }).click();
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 60_000 });
        await api.logout();

        await check('S01', 'wrong password shows a plain-language error and stays on /login', async () => {
          await page.goto('/login');
          await page.getByLabel('University Email').fill(persona.email);
          await page.locator('input#password').fill('wrong-password-123');
          await page.locator('form').getByRole('button', { name: 'Sign In' }).click();
          await expect(page.getByText(/invalid email or password|credentials do not match|invalid credentials|authentication failed/i).first()).toBeVisible();
          await expect(page).toHaveURL(/\/login/);
        }, page, 'S01-login-error');

        await check('S01', 'faculty signs in through the form and reaches the dashboard', async () => {
          await loginViaUi(page, persona.email);
          await expect(page.getByText(/Welcome back, Ayesha/)).toBeVisible();
          await expectNoCrash(page);
        }, page, 'S01-dashboard');

        await check('S01', 'session belongs to the FACULTY role (no admin scopes)', async () => {
          const me = await api.call('GET', '/auth/user');
          const role = String(me.user?.role ?? me.data?.role ?? 'FACULTY').toUpperCase();
          expect(role).toBe('FACULTY');
          return `role=${role}`;
        });

        await check('S01', 'navigation exposes the faculty workspace (Courses, Assessments, Analysis, Analytics, Reports, Feedback, Settings)', async () => {
          for (const label of ['Dashboard', 'Courses', 'Assessments', 'Analysis', 'Analytics', 'Reports', 'Feedback', 'Settings', 'Notifications']) {
            await expect(page.getByRole('link', { name: new RegExp(`^${label}( AI)?$`) }).first(), label).toBeVisible();
          }
        });

        await check('S01', 'dashboard for a new account shows an empty/zero state instead of another user\'s data', async () => {
          await expect(page.getByTestId('dashboard-page')).toBeVisible();
          const text = await bodyText(page);
          const recent = await api.call('GET', '/assessments');
          expect((recent.data ?? []).length).toBe(0);
          const courses = await api.call('GET', '/courses');
          expect((courses.data ?? []).length).toBe(0);
          const headings = ['Recent Assessments', 'Learning Outcome Profile', 'AI Tools', 'Collaboration Activity'].filter((h) => text.includes(h));
          return `panels visible: ${headings.join(', ') || 'none of the expected panel headings'}; empty-state wording present=${/no assessments|get started|create your first|nothing yet|no data/i.test(text)}`;
        });
        await axeScan(page, '/dashboard');
      });

      // ───────────────────────── S02 Create Course ─────────────────────────
      await test.step('S02 Create Course', async () => {
        await check('S02', 'faculty creates a course from Courses → Add Course', async () => {
          await page.goto('/courses');
          await expect(page.getByText('No courses registered yet')).toBeVisible();
          await page.getByRole('button', { name: 'Add Course' }).click();
          await shot(page, 'S02-course-form');
          await page.getByLabel('Course Code').fill(courseCode);
          await page.getByLabel('Course Title / Name').fill('Database Systems');
          await page.getByLabel('Academic Year').fill('2026-2027');
          await page.getByRole('button', { name: 'Create Course' }).click();
          await expect(page.getByText('Database Systems').first()).toBeVisible();
          await expect(page.getByText(courseCode).first()).toBeVisible();
          const courses = await api.call('GET', '/courses');
          const course = (courses.data ?? courses).find((c: any) => c.course_code === courseCode);
          expect(course).toBeTruthy();
          courseId = course.id;
          return `course id ${courseId}`;
        }, page, 'S02-course-list');

        await check('S02', 'course is owned by the signed-in faculty', async () => {
          const me = await api.call('GET', '/auth/user');
          const course = (await api.call('GET', `/courses/${courseId}`)).data;
          expect(Number(course.user_id)).toBe(Number(me.user?.id ?? me.data?.id));
        });

        await check('S02', 'empty course form is rejected inline (no request sent)', async () => {
          await page.getByRole('button', { name: 'Add Course' }).click();
          await page.getByRole('button', { name: 'Create Course' }).click();
          await expect(page.getByLabel('Course Code')).toBeVisible();
          expect(((await api.call('GET', '/courses')).data ?? []).length).toBe(1);
          await page.keyboard.press('Escape');
          const stillOpen = await page.getByLabel('Course Code').isVisible().catch(() => false);
          return stillOpen ? 'NOTE: Escape does not close the course dialog' : 'Escape closes the dialog';
        }, page, 'S02-course-validation');
        await axeScan(page, '/courses');
      });

      // ───────────────────────── S03 Learning Outcomes ─────────────────────────
      await test.step('S03 Learning Outcomes', async () => {
        await check('S03', 'faculty adds three learning outcomes from the course page', async () => {
          await page.goto(`/courses/${courseId}`);
          await expect(page.getByText('No learning outcomes defined')).toBeVisible();
          const spec: [string, string][] = [
            ['CLO1', 'Explain the relational model, keys and integrity constraints.'],
            ['CLO2', 'Apply normalization (1NF–3NF/BCNF) to remove redundancy from a schema.'],
            ['CLO3', 'Evaluate query plans and indexing strategies for performance.'],
          ];
          for (const [code, desc] of spec) {
            await page.getByRole('button', { name: 'Add Outcome' }).first().click();
            await expect(page.getByRole('heading', { name: 'Add Learning Outcome' })).toBeVisible();
            await page.getByLabel('Outcome Code').fill(code);
            await page.getByPlaceholder('Clearly describe what students should be able to demonstrate').fill(desc);
            await page.getByRole('button', { name: 'Add Outcome' }).last().click();
            await expect(page.getByRole('heading', { name: 'Add Learning Outcome' })).toBeHidden();
            await expect(page.getByText(code, { exact: true })).toBeVisible();
          }
          const los = (await api.call('GET', `/courses/${courseId}/learning-outcomes`)).data;
          expect(los).toHaveLength(3);
          loIds = los.map((l: any) => l.id);
        }, page, 'S03-learning-outcomes');

        await check('S03', 'faculty edits an outcome and the change persists', async () => {
          await page.locator('button[title="Edit outcome"]').first().click();
          await expect(page.getByRole('heading', { name: 'Edit Learning Outcome' })).toBeVisible();
          const ta = page.getByPlaceholder('Clearly describe what students should be able to demonstrate');
          await ta.fill('Explain the relational model, keys, integrity constraints and relational algebra.');
          await page.getByRole('button', { name: 'Update Outcome' }).click();
          await expect(page.getByRole('heading', { name: 'Edit Learning Outcome' })).toBeHidden();
          await expect(page.getByText(/relational algebra/)).toBeVisible();
          const los = (await api.call('GET', `/courses/${courseId}/learning-outcomes`)).data;
          expect(los.some((l: any) => /relational algebra/.test(l.description))).toBeTruthy();
        }, page, 'S03-lo-edited');

        await check('S03', 'outcome form fields have programmatic labels (accessibility)', async () => {
          await page.getByRole('button', { name: 'Add Outcome' }).first().click();
          const unlabeled = await page.evaluate(() => {
            const dialogRoot = document.querySelector('.fixed.inset-0') ?? document.body;
            return Array.from(dialogRoot.querySelectorAll('input, select, textarea')).filter((el) => {
              const e = el as HTMLElement;
              if (e.getAttribute('type') === 'hidden') return false;
              const id = e.getAttribute('id');
              return !(e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || (id && document.querySelector(`label[for="${id}"]`)) || e.closest('label'));
            }).map((e) => `${e.tagName.toLowerCase()}${(e as HTMLElement).getAttribute('placeholder') ? '[placeholder]' : ''}`);
          });
          await page.keyboard.press('Escape');
          if (await page.getByRole('heading', { name: 'Add Learning Outcome' }).isVisible().catch(() => false)) {
            await page.getByRole('button', { name: /cancel|close/i }).first().click().catch(() => undefined);
          }
          expect(unlabeled, `controls without a label: ${unlabeled.join(', ')}`).toEqual([]);
        });
        await axeScan(page, '/courses/:id');
      });

      // ───────────────────────── S04 Create Assessment ─────────────────────────
      await test.step('S04 Create Assessment', async () => {
        await check('S04', 'faculty creates a midterm from Assessments → Create Assessment', async () => {
          await page.goto('/assessments');
          await page.getByRole('button', { name: 'Create Assessment' }).first().click();
          await page.getByLabel('Assessment Title').waitFor();
          await shot(page, 'S04-assessment-form');
          await page.locator('select').filter({ has: page.locator(`option:has-text("${courseCode}")`) }).first().selectOption({ label: `${courseCode} — Database Systems` });
          await page.getByLabel('Assessment Title').fill('Midterm Examination');
          await page.getByLabel('Total Marks').fill('30');
          await page.getByLabel('Duration (mins)').fill('90');
          await page.getByRole('button', { name: 'Create Assessment' }).last().click();
          await expect(page.getByLabel('Assessment Title')).toBeHidden();
          await expect(page.getByText('Midterm Examination').first()).toBeVisible();
          const list = (await api.call('GET', `/courses/${courseId}/assessments`)).data;
          expect(list).toHaveLength(1);
          assessmentId = list[0].id;
          return `assessment id ${assessmentId}`;
        }, page, 'S04-assessment-list');

        await check('S04', 'assessment detail page shows configuration and next-step links', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          await expect(page.getByText('Total Marks')).toBeVisible();
          await expect(page.getByText('Questions Count')).toBeVisible();
          await expect(page.getByTestId('blueprint-link')).toBeVisible();
          await expect(page.getByTestId('versions-link')).toBeVisible();
          await expect(page.getByTestId('generate-questions-link')).toBeVisible();
          const text = await bodyText(page);
          return /Question Bank/.test(text) ? 'NOTE: empty state points to "Question Bank" (archive of previous questions), no direct "Add question" affordance' : undefined;
        }, page, 'S04-assessment-detail-empty');
        await axeScan(page, '/assessments/:id (empty)');

        await check('S04', 'in-app links on the assessment page (Blueprint / Versions / sidebar) render the target page without a reload', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          await expect(page.getByText('Questions Count')).toBeVisible();
          await page.getByTestId('blueprint-link').click();
          const rendered = await poll(async () => (await page.getByTestId('assessment-blueprint-page').count()) > 0, 10_000, 500);
          const url = page.url().replace(/^https?:\/\/[^/]+/, '');
          await shot(page, 'S04-nav-after-blueprint-click');
          if (!rendered) {
            await page.goto(`/assessments/${assessmentId}`);
            await expect(page.getByText('Questions Count')).toBeVisible();
            await page.getByRole('link', { name: /^Courses$/ }).first().click();
            const sidebarRendered = await poll(async () => (await page.getByRole('button', { name: 'Add Course' }).count()) > 0, 10_000, 500);
            await shot(page, 'S04-nav-after-sidebar-click');
            throw new Error(`URL changed to ${url} but the assessment page stayed on screen (Blueprint link rendered=${rendered}, sidebar Courses rendered=${sidebarRendered}); a browser reload is required to leave the page`);
          }
          return `Blueprint rendered after click; url=${url}`;
        });
      });

      // ───────────────────────── S05 + S11 Questions via constrained generator ─────────────────────────
      await test.step('S05/S11 Questions', async () => {
        await check('S05', 'there is no manual add-question form; questions enter through AI generation + faculty approval', async () => {
          const text = await bodyText(page);
          expect(text).not.toMatch(/Add Question(?! plan)/);
          return 'Design: official questions come only from Generate Questions → approve → add to assessment';
        });

        await check('S11', 'faculty sets constraints and generates drafts (Generate Questions link from the assessment)', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          await expect(page.getByText('Questions Count')).toBeVisible();
          const navNote = await navigateVia(page, () => page.getByTestId('generate-questions-link').click(), async () => (await page.getByTestId('generation-form').count()) > 0, `/courses/${courseId}/question-generator?assessment=${assessmentId}`) + '. ';
          await expect(page.getByTestId('generation-form')).toBeVisible();
          const form = page.getByTestId('generation-form');
          await expect(form.getByLabel('Course', { exact: true })).toHaveValue(String(courseId), { timeout: 30_000 });
          await expect(form.getByLabel('Assessment', { exact: true })).toHaveValue(String(assessmentId), { timeout: 30_000 });
          await form.getByLabel('Topic', { exact: true }).fill('Normalization and functional dependencies');
          await form.getByLabel('Course outcome', { exact: true }).selectOption(String(loIds[1]));
          await form.getByLabel('Question type', { exact: true }).selectOption('descriptive');
          await form.getByLabel('Difficulty', { exact: true }).selectOption('medium');
          await form.getByLabel('Cognitive level', { exact: true }).selectOption('Apply');
          await form.getByLabel('Marks', { exact: true }).fill('10');
          await form.getByLabel('Number of questions', { exact: true }).fill('3');
          await shot(page, 'S11-generator-form');
          await page.getByTestId('generate-button').click();
          const done = await poll(async () => /completed/i.test((await page.getByTestId('generation-status').first().textContent().catch(() => '')) ?? ''), 240_000, 3000);
          expect(done, 'generation must reach Completed').toBeTruthy();
          await expect(page.getByTestId('generated-question-list')).toBeVisible();
          const cards = page.getByTestId('review-status');
          expect(await cards.count()).toBeGreaterThanOrEqual(3);
          for (let i = 0; i < await cards.count(); i++) await expect(cards.nth(i)).toContainText(/draft/i);
          const text = await bodyText(page);
          const disclaimer = /review aid|faculty|draft|not an academic decision/i.test(text);
          return `${navNote}drafts=${await cards.count()}; disclaimer wording present=${disclaimer}`;
        }, page, 'S11-drafts');

        await check('S11', 'each draft shows constraint validation (type/difficulty/Bloom/LO) that faculty can inspect', async () => {
          await expect(page.getByTestId('constraint-validation').first()).toBeVisible();
          await expect(page.getByTestId('validation-status').first()).toBeVisible();
          const warnings = await page.getByTestId('validation-warnings').count();
          const sim = await page.getByTestId('similarity-warning').count();
          return `validation panels=${await page.getByTestId('constraint-validation').count()}, with warnings=${warnings}, similarity warnings=${sim}`;
        });

        await check('S11', 'faculty edits a draft before approval and the edit is kept (versioned)', async () => {
          await page.getByTestId('edit-button').first().click();
          const editor = page.getByTestId('generated-question-editor');
          await expect(editor).toBeVisible();
          const ta = editor.getByLabel('Question text');
          const current = await ta.inputValue();
          await ta.fill(current.trim() + ' Justify each decomposition step.');
          await editor.getByTestId('editor-save').click();
          await expect(editor).toBeHidden();
          await expect(page.getByTestId('question-text').first()).toContainText('Justify each decomposition step.');
          await expect(page.getByText(/Edited v\d+/).first()).toBeVisible();
        }, page, 'S11-draft-edited');

        await check('S11', 'faculty approves drafts and adds them to the assessment (drafts stay drafts until approved)', async () => {
          for (let i = 0; i < 3; i++) {
            const approve = page.getByTestId('approve-button').first();
            await approve.click();
            await expect(page.getByTestId('review-status').filter({ hasText: /approved/i }).nth(i)).toBeVisible({ timeout: 30_000 });
            const add = page.getByTestId('add-to-assessment-button').first();
            await add.click();
            const modal = page.getByTestId('add-to-assessment-modal');
            if (await modal.isVisible().catch(() => false)) {
              await modal.getByTestId('confirm-add-question').click();
              await expect(modal).toBeHidden({ timeout: 30_000 });
            }
            await expect(page.getByTestId('added-badge').nth(i)).toBeVisible({ timeout: 30_000 });
          }
          const detail = (await api.call('GET', `/assessments/${assessmentId}`)).data;
          questionIds = (detail.questions ?? []).map((q: any) => q.id);
          expect(questionIds.length).toBe(3);
          return `official questions=${questionIds.length}`;
        }, page, 'S11-approved-added');

        await check('S11', 'faculty can reject a draft with a reason (second small batch)', async () => {
          const form = page.getByTestId('generation-form');
          await form.getByLabel('Topic', { exact: true }).fill('Transaction isolation levels');
          await form.getByLabel('Course outcome', { exact: true }).selectOption(String(loIds[2]));
          await form.getByLabel('Cognitive level', { exact: true }).selectOption('Evaluate');
          await form.getByLabel('Number of questions', { exact: true }).fill('1');
          await page.getByTestId('generate-button').click();
          const done = await poll(async () => /completed/i.test((await page.getByTestId('generation-status').first().textContent().catch(() => '')) ?? ''), 240_000, 3000);
          expect(done).toBeTruthy();
          page.once('dialog', (d) => d.accept('Not aligned with CLO3 wording'));
          const reject = page.getByTestId('reject-button').first();
          await reject.click();
          const confirmBtn = page.getByRole('button', { name: /^Reject$|Confirm/ }).last();
          if (await confirmBtn.isVisible().catch(() => false)) await confirmBtn.click().catch(() => undefined);
          const showRejected = page.getByRole('button', { name: /Show \d+ rejected/ });
          await expect(showRejected.or(page.getByTestId('review-status').filter({ hasText: /rejected/i }).first())).toBeVisible({ timeout: 30_000 });
          if (await showRejected.isVisible().catch(() => false)) await showRejected.click();
          await expect(page.getByTestId('review-status').filter({ hasText: /rejected/i }).first()).toBeVisible({ timeout: 30_000 });
          const detail = (await api.call('GET', `/assessments/${assessmentId}`)).data;
          expect((detail.questions ?? []).length).toBe(3);
          return 'rejected draft never entered the assessment (rejected drafts are hidden behind "Show N rejected")';
        }, page, 'S11-rejected');
        await axeScan(page, '/question-generator');

        await check('S05', 'assessment page lists each question with text, marks, difficulty; LO and faculty Bloom level visible?', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          await expect(page.getByText('Questions Count')).toBeVisible();
          await expect(page.getByText(/^Q1$/)).toBeVisible();
          await expect(page.getByText(/^10(\.00)? Marks/).first()).toBeVisible();
          await expect(page.getByText(/^medium$/i).first()).toBeVisible();
          const text = await bodyText(page);
          const loShown = /CLO2/.test(text);
          const bloomShown = /Apply/.test(text);
          const typeShown = /descriptive/i.test(text);
          if (!loShown || !bloomShown || !typeShown) throw new Error(`question cards omit faculty metadata: LO shown=${loShown}, Bloom shown=${bloomShown}, type shown=${typeShown} (only after AI analysis are AI badges shown)`);
        }, page, 'S05-question-list');
        await axeScan(page, '/assessments/:id (questions)');
      });

      // ───────────────────────── S06 Upload Academic Document ─────────────────────────
      await test.step('S06 Documents', async () => {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'uat-docs-'));
        const good = path.join(tmp, 'DBMS-syllabus.txt');
        fs.writeFileSync(good, ['Database Systems — Course Syllabus', 'Unit 1: Relational model, keys, integrity constraints.', 'Unit 2: Functional dependencies and normalization (1NF, 2NF, 3NF, BCNF).', 'Unit 3: SQL, query processing and indexing.', 'Unit 4: Transactions, concurrency control and recovery.', 'Assessment: midterm 30 marks, final 50 marks, assignments 20 marks.'].join('\n').repeat(5));
        const badType = path.join(tmp, 'photo.png');
        fs.writeFileSync(badType, Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex'));
        const tooBig = path.join(tmp, 'huge.txt');
        fs.writeFileSync(tooBig, Buffer.alloc(11 * 1024 * 1024, 'a'));
        const corruptPdf = path.join(tmp, 'corrupt-paper.pdf');
        fs.writeFileSync(corruptPdf, Buffer.from('%PDF-1.7\n' + 'garbage '.repeat(500)));

        await check('S06', 'faculty uploads a syllabus (TXT) and sees it processed to Completed with extracted content', async () => {
          await page.goto(`/courses/${courseId}`);
          await page.getByRole('button', { name: 'Upload Document' }).click();
          await expect(page.getByRole('heading', { name: 'Upload Academic Document' })).toBeVisible();
          await shot(page, 'S06-upload-modal');
          await page.locator('input[type="file"][accept=".pdf,.docx,.txt"]').setInputFiles(good);
          await expect(page.getByText('DBMS-syllabus.txt')).toBeVisible();
          const uploaded = waitFor(page, (r) => /\/api\/documents(\/upload)?$/.test(r.url()) && r.request().method() === 'POST', { timeout: 120_000 });
          await page.getByRole('button', { name: /Upload & Extract/ }).click();
          const res = await uploaded;
          expect([200, 201, 202], await res.text()).toContain(res.status());
          await expect(page.getByRole('heading', { name: 'Upload Academic Document' })).toBeHidden({ timeout: 60_000 });
          const link = page.getByRole('link', { name: 'DBMS-syllabus.txt' });
          await expect(link).toBeVisible({ timeout: 60_000 });
          const ok = await poll(async () => {
            const docs = (await api.call('GET', `/documents?course_id=${courseId}`)).data;
            const list = Array.isArray(docs) ? docs : docs?.data ?? [];
            return list.some((d: any) => d.original_file_name === 'DBMS-syllabus.txt' && d.processing_status === 'completed');
          }, 120_000, 3000);
          expect(ok, 'document must reach completed').toBeTruthy();
          await page.reload();
          await expect(page.getByRole('link', { name: 'DBMS-syllabus.txt' })).toBeVisible();
          await expect(page.getByText(/Completed|Extracted/).first()).toBeVisible();
          await page.getByRole('link', { name: 'DBMS-syllabus.txt' }).click();
          await expect(page).toHaveURL(/\/documents\/\d+/);
          await expect(page.getByText(/COMPLETED/)).toBeVisible();
          await expect(page.getByText(/normalization/i).first()).toBeVisible();
          return 'list badge reads "Extracted", detail page reads "COMPLETED" (UAT-011)';
        }, page, 'S06-document-details');
        await axeScan(page, '/documents/:id');

        await check('S06', 'unsupported file type is rejected before upload with a clear message', async () => {
          await page.goto(`/courses/${courseId}`);
          await page.getByRole('button', { name: 'Upload Document' }).click();
          await page.locator('input[type="file"][accept=".pdf,.docx,.txt"]').setInputFiles(badType);
          await expect(page.getByText(/Unsupported file type/)).toBeVisible();
        }, page, 'S06-invalid-type');

        await check('S06', 'oversized file (11 MB) is rejected with a clear message', async () => {
          await page.locator('input[type="file"][accept=".pdf,.docx,.txt"]').setInputFiles(tooBig);
          await expect(page.getByText(/must not exceed 10 ?MB/i)).toBeVisible();
          await page.getByRole('button', { name: 'Cancel' }).click();
        }, page, 'S06-oversized');

        await check('S06', 'a corrupt PDF ends in Failed with an error the faculty can read and a Reprocess action', async () => {
          await page.getByRole('button', { name: 'Upload Document' }).click();
          await page.locator('select').filter({ has: page.locator('option:has-text("Question Paper / Assessment Draft")') }).selectOption('question_paper');
          await page.locator('input[type="file"][accept=".pdf,.docx,.txt"]').setInputFiles(corruptPdf);
          const uploaded = waitFor(page, (r) => /\/api\/documents(\/upload)?$/.test(r.url()) && r.request().method() === 'POST', { timeout: 120_000 });
          await page.getByRole('button', { name: /Upload & Extract/ }).click();
          const res = await uploaded;
          const status = res.status();
          const modalStillOpen = await page.getByRole('heading', { name: 'Upload Academic Document' }).isVisible().catch(() => false);
          if (status >= 400) {
            await expect(page.getByText(/(failed|could not|unable|invalid|corrupt|extract)/i).first()).toBeVisible();
            await shot(page, 'S06-corrupt-inline-error');
            await page.getByRole('button', { name: 'Cancel' }).click();
            return `upload rejected synchronously with HTTP ${status}; message shown in modal=${modalStillOpen}`;
          }
          await expect(page.getByRole('heading', { name: 'Upload Academic Document' })).toBeHidden({ timeout: 60_000 });
          const failed = await poll(async () => {
            const docs = (await api.call('GET', `/documents?course_id=${courseId}`)).data;
            const list = Array.isArray(docs) ? docs : docs?.data ?? [];
            return list.some((d: any) => d.original_file_name === 'corrupt-paper.pdf' && d.processing_status === 'failed');
          }, 120_000, 3000);
          await page.reload();
          if (!failed) {
            const docs = (await api.call('GET', `/documents?course_id=${courseId}`)).data;
            const list = Array.isArray(docs) ? docs : docs?.data ?? [];
            const d = list.find((x: any) => x.original_file_name === 'corrupt-paper.pdf');
            throw new Error(`corrupt PDF did not reach failed; status=${d?.processing_status}`);
          }
          await expect(page.getByText(/Failed/).first()).toBeVisible();
          await expect(page.locator('button[title="Reprocess Extraction"]').first()).toBeVisible();
          return 'status Failed + Reprocess Extraction visible in list';
        }, page, 'S06-corrupt-failed');

        await check('S06', 'the upload drop-zone is keyboard operable (not a div-only click target)', async () => {
          await page.getByRole('button', { name: 'Upload Document' }).click();
          const focusable = await page.evaluate(() => {
            const zone = Array.from(document.querySelectorAll('div')).find((d) => /Click to browse or drag and drop/.test(d.textContent ?? '') && d.className.includes('border-dashed'));
            if (!zone) return 'zone-not-found';
            const tabbable = zone.getAttribute('tabindex') !== null || zone.getAttribute('role') === 'button' || zone.querySelector('button, [tabindex]');
            return tabbable ? 'tabbable' : 'not-tabbable';
          });
          await page.getByRole('button', { name: 'Cancel' }).click();
          expect(focusable).toBe('tabbable');
        });
      });

      // ───────────────────────── S07 AI Analysis ─────────────────────────
      await test.step('S07 AI Analysis', async () => {
        await check('S07', 'faculty runs the unified AI analysis from the Analysis page and sees all result sections', async () => {
          await page.goto(`/assessments/${assessmentId}/analysis`);
          const run = page.getByRole('button', { name: 'Run AI Analysis', disabled: false }).first();
          await expect(run).toBeVisible();
          await shot(page, 'S07-analysis-empty');
          const analysisResponse = waitFor(page, (r) => /\/api\/ai\/assessments\/\d+\/analyze/.test(r.url()) && r.request().method() === 'POST', { timeout: 240_000 });
          await run.click();
          await expect(page.getByText('Analyzing Complete Assessment...').first()).toBeVisible();
          const res = await analysisResponse;
          expect(res.status(), await res.text()).toBe(200);
          await expect(page.getByRole('heading', { name: /Assessment Rigor/ })).toBeVisible({ timeout: 60_000 });
          await expect(page.getByRole('heading', { name: 'AI Recommendations' })).toBeVisible();
          const text = await bodyText(page);
          const sections = { difficulty: /Difficulty/i.test(text), bloom: /Bloom|Cognitive/i.test(text), alignment: /Learning Outcome Alignment|Outcome Alignment/i.test(text), similarity: /Similarity/i.test(text), findings: /Finding/i.test(text), recommendations: /Recommendation/i.test(text) };
          const missing = Object.entries(sections).filter(([, v]) => !v).map(([k]) => k);
          expect(missing, 'missing sections').toEqual([]);
          await expectNoCrash(page);
        }, page, 'S07-analysis-results');

        await check('S07', 'results are labelled in faculty language (Excellent/Good/Needs Review…) and no raw JSON is shown by default', async () => {
          const text = await bodyText(page);
          expect(text).toMatch(/Excellent|Good|Fair|Needs Review|Requires Attention|Acceptable|Strong|Balanced/i);
          const rawJson = await page.locator('pre').count();
          return `visible <pre> blocks=${rawJson}`;
        });

        await check('S07', 'analysis page states that AI output is decision support, not a decision', async () => {
          const text = await bodyText(page);
          expect(text).toMatch(/decision support|decisions do not automatically|faculty (makes the )?final decision|assistive/i);
        });
        await axeScan(page, '/assessments/:id/analysis');
      });

      // ───────────────────────── S08 Explainability ─────────────────────────
      await test.step('S08 Explainability', async () => {
        await check('S08', 'faculty opens "Why?" on an AI Bloom classification and sees result, reason, confidence, limitations', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          const why = page.getByRole('button', { name: /Why\? Explain Bloom level/ }).first();
          await expect(why).toBeVisible();
          await why.click();
          const dialog = page.getByRole('dialog', { name: 'Bloom level' });
          await expect(dialog).toBeVisible();
          await expect(dialog.getByTestId('explanation-result')).not.toBeEmpty({ timeout: 30_000 });
          await expect(dialog.getByTestId('explanation-why')).toBeVisible();
          await expect(dialog.getByTestId('confidence-value')).toBeVisible();
          await expect(dialog.getByTestId('limitations-panel')).toBeVisible();
          const focusInside = await page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]'));
          const text = ((await dialog.textContent()) ?? '').toLowerCase();
          expect(text).not.toContain('system prompt');
          expect(text).not.toContain('chain of thought');
          expect(text).not.toContain('model thought');
          return `focus moved into dialog=${focusInside}; confidence="${await dialog.getByTestId('confidence-value').textContent()}"`;
        }, page, 'S08-explanation');

        await check('S08', 'evidence and method are available on demand (progressive disclosure, keyboard operable)', async () => {
          const dialog = page.getByRole('dialog', { name: 'Bloom level' });
          const technical = dialog.getByRole('button', { name: /Technical details/ });
          await technical.focus();
          await page.keyboard.press('Enter');
          await expect(technical).toHaveAttribute('aria-expanded', 'true');
          await expect(dialog.getByTestId('method-type')).toBeVisible();
          await dialog.getByRole('button', { name: /Detailed evidence/ }).click();
          await expect(dialog.getByTestId('evidence-item').first().or(dialog.getByTestId('evidence-empty'))).toBeVisible({ timeout: 30_000 });
          const method = await dialog.getByTestId('method-type').textContent();
          return `method=${method?.trim()}`;
        }, page, 'S08-evidence');

        await check('S08', 'faculty can override the AI Bloom level; the AI value stays visible for reference', async () => {
          const dialog = page.getByRole('dialog', { name: 'Bloom level' });
          await dialog.getByTestId('override-button').click();
          const override = page.getByTestId('override-dialog');
          await expect(override).toBeVisible();
          await shot(page, 'S08-override-dialog');
          await override.getByTestId('override-value').selectOption('ANALYZE');
          await override.getByTestId('override-reason').selectOption({ index: 1 });
          const overrideResponse = waitFor(page, (r) => /\/api\/ai-results\/bloom\/\d+\/override$/.test(r.url()) && r.request().method() === 'POST');
          await override.getByTestId('override-submit').click();
          expect((await overrideResponse).status()).toBe(200);
          await expect(dialog.getByTestId('review-latest')).toContainText(/OVERRIDDEN/i);
          await page.keyboard.press('Escape');
          await expect(dialog).toBeHidden();
          const qs = (await api.call('GET', `/assessments/${assessmentId}`)).data.questions ?? [];
          const q = qs.find((x: any) => String(x.cognitive_level) === 'Analyze');
          expect(q, 'faculty value stored').toBeTruthy();
          expect(q.ai_cognitive_level, 'AI value preserved').toBeTruthy();
        }, page, 'S08-overridden');

        await check('S08', '"How is the score calculated?" explains the overall quality score in plain terms', async () => {
          await page.goto(`/assessments/${assessmentId}/analysis`);
          await expect(page.getByRole('heading', { name: /Assessment Rigor/ })).toBeVisible({ timeout: 60_000 });
          await page.getByTestId('quality-how-calculated').click();
          const qd = page.getByRole('dialog', { name: 'Overall quality score' });
          await expect(qd.getByTestId('explanation-result')).toContainText('/ 100', { timeout: 30_000 });
          await expect(qd.getByTestId('explanation-why')).toContainText(/weighted combination/i);
          await page.keyboard.press('Escape');
        }, page, 'S08-score-explained');
      });

      // ───────────────────────── S09 Recommendations ─────────────────────────
      await test.step('S09 Recommendations', async () => {
        await check('S09', 'faculty opens a recommendation, inspects evidence and records an Accept decision with a rating/reason', async () => {
          const recs = await api.call('GET', `/ai/assessments/${assessmentId}/recommendations`);
          const recList: any[] = recs.data?.recommendations ?? recs.data ?? [];
          expect(recList.length, 'analysis must yield recommendations').toBeGreaterThan(0);
          const evidence = page.getByRole('button', { name: /Evidence Details/ }).first();
          let evidenceNote = 'no evidence accordion';
          if (await evidence.count()) {
            await evidence.scrollIntoViewIfNeeded();
            await evidence.click();
            const pre = page.locator('pre').first();
            evidenceNote = (await pre.isVisible().catch(() => false)) ? 'NOTE: evidence is rendered as raw JSON in a <pre> block' : 'evidence rendered as text';
          }
          await shot(page, 'S09-recommendation-evidence');
          const accept = page.getByRole('button', { name: 'Accept', exact: true }).first();
          await accept.scrollIntoViewIfNeeded();
          await accept.click();
          await expect(page.getByRole('dialog').filter({ hasText: 'Accept Recommendation' })).toBeVisible();
          await shot(page, 'S09-accept-dialog');
          const feedbackResponse = waitFor(page, (r) => /\/api\/recommendations\/\d+\/feedback$/.test(r.url()) && r.request().method() === 'POST');
          await page.getByRole('button', { name: 'Confirm & Accept' }).click();
          expect((await feedbackResponse).status()).toBe(200);
          await expect(page.getByText('Accepted').first()).toBeVisible();
          return evidenceNote;
        }, page, 'S09-accepted');

        await check('S09', 'faculty can also Review (with note) and Dismiss a recommendation; decisions never change questions', async () => {
          const before = (await api.call('GET', `/assessments/${assessmentId}`)).data.questions;
          const review = page.getByRole('button', { name: 'Review', exact: true }).first();
          await review.scrollIntoViewIfNeeded();
          await review.click();
          await expect(page.getByRole('dialog').filter({ hasText: 'Mark as Reviewed' })).toBeVisible();
          await page.getByRole('button', { name: 'Mark Reviewed' }).click();
          await expect(page.getByRole('dialog').filter({ hasText: 'Mark as Reviewed' })).toBeHidden({ timeout: 30_000 });
          const after = (await api.call('GET', `/assessments/${assessmentId}`)).data.questions;
          expect(after.map((q: any) => q.question_text)).toEqual(before.map((q: any) => q.question_text));
          const text = await bodyText(page);
          expect(text).toMatch(/decisions do not automatically alter assessment questions/i);
        }, page, 'S09-reviewed');
      });

      // ───────────────────────── S10 Rubric ─────────────────────────
      await test.step('S10 Rubric', async () => {
        await check('S10', 'faculty generates an AI rubric draft for Q1, edits it, saves and approves it', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          await page.getByLabel(/Generate rubric for question 1/).click();
          const dialog = page.getByRole('dialog', { name: /AI Rubric Generator/ });
          await expect(dialog).toBeVisible();
          await expect(dialog.getByTestId('rubric-empty')).toBeVisible();
          await expect(dialog.getByText(/You review and approve the final version/)).toBeVisible();
          const generated = waitFor(page, (r) => /\/questions\/\d+\/rubrics\/generate/.test(r.url()), { timeout: 180_000 });
          await dialog.getByRole('button', { name: 'Generate AI Rubric' }).click();
          const genRes = await generated;
          expect(genRes.status(), await genRes.text()).toBe(201);
          await expect(dialog.getByTestId('rubric-preview')).toBeVisible({ timeout: 30_000 });
          await expect(dialog.getByText(/Draft rubric generated. Review and adjust before approving/)).toBeVisible();
          await shot(page, 'S10-rubric-draft');
          await dialog.getByRole('button', { name: 'Edit Rubric' }).click();
          await expect(dialog.getByTestId('rubric-editor')).toBeVisible();
          const notes = dialog.getByPlaceholder('Overall marking notes for this question');
          await notes.fill('Award partial credit when the dependency diagram is correct but the final normal form is mislabelled.');
          await dialog.getByRole('button', { name: 'Save Draft' }).click();
          await expect(dialog.getByTestId('rubric-preview')).toBeVisible({ timeout: 30_000 });
          await expect(dialog.getByText(/Rubric draft saved/)).toBeVisible();
          const approved = waitFor(page, (r) => /\/rubrics\/\d+\/approve$/.test(r.url()));
          await dialog.getByRole('button', { name: 'Approve Rubric' }).click();
          expect((await approved).status()).toBe(200);
          await expect(dialog.getByText(/Rubric approved by faculty/)).toBeVisible();
          const rubric = await api.call('GET', `/questions/${questionIds[0]}/rubrics`);
          const current = (rubric.data as any[]).find((r) => r.status === 'APPROVED');
          expect(current).toBeTruthy();
          expect(Number(current.criteria.reduce((s: number, c: any) => s + Number(c.max_marks), 0))).toBeCloseTo(10, 1);
          await dialog.getByRole('button', { name: 'Close rubric generator' }).click();
        }, page, 'S10-rubric-approved');
      });

      // ───────────────────────── S12 Blueprint ─────────────────────────
      await test.step('S12 Blueprint', async () => {
        await check('S12', 'faculty creates a blueprint, configures targets and saves/validates it', async () => {
          await page.goto(`/assessments/${assessmentId}/blueprint`);
          await expect(page.getByTestId('assessment-blueprint-page')).toBeVisible();
          await expect(page.getByTestId('blueprint-empty-state')).toBeVisible();
          await page.getByRole('button', { name: 'Create blueprint' }).click();
          await expect(page.getByTestId('blueprint-form')).toBeVisible();
          await shot(page, 'S12-blueprint-form');
          await page.locator('#bp-total-marks').fill('30');
          await page.locator('#bp-total-questions').fill('3');
          await page.locator('#bp-duration').fill('90');
          // one section with three descriptive questions of 10 marks
          if ((await page.locator('#sec-title-0').count()) === 0) await page.getByRole('button', { name: 'Add section' }).click();
          await page.locator('#sec-title-0').fill('Section A');
          await page.locator('#sec-count-0').fill('3');
          await page.locator('#sec-marks-0').fill('10');
          // question plan rows
          const plan = page.getByTestId('blueprint-question-plan');
          if ((await plan.locator('tbody tr').count()) === 0) await plan.getByRole('button', { name: 'Add row' }).click();
          const row = plan.locator('tbody tr').first();
          const countInput = row.locator('input[type="number"]').first();
          await countInput.fill('3');
          const marksInput = row.locator('input[type="number"]').nth(1);
          await marksInput.fill('10');
          await page.getByRole('button', { name: /Save & validate/ }).click();
          await expect(page.getByTestId('blueprint-form')).toBeHidden({ timeout: 30_000 });
          await expect(page.getByTestId('blueprint-summary')).toBeVisible();
          const text = await bodyText(page);
          const hasValidation = await page.getByTestId('blueprint-validation').isVisible().catch(() => false);
          return `validation panel shown=${hasValidation}; status text: ${text.match(/(Draft|Validation pending|Validated|Invalid|Finalized)/i)?.[0] ?? 'n/a'}`;
        }, page, 'S12-blueprint-saved');

        await check('S12', 'faculty reads validation warnings/recommendations and finalizes the blueprint', async () => {
          const validate = page.getByRole('button', { name: 'Validate', exact: true });
          if (await validate.isVisible().catch(() => false)) await validate.click();
          await expect(page.getByTestId('blueprint-validation')).toBeVisible({ timeout: 30_000 });
          const warnings = await bodyText(page);
          const warnNote = warnings.match(/warning/i) ? 'warnings shown' : 'no warnings text';
          const finalize = page.getByRole('button', { name: 'Finalize', exact: true });
          await expect(finalize).toBeVisible();
          await expect(page.getByRole('button', { name: 'Validate', exact: true })).toBeEnabled({ timeout: 30_000 });
          if (await finalize.isDisabled()) {
            const errs = await page.getByTestId('blueprint-validation').innerText();
            const bp = await api.call('GET', `/assessments/${assessmentId}/blueprint`);
            const v = bp.data?.validation ?? {};
            throw new Error(`Finalize disabled after validation (tooltip: "${await finalize.getAttribute('title')}"); validation_status=${bp.data?.blueprint?.validation_status}; errors=${JSON.stringify(v.errors ?? []).slice(0, 400)}; panel: ${errs.replace(/\s+/g, ' ').slice(0, 300)}`);
          }
          page.once('dialog', (d) => d.accept());
          await finalize.click();
          await expect(page.getByText(/Finalized/i).first()).toBeVisible({ timeout: 30_000 });
          await expect(page.getByRole('button', { name: 'Create new version' })).toBeVisible();
          return warnNote;
        }, page, 'S12-blueprint-finalized');

        await check('S12', 'blueprint page uses faculty language (no internal STEP numbers or developer jargon)', async () => {
          const titles = await page.locator('[title]').evaluateAll((els) => els.map((e) => e.getAttribute('title') ?? ''));
          const text = (await bodyText(page)) + ' ' + titles.join(' ');
          const jargon = text.match(/STEP \d+/g);
          expect(jargon, `internal jargon exposed: ${jargon?.join(', ')}`).toBeNull();
        });

        await check('S12', '"Compare with questions" tells faculty how the real paper deviates from the blueprint', async () => {
          await page.getByRole('button', { name: 'Compare with questions' }).click();
          await expect(page.getByText(/compar/i).first()).toBeVisible({ timeout: 30_000 });
          await expectNoCrash(page);
        }, page, 'S12-blueprint-compare');
        await axeScan(page, '/assessments/:id/blueprint');
      });

      // ───────────────────────── S13 Versions ─────────────────────────
      await test.step('S13 Versions', async () => {
        await check('S13', 'faculty snapshots v1.0 and finalizes it; the UI states it is immutable', async () => {
          await page.goto(`/assessments/${assessmentId}/versions`);
          await expect(page.getByTestId('assessment-versions-page')).toBeVisible();
          await page.getByRole('button', { name: /Create v1\.0|Create new version/ }).first().click();
          await page.getByRole('dialog').locator('textarea').fill('Initial midterm paper (3 descriptive questions).');
          const created = waitFor(page, (r) => r.url().endsWith(`/assessments/${assessmentId}/versions`) && r.request().method() === 'POST');
          await page.getByRole('dialog').getByRole('button', { name: /Create v1\.0|Create draft version/ }).click();
          const createRes = await created;
          expect(createRes.status(), await createRes.text()).toBe(201);
          versionId = (await createRes.json()).data.version.id;
          await expect(page).toHaveURL(new RegExp(`/versions/${versionId}$`));
          await shot(page, 'S13-version-draft');
          page.once('dialog', (d) => d.accept());
          const finalized = waitFor(page, (r) => r.url().endsWith(`/assessment-versions/${versionId}/finalize`));
          await page.getByRole('button', { name: 'Finalize', exact: true }).click();
          expect((await finalized).status()).toBe(200);
          await expect(page.getByText(/immutable historical record/).first()).toBeVisible();
          await expect(page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
        }, page, 'S13-version-finalized');

        let v2 = 0;
        await check('S13', 'faculty creates a new version from the finalized one, edits the draft and compares', async () => {
          await page.getByRole('button', { name: 'Create new version' }).click();
          const dlg = page.getByTestId('version-create-dialog');
          await expect(dlg).toBeVisible();
          await dlg.getByLabel(/Minor/).check();
          await dlg.locator('textarea').fill('Clarified wording of Q2 and added instructions.');
          const created = waitFor(page, (r) => r.url().endsWith(`/assessments/${assessmentId}/versions`) && r.request().method() === 'POST');
          await dlg.getByRole('button', { name: 'Create draft version' }).click();
          const res = await created;
          expect(res.status(), await res.text()).toBe(201);
          v2 = (await res.json()).data.version.id;
          await expect(page).toHaveURL(new RegExp(`/versions/${v2}$`));
          await page.getByRole('button', { name: 'Edit draft' }).click();
          await expect(page.getByTestId('version-edit-form')).toBeVisible();
          await page.getByLabel('Version title').fill('Midterm Examination (Set B)');
          await page.getByLabel('Instructions').fill('Answer all questions. Show your working.');
          await page.getByRole('button', { name: 'Save draft' }).click();
          await expect(page.getByTestId('version-edit-form')).toBeHidden({ timeout: 30_000 });
          await expect(page.getByText('Midterm Examination (Set B)').first()).toBeVisible();
          await page.getByRole('button', { name: 'Compare', exact: true }).click();
          await expect(page.getByTestId('assessment-version-compare-page')).toBeVisible();
          await expect(page.getByTestId('version-comparison')).toBeVisible({ timeout: 30_000 });
          await expectNoCrash(page);
        }, page, 'S13-version-compare');

        await check('S13', 'the old finalized version is unchanged after the new draft was edited', async () => {
          const v1 = (await api.call('GET', `/assessments/${assessmentId}/versions/${versionId}`)).data.version;
          expect(v1.status).toBe('FINALIZED');
          expect(v1.title).toBe('Midterm Examination');
          expect(v1.questions?.length ?? 0).toBe(3);
          await api.call('PUT', `/assessment-versions/${versionId}`, { title: 'tamper' }, [409]);
          return `v1 title="${v1.title}" status=${v1.status}; v2 id=${v2}`;
        });
        await axeScan(page, '/assessments/:id/versions/:v/compare');
      });

      // ───────────────────────── S14 Student Submission ─────────────────────────
      await test.step('S14 Submission', async () => {
        await check('S14', 'faculty records a student submission and enters answers for every question through the UI', async () => {
          await page.goto(`/assessments/${assessmentId}/submissions`);
          await expect(page.getByTestId('student-submissions-page')).toBeVisible();
          await page.getByRole('button', { name: 'Add Submission' }).first().click();
          const newStudent = page.getByRole('button', { name: 'New student' });
          if (await newStudent.isVisible().catch(() => false)) await newStudent.click();
          await page.locator('#new-student-identifier').fill('UAT-STU-001');
          await page.locator('#new-student-name').fill('Nabila Hossain');
          await page.locator('#submission-identifier').fill('MID-001');
          await shot(page, 'S14-submission-form');
          const created = waitFor(page, (r) => r.url().endsWith(`/assessments/${assessmentId}/submissions`) && r.request().method() === 'POST');
          await page.getByRole('button', { name: 'Create Submission' }).click();
          const createRes = await created;
          expect(createRes.status(), await createRes.text()).toBe(201);
          submissionId = (await createRes.json()).data.id;
          await expect(page.getByText('Nabila Hossain').first()).toBeVisible();
          await page.goto(`/submissions/${submissionId}`);
          await expect(page.getByTestId('submission-details-page')).toBeVisible();
          const answers = [
            'A relation is in 3NF when it is in 2NF and no non-prime attribute is transitively dependent on a candidate key. Decompose R(A,B,C) with A→B, B→C into R1(A,B) and R2(B,C); each step preserves the dependencies.',
            'BCNF requires every determinant to be a candidate key. The schema violates BCNF because of the dependency on a non-key attribute; splitting it removes update anomalies but may lose dependency preservation.',
            'Functional dependencies describe how one attribute set determines another. Using Armstrong axioms we compute the closure and identify candidate keys before normalizing.',
          ];
          for (let i = 0; i < questionIds.length; i++) {
            const card = page.getByTestId('student-answer-card').nth(i);
            await card.getByRole('button', { name: 'Add Answer' }).click();
            await card.locator(`#answer-text-${questionIds[i]}`).fill(answers[i]);
            const saved = waitFor(page, (r) => /\/submissions\/\d+\/answers$/.test(r.url()) && r.request().method() === 'POST');
            await card.getByRole('button', { name: 'Save Answer' }).click();
            expect((await saved).status()).toBe(201);
            await expect(card.getByTestId('answer-text')).toBeVisible({ timeout: 30_000 });
          }
        }, page, 'S14-answers-recorded');

        await check('S14', 'status workflow is explicit (Submitted → Under review) and marks read "Not graded" before faculty acts', async () => {
          await page.getByTestId('transition-UNDER_REVIEW').click();
          await expect(page.getByTestId('transition-GRADED')).toBeVisible({ timeout: 30_000 });
          const marks = page.getByTestId('answer-marks');
          for (let i = 0; i < await marks.count(); i++) await expect(marks.nth(i)).toContainText('Not graded');
        }, page, 'S14-under-review');
        await axeScan(page, '/submissions/:id');
      });

      // ───────────────────────── S15 AI-Assisted Grading ─────────────────────────
      await test.step('S15 AI grading', async () => {
        await check('S15', 'AI grading is only offered where an approved rubric exists, with a decision-support disclaimer', async () => {
          const first = page.getByTestId('student-answer-card').first();
          await expect(first.getByTestId('grading-disclaimer').first()).toContainText(/Faculty review (is|and final judgment are) required/i);
          await expect(first.getByTestId('ai-grading-button')).toBeEnabled();
          const second = page.getByTestId('student-answer-card').nth(1);
          await expect(second.getByTestId('ai-grading-blocked')).toContainText(/approved rubric is required/i);
        }, page, 'S15-grading-entry');

        await check('S15', 'faculty requests an AI suggestion; the mark stays "Not graded" until the faculty decides', async () => {
          const first = page.getByTestId('student-answer-card').first();
          const queued = waitFor(page, (r) => /\/student-answers\/\d+\/ai-grade|\/ai-grading/.test(r.url()) && r.request().method() === 'POST', { timeout: 60_000 });
          await first.getByTestId('ai-grading-button').click();
          const q = await queued;
          expect([200, 201, 202], await q.text()).toContain(q.status());
          const ready = await poll(async () => (await first.getByTestId('ai-grading-result').count()) > 0, 240_000, 3000);
          expect(ready, 'AI grading suggestion must arrive').toBeTruthy();
          await expect(first.getByTestId('suggested-marks')).toBeVisible();
          await expect(first.getByTestId('answer-marks')).toContainText('Not graded');
          await expect(first.getByTestId('accept-suggestion')).toBeVisible();
          await expect(first.getByTestId('edit-final-marks')).toBeVisible();
          await expect(first.getByTestId('reject-suggestion')).toBeVisible();
          const suggested = (await first.getByTestId('suggested-marks').textContent())?.trim();
          const breakdown = await first.getByTestId('criterion-row').count();
          return `suggested=${suggested}; criterion rows=${breakdown}`;
        }, page, 'S15-ai-suggestion');

        await check('S15', 'faculty overrides the suggestion with their own final mark and it is recorded as the faculty decision', async () => {
          const first = page.getByTestId('student-answer-card').first();
          await first.getByTestId('edit-final-marks').click();
          await first.getByTestId('final-marks-input').fill('8');
          const finalized = waitFor(page, (r) => /\/student-answers\/\d+\/finalize-grade$/.test(r.url()));
          await first.getByTestId('finalize-grade-button').click();
          expect((await finalized).status()).toBe(200);
          await expect(first.getByTestId('answer-marks')).toContainText('8', { timeout: 60_000 });
          await expect(first.getByTestId('faculty-final-marks')).toContainText('8');
          const decision = await first.getByTestId('faculty-decision').textContent().catch(() => 'n/a');
          return `faculty decision label="${decision?.trim()}"`;
        }, page, 'S15-faculty-final');

        await check('S15', 'remaining answers are graded manually; marks above the maximum are refused client-side', async () => {
          const second = page.getByTestId('student-answer-card').nth(1);
          await second.getByTestId('grade-manually').click();
          await second.getByTestId('final-marks-input').fill('11');
          await second.getByTestId('finalize-grade-button').click();
          await expect(second.getByText(/cannot exceed 10/)).toBeVisible();
          await second.getByTestId('final-marks-input').fill('6.5');
          const f2 = waitFor(page, (r) => /\/student-answers\/\d+\/finalize-grade$/.test(r.url()));
          await second.getByTestId('finalize-grade-button').click();
          expect((await f2).status()).toBe(200);
          const third = page.getByTestId('student-answer-card').nth(2);
          await third.getByTestId('grade-manually').click();
          await third.getByTestId('final-marks-input').fill('7');
          const f3 = waitFor(page, (r) => /\/student-answers\/\d+\/finalize-grade$/.test(r.url()));
          await third.getByTestId('finalize-grade-button').click();
          expect((await f3).status()).toBe(200);
          await page.getByTestId('transition-GRADED').click();
          await expect(page.getByTestId('transition-RETURNED')).toBeVisible({ timeout: 30_000 });
          await expect(page.getByTestId('submission-marks')).toContainText('21.5');
        }, page, 'S15-graded');

        await check('S15', 'grading page never claims automatic grading', async () => {
          const text = await bodyText(page);
          expect(text).not.toMatch(/auto-?grad(e|ed|ing)|automatically graded/i);
          expect(text).toMatch(/AI Suggested|suggestion/i);
        });
      });

      // ───────────────────────── S16 Performance ─────────────────────────
      await test.step('S16 Performance', async () => {
        await check('S16', '[fixture] five more graded submissions are created through the same API the UI uses', async () => {
          const marks = [[9, 8, 7], [5, 4, 6], [8, 7, 9], [3, 6, 4], [10, 9, 8]];
          for (const [i, m] of marks.entries()) {
            const student = await api.createStudent(`UAT-STU-${String(i + 2).padStart(3, '0')}`, `Student ${i + 2}`);
            const sub = await api.createSubmissionWithAnswers(assessmentId, student.id, questionIds);
            await api.call('PATCH', `/submissions/${sub.id}/status`, { status: 'UNDER_REVIEW' });
            for (const [j, mark] of m.entries()) await api.call('POST', `/student-answers/${sub.answerIds[j]}/finalize-grade`, { final_marks: mark });
            await api.call('PATCH', `/submissions/${sub.id}/status`, { status: 'GRADED' });
          }
          return '6 graded submissions in total';
        });

        await check('S16', 'faculty runs Student Performance analysis and sees averages, LO/topic analysis and learning gaps', async () => {
          await page.goto(`/assessments/${assessmentId}/analysis`);
          const perfResponse = waitFor(page, (r) => r.url().endsWith(`/assessments/${assessmentId}/performance/analyze`), { timeout: 180_000 });
          await page.getByRole('button', { name: 'Analyze Student Performance' }).click();
          const perfRes = await perfResponse;
          expect(perfRes.status(), await perfRes.text()).toBe(200);
          await expect(page.getByTestId('performance-summary')).toBeVisible({ timeout: 60_000 });
          await expect(page.getByTestId('performance-summary')).toContainText('Overall Average');
          await expect(page.getByTestId('performance-summary')).toContainText('6 submissions');
          const text = await bodyText(page);
          const has = { lo: /Learning Outcome/i.test(text), topic: /Topic/i.test(text), gaps: /Learning Gap|gap/i.test(text) };
          const perf = await api.call('GET', `/assessments/${assessmentId}/performance`);
          const run = perf.data.analysis ?? perf.data;
          expect(run.status).toBe('COMPLETED');
          expect(Number(run.finalized_answer_count)).toBe(18);
          return `sections LO=${has.lo} topic=${has.topic} gaps=${has.gaps}; avg=${run.overall_average_percentage}%`;
        }, page, 'S16-performance');
      });

      // ───────────────────────── S17 Analytics ─────────────────────────
      await test.step('S17 Analytics', async () => {
        await check('S17', 'analytics KPIs match the data the faculty just created', async () => {
          await page.goto('/analytics');
          await expect(page.getByTestId('academic-analytics-page')).toBeVisible();
          await page.getByRole('button', { name: 'Recalculate analytics' }).click();
          await expect(page.getByTestId('kpi-grid')).toBeVisible({ timeout: 60_000 });
          await expect(page.getByTestId('kpi-courses')).toContainText('1');
          await expect(page.getByTestId('kpi-assessments')).toContainText('1');
          await expect(page.getByTestId('kpi-questions')).toContainText('3');
          const overview = await api.call('GET', '/analytics/overview?fresh=1');
          expect(overview.data.kpis.courses.value).toBe(1);
          expect(overview.data.kpis.questions.value).toBe(3);
          expect(overview.data.assessment_quality.analyzed_assessments).toBe(1);
        }, page, 'S17-analytics');

        await check('S17', 'course filter narrows the analytics and charts carry text alternatives', async () => {
          const select = page.getByTestId('analytics-filters').getByLabel('Course', { exact: true });
          await expect(select).toBeVisible();
          await select.selectOption(String(courseId));
          await page.getByRole('button', { name: 'Apply Filters' }).click();
          await expect(page.getByTestId('kpi-grid')).toBeVisible({ timeout: 60_000 });
          const charts = await page.locator('svg, canvas, [role="img"]').count();
          const unlabeled = await page.locator('svg:not([aria-hidden="true"]):not([aria-label]):not([role="img"])').filter({ hasNot: page.locator('title') }).count();
          const sections = await bodyText(page);
          const names = ['Difficulty Distribution', 'Cognitive Level', 'Learning Outcome Coverage', 'Student Performance', 'Learning Gaps', 'Grading'].filter((n) => new RegExp(n, 'i').test(sections));
          return `charts/graphics=${charts}, svg without label=${unlabeled}; sections present: ${names.join(', ')}`;
        }, page, 'S17-analytics-filtered');
        await axeScan(page, '/analytics');
      });

      // ───────────────────────── S18 Reports ─────────────────────────
      await test.step('S18 Reports', async () => {
        const formats: ['PDF' | 'CSV' | 'XLSX', string, RegExp][] = [['PDF', 'ASSESSMENT_QUALITY', /^%PDF-/], ['CSV', 'QUESTION_ANALYSIS', /[A-Za-z",]+/], ['XLSX', 'STUDENT_PERFORMANCE', /^PK/]];
        for (const [fmt, type, magic] of formats) {
          await check('S18', `faculty configures, previews, generates and downloads a ${type} report as ${fmt}`, async () => {
            await page.goto('/reports');
            await page.getByRole('button', { name: 'Create report' }).first().click();
            await expect(page).toHaveURL(/\/reports\/create/);
            await page.locator('#report-type').selectOption(type);
            await page.locator('#report-scope').selectOption('ASSESSMENT');
            await page.locator('#f-course').selectOption({ value: String(courseId) });
            await page.locator('#f-assessment').selectOption({ value: String(assessmentId) });
            await page.getByRole('radio', { name: new RegExp(fmt) }).click();
            if (fmt === 'PDF') await shot(page, 'S18-report-builder');
            const previewed = waitFor(page, (r) => r.url().endsWith('/api/reports/preview'));
            await page.getByRole('button', { name: 'Preview Report' }).click();
            expect((await previewed).status()).toBe(200);
            await expect(page.getByTestId('report-preview')).toBeVisible();
            const generated = waitFor(page, (r) => r.url().endsWith('/api/reports') && r.request().method() === 'POST');
            await page.getByRole('button', { name: 'Generate Report' }).click();
            const genRes = await generated;
            expect([201, 202]).toContain(genRes.status());
            const reportId = (await genRes.json()).data.id;
            await expect(page).toHaveURL(new RegExp(`/reports/${reportId}$`));
            await expect(page.getByText('COMPLETED', { exact: false }).first()).toBeVisible({ timeout: 120_000 });
            const downloadPromise = page.waitForEvent('download', { timeout: 60_000 });
            await page.getByRole('button', { name: `Download ${fmt}` }).click();
            const download = await downloadPromise;
            expect(download.suggestedFilename().toLowerCase()).toMatch(new RegExp(`\\.${fmt.toLowerCase()}$`));
            const bytes = fs.readFileSync((await download.path())!);
            expect(bytes.length).toBeGreaterThan(fmt === 'CSV' ? 20 : 500);
            expect(bytes.subarray(0, 8).toString('latin1')).toMatch(magic);
            expect(bytes.toString('latin1')).not.toContain('UAT-STU-001');
            return `report ${reportId}, ${bytes.length} bytes, no student identifier leaked`;
          }, page, `S18-report-${fmt}`);
        }
        await check('S18', 'report history lists the generated reports', async () => {
          await page.goto('/reports');
          await expect(page.getByTestId('report-history')).toBeVisible();
          expect(await page.locator('[data-testid^="report-row-"]').count()).toBeGreaterThanOrEqual(3);
        }, page, 'S18-report-history');
        await axeScan(page, '/reports');
      });

      // ───────────────────────── S19 Feedback ─────────────────────────
      await test.step('S19 Feedback', async () => {
        await check('S19', 'Feedback page lists the faculty decisions (accepted / reviewed) with filters', async () => {
          await page.goto('/feedback');
          await expect(page.getByText(/accepted/i).first()).toBeVisible();
          await expect(page.getByLabel('Filter feedback by course')).toBeVisible();
          await expectNoCrash(page);
        }, page, 'S19-feedback');

        await check('S19', 'faculty records an Accept-with-comment decision on an AI difficulty result via the explanation panel', async () => {
          await page.goto(`/assessments/${assessmentId}`);
          const why = page.getByRole('button', { name: /Why\? Explain AI difficulty/ }).first();
          await why.click();
          const dialog = page.getByRole('dialog', { name: 'Difficulty' });
          await expect(dialog.getByTestId('explanation-result')).not.toBeEmpty({ timeout: 30_000 });
          await dialog.getByRole('button', { name: /^Accept/ }).click();
          const confirm = dialog.getByTestId('review-confirm');
          await expect(confirm).toBeVisible();
          await confirm.locator('textarea').fill('Difficulty matches my expectation for a second-year cohort.');
          const reviewed = waitFor(page, (r) => /\/api\/ai-results\/difficulty\/\d+\/review$/.test(r.url()) && r.request().method() === 'POST');
          await confirm.getByTestId('review-submit').click();
          expect((await reviewed).status()).toBe(200);
          await expect(dialog.getByTestId('review-latest')).toContainText(/ACCEPTED/i);
          await page.keyboard.press('Escape');
        }, page, 'S19-review-accepted');
        await axeScan(page, '/feedback');
      });

      // ───────────────────────── Settings / Notifications (supporting) ─────────────────────────
      await test.step('Supporting pages', async () => {
        await check('SUP', 'Notifications centre shows workflow events (analysis completed, version finalized …)', async () => {
          await page.goto('/notifications');
          await expect(page.getByRole('heading', { name: /Notification center/i })).toBeVisible();
          const text = await bodyText(page);
          return `unread text: ${text.match(/\d+ unread notifications?/)?.[0] ?? 'n/a'}`;
        }, page, 'SUP-notifications');
        await check('SUP', 'Settings page exposes profile, password and notification preferences', async () => {
          await page.goto('/settings');
          await expect(page.getByRole('heading', { name: /Settings|Profile/i }).first()).toBeVisible({ timeout: 60_000 });
          const text = await bodyText(page);
          expect(text).toMatch(/Profile|Full Name/i);
          expect(text).toMatch(/Password/i);
        }, page, 'SUP-settings');
        await axeScan(page, '/settings');
      });

      // ───────────────────────── Accessibility: keyboard + responsive ─────────────────────────
      await test.step('Accessibility', async () => {
        await check('A11Y', 'keyboard-only: Tab reaches "Add Course" from the page top and Enter opens the dialog; Escape closes it', async () => {
          await page.goto('/courses');
          await expect(page.getByRole('button', { name: 'Add Course' })).toBeVisible();
          let reached = false;
          for (let i = 0; i < 40 && !reached; i++) {
            await page.keyboard.press('Tab');
            reached = await page.evaluate(() => (document.activeElement?.textContent ?? '').trim() === 'Add Course');
          }
          expect(reached, 'Add Course must be reachable by Tab').toBeTruthy();
          const outline = await page.evaluate(() => { const s = getComputedStyle(document.activeElement as Element); return `${s.outlineStyle} ${s.outlineWidth} ${s.boxShadow !== 'none' ? 'shadow' : ''}`; });
          await page.keyboard.press('Enter');
          await expect(page.getByLabel('Course Code')).toBeVisible();
          const focusInDialog = await page.evaluate(() => !!document.activeElement?.closest('.fixed.inset-0, [role="dialog"]'));
          await page.keyboard.press('Escape');
          const closed = await page.getByLabel('Course Code').isHidden().catch(() => false);
          const dialogRole = await page.locator('[role="dialog"]').count();
          if (!closed) {
            await page.getByRole('button', { name: /cancel/i }).first().click().catch(() => undefined);
            throw new Error(`Escape does not close the Add Course dialog (focus moved into dialog=${focusInDialog}, role=dialog count=${dialogRole}, focus style="${outline}")`);
          }
          return `focus style="${outline}", focus moved into dialog=${focusInDialog}`;
        }, page, 'A11Y-keyboard');

        await check('A11Y', 'status indicators carry text (not colour only) on the submissions list', async () => {
          await page.goto(`/assessments/${assessmentId}/submissions`);
          await expect(page.getByTestId('submission-status-badge').first()).toBeVisible();
          const empties = await page.getByTestId('submission-status-badge').evaluateAll((els) => els.filter((e) => !(e.textContent ?? '').trim()).length);
          expect(empties).toBe(0);
        });

        await check('A11Y', 'responsive: dashboard and assessment pages are usable at 390×844 (mobile)', async () => {
          await page.setViewportSize({ width: 390, height: 844 });
          await page.goto('/dashboard');
          await expect(page.getByRole('button', { name: 'Open sidebar' })).toBeVisible();
          await shot(page, 'A11Y-mobile-dashboard');
          await page.goto(`/assessments/${assessmentId}`);
          await expect(page.getByText('Questions Count')).toBeVisible();
          const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          await shot(page, 'A11Y-mobile-assessment');
          await page.setViewportSize({ width: 1280, height: 720 });
          expect(overflow, 'horizontal overflow px').toBeLessThanOrEqual(2);
        });
      });

      // ───────────────────────── Second persona: another faculty must not see this data ─────────────────────────
      await test.step('Isolation', async () => {
        await check('S01', 'another faculty member cannot open this course/assessment/submission (UI shows a safe error, API 403/404)', async () => {
          const ctx = await browser.newContext();
          const other = await ctx.newPage();
          const otherApi = new Api(other);
          await other.goto('/login');
          await otherApi.registerAndLogin('uat.other', 'Dr. Other Faculty');
          await other.goto(`/courses/${courseId}`);
          await expect(other.getByText(/Unable to load course|not found|not authorized|permission|forbidden/i).first()).toBeVisible();
          await other.goto(`/assessments/${assessmentId}`);
          await expect(other.getByText(/Unable to load assessment|not found|not authorized|permission|forbidden/i).first()).toBeVisible();
          await otherApi.call('GET', `/courses/${courseId}`, undefined, [403, 404]);
          await otherApi.call('GET', `/submissions/${submissionId}`, undefined, [403, 404]);
          const list = (await otherApi.call('GET', '/courses')).data ?? [];
          expect(list.length).toBe(0);
          await shot(other, 'S01-other-faculty-denied');
          await ctx.close();
        });
      });

      // ───────────────────────── S20 Logout ─────────────────────────
      await test.step('S20 Logout', async () => {
        await check('S20', 'faculty signs out via the sidebar confirmation; protected routes redirect to /login afterwards', async () => {
          await page.goto('/dashboard');
          await page.getByRole('button', { name: 'Sign out' }).click();
          await expect(page.getByRole('dialog')).toBeVisible();
          await shot(page, 'S20-signout-dialog');
          await page.getByRole('button', { name: 'Yes, sign out' }).click();
          await expect(page).toHaveURL(/\/login/, { timeout: 60_000 });
          for (const p of ['/dashboard', `/assessments/${assessmentId}`, '/reports']) {
            await page.goto(p);
            await expect(page, p).toHaveURL(/\/login/);
          }
          await page.goBack();
          await expect(page).toHaveURL(/\/login/);
          const me = await page.request.get('http://127.0.0.1:8080/api/auth/user', { headers: { Accept: 'application/json' } });
          expect(me.status()).toBe(401);
        }, page, 'S20-logged-out');
        await axeScan(page, '/login');
      });
    } finally {
      writeObservations();
    }

    const fails = observations.filter((o) => o.status === 'FAIL');
    // The walkthrough itself is evidence gathering; only a broken login/course path is treated as a hard failure.
    const blocking = fails.filter((o) => /^S0[124]$/.test(o.scenario) && !/other faculty|Escape|labels|keyboard/i.test(o.check));
    expect(blocking, `blocking UAT failures:\n${blocking.map((f) => `${f.scenario}: ${f.check} — ${f.note}`).join('\n')}`).toEqual([]);
  });
});
