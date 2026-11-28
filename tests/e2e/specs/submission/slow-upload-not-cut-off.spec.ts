import { test, expect } from '../../fixtures/published-form';
import type { Page } from '@playwright/test';

/**
 * Regression guard for file uploads stalling partway on slow connections.
 *
 * The upload field gave Dropzone a fixed 30-second request timeout. An upload
 * still sending after 30 seconds (a large video on a phone connection) was cut
 * off, and because Dropzone 5.5 has no timeout handler the visitor saw the
 * progress bar freeze with no error. Uploads now have no client-side timeout by
 * default.
 *
 * Does not assert what happens when the connection drops completely, or the
 * `evf_fileupload_max_timeout` filter.
 */

// The upload is throttled to take longer than the old 30-second cutoff.
test.setTimeout(150_000);

/**
 * @area    submission
 * @tier    fresh
 * @source  everest-forms-pro#1269 — file uploads stall partway through larger video uploads
 * @why     Uploads taking longer than 30 seconds never completed. Runs ~45s on
 *          purpose: the regression only shows once an upload outlasts the old
 *          30-second timeout.
 */
test('a slow upload that takes longer than 30 seconds still completes @fresh @submission', async ({
  page,
  browser,
  publishedForm,
}) => {
  await addFileUploadField(page, publishedForm.formId);

  const visitor = await browser.newContext();
  try {
    const vp = await visitor.newPage();
    await vp.goto(publishedForm.url, { waitUntil: 'domcontentloaded' });
    await vp.waitForFunction(
      () => ((window as unknown as { Dropzone?: { instances?: unknown[] } }).Dropzone?.instances ?? []).length > 0,
      null,
      { timeout: 30_000 },
    );

    // About 1.2 MB at 30 KB/s: roughly 40 seconds of upload.
    const cdp = await visitor.newCDPSession(vp);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false,
      latency: 40,
      downloadThroughput: 5 * 1024 * 1024,
      uploadThroughput: 30 * 1024,
    });

    const started = Date.now();
    await vp.evaluate(() => {
      const bytes = new Uint8Array(1_200_000);
      bytes.set(new TextEncoder().encode('%PDF-1.4\n% evf-qa slow upload\n'));
      const dz = (window as unknown as { Dropzone: { instances: { addFile: (f: File) => void }[] } }).Dropzone
        .instances[0];
      dz.addFile(new File([bytes], 'evf-qa-slow.pdf', { type: 'application/pdf' }));
    });

    const status = await vp
      .waitForFunction(
        () => {
          const dz = (window as unknown as { Dropzone: { instances: { files: { status: string }[] }[] } }).Dropzone
            .instances[0];
          const file = dz.files[0];
          return file && ['success', 'error'].includes(file.status) ? file.status : null;
        },
        null,
        { timeout: 120_000, polling: 1_000 },
      )
      .then((handle) => handle.jsonValue())
      .catch(() => 'still uploading');

    const seconds = Math.round((Date.now() - started) / 1000);
    expect(
      seconds,
      'The throttled upload finished too quickly to outlast the old 30-second timeout, so the guard proves nothing.',
    ).toBeGreaterThan(30);
    expect(
      status,
      `An upload that took ${seconds}s ended as "${status}". Uploads still sending after 30 seconds must not be cut off.`,
    ).toBe('success');
  } finally {
    await visitor.close();
  }
});

/**
 * Add a File Upload field to the form in the builder and save it.
 */
async function addFileUploadField(page: Page, formId: string): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=evf-builder&tab=fields&form_id=${formId}`, {
    waitUntil: 'domcontentloaded',
  });

  const button = page.locator('#everest-forms-add-fields-file-upload');
  await expect(button, 'The builder offers no File Upload field.').toBeVisible({ timeout: 30_000 });
  await button.click();

  await expect(
    page.locator('.everest-forms-field[data-field-type="file-upload"]').first(),
    'Clicking the File Upload field did not add it to the form.',
  ).toBeVisible({ timeout: 30_000 });

  await page.locator('.everest-forms-save-button').first().click();
  await expect(
    page.locator('.everest-forms-save-button'),
    'Saving the form with its new File Upload field never finished.',
  ).toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1_000);
}
