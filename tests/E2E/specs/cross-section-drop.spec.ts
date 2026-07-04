import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures'
import { activateDragByTitle, dropAndSettle, watchReorderRequests } from '../helpers/drag'

/**
 * Cross-section row drop positions — journey tests.
 *
 * Verifies that rows dragged between sections land at the exact target
 * position (before first, between any pair, after last) in both directions.
 * Uses sequential drag operations within a single fixture load to cover
 * all drop positions and directions efficiently.
 */
// --- Hierarchy-specific helpers (shared across the journey describes) ---

function getSection(page: Page, sectionTitle: string) {
  return page.getByTestId('section-block').filter({ hasText: sectionTitle })
}

/**
 * Enter the target section via its FIRST row center (DOWN direction).
 * The 30-step mouse move triggers cross-container entry via collision detection.
 */
async function enterAtFirst(page: Page, targetSection: Locator, expectedRowCount: number) {
  const firstRow = targetSection.getByTestId('row-block').first()
  await firstRow.scrollIntoViewIfNeeded()
  const box = await firstRow.boundingBox()
  expect(box).not.toBeNull()
  await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 })
  await expect(targetSection.getByTestId('row-block')).toHaveCount(expectedRowCount)
}

/**
 * Enter the target section from below (UP direction).
 * The -40px offset past the last row's center ensures the centerCrossing
 * UP threshold is reliably crossed despite floating-point precision.
 */
async function enterFromBelow(page: Page, targetSection: Locator, expectedRowCount: number) {
  const lastRow = targetSection.getByTestId('row-block').last()
  await lastRow.scrollIntoViewIfNeeded()
  const box = await lastRow.boundingBox()
  expect(box).not.toBeNull()
  await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2 - 40, { steps: 30 })
  await expect(targetSection.getByTestId('row-block')).toHaveCount(expectedRowCount)
}

/**
 * Compute drop coordinates for a target position within a section.
 * Uses row TITLES (not DOM indices) to locate rows, avoiding interference
 * from the active/dragging item which is present in the DOM at opacity 0.3.
 *
 * Positioning strategy:
 * - "before": top quarter of the named row (above center → "before" direction)
 * - "after": bottom quarter of the named row (below center → "after" direction)
 * - "between": bottom 85% of the first (upper) row — stable position above insertion point
 */
async function rowPosition(
  targetSection: Locator,
  position: { before: string } | { after: string } | { between: [string, string] },
) {
  const sectionBox = await targetSection.boundingBox()
  expect(sectionBox).not.toBeNull()
  const centerX = sectionBox!.x + sectionBox!.width / 2

  if ('before' in position) {
    const row = targetSection.getByTestId('row-block').filter({ hasText: position.before })
    const box = await row.boundingBox()
    expect(box).not.toBeNull()
    return { x: centerX, y: box!.y + box!.height * 0.15 }
  }
  if ('after' in position) {
    const row = targetSection.getByTestId('row-block').filter({ hasText: position.after })
    const box = await row.boundingBox()
    expect(box).not.toBeNull()
    return { x: centerX, y: box!.y + box!.height * 0.65 }
  }
  const row1 = targetSection.getByTestId('row-block').filter({ hasText: position.between[0] })
  const box1 = await row1.boundingBox()
  expect(box1).not.toBeNull()
  return { x: centerX, y: box1!.y + box1!.height * 0.85 }
}

