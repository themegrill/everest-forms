import { test, expect } from '@playwright/test';

/**
 * Regression guard for the 3.5.3 fix:
 *
 *   "Google reCAPTCHA/hCaptcha/Turnstile language setting resets to English
 *    (UK) after saving."
 *
 * The four language selects on the CAPTCHA settings screen do not each own a
 * value — they all read and write one shared option,
 * `everest_forms_recaptcha_recaptcha_language`
 * (includes/admin/settings/class-evf-settings-security.php:202, read back at
 * :347 with `'en-GB'` as its default). That default is what the bug fell back
 * to: when the save handler failed to write, every select re-rendered as
 * English (UK) and the user's choice vanished with no error.
 *
 * Which is why this spec asserts on the reload rather than on the select right
 * after clicking Save. Post-save the DOM still holds whatever was chosen
 * whether or not the option row was written, so an in-page assertion would
 * have passed against the broken build too, and this guard would be worthless.
 *
 * The shared option is also why all four selects are checked: the fix routes
 * every one of them through the same write, so a regression that reconnects
 * one select to its own key would leave the other three silently on the
 * default again.
 */

const CAPTCHA_SETTINGS =
  '/wp-admin/admin.php?page=evf-settings&tab=recaptcha&section=integration';

const LANGUAGE_SELECTS = [
  '#everest_forms_recaptcha_v2_language',
  '#everest_forms_recaptcha_v3_language',
  '#everest_forms_recaptcha_hcaptcha_language',
  '#everest_forms_recaptcha_turnstile_language',
];

// Deliberately not English-anything: the bug's signature is a silent fall back
// to `en-GB`, so a probe value near it could pass by coincidence.
const PROBE_LANGUAGE = 'fr';

let original: string | null = null;

test.afterEach(async ({ page }) => {
  if (original === null) return;
  const restore = original;
  original = null;
  try {
    await page.goto(CAPTCHA_SETTINGS, { waitUntil: 'domcontentloaded' });
    await page.locator(LANGUAGE_SELECTS[0]).selectOption(restore);
    await page.locator('button[name="save"]').click();
    await page.waitForLoadState('domcontentloaded');
  } catch {
    // Best-effort: tidying up must not fail a test that passed, nor mask one
    // that did not.
  }
});

/**
 * @area    settings
 * @tier    fresh
 * @source  changelog 3.5.3 — "reCAPTCHA/hCaptcha/Turnstile language setting
 *          resets to English (UK) after saving"
 * @why     A setting that silently reverts is the worst kind of settings bug:
 *          nothing errors, so the user assumes it took, and the CAPTCHA then
 *          renders in the wrong language for every visitor. It was reported
 *          from the field rather than caught here, which is the gap this
 *          closes.
 */
test('the CAPTCHA language survives a save and reload @fresh @settings', async ({ page }) => {
  await page.goto(CAPTCHA_SETTINGS, { waitUntil: 'domcontentloaded' });

  const primary = page.locator(LANGUAGE_SELECTS[0]);
  await expect(
    primary,
    'The reCAPTCHA v2 language select is not on the CAPTCHA settings screen. If the ' +
      'screen was restructured this spec needs a new target, not deleting.',
  ).toBeVisible();

  original = await primary.inputValue();
  expect(
    original,
    'Expected the language select to start on a real value; it is empty, which means ' +
      'the shared option is unreadable rather than merely wrong.',
  ).toBeTruthy();

  await primary.selectOption(PROBE_LANGUAGE);
  await page.locator('button[name="save"]').click();
  await page.waitForLoadState('domcontentloaded');

  // The reload is the assertion — it reads the option back out of the database
  // instead of trusting the select we just changed.
  await page.goto(CAPTCHA_SETTINGS, { waitUntil: 'domcontentloaded' });

  for (const selector of LANGUAGE_SELECTS) {
    await expect(
      page.locator(selector),
      `${selector} did not keep the chosen language across a reload. If it reads ` +
        "'en-GB', this is the 3.5.3 bug back: the save silently fell through to the " +
        'default of the shared `everest_forms_recaptcha_recaptcha_language` option.',
    ).toHaveValue(PROBE_LANGUAGE);
  }
});
