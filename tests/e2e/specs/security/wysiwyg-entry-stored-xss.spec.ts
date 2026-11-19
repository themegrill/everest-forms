import { test, expect } from '../../fixtures/published-form';
import type { Page } from '@playwright/test';

/**
 * Regression guard for stored XSS through the WYSIWYG field.
 *
 * The field used to store its submission with `wp_kses_post()`, which keeps
 * `class`, `id` and `data-*`. The single-entry screen printed that markup as
 * is, and `admin.js` turns every `.tips` element on the page into a Tooltipster
 * tooltip on load — Tooltipster passes `data-tooltip-content` straight to
 * `jQuery()`. So a visitor's `<span class="tips" data-tooltip-content="...">`
 * built whatever node it named and ran it in the admin's browser, with no click
 * and no hover.
 *
 * The field now sanitizes with a stricter allowlist that drops those attributes
 * on save *and* on display, and tooltips no longer initialise inside an entry
 * value.
 */

test.setTimeout(150_000);

/** Sets a flag rather than calling alert(), so the assertion can read the result. */
const MARKER = '__evfEntryXssRan';

const PAYLOAD =
  '<p><strong>evf-qa-bold</strong> <a href="https://example.com">evf-qa-link</a></p>' +
  `<span class="tips" data-tooltip-content="&lt;img src=x onerror=window.${MARKER}=1&gt;">evf-qa-span</span>`;

/**
 * @area    security
 * @tier    fresh
 * @source  security report — stored XSS in WYSIWYG entries (<= 3.6.4)
 * @why     An anonymous visitor could run JavaScript in an administrator's
 *          session simply by submitting a form, the moment that admin opened
 *          the entry to read it.
 */
test('a WYSIWYG submission cannot run script on the entry screen @fresh @security', async ({
  page,
  browser,
  publishedForm,
}) => {
  const fieldId = await addWysiwygField(page, publishedForm.formId);

  // Submit as a real visitor: anonymous, no admin cookies, no admin nonce.
  const visitor = await browser.newContext();
  try {
    const vp = await visitor.newPage();
    await vp.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });

    const editor = vp.locator(`[name="everest_forms[form_fields][${fieldId}]"]`);
    await expect(
      editor,
      'The published form has no WYSIWYG field, so this guard would pass without testing anything.',
    ).toBeAttached({ timeout: 30_000 });

    // TinyMCE owns the textarea; removing it lets the raw markup post, which is
    // what an attacker sends anyway — they never use the editor.
    await vp.evaluate(
      ({ id, html }) => {
        const w = window as unknown as { tinymce?: { remove: () => void } };
        w.tinymce?.remove();
        const ta = document.querySelector<HTMLTextAreaElement>(
          `[name="everest_forms[form_fields][${id}]"]`,
        );
        if (ta) ta.value = html;
      },
      { id: fieldId, html: PAYLOAD },
    );

    await fillOtherFields(vp, fieldId);

    await vp.locator('.evf-submit').first().click();
    await expect(
      vp.locator('.everest-forms-notice--success, .evf-success-message').first(),
      'The submission was rejected, so nothing was stored and the guard proved nothing.',
    ).toBeVisible({ timeout: 30_000 });
  } finally {
    await visitor.close();
  }

  // Now read it the way an administrator does.
  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });
  const entryLink = page.locator('a[href*="view-entry="]').first();
  await expect(entryLink, 'The submission did not reach the entries list.').toBeAttached({
    timeout: 30_000,
  });

  // Followed rather than clicked: the link lives in a hover-only row action.
  const entryUrl = await entryLink.getAttribute('href');
  await page.goto(entryUrl as string, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  // Tooltipster initialises on load; give it the same beat the browser gives it.
  await page.waitForTimeout(1_500);

  const executed = await page.evaluate(
    (marker) => (window as unknown as Record<string, unknown>)[marker],
    MARKER,
  );
  expect(
    executed,
    "A visitor's WYSIWYG submission executed JavaScript in the administrator's browser " +
      'just by having the entry opened. This is the stored XSS this guard exists for.',
  ).toBeUndefined();

  const value = page.locator('.evf-field-value').filter({ hasText: 'evf-qa-span' }).first();
  await expect(value, 'The submitted rich text is missing from the entry screen.').toBeVisible();

  // The attributes are the attack surface, so assert on the rendered markup
  // itself. An element query is not enough: an active Payment addon decodes the
  // value and the broken span then renders as escaped text — no live element,
  // yet `data-tooltip-content` is still right there in the HTML for the next
  // environment where it is not mangled. innerHTML catches both shapes.
  const markup = await value.innerHTML();
  expect(
    markup,
    'The stored WYSIWYG value kept its data-tooltip-content attribute. admin.js feeds that ' +
      'attribute straight to jQuery(), so the value can still build and run a node.',
  ).not.toContain('data-tooltip-content');
  expect(
    markup,
    'The stored WYSIWYG value kept an inline event handler, which is script waiting to run.',
  ).not.toContain('onerror');
  expect(
    markup,
    'The stored WYSIWYG value kept the class that admin.js turns into a tooltip.',
  ).not.toContain('tips');

  // The fix must not turn the rich text field into a plain text field.
  await expect(
    value.locator('strong', { hasText: 'evf-qa-bold' }),
    'Bold formatting was stripped — the sanitizer is now too strict and the WYSIWYG field ' +
      'no longer keeps the formatting it promises.',
  ).toBeVisible();
  await expect(
    value.locator('a[href="https://example.com"]'),
    'Links were stripped — the sanitizer is now too strict for ordinary rich text.',
  ).toBeVisible();
});

/**
 * Add a WYSIWYG field to the fixture's form through the builder, and save.
 *
 * Clicked rather than dragged: the builder binds both, and a jQuery UI drag
 * would fail this security guard for reasons that have nothing to do with
 * security. Returns the id the builder assigned, since the front-end input is
 * named after it.
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

  // The field is Pro-gated; without Pro the button only opens an upsell.
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
 * Fill the template's own fields so the submission turns on the payload alone.
 *
 * Which fields those are depends on which template the fixture picked, and
 * required-ness is enforced server-side, so everything fillable gets a value —
 * except the WYSIWYG field itself and the honeypot, which must stay empty.
 */
async function fillOtherFields(vp: Page, wysiwygId: string): Promise<void> {
  const inputs = vp.locator(
    'form.everest-form input[type="text"], form.everest-form input[type="email"], ' +
      'form.everest-form input[type="url"], form.everest-form input[type="tel"], ' +
      'form.everest-form textarea',
  );

  for (let i = 0; i < (await inputs.count()); i++) {
    const field = inputs.nth(i);
    const name = (await field.getAttribute('name')) ?? '';

    if (name.includes(`[${wysiwygId}]`) || name === 'everest_forms[hp]') {
      continue;
    }
    if (!(await field.isVisible())) {
      continue;
    }

    const type = await field.getAttribute('type');
    await field.fill(type === 'email' ? 'evf-qa@example.com' : 'evf-qa').catch(() => {
      /* A field the template renders read-only is not this guard's problem. */
    });
  }
}
