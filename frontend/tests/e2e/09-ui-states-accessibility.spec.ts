import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { Api, expectNoCrash, loginViaUi } from './helpers';

/**
 * UI resilience: error / loading / empty states, AI-service outage handling, unknown routes,
 * and WCAG 2.1 A/AA automated checks (axe-core) on every major page.
 */
test.describe('UI states and accessibility', () => {
  test('empty states are explicit on every list page for a brand-new account', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('ui-empty');
    const checks: [string, RegExp][] = [
      ['/courses', /No courses registered yet/],
      ['/assessments', /no assessments|create your first|get started/i],
      ['/analytics', /no data|nothing to analyse|nothing to analyze|create a course|no courses|no assessments|start/i],
      ['/reports', /no reports|create your first report|nothing here|generate/i],
      ['/feedback', /no feedback|no decisions|nothing/i],
    ];
    for (const [path, pattern] of checks) {
      await page.goto(path);
      await expect(page.getByText(pattern).first(), `${path} must explain that it is empty`).toBeVisible();
      await expectNoCrash(page);
    }
  });

  test('a backend 500 on a list endpoint shows an error state with retry, not a blank page', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('ui-error');
    await page.route('**/api/courses**', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) }));
    await page.goto('/courses');
    await expect(page.getByText(/could not|failed|error|try again|unable/i).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /retry|try again|reload/i }).first()).toBeVisible();
    await expectNoCrash(page);
    await page.unroute('**/api/courses**');
    await page.getByRole('button', { name: /retry|try again|reload/i }).first().click();
    await expect(page.getByText('No courses registered yet')).toBeVisible();
  });

  test('AI service outage produces a controlled error message on the Analysis page and the app stays usable', async ({ page }) => {
    test.setTimeout(240_000);
    const api = new Api(page);
    await api.registerAndLogin('ui-ai-down');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id);
    await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 2, 10);

    // Simulate the backend reporting an AI outage (what Laravel returns when FastAPI is unreachable)
    await page.route(/\/api\/ai\/assessments\/\d+\/analyze/, (route) => route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({ status: 'error', message: 'AI Service is currently unavailable while performing unified assessment analysis.' }) }));
    await page.goto(`/assessments/${assessment.id}/analysis`);
    await page.getByRole('button', { name: 'Run AI Analysis', disabled: false }).first().click();
    await expect(page.getByRole('alert').filter({ hasText: /AI Service is currently unavailable/ })).toBeVisible();
    await expectNoCrash(page);

    // Non-AI parts keep working
    await page.goto(`/courses/${course.id}`);
    await expect(page.getByText('Course Learning Outcomes')).toBeVisible();
    await expect(page.getByText('LO1', { exact: true })).toBeVisible();
  });

  test('a network failure (server unreachable) is reported in plain language', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('ui-offline');
    await page.goto('/dashboard');
    await page.route('**/api/courses**', (route) => route.abort('connectionrefused'));
    await page.goto('/courses');
    await expect(page.getByText(/Unable to reach the FacultyLens server/)).toBeVisible();
    await expectNoCrash(page);
  });

  test('unknown routes render the not-found page; unknown records render an error, not a crash', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('ui-404');
    await page.goto('/this/route/does/not/exist');
    await expect(page.getByText(/not found|404|doesn.t exist/i).first()).toBeVisible();
    await page.goto('/assessments/999999');
    await expect(page.getByText(/Unable to load assessment|not found|not authorized/i).first()).toBeVisible();
    await page.goto('/courses/999999');
    await expect(page.getByText(/Unable to load course|not found|not authorized/i).first()).toBeVisible();
    await expectNoCrash(page);
  });

  test('loading indicators are shown while data is in flight', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('ui-loading');
    await page.route('**/api/analytics/overview**', async (route) => {
      await new Promise((r) => setTimeout(r, 1500));
      await route.continue();
    });
    await page.goto('/analytics');
    await expect(page.getByTestId('analytics-loading')).toBeVisible();
    await expect(page.getByTestId('analytics-loading')).toBeHidden({ timeout: 30_000 });
  });

  test.describe('accessibility (axe-core, WCAG 2.1 A/AA)', () => {
    const scan = async (page: import('@playwright/test').Page, label: string) => {
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
      const serious = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
      const summary = serious.map((v) => `${v.id} (${v.impact}) ×${v.nodes.length}: ${v.help} → ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`).join('\n');
      expect.soft(serious, `${label}: serious/critical accessibility violations\n${summary}`).toEqual([]);
      return results.violations.length;
    };

    test('public pages', async ({ page }) => {
      await page.goto('/login');
      await scan(page, '/login');
      await page.goto('/register');
      await scan(page, '/register');
    });

    test('authenticated pages with data', async ({ page }) => {
      test.setTimeout(300_000);
      const api = new Api(page);
      await api.registerAndLogin('a11y');
      const course = await api.createCourse();
      const [lo1] = await api.createLearningOutcomes(course.id);
      const assessment = await api.createAssessment(course.id);
      await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 2, 10);
      await api.runAnalysis(assessment.id);

      for (const path of ['/dashboard', '/courses', `/courses/${course.id}`, '/assessments', `/assessments/${assessment.id}`, `/assessments/${assessment.id}/analysis`, `/assessments/${assessment.id}/versions`, `/assessments/${assessment.id}/submissions`, '/analytics', '/reports', '/reports/create', '/feedback']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        await scan(page, path);
      }
    });

    test('keyboard: the login form is fully operable without a mouse', async ({ page }) => {
      const api = new Api(page);
      const { email } = await api.registerAndLogin('kbd');
      await api.logout();
      await page.goto('/login');
      await page.getByLabel('University Email').focus();
      await page.keyboard.type(email);
      await page.keyboard.press('Tab');
      await page.keyboard.type('E2E-Journey#2026');
      await page.keyboard.press('Enter');
      await expect(page).toHaveURL(/\/dashboard/);
      // Skip link / first tab stop lands on something focusable and visible
      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => document.activeElement?.tagName);
      expect(['A', 'BUTTON', 'INPUT']).toContain(focused);
    });
  });
});
