import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures'
import { activateDragByTitle, dropAndSettle, watchReorderRequests } from '../helpers/drag'

/**
 * Cross-row column drop positions — journey tests.
 *
 * Verifies that columns dragged between rows land at the exact target
 * position (before first, between any pair, after last) in both directions.
 * Columns use horizontal layout (X-axis), so direction-aware placement
 * compares pointer X position against the 'over' element's center X.
 */
// --- Hierarchy-specific helpers (shared across the journey describes) ---

function getRow(page: Page, rowTitle: string) {
  return page.getByTestId('row-block').filter({ hasText: rowTitle })
}

/** Extract column titles from collapse-toggle aria-labels within a row. */
function getColumnTitles(rowLocator: Locator): Promise<string[]> {
  return rowLocator
    .getByTestId('column-block')
    .getByTestId('collapse-toggle')
    .evaluateAll((els) =>
      els.map((el) => (el.getAttribute('aria-label') ?? '').replace(/^(Collapse |Expand )/, '')),
    )
}

/**
 * Enter the target row by moving the pointer to the row's center.
 * Uses the container center (not a specific child) so the entry trajectory
 * reliably triggers collision detection regardless of the pointer's starting
 * position — critical in journey tests where multiple prior operations leave
 * the pointer at unpredictable coordinates.
 */
async function enterRow(page: Page, targetRow: Locator, expectedColCount: number) {
  await targetRow.scrollIntoViewIfNeeded()
  const box = await targetRow.boundingBox()
  expect(box).not.toBeNull()
  await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 })
  await expect(targetRow.getByTestId('column-block')).toHaveCount(expectedColCount)
}

/**
 * Locate a column within a row by its title (via collapse-toggle aria-label).
 */
function findColumn(page: Page, targetRow: Locator, colTitle: string) {
  return targetRow.getByTestId('column-block').filter({
    has: page.locator(`[aria-label="Collapse ${colTitle}"], [aria-label="Expand ${colTitle}"]`),
  })
}

/**
 * Compute drop coordinates for a target position within a row.
 * Uses column TITLES to locate columns, avoiding interference from the
 * active/dragging item which is present in the DOM at opacity 0.3.
 *
 * The droppable rect is the OUTER grid cell (ref={setNodeRef}), but
 * column-block is the INNER div. To reliably place before/after, we use
 * the outer wrapper's bounding box (parent of column-block) for X positioning.
 *
 * Positioning strategy (X-axis for horizontal layout):
 * - "before": left 15% of the outer grid cell (left of center → "before")
 * - "after": right 65% of the outer grid cell (right of center → "after")
 * - "between": right 85% of the first (left) outer grid cell
 */
async function colPosition(
  page: Page,
  targetRow: Locator,
  position: { before: string } | { after: string } | { between: [string, string] },
) {
  async function getOuterBox(colTitle: string) {
    const col = findColumn(page, targetRow, colTitle)
    // The droppable rect is the dnd-kit SortableContext wrapper (the column-
    // block's parent), which has no semantic role or test hook of its own —
    // it exists purely to host the drop target. We need its box for X-axis
    // drop positioning, so we reach it structurally via the parent selector.
    const outer = col.locator('..')
    const box = await outer.boundingBox()
    expect(box).not.toBeNull()
    return box!
  }

  if ('before' in position) {
    const box = await getOuterBox(position.before)
    return { x: box.x + box.width * 0.15, y: box.y + box.height / 2 }
  }
  if ('after' in position) {
    const box = await getOuterBox(position.after)
    return { x: box.x + box.width * 0.65, y: box.y + box.height / 2 }
  }
  const box1 = await getOuterBox(position.between[0])
  return { x: box1.x + box1.width * 0.85, y: box1.y + box1.height / 2 }
}

