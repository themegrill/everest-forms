import { test, expect } from '../../fixtures/published-form';

/**
 * Wordfence-reported unauthenticated smart-tag injection (Everest Forms
 * <= 3.6.1): a visitor's own submitted value could be re-resolved as a smart
 * tag. The original fix (#1667) only closed the Hidden-field post-submission
 * path. This spec guards a separate bypass found and fixed alongside it
 * (PR #1669): `{field_id="X"}` — used in an admin-authored redirect query
 * string to embed a submitted field's value, per the builder's own example
 * placeholder (`firstname={field_id="..."}`) — was substituted in *before*
 * the generic smart-tag scan ran, so a tag typed into any ordinary visible
 * field referenced that way still got re-resolved.
 *
 * `{admin_email}` is the payload here rather than `{post_meta key=...}`
 * because it needs no test-data setup (no target post/meta to seed) and is
 * still real site data an anonymous visitor has no business extracting
 * through form input.
 *
 * Reuses the same "External URL + Append Query String" mechanism as
 * `external-redirect-query-params.spec.ts` — only the query string's value
 * differs (a smart tag instead of a plain string).
 *
 * Does not cover the Hidden-field default_value path (#1667's original fix)
 * — that is guarded at the unit level in
 * tests/phpunit/includes/class-evf-smart-tags-security-test.php, since it
 * needs a Hidden field with a specific default_value and this suite has no
 * builder-driven fixture for adding a custom field yet.
 */

test.setTimeout(150_000);

const SETTINGS_TAB = (formId: string) =>
  `/wp-admin/admin.php?page=evf-builder&tab=settings&form_id=${formId}`;
const FIELDS_TAB = (formId: string) =>
  `/wp-admin/admin.php?page=evf-builder&tab=fields&form_id=${formId}`;

/**
 * @area    submission
 * @tier    fresh
 * @guards  wordfence-evf-3.6.1-smart-tag-disclosure
 * @source  verify-fix 2026-09-29 — https://github.com/themegrill/everest-forms/pull/1669
 * @why     A visitor-typed smart tag embedded into an admin-authored redirect
 *          query string via {field_id} must stay literal text. Proves the
 *          resolution-order fix in EVF_Smart_Tags::process(); does not assert
 *          anything about the Hidden-field default_value path, which is
 *          unit-tested separately.
 */
test('a smart tag typed into a field is not resolved when echoed via {field_id} in a redirect query string @fresh @submission', async ({
  page,
  browser,
  publishedForm,
}) => {
  // --- find a text field's id from the builder -----------------------------
  await page.goto(FIELDS_TAB(publishedForm.formId), { waitUntil: 'domcontentloaded' });

  const targetField = page.locator('.everest-forms-field[data-field-type="text"]').first();
  await expect(
    targetField,
    'No text field on the fixture form to target — the template this suite builds ' +
      'from must have changed.',
  ).toBeVisible({ timeout: 30_000 });
  const fieldId = await targetField.getAttribute('data-field-id');
  expect(fieldId, 'The text field has no data-field-id.').toBeTruthy();

  // --- configure an External URL redirect with the payload in the query ----
  const origin = new URL(publishedForm.url).origin;

  await page.goto(SETTINGS_TAB(publishedForm.formId), { waitUntil: 'domcontentloaded' });
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
  await externalUrlRadio.check({ force: true });

  const urlField = page.locator('#everest-forms-panel-field-settings-external_url');
  await expect(
    urlField,
    'Choosing "Redirect to External URL" did not reveal the URL field.',
  ).toBeVisible({ timeout: 15_000 });
  await urlField.fill(`${origin}/`);

  const appendQueryToggle = page.locator(
    '#everest-forms-panel-field-settings-enable_redirect_query_string',
  );
  await expect(
    appendQueryToggle,
    'No "Append Query String" toggle in the Confirmation settings.',
  ).toBeAttached({ timeout: 15_000 });
  await appendQueryToggle.check({ force: true });

  const queryStringField = page.locator('#everest-forms-panel-field-settings-query_string');
  await expect(
    queryStringField,
    'Enabling "Append Query String" did not reveal the Query String field.',
  ).toBeVisible({ timeout: 15_000 });
  // Matches the builder's own documented syntax for this field
  // (placeholder example: `firstname={field_id="..."}`).
  await queryStringField.fill(`payload={field_id="${fieldId}"}`);

  await page.getByRole('button', { name: 'Save', exact: true }).first().click();
  await page.waitForLoadState('networkidle').catch(() => {});

  // --- submit as a visitor, with the payload in the targeted field ----------
  const visitor = await browser.newContext();
  try {
    const visitorPage = await visitor.newPage();
    await visitorPage.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });

    const form = visitorPage.locator(`form#evf-form-${publishedForm.formId}`);
    await expect(form, 'The form did not render for a visitor.').toBeVisible({ timeout: 30_000 });

    // Fill every required field normally first, so validation does not block
    // the submission, then overwrite the one we are attacking.
    const textInputs = form.locator('input[type="text"]:visible');
    const textCount = await textInputs.count();
    for (let i = 0; i < textCount; i++) {
      await textInputs.nth(i).fill(`filler-${i}`);
    }
    const emailInput = form.locator('input[type="email"]:visible');
    if ((await emailInput.count()) > 0) {
      await emailInput.first().fill('qa-visitor@example.com');
    }
    const textarea = form.locator('textarea:visible');
    if ((await textarea.count()) > 0) {
      await textarea.first().fill('filler message');
    }

    const targetInput = form.locator(`[name="everest_forms[form_fields][${fieldId}]"]`);
    await expect(
      targetInput,
      `No input named for field ${fieldId} on the rendered form.`,
    ).toBeVisible({ timeout: 15_000 });
    await targetInput.fill('{admin_email}');

    await form.locator('.everest-forms-submit-button').click();

    // The redirect is driven by `window.location`, so wait for the URL to
    // become the target rather than for a load event on the form's own page.
    await visitorPage.waitForURL(/[?&]payload=/, { timeout: 45_000 });

    const landed = new URL(visitorPage.url());

    expect(
      landed.searchParams.get('payload'),
      `Redirected to ${landed.href}. A visitor-typed smart tag ({admin_email}) in the ` +
        'field was re-resolved into the real admin email instead of being kept as the ' +
        'literal text the visitor typed.',
    ).toBe('{admin_email}');
  } finally {
    await visitor.close();
  }
});
