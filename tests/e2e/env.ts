import dotenv from 'dotenv';
import path from 'path';

/**
 * The single place this suite decides *which site it is talking to*.
 *
 * Three callers drive these specs and none of them can be asked to adopt
 * another's variable names, so every value resolves through one precedence
 * chain rather than each module reading `process.env` its own way:
 *
 *   1. `TGQA_*`            — exported by themegrill-qa's run-suite.mjs. Wins,
 *                            because when the platform is driving, the
 *                            platform decides.
 *   2. `EVEREST_FORMS_*`   — the names this product's .themegrill-qa/suite.json
 *                            declares; a developer's own override.
 *   3. `WP_*`              — generic fallback, so an existing local .env keeps
 *                            working. Not advertised.
 *   4. a default           — for the base URL only. Never for credentials.
 */

// Real environment variables always win: dotenv does not overwrite what is
// already set, so a run-suite.mjs-exported TGQA_* survives a stale .env.local.
dotenv.config({ path: path.join(__dirname, '..', '..', '.themegrill-qa', '.env.local') });
// Plugin root, for anyone who put one there instead.
dotenv.config({ path: path.join(__dirname, '..', '..', '.env.local') });

/** Convenience default, so `npm run test:e2e` needs no setup on the Local site. */
export const DEFAULT_BASE_URL = 'http://test-colormag.local';

/**
 * Where the logged-in admin session is cached between runs.
 *
 * Declared here rather than in `auth.setup.ts` because `playwright.config.ts`
 * needs it too, and a config file may not import a module that calls `test()`
 * — Playwright rejects that with "did not expect test() to be called here".
 * Gitignored via `tests/e2e/.gitignore`.
 */
export const STORAGE_STATE = path.join(__dirname, '.auth', 'admin.json');

export type TargetEnv = 'playground' | 'wp-env' | 'local';

export function baseUrl(): string {
  return (
    process.env.TGQA_BASE_URL ??
    process.env.EVEREST_FORMS_BASE_URL ??
    process.env.WP_BASE_URL ??
    DEFAULT_BASE_URL
  );
}

/**
 * Which kind of environment we are pointed at.
 *
 * Specs read this to skip what genuinely cannot work rather than to weaken an
 * assertion: Playground is PHP-WASM on SQLite, with no real cron and no
 * outbound mail — which matters here, because Everest Forms is a *forms*
 * plugin and half its promises are email-shaped.
 */
export function targetEnv(): TargetEnv {
  const raw = (process.env.TGQA_ENV ?? 'local').toLowerCase();
  return raw === 'playground' || raw === 'wp-env' ? raw : 'local';
}

export const isPlayground = (): boolean => targetEnv() === 'playground';

export type AdminCredentials = { user: string; password: string };

/**
 * Admin credentials, or a loud failure.
 *
 * Deliberately throws rather than defaulting to `admin`/`password`. A default
 * that works on exactly one environment turns "you forgot to configure this"
 * into "the login step timed out", which costs an investigation every time.
 */
export function adminCredentials(): AdminCredentials {
  const user =
    process.env.TGQA_ADMIN_USER ??
    process.env.EVEREST_FORMS_ADMIN_USER ??
    process.env.WP_ADMIN_USER;
  const password =
    process.env.TGQA_ADMIN_PASS ??
    process.env.EVEREST_FORMS_ADMIN_PASS ??
    process.env.WP_ADMIN_PASSWORD;

  if (!user || !password) {
    const missing = [!user && 'user', !password && 'password'].filter(Boolean).join(' and ');
    throw new Error(
      `No admin ${missing} for ${baseUrl()}.\n` +
        'Set one of these pairs, in this order of precedence:\n' +
        '  TGQA_ADMIN_USER          / TGQA_ADMIN_PASS          (exported by themegrill-qa run-suite.mjs)\n' +
        '  EVEREST_FORMS_ADMIN_USER / EVEREST_FORMS_ADMIN_PASS (.themegrill-qa/.env.local)\n' +
        '  WP_ADMIN_USER            / WP_ADMIN_PASSWORD        (generic fallback)\n\n' +
        'See tests/e2e/README.md. Never commit credentials.',
    );
  }

  return { user, password };
}

export function playgroundSkipReason(what: string): string {
  return `${what} — not available on Playground (PHP-WASM on SQLite: no MySQL, no real cron, no outbound mail).`;
}
