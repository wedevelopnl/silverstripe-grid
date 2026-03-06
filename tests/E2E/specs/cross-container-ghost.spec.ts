import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

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
  test.use({ viewport: { width: 1280, height: 1400 } });

  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('ghost leaves source container and arrives in target during cross-section drag', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'cross-container-ghost');
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // Verify initial state: Alpha has 2 rows, Beta has 1 row
    const sectionAlpha = page.getByTestId('section-block').filter({ hasText: 'Section Alpha' });
    const sectionBeta = page.getByTestId('section-block').filter({ hasText: 'Section Beta' });
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(2);
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(1);

    // Record Beta-1's bounding box to compute the drag target
    const betaRow1 = sectionBeta.getByTestId('row-block').first();

    // Locate Alpha-2's drag handle and start drag
    const alpha2Handle = page.locator('[data-testid="drag-handle"][aria-label="Move Row Alpha-2"]');
    await alpha2Handle.scrollIntoViewIfNeeded();
    const handleBox = await alpha2Handle.boundingBox();
    expect(handleBox).not.toBeNull();
    const fromX = handleBox!.x + handleBox!.width / 2;
    const fromY = handleBox!.y + handleBox!.height / 2;

    // Activate drag: press and move 10px downward to exceed 8px threshold
    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX, fromY + 10, { steps: 3 });
    await page.waitForTimeout(150);

    // Assert activation: overlay visible
    await expect(page.getByTestId('drag-overlay-row')).toBeVisible();

    // Move pointer into Beta's area — at Beta-1's vertical center
    const betaRow1Box = await betaRow1.boundingBox();
    expect(betaRow1Box).not.toBeNull();
    const betaCenterY = betaRow1Box!.y + betaRow1Box!.height / 2;
    await page.mouse.move(fromX, betaCenterY, { steps: 30 });
    await page.waitForTimeout(300);

    // Assert: ghost left Alpha — Alpha now has only 1 row (Alpha-2 moved out)
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1);
    await expect(sectionAlpha.getByTestId('row-title').first()).toHaveText('Row Alpha-1');

    // Assert: ghost arrived in Beta — Alpha-2 placeholder appears inside Beta.
    // We can't compare absolute Y because Alpha shrinks when it loses Alpha-2,
    // shifting everything below it upward. Instead, verify the row count changed:
    // Beta now has 2 rows (Beta-1 + Alpha-2 placeholder appended at end).
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(2);

    // Register mutation settlement listeners, release mouse
    const reorderDone = page.waitForResponse(
      (resp) => resp.url().includes('/api/reorder') && resp.ok(),
    );
    const refetchDone = page.waitForResponse(
      (resp) => resp.url().includes('/api/readTree/') && resp.ok(),
    );

    await page.mouse.up();
    await reorderDone;
    await refetchDone;
    await page.waitForTimeout(500);

    // Verify: Alpha has 1 row and Beta has 2 rows
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1);
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(2);

    // Verify order: Alpha-2 should be AFTER Beta-1 (appended to target container)
    const betaRowTitles = sectionBeta.getByTestId('row-title');
    await expect(betaRowTitles.first()).toHaveText('Row Beta-1');
    await expect(betaRowTitles.last()).toHaveText('Row Alpha-2');
  });
});
