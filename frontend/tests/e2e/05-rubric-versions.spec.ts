import { test, expect } from '@playwright/test';
import { Api, expectNoCrash } from './helpers';

/** Journeys 5 & 6 — rubric generation/approval and version snapshot/finalize, through the UI. */
test.describe('Journeys 5–6: rubric and versioning', () => {
  test('faculty generates an AI rubric, approves it, then snapshots and finalizes a version', async ({ page }) => {
    test.setTimeout(240_000);
    const api = new Api(page);
    await api.registerAndLogin('journey5');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 20 });
    const [q1] = await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 2, 10);

    // ---- Journey 5: rubric
    await page.goto(`/assessments/${assessment.id}`);
    await page.getByLabel(/Generate rubric for question 1/).click();
    await expect(page.getByTestId('rubric-empty')).toBeVisible();
    const generated = page.waitForResponse((r) => r.url().includes(`/questions/${q1}/rubrics/generate`), { timeout: 120_000 });
    await page.getByRole('button', { name: 'Generate AI Rubric' }).click();
    const genRes = await generated;
    expect(genRes.status(), await genRes.text()).toBe(201);
    await expect(page.getByRole('button', { name: 'Approve Rubric' })).toBeEnabled({ timeout: 30_000 });

    const approved = page.waitForResponse((r) => /\/rubrics\/\d+\/approve$/.test(r.url()));
    await page.getByRole('button', { name: 'Approve Rubric' }).click();
    expect((await approved).status()).toBe(200);
    await expect(page.getByText(/approved/i).first()).toBeVisible();
    await expectNoCrash(page);

    const rubric = await api.call('GET', `/questions/${q1}/rubrics`);
    const current = (rubric.data as any[]).find((r) => r.status === 'APPROVED') ?? rubric.data[0];
    expect(String(current.status)).toBe('APPROVED');
    expect(Number(current.criteria.reduce((s: number, c: any) => s + Number(c.max_marks), 0))).toBeCloseTo(10, 1);

    // ---- Journey 6: versions
    await page.goto(`/assessments/${assessment.id}/versions`);
    await expect(page.getByTestId('assessment-versions-page')).toBeVisible();
    await expect(page.getByText('No versions yet')).toBeVisible();
    await page.getByRole('button', { name: /Create v1\.0|Create new version/ }).first().click();
    await page.getByRole('dialog').locator('textarea').fill('Initial paper for the midterm.');
    const created = page.waitForResponse((r) => r.url().endsWith(`/assessments/${assessment.id}/versions`) && r.request().method() === 'POST');
    await page.getByRole('dialog').getByRole('button', { name: /Create v1\.0|Create draft version/ }).click();
    const createRes = await created;
    expect(createRes.status(), await createRes.text()).toBe(201);
    const versionId = (await createRes.json()).data.version.id;

    await page.goto(`/assessments/${assessment.id}/versions/${versionId}`);
    await expect(page.getByTestId('assessment-version-detail-page')).toBeVisible();
    page.once('dialog', (d) => d.accept());
    const finalized = page.waitForResponse((r) => r.url().endsWith(`/assessment-versions/${versionId}/finalize`));
    await page.getByRole('button', { name: 'Finalize', exact: true }).click();
    const finRes = await finalized;
    expect(finRes.status(), await finRes.text()).toBe(200);
    await expect(page.getByText(/Finalized versions are immutable/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
    await expectNoCrash(page);

    const detail = await api.call('GET', `/assessments/${assessment.id}/versions/${versionId}`);
    expect(detail.data.version.status).toBe('FINALIZED');
    expect(detail.data.version.questions?.length ?? detail.data.questions?.length).toBe(2);
    // Immutable: the API refuses edits with 409
    await api.call('PUT', `/assessment-versions/${versionId}`, { title: 'tamper' }, [409]);
  });
});
