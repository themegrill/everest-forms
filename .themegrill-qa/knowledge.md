# Everest Forms — QA knowledge

Product: **Everest Forms** 3.6.0 (plugin, free) · text domain `everest-forms` · entry `everest-forms.php`
Drafted by `knowledge-init` from source, docs and git history. **This is a draft.**
Sections marked `TODO` are human sections — the agents will be wrong until they are filled in.

---

## Admin surfaces

Top-level menu `everest-forms`, capability `manage_everest_forms`
(`includes/admin/class-evf-admin-menus.php:155`).

| Page | Slug | Capability | Source |
|---|---|---|---|
| All Forms / Builder | `evf-builder` | `manage_everest_forms` | `class-evf-admin-menus.php:182` |
| Entries | `evf-entries` | `everest_forms_view_entries`, else `everest_forms_view_others_entries` | `class-evf-admin-menus.php:241` |
| Settings | `evf-settings` | `manage_everest_forms` | `class-evf-admin-menus.php:319` |
| SMTP | `evf-smart-smtp` | `manage_everest_forms` | `class-evf-admin-menus.php:360` |
| Tools | `evf-tools` | `manage_everest_forms` | `class-evf-admin-menus.php:367` |
| Add-ons | link to `evf-dashboard#/features` | `manage_everest_forms` | `class-evf-admin-menus.php:390` |

Settings tabs (`includes/admin/settings/`): General, Email, Validation, Security,
Payments, Integrations, Advanced, Reporting.

## Data model

Post type `everest_form` (`includes/class-evf-post-types.php:46`), tags taxonomy
(`:169`). Shortcode registration is filtered per tag (`includes/class-evf-shortcodes.php:27`).

Custom tables (`includes/class-evf-install.php:387` via `dbDelta`):

- `{prefix}evf_entries` (`:407`)
- `{prefix}evf_entrymeta` (`:422`)
- `{prefix}evf_sessions` (`:431`)

**Verifying a save actually stuck means checking these tables, not just the UI.**

## Persistence — option keys

~60 `everest_forms_*` options. The ones most likely to matter to a spec:

`everest_forms_db_version`, `everest_forms_install`, `everest_forms_activated`,
`everest_forms_currency`, `everest_forms_email_template`, `everest_forms_email_send_to`,
`everest_forms_enable_email_copies`, `everest_forms_integrations`,
`everest_forms_enable_restapi`, `everest_forms_enable_log`, `everest_forms_enabled_features`,
`everest_forms_admin_approval_entries_enable`, `everest_forms_enable_entries_reporting`
(+ `_frequency`, `_day`, `_email`, `_subject`), `everest_forms_custom_color_palettes`,
`everest_forms_ai_api_key`, validation keys (`_email_validation`, `_phone_validation`,
`_number_validation`, `_filesize_validation`, `_fileextension_validation`,
`_check_limit_validation`, `_confirm_validation`), `everest_forms_load_fonts_locally`,
`everest_forms_pdf_download_after_submit`, `_everest_forms_permission`.

Full list: `grep -rhoP "(get|update)_option\(\s*'\K[a-z0-9_]+" --include='*.php' .`

## Capabilities

Core: `manage_everest_forms`. Generated per type in
`EVF_Install::get_core_capabilities()` (`includes/class-evf-install.php:519`) for
`forms` and `entries`:

- `everest_forms_create_forms`
- `everest_forms_{view,edit,delete}_forms` and `..._others_forms`
- `everest_forms_{view,edit,delete}_entries` and `..._others_entries`

Meta caps map own-vs-others in `get_meta_caps()` (`:540`) over `form`,
`form_entries`, `entry`. **The own/others split is the interesting boundary for a
roles table** — an author with `view_entries` but not `view_others_entries` must
not see another user's submissions.

## Entry points

- **REST** — `includes/RestApi/class-evf-rest-api.php:56` registers controllers under
  `includes/RestApi/controllers/version1/`: entry submission, templates, plugin status,
  gutenberg blocks, changelog, role and permission. Style Customizer V2 has its own
  controller (`addons/StyleCustomizer/V2/RestController.php`, 5 routes).
  Gated by `everest_forms_enable_restapi`.
- **AJAX** — `evf_ai_generate_form`, `evf_ai_activate_form`, `evf_ai_update_form`,
  `evf_ai_discard_form`, `evf_ai_render_fields`, `evf_ai_get_usage`, `evf_ai_dismiss_hint`,
  `save_template`, `delete_template`, `save_custom_color_palette`, plus dynamically
  built `everest_forms_*` and `everest_forms_new_field_*` handlers
  (`includes/class-evf-ajax.php`).
- **Abilities API** — `includes/abilities/class-evf-abilities.php:632`.

## Migrations

`EVF_Install::$db_updates` (`includes/class-evf-install.php:21`) — 14 versioned
steps from 1.0.0 to 1.7.5. The ones that transform data rather than only bump
`everest_forms_db_version`:

