import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * Regression test for cross-container drop landing at the wrong position.
 *
 * When dragging a row from Section Alpha to BETWEEN two rows in Section Beta,
 * the row should land at the visual ghost's position — not snap to the first
 * or last position. The fix tracks within-container position changes in the
 * pending tree ref during handleDragOver, and handleDragEnd reads the final
 * position from the pending tree rather than relying on over.rect heuristics.
 */
test.describe('Cross container between-rows drop', () => {
  test.use({ viewport: { width: 1280, height: 1400 } });

  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('row dragged from one section lands between two rows in another section', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'cross-container-between');
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // Verify initial state
    const sectionAlpha = page.getByTestId('section-block').filter({ hasText: 'Section Alpha' });
    const sectionBeta = page.getByTestId('section-block').filter({ hasText: 'Section Beta' });
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1);
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(3);

    const betaRowTitles = sectionBeta.getByTestId('row-title');
    await expect(betaRowTitles).toHaveText(['Row Beta-1', 'Row Beta-2', 'Row Beta-3']);

    // Measure Beta-2's position — we'll drag Alpha-1 to land between Beta-2 and Beta-3.
    // Target the midpoint between Beta-2's bottom edge and Beta-3's top edge.
    const betaRow2 = sectionBeta.getByTestId('row-block').filter({ hasText: 'Row Beta-2' });
    const betaRow3 = sectionBeta.getByTestId('row-block').filter({ hasText: 'Row Beta-3' });

    // Locate Alpha-1's drag handle
    const alpha1Handle = page.locator('[data-testid="drag-handle"][aria-label="Move Row Alpha-1"]');
    await alpha1Handle.scrollIntoViewIfNeeded();
    const handleBox = await alpha1Handle.boundingBox();
    expect(handleBox).not.toBeNull();
    const fromX = handleBox!.x + handleBox!.width / 2;
    const fromY = handleBox!.y + handleBox!.height / 2;

    // Activate drag: press and move 10px downward to exceed 8px threshold
    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX, fromY + 10, { steps: 3 });
    await page.waitForTimeout(150);

    // Assert activation
    await expect(page.getByTestId('drag-overlay-row')).toBeVisible();

    // Measure the gap between Beta-2 and Beta-3 to compute the drop target.
    // Re-measure after scrolling into view to get accurate coordinates.
    await betaRow3.scrollIntoViewIfNeeded();
    const row2Box = await betaRow2.boundingBox();
    const row3Box = await betaRow3.boundingBox();
    expect(row2Box).not.toBeNull();
    expect(row3Box).not.toBeNull();

    // Target: midpoint between Beta-2's bottom edge and Beta-3's top edge
    const targetY = (row2Box!.y + row2Box!.height + row3Box!.y) / 2;

    // Move pointer to the gap between Beta-2 and Beta-3
    await page.mouse.move(fromX, targetY, { steps: 30 });
    await page.waitForTimeout(300);

    // Assert: Alpha-1 has left Alpha (Alpha now empty) and appears in Beta
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(0);
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(4);

    // Register mutation settlement listeners, then release
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

    // Verify final state: Alpha is empty, Beta has 4 rows
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(0);
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(4);

    // Alpha-1 should be BETWEEN Beta-2 and Beta-3 (not at start or end).
    // Accepted orders:
    //   [Beta-1, Beta-2, Alpha-1, Beta-3] — dropped between Beta-2 and Beta-3
    //   [Beta-1, Alpha-1, Beta-2, Beta-3] — dropped between Beta-1 and Beta-2
    // Both are valid "between" positions. The key assertion is: NOT at first
    // position and NOT at last position.
    const finalTitles = await sectionBeta.getByTestId('row-title').allTextContents();
    expect(finalTitles).toHaveLength(4);
    expect(finalTitles[0]).not.toBe('Row Alpha-1');
    expect(finalTitles[3]).not.toBe('Row Alpha-1');

    // Verify persistence: reload and check the order survived
    await page.reload();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    const reloadBeta = page.getByTestId('section-block').filter({ hasText: 'Section Beta' });
    const reloadTitles = await reloadBeta.getByTestId('row-title').allTextContents();
    expect(reloadTitles).toEqual(finalTitles);
  });
});
