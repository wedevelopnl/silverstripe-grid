import { expect, test } from '@playwright/test'
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures'
import { releaseDrag, waitForMutationSettlement } from '../helpers/drag'

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

    // The drag overlay rendering is the user-visible activation signal — this
    // assertion replaces a fixed post-activation sleep.
    await expect(page.getByTestId('drag-overlay-row')).toBeVisible()

    // Row 1 hasn't swapped on activation. expect.poll re-reads the live
    // position until it settles, so we assert on the condition rather than
    // sleeping for a guessed reconciliation duration.
    const row1OffsetFromInitial = async () => {
      const box = await rows.nth(0).boundingBox()
      expect(box).not.toBeNull()
      return Math.abs(box!.y - initialY)
    }
    await expect.poll(row1OffsetFromInitial).toBeLessThan(NO_SWAP_TOLERANCE)

    // Move into Row 1's lower quarter (inside rect but before center)
    const row1BoxNow = await rows.nth(0).boundingBox()
    expect(row1BoxNow).not.toBeNull()
    const lowerQuarterY = row1BoxNow!.y + row1BoxNow!.height * 0.75
    await page.mouse.move(fromX, lowerQuarterY, { steps: 10 })

    // Assert no swap: Row 1 stays near its original position (poll retries
    // until dnd-kit has processed the move — no fixed wait needed).
    await expect.poll(row1OffsetFromInitial).toBeLessThan(NO_SWAP_TOLERANCE)

    // Cross Row 1's center: move well above its vertical midpoint
    const aboveCenterY = row1BoxNow!.y + row1BoxNow!.height * 0.2
    await page.mouse.move(fromX, aboveCenterY, { steps: 10 })

    // Assert swap: Row 1 pushed down by CSS transform (full row height, well
    // beyond tolerance). Poll until the transform-driven shift lands.
    await expect
      .poll(async () => {
        const box = await rows.nth(0).boundingBox()
        expect(box).not.toBeNull()
        return box!.y
      })
      .toBeGreaterThan(initialY + NO_SWAP_TOLERANCE)

    // The release commits the swap (Row 2 crossed Row 1's center above), so
    // treat it as a real drop: releaseDrag for Firefox pointerup delivery,
    // then settle the mutation and verify the swap actually landed.
    const settle = waitForMutationSettlement(page)
    await releaseDrag(page, fromX, aboveCenterY)
    await expect(page.getByTestId('drag-overlay-row')).toBeHidden()
    await settle()
    await expect(rows.getByTestId('row-title')).toHaveText(['Row 2', 'Row 1'])
  })
})