| Version | Routine | What it touches |
|---|---|---|
| 1.1.0 | `evf_update_110_update_forms` | form records |
| 1.1.6 | `evf_update_116_delete_options` | drops options |
| 1.2.0 | `evf_update_120_db_rename_options` | renames options |
| 1.4.0 | `evf_update_140_db_multiple_email` | single → multiple email notifications |
| 1.4.4 | `evf_update_144_delete_options` | drops options |
| 1.4.9 | `evf_update_149_db_rename_options`, `evf_update_149_no_payment_options` | options, payments |
| 1.5.0 | `evf_update_150_field_datetime_type` | date/time field type |
| 1.7.5 | `evf_update_175_remove_capabilities`, `evf_update_175_restore_draft_forms` | caps, draft forms |

`TODO (human)` — **what must survive an upgrade.** Nobody has stated this. The
1.4.0 multi-email and 1.7.5 capability-removal steps are the obvious candidates
for "must not lose data", but that is an inference, not a requirement.

## Fragile areas — evidenced from git history

Churn over the last 400 commits, `--diff-filter=M`, excluding changelog/readme:

| Area | Modify commits | Note |
|---|---|---|
| **Style Customizer V2** | `dist/styleCustomizerV2.min.js` 63, `src/style-customizer-v2/style.scss` 32, `panes.tsx` 30, `App.tsx` 17, `store.ts` 15, `RestController.php` 16, `PreviewBridge.ts` 12, `ControlRenderer.tsx` 11 | overwhelmingly the most fix-prone area in 3.6.0 |
| Admin CSS | `assets/css/admin.scss` 59 | |
| Form builder JS | `assets/js/admin/form-builder.js` 27 | |
| Core bootstrap | `includes/class-everest-forms.php` 24 | |
| Admin assets | `includes/admin/class-evf-admin-assets.php` 20 | |
| AJAX | `includes/class-evf-ajax.php` 12 | |
| Form task (submission) | `includes/class-evf-form-task.php` 11 | |

Named fix commits worth reading before touching Style Customizer V2 — the v1→v2
migration produced a cluster of same-class bugs:

- `dfcd13395` migrate a template's own defaults for props never explicitly saved (EVF-2746)
- `b57c1f513` two more v1-vs-v2 migration gaps found in a full property audit
- `c6a8182b1` v2 style engine overriding Stripe's own border/focus styling
- `cd0acd330` Custom CSS genuinely global again, matching v1's actual behavior
- `b78c7fde3` button-alignment default and preserve legacy Additional CSS on migration (EVF-2732)
- `a9e2c0723` / `d5f672014` missing `text-decoration` var — same bug class twice (EVF-2733)
- `cbc3a5190` stop exposing WP core's global Additional CSS in the per-form customizer (EVF-2737)
- `7d1edbbef` stray (un)slashing mangling preview-draft JSON

`a9e2c0723` explicitly says "same bug class as EVF-2733" — that is a structural
gap in how style properties are applied, not two coincidences.

Second cluster, the AI form builder: `1b9711f21`, `8170cd359`, `c34f6bf4f`,
`7008b783e` (EVF-2736) — the assistant button's visibility and placement against
Multi-Part.

Also `44abd02bf`: unguarded `explode()[1]` in `EVF_Smart_Tags::process()` — smart
tag parsing takes untrusted form content.

## Areas

Two lists, for two different purposes.

**`area_paths` in `.themegrill-qa/suite.json`** maps 11 areas to source globs so
CI can narrow a run to what a diff touches: `activation`, `forms`, `entries`,
`submission`, `settings`, `email`, `style-customizer`, `integrations`,
`page-builders`, `rest-api`, `upgrade`. Every path was checked to exist at
3.6.0. It is a first cut — a maintainer should confirm the boundaries,
particularly whether `submission` and `entries` are usefully separate.

**Doc sections** below (24, from the ingest) are the sweep's `areas_json`:

```json
["tutorials","form-fields","marketing","advanced","faq","everest-forms-pro","new-payment-gateways","form-customization-and-user-experience","getting-started-with-form","crms","individual-form-settings","payment-gateways","integrations","troubleshooting","page-builder-compatibility","account-management","developers-doc","global-settings","anti-spam-and-security","tools","getting-started","design","uncategorised","translation"]
```

## Test coverage as it stands

A `@fresh` suite exists at `tests/e2e/` — 20 tests, all passing against
`test-colormag.local` on 2026-09-07 (product version 3.6.1).

Baseline (flows 8 partially, and the first half of 1):

- four admin screens render with no PHP error, plus the front end
- two settings values survive save and reload
- the template picker offers free templates, and picking one creates a form

Regression guards for every free-testable change between **3.4.8 and 3.6.0**,
each traced to its changelog entry:

- the Style Customizer V2 panel mounts with its Template/Colors controls, all
  six element groups and a live preview containing the form (3.6.0)
