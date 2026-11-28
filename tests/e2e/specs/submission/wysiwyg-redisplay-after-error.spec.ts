import { test, expect } from '../../fixtures/published-form';
import type { Page } from '@playwright/test';

/**
 * Regression guard for a Rich Text answer redisplayed after a server-side
 * validation error.
 *
 * When a submission failed a server-side check, the WYSIWYG editor was filled
 * with the visitor's HTML escaped twice, so it showed `<strong>…</strong>` as
 * text, and resubmitting saved the tags as text. Attribute quotes inside the
 * redisplayed editor were also turned into curly quotes, breaking links, and
 * text typed as a literal tag must stay text.
 *
 * Does not assert AJAX submission (which does not redisplay the form).
 */

test.setTimeout(150_000);

const CONTENT =
  '<p>evf-qa <strong>evf-qa-bold</strong>, don\'t <a href="https://example.com/evf-qa">evf-qa-link</a> &lt;em&gt;evf-qa-literal&lt;/em&gt;</p>';

/**
 * @area    submission
 * @tier    fresh
 * @source  everest-forms-pro#1338 — Rich Text field shows HTML tags as text after a server-side validation error
 * @why     Visitors who hit a server-side error lost their formatting, and a
 *          resubmission stored raw tags as the answer.
 */
test('a Rich Text answer keeps its formatting after a server-side validation error @fresh @submission', async ({
  page,
  browser,
  publishedForm,
}) => {
  const fieldId = await addWysiwygField(page, publishedForm.formId);

  const visitor = await browser.newContext();
  try {
    const vp = await visitor.newPage();
    await vp.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });
    await vp.waitForFunction(
      () => ((window as unknown as { tinymce?: { editors: unknown[] } }).tinymce?.editors ?? []).length > 0,
      null,
      { timeout: 30_000 },
    );

    // Invalid email and empty required fields, sent with a native submit so only the server validates.
    const navigation = vp.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await vp.evaluate(
      ({ id, html }) => {
        const mce = (window as unknown as {
          tinymce: { editors: { getElement: () => HTMLElement; setContent: (h: string) => void }[]; triggerSave: () => void };
        }).tinymce;
        const editor = mce.editors.find((e) => e.getElement().getAttribute('name') === `everest_forms[form_fields][${id}]`);
        editor?.setContent(html);
        mce.triggerSave();
        const form = document.querySelector<HTMLFormElement>('form.everest-form');
        form?.querySelectorAll<HTMLInputElement>('input[type="email"]').forEach((i) => (i.value = 'not-an-email'));
        if (form) HTMLFormElement.prototype.submit.call(form);
      },
      { id: fieldId, html: CONTENT },
    );

    await navigation;
    await expect(
      vp.locator('.everest-forms-notice--error, .everest-forms-notice').first(),
      'The submission did not come back with a validation error, so the redisplay was never exercised.',
    ).toBeVisible({ timeout: 30_000 });
    // TinyMCE fills the editor asynchronously after it is created.
    await vp
      .waitForFunction(
        (id) => {
          const mce = (window as unknown as {
            tinymce?: { editors: { initialized?: boolean; getElement: () => HTMLElement; getContent: () => string }[] };
          }).tinymce;
          const editor = mce?.editors.find((e) => e.getElement().getAttribute('name') === `everest_forms[form_fields][${id}]`);
          return !!editor && !!editor.initialized && '' !== editor.getContent();
        },
        fieldId,
        { timeout: 30_000 },
      )
      .catch(() => undefined);

    const shown = await vp.evaluate((id) => {
      const mce = (window as unknown as {
        tinymce: { editors: { getElement: () => HTMLElement; getContent: (o?: object) => string }[] };
      }).tinymce;
      const editor = mce.editors.find((e) => e.getElement().getAttribute('name') === `everest_forms[form_fields][${id}]`);
      return editor ? { html: editor.getContent(), text: editor.getContent({ format: 'text' }) } : null;
    }, fieldId);

    expect(shown, 'The redisplayed form has no Rich Text editor.').not.toBeNull();
    expect(
      shown?.text,
      `The editor shows HTML tags as text after the error: ${JSON.stringify(shown?.text)}`,
    ).not.toContain('<strong>');
    expect(shown?.html, 'Bold formatting was lost after the validation error.').toContain(
      '<strong>evf-qa-bold</strong>',
    );
    expect(
      shown?.html,
      'The link came back broken (quotes changed or escaped) after the validation error.',
    ).toContain('href="https://example.com/evf-qa"');
    expect(shown?.text, 'The apostrophe was changed after the validation error.').toContain("don't");
    expect(
      shown?.text,
      'Text typed as a literal tag turned into formatting after the validation error.',
    ).toContain('<em>evf-qa-literal</em>');

    // Fix the other fields and submit the redisplayed form as it is.
    await fillOtherFields(vp, fieldId);
    const resubmit = vp.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await vp.evaluate(() => {
      (window as unknown as { tinymce: { triggerSave: () => void } }).tinymce.triggerSave();
      const form = document.querySelector<HTMLFormElement>('form.everest-form');
      if (form) HTMLFormElement.prototype.submit.call(form);
    });
    await resubmit;
  } finally {
    await visitor.close();
  }

  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });
  const entryLink = page.locator('a[href*="view-entry="]').first();
  await expect(entryLink, 'The corrected resubmission did not create an entry.').toBeAttached({
    timeout: 30_000,
  });
  await page.goto((await entryLink.getAttribute('href')) as string, { waitUntil: 'domcontentloaded' });

  const value = page.locator('.evf-field-value').filter({ hasText: 'evf-qa-bold' }).first();
  await expect(value, 'The Rich Text answer is missing from the saved entry.').toBeVisible({ timeout: 30_000 });
  await expect(
    value.locator('strong', { hasText: 'evf-qa-bold' }),
    'The saved entry lost the bold formatting (or stored the tags as text).',
  ).toBeVisible();
  await expect(
    value.locator('a[href="https://example.com/evf-qa"]'),
    'The saved entry lost the link.',
  ).toBeVisible();
  expect(await value.innerText(), 'The saved entry shows HTML tags as text.').not.toContain('<strong>');
});

