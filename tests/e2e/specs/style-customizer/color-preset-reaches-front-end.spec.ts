import { test, expect } from '../../fixtures/published-form';

/**
 * The guard the Style Customizer's history actually calls for: a style choice
 * made in the panel has to reach the rendered form.
 *
 * Every v1→v2 fix in 3.6.0 is the same shape — a control that still exists,
 * still looks right, and no longer changes anything: EVF-2732 (button
 * alignment default), EVF-2733 and `a9e2c0723` (a missing `text-decoration`
 * var, the same bug twice), EVF-2746 (template defaults never applied for
 * props that were never explicitly saved), `cd0acd330` (Custom CSS silently
 * stopped being global). A spec that only checks the panel renders would have
 * passed against every one of them.
 *
 * So this asserts on computed style on the **front end, as an anonymous
 * visitor**, after a save — the far side of the entire pipeline: panel → saved
 * record → enqueued CSS → browser.
 *
 * ## Why a colour preset, and why "changed" rather than an exact value
 *
 * In the free plugin almost all of this panel is a Pro upsell. The six element
 * groups (Form, Text, Inputs, Choices, Button, Messages) render as locked rows
 * — "Button styling is a Pro feature" — and of the ten colour presets only
 * **Classic** and **Monochrome** carry no Pro badge. Those two are the whole
 * free surface, so they are what a free-tier regression guard can use.
 *
 * The assertion is that the colour *changed*, not that it equals a specific
 * hex. Pinning the exact value would couple this spec to a palette's contents,
 * which is a design decision that may legitimately change; "the control did
 * something" is the property the bug class actually violates, and it is
 * exactly what a broken pipeline fails.
 */

// Panel interaction plus two front-end round trips, on top of building a form
// and publishing a page.
test.setTimeout(150_000);

const SUBMIT_BUTTON = '.everest-forms-submit-button';

/**
 * @area    style-customizer
 * @tier    fresh
 * @source  changelog 3.6.0 — "Revamped Style Customizer"
 * @why     The revamp's entire fix history is controls that stopped applying.
 *          Nothing else in this suite would notice: the panel renders, the
 *          save succeeds, the form loads, and every style is silently the
 *          default.
 */
test('a colour preset chosen in the panel reaches the rendered form @fresh @style-customizer', async ({
  page,
  browser,
  publishedForm,
}) => {
  const baseline = await submitButtonColour(browser, publishedForm.url);
  expect(
    baseline,
    'Could not read the submit button colour before styling, so a later change could ' +
      'not be attributed to the preset.',
  ).toBeTruthy();

  // --- change the preset in the panel -------------------------------------
  await page.goto(`/wp-admin/admin.php?page=evf-builder&tab=style&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });

  // The Colors card, not its label: `.predef-kicker` is a span inside the
  // card and clicking it does nothing.
  const colorsCard = page
    .locator('button.predef-card')
    .filter({ has: page.locator('.predef-kicker', { hasText: /^colors$/i }) })
    .first();

  await expect(colorsCard, 'No Colors card in the Style panel.').toBeVisible({ timeout: 30_000 });
  await colorsCard.click();

  await expect(
    page.getByText('PRESETS', { exact: false }).first(),
    'The Colors sub-panel did not open.',
  ).toBeVisible({ timeout: 20_000 });

  // Monochrome is one of only two presets a free install can apply, and it is
  // greyscale — maximally distinct from the blue default, so a change is
  // unambiguous rather than a near-miss between similar palettes.
  const monochrome = page.getByText('Monochrome', { exact: true }).first();
  await expect(
    monochrome,
    'The Monochrome preset is missing. If the free presets changed, pick the other ' +
      'un-badged one (Classic) rather than a Pro palette this install cannot apply.',
  ).toBeVisible({ timeout: 20_000 });
  await monochrome.click();

  await page.getByRole('button', { name: 'Save', exact: true }).first().click();

  // --- verify on the front end --------------------------------------------
  //
  // Polled rather than read once. The obvious signal — the panel's
  // "All changes saved" label — is NOT one: it is the live preview's idle
  // state and is already on screen before anything is changed, so waiting for
  // it returns immediately and the front end then gets read before the save
  // has regenerated the form's CSS. That produced a confident, entirely false
  // "the control does nothing" failure against a build where it works.
  //
  // Polling states the real property: the colour changes within a bounded
  // time. A build where the pipeline is broken never changes it, so this still
  // fails for the right reason — it just no longer races the write.
  await expect
    .poll(async () => submitButtonColour(browser, publishedForm.url), {
      timeout: 60_000,
      intervals: [2_000, 3_000, 5_000],
      message:
        `The submit button is still ${baseline} a minute after applying the Monochrome ` +
        'preset and saving. The panel accepted the choice and the rendered form never ' +
        'changed — this is the v1→v2 bug class (EVF-2732/2733/2746): the control ' +
        'exists and does nothing.',
    })
    .not.toBe(baseline);

  // A second, more specific witness: V2 emits its own inline stylesheet. If the
  // colour changed but this is absent, something else moved the button and the
  // assertion above passed for the wrong reason.
  const visitor = await browser.newContext();
  try {
    const visitorPage = await visitor.newPage();
    await visitorPage.goto(publishedForm.url, { waitUntil: 'networkidle' });
    await expect(
      visitorPage.locator('style#evf-style-v2-inline-css'),
      'The form colour changed but the Style Customizer V2 stylesheet was never emitted, ' +
        'so the change did not come from the styling pipeline.',
    ).toHaveCount(1);
  } finally {
    await visitor.close();
  }
});

/**
 * The submit button's computed background colour, read as an anonymous visitor.
 *
 * A fresh context each time, deliberately: reusing one would serve the styled
 * CSS from cache and report "no change" for a build that works. Anonymous
 * because that is who sees the form — and admin styles are not in play.
 */
async function submitButtonColour(
  browser: import('@playwright/test').Browser,
  url: string,
): Promise<string> {
  const visitor = await browser.newContext();
  try {
    const visitorPage = await visitor.newPage();
    await visitorPage.goto(url, { waitUntil: 'networkidle' });

    const button = visitorPage.locator(SUBMIT_BUTTON).first();
    await expect(button, `No submit button on ${url}.`).toBeVisible({ timeout: 30_000 });

    return await button.evaluate((element) => getComputedStyle(element).backgroundColor);
  } finally {
    await visitor.close();
  }
}
