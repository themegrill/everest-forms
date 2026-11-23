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
 * @source  security audit — upload field file-size lookup used wp_remote_head()
 * @why     A visitor's submitted upload value is re-rendered as a file preview,
 *          and the preview's size was read with an unrestricted HEAD request to
 *          that URL, so an anonymous POST made the server request an internal
 *          address and print the result. Guards that internal addresses are
 *          refused (size 0) while the site's own files still report a size; does
 *          not assert the preview's layout or the upload flow itself.
 */
test('upload preview does not fetch internal addresses for a visitor @fresh @security', async ({
  page,
  browser,
  publishedForm,
}) => {
  const fieldId = await addFileUploadField(page, publishedForm.formId);
  const fieldName = `everest_forms[form_fields][${fieldId}]`;

  const site = new URL(publishedForm.url);
  const internal = new URL(site.origin);
  internal.hostname = site.hostname === 'localhost' ? '127.0.0.1' : 'localhost';
  const filePath = '/wp-includes/images/media/text.png';

  const visitor = await browser.newContext();
  try {
    const previewSize = async (fileUrl: string) => {
      const response = await visitor.request.post(publishedForm.url, {
        form: { 'everest_forms[id]': publishedForm.formId, [fieldName]: fileUrl },
      });
      const match = (await response.text()).match(/data-dz-size[^>]*>\s*<strong>([^<]*)</);
      return match ? match[1].trim() : null;
    };

    const ownSize = await previewSize(`${site.origin}${filePath}`);
    expect(
      ownSize,
      'The form did not re-render the submitted file as a preview, so the guard proves nothing.',
    ).not.toBeNull();
    expect(
      ownSize,
      "A file on the site's own host no longer reports a size, so the lookup itself is broken.",
    ).not.toMatch(/^0(\.0)? B$/);

    const internalSize = await previewSize(`${internal.origin}${filePath}`);
    expect(
      internalSize,
      'The preview for the internal address was not rendered, so the guard proves nothing.',
    ).not.toBeNull();
    expect(
      internalSize,
      `The upload preview reported a size (${internalSize}) for ${internal.origin}, so the server ` +
        'made a HEAD request to an internal address on behalf of an anonymous visitor.',
    ).toMatch(/^0(\.0)? B$/);
  } finally {
    await visitor.close();
  }
});

/**
 * Add a File Upload field to the fixture's form through the builder, and save.
 * Returns the id the builder assigned, since the front-end input is named after it.
 */
async function addFileUploadField(page: Page, formId: string): Promise<string> {
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
