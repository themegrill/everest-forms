import { test, expect } from '../../fixtures/form';
import type { Page } from '@playwright/test';

/**
 * Regression guard: a submitted textarea value re-rendered into a block-embedded
 * form cannot run as a shortcode.
 *
 * When a form is shown through the plugin's own "Everest Forms" block
 * (everest-forms/form-selector), the block renders during do_blocks()
 * (the_content priority 9) and that output is then scanned by do_shortcode()
 * (priority 11). The textarea printed a reflected POST value in element body
 * with esc_html(), which leaves [ and ] intact — so an anonymous visitor could
 * post [audio src=...] as the field value and have WordPress run the core
 * shortcode, returning its output in the response. The shortcode form embed is
 * not affected: only the block's do_blocks() output is re-scanned.
 *
 * The textarea now entity-encodes square brackets in the reflected body
 * (&#91; / &#93;), so the value still displays as typed but do_shortcode() can
 * no longer read it as a shortcode.
 */

test.setTimeout(150_000);

const MARKER = 'https://attacker.example/evf-qa-shortcode.mp3';

/**
 * @area    security
 * @tier    fresh
 * @source  Patchstack VDP 0c73d06c — unauthenticated shortcode execution via a reflected form field
 * @why     An anonymous visitor could make WordPress run an attacker-chosen
 *          shortcode by posting [shortcode] text as a textarea value of a
 *          block-embedded form. Guards that the reflected value is inert, not
 *          the textarea's styling, and not the shortcode-embed path core
 *          already protects.
 */
test('a reflected textarea value cannot run a shortcode on a block-embedded form @fresh @security', async ({
  page,
  browser,
  form,
}) => {
  // Host the form through the block, not the shortcode: only the block's
  // do_blocks() output is re-scanned by do_shortcode(), so the shortcode embed
  // would prove nothing.
  const nonce = await restNonce(page);
  const hostRes = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: {
      title: `QA block form host ${form.id}`,
      status: 'publish',
      content: `<!-- wp:everest-forms/form-selector {"formId":"${form.id}"} /-->`,
    },
  });
  expect(
    hostRes.status(),
    'Could not publish a block page to host the form, so the block render path is unreachable.',
  ).toBe(201);
  const host = await hostRes.json();

  const visitor = await browser.newContext();
  try {
    const vp = await visitor.newPage();
    await vp.goto(host.link, { waitUntil: 'domcontentloaded' });

    const textarea = vp.locator('textarea[name^="everest_forms[form_fields]"]').first();
    await expect(
      textarea,
      'The hosted form has no textarea field, so this guard would pass without testing anything.',
    ).toBeAttached({ timeout: 30_000 });
    const fieldName = (await textarea.getAttribute('name')) as string;

    // Anonymous POST straight to the page, the way the PoC does — no form UI, no
    // admin cookies, no nonce; the plugin re-renders the form with the value.
    const exploit = await vp.request.post(host.link, {
      form: { 'everest_forms[id]': form.id, [fieldName]: `[audio src=${MARKER}]` },
    });
    const exploitHtml = await exploit.text();

    expect(
      exploitHtml.includes('wp-audio-shortcode'),
      'An anonymous POST of [audio src=...] into the textarea ran the core [audio] shortcode and ' +
        'returned its rendered output. This is the unauthenticated shortcode execution this guard exists for.',
    ).toBe(false);

    // Control: the same text without brackets is echoed back verbatim, proving
    // the form really does reflect the submitted value — so the check above is
    // asserting on a live reflection, not a form that ignored the input.
    const control = await vp.request.post(host.link, {
      form: { 'everest_forms[id]': form.id, [fieldName]: `audio src=${MARKER}` },
    });
    expect(
      (await control.text()).includes(`audio src=${MARKER}`),
      'The form did not reflect the submitted textarea value at all, so the guard proves nothing.',
    ).toBe(true);
  } finally {
    await visitor.close();
    await page.request
      .delete(`/wp-json/wp/v2/pages/${host.id}?force=true`, { headers: { 'X-WP-Nonce': nonce } })
      .catch(() => {
        /* Never fail a run over cleanup. */
      });
  }
});

/**
 * @area    security
 * @tier    fresh
 * @source  QA review of PR #1691 — same reflected shortcode execution through the WYSIWYG field
 * @why     The WYSIWYG field passes the reflected value to wp_editor(), which
 *          HTML-escapes it but leaves [ and ] intact, so the block's output was
 *          re-scanned by do_shortcode() exactly as the textarea's was. Guards
 *          that the reflected rich-text value is inert; does not assert the
 *          editor's toolbar, styling or TinyMCE behaviour.
 */
