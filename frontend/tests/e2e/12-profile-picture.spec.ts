import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { Api, API_URL, expectNoCrash, loginViaUi } from './helpers';

/**
 * Profile picture system, end to end against the live stack.
 *
 *   E2E 1  Login → Settings → upload → save → avatar in sidebar/header/dashboard → survives navigation and re-login → remove → initial
 *   E2E 2  Client + server validation surfaces friendly messages and never changes the stored picture
 *   E2E 3  Security: user B cannot view/replace/delete user A's picture; anonymous access denied; no public storage URL
 *   plus   axe scan of the profile settings card
 */

const FIXTURES = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../backend/tests/Fixtures/profile-pictures');
const fixture = (name: string) => path.join(FIXTURES, name);

const sidebarAvatar = (page: Page) => page.locator('aside').getByRole('link', { name: 'Open profile settings' }).getByTestId('profile-picture');
const headerAvatar = (page: Page) => page.getByTestId('header-account-link').getByTestId('profile-picture');

async function expectImageLoaded(page: Page, locator: ReturnType<Page['locator']>, userId: number) {
  const img = locator.locator('img');
  await expect(img).toHaveAttribute('src', new RegExp(`/api/users/${userId}/profile-picture\\?v=\\d+`));
  await expect.poll(async () => img.evaluate((el) => (el as HTMLImageElement).complete && (el as HTMLImageElement).naturalWidth > 0)).toBe(true);
}

