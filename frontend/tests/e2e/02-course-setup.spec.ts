import { test, expect } from '@playwright/test';
import { Api, expectNoCrash } from './helpers';

/** Journey 2 — Create course → add learning outcomes → create assessment (all through the UI). */
test.describe('Journey 2: course, outcomes and assessment', () => {
  test('faculty creates a course, three outcomes and a midterm through the forms', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('journey2');
    const code = `CSE-${Date.now().toString().slice(-6)}`;

    // ---- course
    await page.goto('/courses');
    await expect(page.getByText('No courses registered yet')).toBeVisible();
    await page.getByRole('button', { name: 'Add Course' }).click();
    await page.getByLabel('Course Code').fill(code);
    await page.getByLabel('Course Title / Name').fill('Artificial Intelligence');
    await page.getByLabel('Academic Year').fill('2026-2027');
    await page.getByRole('button', { name: 'Create Course' }).click();
    await expect(page.getByText('Artificial Intelligence').first()).toBeVisible();
    await expect(page.getByText(code).first()).toBeVisible();

    const courses = await api.call('GET', '/courses');
    const course = (courses.data ?? courses).find((c: any) => c.course_code === code);
    expect(course, 'course must be persisted through the UI').toBeTruthy();

    // ---- learning outcomes
    await page.goto(`/courses/${course.id}`);
    await expect(page.getByText('No learning outcomes defined')).toBeVisible();
    for (const [i, [outcomeCode, desc]] of [
      ['LO1', 'Explain uninformed and informed search strategies and their complexity.'],
      ['LO2', 'Apply knowledge representation techniques to model a problem domain.'],
      ['LO3', 'Evaluate machine learning models using appropriate metrics.'],
    ].entries()) {
      await page.getByRole('button', { name: 'Add Outcome' }).first().click();
      await expect(page.getByRole('heading', { name: 'Add Learning Outcome' })).toBeVisible();
      await page.getByLabel('Outcome Code').fill(outcomeCode);
      await page.getByPlaceholder('Clearly describe what students should be able to demonstrate').fill(desc);
      await page.getByRole('button', { name: 'Add Outcome' }).last().click();
      await expect(page.getByRole('heading', { name: 'Add Learning Outcome' })).toBeHidden();
      await expect(page.getByText(outcomeCode, { exact: true })).toBeVisible();
      await expect(page.getByText(desc)).toBeVisible();
      expect((await api.call('GET', `/courses/${course.id}/learning-outcomes`)).data).toHaveLength(i + 1);
    }

    // ---- assessment
    await page.goto('/assessments');
    await page.getByRole('button', { name: 'Create Assessment' }).first().click();
    await page.getByLabel('Assessment Title').waitFor();
    await page.locator('select').filter({ has: page.locator(`option:has-text("${code}")`) }).first().selectOption({ label: `${code} — Artificial Intelligence` });
    await page.getByLabel('Assessment Title').fill('Midterm Examination');
    await page.getByLabel('Total Marks').fill('100');
    await page.getByLabel('Duration (mins)').fill('120');
    await page.getByRole('button', { name: 'Create Assessment' }).last().click();
    await expect(page.getByLabel('Assessment Title')).toBeHidden();
    await expect(page.getByText('Midterm Examination').first()).toBeVisible();

    const assessments = (await api.call('GET', `/courses/${course.id}/assessments`)).data;
    expect(assessments).toHaveLength(1);
    expect(Number(assessments[0].total_marks)).toBe(100);
    expect(Number(assessments[0].duration_minutes)).toBe(120);

    // ---- detail page reflects everything
    await page.goto(`/assessments/${assessments[0].id}`);
    await expect(page.getByText('Total Marks')).toBeVisible();
    await expect(page.getByText('Questions Count')).toBeVisible();
    await expect(page.getByTestId('blueprint-link')).toBeVisible();
    await expect(page.getByTestId('versions-link')).toBeVisible();
    await expectNoCrash(page);
  });

  test('course form rejects an empty submission and keeps the user on the form', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('journey2b');
    await page.goto('/courses');
    await page.getByRole('button', { name: 'Add Course' }).click();
    await page.getByRole('button', { name: 'Create Course' }).click();
    await expect(page.getByLabel('Course Code')).toBeVisible();
    expect(((await api.call('GET', '/courses')).data ?? []).length).toBe(0);
    await expectNoCrash(page);
  });
});
