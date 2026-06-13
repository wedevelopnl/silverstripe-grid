import { expect, test } from '@playwright/test'
import { activateDragByTitle, waitForMutationSettlement } from '../helpers/drag'
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures'

/**
 * Regression test for cross-container ghost sticking: when dragging a row
 * from Section Alpha to Section Beta, the ghost should leave the source
 * container (Alpha siblings return to original positions) and arrive in
 * the target container (Beta siblings get displaced).
 *
 * Without the overlap gate in centerCrossing, the source container's
 * sibling collisions persist even when the collision rect has moved far
 * away, preventing Pass 2 (parent containers) from firing.
 */
test.describe('Cross container ghost', () => {
  test.use({ viewport: { width: 1280, height: 1400 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('ghost leaves source container and arrives in target during cross-section drag', async ({
    page,
  }) => {
    await loadAndNavigate(page, 'cross-container-ghost')

    // Verify initial state: Alpha has 2 rows, Beta has 1 row
    const sectionAlpha = page.getByTestId('section-block').filter({ hasText: 'Section Alpha' })
    const sectionBeta = page.getByTestId('section-block').filter({ hasText: 'Section Beta' })
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(2)
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(1)

    // Record Beta-1's bounding box to compute the drag target
    const betaRow1 = sectionBeta.getByTestId('row-block').first()

    // Activate drag on Alpha-2 and get handle center for subsequent moves
    const { x: fromX } = await activateDragByTitle(page, 'Row Alpha-2', {
      overlayTestId: 'drag-overlay-row',
    })

    // Move pointer into Beta's area — at Beta-1's vertical center
    const betaRow1Box = await betaRow1.boundingBox()
    expect(betaRow1Box).not.toBeNull()
    const betaCenterY = betaRow1Box!.y + betaRow1Box!.height / 2
    await page.mouse.move(fromX, betaCenterY, { steps: 30 })

    // Assert: ghost left Alpha — Alpha now has only 1 row (Alpha-2 moved out)
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1)
    await expect(sectionAlpha.getByTestId('row-title').first()).toHaveText('Row Alpha-1')

    // Assert: ghost arrived in Beta — Alpha-2 placeholder appears inside Beta.
    // We can't compare absolute Y because Alpha shrinks when it loses Alpha-2,
    // shifting everything below it upward. Instead, verify the row count changed:
    // Beta now has 2 rows (Beta-1 + Alpha-2 placeholder appended at end).
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(2)

    // Release and await mutation settlement
    const settle = waitForMutationSettlement(page)
    await page.mouse.up()
    await settle()

    // Verify: Alpha has 1 row and Beta has 2 rows
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1)
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(2)

    // Verify order: Alpha-2 should be AFTER Beta-1 (appended to target container)
    const betaRowTitles = sectionBeta.getByTestId('row-title')
    await expect(betaRowTitles.first()).toHaveText('Row Beta-1')
    await expect(betaRowTitles.last()).toHaveText('Row Alpha-2')
  })
})
