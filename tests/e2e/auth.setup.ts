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
    await page.waitForLoadState('domcontentloaded');

    // Diagnose before asserting.
    //
    // The bare `#wpadminbar` assertion this replaced could only ever say "the
    // admin bar is not here", and then offered a guess — wrong credentials, or
    // an unreachable site. On CI that guess is unactionable: the reader cannot
    // tell a rejected password from a fatal in admin bootstrap, and the two
    // have completely different fixes. Both are visible on the page we are
    // already looking at, so read them.
    //
    // The distinction matters most on a disposable Playground site, where
    // plugins load on wp-login.php but admin-only hooks do not: a fatal in
    // admin init leaves the login page working perfectly and blanks
    // /wp-admin/, which presents exactly like a bad password.
    const landedOn = page.url();
    const bodyText = await page.locator('body').innerText().catch(() => '');

    const loginError = await page
      .locator('#login_error')
      .first()
      .innerText()
      .catch(() => '');
    if (loginError.trim()) {
      throw new Error(
        `WordPress rejected the login for "${user}" at ${url}.\n` +
          `It said: ${loginError.trim().replace(/\s+/g, ' ')}\n\n` +
          'This is a credentials problem, not a connectivity one — the site served ' +
          'wp-login.php and answered. Check TGQA_ADMIN_USER / TGQA_ADMIN_PASS for this ' +
          'environment. On a Playground run these default to admin/password from the ' +
          'blueprint; if that is what was sent, the blueprint and the runner disagree.',
      );
    }

    const fatal = bodyText.match(
      /(Fatal error|Parse error|Uncaught \w*(Error|Exception))[^\n]{0,300}/,
    );
    if (fatal) {
      throw new Error(
        `The login succeeded but wp-admin returned a PHP fatal at ${url}.\n` +
          `${fatal[0]}\n\n` +
          'The credentials are fine. Something in admin bootstrap is dying — note that ' +
          'plugins load on wp-login.php but admin-only hooks do not, which is why the ' +
          'login page rendered and this did not.',
      );
    }

    // The admin bar only renders once the login round-trip actually completed,
    // so it distinguishes "logged in" from "wp-login.php re-rendered", which a
    // URL check alone does not.
    await expect(
      page.locator('#wpadminbar'),
      `Logged in as "${user}" at ${url} but wp-admin never rendered, with no login ` +
        `error and no PHP fatal on the page.\n` +
        `Landed on: ${landedOn}\n` +
        `Page began: ${bodyText.trim().replace(/\s+/g, ' ').slice(0, 200) || '(empty body)'}\n\n` +
        'An empty body here usually means a fatal that was logged rather than printed ' +
        '(display_errors off). A redirect to somewhere unexpected means a plugin is ' +
        'intercepting admin init.',
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
