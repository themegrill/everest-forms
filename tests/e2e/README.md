# Everest Forms — E2E suite

Playwright, Chromium only. Twenty `@fresh` tests. Half cover the basics —
activation, settings persistence, form creation — and half are regression
guards for specific changes shipped between **3.4.8 and 3.6.0**, each traced to
its changelog entry in the spec's `@source`. This is still a **starting** suite
— see "What is not covered" below before trusting a green run.

### Regression guards by release

| Release | Change | Spec |
|---|---|---|
| 3.6.1 | Query params in external redirect URLs were HTML-encoded | `submission/external-redirect-query-params` |
| 3.6.0 | Revamped Style Customizer — panel structure | `style-customizer/style-customizer-v2` |
| 3.6.0 | Revamped Style Customizer — a preset reaches the form | `style-customizer/color-preset-reaches-front-end` |
| 3.5.3 | CAPTCHA language reset to English (UK) after saving | `settings/captcha-language-persists` |
| 3.5.3 | Hide "Add Repeater Row" when Pro is inactive | `builder/pro-gated-controls` |
| 3.5.3 | Field settings tabs needed a double-click | `builder/field-option-groups` |
| 3.5.3 | Renamed Payment menu to Payments | `admin/menus-and-pages` |
| 3.5.3 | Active addons list in System Info | `admin/menus-and-pages` |
| 3.5.0 | Conditional logic showcased as a Pro feature | `builder/pro-gated-controls` |
| 3.5.0 | Export and Print on the free Analytics page | `admin/menus-and-pages` |

## Running it

```bash
npm run test:e2e                 # everything
npm run test:e2e -- --grep @fresh   # the tier CI runs
```

Or through the QA platform, which is what CI uses:

```bash
node <themegrill-qa>/scripts/run-suite.mjs --tier fresh --json
```

## Configuration

Credentials come from `.themegrill-qa/.env.local` (gitignored). `env.ts` owns
the precedence chain and documents it; the short version is that
`run-suite.mjs`'s `TGQA_*` wins, then `EVEREST_FORMS_*`, then `WP_*`, then a
local default for the base URL only. Credentials never get a default — a
missing one throws with instructions rather than timing out on a login screen.

The suite needs Everest Forms to be **active** on the target site. It does not
activate it, because a suite that changes which plugins are on is a suite that
cannot be trusted to have tested the configuration you asked about.

## Tiers

Tagging is in the test *title*, because that is what `--grep` matches.

- `@fresh` — runs against any WordPress with the plugin active. No seeded content.
- `@demo` — needs a demo-imported site. Nothing uses this yet.

Area tags (`@activation`, `@settings`, `@forms`) match the `area_paths` keys in
`.themegrill-qa/suite.json`, which is how CI narrows a run to what a diff touches.

## Conventions

Every test carries a docblock with `@area`, `@tier`, `@source` and `@why`. The
`@why` is the load-bearing one: it says what would break in the product if this
test failed, so a future reader can judge whether a failure matters or whether
the test has simply gone stale.

Assertions carry messages that name the *product* consequence, not the DOM
condition. "Settings saving is broken" is useful; "expected value to be X" is
not.

## Things this suite learned the hard way

Recorded because each cost a debugging cycle and the DOM gives no hint:

- The **template filters** (All / Free / Premium) are Chakra tabs —
  `<button role="tab">`. An explicit ARIA role wins over the tag, so
  `getByRole('button')` does not match them.
- **Every template card** carries its own hidden "Use this template" button. An
  unscoped lookup matches five and fails strict mode.
- The **confirmation modal** is a Chakra portal with no `role="dialog"` and no
  accessible name. It is targeted by `.chakra-modal__content-container`.
- **WP list-table row actions** (the Trash link) are visually hidden until the
  row is hovered. Clicking one retries against an off-viewport element until
  the hook times out. Read the `href` and navigate to it instead — it carries
  the nonce anyway.
- The **form builder guards navigation** with an unsaved-changes confirm.
- **`locator.innerText()` auto-waits for the element to exist.** Reading a
  possibly-absent element (`#login_error`, a notice, an error banner) costs the
  full actionability timeout every time it is absent — which is usually the
  passing path. Call `count()` first. This is invisible locally whenever a
  cached `.auth/admin.json` skips the code, and fatal on CI where every run is
  cold: delete `tests/e2e/.auth/` before trusting a local run of anything in
  `auth.setup.ts`.
