import { test, expect } from '@playwright/test';

/**
 * Critical flow #1, first half: a user picks a template and gets a real,
 * saved form.
 *
 * This is the entry point to everything else the plugin does — no form, no
 * entries, no notifications, no styling. It is also React-rendered through a
 * REST controller, which is exactly the shape that breaks silently: the PHP
 * stays green, the bundle throws, and the screen renders an empty grid that a
 * status-code check calls healthy.
 *
 * The flow is deliberately asserted in two steps, because they fail for
 * different reasons and a combined assertion would not say which:
 *
 *   1. the template grid populates      → the templates REST route answered
 *   2. picking one lands in the builder → form creation actually wrote a post
 *
 * The Free/All/Premium filters are Chakra tabs — <button role="tab">. An
 * explicit ARIA role wins over the tag, so getByRole('button') does NOT match
 * them; that mismatch is what this spec failed on first.
 *
 * Note the two clicks, and that neither is on the card itself. Each template
 * card carries a "Use this template" button in a hover overlay; clicking that
 * opens a confirmation modal ("<name> / Ready to use") whose own
 * "Use this template" button is what actually creates the form. Clicking the
 * card's title does nothing at all.
 *
 * All of which means the obvious selector is wrong twice over: every card has
 * a copy of that button (five matches, strict-mode failure), and the modal is
 * a Chakra portal with no role="dialog" and no accessible name, so
 * getByRole('dialog') finds nothing. Both are scoped explicitly below.
 * Observed on 3.6.0 — if a future version drops the modal, this becomes a
 * single click and the spec should be simplified, not force-clicked into
 * submission.
 */

const CREATE_FORM = '/wp-admin/admin.php?page=evf-builder&create-form=1';
const FORM_LIST = '/wp-admin/admin.php?page=evf-builder';

/**
 * Every form this spec creates, trashed again in `afterEach`.
 *
 * Without this the spec leaves a "Simple Contact Form" behind on every run,
 * which is how a QA site ends up with forty of them and how the next spec that
 * asserts on a form count starts failing for no reason anyone can find.
 *
 * Cleanup is best-effort by design: a failure to tidy up must not turn a
 * passing assertion into a red test, and must not mask the real failure when
 * the assertion did fail.
 */
let createdFormId: string | null = null;

test.afterEach(async ({ page }) => {
  if (!createdFormId) return;
  const id = createdFormId;
  createdFormId = null;

  try {
    // The builder guards navigation with an unsaved-changes confirm. Left
    // unhandled it blocks the goto below until the hook times out, which
    // reports as a failure of the test that just passed.
    page.on('dialog', (dialog) => dialog.accept().catch(() => {}));

    await page.goto(FORM_LIST, { waitUntil: 'domcontentloaded' });

    // Read the row's own Trash href and navigate to it, rather than clicking
    // it. WP list-table row actions are visually hidden until the row is
    // hovered, so a click retries against an off-viewport element until the
    // hook times out — which then reports as a failure of the test that just
    // passed. The href is what matters here anyway: it carries the nonce.
    const trash = page.locator(`a.submitdelete[href*="post=${id}"]`).first();
    if ((await trash.count()) > 0) {
      const href = await trash.getAttribute('href');
      if (href) await page.goto(href, { waitUntil: 'domcontentloaded' });
    }
  } catch {
    // Left behind rather than failing the run; see the note above.
  }
});

/**
 * @area    forms
 * @tier    fresh
 * @source  knowledge-init 2026-09-07
 * @why     An empty template grid is the failure mode a smoke test misses: the
 *          page is 200, the app mounted, and there is simply nothing to pick.
 *          Asserts on the Free filter specifically so the free plugin's suite
 *          never depends on premium templates being fetchable.
 */
test('the template picker offers free templates @fresh @forms', async ({ page }) => {
  await page.goto(CREATE_FORM, { waitUntil: 'domcontentloaded' });

  await expect(
    page.getByRole('heading', { name: /Choose from Templates/i }),
    'The Add New Form screen did not mount. If the bundle threw, the console in ' +
      'the trace will say so.',
  ).toBeVisible({ timeout: 30_000 });

  await page.getByRole('tab', { name: 'Free', exact: true }).click();

  const titles = page.locator('.template-title');
  await expect(
    titles.first(),
    'No free templates rendered. The templates REST route ' +
      '(includes/RestApi/controllers/version1/class-evf-templates.php) returned ' +
      'nothing usable, or the grid failed to render what it returned.',
  ).toBeVisible({ timeout: 30_000 });

  expect(
    await titles.count(),
    'The Free filter produced an empty grid — a user cannot create a form at all.',
  ).toBeGreaterThan(0);
});

/**
 * @area    forms
 * @tier    fresh
 * @source  knowledge-init 2026-09-07
 * @why     This is the single flow that gates the product. It is also the one
 *          most exposed to the AI-assistant work that produced four fix
 *          commits under EVF-2736, all of them about controls on this screen
 *          overlapping or hiding each other.
 */
test('picking a template creates a form and opens the builder @fresh @forms', async ({ page }) => {
  await page.goto(CREATE_FORM, { waitUntil: 'domcontentloaded' });
  await page.getByRole('tab', { name: 'Free', exact: true }).click();

  const firstTemplate = page.locator('.template-title').first();
  await expect(firstTemplate).toBeVisible({ timeout: 30_000 });
  const templateName = (await firstTemplate.textContent())?.trim() ?? '(unnamed)';

  // The button lives in a hover overlay, so the card has to be hovered before
  // its own button is the one under the cursor.
  await firstTemplate.hover();
  const cardButton = page.getByRole('button', { name: /Use this template/i }).first();
  await cardButton.click();

  // Scoped to the modal container by class, because this modal is a Chakra
  // portal with no dialog role to target and no heading of its own.
  const modal = page.locator('.chakra-modal__content-container');
  await expect(
    modal.getByText(/Ready to use/i),
    `Clicking "${templateName}" did not open the confirmation modal.`,
  ).toBeVisible({ timeout: 15_000 });

  const confirm = modal.getByRole('button', { name: /Use this template/i });
  await confirm.click();

  // Form creation round-trips through admin-ajax and then redirects into the
  // builder with the new form's id in the URL. That id is the proof a post was
  // actually written — the builder chrome renders either way.
  await expect(
    page,
    `Picking "${templateName}" did not produce a saved form. The builder URL never ` +
      'gained a form_id, so nothing was written to the everest_form post type.',
  ).toHaveURL(/[?&](form_id|id)=\d+/, { timeout: 45_000 });

  createdFormId = new URL(page.url()).searchParams.get('form_id');

  await expect(
    page.locator('#everest-forms-builder, .everest-forms-admin-page, #evf-builder'),
    'A form id is in the URL but the builder never rendered — the form exists and ' +
      'is unreachable, which is worse than a failed creation.',
  ).toBeVisible({ timeout: 30_000 });
});