test.describe('Cross-section row drop — both directions', () => {
  test.use({ viewport: { width: 1280, height: 2800 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('moves rows across sections in both directions', async ({ page }) => {
    await loadAndNavigate(page, 'cross-section-drop')
    const sectionAlpha = getSection(page, 'Section Alpha')
    const sectionBeta = getSection(page, 'Section Beta')

    // Alpha [A1, A2, A3]   Beta [B1, B2, B3]
    await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row A1', 'Row A2', 'Row A3'])
    await expect(sectionBeta.getByTestId('row-title')).toHaveText(['Row B1', 'Row B2', 'Row B3'])

    // Alpha [A1, A2, A3]  →  Alpha [A2, A3]
    // Beta  [B1, B2, B3]  →  Beta  [*A1*, B1, B2, B3]
    await test.step('Forward, before-first: A1 → Beta before B1', async () => {
      await activateDragByTitle(page, 'Row A1', { overlayTestId: 'drag-overlay-row' })
      await enterAtFirst(page, sectionBeta, 4)
      const pos = await rowPosition(sectionBeta, { before: 'Row B1' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(sectionBeta.getByTestId('row-title')).toHaveText([
        'Row A1',
        'Row B1',
        'Row B2',
        'Row B3',
      ])
    })

    // Beta  [A1, B1, B2, B3]  →  Beta  [A1, B1, B2]
    // Alpha [A2, A3]           →  Alpha [A2, *B3*, A3]
    await test.step('Reverse, between: B3 → Alpha between A2,A3', async () => {
      await activateDragByTitle(page, 'Row B3', { overlayTestId: 'drag-overlay-row' })
      await enterFromBelow(page, sectionAlpha, 3)
      const pos = await rowPosition(sectionAlpha, { between: ['Row A2', 'Row A3'] })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row A2', 'Row B3', 'Row A3'])
    })

    // Beta  [A1, B1, B2]        →  Beta  [A1, B1]
    // Alpha [A2, B3, A3]        →  Alpha [A2, B3, A3, *B2*]
    await test.step('Reverse, after-last: B2 → Alpha after A3', async () => {
      await activateDragByTitle(page, 'Row B2', { overlayTestId: 'drag-overlay-row' })
      await enterFromBelow(page, sectionAlpha, 4)
      const pos = await rowPosition(sectionAlpha, { after: 'Row A3' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(sectionAlpha.getByTestId('row-title')).toHaveText([
        'Row A2',
        'Row B3',
        'Row A3',
        'Row B2',
      ])
    })

    // Alpha [A2, B3, A3, B2]  →  Alpha [A2, B3, A3]
    // Beta  [A1, B1]           →  Beta  [A1, *B2*, B1]
    await test.step('Forward, between: B2 → Beta between A1,B1', async () => {
      await activateDragByTitle(page, 'Row B2', { overlayTestId: 'drag-overlay-row' })
      await enterAtFirst(page, sectionBeta, 3)
      const pos = await rowPosition(sectionBeta, { between: ['Row A1', 'Row B1'] })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(sectionBeta.getByTestId('row-title')).toHaveText(['Row A1', 'Row B2', 'Row B1'])
    })

    // Alpha [A2, B3, A3]     →  Alpha [A2, B3]
    // Beta  [A1, B2, B1]     →  Beta  [A1, B2, B1, *A3*]
    await test.step('Forward, after-last: A3 → Beta after B1', async () => {
      await activateDragByTitle(page, 'Row A3', { overlayTestId: 'drag-overlay-row' })
      await enterAtFirst(page, sectionBeta, 4)
      const pos = await rowPosition(sectionBeta, { after: 'Row B1' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(sectionBeta.getByTestId('row-title')).toHaveText([
        'Row A1',
        'Row B2',
        'Row B1',
        'Row A3',
      ])
    })

    // Alpha [A2, B3]  →  Alpha [*B3*, A2]
    await test.step('Intra-container: B3 before A2 in Alpha', async () => {
      await activateDragByTitle(page, 'Row B3', { overlayTestId: 'drag-overlay-row' })
      const pos = await rowPosition(sectionAlpha, { before: 'Row A2' })
      await dropAndSettle(page, pos.x, pos.y)
      await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row B3', 'Row A2'])
    })

    // Verify persistence after reload
    await page.reload()
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const alphaReloaded = getSection(page, 'Section Alpha')
    const betaReloaded = getSection(page, 'Section Beta')
    await expect(alphaReloaded.getByTestId('row-title')).toHaveText(['Row B3', 'Row A2'])
    await expect(betaReloaded.getByTestId('row-title')).toHaveText([
      'Row A1',
      'Row B2',
      'Row B1',
      'Row A3',
    ])
  })
})

test.describe('Cross-section row drop — source depletion and cancel', () => {
  test.use({ viewport: { width: 1280, height: 2800 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('handles edge cases: source depletion and cancel mid-drag', async ({ page }) => {
    // Alpha [A1]           →  Alpha []
    // Beta  [B1, B2, B3]  →  Beta  [B1, *A1*, B2, B3]
    await test.step('Source depletion: move lone row to target', async () => {
      await loadAndNavigate(page, 'cross-section-drop-single')
      const sectionAlpha = getSection(page, 'Section Alpha')
      const sectionBeta = getSection(page, 'Section Beta')
      await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1)

      await activateDragByTitle(page, 'Row A1', { overlayTestId: 'drag-overlay-row' })
      await enterAtFirst(page, sectionBeta, 4)
      const pos = await rowPosition(sectionBeta, { between: ['Row B1', 'Row B2'] })
      await dropAndSettle(page, pos.x, pos.y)

      await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(0)
      await expect(sectionBeta.getByTestId('row-title')).toHaveText([
        'Row B1',
        'Row A1',
        'Row B2',
        'Row B3',
      ])

      // Verify persistence
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      const betaReloaded = getSection(page, 'Section Beta')
      await expect(betaReloaded.getByTestId('row-title')).toHaveText([
        'Row B1',
        'Row A1',
        'Row B2',
        'Row B3',
      ])
    })

    // Alpha [A1, A2, A3]  →  Alpha [A1, A2, A3, *B1*]
    // Beta  [B1]           →  Beta  []
    await test.step('Source depletion from below: B1 → Alpha after A3 (last position)', async () => {
      await loadAndNavigate(page, 'cross-section-drop-single-reverse')
      const sectionAlpha = getSection(page, 'Section Alpha')
      const sectionBeta = getSection(page, 'Section Beta')
      await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(3)
      await expect(sectionBeta.getByTestId('row-block')).toHaveCount(1)

      await activateDragByTitle(page, 'Row B1', { overlayTestId: 'drag-overlay-row' })
      await enterFromBelow(page, sectionAlpha, 4)
      const pos = await rowPosition(sectionAlpha, { after: 'Row A3' })
      await dropAndSettle(page, pos.x, pos.y)

      await expect(sectionAlpha.getByTestId('row-title')).toHaveText([
        'Row A1',
        'Row A2',
        'Row A3',
        'Row B1',
      ])
      await expect(sectionBeta.getByTestId('row-block')).toHaveCount(0)

      // Verify persistence
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      const alphaReloaded = getSection(page, 'Section Alpha')
      await expect(alphaReloaded.getByTestId('row-title')).toHaveText([
        'Row A1',
        'Row A2',
        'Row A3',
        'Row B1',
      ])
    })

    // Drag A1 into Beta, press Escape → both containers revert to initial state
    await test.step('Cancel mid-drag reverts to original state', async () => {
      const reorderWatch = watchReorderRequests(page)
      await loadAndNavigate(page, 'cross-section-drop')
      const sectionAlpha = getSection(page, 'Section Alpha')
      const sectionBeta = getSection(page, 'Section Beta')

      await activateDragByTitle(page, 'Row A1', { overlayTestId: 'drag-overlay-row' })
      await enterAtFirst(page, sectionBeta, 4)

      await page.keyboard.press('Escape')

      await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row A1', 'Row A2', 'Row A3'])
      await expect(sectionBeta.getByTestId('row-title')).toHaveText(['Row B1', 'Row B2', 'Row B3'])
      expect(reorderWatch.count()).toBe(0)
      reorderWatch.stop()
    })
  })
})
