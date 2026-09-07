import { test, expect } from '../../fixtures/form';

/**
 * Regression guard for the 3.5.3 fix:
 *
 *   "Field settings tabs sluggish and required double-click to switch."
 *
 * This is a hard bug to guard against, because the broken behaviour was not an
 * error — the group *did* open, just on the second click. Any assertion that
 * retries, or that clicks and then waits generously, passes against the bug as
 * happily as against the fix.
 *
 * So the shape here matters: exactly one click, then assert the state changed.
 * Playwright's auto-waiting applies to the assertion, not to the click, so a
 * build that needs a second click leaves the group `closed` and fails — which
 * is the only way this spec is worth having.
 *
 * State is read from the group's own classes (`open` / `closed`), which the
 * builder toggles directly, rather than from visibility: the panel animates,
 * and a visibility check would be a race against a CSS transition rather than
 * a statement about the product.
 *
 * Note the sidebar step. Selecting a field on the canvas does NOT reveal its
 * settings — the whole `.everest-forms-field-options` panel stays hidden until
 * the sidebar's "Field Options" tab is chosen. Every option group is in the
 * DOM the entire time, for every field on the canvas, at zero height. That is
 * why the groups are scoped to the visible options wrapper below: an unscoped
 * lookup finds four fields' worth of identical groups and clicks one nobody
 * can see.
 */

/**
 * @area    forms
 * @tier    fresh
 * @source  changelog 3.5.3 — "Field settings tabs sluggish and required
 *          double-click to switch"
 * @why     Every field setting past the basics lives behind these groups. A
 *          toggle that ignores the first click makes the builder feel broken
 *          on the most-used screen in the product, and it reached a release
 *          once already.
 */
test('a single click opens the Advanced field options group @fresh @forms', async ({
  page,
  form,
}) => {
  await page.goto(form.builderUrl('fields'), { waitUntil: 'domcontentloaded' });

  const field = page.locator('.everest-forms-field').first();
  await expect(
    field,
    'The fixture form has no fields on the canvas, so there are no option groups to open.',
  ).toBeVisible({ timeout: 30_000 });
  await field.click();

  // Reveal the settings panel. Without this the groups exist but are 0x0.
  await page.locator('.everest-forms-tabs a.options, a.options').first().click();

  // Scoped to the options wrapper that is actually on screen: every field on
  // the canvas keeps its own copy of these groups in the DOM at all times.
  // `:not(.evf-cl-upsell-group)` excludes the Conditional Logic group, which
  // shares the `-advanced` class but is a Pro upsell that does not toggle.
  const advancedGroup = page
    .locator('.everest-forms-field-option:visible')
    .locator('.everest-forms-field-option-group-advanced:not(.evf-cl-upsell-group)')
    .first();

  await expect(
    advancedGroup,
    'No Advanced options group for the selected field. If the settings panel did not ' +
      'open, the "Field Options" sidebar tab is the thing that reveals it.',
  ).toBeAttached({ timeout: 15_000 });

  await expect(
    advancedGroup,
    'The Advanced group is already open, so this spec cannot prove a single click ' +
      'opens it. If the default state changed, target a group that starts closed.',
  ).toHaveClass(/closed/);

  // Exactly one click. Not `click()` twice, not a retry loop — the bug being
  // guarded is precisely "the first click does nothing".
  await advancedGroup.locator('.everest-forms-field-option-group-toggle').first().click();

  await expect(
    advancedGroup,
    'The Advanced group did not open on the first click. This is the 3.5.3 bug back: ' +
      'the toggle needs a second click to register.',
  ).toHaveClass(/open/, { timeout: 10_000 });
});
