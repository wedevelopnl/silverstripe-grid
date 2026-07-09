import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures'
import {
  activateDragByTitle,
  dropAndSettle,
  enterContainerCenter,
  watchReorderRequests,
} from '../helpers/drag'

/**
 * Cross-row column drop positions — journey tests.
 *
 * Verifies that columns dragged between rows land at the exact target
 * position (before first, between any pair, after last) in both directions.
 * Columns use horizontal layout (X-axis), so direction-aware placement
 * compares pointer X position against the 'over' element's center X.
 */

function getRow(page: Page, rowTitle: string) {
  return page.getByTestId('row-block').filter({ hasText: rowTitle })
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
    // The droppable rect is the dnd-kit SortableContext wrapper (the column-
    // block's parent). We need its box for X-axis drop positioning, so we
    // locate it directly via its own testid rather than the inner column-block.
    const outer = targetRow.getByTestId('column-block-outer').filter({
      has: page.locator(`[aria-label="Collapse ${colTitle}"], [aria-label="Expand ${colTitle}"]`),
    })
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
    await expect(rowA.getByTestId('column-title')).toHaveText(['Col A1', 'Col A2', 'Col A3'])
    await expect(rowB.getByTestId('column-title')).toHaveText(['Col B1', 'Col B2', 'Col B3'])

    // Row A [A1, A2, A3]  →  Row A [A2, A3]
    // Row B [B1, B2, B3]  →  Row B [*A1*, B1, B2, B3]
    await test.step('Forward, before-first: A1 → Row B before B1', async () => {
      await activateDragByTitle(page, 'Col A1', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowB, 'column-block', 4)
      const pos = await colPosition(page, rowB, { before: 'Col B1' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(rowB.getByTestId('column-title')).toHaveText([
        'Col A1',
        'Col B1',
        'Col B2',
        'Col B3',
      ])
    })

    // Row B [A1, B1, B2, B3]  →  Row B [A1, B1, B2]
    // Row A [A2, A3]           →  Row A [A2, *B3*, A3]
    await test.step('Reverse, between: B3 → Row A between A2,A3', async () => {
      await activateDragByTitle(page, 'Col B3', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowA, 'column-block', 3)
      const pos = await colPosition(page, rowA, { between: ['Col A2', 'Col A3'] })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(rowA.getByTestId('column-title')).toHaveText(['Col A2', 'Col B3', 'Col A3'])
    })

    // Row B [A1, B1, B2]        →  Row B [A1, B1]
    // Row A [A2, B3, A3]        →  Row A [A2, B3, A3, *B2*]
    await test.step('Reverse, after-last: B2 → Row A after A3', async () => {
      await activateDragByTitle(page, 'Col B2', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowA, 'column-block', 4)
      const pos = await colPosition(page, rowA, { after: 'Col A3' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(rowA.getByTestId('column-title')).toHaveText([
        'Col A2',
        'Col B3',
        'Col A3',
        'Col B2',
      ])
    })

    // Row A [A2, B3, A3, B2]  →  Row A [A2, B3, A3]
    // Row B [A1, B1]           →  Row B [A1, *B2*, B1]
    await test.step('Forward, between: B2 → Row B between A1,B1', async () => {
      await activateDragByTitle(page, 'Col B2', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowB, 'column-block', 3)
      const pos = await colPosition(page, rowB, { between: ['Col A1', 'Col B1'] })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(rowB.getByTestId('column-title')).toHaveText(['Col A1', 'Col B2', 'Col B1'])
    })

    // Row A [A2, B3, A3]     →  Row A [A2, B3]
    // Row B [A1, B2, B1]     →  Row B [A1, B2, B1, *A3*]
    await test.step('Forward, after-last: A3 → Row B after B1', async () => {
      await activateDragByTitle(page, 'Col A3', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowB, 'column-block', 4)
      const pos = await colPosition(page, rowB, { after: 'Col B1' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(rowB.getByTestId('column-title')).toHaveText([
        'Col A1',
        'Col B2',
        'Col B1',
        'Col A3',
      ])
    })

    // Row A [A2, B3]  →  Row A [*B3*, A2]
    await test.step('Intra-container: B3 before A2 in Row A', async () => {
      await activateDragByTitle(page, 'Col B3', {
        axis: 'horizontal',
        overlayTestId: 'drag-overlay-column',
      })
      const pos = await colPosition(page, rowA, { before: 'Col A2' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(rowA.getByTestId('column-title')).toHaveText(['Col B3', 'Col A2'])
    })

    // Verify persistence after reload
    await page.reload()
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const rowAReloaded = getRow(page, 'Row A')
    const rowBReloaded = getRow(page, 'Row B')
    await expect(rowAReloaded.getByTestId('column-title')).toHaveText(['Col B3', 'Col A2'])
    await expect(rowBReloaded.getByTestId('column-title')).toHaveText([
      'Col A1',
      'Col B2',
      'Col B1',
      'Col A3',
    ])
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
      await enterContainerCenter(page, rowB, 'column-block', 4)
      const pos = await colPosition(page, rowB, { between: ['Col B1', 'Col B2'] })
      await dropAndSettle(page, pos.x, pos.y)

      await expect(rowA.getByTestId('column-block')).toHaveCount(0)
      await expect(rowB.getByTestId('column-title')).toHaveText([
        'Col B1',
        'Col A1',
        'Col B2',
        'Col B3',
      ])

      // Verify persistence
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      const rowBReloaded = getRow(page, 'Row B')
      await expect(rowBReloaded.getByTestId('column-title')).toHaveText([
        'Col B1',
        'Col A1',
        'Col B2',
        'Col B3',
      ])
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
      await enterContainerCenter(page, rowA, 'column-block', 4)
      const pos = await colPosition(page, rowA, { after: 'Col A3' })
      await dropAndSettle(page, pos.x, pos.y)

      await expect(rowA.getByTestId('column-title')).toHaveText([
        'Col A1',
        'Col A2',
        'Col A3',
        'Col B1',
      ])
      await expect(rowB.getByTestId('column-block')).toHaveCount(0)

      // Verify persistence
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      const rowAReloaded = getRow(page, 'Row A')
      await expect(rowAReloaded.getByTestId('column-title')).toHaveText([
        'Col A1',
        'Col A2',
        'Col A3',
        'Col B1',
      ])
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
      await enterContainerCenter(page, rowB, 'column-block', 4)

      await page.keyboard.press('Escape')

      await expect(rowA.getByTestId('column-title')).toHaveText(['Col A1', 'Col A2', 'Col A3'])
      await expect(rowB.getByTestId('column-title')).toHaveText(['Col B1', 'Col B2', 'Col B3'])
      expect(reorderWatch.count()).toBe(0)
      reorderWatch.stop()
    })
  })
})
