import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig, devices, type Project } from '@playwright/test'

/**
 * Resolve a testbed's base URL from its port key in .docker/.env
 * (WEB_PORT for `app`, MODULES_WEB_PORT for `app-modules`).
 */
export function resolveBaseUrl(portKey: string): string {
  const envPath = resolve(__dirname, '.docker/.env')
  try {
    const envContent = readFileSync(envPath, 'utf-8')
    const match = envContent.match(new RegExp(`^${portKey}=(\\d+)$`, 'm'))
    if (match) {
      return `https://localhost:${match[1]}`
    }
  } catch {
    // .docker/.env not generated yet — fall through
  }

  throw new Error(
    `Cannot determine base URL: no ${portKey} in .docker/.env. Run .docker/env.sh first.`,
  )
}

/** One admin session per testbed: each has its own database. */
export const AUTH_FILE = 'tests/E2E/.auth/admin.json'
export const MODULES_AUTH_FILE = 'tests/E2E/.auth/admin-modules.json'

/** Logs in as admin and saves the session to `authFile` (read by global.setup.ts). */
export function setupProject(name: string, use: Project['use'], authFile: string): Project {
  return {
    name,
    testDir: './tests/E2E',
    testMatch: /global\.setup\.ts/,
    use,
    metadata: { authFile },
  }
}

export default defineConfig({
  testDir: './tests/E2E/specs',
  fullyParallel: false,
  workers: 1,
  // Two retries in CI, one locally. The app container is set to restart on
  // failure (.docker/compose.yml), and a restart costs roughly one test timeout
  // to come back — so the first retry after a crash still lands on a dead port
  // and only the second can pass. Locally a crash is visible and worth stopping
  // on, so the extra attempt would only slow the feedback loop.
  retries: process.env.CI ? 2 : 1,
  reporter: 'html',

  use: {
    baseURL: process.env.E2E_BASE_URL ?? resolveBaseUrl('WEB_PORT'),
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    // Dev environment uses self-signed certificates
    ignoreHTTPSErrors: true,
  },

  projects: [
    setupProject('setup-chromium', devices['Desktop Chrome'], AUTH_FILE),
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: AUTH_FILE,
      },
      dependencies: ['setup-chromium'],
    },
    // Firefox only runs in CI (via --project flag)
    ...(process.env.CI
      ? [
          setupProject('setup-firefox', devices['Desktop Firefox'], AUTH_FILE),
          {
            name: 'firefox',
            use: {
              ...devices['Desktop Firefox'],
              storageState: AUTH_FILE,
            },
            dependencies: ['setup-firefox'],
          },
        ]
      : []),
  ],
})