test('a reflected WYSIWYG value cannot run a shortcode on a block-embedded form @fresh @security', async ({
  page,
  browser,
  form,
}) => {
  const fieldId = await addWysiwygField(page, form.id);

  const nonce = await restNonce(page);
  const hostRes = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: {
      title: `QA block form host ${form.id}`,
      status: 'publish',
      content: `<!-- wp:everest-forms/form-selector {"formId":"${form.id}"} /-->`,
    },
  });
  expect(
    hostRes.status(),
    'Could not publish a block page to host the form, so the block render path is unreachable.',
  ).toBe(201);
  const host = await hostRes.json();

  const visitor = await browser.newContext();
  try {
    const vp = await visitor.newPage();
    await vp.goto(host.link, { waitUntil: 'domcontentloaded' });

    const fieldName = `everest_forms[form_fields][${fieldId}]`;
    await expect(
      vp.locator(`textarea[name="${fieldName}"]`),
      'The hosted form has no WYSIWYG field, so this guard would pass without testing anything.',
    ).toBeAttached({ timeout: 30_000 });

    const exploit = await vp.request.post(host.link, {
      form: { 'everest_forms[id]': form.id, [fieldName]: `[audio src=${MARKER}]` },
    });
    expect(
      (await exploit.text()).includes('wp-audio-shortcode'),
      'An anonymous POST of [audio src=...] into the WYSIWYG field ran the core [audio] shortcode ' +
        'and returned its rendered output. This is the unauthenticated shortcode execution this guard exists for.',
    ).toBe(false);

    const control = await vp.request.post(host.link, {
      form: { 'everest_forms[id]': form.id, [fieldName]: `audio src=${MARKER}` },
    });
    expect(
      (await control.text()).includes(`audio src=${MARKER}`),
      'The form did not reflect the submitted WYSIWYG value at all, so the guard proves nothing.',
    ).toBe(true);
  } finally {
    await visitor.close();
    await page.request
      .delete(`/wp-json/wp/v2/pages/${host.id}?force=true`, { headers: { 'X-WP-Nonce': nonce } })
      .catch(() => {
        /* Never fail a run over cleanup. */
      });
  }
});

/**
 * Add a WYSIWYG field to the fixture's form through the builder, and save.
 * Returns the id the builder assigned, since the front-end input is named after it.
 */
async function addWysiwygField(page: Page, formId: string): Promise<string> {
  await page.goto(`/wp-admin/admin.php?page=evf-builder&tab=fields&form_id=${formId}`, {
    waitUntil: 'domcontentloaded',
  });

  const button = page.locator('#everest-forms-add-fields-wysiwyg');
  await expect(
    button,
    'The builder offers no WYSIWYG field at all, so there is nothing to guard here.',
  ).toBeVisible({ timeout: 30_000 });

  const locked = await button.evaluate((el) =>
    el.classList.contains('upgrade-modal') || el.classList.contains('evf-upgrade-addon'),
  );
  test.skip(locked, 'WYSIWYG is a Pro field and Pro is not active on this site.');

  await button.click();

  const field = page.locator('.everest-forms-field[data-field-type="wysiwyg"]').first();
  await expect(field, 'Clicking the WYSIWYG field did not add it to the form.').toBeVisible({
    timeout: 30_000,
  });
  const fieldId = await field.getAttribute('data-field-id');
  expect(fieldId, 'The new WYSIWYG field has no field id to submit against.').toBeTruthy();

  await page.locator('.everest-forms-save-button').first().click();
  await expect(
    page.locator('.everest-forms-save-button'),
    'Saving the form with its new WYSIWYG field never finished.',
  ).toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1_000);

  return fieldId as string;
}

/**
 * A REST nonce for the logged-in admin, read off a wp-admin screen rather than
 * minted — cookie-authenticated REST requests are rejected without the nonce
 * WordPress itself issued for this session.
 */
async function restNonce(page: Page): Promise<string> {
  await page.goto('/wp-admin/post-new.php?post_type=page', { waitUntil: 'domcontentloaded' });
  const handle = await page.waitForFunction(
    () => (window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce,
    undefined,
    { timeout: 30_000 },
  );
  const value = (await handle.jsonValue()) as string;
  expect(value, 'No REST nonce on the new-page screen; cannot create the host page.').toBeTruthy();
  return value;
}