- a colour preset chosen in the Style panel reaches the rendered form, verified
  as an anonymous visitor against computed style (3.6.0) — the one guard that
  actually covers the v1→v2 bug class
- the CAPTCHA language survives a save and reload across all four selects (3.5.3)
- no "Add Repeater Row" control on a free install (3.5.3)
- a single click opens a field's Advanced options group (3.5.3)
- the Payments menu is named "Payments" and resolves to `evf-payment-log` (3.5.3)
- System Info reports the addons list (3.5.3)
- conditional logic appears as a Pro upsell in field settings (3.5.0)
- the free Analytics page offers Export and Print (3.5.0)
- an external redirect keeps every query parameter (3.6.1) — proved against the
  reverted fix, where the second parameter is silently swallowed by a fragment

Critical flow #1 is now covered end to end: a visitor submits a published form
anonymously and the entry is verified on the Entries screen, values included.

Still **no coverage at all** of email, entries management, capability
boundaries, upgrade routines, or field-level validation against a real
submission. Style Customizer coverage is one free colour preset — the
per-property breadth its fix history argues for is Pro-gated and cannot be
tested on a free install. See `tests/e2e/README.md` for the gap list.

**Two behaviours found while building the suite**, both verified on 3.6.0 and
neither obviously wrong, but both worth a maintainer's eye:

- The plugin's own form preview URL (`?form_id=<id>&evf_preview=true`) is
  **admin-only**. Anonymous visitors get the theme's page with no form.
- Deleting a form does **not** cascade to `wp_evf_entries`. Its entries survive
  as orphaned rows keyed to a form id that no longer exists.

**Changes since 3.4.8 that could not be covered here**, and why: the coupon
PHP-warning fix and the integration "Remove Authentication" fix (3.6.0) need Pro
addons and live OAuth connections; the subscription currency fix (3.5.3) is a
Pro field; AI form creation (3.5.0/3.5.1) needs an API key; the Contact Form 7
migration enhancement (3.5.0) needs CF7 installed.

## Critical flows — `TODO: confirm ordering`

**Proposed from the surfaces above. I can see what exists; I cannot see what
matters most.** A maintainer must reorder, cut and add. `suite-index.mjs` derives
`areas_uncovered` from this list, so a wrong list sends every future QA effort to
the wrong place.

1. Create a form from a template, save, publish, submit it on the frontend, see the entry
2. Entry list, entry detail, entry export (CSV), entry delete
3. Email notification delivery and the confirmation shown after submit
4. Field validation — required, email, phone, number, file size and extension
5. Style Customizer V2 — apply a template, change a property, verify it renders on the frontend
6. Style Customizer v1 → v2 migration of an existing styled form
7. Create with AI — generate, preview, Use This Form
8. Settings persistence across all eight tabs
9. Capability boundaries — own vs others forms and entries
10. Upgrade from an older `everest_forms_db_version`

## Expected behaviour — `TODO (human)`

Lifted candidates from the docs are in `.themegrill-qa/docs/*.md` under
"Stated outcomes in this section" — each is a doc-owner-phrased assertion with a
URL. Examples already extracted:

- "No form is saved to your site until you click **Use This Form**."
  (create-with-ai) — a real, testable guarantee.
- "The preview reflects the actual form as it will appear to your users —
  including field labels, placeholders, and layout."

These have **not** been verified against 3.6.0. Confirming or refuting each is
QA work, and a mismatch is a finding either way (`DOC DRIFT`).

## Known non-issues — `TODO (human)`

Empty. Nobody has told me what is intentional. Until this is filled in, agents
will file known-good behaviour as bugs.

## Doc drift found so far

`DOC DRIFT: https://docs.everestforms.net/docs/.../create-with-ai` — the docs
state that the form creation screen shows "two options: **Start From Scratch**
and **Create with AI**". On 3.6.0 with the free plugin, the Add New Form screen
shows neither label; it opens straight into "Choose from Templates", and the
only AI affordance is an "Edit with AI" button inside a template's confirmation
modal. Either the docs describe a Pro-only or later screen, or the entry point
changed without the docs following. A human should decide which.

Otherwise unchecked — the ingest ran, the full comparison did not. 4 articles
came back unusually short and are flagged `thin` in `docs-index.json`; those are
parser misses, not necessarily product gaps.

---

## Supported versions

Read from `everest-forms.php` and `readme.txt` at 3.6.0:

| | |
|---|---|
| Requires WordPress | 5.5 |
| Tested up to | 7.0 |
| Requires PHP | 7.2 |
| Stable tag | 3.6.0 |

Note the gap: the plugin header claims **PHP 7.2**, but CI runs **PHP 8.2**
because `composer.lock` requires it (`d290df430`). Nothing in the pipeline
tests the floor it advertises, so a 7.2-incompatible syntax could ship green.
Worth a decision from a human: raise the header, or add a 7.2 lint job.