/**
 * Fill the template's own text inputs so the resubmission is accepted.
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
    if (name.includes(`[${wysiwygId}]`) || name === 'everest_forms[hp]' || !(await field.isVisible())) {
      continue;
    }
    const type = await field.getAttribute('type');
    await field.fill(type === 'email' ? 'evf-qa@example.com' : 'evf-qa').catch(() => {
      /* A read-only template field is not this guard's concern. */
    });
  }
}

/**
 * Add a WYSIWYG field to the form in the builder and save it.
 */
async function addWysiwygField(page: Page, formId: string): Promise<string> {
  await page.goto(`/wp-admin/admin.php?page=evf-builder&tab=fields&form_id=${formId}`, {
    waitUntil: 'domcontentloaded',
  });

  const button = page.locator('#everest-forms-add-fields-wysiwyg');
  await expect(button, 'The builder offers no WYSIWYG field.').toBeVisible({ timeout: 30_000 });

  const locked = await button.evaluate(
    (el) => el.classList.contains('upgrade-modal') || el.classList.contains('evf-upgrade-addon'),
  );
  test.skip(locked, 'WYSIWYG is a Pro field and Pro is not active on this site.');

  await button.click();

  const field = page.locator('.everest-forms-field[data-field-type="wysiwyg"]').first();
  await expect(field, 'Clicking the WYSIWYG field did not add it to the form.').toBeVisible({
    timeout: 30_000,
  });
  const fieldId = await field.getAttribute('data-field-id');
  expect(fieldId, 'The new WYSIWYG field has no field id.').toBeTruthy();

  await page.locator('.everest-forms-save-button').first().click();
  await expect(
    page.locator('.everest-forms-save-button'),
    'Saving the form with its new WYSIWYG field never finished.',
  ).toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1_000);

  return fieldId as string;
}
