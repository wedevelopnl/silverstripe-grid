import { defineConfig, devices } from '@playwright/test'
import base from './playwright.config'

/**
 * Config for the documentation screenshot run (`npm run docs:screenshots`).
 *
 * Kept out of `playwright.config.ts` on purpose: `task test-e2e` runs
 * `npx playwright test` with no project filter, so a screenshot project living
 * in the main config would execute — and rewrite docs/images/ — on every E2E
 * run. Reuses the main config's base URL and admin auth setup.
 */
export default defineConfig({
  ...base,
  testDir: './tests/E2E/screenshots',
  reporter: 'line',
  retries: 0,

  projects: [
    {
      name: 'setup-chromium',
      testDir: './tests/E2E',
      testMatch: /global\.setup\.ts/,
      use: devices['Desktop Chrome'],
    },
    {
      name: 'docs',
      // The capture file is not a spec — name it explicitly so Playwright's
      // default `*.spec.ts` matcher does not skip it.
      testMatch: /docs\.screenshots\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/E2E/.auth/admin.json',
        // Retina-density captures so the images stay sharp on the GitHub
        // README, which renders them at roughly half their pixel width.
        deviceScaleFactor: 2,
        viewport: { width: 1500, height: 1000 },
      },
      dependencies: ['setup-chromium'],
    },
  ],
})
