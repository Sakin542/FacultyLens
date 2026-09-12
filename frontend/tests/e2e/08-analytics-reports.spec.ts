import { test, expect } from '@playwright/test';
import { Api, expectNoCrash } from './helpers';

/** Journey 8 — analytics reflect the data; an institutional report is previewed, generated and downloaded. */
test.describe('Journey 8: analytics and institutional reports', () => {
  test('analytics show the faculty data and a PDF report is generated and downloaded from the UI', async ({ page }) => {
    test.setTimeout(300_000);
    const api = new Api(page);
    await api.registerAndLogin('journey8');

    // Empty analytics before any data
    await page.goto('/analytics');
    await expect(page.getByTestId('academic-analytics-page')).toBeVisible();
    await expect(page.getByTestId('analytics-empty-state')).toBeVisible();
    await expectNoCrash(page);

    const course = await api.createCourse({ course_name: 'Artificial Intelligence' });
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 30 });
    await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 3, 10);
    const analysis = await api.runAnalysis(assessment.id);
    expect(analysis.status).toBe('success');

    // Analytics now reflect 1 course / 1 assessment / 3 questions / 1 analyzed assessment
    await page.goto('/analytics');
    await page.getByRole('button', { name: 'Recalculate analytics' }).click();
    await expect(page.getByTestId('kpi-grid')).toBeVisible();
    await expect(page.getByTestId('kpi-courses')).toContainText('1');
    await expect(page.getByTestId('kpi-assessments')).toContainText('1');
    await expect(page.getByTestId('kpi-questions')).toContainText('3');
    const overview = await api.call('GET', '/analytics/overview?fresh=1');
    expect(overview.data.kpis.courses.value).toBe(1);
    expect(overview.data.kpis.questions.value).toBe(3);
    expect(overview.data.assessment_quality.analyzed_assessments).toBe(1);

    // ---- report builder: type → scope → filters → preview → generate
    await page.goto('/reports');
    await page.getByRole('button', { name: 'Create report' }).first().click();
    await expect(page).toHaveURL(/\/reports\/create/);
    await page.locator('#report-type').selectOption('ASSESSMENT_QUALITY');
    await page.locator('#report-scope').selectOption('ASSESSMENT');
    await page.locator('#f-course').selectOption({ value: String(course.id) });
    await page.locator('#f-assessment').selectOption({ value: String(assessment.id) });
    await page.getByRole('radio', { name: /PDF/ }).click();

    const previewed = page.waitForResponse((r) => r.url().endsWith('/api/reports/preview'));
    await page.getByRole('button', { name: 'Preview Report' }).click();
    const previewRes = await previewed;
    expect(previewRes.status(), await previewRes.text()).toBe(200);
    await expect(page.getByTestId('report-preview')).toBeVisible();
    await expect(page.getByTestId('report-preview')).toContainText('Midterm Examination');

    const generated = page.waitForResponse((r) => r.url().endsWith('/api/reports') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Generate Report' }).click();
    const genRes = await generated;
    expect([201, 202]).toContain(genRes.status());
    const reportId = (await genRes.json()).data.id;
    await expect(page).toHaveURL(new RegExp(`/reports/${reportId}$`));
    await expect(page.getByTestId('report-metadata')).toBeVisible();
    await expect(page.getByText('COMPLETED', { exact: false }).first()).toBeVisible({ timeout: 60_000 });

    // ---- download through the UI (authenticated endpoint → real PDF bytes)
    const downloadPromise = page.waitForEvent('download', { timeout: 60_000 });
    await page.getByRole('button', { name: 'Download PDF' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.pdf$/i);
    const path = await download.path();
    const fs = await import('node:fs');
    const bytes = fs.readFileSync(path!);
    expect(bytes.length).toBeGreaterThan(1000);
    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
    await expectNoCrash(page);

    // ---- history lists it; DB agrees
    await page.goto('/reports');
    await expect(page.getByTestId('report-history')).toBeVisible();
    await expect(page.getByTestId(`report-row-${reportId}`)).toBeVisible();
    const row = await api.call('GET', `/reports/${reportId}`);
    expect(row.data.status).toBe('COMPLETED');
    expect(row.data.format).toBe('PDF');
    expect(row.data.report_type).toBe('ASSESSMENT_QUALITY');

    // Another faculty member cannot see or download this report
    await api.logout();
    const api2 = new Api(page);
    await api2.registerAndLogin('journey8-other');
    await api2.call('GET', `/reports/${reportId}`, undefined, [403, 404]);
    await api2.call('GET', `/reports/${reportId}/download`, undefined, [403, 404]);
    await page.goto(`/reports/${reportId}`);
    await expect(page.getByText(/not found|not authorized|permission|forbidden|could not be found/i).first()).toBeVisible();
    await expectNoCrash(page);
  });
});
