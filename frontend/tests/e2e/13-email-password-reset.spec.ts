import { test, expect, type Page } from '@playwright/test';
import { Api, PASSWORD, loginViaUi, expectNoCrash } from './helpers';

/**
 * Journey 13 — E-mail system + password reset, end to end through the real queue:
 *
 *   Login → Forgot password → generic success → reset e-mail captured (Mailpit) → reset link → new password
 *   → Go to Login → login with new password; old password rejected. Plus: test e-mail delivery reaches SENT
 *   through Redis → Horizon → SMTP, and e-mail preferences round-trip in Settings.
 *
 * Mail capture: the Docker stack must run with MAIL_MAILER=mailpit (docker-compose `mailpit` service). The suite
 * checks /api/email/status → capture_mode and skips (never sends to Gmail) when the stack is pointed at a live SMTP.
 */
const MAILPIT_URL = process.env.E2E_MAILPIT_URL || 'http://127.0.0.1:8025';
const NEW_PASSWORD = 'E2E-Reset#2027';

interface MailpitMessage { ID: string; To: { Address: string }[]; Subject: string; Created: string }

async function mailpitSearch(page: Page, recipient: string, subject: string): Promise<MailpitMessage | null> {
  const res = await page.request.get(`${MAILPIT_URL}/api/v1/search?query=${encodeURIComponent(`to:${recipient} subject:"${subject}"`)}`);
  if (!res.ok()) return null;
  const body = await res.json();
  const messages: MailpitMessage[] = body.messages ?? [];
  return messages.find((m) => m.To.some((t) => t.Address.toLowerCase() === recipient.toLowerCase())) ?? null;
}

async function waitForMail(page: Page, recipient: string, subject: string, timeoutMs = 60_000): Promise<{ html: string; text: string; subject: string }> {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const msg = await mailpitSearch(page, recipient, subject);
    if (msg) {
      const detail = await (await page.request.get(`${MAILPIT_URL}/api/v1/message/${msg.ID}`)).json();
      return { html: detail.HTML ?? '', text: detail.Text ?? '', subject: detail.Subject ?? msg.Subject };
    }
    await page.waitForTimeout(1500);
  }
  throw new Error(`No e-mail "${subject}" for ${recipient} reached Mailpit within ${timeoutMs / 1000}s (is Horizon running? MAIL_MAILER=mailpit?)`);
}

async function captureModeOrSkip(api: Api): Promise<void> {
  const status = (await api.call('GET', '/email/status')).data;
  test.skip(!status.enabled, 'EMAIL_ENABLED=false on the backend');
  test.skip(!status.capture_mode, 'Backend mailer is a live transport (Gmail). Run the stack with MAIL_MAILER=mailpit for E2E mail capture.');
  let mailpitUp = false;
  try {
    const health = await fetch(`${MAILPIT_URL}/api/v1/info`);
    mailpitUp = health.ok;
  } catch {
    mailpitUp = false;
  }
  test.skip(!mailpitUp, `Mailpit is not reachable on ${MAILPIT_URL}`);
}

