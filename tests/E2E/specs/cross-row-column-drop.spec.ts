import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * Cross-row column drop positions — comprehensive permutation test.
 *
 * Verifies that columns dragged between rows land at the exact target
 * position (before first, between any pair, after last) in both directions.
 * Each test asserts exact column order after drop and persistence after reload.
 *
 * Columns use horizontal layout (X-axis), so direction-aware placement
 * compares pointer X position against the 'over' element's center X.
 */
test.describe('Cross-row column drop positions', () => {
  test.use({ viewport: { width: 1280, height: 1400 } });

  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  // --- Helpers ---

  async function startDrag(page: Page, colTitle: string) {
    const handle = page.locator(`[data-testid="drag-handle"][aria-label="Move ${colTitle}"]`);
    await handle.scrollIntoViewIfNeeded();
    const box = await handle.boundingBox();
    expect(box).not.toBeNull();
    const x = box!.x + box!.width / 2;
    const y = box!.y + box!.height / 2;

    await page.mouse.move(x, y);
    await page.mouse.down();
    // Move horizontally to activate drag (columns are horizontal)
    await page.mouse.move(x + 10, y, { steps: 3 });
    await page.waitForTimeout(150);

    await expect(page.getByTestId('drag-overlay-column')).toBeVisible();
  }

  function getRow(page: Page, rowTitle: string) {
    return page.getByTestId('row-block').filter({ hasText: rowTitle });
  }

  /** Extract column titles from collapse-toggle aria-labels within a row. */
  async function getColumnTitles(rowLocator: Locator): Promise<string[]> {
    return rowLocator
      .getByTestId('column-block')
      .getByTestId('collapse-toggle')
      .evaluateAll((els) =>
        els.map((el) => (el.getAttribute('aria-label') ?? '').replace(/^(Collapse |Expand )/, '')),
      );
  }

  /**
   * Enter the target row via its FIRST column center (rightward direction).
   */
  async function enterAtFirst(page: Page, targetRow: Locator, expectedColCount: number) {
    const firstCol = targetRow.getByTestId('column-block').first();
    await firstCol.scrollIntoViewIfNeeded();
    const box = await firstCol.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 });
    await page.waitForTimeout(300);
    await expect(targetRow.getByTestId('column-block')).toHaveCount(expectedColCount);
  }

  /**
   * Enter the target row from the right (leftward direction).
   * The -40px X offset past the last column's center ensures the
   * centerCrossing LEFT threshold is reliably crossed.
   */
  async function enterFromRight(page: Page, targetRow: Locator, expectedColCount: number) {
    const lastCol = targetRow.getByTestId('column-block').last();
    await lastCol.scrollIntoViewIfNeeded();
    const box = await lastCol.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2 - 40, box!.y + box!.height / 2, { steps: 30 });
    await page.waitForTimeout(300);
    await expect(targetRow.getByTestId('column-block')).toHaveCount(expectedColCount);
  }

  /**
   * Locate a column within a row by its title (via collapse-toggle aria-label).
   */
  function findColumn(page: Page, targetRow: Locator, colTitle: string) {
    return targetRow.getByTestId('column-block').filter({
      has: page.locator(`[aria-label="Collapse ${colTitle}"], [aria-label="Expand ${colTitle}"]`),
    });
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
    // Get the outer wrapper (the grid cell div with ref={setNodeRef}) via
    // the column-block's parent element, since that's the actual droppable.
    async function getOuterBox(colTitle: string) {
      const col = findColumn(page, targetRow, colTitle);
      // column-block is the inner div; its parent is the outer grid cell
      const outer = col.locator('..');
      const box = await outer.boundingBox();
      expect(box).not.toBeNull();
      return box!;
    }

    if ('before' in position) {
      const box = await getOuterBox(position.before);
      return { x: box.x + box.width * 0.15, y: box.y + box.height / 2 };
    }
    if ('after' in position) {
      const box = await getOuterBox(position.after);
      return { x: box.x + box.width * 0.65, y: box.y + box.height / 2 };
    }
    // Between: use the first column's right area (stable, above insertion point)
    const box1 = await getOuterBox(position.between[0]);
    return { x: box1.x + box1.width * 0.85, y: box1.y + box1.height / 2 };
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
    rowLocator: Locator,
    expectedTitles: string[],
    rowFilterText: string,
  ) {
    await expect.poll(() => getColumnTitles(rowLocator)).toEqual(expectedTitles);

    await page.reload();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    const reloaded = page.getByTestId('row-block').filter({ hasText: rowFilterText });
    await expect.poll(() => getColumnTitles(reloaded)).toEqual(expectedTitles);
  }

  async function loadAndNavigate(page: Page, fixtureName: string) {
    const fixture = await loadFixture(page.request, fixtureName);
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
  }

  // --- Down (Row A -> Row B) ---

  test.describe('Down (Row A -> Row B)', () => {
    test('before first column in Row B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowB = getRow(page, 'Row B');

      await startDrag(page, 'Col A1');
      await enterAtFirst(page, rowB, 4);
      const pos = await colPosition(page, rowB, { before: 'Col B1' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowB, ['Col A1', 'Col B1', 'Col B2', 'Col B3'], 'Row B');
    });

    test('between first and second columns in Row B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowB = getRow(page, 'Row B');

      await startDrag(page, 'Col A1');
      await enterAtFirst(page, rowB, 4);
      const pos = await colPosition(page, rowB, { between: ['Col B1', 'Col B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowB, ['Col B1', 'Col A1', 'Col B2', 'Col B3'], 'Row B');
    });

    test('between second and third columns in Row B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowB = getRow(page, 'Row B');

      await startDrag(page, 'Col A1');
      await enterAtFirst(page, rowB, 4);
      const pos = await colPosition(page, rowB, { between: ['Col B2', 'Col B3'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowB, ['Col B1', 'Col B2', 'Col A1', 'Col B3'], 'Row B');
    });

    test('after last column in Row B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowB = getRow(page, 'Row B');

      await startDrag(page, 'Col A1');
      await enterAtFirst(page, rowB, 4);
      const pos = await colPosition(page, rowB, { after: 'Col B3' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowB, ['Col B1', 'Col B2', 'Col B3', 'Col A1'], 'Row B');
    });
  });

  // --- Up (Row B -> Row A) ---

  test.describe('Up (Row B -> Row A)', () => {
    test('before first column in Row A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowA = getRow(page, 'Row A');

      await startDrag(page, 'Col B1');
      await enterFromRight(page, rowA, 4);
      const pos = await colPosition(page, rowA, { before: 'Col A1' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowA, ['Col B1', 'Col A1', 'Col A2', 'Col A3'], 'Row A');
    });

    test('between first and second columns in Row A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowA = getRow(page, 'Row A');

      await startDrag(page, 'Col B1');
      await enterFromRight(page, rowA, 4);
      const pos = await colPosition(page, rowA, { between: ['Col A1', 'Col A2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowA, ['Col A1', 'Col B1', 'Col A2', 'Col A3'], 'Row A');
    });

    test('between second and third columns in Row A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowA = getRow(page, 'Row A');

      await startDrag(page, 'Col B1');
      await enterFromRight(page, rowA, 4);
      const pos = await colPosition(page, rowA, { between: ['Col A2', 'Col A3'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowA, ['Col A1', 'Col A2', 'Col B1', 'Col A3'], 'Row A');
    });

    test('after last column in Row A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowA = getRow(page, 'Row A');

      await startDrag(page, 'Col B1');
      await enterFromRight(page, rowA, 4);
      const pos = await colPosition(page, rowA, { after: 'Col A3' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, rowA, ['Col A1', 'Col A2', 'Col A3', 'Col B1'], 'Row A');
    });
  });

  // --- Edge cases ---

  test.describe('Edge cases', () => {
    test('source row becomes empty after move', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop-single');
      const rowA = getRow(page, 'Row A');
      const rowB = getRow(page, 'Row B');
      await expect(rowA.getByTestId('column-block')).toHaveCount(1);

      await startDrag(page, 'Col A1');
      await enterAtFirst(page, rowB, 4);
      const pos = await colPosition(page, rowB, { between: ['Col B1', 'Col B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await expect(rowA.getByTestId('column-block')).toHaveCount(0);
      await assertOrderAndPersistence(page, rowB, ['Col B1', 'Col A1', 'Col B2', 'Col B3'], 'Row B');
    });

    test('cancel mid-drag reverts to original state', async ({ page }) => {
      await loadAndNavigate(page, 'cross-row-column-drop');
      const rowA = getRow(page, 'Row A');
      const rowB = getRow(page, 'Row B');

      await startDrag(page, 'Col A1');
      await enterAtFirst(page, rowB, 4);

      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      await expect.poll(() => getColumnTitles(rowA)).toEqual(['Col A1', 'Col A2', 'Col A3']);
      await expect.poll(() => getColumnTitles(rowB)).toEqual(['Col B1', 'Col B2', 'Col B3']);
    });
  });
});