- **`networkidle` is not a save signal in the builder.** It is a long-lived page
  that keeps chattering, so the wait resolves immediately and a following
  navigation races the write — the field you just added is silently discarded.
  Wait for the `admin-ajax.php` response whose body contains
  `everest_forms_save_form` (see `saveForm()` usage).
- **Smart tags are not processed in the confirmation success message.**
  `everest_forms_process_smart_tags` is applied to redirect URLs and email
  fields only (`class-evf-form-task.php:877` renders the message as-is), so a
  `{field_id="..."}` there renders literally. This is by design, not a bug.
- **The external-URL setting strips `{`, `"` and `}` on save**, so a smart tag
  cannot be smuggled through a redirect query value either.
- The plugin's **preview URL (`?form_id=<id>&evf_preview=true`) is admin-only**.
  An anonymous request gets the theme's page with no form in it, so any
  submission test that uses it is really testing an administrator submitting a
  form. Publish a real page instead — see `fixtures/published-form.ts`.
- **"All changes saved" in the Style panel is not a save signal.** It is the
  live preview's idle label and is on screen before anything changes, so
  waiting for it returns instantly and the front end gets read before the CSS
  is regenerated. Poll the rendered result instead.
- **Templates do not share field ids.** A spec that hardcodes `fullname` or
  `subject` will hang on a form built from a different template. Fill by input
  *type*.
- **Selecting a field does not open its settings.** The whole
  `.everest-forms-field-options` panel stays hidden until the sidebar's "Field
  Options" tab is clicked, and every field's option groups sit in the DOM at
  0x0 the entire time. Scope to the visible wrapper or you will click a group
  belonging to a different field.
- The Style panel's **TEMPLATE / COLORS labels are `text-transform: uppercase`**.
  The DOM text is "Template" and "Colors", so an exact match on the rendered
  casing finds nothing.
- **Conditional Logic shares the `-advanced` group class** with real option
  groups but is a Pro upsell that does not toggle. Exclude it with
  `:not(.evf-cl-upsell-group)`.
- The Tools page tab slug is **`system_info`**, not `system_status`. An unknown
  `tab` value renders an "Undefined array key" PHP warning
  (`html-admin-page-tools.php:91`) — a pre-existing bug, not one this suite
  introduced.

## What is not covered

Honest gaps, roughly in priority order:

1. **Email notifications.** Everest Forms is a forms plugin; half its promises
   are email-shaped, and none of them are asserted. Needs a mail catcher.
2. **Style Customizer breadth.** `color-preset-reaches-front-end` proves the
   pipeline works for one free colour preset. The per-property coverage the
   v1→v2 fix history argues for — typography, spacing, borders, per-element
   overrides — is all Pro-gated and untestable on a free install.
3. **Capability boundaries** — the own-vs-others split on forms and entries.
4. **Upgrade routines.** 14 versioned steps, none exercised.
5. **Field-level validation.** Required/email/phone/number/file rules are
   configured by the settings specs but never exercised against a submission.
6. **Entries management** — export, delete, bulk actions, the single-entry view.
7. **The 3.6.1 Country smart-tag fix.** Attempted and abandoned: the fix lives in
   the array-shaped branch of `EVF_Smart_Tags::process()`, and neither reachable
   free surface gets there — the success message never runs smart tags, and the
   redirect URL field strips the tag's braces on save. It is very likely only
   observable through an email notification, so it needs a mail catcher. A first
   version of this spec passed identically with the fix present and reverted;
   it was deleted rather than kept, because a guard that cannot fail is worse
   than no guard.

A green run means "the plugin activates, its admin screens render, settings
save, a form can be created, and the specific things fixed between 3.4.8 and
3.6.0 are still fixed". It does not mean the product works.

## Site state

Specs that change site state restore it: the settings specs capture and put
back the original option value, the builder fixture trashes the form it
created, and the published-form fixture force-deletes its host page.

Two things still accumulate on a long-lived QA site, both deliberate:

- **Forms are trashed, not permanently deleted** — that is exactly what the row
  action a user clicks does, and the fixture uses the real one.
- **Entries survive their form.** Deleting a form does *not* cascade to
  `wp_evf_entries`, so a submission spec's entry outlives the form it was
  submitted to and becomes orphaned. Verified against 3.6.0; worth knowing
  before reading anything into old rows.

Neither affects correctness — every spec keys on a per-run unique stamp rather
than on counts — but empty the Trash and prune `wp_evf_entries` occasionally.
