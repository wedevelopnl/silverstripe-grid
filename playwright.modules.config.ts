import { defineConfig, devices } from '@playwright/test'
import base, { MODULES_AUTH_FILE, resolveBaseUrl, setupProject } from './playwright.config'

/**
 * Config for the optional-modules testbed (`task test-e2e-modules`): the
 * `app-modules` container, with userforms, Fluent and the admin toolbar
 * installed.
 *
 * Kept out of `playwright.config.ts` on purpose: `task test-e2e` runs
 * `npx playwright test` with no project filter against `app`, where these
 * modules — and the fixtures that need them — do not exist.
 *
 * Specs run as admin, like the base suite; a visitor journey opts out with an
 * empty `storageState`.
 */
export default defineConfig({
  ...base,
  testDir: './tests/E2E/modules',

  use: {
    ...base.use,
    baseURL: resolveBaseUrl('MODULES_WEB_PORT'),
  },

  projects: [
    setupProject('setup-chromium', devices['Desktop Chrome'], MODULES_AUTH_FILE),
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: MODULES_AUTH_FILE,
      },
      dependencies: ['setup-chromium'],
    },
  ],
})
