import { expect, test } from '@playwright/test'
import {
  activateViewport,
  readAdapterConfig,
  twoNonDefaultViewports,
  expectActiveViewport,
  widthLabel,
} from '../helpers/adapter'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

/**
 * Isolated-strategy override resolution — user-observable path.
 *
 * The project's default override strategy is "isolated": an override at one
 * viewport does not propagate to other viewports. Exhaustive strategy
 * coverage (including the alternative "cascade" strategy that walks
 * largest→smallest) lives in GridSettingsResolverTest at the unit level.
 *
 * This spec verifies the runtime path a user sees:
 *   1. A non-default viewport override shows up only when that viewport
 *      is active.
 *   2. Other viewports continue to show the column's default width.
 *   3. Switching back to the override viewport restores the override
 *      value from TanStack Query cache without refetching.
 */
test.describe('Viewport override resolution (isolated strategy)', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('an override at one viewport does not bleed into siblings', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'viewport-cascade')

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const [overrideViewport, otherViewport] = twoNonDefaultViewports(adapter)

    const column = page.getByTestId('column-block').filter({ hasText: 'Cascade Column' })
    const badge = column.getByTestId('column-badge')

    const fullWidth = widthLabel(adapter.columnCount)
    const overrideWidth = widthLabel(Math.floor(adapter.columnCount / 2))

    await test.step('default viewport is active on first load, badge shows the stored default', async () => {
      await expectActiveViewport(page, adapter.defaultViewport)
      await expect(badge).toHaveText(fullWidth)
    })

    await test.step('setting a width override at one non-default viewport updates the badge there', async () => {
      await activateViewport(page, overrideViewport)
      await badge.click()
      await column
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: overrideWidth, exact: true })
        .click()
      await expect(badge).toHaveText(overrideWidth)
    })

    await test.step('a different non-default viewport still shows the column default', async () => {
      await activateViewport(page, otherViewport)
      await expect(badge).toHaveText(fullWidth)
    })

    await test.step('the default viewport is unaffected by the override', async () => {
      await activateViewport(page, adapter.defaultViewport)
      await expect(badge).toHaveText(fullWidth)
    })

    await test.step('switching back to the override viewport restores the override value', async () => {
      await activateViewport(page, overrideViewport)
      await expect(badge).toHaveText(overrideWidth)
    })
  })
})
