import { test, expect } from '@playwright/test';
import { Api, expectNoCrash } from './helpers';

/**
 * Journeys 3 & 4 — run the live AI analysis from the Analysis page and act on a recommendation.
 * Questions are produced by the AI question generator (the product's real path), then approved and added.
 */
test.describe('Journeys 3–4: AI analysis and recommendations', () => {
  test('faculty runs AI analysis in the UI, sees the persisted results and accepts a recommendation', async ({ page }) => {
    test.setTimeout(240_000);
    const api = new Api(page);
    await api.registerAndLogin('journey3');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 30 });
    const questionIds = await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 3, 10);
    expect(questionIds).toHaveLength(3);

    // The assessment page shows the official questions and the analysis entry point
    await page.goto(`/assessments/${assessment.id}`);
    await expect(page.getByText('Questions Count')).toBeVisible();
    await expect(page.getByLabel(/Generate rubric for question 1/)).toBeVisible();

    // Analysis page: empty state → run → results
    await page.goto(`/assessments/${assessment.id}/analysis`);
    const run = page.getByRole('button', { name: 'Run AI Analysis', disabled: false }).first();
    await expect(run).toBeVisible();
    const analysisResponse = page.waitForResponse((r) => /\/api\/ai\/assessments\/\d+\/analyze/.test(r.url()) && r.request().method() === 'POST', { timeout: 180_000 });
    await run.click();
    await expect(page.getByText('Analyzing Complete Assessment...').first()).toBeVisible();
    const res = await analysisResponse;
    expect(res.status(), await res.text()).toBe(200);

    await expect(page.getByRole('heading', { name: /Assessment Rigor/ })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('heading', { name: 'AI Recommendations' })).toBeVisible();
    await expectNoCrash(page);

    // Database state matches what the page shows
    const status = await api.call('GET', `/ai/assessments/${assessment.id}/analysis-status`);
    expect(status.analysis_status).toBe('completed');
    expect(status.overall_score).toBeGreaterThanOrEqual(0);
    const recs = await api.call('GET', `/ai/assessments/${assessment.id}/recommendations`);
    const recList: any[] = recs.data?.recommendations ?? recs.data ?? [];
    expect(recList.length, 'analysis of a real paper must yield recommendations').toBeGreaterThan(0);

    // Journey 4: accept the first recommendation through the UI
    const accept = page.getByRole('button', { name: 'Accept', exact: true }).first();
    await accept.scrollIntoViewIfNeeded();
    await accept.click();
    const feedbackResponse = page.waitForResponse((r) => /\/api\/recommendations\/\d+\/feedback$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Confirm & Accept' }).click();
    expect((await feedbackResponse).status()).toBe(200);
    await expect(page.getByText('Accepted').first()).toBeVisible();

    const after = await api.call('GET', `/ai/assessments/${assessment.id}/recommendations`);
    const afterList: any[] = after.data?.recommendations ?? after.data ?? [];
    expect(afterList.some((r) => String(r.status).toLowerCase() === 'accepted'), 'decision must be persisted').toBeTruthy();

    // Feedback page lists the decision
    await page.goto('/feedback');
    await expect(page.getByText(/accepted/i).first()).toBeVisible();
    await expectNoCrash(page);

    // Re-running yields a new analysis version while the faculty decision survives
    const second = await api.runAnalysis(assessment.id);
    expect(second.status).toBe('success');
    const kept = await api.call('GET', `/ai/assessments/${assessment.id}/recommendations`);
    const keptList: any[] = kept.data?.recommendations ?? kept.data ?? [];
    expect(keptList.some((r) => String(r.status).toLowerCase() === 'accepted'), 'faculty decision must survive re-analysis').toBeTruthy();
  });

  test('analysis page explains when an assessment has no questions instead of failing silently', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('journey3b');
    const course = await api.createCourse();
    const assessment = await api.createAssessment(course.id);
    await page.goto(`/assessments/${assessment.id}/analysis`);
    const run = page.getByRole('button', { name: 'Run AI Analysis', disabled: false }).first();
    await expect(run).toBeVisible();
    const response = page.waitForResponse((r) => /\/api\/ai\/assessments\/\d+\/analyze/.test(r.url()));
    await run.click();
    expect((await response).status()).toBe(422);
    await expect(page.getByText(/no questions/i).first()).toBeVisible();
    await expectNoCrash(page);
  });
});
