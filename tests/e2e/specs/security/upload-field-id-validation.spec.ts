import { test, expect } from '../../fixtures/published-form';
import type { Page } from '@playwright/test';

/**
 * Regression guard for the unauthenticated upload restriction bypass.
 *
 * `everest_forms_upload_file` is public by design, since visitors upload through
 * front-end forms. The handler used to accept any `field_id`, including one that
 * does not exist in the form. With no field config to read, the field's
 * "Allowed File Extensions" list was skipped and the fallback list let files such
 * as `.html` through, to be served from the site origin.
 *
 * The handler must now reject a `field_id` that is not an upload field of the
 * submitted form.
 */

test.setTimeout(90_000);

/**
 * @area    security
 * @tier    fresh
 * @source  security report — upload field_id validation bypass
 * @why     An anonymous visitor could place attacker-controlled HTML on the site
 *          origin by sending a made-up field id.
 */
test('upload endpoint rejects an unknown field_id for a visitor @fresh @security', async ({
  browser,
  publishedForm,
}) => {
  const visitor = await browser.newContext();

  try {
    const baseURL = new URL(publishedForm.url).origin;

    const upload = (fieldId: string) =>
      visitor.request.post(`${baseURL}/wp-admin/admin-ajax.php`, {
        multipart: {
          action: 'everest_forms_upload_file',
          form_id: publishedForm.formId,
          field_id: fieldId,
          file: {
            name: 'evf-qa.html',
            mimeType: 'text/html',
            buffer: Buffer.from('<!doctype html><title>qa</title><script>1</script>'),
          },
        },
      });

    for (const fieldId of ['does-not-exist', '0', 'evf-qa-missing-field']) {
      const response = await upload(fieldId);
      const body = await response.json().catch(() => null);

      expect(
        body?.success,
        `A visitor upload with the made-up field_id "${fieldId}" was accepted ` +
          `(${JSON.stringify(body)}). The field_id must be validated against the form's upload fields.`,
      ).toBe(false);
    }
  } finally {
    await visitor.close();
  }
});

/**
 * @area    security
 * @tier    fresh
 * @source  security report — stored names of submitted uploads
 * @why     Submitted files are renamed to <name>-<suffix>.<ext>. Guards that the
 *          suffix differs per upload, so two uploads of the same file name get
 *          unrelated stored names; does not assert the folder layout or whether
 *          stored files are public.
 */
test('two uploads of the same file name get unrelated stored names @fresh @security', async ({
  page,
  browser,
  publishedForm,
}) => {
  test.setTimeout(150_000);
  const fieldId = await addFileUploadFieldForNames(page, publishedForm.formId);

  for (let i = 0; i < 2; i++) {
    const visitor = await browser.newContext();
    try {
      const vp = await visitor.newPage();
      await vp.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });
      await vp.waitForFunction(
        () => ((window as unknown as { Dropzone?: { instances?: unknown[] } }).Dropzone?.instances ?? []).length > 0,
        undefined,
        { timeout: 30_000 },
      );
      await vp.evaluate(async () => {
        const dz = (window as unknown as { Dropzone: { instances: { addFile: (f: File) => void }[] } }).Dropzone.instances[0];
        dz.addFile(new File(['%PDF-1.4\n% evf-qa\n'], 'evf-qa-cv.pdf', { type: 'application/pdf' }));
      });
      await expect(
        vp.locator('.dz-preview.dz-success'),
        'The file never finished uploading, so there is no stored name to compare.',
      ).toHaveCount(1, { timeout: 30_000 });

      await fillRemainingFields(vp, fieldId);
      await vp.locator('.evf-submit').first().click();
      await expect(
        vp.locator('.everest-forms-notice--success, .evf-success-message').first(),
        'The submission was rejected, so no file was stored.',
      ).toBeVisible({ timeout: 30_000 });
    } finally {
      await visitor.close();
    }
  }

  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });
  const entryLinks = await page.locator('a[href*="view-entry="]').evaluateAll((links) =>
    Array.from(new Set(links.map((a) => (a as HTMLAnchorElement).href))),
  );
  expect(entryLinks.length, 'Both submissions should be in the entries list.').toBeGreaterThanOrEqual(2);

  const stored: string[] = [];
  for (const link of entryLinks.slice(0, 2)) {
    await page.goto(link, { waitUntil: 'domcontentloaded' });
    const href = await page.locator('a[href*="evf-qa-cv"]').first().getAttribute('href');
    expect(href, 'The entry does not link to the uploaded file.').toBeTruthy();
    stored.push((href as string).split('/').pop() as string);
  }

  const hashOf = (name: string) => name.replace(/^evf-qa-cv-/, '').replace(/(-\d+)?\.pdf$/, '');
  expect(
    hashOf(stored[0]),
    `Two uploads of evf-qa-cv.pdf were stored as ${stored[0]} and ${stored[1]}. ` +
      'Each upload must get its own random suffix.',
  ).not.toBe(hashOf(stored[1]));
});

/**
 * Add a File Upload field to the fixture's form through the builder, and save.
 * Returns the id the builder assigned, since the front-end input is named after it.
 */
async function addFileUploadFieldForNames(page: Page, formId: string): Promise<string> {
  await page.goto(`/wp-admin/admin.php?page=evf-builder&tab=fields&form_id=${formId}`, {
    waitUntil: 'domcontentloaded',
  });

  const button = page.locator('#everest-forms-add-fields-file-upload');
  await expect(
    button,
    'The builder offers no File Upload field, so there is nothing to guard here.',
  ).toBeVisible({ timeout: 30_000 });
  await button.click();

  const field = page.locator('.everest-forms-field[data-field-type="file-upload"]').first();
  await expect(field, 'Clicking the File Upload field did not add it to the form.').toBeVisible({
    timeout: 30_000,
  });
  const fieldId = await field.getAttribute('data-field-id');
  expect(fieldId, 'The new File Upload field has no field id to submit against.').toBeTruthy();

  await page.locator('.everest-forms-save-button').first().click();
  await expect(
    page.locator('.everest-forms-save-button'),
    'Saving the form with its new File Upload field never finished.',
  ).toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1_000);

  return fieldId as string;
}

/**
 * Fill the template's own fields so the submission turns on the upload alone.
 */
async function fillRemainingFields(vp: Page, uploadFieldId: string): Promise<void> {
  const inputs = vp.locator(
    'form.everest-form input[type="text"], form.everest-form input[type="email"], ' +
      'form.everest-form input[type="url"], form.everest-form input[type="tel"], ' +
      'form.everest-form textarea',
  );

  for (let i = 0; i < (await inputs.count()); i++) {
    const field = inputs.nth(i);
    const name = (await field.getAttribute('name')) ?? '';
    if (name.includes(uploadFieldId) || name === 'everest_forms[hp]' || !(await field.isVisible())) {
      continue;
    }
    const type = await field.getAttribute('type');
    await field.fill(type === 'email' ? 'evf-qa@example.com' : 'evf-qa').catch(() => {
      /* A field the template renders read-only is not this guard's problem. */
    });
  }
}
