import { test as setup, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { adminCredentials, baseUrl, STORAGE_STATE } from './env';

/**
 * Log in once per run and hand every other project a warm session.
 *
 * This used to live in `global-setup.ts`, which made it invisible to
 * Playwright: a failed login surfaced as an opaque global-setup crash with no
 * trace, no screenshot and no retry. As a setup *project* it is a real test —
 * it retries, it reports, and its failure names itself.
 *
 * Self-healing, which is the requirement that matters for a fresh Playground
 * site: the saved `.auth/admin.json` is *validated* before it is trusted, and
 * a stale or absent one triggers a fresh login instead of failing the run.
 * The three cases that produce a stale state are all routine, not exceptional
 * — a brand-new disposable site (no state file at all), a site rebuilt under
 * the same URL (cookies now point at a dead session), and a state file older
 * than WordPress's own auth cookie expiry.
 */
setup('authenticate', async ({ browser }) => {
  const { user, password } = adminCredentials();
  const url = baseUrl();

  if (await storedStateStillWorks(browser, url)) {
    setup.info().annotations.push({ type: 'auth', description: 'reused .auth/admin.json' });
    return;
  }

  const context = await browser.newContext({ baseURL: url });
  const page = await context.newPage();

  try {
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();

    // The admin bar only renders once the login round-trip actually completed,
    // so it distinguishes "logged in" from "wp-login.php re-rendered with an
    // error", which a URL check alone does not.
    await expect(
      page.locator('#wpadminbar'),
      `Logged in as "${user}" at ${url} but wp-admin never rendered. Wrong credentials, ` +
        'or the site is not reachable from here.',
    ).toBeVisible({ timeout: 30_000 });

    fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true });
    await context.storageState({ path: STORAGE_STATE });
    setup.info().annotations.push({ type: 'auth', description: 'logged in fresh' });
  } finally {
    await context.close();
  }
});

/**
 * Is the state file on disk still a live admin session?
 *
 * Checked by loading it and asking wp-admin, rather than by inspecting cookie
 * expiry: WordPress can invalidate a session well before its cookie expires
 * (a password change, a salt rotation, a rebuilt site), and an expiry check
 * would call all of those "valid" and then fail every spec in the run instead
 * of this one cheap probe.
 *
 * Any failure here means "log in again", never "fail the run" — that is the
 * whole point of the function, so it swallows its errors deliberately.
 */
async function storedStateStillWorks(
  browser: import('@playwright/test').Browser,
  url: string,
): Promise<boolean> {
  if (!fs.existsSync(STORAGE_STATE)) return false;

  let context;
  try {
    context = await browser.newContext({ storageState: STORAGE_STATE, baseURL: url });
    const page = await context.newPage();
    const response = await page.goto('/wp-admin/', { timeout: 20_000 });
    if (!response || response.status() >= 400) return false;
    // A logged-out request to /wp-admin/ is redirected to wp-login.php.
    if (/wp-login\.php/.test(page.url())) return false;
    return await page.locator('#wpadminbar').isVisible({ timeout: 10_000 });
  } catch {
    return false;
  } finally {
    await context?.close();
  }
}
