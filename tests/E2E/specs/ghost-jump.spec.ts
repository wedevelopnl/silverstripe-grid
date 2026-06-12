import { expect, test } from '@playwright/test'
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures'

/**
 * Regression test for ghost-jump bug: with exactly 2 sibling rows, starting
 * a drag should not cause the other row to visually swap position until the
 * dragged item's center crosses the other item's center.
 *
 * Uses a tolerance for "no swap" assertions because dnd-kit's activation
 * causes minor layout shifts (~3px) from transforms on the dragged item.
 * The ghost jump bug causes a full row-height shift (~80px+), so a 10px
 * tolerance safely distinguishes between layout jitter and the actual bug.
 */
test.describe('Ghost jump regression', () => {
  // Max pixel drift that counts as "no swap" (layout jitter, not ghost jump)
  const NO_SWAP_TOLERANCE = 10

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('row does not swap until dragged center crosses sibling center', async ({ page }) => {
    await loadAndNavigate(page, 'ghost-jump')

    // Verify initial order: Row 1 first, Row 2 second
    const rows = page.getByTestId('row-block')
    await expect(rows).toHaveCount(2)

    const row1Title = rows.nth(0).getByTestId('row-title')
    const row2Title = rows.nth(1).getByTestId('row-title')
    await expect(row1Title).toHaveText('Row 1')
    await expect(row2Title).toHaveText('Row 2')

    // Locate Row 2's drag handle and ensure it's visible. scrollIntoViewIfNeeded
    // can scroll the CMS content panel when the handle is at the viewport edge,
    // so we measure Row 1's baseline position AFTER scrolling to avoid a false
    // positive from scroll-induced Y shift.
    const row2Handle = page.locator('[data-testid="drag-handle"][aria-label="Move Row 2"]')
    await row2Handle.scrollIntoViewIfNeeded()
    const handleBox = await row2Handle.boundingBox()
    expect(handleBox).not.toBeNull()

    // Record Row 1's vertical position after any scroll has settled
    const row1Box = await rows.nth(0).boundingBox()
    expect(row1Box).not.toBeNull()
    const initialY = row1Box!.y

    const fromX = handleBox!.x + handleBox!.width / 2
    const fromY = handleBox!.y + handleBox!.height / 2

    // Activate drag: press and move 10px upward (exceeds 8px PointerSensor threshold)
    await page.mouse.move(fromX, fromY)
    await page.mouse.down()
    await page.mouse.move(fromX, fromY - 10, { steps: 3 })
    await page.waitForTimeout(150)

    // Assert activation: overlay visible, Row 1 hasn't swapped
    await expect(page.getByTestId('drag-overlay-row')).toBeVisible()
    const afterActivation = await rows.nth(0).boundingBox()
    expect(afterActivation).not.toBeNull()
    expect(Math.abs(afterActivation!.y - initialY)).toBeLessThan(NO_SWAP_TOLERANCE)

    // Move into Row 1's lower quarter (inside rect but before center)
    const row1BoxNow = await rows.nth(0).boundingBox()
    expect(row1BoxNow).not.toBeNull()
    const lowerQuarterY = row1BoxNow!.y + row1BoxNow!.height * 0.75
    await page.mouse.move(fromX, lowerQuarterY, { steps: 10 })
    await page.waitForTimeout(150)

    // Assert no swap: Row 1 still near original position
    const afterLowerQuarter = await rows.nth(0).boundingBox()
    expect(afterLowerQuarter).not.toBeNull()
    expect(Math.abs(afterLowerQuarter!.y - initialY)).toBeLessThan(NO_SWAP_TOLERANCE)

    // Cross Row 1's center: move well above its vertical midpoint
    const aboveCenterY = row1BoxNow!.y + row1BoxNow!.height * 0.2
    await page.mouse.move(fromX, aboveCenterY, { steps: 10 })
    await page.waitForTimeout(150)

    // Assert swap: Row 1 pushed down by CSS transform (full row height, well beyond tolerance)
    const afterCrossing = await rows.nth(0).boundingBox()
    expect(afterCrossing).not.toBeNull()
    expect(afterCrossing!.y).toBeGreaterThan(initialY + NO_SWAP_TOLERANCE)

    // Cleanup: release mouse
    await page.mouse.up()
    await page.waitForTimeout(150)
  })
})