test.describe('Cross-row column drop — both directions', () => {
  test.use({ viewport: { width: 1280, height: 1400 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('moves columns across rows in both directions', async ({ page }) => {
    await loadAndNavigate(page, 'cross-row-column-drop')
    const rowA = getRow(page, 'Row A')
    const rowB = getRow(page, 'Row B')

    // Row A [A1, A2, A3]   Row B [B1, B2, B3]
    await expect.poll(() => getColumnTitles(rowA)).toEqual(['Col A1', 'Col A2', 'Col A3'])
    await expect.poll(() => getColumnTitles(rowB)).toEqual(['Col B1', 'Col B2', 'Col B3'])

    // Row A [A1, A2, A3]  →  Row A [A2, A3]
    // Row B [B1, B2, B3]  →  Row B [*A1*, B1, B2, B3]
    await test.step('Forward, before-first: A1 → Row B before B1', async () => {
      await activateDragByTitle(page, 'Col A1', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowB, 4)
      const pos = await colPosition(page, rowB, { before: 'Col B1' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect
        .poll(() => getColumnTitles(rowB))
        .toEqual(['Col A1', 'Col B1', 'Col B2', 'Col B3'])
    })

    // Row B [A1, B1, B2, B3]  →  Row B [A1, B1, B2]
    // Row A [A2, A3]           →  Row A [A2, *B3*, A3]
    await test.step('Reverse, between: B3 → Row A between A2,A3', async () => {
      await activateDragByTitle(page, 'Col B3', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowA, 3)
      const pos = await colPosition(page, rowA, { between: ['Col A2', 'Col A3'] })
      await dropAndSettle(page, pos.x, pos.y)
      await expect.poll(() => getColumnTitles(rowA)).toEqual(['Col A2', 'Col B3', 'Col A3'])
    })

    // Row B [A1, B1, B2]        →  Row B [A1, B1]
    // Row A [A2, B3, A3]        →  Row A [A2, B3, A3, *B2*]
    await test.step('Reverse, after-last: B2 → Row A after A3', async () => {
      await activateDragByTitle(page, 'Col B2', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowA, 4)
      const pos = await colPosition(page, rowA, { after: 'Col A3' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect
        .poll(() => getColumnTitles(rowA))
        .toEqual(['Col A2', 'Col B3', 'Col A3', 'Col B2'])
    })

    // Row A [A2, B3, A3, B2]  →  Row A [A2, B3, A3]
    // Row B [A1, B1]           →  Row B [A1, *B2*, B1]
    await test.step('Forward, between: B2 → Row B between A1,B1', async () => {
      await activateDragByTitle(page, 'Col B2', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowB, 3)
      const pos = await colPosition(page, rowB, { between: ['Col A1', 'Col B1'] })
      await dropAndSettle(page, pos.x, pos.y)
      await expect.poll(() => getColumnTitles(rowB)).toEqual(['Col A1', 'Col B2', 'Col B1'])
    })

    // Row A [A2, B3, A3]     →  Row A [A2, B3]
    // Row B [A1, B2, B1]     →  Row B [A1, B2, B1, *A3*]
    await test.step('Forward, after-last: A3 → Row B after B1', async () => {
      await activateDragByTitle(page, 'Col A3', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowB, 4)
      const pos = await colPosition(page, rowB, { after: 'Col B1' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect
        .poll(() => getColumnTitles(rowB))
        .toEqual(['Col A1', 'Col B2', 'Col B1', 'Col A3'])
    })

    // Row A [A2, B3]  →  Row A [*B3*, A2]
    await test.step('Intra-container: B3 before A2 in Row A', async () => {
      await activateDragByTitle(page, 'Col B3', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      const pos = await colPosition(page, rowA, { before: 'Col A2' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect.poll(() => getColumnTitles(rowA)).toEqual(['Col B3', 'Col A2'])
    })

    // Verify persistence after reload
    await page.reload()
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const rowAReloaded = getRow(page, 'Row A')
    const rowBReloaded = getRow(page, 'Row B')
    await expect.poll(() => getColumnTitles(rowAReloaded)).toEqual(['Col B3', 'Col A2'])
    await expect
      .poll(() => getColumnTitles(rowBReloaded))
      .toEqual(['Col A1', 'Col B2', 'Col B1', 'Col A3'])
  })
})

test.describe('Cross-row column drop — source depletion and cancel', () => {
  test.use({ viewport: { width: 1280, height: 1400 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('handles edge cases: source depletion and cancel mid-drag', async ({ page }) => {
    // Row A [A1]           →  Row A []
    // Row B [B1, B2, B3]  →  Row B [B1, *A1*, B2, B3]
    await test.step('Source depletion: move lone column to target', async () => {
      await loadAndNavigate(page, 'cross-row-column-drop-single')
      const rowA = getRow(page, 'Row A')
      const rowB = getRow(page, 'Row B')
      await expect(rowA.getByTestId('column-block')).toHaveCount(1)

      await activateDragByTitle(page, 'Col A1', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowB, 4)
      const pos = await colPosition(page, rowB, { between: ['Col B1', 'Col B2'] })
      await dropAndSettle(page, pos.x, pos.y)

      await expect(rowA.getByTestId('column-block')).toHaveCount(0)
      await expect
        .poll(() => getColumnTitles(rowB))
        .toEqual(['Col B1', 'Col A1', 'Col B2', 'Col B3'])

      // Verify persistence
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      const rowBReloaded = getRow(page, 'Row B')
      await expect
        .poll(() => getColumnTitles(rowBReloaded))
        .toEqual(['Col B1', 'Col A1', 'Col B2', 'Col B3'])
    })

    // Row A [A1, A2, A3]  →  Row A [A1, A2, A3, *B1*]
    // Row B [B1]           →  Row B []
    await test.step('Source depletion reverse: lone column B1 → Row A after A3', async () => {
      await loadAndNavigate(page, 'cross-row-column-drop-single-reverse')
      const rowA = getRow(page, 'Row A')
      const rowB = getRow(page, 'Row B')
      await expect(rowA.getByTestId('column-block')).toHaveCount(3)
      await expect(rowB.getByTestId('column-block')).toHaveCount(1)

      await activateDragByTitle(page, 'Col B1', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowA, 4)
      const pos = await colPosition(page, rowA, { after: 'Col A3' })
      await dropAndSettle(page, pos.x, pos.y)

      await expect
        .poll(() => getColumnTitles(rowA))
        .toEqual(['Col A1', 'Col A2', 'Col A3', 'Col B1'])
      await expect(rowB.getByTestId('column-block')).toHaveCount(0)

      // Verify persistence
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      const rowAReloaded = getRow(page, 'Row A')
      await expect
        .poll(() => getColumnTitles(rowAReloaded))
        .toEqual(['Col A1', 'Col A2', 'Col A3', 'Col B1'])
    })

    // Drag A1 into Row B, press Escape → both rows revert to initial state
    await test.step('Cancel mid-drag reverts to original state', async () => {
      const reorderWatch = watchReorderRequests(page)
      await loadAndNavigate(page, 'cross-row-column-drop')
      const rowA = getRow(page, 'Row A')
      const rowB = getRow(page, 'Row B')

      await activateDragByTitle(page, 'Col A1', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterRow(page, rowB, 4)

      await page.keyboard.press('Escape')

      await expect.poll(() => getColumnTitles(rowA)).toEqual(['Col A1', 'Col A2', 'Col A3'])
      await expect.poll(() => getColumnTitles(rowB)).toEqual(['Col B1', 'Col B2', 'Col B3'])
      expect(reorderWatch.count()).toBe(0)
      reorderWatch.stop()
    })
  })
})
