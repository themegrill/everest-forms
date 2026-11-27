import { test, expect } from '../../fixtures/published-form';
import type { Page } from '@playwright/test';

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

/**
 * @area    submission
 * @tier    fresh
 * @source  issue report — Media Library uploads kept after their entry is deleted
 * @why     With "Store file in WordPress Media Library" on, the uploaded file is
 *          a Media Library attachment that permanent entry deletion never
 *          removed, so the file stayed publicly reachable. Guards that deleting
 *          the entry removes it; does not assert where non-media uploads live or
 *          the disabled-entry-storage path.
 */
test('permanently deleting an entry removes its Media Library upload @fresh @submission', async ({
  page,
  browser,
  publishedForm,
}) => {
  test.setTimeout(150_000);
  const fieldId = await addMediaLibraryUploadField(page, publishedForm.formId);
  const stamp = `QA-MEDIA-${Date.now()}`;

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
      dz.addFile(new File(['%PDF-1.4\n% evf-qa media\n'], 'evf-qa-media.pdf', { type: 'application/pdf' }));
    });
    await expect(
      vp.locator('.dz-preview.dz-success'),
      'The file never finished uploading, so there is nothing to clean up.',
    ).toHaveCount(1, { timeout: 30_000 });

    const inputs = vp.locator(
      'form.everest-form input[type="text"]:visible, form.everest-form input[type="email"]:visible, form.everest-form textarea:visible',
    );
    for (let i = 0; i < (await inputs.count()); i++) {
      const input = inputs.nth(i);
      const name = (await input.getAttribute('name')) ?? '';
      if (name.includes(fieldId) || name === 'everest_forms[hp]') {
        continue;
      }
      const type = await input.getAttribute('type');
      await input.fill(type === 'email' ? 'evf-qa@example.com' : stamp).catch(() => {
        /* A read-only template field is not this guard's problem. */
      });
    }
    await vp.locator('.everest-forms-submit-button').first().click();
    await expect(
      vp.locator('.everest-forms-notice--success, .everest-forms-confirmation').first(),
      'The submission was rejected, so no file was stored.',
    ).toBeVisible({ timeout: 30_000 });
  } finally {
    await visitor.close();
  }

  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });
  const viewLink = await page.locator('a[href*="view-entry="]').first().getAttribute('href');
  expect(viewLink, 'The submission did not reach the entries list.').toBeTruthy();
  await page.goto(viewLink as string, { waitUntil: 'domcontentloaded' });
  const fileUrl = await page.locator('a[href*="evf-qa-media"]').first().getAttribute('href');
  expect(fileUrl, 'The entry does not link to the uploaded file.').toBeTruthy();
  expect(
    fileUrl,
    'The upload was not stored in the Media Library, so this guard would not test the Media Library path.',
  ).not.toContain('everest_forms_uploads');
  expect(
    (await page.request.get(fileUrl as string)).status(),
    'The Media Library file should exist before the entry is deleted.',
  ).toBe(200);

  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}`, {
    waitUntil: 'domcontentloaded',
  });
  const trashHref = await page.locator('a[aria-label="Trash form entry"]').first().getAttribute('href');
  expect(trashHref, 'No Trash link for the entry.').toBeTruthy();
  await page.goto(trashHref as string, { waitUntil: 'domcontentloaded' });

  await page.goto(`/wp-admin/admin.php?page=evf-entries&form_id=${publishedForm.formId}&status=trash`, {
    waitUntil: 'domcontentloaded',
  });
  const deleteHref = await page
    .locator('a[aria-label="Delete form entry permanently"]')
    .first()
    .getAttribute('href');
  expect(deleteHref, 'No Delete Permanently link for the trashed entry.').toBeTruthy();
  await page.goto(deleteHref as string, { waitUntil: 'domcontentloaded' });

  expect(
    (await page.request.get(fileUrl as string)).status(),
    `The entry was permanently deleted but its Media Library file is still served at ${fileUrl}.`,
  ).toBe(404);
});

/**
 * Add a File Upload field stored in the WordPress Media Library, and save.
 * Returns the id the builder assigned.
 */
async function addMediaLibraryUploadField(page: Page, formId: string): Promise<string> {
  await page.goto(`/wp-admin/admin.php?page=evf-builder&tab=fields&form_id=${formId}`, {
    waitUntil: 'domcontentloaded',
  });

  const button = page.locator('#everest-forms-add-fields-file-upload');
  await expect(button, 'The builder offers no File Upload field.').toBeVisible({ timeout: 30_000 });
  await button.click();

  const field = page.locator('.everest-forms-field[data-field-type="file-upload"]').first();
  await expect(field, 'Clicking the File Upload field did not add it to the form.').toBeVisible({
    timeout: 30_000,
  });
  const fieldId = (await field.getAttribute('data-field-id')) as string;
  expect(fieldId, 'The new File Upload field has no field id.').toBeTruthy();

  await field.click();
  const mediaToggle = page.locator(`input[name="form_fields[${fieldId}][media_library]"][type="checkbox"]`);
  await expect(mediaToggle, 'The File Upload field has no Media Library option.').toBeAttached({
    timeout: 30_000,
  });
  // The option sits in a collapsed panel; set it the way the panel's own toggle does.
  await mediaToggle.evaluate((el) => {
    (el as HTMLInputElement).checked = true;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  });

  await page.locator('.everest-forms-save-button').first().click();
  await expect(
    page.locator('.everest-forms-save-button'),
    'Saving the form never finished.',
  ).toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1_000);

  return fieldId;
}
