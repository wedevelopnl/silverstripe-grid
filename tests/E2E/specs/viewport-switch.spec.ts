import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'
import {
  activateViewport,
  readAdapterConfig,
  twoNonDefaultViewports,
  viewportButton,
  viewportLabel,
  widthLabel,
} from '../helpers/adapter'

test.describe('Viewport switcher — create and reset overrides', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor creates per-viewport overrides and resets them via the viewport switcher', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'element-tree')

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const [viewportA, viewportB] = twoNonDefaultViewports(adapter)

    const leftColumn = page.getByTestId('column-block').first()
    const leftBadge = leftColumn.getByTestId('column-badge')
    const resetTrigger = page.getByTestId('viewport-reset-trigger')
    const resetMenu = page.getByTestId('viewport-reset-dropdown')
    const confirmDialog = page.getByTestId('confirm-dialog')

    /** Menu-item labels, in order, as the reset menu currently offers them. */
    const openResetScopes = async (): Promise<string[]> => {
      await resetTrigger.click()
      await expect(resetMenu).toBeVisible()
      return await resetMenu
        .getByRole('menuitem')
        .evaluateAll((items) => items.map((item) => item.textContent ?? ''))
    }

    // Change a column's width via the badge listbox at a given viewport.
    const setBadgeWidth = async (label: string) => {
      await leftBadge.click()
      await leftColumn
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: label, exact: true })
        .click()
      await expect(leftBadge).toHaveText(label)
    }

    await test.step('no overrides — the reset menu is not offered at all', async () => {
      await expect(resetTrigger).toBeHidden()
    })

    const halfWidth = widthLabel(Math.floor(adapter.columnCount / 2))
    const thirdWidth = widthLabel(Math.floor(adapter.columnCount / 3))
    const labelA = viewportLabel(adapter, viewportA)
    const labelB = viewportLabel(adapter, viewportB)

    await test.step('one overridden viewport is offered alone, with its column count', async () => {
      await activateViewport(page, viewportA)
      await setBadgeWidth(halfWidth)

      await expect(resetTrigger).toBeVisible()
      // No "all viewports" entry — it would clear exactly the same column.
      expect(await openResetScopes()).toEqual([`${labelA}1`])
      await page.keyboard.press('Escape')
    })

    await test.step('a second overridden viewport adds its own scope and the aggregate', async () => {
      await activateViewport(page, viewportB)
      await setBadgeWidth(thirdWidth)

      expect(await openResetScopes()).toEqual([`${labelA}1`, `${labelB}1`, 'All viewports1'])
      await page.keyboard.press('Escape')
    })

    await test.step('every scope stays reachable from the adapter default viewport', async () => {
      // The control this replaced put "reset everything" behind this one tab
      // and offered only the single-viewport reset from any other.
      await activateViewport(page, adapter.defaultViewport)

      expect(await openResetScopes()).toEqual([`${labelA}1`, `${labelB}1`, 'All viewports1'])
      await page.keyboard.press('Escape')
    })

    await test.step('resetting one viewport leaves the other override standing', async () => {
      await openResetScopes()
      await resetMenu.getByRole('menuitem').filter({ hasText: labelA }).click()
      await expect(confirmDialog).toBeVisible()
      await confirmDialog.getByRole('button', { name: 'Reset', exact: true }).click()

      const fullWidth = widthLabel(adapter.columnCount)
      await activateViewport(page, viewportA)
      await expect(leftBadge).toHaveText(fullWidth)
      await activateViewport(page, viewportB)
      await expect(leftBadge).toHaveText(thirdWidth)
    })

    await test.step('the last scope clears the rest and retires the menu', async () => {
      // One viewport left overridden, so the menu is back to a single entry.
      expect(await openResetScopes()).toEqual([`${labelB}1`])
      await resetMenu.getByRole('menuitem').filter({ hasText: labelB }).click()
      await expect(confirmDialog).toBeVisible()
      await confirmDialog.getByRole('button', { name: 'Reset', exact: true }).click()

      await expect(resetTrigger).toBeHidden()

      const fullWidth = widthLabel(adapter.columnCount)
      for (const key of [viewportA, viewportB]) {
        await activateViewport(page, key)
        await expect(leftBadge).toHaveText(fullWidth)
      }
    })
  })
})

test.describe('Viewport switcher — independent overrides and publish', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('viewport switcher preserves per-viewport overrides independently and publishes successfully', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'element-tree')

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const [viewportA, viewportB] = twoNonDefaultViewports(adapter)

    const viewportButtons = page.getByTestId(/^viewport-button-/)
    const leftColumn = page.getByTestId('column-block').first()
    const leftBadge = leftColumn.getByTestId('column-badge')

    const fullWidth = widthLabel(adapter.columnCount)
    const halfWidth = widthLabel(Math.floor(adapter.columnCount / 2))
    const thirdWidth = widthLabel(Math.floor(adapter.columnCount / 3))

    await test.step('switcher renders one button per adapter viewport with the default viewport active', async () => {
      await expect(viewportButtons).toHaveCount(adapter.viewports.length)
      await expect(viewportButton(page, adapter.defaultViewport)).toHaveAttribute(
        'aria-pressed',
        'true',
      )
      for (const vp of adapter.viewports) {
        if (vp.key === adapter.defaultViewport) continue
        await expect(viewportButton(page, vp.key)).toHaveAttribute('aria-pressed', 'false')
      }
    })

    await test.step('baseline width renders at all viewports (no overrides)', async () => {
      for (const vp of adapter.viewports) {
        await activateViewport(page, vp.key)
        await expect(leftBadge).toHaveText(fullWidth)
      }
    })

    await test.step('override at viewportA does not affect other viewports', async () => {
      await activateViewport(page, viewportA)
      await leftBadge.click()
      await leftColumn
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: halfWidth, exact: true })
        .click()
      await expect(leftBadge).toHaveText(halfWidth)

      for (const vp of adapter.viewports) {
        if (vp.key === viewportA) continue
        await activateViewport(page, vp.key)
        await expect(leftBadge).toHaveText(fullWidth)
      }
    })

    await test.step('a second override at viewportB is independent of viewportA', async () => {
      await activateViewport(page, viewportB)
      await leftBadge.click()
      await leftColumn
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: thirdWidth, exact: true })
        .click()
      await expect(leftBadge).toHaveText(thirdWidth)

      // Original override intact.
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
