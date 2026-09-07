import { test, expect } from '../../fixtures/form';

/**
 * The headline change of 3.6.0: "Revamped Style Customizer."
 *
 * This is the highest-risk code in the release by a wide margin. Across the
 * last 400 commits the V2 bundle and its source account for ~150 modify
 * commits, and the fix log reads as one repeated theme — properties that were
 * styled in v1 and silently stopped being styled in v2 (EVF-2732, EVF-2733,
 * EVF-2746, and `a9e2c0723`, which names itself "same bug class as EVF-2733").
 * See .themegrill-qa/knowledge.md.
 *
 * What that history implies for a spec: the panel *rendering* is not the risk.
 * The risk is a control existing and doing nothing. So this asserts the
 * structure that later property-level specs will hang off — the pre-defined
 * Template and Colors controls, all six element groups, and a live preview
 * that actually contains the form — and stops there rather than pretending to
 * cover styling.
 *
 * This is the structural half of the coverage. The behavioural half — change a
 * property, assert the computed style on the rendered form — lives in
 * `color-preset-reaches-front-end.spec.ts`, which is the one that actually
 * guards the v1→v2 bug class.
 *
 * ## What "the element groups are present" does and does not mean
 *
 * On a **free** install the six element groups are Pro upsells, not working
 * controls: opening one shows "Button styling is a Pro feature". Asserting
 * they render is still worth doing — they are the panel's structure and the
 * upsell surface, and a mount failure removes them — but it is emphatically
 * not a claim that they style anything here. Only the Template and Colors
 * presets are usable without Pro, and of the colour presets only Classic and
 * Monochrome carry no Pro badge.
 */

const ELEMENT_GROUPS = ['Form', 'Text', 'Inputs', 'Choices', 'Button', 'Messages'];

/**
 * @area    style-customizer
 * @tier    fresh
 * @source  changelog 3.6.0 — "Revamped Style Customizer"
 * @why     A JS bundle that throws on mount leaves a 200 response, an intact
 *          admin page and an empty panel. Nothing else in the suite would
 *          notice, and the styling feature would simply be gone.
 */
test('the Style panel mounts with its controls and a live preview @fresh @style-customizer', async ({
  page,
  form,
}) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  await page.goto(form.builderUrl('fields'), { waitUntil: 'domcontentloaded' });

  // Entered by clicking the builder's own Style tab rather than by navigating
  // to `&tab=style` directly: the tab is how users get here, and a broken
  // handler on it would be invisible to a direct navigation.
  const styleTab = page.locator('a.evf-panel-style-button');
  await expect(styleTab, 'The builder has no Style tab.').toBeVisible({ timeout: 30_000 });
  await styleTab.click();

  await expect(page, 'Clicking Style did not switch the builder panel.').toHaveURL(
    /[?&]tab=style/,
    { timeout: 20_000 },
  );

  // The two pre-defined controls at the top of the panel. Present in v1 too,
  // which is the point — they are the contract the revamp had to keep.
  //
  // Matched case-insensitively against `.predef-kicker`: these labels render as
  // TEMPLATE and COLORS but only because of `text-transform: uppercase`. The
  // DOM text is "Template" and "Colors", so an exact-text match on the
  // rendered casing finds nothing.
  const kickers = page.locator('.predef-kicker');

  await expect(
    kickers.filter({ hasText: /^template$/i }).first(),
    'No Template control in the Style panel — the V2 app did not mount, or mounted empty.',
  ).toBeVisible({ timeout: 30_000 });

  await expect(
    kickers.filter({ hasText: /^colors$/i }).first(),
    'No Colors control in the Style panel.',
  ).toBeVisible({ timeout: 20_000 });

  // Every element group, by name. On free these are locked rows rather than
  // working controls (see the note above), so this asserts the panel's
  // structure and its upsell surface — not that any of them style anything.
  for (const group of ELEMENT_GROUPS) {
    await expect(
      page.getByRole('button', { name: new RegExp(`^${group}`) }).first(),
      `The "${group}" element group row is missing from the Style panel. On a free ` +
        'install that is the upsell for a whole category of styling, and its absence ' +
        'means the panel mounted incompletely.',
    ).toBeVisible({ timeout: 20_000 });
  }

  // The preview is the feedback loop the whole panel exists for; an empty one
  // makes every control unverifiable by the user.
  await expect(
    page.getByText(/Live preview/i).first(),
    'No live preview pane in the Style panel.',
  ).toBeVisible({ timeout: 20_000 });

  const previewFrame = page.frameLocator('iframe').first();
  await expect(
    previewFrame.locator('form, .everest-forms').first(),
    'The live preview rendered no form. The panel is intact but styling cannot be ' +
      'judged against anything, which makes the feature unusable rather than broken.',
  ).toBeVisible({ timeout: 30_000 });

  // Reported rather than asserted-on-empty: WordPress admin is noisy with
  // third-party 404s, and failing on any console error would make this spec
  // hostage to whatever else is installed. A bundle that throws shows up in
  // the assertions above.
  const bundleErrors = consoleErrors.filter((text) => /style|customizer/i.test(text));
  expect(
    bundleErrors,
    `The Style Customizer bundle logged errors: ${bundleErrors.join(' | ')}`,
  ).toHaveLength(0);
});
