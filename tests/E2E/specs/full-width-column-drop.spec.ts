import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures'
import { activateDragByTitle, dropAndSettle, enterContainerCenter } from '../helpers/drag'

/**
 * Adaptive drop axis — journey over ALL four axis/side combinations.
 *
 * A 12/12 column spans its whole row, so it stacks vertically: dropping
 * another column onto its BOTTOM half lands below it, its TOP half above
 * it. Narrow (4/12) columns keep horizontal left/right semantics — even
 * when a narrow column sits alone on its visual band (the rule is
 * width-based, not band-occupancy-based).
 *
 * Every drop point uses an ADVERSARIAL corner: the coordinate on the
 * decisive axis expresses the intent, while the other axis points the
 * opposite way. A regression to the old always-X rule (steps 1-2) or an
 * axis misfire to Y on narrow targets (steps 3-4) flips the resulting
 * order and fails the assertion. All drags are cross-container because
 * direction resolution only runs on cross-container moves.
 */

function getRow(page: Page, rowTitle: string) {
  return page.getByTestId('row-block').filter({ hasText: rowTitle })
}

/**
 * Drop coordinate in a corner of a named column's outer grid cell (the
 * droppable rect). The corner encodes intent on the decisive axis and
 * the adversarial position on the other (see file doc comment).
 */
async function colCorner(
  page: Page,
  targetRow: Locator,
  colTitle: string,
  place: 'top-right' | 'bottom-left',
) {
  const outer = targetRow.getByTestId('column-block-outer').filter({
    has: page.locator(`[aria-label="Collapse ${colTitle}"], [aria-label="Expand ${colTitle}"]`),
  })
  const box = await outer.boundingBox()
  expect(box).not.toBeNull()
  if (place === 'bottom-left') {
    return { x: box!.x + box!.width * 0.15, y: box!.y + box!.height * 0.85 }
  }
  return { x: box!.x + box!.width * 0.85, y: box!.y + box!.height * 0.15 }
}

test.describe('Adaptive drop axis — full-width and narrow column targets', () => {
  test.use({ viewport: { width: 1280, height: 2000 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('drops resolve on the geometric axis for every side', async ({ page }) => {
    await loadAndNavigate(page, 'full-width-column-drop')
    const rowOne = getRow(page, 'Row One')
    const rowTwo = getRow(page, 'Row Two')
    const rowThree = getRow(page, 'Row Three')

    await expect(rowOne.getByTestId('column-title')).toHaveText(['Narrow A', 'Narrow B'])
    await expect(rowTwo.getByTestId('column-title')).toHaveText(['Full Col'])
    await expect(rowThree.getByTestId('column-title')).toHaveText(['Narrow C', 'Narrow D'])

    // R1 [A, B] / R2 [Full]  →  R1 [B] / R2 [Full, *A*]
    await test.step('Y-below: bottom-LEFT of Full Col → Narrow A lands BELOW it', async () => {
      await activateDragByTitle(page, 'Narrow A', {
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowTwo, 'column-block', 2)
      const pos = await colCorner(page, rowTwo, 'Full Col', 'bottom-left')
      await dropAndSettle(
        page,
        pos.x,
        pos.y,
        'Y-below: bottom-LEFT of Full Col → Narrow A lands BELOW it',
      )
      await expect(rowTwo.getByTestId('column-title')).toHaveText(['Full Col', 'Narrow A'])
    })

    // R1 [B] / R2 [Full, A]  →  R1 [] / R2 [*B*, Full, A]   (source depletion)
    await test.step('Y-above: top-RIGHT of Full Col → Narrow B lands ABOVE it', async () => {
      await activateDragByTitle(page, 'Narrow B', {
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowTwo, 'column-block', 3)
      const pos = await colCorner(page, rowTwo, 'Full Col', 'top-right')
      await dropAndSettle(
        page,
        pos.x,
        pos.y,
        'Y-above: top-RIGHT of Full Col → Narrow B lands ABOVE it',
      )
      await expect(rowTwo.getByTestId('column-title')).toHaveText([
        'Narrow B',
        'Full Col',
        'Narrow A',
      ])
    })

    // R3 [C, D] / R2 [B, Full, A]  →  R3 [D] / R2 [*C*, B, Full, A]
    // Narrow B sits alone on its band (nothing fits beside the 12/12),
    // yet keeps X-axis semantics: bottom-left must mean LEFT, not below.
    await test.step('X-before: bottom-LEFT of Narrow B → Narrow C lands LEFT of it', async () => {
      await activateDragByTitle(page, 'Narrow C', {
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowTwo, 'column-block', 4)
      const pos = await colCorner(page, rowTwo, 'Narrow B', 'bottom-left')
      await dropAndSettle(
        page,
        pos.x,
        pos.y,
        'X-before: bottom-LEFT of Narrow B → Narrow C lands LEFT of it',
      )
      await expect(rowTwo.getByTestId('column-title')).toHaveText([
        'Narrow C',
        'Narrow B',
        'Full Col',
        'Narrow A',
      ])
    })

    // R3 [D] / R2 [C, B, Full, A]  →  R3 [] / R2 [C, B, Full, A, *D*]   (source depletion)
    await test.step('X-after: top-RIGHT of Narrow A → Narrow D lands RIGHT of it', async () => {
      await activateDragByTitle(page, 'Narrow D', {
        overlayTestId: 'drag-overlay-column',
      })
      await enterContainerCenter(page, rowTwo, 'column-block', 5)
      const pos = await colCorner(page, rowTwo, 'Narrow A', 'top-right')
      await dropAndSettle(
        page,
        pos.x,
        pos.y,
        'X-after: top-RIGHT of Narrow A → Narrow D lands RIGHT of it',
      )
      await expect(rowTwo.getByTestId('column-title')).toHaveText([
        'Narrow C',
        'Narrow B',
        'Full Col',
        'Narrow A',
        'Narrow D',
      ])
    })

    // Verify persistence after reload
    await page.reload()
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
    const rowTwoReloaded = getRow(page, 'Row Two')
    await expect(rowTwoReloaded.getByTestId('column-title')).toHaveText([
      'Narrow C',
      'Narrow B',
      'Full Col',
      'Narrow A',
      'Narrow D',
    ])
    await expect(getRow(page, 'Row One').getByTestId('column-block')).toHaveCount(0)
    await expect(getRow(page, 'Row Three').getByTestId('column-block')).toHaveCount(0)
  })
})
