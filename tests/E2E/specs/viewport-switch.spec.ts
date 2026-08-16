import { expect, test } from '@playwright/test'
import {
  activateViewport,
  expectActiveViewport,
  readAdapterConfig,
  twoNonDefaultViewports,
  viewportLabel,
  viewportTrigger,
  widthLabel,
} from '../helpers/adapter'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

test.describe('Viewport picker — create and reset overrides', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor creates per-viewport overrides and resets them through the picker', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'element-tree')

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const [viewportA, viewportB] = twoNonDefaultViewports(adapter)
    const labelA = viewportLabel(adapter, viewportA)
    const labelB = viewportLabel(adapter, viewportB)

    const trigger = viewportTrigger(page)
    const menu = page.getByTestId('viewport-picker-dropdown')
    const confirmDialog = page.getByTestId('confirm-dialog')
    const leftColumn = page.getByTestId('column-block').first()
    const leftBadge = leftColumn.getByTestId('column-badge')

    const fullWidth = widthLabel(adapter.columnCount)
    const halfWidth = widthLabel(Math.floor(adapter.columnCount / 2))
    const thirdWidth = widthLabel(Math.floor(adapter.columnCount / 3))

    /** Reset-scope rows, in order, as the picker currently offers them. */
    const openResetScopes = async (): Promise<string[]> => {
      await trigger.click()
      await expect(menu).toBeVisible()
      return await menu
        .getByRole('menuitem')
        .evaluateAll((items) => items.map((item) => item.textContent ?? ''))
    }

    const setBadgeWidth = async (label: string) => {
      await leftBadge.click()
      await leftColumn
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: label, exact: true })
        .click()
      await expect(leftBadge).toHaveText(label)
    }

    await test.step('the picker names the default viewport and offers all of them', async () => {
      await expectActiveViewport(page, adapter.defaultViewport)
      await trigger.click()
      await expect(menu.getByRole('menuitemradio')).toHaveCount(adapter.viewports.length)
      await expect(
        page.getByTestId(`viewport-picker-option-${adapter.defaultViewport}`),
      ).toHaveAttribute('aria-checked', 'true')
      // Nothing is overridden yet, so no reset scopes are offered.
      await expect(menu.getByRole('menuitem')).toHaveCount(0)
      await page.keyboard.press('Escape')
      await expect(menu).toBeHidden()
    })

    await test.step('one overridden viewport is offered alone, with its column count', async () => {
      await activateViewport(page, viewportA)
      await setBadgeWidth(halfWidth)

      // No "all viewports" entry — it would clear exactly the same column.
      expect(await openResetScopes()).toEqual([`${labelA}1`])
      await page.keyboard.press('Escape')
      await expect(menu).toBeHidden()
    })

    await test.step('a second overridden viewport adds its own scope and the aggregate', async () => {
      await activateViewport(page, viewportB)
      await setBadgeWidth(thirdWidth)

      expect(await openResetScopes()).toEqual([`${labelA}1`, `${labelB}1`, 'All viewports1'])
      await page.keyboard.press('Escape')
    })

    await test.step('every scope stays reachable from the adapter default viewport', async () => {
      await activateViewport(page, adapter.defaultViewport)

      expect(await openResetScopes()).toEqual([`${labelA}1`, `${labelB}1`, 'All viewports1'])
      await page.keyboard.press('Escape')
    })

    await test.step('resetting one viewport leaves the other override standing', async () => {
      await openResetScopes()
      await menu.getByRole('menuitem').filter({ hasText: labelA }).click()
      await expect(confirmDialog).toBeVisible()
      await confirmDialog.getByRole('button', { name: 'Reset', exact: true }).click()

      await activateViewport(page, viewportA)
      await expect(leftBadge).toHaveText(fullWidth)
      await activateViewport(page, viewportB)
      await expect(leftBadge).toHaveText(thirdWidth)
    })

    await test.step('the aggregate withdraws once a single viewport is left', async () => {
      expect(await openResetScopes()).toEqual([`${labelB}1`])
      await page.keyboard.press('Escape')
      await expect(menu).toBeHidden()
    })

    await test.step('the aggregate scope clears every viewport at once', async () => {
      // A different path to the API than the per-viewport scopes above (no
      // viewport parameter), so it needs its own journey.
      await activateViewport(page, viewportA)
      await setBadgeWidth(halfWidth)
      expect(await openResetScopes()).toEqual([`${labelA}1`, `${labelB}1`, 'All viewports1'])

      await menu.getByRole('menuitem').filter({ hasText: 'All viewports' }).click()
      await expect(confirmDialog).toBeVisible()
      await confirmDialog.getByRole('button', { name: 'Reset', exact: true }).click()

      for (const key of [viewportA, viewportB]) {
        await activateViewport(page, key)
        await expect(leftBadge).toHaveText(fullWidth)
      }
      expect(await openResetScopes()).toEqual([])
    })
  })
})

test.describe('Viewport picker — independent overrides and publish', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('per-viewport overrides stay independent and the page publishes', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'element-tree')

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const [viewportA, viewportB] = twoNonDefaultViewports(adapter)

    const leftColumn = page.getByTestId('column-block').first()
    const leftBadge = leftColumn.getByTestId('column-badge')

    const fullWidth = widthLabel(adapter.columnCount)
    const halfWidth = widthLabel(Math.floor(adapter.columnCount / 2))
    const thirdWidth = widthLabel(Math.floor(adapter.columnCount / 3))

    const setBadgeWidth = async (label: string) => {
      await leftBadge.click()
      await leftColumn
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: label, exact: true })
        .click()
      await expect(leftBadge).toHaveText(label)
    }

    await test.step('baseline width renders at all viewports (no overrides)', async () => {
      for (const vp of adapter.viewports) {
        await activateViewport(page, vp.key)
        await expect(leftBadge).toHaveText(fullWidth)
      }
    })

    await test.step('override at viewportA does not affect other viewports', async () => {
      await activateViewport(page, viewportA)
      await setBadgeWidth(halfWidth)

      for (const vp of adapter.viewports) {
        if (vp.key === viewportA) continue
        await activateViewport(page, vp.key)
        await expect(leftBadge).toHaveText(fullWidth)
      }
    })

    await test.step('a second override at viewportB is independent of viewportA', async () => {
      await activateViewport(page, viewportB)
      await setBadgeWidth(thirdWidth)

      await activateViewport(page, viewportA)
      await expect(leftBadge).toHaveText(halfWidth)
    })

    await test.step('publishing the page succeeds and the frontend renders', async () => {
      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
        timeout: 10_000,
      })

      const livePath = fixture.pageUrl.split('?')[0]
      await page.goto(livePath)
      await expect(
        page.getByRole('heading', { level: 1, name: 'E2E Grid Test Page', exact: true }),
      ).toBeVisible()
    })
  })
})

test.describe('Viewport picker — narrow panels', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  // Split mode is the CMS's default and leaves the editor around 600px, the
  // width the control has to survive without spilling the edit form sideways.
  test('the control fits a split-mode panel without overflowing it', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'element-tree')

    await page.setViewportSize({ width: 1000, height: 900 })
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const control = page.getByTestId('viewport-switcher')

    const overflow = await control.evaluate((el) => el.scrollWidth - el.clientWidth)
    expect(overflow).toBeLessThanOrEqual(0)

    await viewportTrigger(page).click()
    await expect(
      page.getByTestId('viewport-picker-dropdown').getByRole('menuitemradio'),
    ).toHaveCount(adapter.viewports.length)
  })
})
