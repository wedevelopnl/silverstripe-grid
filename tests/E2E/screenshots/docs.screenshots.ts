import { expect, type Locator, type Page, test } from '@playwright/test'
import { readAdapterConfig } from '../helpers/adapter'
import { loadAndNavigate } from '../helpers/fixtures'

/**
 * Regenerates the images embedded in README.md and docs/usage/grid-editor.md.
 *
 * Run with `npm run docs:screenshots` (not part of `task test-e2e` — see
 * playwright.docs.config.ts). Every capture comes from the `docs-page`
 * fixture, so re-running after a UI change refreshes all images consistently.
 * These are captures, not assertions: the expect() calls exist only to wait
 * for the UI to settle before the shutter.
 */

/**
 * Relative to the working directory, which is the repository root — the only
 * place `npm run docs:screenshots` is ever started from. Playwright creates
 * missing directories when it writes a screenshot.
 */
const OUT_DIR = 'docs/images'

/** Pixels of breathing room left around a clipped region. */
const PADDING = 12

/**
 * Tall window for the cropped captures.
 *
 * A clip is clamped to the viewport, so the window has to be at least as tall
 * as the largest region being cropped — the services section runs to roughly
 * 900 CSS pixels inside the CMS content panel.
 */
const TALL = { width: 1500, height: 1400 }

/**
 * Load the docs fixture and open its page in the CMS with the preview panel
 * closed, so the grid editor gets the full content width.
 *
 * The CMS persists the preview mode in localStorage and reads it during init,
 * so the value has to be set against a loaded CMS origin and picked up by a
 * reload.
 */
async function openDocsPage(page: Page): Promise<void> {
  await loadAndNavigate(page, 'docs-page')
  await page.evaluate(() => {
    window.localStorage.setItem('cms-preview-state-mode', 'content')
  })
  await page.reload({ waitUntil: 'load' })
  await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
  await expect(page.getByTestId('grid-editor')).toBeVisible()
  await assertBootstrapAdapter(page)
}

/**
 * Fail the capture unless the dev environment runs the Bootstrap preset.
 *
 * `.docker/env.sh` seeds `SS_GRID_ADAPTER=tailwind`, but the editor guide names
 * Bootstrap's column count and viewports in its prose and in the fixture's
 * `xs` overrides. Regenerating under another adapter would silently produce
 * images that contradict the text, so refuse instead.
 */
async function assertBootstrapAdapter(page: Page): Promise<void> {
  const config = await readAdapterConfig(page)
  if (config.columnCount !== 12 || config.defaultViewport !== 'md') {
    throw new Error(
      `docs/images/ are captured against the Bootstrap preset (12 columns, default "md"), ` +
        `but this environment reports ${String(config.columnCount)} columns and default ` +
        `"${config.defaultViewport}". Set SS_GRID_ADAPTER=bootstrap in .docker/.env, ` +
        `restart the app container, and re-run.`,
    )
  }
}

/**
 * Capture the region covering every locator, padded and clamped to the viewport.
 *
 * Takes a list rather than a single target because the interesting shots have
 * an open dropdown or menu: those are positioned outside their anchor's
 * bounding box, so cropping to the anchor alone would cut them off.
 */
async function shoot(page: Page, name: string, targets: Locator[]): Promise<void> {
  const boxes: { x: number; y: number; width: number; height: number }[] = []
  for (const target of targets) {
    const box = await target.boundingBox()
    if (box === null) {
      throw new Error(`Cannot screenshot "${name}": a target has no bounding box`)
    }
    boxes.push(box)
  }

  const viewport = page.viewportSize()
  if (viewport === null) {
    throw new Error(`Cannot screenshot "${name}": no viewport size`)
  }

  const left = Math.max(0, Math.min(...boxes.map((box) => box.x)) - PADDING)
  const top = Math.max(0, Math.min(...boxes.map((box) => box.y)) - PADDING)
  const right = Math.max(...boxes.map((box) => box.x + box.width)) + PADDING
  const bottom = Math.max(...boxes.map((box) => box.y + box.height)) + PADDING

  await page.screenshot({
    path: `${OUT_DIR}/${name}.png`,
    clip: {
      x: left,
      y: top,
      width: Math.min(right - left, viewport.width - left),
      height: Math.min(bottom - top, viewport.height - top),
    },
  })
}

/** The "What we do" section: a centred 8/12 intro row above a 4+4+4 card row. */
function servicesSection(page: Page): Locator {
  return page.getByTestId('section-block').nth(1)
}

test('cms context', async ({ page }) => {
  await openDocsPage(page)

  // Scroll the multi-column section into view so the shot shows the grid
  // rather than the single full-width hero row.
  await servicesSection(page).scrollIntoViewIfNeeded()
  await expect(servicesSection(page).getByTestId('column-block').first()).toBeVisible()

  await page.screenshot({ path: `${OUT_DIR}/cms-context.png` })
})

test('editor overview', async ({ page }) => {
  await page.setViewportSize(TALL)
  await openDocsPage(page)

  const section = servicesSection(page)
  await section.scrollIntoViewIfNeeded()
  await expect(section.getByTestId('column-block').first()).toBeVisible()

  await shoot(page, 'editor-overview', [section])
})

test('element type picker', async ({ page }) => {
  await openDocsPage(page)

  await page.getByTestId('add-content-button').first().click()
  const picker = page.getByTestId('element-type-picker')
  await expect(picker).toBeVisible()

  await shoot(page, 'element-type-picker', [picker])
})

test('column width and offset', async ({ page }) => {
  await page.setViewportSize(TALL)
  await openDocsPage(page)

  const column = page.getByTestId('column-block').first()
  await column.getByTestId('column-badge').click()
  const listbox = page.getByTestId('column-badge-listbox')
  await expect(listbox).toBeVisible()

  await shoot(page, 'column-width', [column.getByTestId('column-header'), listbox])
})

test('viewport picker', async ({ page }) => {
  await page.setViewportSize(TALL)
  await openDocsPage(page)

  const trigger = page.getByTestId('viewport-picker-trigger')
  await trigger.click()
  const dropdown = page.getByTestId('viewport-picker-dropdown')
  await expect(dropdown).toBeVisible()

  await shoot(page, 'viewport-picker', [trigger, dropdown])
})

test('publish status badges', async ({ page }) => {
  await page.setViewportSize(TALL)
  await openDocsPage(page)

  const section = page.getByTestId('section-block').last()
  await section.scrollIntoViewIfNeeded()
  await expect(section.getByTestId('element-card').first()).toBeVisible()

  await shoot(page, 'status-badges', [section])
})

test('element actions menu', async ({ page }) => {
  await page.setViewportSize(TALL)
  await openDocsPage(page)

  // The last card sits in a narrow column, where ElementActions folds the
  // whole action set into the overflow menu instead of an icon row — which is
  // what makes the actions legible in a screenshot.
  const card = page.getByTestId('element-card').last()
  await card.scrollIntoViewIfNeeded()
  await card.getByTestId('actions-menu-trigger').click()
  const menu = page.getByRole('menu')
  await expect(menu).toBeVisible()

  await shoot(page, 'element-actions', [card, menu])
})
