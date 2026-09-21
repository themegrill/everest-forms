import { test, expect } from '../../fixtures/published-form';

/**
 * Critical flow #1, second half, and the thing the whole product exists to do:
 * a visitor fills in a form, submits it, and the site owner finds the entry.
 *
 * Everything else in this suite is scaffolding around this. A build can pass
 * every other spec — admin screens render, settings save, forms can be created
 * and styled — while silently accepting no submissions at all, and until this
 * existed nothing would have noticed.
 *
 * Three properties are asserted, deliberately separately, because they fail
 * for different reasons and a single "it worked" check could not tell them
 * apart:
 *
 *   1. the visitor sees a confirmation      → the submission was accepted
 *   2. the entry is listed in wp-admin      → it was persisted, not just echoed
 *   3. the submitted values are in it       → the right data was persisted
 *
 * (2) is the one that matters most and the one a manual tester skips. A
 * confirmation message is rendered by the same request that was supposed to
 * write the row; a form that shows "Thanks for contacting us" and stores
 * nothing looks perfectly healthy from the front end.
 *
 * Run **anonymously**, in a context with no admin cookies. Submitting as a
 * logged-in administrator exercises a different capability path than any real
 * visitor takes, and the plugin's own preview URL is admin-only — see
 * fixtures/published-form.ts.
 */

/**
 * @area    submission
 * @tier    fresh
 * @source  knowledge-init 2026-09-07 — critical flow #1
 * @why     This is the product. `includes/class-evf-form-task.php` carries the
 *          whole submission path and has 11 modify commits in the last 400;
 *          a regression in it is total, not partial, and nothing else here
 *          would catch it.
 */
// The longest path in the suite: build a form, publish a page, submit as a
// visitor in a second browser context, then verify in wp-admin.
test.setTimeout(120_000);

test('a visitor can submit a form and the entry reaches the admin @fresh @submission', async ({
  page,
  browser,
  publishedForm,
}) => {
  // Unique per run, so the admin-side assertion cannot pass on an entry left
  // behind by an earlier run — the failure mode that makes a persistence test
  // quietly worthless.
  const stamp = `QA-${Date.now()}`;
  const visitorEmail = 'qa-visitor@example.com';

  // A fresh context with no storage state: a real visitor is not logged in.
  const visitor = await browser.newContext();
  const visitorPage = await visitor.newPage();

  try {
    const response = await visitorPage.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), 'The page hosting the form is not reachable to a visitor.').toBe(
      200,
    );

    // `form.everest-form` — singular. The wrapper div is `.everest-forms`, the
    // form element itself is not, which is an easy hour to lose.
    const form = visitorPage.locator(`form#evf-form-${publishedForm.formId}`);
    await expect(
      form,
      'The shortcode rendered no form for an anonymous visitor. Either the shortcode ' +
        'output is gated, or the form failed to render outside wp-admin.',
    ).toBeVisible({ timeout: 30_000 });

    // Filled by input type, not by field name.
    //
    // The fixture builds its form from whichever template is first in the Free
    // list, and templates do not share field ids — the earlier version of this
    // spec hardcoded `fullname`/`subject` from a different form and hung
    // waiting on inputs that were not there. Typing is the stable contract:
    // every contact-shaped template has text inputs, one email input and a
    // textarea.
    const textInputs = form.locator('input[type="text"]:visible');
    const textCount = await textInputs.count();
    expect(
      textCount,
      'The rendered form has no text inputs to fill, so there is nothing to submit.',
    ).toBeGreaterThan(0);

    // The stamp goes in every text field so the admin-side lookup finds it
    // whichever column the Entries table chooses to show.
    for (let i = 0; i < textCount; i++) {
      await textInputs.nth(i).fill(stamp);
    }

    const emailInput = form.locator('input[type="email"]:visible');
    if ((await emailInput.count()) > 0) {
      await emailInput.first().fill(visitorEmail);
    }

    const textarea = form.locator('textarea:visible');
    if ((await textarea.count()) > 0) {
      await textarea.first().fill(`Message body for ${stamp}`);
    }

    await form.locator('.everest-forms-submit-button').click();

    // 1. The visitor is told it worked.
    await expect(
      visitorPage.locator('.everest-forms-notice--success, .everest-forms-confirmation').first(),
      'No confirmation after submitting. The submission was rejected, or the ' +
        'confirmation step is broken — check for a validation error on the form.',
    ).toBeVisible({ timeout: 30_000 });
  } finally {
    await visitor.close();
  }

  // 2 and 3. The entry actually exists, as the site owner would find it.
  // `page` is the admin context the suite logs in once for.
  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });

  const entryRow = page.locator('tbody tr', { hasText: stamp }).first();
  await expect(
    entryRow,
    `The visitor saw a confirmation but no entry containing "${stamp}" reached the ` +
      'Entries screen. The submission was accepted and then lost — the worst ' +
      'possible outcome, because the site owner has no way to know it happened.',
  ).toBeVisible({ timeout: 30_000 });

  await expect(
    entryRow,
    'The entry exists but does not carry the email that was submitted, so the wrong ' +
      'data was persisted.',
  ).toContainText(visitorEmail);
});
