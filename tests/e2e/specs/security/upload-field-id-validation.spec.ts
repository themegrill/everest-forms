import { test, expect } from '../../fixtures/published-form';

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
