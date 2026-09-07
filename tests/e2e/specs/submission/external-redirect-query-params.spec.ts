import { test, expect } from '../../fixtures/published-form';

/**
 * Regression guard for the 3.6.1 fix:
 *
 *   "Query parameters in external redirect URLs were HTML-encoded, causing
 *    incorrect values."
 *
 * The fix (`e265d5ec4`) swaps `esc_url()` for `esc_url_raw()` in every place a
 * redirect target is emitted. `esc_url()` is for printing a URL into HTML, and
 * it encodes `&` as `&#038;` — correct for an href in markup, wrong for a URL
 * handed to `window.location`, which takes it literally.
 *
 * The damage is worse than a cosmetic mangling, which is why this spec asserts
 * on the *second* parameter rather than merely on the string. A target of
 *
 *     https://example.test/?a=1&b=2
 *
 * became
 *
 *     https://example.test/?a=1&#038;b=2
 *
 * and `#` opens the fragment, so everything from there on leaves the query
 * entirely: `a` survives, `b` is silently gone. A site owner passing a form
 * value to their CRM would get a request that looks fine, arrives, and is
 * missing data — with nothing anywhere reporting an error.
 *
 * Two parameters is therefore the minimum meaningful case. A single-parameter
 * redirect passes with the bug present.
 */

test.setTimeout(150_000);

const CONFIRMATION_TAB = (formId: string) =>
  `/wp-admin/admin.php?page=evf-builder&tab=settings&form_id=${formId}`;

/**
 * @area    submission
 * @tier    fresh
 * @source  changelog 3.6.1 — "Query parameters in external redirect URLs were
 *          HTML-encoded, causing incorrect values"
 * @why     Silent data loss on a hand-off to an external system. Nothing
 *          errors, the visitor sees a normal redirect, and the receiving end
 *          gets a request missing every parameter after the first.
 */
test('an external redirect keeps every query parameter @fresh @submission', async ({
  page,
  browser,
  publishedForm,
}) => {
  const stamp = `qa${Date.now()}`;
  const origin = new URL(publishedForm.url).origin;

  // The site's own front page is the redirect target: "external" here means
  // "a URL typed into this field", and pointing at a real reachable page keeps
  // the assertion about the URL rather than about network conditions.
  // Two parameters, because one would pass against the bug.
  const target = `${origin}/?evfqa=${stamp}&second=kept`;

  // --- configure the confirmation ------------------------------------------
  await page.goto(CONFIRMATION_TAB(publishedForm.formId), { waitUntil: 'domcontentloaded' });

  await page
    .locator('.everest-forms-panel-sidebar a')
    .filter({ hasText: /^Confirmation$/ })
    .first()
    .click();

  const externalUrlRadio = page.locator('#everest-forms-panel-field-settings-redirect_to-3');
  await expect(
    externalUrlRadio,
    'No "Redirect to External URL" option in the Confirmation settings.',
  ).toBeAttached({ timeout: 20_000 });
  // The radio is styled, so the visible target is its label rather than the
  // input; `force` checks the input itself and skips the hit-test.
  await externalUrlRadio.check({ force: true });

  const urlField = page.locator('#everest-forms-panel-field-settings-external_url');
  await expect(
    urlField,
    'Choosing "Redirect to External URL" did not reveal the URL field.',
  ).toBeVisible({ timeout: 15_000 });
  await urlField.fill(target);

  await page.getByRole('button', { name: 'Save', exact: true }).first().click();
  await page.waitForLoadState('networkidle').catch(() => {});

  // --- submit as a visitor and follow the redirect --------------------------
  const visitor = await browser.newContext();
  try {
    const visitorPage = await visitor.newPage();
    await visitorPage.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });

    const form = visitorPage.locator(`form#evf-form-${publishedForm.formId}`);
    await expect(form, 'The form did not render for a visitor.').toBeVisible({ timeout: 30_000 });

    // Filled by input type — templates do not share field ids.
    const textInputs = form.locator('input[type="text"]:visible');
    for (let i = 0; i < (await textInputs.count()); i++) {
      await textInputs.nth(i).fill(stamp);
    }
    const email = form.locator('input[type="email"]:visible');
    if ((await email.count()) > 0) await email.first().fill('qa-visitor@example.com');
    const textarea = form.locator('textarea:visible');
    if ((await textarea.count()) > 0) await textarea.first().fill(`Body ${stamp}`);

    await form.locator('.everest-forms-submit-button').click();

    // The redirect is driven by `window.location`, so wait for the URL to
    // become the target rather than for a load event on the form's own page.
    await visitorPage.waitForURL(/evfqa=/, { timeout: 45_000 });

    const landed = new URL(visitorPage.url());

    expect(
      landed.searchParams.get('evfqa'),
      `Redirected to ${landed.href}, but the first query parameter did not survive. ` +
        'The redirect fired and the URL is wrong from the start.',
    ).toBe(stamp);

    // The assertion that actually distinguishes fixed from broken.
    expect(
      landed.searchParams.get('second'),
      `Redirected to ${landed.href}. The first parameter survived and "second" did ` +
        'not — this is the 3.6.1 bug back: `esc_url()` encoded the "&" as "&#038;", ' +
        'the "#" opened a fragment, and every parameter after the first fell out of ' +
        'the query silently.',
    ).toBe('kept');

    expect(
      landed.hash,
      `The landed URL carries a fragment (${landed.hash}), which is the signature of ` +
        'the "&" having been HTML-encoded into "&#038;".',
    ).toBe('');
  } finally {
    await visitor.close();
  }
});
