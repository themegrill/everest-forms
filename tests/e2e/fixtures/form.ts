import { test as base, expect, Page } from '@playwright/test';

/**
 * A form to run builder specs against, created per spec file and trashed after.
 *
 * Builder specs need *a* form; none of them care which. Sharing a fixture
 * beats each spec creating its own (slow) or all of them pointing at whatever
 * form happens to be on the site (a suite that passes locally and fails on a
 * clean install, which is the failure mode that wastes the most time).
 *
 * Created through the template picker rather than by writing a post directly,
 * because a form built any other way is not the thing users have — its
 * `form_fields` would be whatever this fixture invented, and a field-settings
 * assertion against invented data proves nothing about the product.
 */

export type FormFixture = {
  /** Post id of the form created for this spec file. */
  id: string;
  /** Builder URL for a given tab of that form. */
  builderUrl: (tab: 'fields' | 'settings' | 'style' | 'integrations') => string;
};

export const test = base.extend<{ form: FormFixture }>({
  form: async ({ page }, use) => {
    const id = await createFormFromFirstFreeTemplate(page);

    await use({
      id,
      builderUrl: (tab) => `/wp-admin/admin.php?page=evf-builder&tab=${tab}&form_id=${id}`,
    });

    await trashForm(page, id);
  },
});

export { expect };

async function createFormFromFirstFreeTemplate(page: Page): Promise<string> {
  await page.goto('/wp-admin/admin.php?page=evf-builder&create-form=1', {
    waitUntil: 'domcontentloaded',
  });

  // The filters are Chakra tabs — <button role="tab"> — so getByRole('button')
  // does not match them.
  await page.getByRole('tab', { name: 'Free', exact: true }).click();

  const firstTemplate = page.locator('.template-title').first();
  await expect(
    firstTemplate,
    'No free templates to build a fixture form from. Every builder spec depends on ' +
      'this, so they will all fail together — fix the template list first.',
  ).toBeVisible({ timeout: 30_000 });

  // The button lives in a hover overlay, and every card carries its own copy,
  // so it has to be hovered and then taken by index rather than by name.
  await firstTemplate.hover();
  await page.getByRole('button', { name: /Use this template/i }).first().click();

  // The confirmation modal is a Chakra portal: no role="dialog", no accessible
  // name, so it is targeted by its container class.
  const modal = page.locator('.chakra-modal__content-container');
  await modal.getByRole('button', { name: /Use this template/i }).click();

  await page.waitForURL(/[?&]form_id=\d+/, { timeout: 45_000 });

  const id = new URL(page.url()).searchParams.get('form_id');
  expect(id, 'The builder opened without a form_id, so no form was actually created.').toBeTruthy();
  return id as string;
}

async function trashForm(page: Page, id: string): Promise<void> {
  try {
    // The builder guards navigation with an unsaved-changes confirm.
    page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
    await page.goto('/wp-admin/admin.php?page=evf-builder', { waitUntil: 'domcontentloaded' });

    // Read the Trash href and navigate to it rather than clicking: WP
    // list-table row actions stay off-viewport until the row is hovered, and a
    // click would retry until the fixture times out. The href carries the nonce.
    const trash = page.locator(`a.submitdelete[href*="post=${id}"]`).first();
    if ((await trash.count()) > 0) {
      const href = await trash.getAttribute('href');
      if (href) await page.goto(href, { waitUntil: 'domcontentloaded' });
    }
  } catch {
    // Never fail a run over cleanup.
  }
}
