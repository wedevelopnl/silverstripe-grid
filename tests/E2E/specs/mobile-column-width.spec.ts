import { expect, type Page, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

/**
 * Rendered column geometry below the first breakpoint.
 *
 * The row wrapper declares its grid unconditionally (`getRowClasses()` has no
 * responsive variant), so a column whose only width class carries a breakpoint
 * prefix matches no rule on a phone and falls back to `grid-column: auto` — one
 * track out of `columnCount`, under 10% of the row. This spec pins the geometry
 * rather than the class names: it loads the published page through the
 * testbed's real framework stylesheet and measures what the browser lays out.
 *
 * The fixture's columns are half width at the smallest viewport and full width
 * by default, so the ratio flips across the second breakpoint. Both widths are
 * well below every preset's second breakpoint (Bootstrap `sm` 576, Tailwind
 * `sm` 640, Bulma `tablet` 769) and well above it respectively, so the same
 * assertions hold for whichever adapter the CI matrix is running.
 */
const PHONE = { width: 390, height: 844 }
const DESKTOP = { width: 1280, height: 900 }

/** Absolute tolerance on a width ratio, covering borders and sub-pixel rounding. */
const TOLERANCE = 0.02

async function columnWidthRatios(page: Page): Promise<number[]> {
  const row = page.locator('[data-element="row"]').first()
  const columns = row.locator('[data-element="column"]')

  const rowBox = await row.boundingBox()
  if (rowBox === null) {
    throw new Error('Row is not visible on the rendered page.')
  }

  const boxes = await columns.evaluateAll((nodes) =>
    nodes.map((node) => node.getBoundingClientRect().width),
  )

  return boxes.map((width) => width / rowBox.width)
}

test.describe('Rendered column width across the first breakpoint', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('the smallest viewport sizes columns on a phone, the default takes over above it', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'mobile-column-width')

    await test.step('phone: the smallest-viewport override halves each column', async () => {
      await page.setViewportSize(PHONE)
      await page.goto(fixture.pageUrl)
      await expect(page.getByText('Content element 1')).toBeVisible()

      const ratios = await columnWidthRatios(page)

      expect(ratios).toHaveLength(2)
      for (const ratio of ratios) {
        expect(ratio).toBeGreaterThan(0.5 - TOLERANCE)
        expect(ratio).toBeLessThan(0.5 + TOLERANCE)
      }
    })

    await test.step('desktop: the full-width default reasserts and the columns stack', async () => {
      await page.setViewportSize(DESKTOP)

      const ratios = await columnWidthRatios(page)

      expect(ratios).toHaveLength(2)
      for (const ratio of ratios) {
        expect(ratio).toBeGreaterThan(1 - TOLERANCE)
      }
    })
  })
})
