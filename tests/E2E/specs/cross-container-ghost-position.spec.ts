import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { activateDragByTitle, releaseDrag, waitForMutationSettlement } from '../helpers/drag'
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures'

/**
 * Cross-container ghost PREVIEW positioning — the drag-over pending tree.
 *
 * The final drop position is already covered by cross-column-element-drop.spec.
 * This spec pins the *preview*: while an element is dragged into a populated
 * target column, the ghost (the dimmed active card, rendered via the pending
 * tree) must track the pointer to every slot — top (before first), any middle
 * pair, and bottom (after last) — not stick at the position where it first
 * entered. Regression for the cross-container drag-over freeze.
 */

function getColumn(page: Page, colTitle: string): Locator {
  return page.getByTestId('column-block').filter({
    has: page.locator(`[aria-label="Move ${colTitle}"]`),
  })
}

/** Center X of a column, used for all vertical-axis pointer moves. */
async function columnCenterX(col: Locator): Promise<number> {
  const box = await col.boundingBox()
  expect(box).not.toBeNull()
  return box!.x + box!.width / 2
}

/** Move the pointer into the target column's center to trigger entry. */
async function enterColumn(page: Page, col: Locator, expectedCards: number): Promise<void> {
  await col.scrollIntoViewIfNeeded()
  const box = await col.boundingBox()
  expect(box).not.toBeNull()
  await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 })
  // The active card is rendered into the target via the pending tree.
  await expect(col.getByTestId('element-card')).toHaveCount(expectedCards)
}

/**
 * Hover a Y position relative to a named card in the column and settle.
 * `place` picks the top 15% (→ 'before') or bottom 75% (→ 'after') of the card.
 * Re-measures on every call because the pending tree shifts cards as the ghost
 * moves.
 */
async function hoverCard(
  page: Page,
  col: Locator,
  cardTitle: string,
  place: 'before' | 'after',
): Promise<{ x: number; y: number }> {
  const centerX = await columnCenterX(col)
  const card = col.getByTestId('element-card').filter({ hasText: cardTitle })
  const box = await card.boundingBox()
  expect(box).not.toBeNull()
  const y = place === 'before' ? box!.y + box!.height * 0.15 : box!.y + box!.height * 0.75
  await page.mouse.move(centerX, y, { steps: 20 })
  return { x: centerX, y }
}

test.describe('Cross-container ghost preview — reaches every slot', () => {
  test.use({ viewport: { width: 1280, height: 1400 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('ghost tracks the pointer to top, middle and bottom of the target column', async ({
    page,
  }) => {
    await loadAndNavigate(page, 'cross-column-element-drop')
    const colB = getColumn(page, 'Col B')

    await expect(colB.getByTestId('element-card-title')).toHaveText([
      'Element B1',
      'Element B2',
      'Element B3',
    ])

    // Drag Element A1 out of Col A into Col B. The dimmed active card now lives
    // in Col B's list via the pending tree; its position IS the preview.
    await activateDragByTitle(page, 'Element A1', { overlayTestId: 'drag-overlay-element' })
    await enterColumn(page, colB, 4)

    await test.step('preview reaches the TOP (before B1)', async () => {
      await hoverCard(page, colB, 'Element B1', 'before')
      await expect(colB.getByTestId('element-card-title')).toHaveText([
        'Element A1',
        'Element B1',
        'Element B2',
        'Element B3',
      ])
    })

    await test.step('preview reaches a MIDDLE slot (between B2 and B3)', async () => {
      await hoverCard(page, colB, 'Element B2', 'after')
      await expect(colB.getByTestId('element-card-title')).toHaveText([
        'Element B1',
        'Element B2',
        'Element A1',
        'Element B3',
      ])
    })

    await test.step('preview reaches the BOTTOM (after B3)', async () => {
      await hoverCard(page, colB, 'Element B3', 'after')
      await expect(colB.getByTestId('element-card-title')).toHaveText([
        'Element B1',
        'Element B2',
        'Element B3',
        'Element A1',
      ])
    })

    await test.step('returns to the TOP and drops there (persists the previewed order)', async () => {
      const { x, y } = await hoverCard(page, colB, 'Element B1', 'before')
      await expect(colB.getByTestId('element-card-title')).toHaveText([
        'Element A1',
        'Element B1',
        'Element B2',
        'Element B3',
      ])

      const settle = waitForMutationSettlement(page)
      await releaseDrag(page, x, y)
      await settle()

      await expect(colB.getByTestId('element-card-title')).toHaveText([
        'Element A1',
        'Element B1',
        'Element B2',
        'Element B3',
      ])
    })

    await test.step('order survives a reload', async () => {
      await page.reload()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      await expect(getColumn(page, 'Col B').getByTestId('element-card-title')).toHaveText([
        'Element A1',
        'Element B1',
        'Element B2',
        'Element B3',
      ])
    })
  })
})