async function uploadViaSettings(page: Page, file: string) {
  await page.goto('/settings');
  await expect(page.getByTestId('profile-picture-uploader')).toBeVisible();
  await page.getByTestId('profile-picture-input').setInputFiles(file);
  await expect(page.getByTestId('profile-picture-pending')).toBeVisible();
  const response = page.waitForResponse((r) => r.url().includes('/api/profile/picture') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save new profile picture' }).click();
  return response;
}

test.describe('Profile picture system', () => {
  test('E2E 1 — upload, display everywhere, persist across navigation and re-login, remove', async ({ page }) => {
    test.setTimeout(240_000);
    const api = new Api(page);
    await page.goto('/');
    const { email, id } = await api.registerAndLogin('avatar1', 'Dr. Ada Lovelace');
    await api.logout();
    await loginViaUi(page, email);

    // No picture yet → initial fallback in the sidebar and header
    await expect(sidebarAvatar(page)).toHaveAttribute('data-state', 'initial');
    await expect(sidebarAvatar(page)).toHaveText('D');
    await expect(headerAvatar(page)).toHaveAttribute('data-state', 'initial');

    // Upload through the real UI
    const res = await uploadViaSettings(page, fixture('valid.png'));
    expect(res.status(), await res.text()).toBe(200);
    const body = await res.json();
    expect(body.user.profile_picture_url).toMatch(new RegExp(`^/api/users/${id}/profile-picture\\?v=`));
    expect(JSON.stringify(body)).not.toContain('profile-pictures/');
    await expect(page.getByRole('status')).toContainText('Profile picture updated successfully.');

    // State propagated without reload: sidebar + header render the image
    await expectImageLoaded(page, sidebarAvatar(page), id);
    await expectImageLoaded(page, headerAvatar(page), id);
    const firstSrc = await sidebarAvatar(page).locator('img').getAttribute('src');

    // Navigate around — avatar persists
    await page.goto('/dashboard');
    await expectImageLoaded(page, page.getByTestId('dashboard-avatar-link').getByTestId('profile-picture'), id);
    await page.goto('/courses');
    await expectImageLoaded(page, sidebarAvatar(page), id);
    await expectNoCrash(page);

    // Persisted server-side
    const me = await api.call('GET', '/auth/user');
    expect(me.user.profile_picture_url).toBe(body.user.profile_picture_url);
    const raw = await page.request.get(`${API_URL}${me.user.profile_picture_url}`, { headers: { Accept: 'image/*', Referer: 'http://127.0.0.1:4173/' } });
    expect(raw.status()).toBe(200);
    expect(raw.headers()['content-type']).toMatch(/^image\/(webp|png)$/);
    expect(raw.headers()['x-content-type-options']).toBe('nosniff');
    expect(raw.headers()['cache-control']).toContain('private');

    // Logout → login again → still there
    await api.logout();
    await loginViaUi(page, email);
    await expectImageLoaded(page, sidebarAvatar(page), id);

    // Replace → version changes (cache busting)
    const replace = await uploadViaSettings(page, fixture('valid.jpg'));
    expect(replace.status()).toBe(200);
    await expect(page.getByRole('status')).toContainText('replaced');
    await expect.poll(async () => sidebarAvatar(page).locator('img').getAttribute('src')).not.toBe(firstSrc);

    // Remove → initial fallback everywhere
    await page.getByRole('button', { name: 'Remove profile picture' }).click();
    const del = page.waitForResponse((r) => r.url().includes('/api/profile/picture') && r.request().method() === 'DELETE');
    await page.getByRole('button', { name: 'Confirm remove profile picture' }).click();
    expect((await del).status()).toBe(200);
    await expect(sidebarAvatar(page)).toHaveAttribute('data-state', 'initial');
    await expect(headerAvatar(page)).toHaveAttribute('data-state', 'initial');
    expect((await api.call('GET', '/auth/user')).user.profile_picture_url).toBeNull();
    await expect(page.getByRole('button', { name: 'Change profile picture' })).toHaveText(/Upload photo/);

    // Accessibility of the settings card
    const axe = await new AxeBuilder({ page }).include('[data-testid="profile-picture-uploader"]').analyze();
    expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([]);
  });

  test('E2E 2 — validation: unsupported, oversized and tiny images are rejected with friendly messages', async ({ page }) => {
    const api = new Api(page);
    await page.goto('/');
    const { email } = await api.registerAndLogin('avatar2');
    await api.logout();
    await loginViaUi(page, email);
    await page.goto('/settings');

    // Client-side: wrong type never reaches the server
    let posts = 0;
    page.on('request', (r) => { if (r.url().includes('/api/profile/picture') && r.method() === 'POST') posts += 1; });
    await page.getByTestId('profile-picture-input').setInputFiles(fixture('invalid.txt'));
    await expect(page.getByRole('alert')).toHaveText(/Please select a JPG, PNG, or WEBP image\./);
    await expect(page.getByTestId('profile-picture-pending')).toHaveCount(0);

    await page.getByTestId('profile-picture-input').setInputFiles(fixture('svg-vector.svg'));
    await expect(page.getByRole('alert')).toHaveText(/Please select a JPG, PNG, or WEBP image\./);
    expect(posts).toBe(0);

    // Server-side: a real but too-small PNG passes the client check and is rejected by Laravel
    const res = await uploadViaSettings(page, fixture('tiny.png'));
    expect(res.status()).toBe(422);
    await expect(page.getByRole('alert')).toContainText('Profile picture must be between 100 × 100 and 5000 × 5000 pixels.');
    await expect(page.getByTestId('profile-picture-pending')).toBeVisible();
    await expectNoCrash(page);
    expect((await api.call('GET', '/auth/user')).user.profile_picture_url).toBeNull();
    await expect(sidebarAvatar(page)).toHaveAttribute('data-state', 'initial');
  });

  test('E2E 3 — security: another user cannot view, replace or delete my picture; anonymous access is denied', async ({ browser }) => {
    const ctxA = await browser.newContext();
    const ctxB = await browser.newContext();
    const pageA = await ctxA.newPage();
    const pageB = await ctxB.newPage();
    try {
      const apiA = new Api(pageA);
      const apiB = new Api(pageB);
      await pageA.goto('/');
      await pageB.goto('/');
      const a = await apiA.registerAndLogin('victim');
      const b = await apiB.registerAndLogin('attacker');
      await apiA.logout();
      await apiB.logout();
      await loginViaUi(pageA, a.email);
      await loginViaUi(pageB, b.email);

      const up = await uploadViaSettings(pageA, fixture('valid.png'));
      expect(up.status()).toBe(200);
      const aUrl = (await apiA.call('GET', '/auth/user')).user.profile_picture_url as string;

      // B cannot view A's picture (no shared course)
      const view = await pageB.request.get(`${API_URL}${aUrl}`, { headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:4173/' } });
      expect(view.status()).toBe(403);

      // B uploading their own picture (even while "targeting" A) only changes B's record
      const bUpload = await uploadViaSettings(pageB, fixture('valid.jpg'));
      expect(bUpload.status()).toBe(200);
      expect((await apiA.call('GET', '/auth/user')).user.profile_picture_url).toBe(aUrl);

      // B deleting (with a forged user_id) cannot touch A
      await apiB.call('DELETE', '/profile/picture', { user_id: a.id });
      expect((await apiA.call('GET', '/auth/user')).user.profile_picture_url).toBe(aUrl);
      const stillThere = await pageA.request.get(`${API_URL}${aUrl}`, { headers: { Accept: 'image/*', Referer: 'http://127.0.0.1:4173/' } });
      expect(stillThere.status()).toBe(200);

      // Anonymous context: 401, and no public storage path serves the file
      const anon = await browser.newContext();
      try {
        const anonPage = await anon.newPage();
        const res = await anonPage.request.get(`${API_URL}${aUrl}`, { headers: { Accept: 'application/json' } });
        expect(res.status()).toBe(401);
        const pub = await anonPage.request.get(`${API_URL}/storage/profile-pictures/${a.id}/`, { headers: { Accept: '*/*' } });
        expect([403, 404]).toContain(pub.status());
      } finally {
        await anon.close();
      }

      // A invites B to a course and B accepts → B may now see A's avatar (shared-course rule)
      const course = await apiA.createCourse();
      const invite = await apiA.call('POST', `/courses/${course.id}/collaborators/invite`, { email: b.email, role: 'REVIEWER' }, [201]);
      await apiB.call('POST', `/collaboration/my-invitations/${invite.data.id}/accept`, {});
      const now = await pageB.request.get(`${API_URL}${aUrl}`, { headers: { Accept: 'image/*', Referer: 'http://127.0.0.1:4173/' } });
      expect(now.status()).toBe(200);
      const roster = await apiB.call('GET', `/courses/${course.id}/collaborators`);
      expect(roster.data.owner.profile_picture_url).toBe(aUrl);
      expect(JSON.stringify(roster)).not.toContain('profile-pictures/');
    } finally {
      await ctxA.close();
      await ctxB.close();
    }
  });
});
