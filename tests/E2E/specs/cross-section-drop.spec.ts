import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * Cross-section row drop positions — comprehensive permutation test.
 *
 * Verifies that rows dragged between sections land at the exact target
 * position (before first, between any pair, after last) in both directions.
 * Each test asserts exact title order after drop and persistence after reload.
 *
 * Drop positioning relies on direction-aware placement at DragEnd: the pointer
 * position relative to the 'over' element's center determines before/after.
 * This is independent of entry position or intermediate corrections.
 */
test.describe('Cross-section row drop positions', () => {
  test.use({ viewport: { width: 1280, height: 1400 } });

  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  // --- Helpers ---

  async function startDrag(page: Page, rowTitle: string) {
    const handle = page.locator(`[data-testid="drag-handle"][aria-label="Move ${rowTitle}"]`);
    await handle.scrollIntoViewIfNeeded();
    const box = await handle.boundingBox();
    expect(box).not.toBeNull();
    const x = box!.x + box!.width / 2;
    const y = box!.y + box!.height / 2;

    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x, y + 10, { steps: 3 });
    await page.waitForTimeout(150);

    await expect(page.getByTestId('drag-overlay-row')).toBeVisible();
  }

  function getSection(page: Page, sectionTitle: string) {
    return page.getByTestId('section-block').filter({ hasText: sectionTitle });
  }

  /**
   * Enter the target section via its FIRST row center (DOWN direction).
   * The 30-step mouse move triggers cross-container entry via collision detection.
   */
  async function enterAtFirst(page: Page, targetSection: Locator, expectedRowCount: number) {
    const firstRow = targetSection.getByTestId('row-block').first();
    await firstRow.scrollIntoViewIfNeeded();
    const box = await firstRow.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 });
    await page.waitForTimeout(300);
    await expect(targetSection.getByTestId('row-block')).toHaveCount(expectedRowCount);
  }

  /**
   * Enter the target section from below (UP direction).
   * The -40px offset past the last row's center ensures the centerCrossing
   * UP threshold is reliably crossed despite floating-point precision.
   */
  async function enterFromBelow(page: Page, targetSection: Locator, expectedRowCount: number) {
    const lastRow = targetSection.getByTestId('row-block').last();
    await lastRow.scrollIntoViewIfNeeded();
    const box = await lastRow.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2 - 40, { steps: 30 });
    await page.waitForTimeout(300);
    await expect(targetSection.getByTestId('row-block')).toHaveCount(expectedRowCount);
  }

  /**
   * Compute drop coordinates for a target position within a section.
   * Uses row TITLES (not DOM indices) to locate rows, avoiding interference
   * from the active/dragging item which is present in the DOM at opacity 0.3.
   *
   * Positioning strategy:
   * - "before": top quarter of the named row (above center → "before" direction)
   * - "after": bottom quarter of the named row (below center → "after" direction)
   * - "between": top quarter of the second (lower) row — ensures closestCenter
   *   picks the lower row, and pointer < center gives "before" placement.
   *
   * Using quarters instead of edge midpoints avoids ambiguity at row borders
   * and prevents the pointer from exiting the section's collision zone.
   */
  async function rowPosition(
    targetSection: Locator,
    position: { before: string } | { after: string } | { between: [string, string] },
  ) {
    const sectionBox = await targetSection.boundingBox();
    expect(sectionBox).not.toBeNull();
    const centerX = sectionBox!.x + sectionBox!.width / 2;

    if ('before' in position) {
      const row = targetSection.getByTestId('row-block').filter({ hasText: position.before });
      const box = await row.boundingBox();
      expect(box).not.toBeNull();
      return { x: centerX, y: box!.y + box!.height * 0.15 };
    }
    if ('after' in position) {
      const row = targetSection.getByTestId('row-block').filter({ hasText: position.after });
      const box = await row.boundingBox();
      expect(box).not.toBeNull();
      // Use 65% (below center, above bottom) to stay within the row's collision
      // zone. 85% risks crossing into adjacent section boundaries during
      // cross-container drags where closestCenter uses stale measurements.
      return { x: centerX, y: box!.y + box!.height * 0.65 };
    }
    // Use the first row's bottom area rather than the second row's top area.
    // During cross-container drags, SortableContext transforms shift the second
    // row's visual position, making its pre-measured coordinates unreliable.
    // The first row's position is stable (above the insertion point).
    const row1 = targetSection.getByTestId('row-block').filter({ hasText: position.between[0] });
    const box1 = await row1.boundingBox();
    expect(box1).not.toBeNull();
    return { x: centerX, y: box1!.y + box1!.height * 0.85 };
  }

  async function dropAndSettle(page: Page, targetX: number, targetY: number) {
    await page.mouse.move(targetX, targetY, { steps: 15 });
    await page.waitForTimeout(200);

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
  }

  async function assertOrderAndPersistence(
    page: Page,
    sectionLocator: Locator,
    expectedTitles: string[],
    sectionFilterText: string,
  ) {
    await expect(sectionLocator.getByTestId('row-title')).toHaveText(expectedTitles);

    await page.reload();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    const reloaded = page.getByTestId('section-block').filter({ hasText: sectionFilterText });
    await expect(reloaded.getByTestId('row-title')).toHaveText(expectedTitles);
  }

  async function loadAndNavigate(page: Page, fixtureName: string) {
    const fixture = await loadFixture(page.request, fixtureName);
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
  }

  // --- Down (Alpha -> Beta) ---

  test.describe('Down (Alpha -> Beta)', () => {
    test('before first item in Beta', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionBeta = getSection(page, 'Section Beta');

      await startDrag(page, 'Row A1');
      await enterAtFirst(page, sectionBeta, 4);
      const pos = await rowPosition(sectionBeta, { before: 'Row B1' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionBeta, ['Row A1', 'Row B1', 'Row B2', 'Row B3'], 'Section Beta');
    });

    test('between first and second items in Beta', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionBeta = getSection(page, 'Section Beta');

      await startDrag(page, 'Row A1');
      await enterAtFirst(page, sectionBeta, 4);
      const pos = await rowPosition(sectionBeta, { between: ['Row B1', 'Row B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionBeta, ['Row B1', 'Row A1', 'Row B2', 'Row B3'], 'Section Beta');
    });

    test('between second and third items in Beta', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionBeta = getSection(page, 'Section Beta');

      await startDrag(page, 'Row A1');
      await enterAtFirst(page, sectionBeta, 4);
      const pos = await rowPosition(sectionBeta, { between: ['Row B2', 'Row B3'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionBeta, ['Row B1', 'Row B2', 'Row A1', 'Row B3'], 'Section Beta');
    });

    test('after last item in Beta', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionBeta = getSection(page, 'Section Beta');

      await startDrag(page, 'Row A1');
      await enterAtFirst(page, sectionBeta, 4);
      const pos = await rowPosition(sectionBeta, { after: 'Row B3' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionBeta, ['Row B1', 'Row B2', 'Row B3', 'Row A1'], 'Section Beta');
    });
  });

  // --- Up (Beta -> Alpha) ---

  test.describe('Up (Beta -> Alpha)', () => {
    test('before first item in Alpha', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionAlpha = getSection(page, 'Section Alpha');

      await startDrag(page, 'Row B1');
      await enterFromBelow(page, sectionAlpha, 4);
      const pos = await rowPosition(sectionAlpha, { before: 'Row A1' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionAlpha, ['Row B1', 'Row A1', 'Row A2', 'Row A3'], 'Section Alpha');
    });

    test('between first and second items in Alpha', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionAlpha = getSection(page, 'Section Alpha');

      await startDrag(page, 'Row B1');
      await enterFromBelow(page, sectionAlpha, 4);
      const pos = await rowPosition(sectionAlpha, { between: ['Row A1', 'Row A2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionAlpha, ['Row A1', 'Row B1', 'Row A2', 'Row A3'], 'Section Alpha');
    });

    test('between second and third items in Alpha', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionAlpha = getSection(page, 'Section Alpha');

      await startDrag(page, 'Row B1');
      await enterFromBelow(page, sectionAlpha, 4);
      const pos = await rowPosition(sectionAlpha, { between: ['Row A2', 'Row A3'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionAlpha, ['Row A1', 'Row A2', 'Row B1', 'Row A3'], 'Section Alpha');
    });

    test('after last item in Alpha', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionAlpha = getSection(page, 'Section Alpha');

      await startDrag(page, 'Row B1');
      await enterFromBelow(page, sectionAlpha, 4);
      const pos = await rowPosition(sectionAlpha, { after: 'Row A3' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, sectionAlpha, ['Row A1', 'Row A2', 'Row A3', 'Row B1'], 'Section Alpha');
    });
  });

  // --- Edge cases ---

  test.describe('Edge cases', () => {
    test('source container becomes empty after move', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop-single');
      const sectionAlpha = getSection(page, 'Section Alpha');
      const sectionBeta = getSection(page, 'Section Beta');
      await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1);

      await startDrag(page, 'Row A1');
      await enterAtFirst(page, sectionBeta, 4);
      const pos = await rowPosition(sectionBeta, { between: ['Row B1', 'Row B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(0);
      await assertOrderAndPersistence(page, sectionBeta, ['Row B1', 'Row A1', 'Row B2', 'Row B3'], 'Section Beta');
    });

    test('cancel mid-drag reverts to original state', async ({ page }) => {
      await loadAndNavigate(page, 'cross-section-drop');
      const sectionAlpha = getSection(page, 'Section Alpha');
      const sectionBeta = getSection(page, 'Section Beta');

      await startDrag(page, 'Row A1');
      await enterAtFirst(page, sectionBeta, 4);

      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row A1', 'Row A2', 'Row A3']);
      await expect(sectionBeta.getByTestId('row-title')).toHaveText(['Row B1', 'Row B2', 'Row B3']);
    });
  });
});
