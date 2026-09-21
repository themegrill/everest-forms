import { test as formTest, expect } from './form';
import type { Page } from '@playwright/test';

/**
 * A form embedded in a published page, so specs can meet it the way a visitor
 * does.
 *
 * The obvious shortcut is Everest Forms' own preview URL
 * (`/?form_id=<id>&evf_preview=true`). It renders a real, submittable form and
 * needs no page at all — but it is **admin-gated**: an anonymous request gets
 * the theme's page with no form in it. Testing submission through the preview
 * would therefore prove that an administrator can submit a form, which is not
 * the thing the product promises and not the path any real submission takes.
 *
 * So the page is created for real, through the REST API with the admin's own
 * nonce, and torn down afterwards. `force=true` on delete because a page left
 * in the trash still owns its slug, and the next run would silently get
 * `qa-form-host-2` while asserting against `qa-form-host`.
 */

export type PublishedForm = {
  /** Post id of the form. */
  formId: string;
  /** Post id of the page hosting it. */
  pageId: number;
  /** Public permalink of that page. */
  url: string;
};

export const test = formTest.extend<{ publishedForm: PublishedForm }>({
  publishedForm: async ({ page, form }, use) => {
    const nonce = await restNonce(page);

    const response = await page.request.post('/wp-json/wp/v2/pages', {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: {
        title: `QA form host ${form.id}`,
        status: 'publish',
        content: `[everest_form id="${form.id}"]`,
      },
    });

    expect(
      response.status(),
      'Could not publish a page to host the form. Without it there is no way to reach ' +
        'the form as a visitor, since the plugin preview URL is admin-only.',
    ).toBe(201);

    const created = await response.json();

    await use({ formId: form.id, pageId: created.id, url: created.link });

    await page.request
      .delete(`/wp-json/wp/v2/pages/${created.id}?force=true`, { headers: { 'X-WP-Nonce': nonce } })
      .catch(() => {
        /* Never fail a run over cleanup. */
      });
  },
});

export { expect };

/**
 * A REST nonce for the logged-in admin.
 *
 * Read off a wp-admin screen rather than minted, because cookie-authenticated
 * REST requests are rejected without the nonce WordPress itself issued for
 * this session.
 */
async function restNonce(page: Page): Promise<string> {
  await page.goto('/wp-admin/post-new.php?post_type=page', { waitUntil: 'domcontentloaded' });

  const nonce = await page.waitForFunction(
    () => (window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce,
    undefined,
    { timeout: 30_000 },
  );

  const value = (await nonce.jsonValue()) as string;
  expect(value, 'No REST nonce on the new-page screen; cannot create the host page.').toBeTruthy();
  return value;
}
