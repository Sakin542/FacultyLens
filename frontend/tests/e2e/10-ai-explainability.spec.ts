import { test, expect } from '@playwright/test';
import { Api, expectNoCrash } from './helpers';

/**
 * STEP 45 — AI explainability & transparency.
 * Faculty opens analysis → views an AI result → opens the explanation → inspects evidence → reviews the
 * limitations → overrides the result → the audit event is recorded and visible in the course activity feed.
 */
test.describe('Journey 10: AI explainability', () => {
  test('faculty can inspect, question and override an AI result without trusting it blindly', async ({ page }) => {
    test.setTimeout(240_000);
    const api = new Api(page);
    await api.registerAndLogin('journey10');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 30 });
    const questionIds = await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 3, 10);
    expect(questionIds).toHaveLength(3);
    const analysis = await api.runAnalysis(assessment.id);
    expect(analysis.status).toBe('success');

    // 1. Assessment page shows AI badges with a "Why?" affordance (explanations are NOT fetched yet)
    let explanationRequests = 0;
    page.on('request', (r) => {
      if (/\/api\/ai-results\/[a-z_]+\/\d+\/explanation/.test(r.url())) explanationRequests++;
    });
    await page.goto(`/assessments/${assessment.id}`);
    await expect(page.getByText('Questions Count')).toBeVisible();
    const bloomBadge = page.getByRole('button', { name: /Why\? Explain Bloom level/ }).first();
    await expect(bloomBadge).toBeVisible();
    expect(explanationRequests, 'explanations must be lazy').toBe(0);

    // 2. Open the explanation (Level 1: result, why, evidence, limitations)
    await bloomBadge.click();
    const dialog = page.getByRole('dialog', { name: 'Bloom level' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByTestId('explanation-result')).not.toBeEmpty({ timeout: 30_000 });
    await expect(dialog.getByTestId('explanation-why')).toContainText('FacultyLens');
    await expect(dialog.getByTestId('confidence-value')).toHaveText('Not available');
    await expect(dialog.getByTestId('limitations-panel')).toContainText('Bloom classification can involve expert judgment');
    expect(explanationRequests).toBe(1);
    const panelText = (await dialog.textContent()) ?? '';
    expect(panelText.toLowerCase()).not.toContain('system prompt');
    expect(panelText.toLowerCase()).not.toContain('model thought');

    // 3. Inspect evidence (Level 3) and technical details (Level 2) — keyboard operable disclosures
    const technical = dialog.getByRole('button', { name: /Technical details/ });
    await technical.focus();
    await page.keyboard.press('Enter');
    await expect(technical).toHaveAttribute('aria-expanded', 'true');
    await expect(dialog.getByTestId('method-type')).toContainText('RULE_BASED');
    await expect(dialog.getByTestId('model-info')).toContainText('Analysis version');
    await expect(dialog.getByTestId('evaluation-status')).toContainText('Evaluation status');
    const evidenceResponse = page.waitForResponse((r) => /\/api\/ai-results\/bloom\/\d+\/events$/.test(r.url()) && r.request().method() === 'POST');
    await dialog.getByRole('button', { name: /Detailed evidence/ }).click();
    expect((await evidenceResponse).status()).toBe(202);
    await expect(dialog.getByTestId('evidence-item').first()).toBeVisible();

    // 4. Override the result — faculty decides; the AI value stays for reference
    await dialog.getByTestId('override-button').click();
    const override = page.getByTestId('override-dialog');
    await expect(override).toBeVisible();
    await override.getByTestId('override-value').selectOption('EVALUATE');
    await override.getByTestId('override-reason').selectOption('ACADEMIC_JUDGMENT');
    const overrideResponse = page.waitForResponse((r) => /\/api\/ai-results\/bloom\/\d+\/override$/.test(r.url()) && r.request().method() === 'POST');
    await override.getByTestId('override-submit').click();
    expect((await overrideResponse).status()).toBe(200);
    await expect(dialog.getByTestId('review-latest')).toContainText('OVERRIDDEN');
    await expectNoCrash(page);

    // 5. Persisted state: faculty field changed, AI field untouched, audit event recorded
    const questions = (await api.call('GET', `/assessments/${assessment.id}`)).data?.questions ?? [];
    const overridden = questions.find((q: any) => String(q.cognitive_level) === 'Evaluate');
    expect(overridden, 'faculty cognitive level must be updated').toBeTruthy();
    expect(overridden.ai_cognitive_level, 'AI column must be preserved').toBeTruthy();
    const activity = await api.call('GET', `/courses/${course.id}/collaboration/activity?per_page=50`);
    const actions = (activity.data ?? []).map((a: any) => a.action);
    expect(actions).toContain('AI_RESULT_OVERRIDDEN');
    expect(actions).toContain('AI_EXPLANATION_VIEWED');
    expect(actions).toContain('AI_EVIDENCE_VIEWED');
    const reviews = await api.call('GET', `/ai-results/bloom/${overridden.id}/reviews`);
    expect(reviews.data[0].action).toBe('OVERRIDDEN');
    expect(reviews.data[0].override_reason).toBe('ACADEMIC_JUDGMENT');

    // Escape closes the modal
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    // 6. Analysis page: "How this score is calculated" + similarity shown as x / 1.00 (never a probability)
    await page.goto(`/assessments/${assessment.id}/analysis`);
    await expect(page.getByRole('heading', { name: /Assessment Rigor/ })).toBeVisible({ timeout: 30_000 });
    await page.getByTestId('quality-how-calculated').click();
    const qualityDialog = page.getByRole('dialog', { name: 'Overall quality score' });
    await expect(qualityDialog.getByTestId('explanation-result')).toContainText('/ 100', { timeout: 30_000 });
    await expect(qualityDialog.getByTestId('explanation-why')).toContainText('weighted combination');
    await qualityDialog.getByRole('button', { name: /Technical details/ }).click();
    await expect(qualityDialog.getByTestId('explanation-details')).toContainText('Weights');
    await page.keyboard.press('Escape');

    const recWhy = page.getByRole('button', { name: /Why\? Explain recommendation/ }).first();
    if (await recWhy.count()) {
      await recWhy.scrollIntoViewIfNeeded();
      await recWhy.click();
      const recDialog = page.getByRole('dialog', { name: 'Recommendation' });
      await expect(recDialog.getByTestId('explanation-why')).toBeVisible({ timeout: 30_000 });
      await recDialog.getByRole('button', { name: /Technical details/ }).click();
      await expect(recDialog.getByTestId('explanation-details')).toContainText('Source');
      await page.keyboard.press('Escape');
    }
    await expectNoCrash(page);
  });

  test('a collaborator without edit rights can read the explanation but cannot override', async ({ page, browser }) => {
    test.setTimeout(180_000);
    const api = new Api(page);
    const owner = await api.registerAndLogin('journey10owner');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 10 });
    const [qid] = await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 1, 10);
    await api.runAnalysis(assessment.id);

    // Owner sees the override action
    const own = await api.call('GET', `/ai-results/bloom/${qid}/explanation`);
    expect(own.data.review.can_review).toBe(true);
    expect(own.data.review.actions).toContain('OVERRIDE');

    // Invite a VIEWER in a second browser context
    const ctx = await browser.newContext();
    const viewerPage = await ctx.newPage();
    const viewerApi = new Api(viewerPage);
    const viewer = await viewerApi.registerAndLogin('journey10viewer');
    await api.call('POST', `/courses/${course.id}/collaborators/invite`, { email: viewer.email, role: 'VIEWER' });
    const mine = await viewerApi.call('GET', '/collaboration/invitations');
    const invitation = (mine.data ?? []).find((i: any) => Number(i.course_id ?? i.course?.id) === Number(course.id));
    expect(invitation, 'viewer must see the pending invitation').toBeTruthy();
    await viewerApi.call('POST', `/collaboration/my-invitations/${invitation.id}/accept`);

    // Viewer can read the explanation (transparency) but gets no decision actions and cannot override
    const seen = await viewerApi.call('GET', `/ai-results/bloom/${qid}/explanation`);
    expect(seen.data.result.label).toBeTruthy();
    expect(seen.data.review.can_review).toBe(false);
    expect(seen.data.review.actions).toEqual([]);
    await viewerApi.call('POST', `/ai-results/bloom/${qid}/override`, { value: { label: 'APPLY' }, reason: 'OTHER' }, [403]);
    // Student data stays private: the viewer cannot read grading explanations for this course
    await viewerApi.call('GET', `/ai-results/inter_grader/${assessment.id}/explanation`, undefined, [403]);
    expect(owner.id).not.toBe(viewer.id);
    await ctx.close();
  });
});
