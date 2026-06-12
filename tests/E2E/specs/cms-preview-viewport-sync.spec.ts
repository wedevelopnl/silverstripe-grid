import { expect, test } from '@playwright/test'
import {
  type AdapterConfig,
  activateViewport,
  readAdapterConfig,
  twoNonDefaultViewports,
  viewportButton,
} from '../helpers/adapter'
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures'
import { forceSplitViewMode } from '../helpers/preview'

/**
 * Editor tunes a responsive layout across framework breakpoints with the
 * CMS preview bar synced to the in-editor ViewportSwitcher.
 *
 * Clicking a viewport in either surface updates the other AND actually
 * resizes the preview iframe — proving the store → React consumer path
 * and the vendor `changeSize` piggyback both wire up end-to-end.
 *
 * Adapter-agnostic: reads the active adapter's viewport set at test
 * time, picks default + two non-defaults, and computes expected iframe
 * dimensions from the adapter's own `minWidth` values.
 */

// Split mode requires a minimum viewport width — the CMS applies a
// `split-disabled` guard on narrower screens. Override Playwright's default.
test.use({ viewport: { width: 1600, height: 900 } })

/**
 * Matches the bridge's widthForKey + heightForWidth formulas. Kept here
 * so the spec fails if someone quietly changes either formula without
 * updating the test.
 */
const MOBILE_FIRST_PREVIEW_WIDTH = 375

function expectedDeviceSize(
  adapter: AdapterConfig,
  key: string,
): { width: number; height: number } {
  const vp = adapter.viewports.find((v) => v.key === key)
  if (vp === undefined) {
    throw new Error(`Viewport "${key}" not in adapter config.`)
  }
  const width = vp.minWidth > 0 ? vp.minWidth : MOBILE_FIRST_PREVIEW_WIDTH
  const height = Math.min(900, Math.max(500, Math.round(width * 0.75)))
  return { width, height }
}

test.describe('CMS preview viewport sync', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor tunes responsive layout across breakpoints and preview rescales', async ({
    page,
  }) => {
    await loadAndNavigate(page, 'element-tree')
    await forceSplitViewMode(page)

    const adapter = await readAdapterConfig(page)
    const [viewportA, viewportB] = twoNonDefaultViewports(adapter)
    const cmsSelector = page.getByTestId('cms-preview-viewport-selector')

    // CMS bar buttons use the same accessible name as the editor — the
    // viewport's label from adapter config. We look them up by label so
    // the spec doesn't assume a specific adapter's vocabulary.
    const cmsButton = (key: string) => {
      const label = adapter.viewports.find((v) => v.key === key)?.label
      if (label === undefined) {
        throw new Error(`Viewport "${key}" not in adapter config.`)
      }
      return cmsSelector.getByRole('button', { name: label, exact: true })
    }

    // Read the iframe's rendered dimensions. Our bridge drives these
    // via the injected stylesheet — asserting on the actual bounding box
    // catches regressions where styles inject but don't apply.
    const deviceSize = () =>
      page.evaluate(() => {
        const iframe = document.querySelector<HTMLIFrameElement>(
          'iframe[name="cms-preview-iframe"]',
        )
        if (iframe === null) return null
        const rect = iframe.getBoundingClientRect()
        return { width: Math.round(rect.width), height: Math.round(rect.height) }
      })

    await test.step('split mode renders both selectors at the adapter default', async () => {
      await expect(page.getByTestId('viewport-switcher')).toBeVisible()
      await expect(cmsSelector).toBeVisible({ timeout: 15_000 })

      await expect(viewportButton(page, adapter.defaultViewport)).toHaveAttribute(
        'aria-pressed',
        'true',
      )
      await expect(cmsButton(adapter.defaultViewport)).toHaveAttribute('aria-pressed', 'true')

      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual(expectedDeviceSize(adapter, adapter.defaultViewport))
    })

    await test.step('switching viewport in the editor drives the CMS bar and rescales preview', async () => {
      await activateViewport(page, viewportA)

      await expect(cmsButton(viewportA)).toHaveAttribute('aria-pressed', 'true')
      await expect(cmsButton(adapter.defaultViewport)).toHaveAttribute('aria-pressed', 'false')

      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual(expectedDeviceSize(adapter, viewportA))
    })

    await test.step('switching viewport in the CMS bar drives the editor and rescales preview', async () => {
      await cmsButton(viewportB).click()

      await expect(viewportButton(page, viewportB)).toHaveAttribute('aria-pressed', 'true')
      await expect(viewportButton(page, viewportA)).toHaveAttribute('aria-pressed', 'false')

      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual(expectedDeviceSize(adapter, viewportB))
    })

    await test.step('surviving a content-area swap remounts the selector and keeps sync working', async () => {
      // Simulate a CMS action that swaps the content area (save/publish
      // Pjax). A full reload is the strongest version — if the bridge
      // survives this, lighter DOM churn is covered by construction.
      await page.reload({ waitUntil: 'load' })
      await forceSplitViewMode(page)

      await expect(cmsSelector).toBeVisible({ timeout: 15_000 })

      // Sync still works after the remount — switch via the CMS bar and
      // confirm the editor + iframe follow through the fresh React root.
      await cmsButton(viewportA).click()
      await expect(viewportButton(page, viewportA)).toHaveAttribute('aria-pressed', 'true')
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual(expectedDeviceSize(adapter, viewportA))
    })
  })
})
