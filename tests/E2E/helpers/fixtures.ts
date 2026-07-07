import type { APIRequestContext, Page } from '@playwright/test'
import { createFixtureClient, type FixtureLoadResponse } from '@wedevelop/e2e'

export type { FixtureLoadResponse, FixtureMap } from '@wedevelop/e2e'

/**
 * Fixture client from the wedevelopnl/silverstripe-e2e module, configured for
 * the grid: waits for the grid editor spinner to disappear after navigation.
 * Fixtures themselves are registered in _config/dev.yml.
 */
const client = createFixtureClient({
  editorReadySelector: '[data-testid="grid-editor-loading"]',
  editorReadyTimeout: 15_000,
})

/**
 * Load a named fixture via the e2e module's fixture endpoint.
 *
 * Resets existing E2E data and writes the fixture into the database.
 * Returns page ID, URL, and full fixture map for test navigation.
 */
export function loadFixture(
  request: APIRequestContext,
  name: string,
): Promise<FixtureLoadResponse> {
  return client.load(request, name)
}

/**
 * Reset all E2E fixture data (removes pages with 'e2e-' URL prefix).
 */
export function resetFixtures(request: APIRequestContext): Promise<void> {
  return client.reset(request)
}

/**
 * Load a fixture and navigate to its CMS page editor.
 * Waits for the grid editor to finish loading before returning.
 */
export function loadAndNavigate(page: Page, fixtureName: string): Promise<FixtureLoadResponse> {
  return client.loadAndNavigate(page, fixtureName)
}
