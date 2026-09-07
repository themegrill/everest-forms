import { test, expect } from '@playwright/test';

/**
 * Settings persistence, asserted the only way that means anything: change a
 * value, save, *reload from the database*, and read it back.
 *
 * A spec that asserts on the field right after clicking Save proves nothing —
 * the DOM still holds what you typed whether or not the option row was
 * written. The reload is the assertion.
 *
 * `everest_forms_required_validation` is the target because it is a plain text
 * option on the default Settings tab with no dependency on any other setting,
 * so a failure here means "saving settings is broken", not "this particular
 * feature is broken". It is one of ~60 `everest_forms_*` options
 * (.themegrill-qa/knowledge.md), all written through the same handler.
 *
 * The original value is captured and restored in `afterEach` rather than
 * assumed to be the shipped default: this suite runs against real sites, and a
 * spec that leaves a site's validation message reading "QA probe 1757..." is a
 * spec nobody will let near a staging environment twice.
 */

const SETTINGS = '/wp-admin/admin.php?page=evf-settings';
const FIELD = '#everest_forms_required_validation';

let original: string | null = null;

test.afterEach(async ({ page }) => {
  if (original === null) return;
  await page.goto(SETTINGS, { waitUntil: 'domcontentloaded' });
  await page.locator(FIELD).fill(original);
  await page.locator('button[name="save"]').click();
  await page.waitForLoadState('domcontentloaded');
  original = null;
});

/**
 * @area    settings
 * @tier    fresh
 * @source  knowledge-init 2026-09-07
 * @why     Every one of the plugin's ~60 options goes through this one save
 *          path. If it regresses, every settings-shaped bug report that
 *          follows is a symptom of this, and each will be investigated
 *          separately until someone checks the obvious thing.
 */
test('a changed validation message survives a save and reload @fresh @settings', async ({
  page,
}) => {
  await page.goto(SETTINGS, { waitUntil: 'domcontentloaded' });

  const field = page.locator(FIELD);
  await expect(
    field,
    'The required-validation field is not on the default Settings tab any more. ' +
      'If the tab layout changed, this spec needs a new target, not deleting.',
  ).toBeVisible();

  original = await field.inputValue();
  const probe = `QA probe ${Date.now()}`;

  await field.fill(probe);
  await page.locator('button[name="save"]').click();

  // Save posts the form and re-renders the screen; wait for that round trip
  // before navigating away, or the request races the reload below.
  await page.waitForLoadState('domcontentloaded');

  // The reload is the point: this reads the option back out of the database
  // rather than trusting the DOM we just typed into.
  await page.goto(SETTINGS, { waitUntil: 'domcontentloaded' });

  await expect(
    page.locator(FIELD),
    'The value did not survive a reload, so it never reached the ' +
      '`everest_forms_required_validation` option row. Settings saving is broken.',
  ).toHaveValue(probe);
});
