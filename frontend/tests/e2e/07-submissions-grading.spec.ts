import { test, expect } from '@playwright/test';
import { Api, expectNoCrash } from './helpers';

/**
 * Journey 7 — student submission → answers → faculty grading → status GRADED → performance analysis.
 * The submission is created and one answer graded through the UI; remaining answers and students use the
 * same public endpoints the UI calls, so that performance analysis has enough finalized marks (min. responses).
 */
test.describe('Journey 7: submissions, grading and performance', () => {
  test('faculty records a submission, grades answers and runs student performance analysis', async ({ page }) => {
    test.setTimeout(300_000);
    const api = new Api(page);
    await api.registerAndLogin('journey7');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 20 });
    const questionIds = await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 2, 10);

    // ---- create the first submission through the UI (new student)
    await page.goto(`/assessments/${assessment.id}/submissions`);
    await expect(page.getByTestId('student-submissions-page')).toBeVisible();
    await page.getByRole('button', { name: 'Add Submission' }).first().click();
    const newStudent = page.getByRole('button', { name: 'New student' });
    if (await newStudent.isVisible()) await newStudent.click();
    await page.locator('#new-student-identifier').fill('J7-STU-001');
    await page.locator('#new-student-name').fill('Student One');
    await page.locator('#submission-identifier').fill('MID-001');
    const created = page.waitForResponse((r) => r.url().endsWith(`/assessments/${assessment.id}/submissions`) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create Submission' }).click();
    const createRes = await created;
    expect(createRes.status(), await createRes.text()).toBe(201);
    const submission = (await createRes.json()).data;
    await expect(page.getByText('Student One').first()).toBeVisible();

    // Answers for the first student arrive through the answer endpoint (UI "Add Answer" uses the same route)
    for (const [i, qid] of questionIds.entries()) {
      await api.call('POST', `/submissions/${submission.id}/answers`, { question_id: qid, answer_text: `Student One answer ${i + 1}: BFS is complete and optimal for unit costs; DFS is memory-efficient but not complete on infinite graphs.` }, [201]);
    }

    // ---- grade the first answer through the UI
    await page.goto(`/submissions/${submission.id}`);
    await expect(page.getByTestId('submission-details-page')).toBeVisible();
    await expect(page.getByTestId('student-name')).toContainText('Student One');
    await page.getByTestId('transition-UNDER_REVIEW').click();
    await expect(page.getByTestId('transition-GRADED')).toBeVisible();

    const firstCard = page.getByTestId('student-answer-card').first();
    await firstCard.getByTestId('grade-manually').click();
    await firstCard.getByTestId('final-marks-input').fill('7.5');
    const finalized = page.waitForResponse((r) => /\/student-answers\/\d+\/finalize-grade$/.test(r.url()));
    await firstCard.getByTestId('finalize-grade-button').click();
    expect((await finalized).status()).toBe(200);
    // The success toast is transient (4 s) and only appears after the page re-fetches the submission, which can take
    // longer than that on a cold dev server — so the persisted marks are the assertion that matters.
    await expect(firstCard.getByTestId('answer-marks')).toContainText('7.5', { timeout: 60_000 });

    // Marks beyond the question maximum are rejected client-side before any request is sent
    const secondCard = page.getByTestId('student-answer-card').nth(1);
    await secondCard.getByTestId('grade-manually').click();
    await secondCard.getByTestId('final-marks-input').fill('11');
    await secondCard.getByTestId('finalize-grade-button').click();
    await expect(secondCard.getByText(/cannot exceed 10/)).toBeVisible();
    await secondCard.getByTestId('final-marks-input').fill('6');
    const finalized2 = page.waitForResponse((r) => /\/student-answers\/\d+\/finalize-grade$/.test(r.url()));
    await secondCard.getByTestId('finalize-grade-button').click();
    expect((await finalized2).status()).toBe(200);

    await page.getByTestId('transition-GRADED').click();
    await expect(page.getByTestId('transition-RETURNED')).toBeVisible();
    await expect(page.getByTestId('submission-marks')).toContainText('13.5');
    await expectNoCrash(page);

    // ---- more students so the performance engine has enough finalized responses
    const marks = [[9, 8], [5, 4], [8, 7], [3, 6], [10, 9]];
    for (const [i, [m1, m2]] of marks.entries()) {
      const student = await api.createStudent(`J7-STU-${String(i + 2).padStart(3, '0')}`, `Student ${i + 2}`);
      const sub = await api.createSubmissionWithAnswers(assessment.id, student.id, questionIds);
      await api.call('PATCH', `/submissions/${sub.id}/status`, { status: 'UNDER_REVIEW' });
      for (const [j, m] of [m1, m2].entries()) {
        await api.call('POST', `/student-answers/${sub.answerIds[j]}/finalize-grade`, { final_marks: m });
      }
      await api.call('PATCH', `/submissions/${sub.id}/status`, { status: 'GRADED' });
    }

    // ---- performance analysis from the Analysis page
    await page.goto(`/assessments/${assessment.id}/analysis`);
    const perfResponse = page.waitForResponse((r) => r.url().endsWith(`/assessments/${assessment.id}/performance/analyze`), { timeout: 120_000 });
    await page.getByRole('button', { name: 'Analyze Student Performance' }).click();
    const perfRes = await perfResponse;
    expect(perfRes.status(), await perfRes.text()).toBe(200);
    await expect(page.getByTestId('performance-summary')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('performance-summary')).toContainText('Overall Average');
    await expect(page.getByTestId('performance-summary')).toContainText('6 submissions');

    // 6 students × 2 questions = 12 finalized answers; total awarded 7.5+6+9+8+5+4+8+7+3+6+10+9 = 82.5 of 120 → 68.8%
    const perf = await api.call('GET', `/assessments/${assessment.id}/performance`);
    const run = perf.data.analysis ?? perf.data;
    expect(run.status).toBe('COMPLETED');
    expect(Number(run.finalized_answer_count)).toBe(12);
    expect(Number(run.overall_average_percentage)).toBeCloseTo(68.75, 0);
    await expect(page.getByTestId('performance-summary')).toContainText(/68\.\d%|69\.?\d?%/);
    await expectNoCrash(page);
  });
});
