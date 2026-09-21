import { test, expect } from '../../fixtures/form';

/**
 * The free plugin must not offer controls that only Pro can deliver.
 *
 * Two changes since 3.4.8 landed here, and both are *negative* behaviour — the
 * product is correct when something is absent. Nothing else in the suite would
 * notice them regressing, because a stray control neither errors nor fails any
 * positive assertion; it just wastes a user's time and then does nothing.
 *
 *   3.5.3  "Hide repeater 'Add Repeater Row' builder control when Pro is inactive."
 *   3.5.0  "Showcase conditional logic as a pro feature in field settings panel."
 *
 * The repeater gate is a hard one: both `EVF_REPEATER_FIELDS_VERSION` and
 * `EFP_VERSION` must be defined for the button or its wrapper to be printed at
 * all (includes/admin/builder/class-evf-builder-fields.php:446). So on a free
 * install the correct count is exactly zero, and "the button is hidden with
 * CSS" would be a regression this spec catches — `.count()` is used rather
 * than a visibility check on purpose.
 *
 * These assertions are only meaningful while Pro is inactive. If someone runs
 * this suite on a site with Everest Forms Pro active, the repeater test SHOULD
 * fail — it is asserting the free-install contract, and that is a different
 * site, not a broken product.
 */

test.describe('builder controls gated behind Pro', () => {
  /**
   * @area    forms
   * @tier    fresh
   * @source  changelog 3.5.3 — "Hide repeater 'Add Repeater Row' builder
   *          control when Pro is inactive"
   * @why     Before the fix, free users got an "Add Repeater Row" button for a
   *          field type the free plugin never registers. Clicking it produced
   *          a row that could not work. A control that lies about what the
   *          product can do is worse than a missing feature.
   */
  test('no "Add Repeater Row" control on a free install @fresh @forms', async ({ page, form }) => {
    await page.goto(form.builderUrl('fields'), { waitUntil: 'domcontentloaded' });

    // "Add Row" is unconditional — asserting it first proves the row controls
    // rendered at all, so a zero count below means "absent", not "never got here".
    await expect(
      page.locator('.evf-add-row:not(.repeater-row)'),
      'The ordinary "Add Row" control is missing, so this spec cannot tell whether the ' +
        'repeater control is correctly hidden or the whole builder failed to render.',
    ).toHaveCount(1);

    // Not `toBeHidden()`: the fix is that the markup is never printed. Hiding
    // it with CSS would still ship a control Pro-less users can reach.
    await expect(
      page.locator('.evf-add-row.repeater-row'),
      'An "Add Repeater Row" control was rendered without Pro. This is the 3.5.3 bug ' +
        'back: the free plugin does not register the repeater field, so the row it ' +
        'creates cannot work.',
    ).toHaveCount(0);

    await expect(
      page.locator('.evf-repeater-row-wrapper'),
      'The repeater row wrapper was printed without Pro. Both EVF_REPEATER_FIELDS_VERSION ' +
        'and EFP_VERSION must be defined before any of this markup exists.',
    ).toHaveCount(0);
  });

  /**
   * @area    forms
   * @tier    fresh
   * @source  changelog 3.5.0 — "Showcase conditional logic as a pro feature in
   *          field settings panel"
   * @why     Conditional logic is Pro-only, but free users were given no signal
   *          that it exists. The upsell group is the deliberate replacement —
   *          if it stops rendering, the feature becomes invisible again and the
   *          change is silently undone.
   */
  test('conditional logic appears as a Pro upsell in field settings @fresh @forms', async ({
    page,
    form,
  }) => {
    await page.goto(form.builderUrl('fields'), { waitUntil: 'domcontentloaded' });

    const field = page.locator('.everest-forms-field').first();
    await expect(
      field,
      'The fixture form has no fields on the canvas, so there are no field settings to open.',
    ).toBeVisible({ timeout: 30_000 });
    await field.click();

    await expect(
      page.locator('.evf-cl-upsell-group').first(),
      'No conditional-logic upsell group in the field settings panel. Free users now ' +
        'have no indication the feature exists at all.',
    ).toBeAttached({ timeout: 15_000 });
  });
});
