import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { Api, expectNoCrash, loginViaUi } from './helpers';

/**
 * STEP 47 — Notification system, end to end against the live stack (Laravel + MySQL + queue worker + AI service).
 *
 *   E2E 1  AI analysis completes → bell shows the unread count → notification opens the analysis page
 *   E2E 2  Faculty A invites Faculty B → B is notified → B accepts → A is notified of the acceptance
 *   E2E 3  Report generated → notification → opens the protected report page
 *   E2E 4  Authorization: user A cannot read / mutate user B's notification ids (404), and a notification never grants access
 *   plus   notification center filters / mark-all / dismiss / preferences and an axe scan
 */

async function unreadCount(api: Api): Promise<number> {
  return (await api.call('GET', '/notifications/unread-count')).data.unread_count as number;
}

async function waitForNotification(api: Api, type: string, timeoutMs = 60_000): Promise<any> {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const res = await api.call('GET', '/notifications?per_page=50');
    const hit = (res.data as any[]).find((n) => n.type === type);
    if (hit) return hit;
    await new Promise((r) => setTimeout(r, 1500));
  }
  throw new Error(`Notification ${type} did not arrive within ${timeoutMs}ms`);
}

test.describe('STEP 47: notification system', () => {
  test('E2E 1 — AI analysis completion produces a notification that opens the analysis page', async ({ page }) => {
    test.setTimeout(300_000);
    const api = new Api(page);
    await api.registerAndLogin('notif1');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 30 });
    const questionIds = await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 3, 10);
    expect(questionIds).toHaveLength(3);
    // Question generation itself produced a notification for the requester (drafts, not approved questions).
    const qgen = await waitForNotification(api, 'QUESTION_GENERATION_COMPLETED', 15_000);
    expect(qgen.category).toBe('AI');
    expect(qgen.message).toContain('ready for faculty review');
    const before = await unreadCount(api);

    await page.goto(`/assessments/${assessment.id}/analysis`);
    const run = page.getByRole('button', { name: 'Run AI Analysis', disabled: false }).first();
    await expect(run).toBeVisible();
    const analysisResponse = page.waitForResponse((r) => /\/api\/ai\/assessments\/\d+\/analyze/.test(r.url()) && r.request().method() === 'POST', { timeout: 180_000 });
    await run.click();
    const res = await analysisResponse;
    expect(res.status(), await res.text()).toBe(200);
    await expect(page.getByRole('heading', { name: /Assessment Rigor/ })).toBeVisible({ timeout: 30_000 });

    // Notification exists in the DB (queue worker processed it), addressed to this faculty only, with safe payload
    const n = await waitForNotification(api, 'AI_ANALYSIS_COMPLETED');
    expect(n.title).toBe('Assessment analysis completed');
    expect(n.message).toContain('Midterm Examination');
    expect(n.severity).toBe('SUCCESS');
    expect(n.action_url).toBe(`/assessments/${assessment.id}/analysis`);
    expect(n.data.assessment_id).toBe(assessment.id);
    expect(JSON.stringify(n)).not.toMatch(/token|password|api_key/i);
    expect(await unreadCount(api)).toBeGreaterThan(before);

    // Bell reflects the backend count and the dropdown opens the analysis page
    await page.goto('/dashboard');
    const bell = page.getByTestId('notification-bell').getByRole('button', { name: /^Notifications/ });
    await expect(bell).toHaveAttribute('aria-label', /unread/, { timeout: 60_000 });
    await expect(page.getByTestId('unread-count')).toBeVisible();
    await bell.click();
    const dialog = page.getByRole('dialog', { name: 'Notifications' });
    await expect(dialog).toBeVisible();
    const item = dialog.getByRole('button', { name: /^Unread: Assessment analysis completed/ }).first();
    await expect(item).toBeVisible();
    const marked = page.waitForResponse((r) => /\/api\/notifications\/[^/]+\/read$/.test(r.url()) && r.request().method() === 'POST');
    await item.click();
    expect((await marked).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`/assessments/${assessment.id}/analysis$`));
    await expect(page.getByRole('heading', { name: /Assessment Rigor/ })).toBeVisible({ timeout: 30_000 });
    const after = await api.call('GET', `/notifications/${n.id}`);
    expect(after.data.read_at, 'opening a notification marks it read on the server').not.toBeNull();
    await expectNoCrash(page);
  });

  test('E2E 2 — collaboration invitation and acceptance notify both faculty members', async ({ browser }) => {
    test.setTimeout(180_000);
    const ctxA: BrowserContext = await browser.newContext();
    const ctxB: BrowserContext = await browser.newContext();
    const pageA: Page = await ctxA.newPage();
    const pageB: Page = await ctxB.newPage();
    const apiA = new Api(pageA);
    const apiB = new Api(pageB);
    await pageA.goto('/');
    await pageB.goto('/');
    const a = await apiA.registerAndLogin('notif2a', 'Dr. Alpha');
    const b = await apiB.registerAndLogin('notif2b', 'Dr. Beta');
    const course = await apiA.createCourse({ course_name: 'Notification Systems' });

    // A invites B (real invite endpoint)
    const invite = await apiA.call('POST', `/courses/${course.id}/collaborators/invite`, { email: b.email, role: 'EDITOR' }, [201]);
    const invitationId = invite.data.id;

    // B receives COLLABORATION_INVITATION — without the secret token
    const inv = await waitForNotification(apiB, 'COLLABORATION_INVITATION');
    expect(inv.message).toContain('Dr. Alpha invited you to collaborate');
    expect(inv.message).toContain('Notification Systems');
    expect(inv.action_url).toBe('/collaboration/invitations');
    expect(inv.expires_at).not.toBeNull();
    expect(JSON.stringify(inv)).not.toContain(invite.data.accept_url.split('/').pop());

    // B sees it in the UI and follows it to the pending-invitations page, then accepts
    await pageB.goto('/dashboard');
    const bellB = pageB.getByTestId('notification-bell').getByRole('button', { name: /^Notifications/ });
    await expect(bellB).toHaveAttribute('aria-label', /1 unread/, { timeout: 60_000 });
    await bellB.click();
    await pageB.getByRole('dialog', { name: 'Notifications' }).getByRole('button', { name: /^Unread: Collaboration invitation/ }).first().click();
    await expect(pageB).toHaveURL(/\/collaboration\/invitations$/);
    await apiB.call('POST', `/collaboration/my-invitations/${invitationId}/accept`, {});

    // A is notified of the acceptance; B's inbox is now read (count 0)
    const acc = await waitForNotification(apiA, 'COLLABORATION_ACCEPTED');
    expect(acc.message).toContain('Dr. Beta accepted your invitation');
    expect(acc.action_url).toBe(`/courses/${course.id}/collaboration`);
    await pageA.goto('/notifications');
    await expect(pageA.getByRole('list', { name: 'Notifications' })).toBeVisible();
    await expect(pageA.getByText('Collaboration invitation accepted').first()).toBeVisible();
    await pageA.getByTestId('notification-filter-COLLABORATION').click();
    await expect(pageA.getByText('Collaboration invitation accepted').first()).toBeVisible();
    await pageA.getByTestId('notification-filter-REPORT').click();
    await expect(pageA.getByTestId('notification-empty')).toBeVisible();
    await expectNoCrash(pageA);

    // Nobody else saw anything: B never received A's acceptance notice
    const bList = await apiB.call('GET', '/notifications');
    expect((bList.data as any[]).some((n) => n.type === 'COLLABORATION_ACCEPTED')).toBeFalsy();
    expect(a.id).not.toBe(b.id);
    await ctxA.close();
    await ctxB.close();
  });

  test('E2E 3 — a generated report produces a notification that opens the protected report page', async ({ page }) => {
    test.setTimeout(180_000);
    const api = new Api(page);
    await api.registerAndLogin('notif3');
    const course = await api.createCourse();
    const [lo1] = await api.createLearningOutcomes(course.id);
    const assessment = await api.createAssessment(course.id, { total_marks: 30 });
    await api.generateAndAddQuestions(course.id, assessment.id, lo1.id, 3, 10);

    const gen = await api.call('POST', '/reports', { report_type: 'ASSESSMENT', scope_type: 'ASSESSMENT', filters: { course_id: course.id, assessment_id: assessment.id }, format: 'PDF' }, [201, 202]);
    const reportId = gen.data.id;
    for (let i = 0; i < 40; i++) {
      const row = await api.call('GET', `/reports/${reportId}`);
      if (row.data.status === 'COMPLETED') break;
      if (row.data.status === 'FAILED') throw new Error('report failed: ' + row.data.error_message);
      await page.waitForTimeout(1500);
    }

    const n = await waitForNotification(api, 'REPORT_GENERATED');
    expect(n.title).toBe('Report ready');
    expect(n.message).toContain('ready to view or download');
    expect(n.action_url).toBe(`/reports/${reportId}`);
    expect(n.category).toBe('REPORT');

    await page.goto('/notifications');
    const row = page.getByTestId(`notification-${n.id}`);
    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute('data-unread', 'true');
    await row.getByRole('button', { name: /^Unread: Report ready/ }).click();
    await expect(page).toHaveURL(new RegExp(`/reports/${reportId}$`));
    await expect(page.getByTestId('report-metadata')).toBeVisible({ timeout: 30_000 });
    await expectNoCrash(page);

    // Mark-all-read from the page (the question-generation notification is still unread)
    await page.goto('/notifications');
    await expect(page.getByRole('list', { name: 'Notifications' })).toBeVisible();
    const markAll = page.getByTestId('mark-all-read');
    await expect(markAll).toBeEnabled({ timeout: 15_000 });
    const done = page.waitForResponse((r) => r.url().endsWith('/api/notifications/read-all'));
    await markAll.click();
    expect((await done).status()).toBe(200);
    await expect.poll(async () => unreadCount(api), { timeout: 15_000 }).toBe(0);
    await expect(page.getByTestId('notification-bell').getByTestId('unread-count')).toHaveCount(0, { timeout: 60_000 });
    await page.getByTestId('notification-filter-unread').click();
    await expect(page.getByTestId('notification-empty')).toBeVisible();
  });

  test('E2E 4 — a user cannot access another user\'s notifications and a notification grants no access', async ({ browser }) => {
    const ctxA = await browser.newContext();
    const ctxB = await browser.newContext();
    const pageA = await ctxA.newPage();
    const pageB = await ctxB.newPage();
    await pageA.goto('/');
    await pageB.goto('/');
    const apiA = new Api(pageA);
    const apiB = new Api(pageB);
    await apiA.registerAndLogin('notif4a');
    await apiB.registerAndLogin('notif4b');

    // B gets a real notification by changing their password (SECURITY_ALERT, mandatory)
    const { PASSWORD } = await import('./helpers');
    await apiB.call('POST', '/auth/change-password', { current_password: PASSWORD, password: PASSWORD + 'x', password_confirmation: PASSWORD + 'x' });
    const alert = await waitForNotification(apiB, 'SECURITY_ALERT');
    expect(alert.severity).toBe('CRITICAL');
    expect(JSON.stringify(alert)).not.toContain(PASSWORD);

    // A attempts B's id on every endpoint → 404, and B's row is untouched
    for (const [method, path] of [['GET', `/notifications/${alert.id}`], ['POST', `/notifications/${alert.id}/read`], ['POST', `/notifications/${alert.id}/dismiss`], ['DELETE', `/notifications/${alert.id}`]] as const) {
      await apiA.call(method, path, method === 'GET' ? undefined : {}, [404]);
    }
    const still = await apiB.call('GET', `/notifications/${alert.id}`);
    expect(still.data.read_at).toBeNull();
    expect(still.data.dismissed_at).toBeNull();
    const aList = await apiA.call('GET', '/notifications');
    expect((aList.data as any[]).some((n) => n.id === alert.id)).toBeFalsy();
    await apiA.call('GET', `/notifications?user_id=${9999}`); // extra params are ignored, still own scope only

    // Preferences are per-user and mandatory categories cannot be disabled
    await apiA.call('PATCH', '/notification-preferences/AI_ANALYSIS_COMPLETED', { in_app_enabled: false });
    const prefsB = await apiB.call('GET', '/notification-preferences');
    expect((prefsB.data.preferences as any[]).find((p) => p.notification_type === 'AI_ANALYSIS_COMPLETED').in_app_enabled).toBe(true);
    const sec = await apiA.call('PATCH', '/notification-preferences/SECURITY_ALERT', { in_app_enabled: false });
    expect(sec.data.in_app_enabled).toBe(true);
    expect(sec.data.mandatory).toBe(true);

    // A notification in A's inbox pointing at B's course would still be re-authorized by the target page
    const courseB = await apiB.createCourse();
    await apiA.call('GET', `/courses/${courseB.id}`, undefined, [403]);
    await pageA.goto(`/courses/${courseB.id}`);
    await expect(pageA.getByText(/permission|not found|Unauthorized|could not/i).first()).toBeVisible({ timeout: 20_000 });
    await ctxA.close();
    await ctxB.close();
  });

  test('notification center UI: states, preferences and accessibility', async ({ page }) => {
    const api = new Api(page);
    await page.goto('/');
    const me = await api.registerAndLogin('notif5');
    await api.logout();
    await loginViaUi(page, me.email); // real sign-in form, then the authenticated shell

    // Empty inbox
    await page.goto('/notifications');
    await expect(page.getByRole('heading', { name: 'Notification center' })).toBeVisible();
    await expect(page.getByTestId('notification-empty')).toBeVisible();
    await expect(page.getByText("You're all caught up.")).toBeVisible();
    await expect(page.getByTestId('mark-all-read')).toBeDisabled();
    const emptyScan = await new AxeBuilder({ page }).include('[data-testid="notifications-page"]').analyze();
    expect(emptyScan.violations.filter((v) => ['critical', 'serious'].includes(v.impact ?? '')), JSON.stringify(emptyScan.violations, null, 2)).toEqual([]);

    // Preferences: toggle → save → persisted
    await page.getByTestId('view-preferences').click();
    await expect(page.getByTestId('notification-preferences')).toBeVisible();
    const cb = page.getByLabel('Report ready');
    await expect(cb).toBeChecked();
    await cb.uncheck();
    const saved = page.waitForResponse((r) => r.url().endsWith('/api/notification-preferences') && r.request().method() === 'PUT');
    await page.getByTestId('save-preferences').click();
    expect((await saved).status()).toBe(200);
    await expect(page.getByText('Preferences saved.')).toBeVisible();
    const prefs = await api.call('GET', '/notification-preferences');
    expect((prefs.data.preferences as any[]).find((p) => p.notification_type === 'REPORT_GENERATED').in_app_enabled).toBe(false);
    await expect(page.getByLabel('Security alert')).toBeDisabled();
    const prefScan = await new AxeBuilder({ page }).include('[data-testid="notifications-page"]').analyze();
    expect(prefScan.violations.filter((v) => ['critical', 'serious'].includes(v.impact ?? '')), JSON.stringify(prefScan.violations, null, 2)).toEqual([]);

    // Keyboard: bell is reachable and opens/closes with the keyboard
    await page.goto('/dashboard');
    const bell = page.getByTestId('notification-bell').getByRole('button', { name: /^Notifications/ });
    await bell.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog', { name: 'Notifications' })).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'Notifications' }).getByTestId('notification-empty')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Notifications' })).toHaveCount(0);
    await expect(bell).toBeFocused();
    await expectNoCrash(page);
  });
});