test.describe('Journey 13: e-mail system and password reset', () => {
  test('forgot password → captured reset e-mail → reset page → new password → login', async ({ page }) => {
    test.setTimeout(180_000);
    const api = new Api(page);
    const { email } = await api.registerAndLogin('journey13', 'Dr. Reset Faculty');
    await captureModeOrSkip(api);
    await api.logout();

    // Forgot password through the UI
    await page.goto('/login');
    await page.getByRole('link', { name: /forgot password/i }).click();
    await expect(page).toHaveURL(/\/forgot-password/);
    await page.getByLabel(/university email/i).fill(email);
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await expect(page.getByTestId('forgot-password-success')).toBeVisible();
    await expect(page.getByRole('status')).toContainText('If an account exists for this email address, a password reset link has been sent.');
    await expect(page.getByTestId('forgot-password-success')).not.toContainText(email);
    await expectNoCrash(page);

    // Unknown address gets the identical UI (enumeration protection)
    await page.goto('/forgot-password');
    await page.getByLabel(/university email/i).fill(`nobody.${Date.now()}@university.edu`);
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await expect(page.getByRole('status')).toContainText('If an account exists for this email address, a password reset link has been sent.');

    // The e-mail went queue → Redis → Horizon → SMTP (Mailpit)
    const mail = await waitForMail(page, email, 'Reset Your Password');
    expect(mail.subject).toBe('FacultyLens — Reset Your Password');
    expect(mail.html).toContain('If you did not request a password reset, you can safely ignore this email.');
    expect(mail.text.length).toBeGreaterThan(50);
    const match = mail.html.match(/href="([^"]*\/reset-password\?[^"]+)"/);
    expect(match, 'reset link present in the e-mail').not.toBeNull();
    const resetUrl = match![1].replace(/&amp;/g, '&');
    expect(resetUrl).not.toContain(NEW_PASSWORD);

    // Open the reset link on the frontend under test (the e-mail links to FRONTEND_URL; we keep the query string)
    const linkUrl = new URL(resetUrl);
    await page.goto(`/reset-password${linkUrl.search}`);
    await expect(page.getByTestId('reset-password-form')).toBeVisible();
    await page.getByLabel(/^new password/i).fill(NEW_PASSWORD);
    await page.getByLabel(/^confirm new password/i).fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'Reset Password' }).click();
    await expect(page.getByTestId('reset-password-success')).toBeVisible();
    await expect(page.getByRole('status')).toContainText('Password reset successfully. You can now sign in with your new password.');

    // The same link cannot be used twice
    await page.goto(`/reset-password${linkUrl.search}`);
    await page.getByLabel(/^new password/i).fill('Another#Pass1');
    await page.getByLabel(/^confirm new password/i).fill('Another#Pass1');
    await page.getByRole('button', { name: 'Reset Password' }).click();
    await expect(page.getByTestId('reset-password-invalid')).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('This password reset link is invalid or has expired.');
    await page.getByRole('button', { name: 'Request a New Link' }).click();
    await expect(page).toHaveURL(/\/forgot-password/);

    // Old password rejected, new password works
    await page.goto('/login');
    await page.getByLabel('University Email').fill(email);
    await page.locator('input#password').fill(PASSWORD);
    await page.locator('form').getByRole('button', { name: 'Sign In' }).click();
    await expect(page.getByText(/invalid email or password/i)).toBeVisible();

    await loginViaUi(page, email, NEW_PASSWORD);
    await expect(page.getByText(/Welcome back, Reset/)).toBeVisible();

    // The password change raised a mandatory SECURITY e-mail as well
    await waitForMail(page, email, 'Security Alert');
  });

  test('a tampered or missing reset link shows the invalid-link screen', async ({ page }) => {
    await page.goto('/reset-password');
    await expect(page.getByTestId('reset-password-invalid')).toBeVisible();
    await page.goto('/reset-password?token=forged-token&email=someone%40university.edu');
    await page.getByLabel(/^new password/i).fill(NEW_PASSWORD);
    await page.getByLabel(/^confirm new password/i).fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'Reset Password' }).click();
    await expect(page.getByTestId('reset-password-invalid')).toBeVisible();
    await expectNoCrash(page);
  });

  test('test e-mail travels queue → Horizon → SMTP and the delivery status becomes SENT', async ({ page }) => {
    test.setTimeout(120_000);
    const api = new Api(page);
    const { email } = await api.registerAndLogin('journey13mail');
    await captureModeOrSkip(api);

    const queued = (await api.call('POST', '/email/test', {}, [202])).data;
    expect(['PENDING', 'SENT']).toContain(queued.status);
    expect(JSON.stringify(queued)).not.toMatch(/smtp\.gmail|MAIL_PASSWORD|password/i);

    let status = queued.status;
    for (let i = 0; i < 40 && status !== 'SENT' && status !== 'FAILED'; i++) {
      await page.waitForTimeout(1500);
      status = (await api.call('GET', `/email/deliveries/${queued.id}`)).data.status;
    }
    expect(status).toBe('SENT');
    const mail = await waitForMail(page, email, 'Test Email');
    expect(mail.subject).toBe('FacultyLens — Test Email');
    expect(mail.html).toContain('AI-Powered Academic Decision Support');
  });

  test('e-mail preferences are editable in Settings and persist', async ({ page }) => {
    const api = new Api(page);
    const { email } = await api.registerAndLogin('journey13prefs');
    await api.logout();
    await loginViaUi(page, email);
    await page.goto('/settings');
    const prefs = page.getByTestId('notification-preferences');
    await expect(prefs).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('email-preferences')).toBeVisible();

    const reports = page.getByLabel('Reports', { exact: true });
    const before = await reports.isChecked();
    await reports.click();
    await page.getByTestId('save-preferences').click();
    await expect(page.getByText('Preferences saved.')).toBeVisible();

    const saved = (await api.call('GET', '/notification-preferences')).data.preferences.find((p: any) => p.notification_type === 'REPORT_GENERATED');
    expect(saved.email_enabled).toBe(!before);
    const security = (await api.call('GET', '/notification-preferences')).data.preferences.find((p: any) => p.notification_type === 'SECURITY_ALERT');
    expect(security.email_enabled).toBe(true);
    expect(security.email_mandatory).toBe(true);
    await expectNoCrash(page);
  });
});
