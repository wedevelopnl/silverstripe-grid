import { defineConfig, devices } from '@playwright/test'
import base, { resolveBaseUrl } from './playwright.config'

/**
 * Config for the optional-modules testbed (`task test-e2e-modules`): the
 * `app-modules` container, with userforms and Fluent installed.
 *
 * Kept out of `playwright.config.ts` on purpose: `task test-e2e` runs
 * `npx playwright test` with no project filter against `app`, where these
 * modules — and the fixtures that need them — do not exist.
 *
 * No admin login: the specs here drive the site as an anonymous visitor, and
 * the fixture endpoint needs no session.
 */
export default defineConfig({
  ...base,
  testDir: './tests/E2E/modules',

  use: {
    ...base.use,
    baseURL: resolveBaseUrl('MODULES_WEB_PORT'),
  },

  projects: [
    {
      name: 'chromium',
      use: devices['Desktop Chrome'],
    },
  ],
})
