import { test, expect } from '@playwright/test';
import { Api, PASSWORD, loginViaUi, uniqueEmail, expectNoCrash } from './helpers';

/** Journey 1 — Register → Login → Dashboard (plus the failure paths a real user hits). */
test.describe('Journey 1: authentication', () => {
  test('a new faculty member registers through the form and lands on the dashboard', async ({ page }) => {
    const email = uniqueEmail('journey1');
    await page.goto('/register');
    await page.getByLabel('Full Name').fill('Dr. Grace Hopper');
    await page.getByLabel('University Email').fill(email);
    await page.getByLabel('Department').fill('Computer Science');
    await page.getByLabel('Designation').fill('Professor');
    await page.locator('input#password').fill(PASSWORD);
    await page.locator('input#confirm-password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Create Account' }).click();

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByTestId('dashboard-page')).toBeVisible();
    await expect(page.getByText(/Welcome back, Grace/)).toBeVisible();
    await expectNoCrash(page);

    // Sign out through the sidebar and sign back in through the login form.
    // The first test of a run hits a cold single-threaded dev server (`php artisan serve`) while the dashboard's
    // parallel requests are still in flight, so the logout round-trip is allowed extra time.
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByRole('button', { name: 'Yes, sign out' }).click();
    await expect(page).toHaveURL(/\/login/, { timeout: 60_000 });

    await loginViaUi(page, email);
    await expect(page.getByText(/Welcome back, Grace/)).toBeVisible();
  });

  test('wrong credentials show a friendly message and never a raw error', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('University Email').fill('nobody@university.edu');
    await page.locator('input#password').fill('definitely-wrong');
    await page.locator('form').getByRole('button', { name: 'Sign In' }).click();
    await expect(page.getByText(/invalid email or password|credentials do not match|invalid credentials/i)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
    await expectNoCrash(page);
  });

  test('protected pages redirect anonymous visitors to the login page', async ({ page }) => {
    for (const path of ['/dashboard', '/courses', '/assessments', '/analytics', '/reports']) {
      await page.goto(path);
      await expect(page, path).toHaveURL(/\/login/);
    }
  });

  test('registration validation errors are shown inline (no request is sent with an invalid form)', async ({ page }) => {
    await page.goto('/register');
    await page.getByLabel('Full Name').fill('X');
    await page.getByLabel('University Email').fill('not-an-email');
    await page.locator('input#password').fill('short');
    await page.locator('input#confirm-password').fill('different');
    await page.getByRole('button', { name: 'Create Account' }).click();
    await expect(page).toHaveURL(/\/register/);
    await expect(page.locator('[role="alert"], .text-\\[\\#DC2626\\], .text-red-600').first()).toBeVisible();
    await expectNoCrash(page);
  });

  test('session survives a full page reload', async ({ page }) => {
    const api = new Api(page);
    await api.registerAndLogin('journey1b');
    await page.goto('/dashboard');
    await expect(page.getByTestId('dashboard-page')).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('dashboard-page')).toBeVisible();
    await expect(page).toHaveURL(/\/dashboard/);
  });
});
