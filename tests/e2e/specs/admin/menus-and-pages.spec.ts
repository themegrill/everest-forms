import { test, expect } from '@playwright/test';

/**
 * Admin surfaces that changed after 3.4.8, grouped because each is a single
 * cheap assertion and none needs a form.
 *
 *   3.5.3  "Renamed Payment admin menu to Payments."
 *   3.5.3  "Show active addons list in System Info."
 *   3.5.0  "Missing the export and print in the free version of analytics page."
 *
 * All three are the kind of change that silently reverts in a refactor and
 * that nobody notices until a customer mentions it.
 */

/**
 * @area    activation
 * @tier    fresh
 * @source  changelog 3.5.3 — "Renamed Payment admin menu to Payments"
 * @why     A menu label is trivial to reintroduce wrongly and impossible to
 *          spot in a diff. Also pins the destination, because renaming the
 *          label while leaving a dead link would satisfy a text-only check.
 */
test('the Payments menu is named "Payments" and resolves @fresh @activation', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=evf-builder', { waitUntil: 'domcontentloaded' });

  const payments = page.locator('#toplevel_page_everest-forms .wp-submenu li a', {
    hasText: /^Payments$/,
  });

  await expect(
    payments,
    'No submenu item labelled exactly "Payments". Before 3.5.3 this read "Payment"; ' +
      'a regression here means the rename was undone.',
  ).toHaveCount(1);

  const href = await payments.getAttribute('href');
  expect(
    href,
    `The Payments menu item points at ${href}, which is not the payment log screen. ` +
      'A renamed label on a dead link is worse than the old name.',
  ).toContain('page=evf-payment-log');
});

/**
 * @area    activation
 * @tier    fresh
 * @source  changelog 3.5.3 — "Show active addons list in System Info"
 * @why     System Info exists to be pasted into a support ticket. The addons
 *          list was added because its absence made half those tickets need a
 *          follow-up question. If it silently drops out, support regresses
 *          without a single test failing anywhere else.
 */
test('System Info reports the addons list @fresh @activation', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=evf-tools&tab=system_info', {
    waitUntil: 'domcontentloaded',
  });

  await expect(
    page.getByText(/System Info/i).first(),
    'The System Info tab did not render. Note the slug is `system_info` — an unknown ' +
      'tab value renders a PHP warning instead (see tests/e2e/README.md).',
  ).toBeVisible({ timeout: 20_000 });

  await expect(
    page.getByRole('cell', { name: /^Addons$/i }).or(page.locator('th', { hasText: /^Addons$/i })).first(),
    'System Info has no Addons row. Added in 3.5.3 so support can see what is active ' +
      'without asking.',
  ).toBeVisible({ timeout: 20_000 });
});

/**
 * @area    entries
 * @tier    fresh
 * @source  changelog 3.5.0 — "Missing the export and print in the free version
 *          of analytics page"
 * @why     These two controls were absent from the free build specifically, so
 *          the regression to guard against is them drifting back behind a Pro
 *          gate. A test that ran only on Pro would never see it.
 */
test('the free Analytics page offers Export and Print @fresh @entries', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=evf-analytics', { waitUntil: 'domcontentloaded' });

  await expect(
    page.getByRole('button', { name: /^Export$/i }).first(),
    'No Export control on the Analytics page. It was added to the free build in 3.5.0; ' +
      'if it has gone, it has most likely drifted back behind a Pro gate.',
  ).toBeVisible({ timeout: 30_000 });

  await expect(
    page.getByRole('button', { name: /^Print$/i }).first(),
    'No Print control on the Analytics page — same 3.5.0 change as Export.',
  ).toBeVisible({ timeout: 30_000 });
});
