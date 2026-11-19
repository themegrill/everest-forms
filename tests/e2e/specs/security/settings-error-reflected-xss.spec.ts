import { test, expect } from '@playwright/test';

/**
 * Regression guard for reflected XSS through the settings notice params.
 *
 * `admin.php?page=evf-settings` queued `evf_error` / `evf_message` from the URL
 * after only `wp_kses_post()`, and `show_messages()` printed the error with
 * `wp_kses_post()` too — so `class` and `data-tooltip-content` survived on a
 * span. `admin.js` turns every `.tips` element into a Tooltipster tooltip on
 * load, and Tooltipster handed `data-tooltip-content` to `jQuery()`, which
 * parses a value starting with `<` as HTML. A crafted link an admin opened
 * therefore ran script in their session.
 *
 * The params are now plain text (sanitize_text_field on the way in, esc_html on
 * the way out) and Tooltipster resolves `data-tooltip-content` only as a
 * selector, so neither the notice markup nor the tooltip can execute.
 */

test.setTimeout(60_000);

const MARKER = '__evfSettingsXssRan';

const PAYLOAD =
  `<span class="tips" data-tooltip-content="&lt;img src=x onerror=window.${MARKER}=1&gt;">boom</span>`;

/**
 * @area    security
 * @tier    fresh
 * @source  security report — reflected XSS via settings notice params (<= 3.6.4)
 * @why     A crafted settings link could run JavaScript in an administrator's
 *          session the moment they opened it.
 */
test('a crafted settings error link cannot run script @fresh @security', async ({ page }) => {
  await page.goto(
    `/wp-admin/admin.php?page=evf-settings&evf_error=${encodeURIComponent(PAYLOAD)}`,
    { waitUntil: 'domcontentloaded' },
  );
  await page.waitForLoadState('networkidle');
  // Tooltipster initialises on load; give it the beat the browser gives it.
  await page.waitForTimeout(1_500);

  const executed = await page.evaluate(
    (marker) => (window as unknown as Record<string, unknown>)[marker],
    MARKER,
  );
  expect(
    executed,
    'A crafted settings error link executed JavaScript in the administrator session. ' +
      'This is the reflected XSS this guard exists for.',
  ).toBeUndefined();

  const notice = page.locator('#message').first();
  await expect(notice, 'The settings notice did not render at all.').toBeVisible();

  const markup = await notice.innerHTML();
  expect(
    markup,
    'The notice kept the data-tooltip-content attribute admin.js feeds to jQuery().',
  ).not.toContain('data-tooltip-content');
  expect(
    markup,
    'The notice kept the class that admin.js turns into a tooltip.',
  ).not.toContain('tips');
  // The text itself still reaches the admin, just inert.
  await expect(notice, 'The error text was lost entirely.').toContainText('boom');

  // A genuine plain message still shows.
  await page.goto(
    `/wp-admin/admin.php?page=evf-settings&evf_message=${encodeURIComponent('evf-qa-benign-notice')}`,
    { waitUntil: 'domcontentloaded' },
  );
  await expect(
    page.locator('#message').first(),
    'A legitimate settings message no longer displays.',
  ).toContainText('evf-qa-benign-notice');
});
