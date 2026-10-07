import { defineConfig, devices } from '@playwright/test'
import base, {
  AUTH_FILE,
  MODULES_AUTH_FILE,
  resolveBaseUrl,
  setupProject,
} from './playwright.config'

/**
 * Config for the documentation screenshot run (`npm run docs:screenshots`).
 *
 * Kept out of `playwright.config.ts` on purpose: `task test-e2e` runs
 * `npx playwright test` with no project filter, so a screenshot project living
 * in the main config would execute — and rewrite docs/images/ — on every E2E
 * run. Reuses the main config's base URL and admin auth setup.
 *
 * Captures tagged `@modules` need an optional module (the admin toolbar) and
 * run against the optional-modules testbed, so both testbeds must be up.
 */
const docsUse = {
  ...devices['Desktop Chrome'],
  // Retina-density captures so the images stay sharp on the GitHub
  // README, which renders them at roughly half their pixel width.
  deviceScaleFactor: 2,
  viewport: { width: 1500, height: 1000 },
}
const modulesURL = resolveBaseUrl('MODULES_WEB_PORT')

export default defineConfig({
  ...base,
  testDir: './tests/E2E/screenshots',
  reporter: 'line',
  retries: 0,
  // The capture file is not a spec — name it explicitly so Playwright's
  // default `*.spec.ts` matcher does not skip it.
  testMatch: /docs\.screenshots\.ts/,

  projects: [
    setupProject('setup-chromium', devices['Desktop Chrome'], AUTH_FILE),
    {
      name: 'docs',
      grepInvert: /@modules/,
      use: { ...docsUse, storageState: AUTH_FILE },
      dependencies: ['setup-chromium'],
    },
    setupProject(
      'setup-modules',
      { ...devices['Desktop Chrome'], baseURL: modulesURL },
      MODULES_AUTH_FILE,
    ),
    {
      name: 'docs-modules',
      grep: /@modules/,
      use: { ...docsUse, baseURL: modulesURL, storageState: MODULES_AUTH_FILE },
      dependencies: ['setup-modules'],
    },
  ],
})
