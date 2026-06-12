import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'
import {
  activateViewport,
  readAdapterConfig,
  twoNonDefaultViewports,
  viewportButton,
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
    const resetButton = page.getByTestId('reset-overrides-button')
    const confirmDialog = page.getByTestId('confirm-dialog')

    // Change a column's width via the badge listbox at a given viewport.
    const setBadgeWidth = async (widthOverTotal: string) => {
      await leftBadge.click()
      await leftColumn
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: widthOverTotal })
        .click()
      await expect(leftBadge).toHaveText(widthOverTotal)
    }

    await test.step('no overrides — reset button is hidden', async () => {
      await expect(resetButton).toBeHidden()
    })

    const halfWidth = `${Math.floor(adapter.columnCount / 2)}/${adapter.columnCount}`
    const thirdWidth = `${Math.floor(adapter.columnCount / 3)}/${adapter.columnCount}`

    await test.step('creating an override at a non-default viewport shows "Reset viewport"', async () => {
      await activateViewport(page, viewportA)
      await setBadgeWidth(halfWidth)

      await expect(resetButton).toBeVisible()
      await expect(resetButton).toHaveText('Reset viewport')
    })

    await test.step('switching to the default viewport flips the label to "Reset all"', async () => {
      await activateViewport(page, adapter.defaultViewport)
      await expect(resetButton).toHaveText('Reset all')
    })

    await test.step('adding a second override at another non-default viewport keeps "Reset all" from default', async () => {
      await activateViewport(page, viewportB)
      await setBadgeWidth(thirdWidth)

      await activateViewport(page, adapter.defaultViewport)
      await expect(resetButton).toHaveText('Reset all')
    })

    await test.step('"Reset all" clears every override and hides the reset button', async () => {
      await resetButton.click()
      await expect(confirmDialog).toBeVisible()
      await confirmDialog.getByRole('button', { name: 'Reset' }).click()

      await expect(resetButton).toBeHidden()

      // Each previously-overridden viewport now shows the column default again.
      const fullWidth = `${adapter.columnCount}/${adapter.columnCount}`
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

    const fullWidth = `${adapter.columnCount}/${adapter.columnCount}`
    const halfWidth = `${Math.floor(adapter.columnCount / 2)}/${adapter.columnCount}`
    const thirdWidth = `${Math.floor(adapter.columnCount / 3)}/${adapter.columnCount}`

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
        .getByRole('option', { name: halfWidth })
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
        .getByRole('option', { name: thirdWidth })
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
        page.getByRole('heading', { level: 1, name: /E2E Grid Test Page/ }),
      ).toBeVisible()
    })
  })
})
