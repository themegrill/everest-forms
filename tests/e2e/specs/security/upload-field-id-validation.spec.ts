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
 * @source  security report — anonymous uploads publicly served from the tmp folder
 * @why     A visitor could upload a file without submitting the form and the
 *          server stored it at a public URL built from the name it returned, so
 *          bots used it to host indexed spam PDFs. Guards that the returned name
 *          does not resolve to a public tmp URL; does not assert where submitted
 *          files end up or that the upload itself is refused.
 */
test('anonymous upload is not served from a guessable tmp URL @fresh @security', async ({
  page,
  browser,
  publishedForm,
}) => {
  const fieldId = await addUploadFieldToForm(page, publishedForm.formId);

  const visitor = await browser.newContext();
  try {
    const origin = new URL(publishedForm.url).origin;
    const upload = await visitor.request.post(`${origin}/wp-admin/admin-ajax.php`, {
      multipart: {
        action: 'everest_forms_upload_file',
        form_id: publishedForm.formId,
        field_id: fieldId,
        file: {
          name: 'evf-qa.pdf',
          mimeType: 'application/pdf',
          buffer: Buffer.from('%PDF-1.4\n% evf-qa tmp exposure check\n'),
        },
      },
    });
    const body = await upload.json().catch(() => null);
    expect(
      body?.success,
      `The anonymous upload was refused (${JSON.stringify(body)}), so the tmp URL check proves nothing.`,
    ).toBe(true);

    const tmpUrl = `${origin}/wp-content/uploads/everest_forms_uploads/tmp/${body.data.file}`;
    const served = await visitor.request.get(tmpUrl);
    expect(
      served.status(),
      `An anonymous, unsubmitted upload is publicly served at ${tmpUrl}. ` +
        'This is the tmp-folder exposure used to host indexed spam files.',
    ).not.toBe(200);
  } finally {
    await visitor.close();
  }
});

/**
 * Add a File Upload field to the fixture's form through the builder, and save.
 * Returns the id the builder assigned, since uploads are addressed by it.
 */
async function addUploadFieldToForm(page: Page, formId: string): Promise<string> {
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
  expect(fieldId, 'The new File Upload field has no field id to upload against.').toBeTruthy();

  await page.locator('.everest-forms-save-button').first().click();
  await expect(
    page.locator('.everest-forms-save-button'),
    'Saving the form with its new File Upload field never finished.',
  ).toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1_000);

  return fieldId as string;
}
