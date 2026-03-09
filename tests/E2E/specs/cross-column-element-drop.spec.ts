import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * Cross-column element drop positions — comprehensive permutation test.
 *
 * Verifies that content elements dragged between columns land at the exact
 * target position (before first, between any pair, after last) in both
 * directions. Each test asserts exact element order after drop and
 * persistence after reload.
 *
 * Elements use vertical layout (Y-axis) within columns, so direction-aware
 * placement compares pointer Y position against the 'over' element's center Y.
 */
test.describe('Cross-column element drop positions', () => {
  test.use({ viewport: { width: 1280, height: 1400 } });

  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  // --- Helpers ---

  async function startDrag(page: Page, elementTitle: string) {
    const handle = page.locator(`[data-testid="drag-handle"][aria-label="Move ${elementTitle}"]`);
    await handle.scrollIntoViewIfNeeded();
    const box = await handle.boundingBox();
    expect(box).not.toBeNull();
    const x = box!.x + box!.width / 2;
    const y = box!.y + box!.height / 2;

    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x, y + 10, { steps: 3 });
    await page.waitForTimeout(150);

    await expect(page.getByTestId('drag-overlay-element')).toBeVisible();
  }

  /** Locate a column by its title (via drag handle aria-label). */
  function getColumn(page: Page, colTitle: string) {
    return page.getByTestId('column-block').filter({
      has: page.locator(`[aria-label="Move ${colTitle}"]`),
    });
  }

  /**
   * Enter the target column via its FIRST element center (downward direction).
   */
  async function enterAtFirst(page: Page, targetCol: Locator, expectedElCount: number) {
    const firstEl = targetCol.getByTestId('element-card').first();
    await firstEl.scrollIntoViewIfNeeded();
    const box = await firstEl.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 });
    await page.waitForTimeout(300);
    await expect(targetCol.getByTestId('element-card')).toHaveCount(expectedElCount);
  }

  /**
   * Enter the target column from below (upward direction).
   * The -40px Y offset past the last element's center ensures the
   * centerCrossing UP threshold is reliably crossed.
   */
  async function enterFromBelow(page: Page, targetCol: Locator, expectedElCount: number) {
    const lastEl = targetCol.getByTestId('element-card').last();
    await lastEl.scrollIntoViewIfNeeded();
    const box = await lastEl.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2 - 40, { steps: 30 });
    await page.waitForTimeout(300);
    await expect(targetCol.getByTestId('element-card')).toHaveCount(expectedElCount);
  }

  /**
   * Compute drop coordinates for a target position within a column.
   * Uses element TITLES to locate elements, avoiding interference from the
   * active/dragging item which is present in the DOM at opacity 0.3.
   *
   * Positioning strategy (Y-axis for vertical layout):
   * - "before": top 15% of the named element (above center → "before")
   * - "after": bottom 65% of the named element (below center → "after")
   * - "between": bottom 85% of the first (upper) element
   */
  async function elPosition(
    targetCol: Locator,
    position: { before: string } | { after: string } | { between: [string, string] },
  ) {
    const colBox = await targetCol.boundingBox();
    expect(colBox).not.toBeNull();
    const centerX = colBox!.x + colBox!.width / 2;

    if ('before' in position) {
      const el = targetCol.getByTestId('element-card').filter({ hasText: position.before });
      const box = await el.boundingBox();
      expect(box).not.toBeNull();
      return { x: centerX, y: box!.y + box!.height * 0.15 };
    }
    if ('after' in position) {
      const el = targetCol.getByTestId('element-card').filter({ hasText: position.after });
      const box = await el.boundingBox();
      expect(box).not.toBeNull();
      return { x: centerX, y: box!.y + box!.height * 0.65 };
    }
    // Between: use the midpoint of the gap between the two elements.
    // This is stable regardless of SortableContext CSS transforms that
    // shift elements during drag, unlike a percentage of the first element.
    const el1 = targetCol.getByTestId('element-card').filter({ hasText: position.between[0] });
    const el2 = targetCol.getByTestId('element-card').filter({ hasText: position.between[1] });
    const box1 = await el1.boundingBox();
    const box2 = await el2.boundingBox();
    expect(box1).not.toBeNull();
    expect(box2).not.toBeNull();
    return { x: centerX, y: (box1!.y + box1!.height + box2!.y) / 2 };
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
    columnLocator: Locator,
    expectedTitles: string[],
    colTitle: string,
  ) {
    await expect(columnLocator.getByTestId('element-card-title')).toHaveText(expectedTitles);

    await page.reload();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    const reloaded = getColumn(page, colTitle);
    await expect(reloaded.getByTestId('element-card-title')).toHaveText(expectedTitles);
  }

  async function loadAndNavigate(page: Page, fixtureName: string) {
    const fixture = await loadFixture(page.request, fixtureName);
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
  }

  // --- Right (Col A -> Col B) ---

  test.describe('Right (Col A -> Col B)', () => {
    test('before first element in Col B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colB = getColumn(page, 'Col B');

      await startDrag(page, 'Element A1');
      await enterAtFirst(page, colB, 4);
      const pos = await elPosition(colB, { before: 'Element B1' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colB, ['Element A1', 'Element B1', 'Element B2', 'Element B3'], 'Col B');
    });

    test('between first and second elements in Col B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colB = getColumn(page, 'Col B');

      await startDrag(page, 'Element A1');
      await enterAtFirst(page, colB, 4);
      const pos = await elPosition(colB, { between: ['Element B1', 'Element B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colB, ['Element B1', 'Element A1', 'Element B2', 'Element B3'], 'Col B');
    });

    test('between second and third elements in Col B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colB = getColumn(page, 'Col B');

      await startDrag(page, 'Element A1');
      await enterAtFirst(page, colB, 4);
      const pos = await elPosition(colB, { between: ['Element B2', 'Element B3'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colB, ['Element B1', 'Element B2', 'Element A1', 'Element B3'], 'Col B');
    });

    test('after last element in Col B', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colB = getColumn(page, 'Col B');

      await startDrag(page, 'Element A1');
      await enterAtFirst(page, colB, 4);
      const pos = await elPosition(colB, { after: 'Element B3' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colB, ['Element B1', 'Element B2', 'Element B3', 'Element A1'], 'Col B');
    });
  });

  // --- Left (Col B -> Col A) ---

  test.describe('Left (Col B -> Col A)', () => {
    test('before first element in Col A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colA = getColumn(page, 'Col A');

      await startDrag(page, 'Element B1');
      await enterFromBelow(page, colA, 4);
      const pos = await elPosition(colA, { before: 'Element A1' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colA, ['Element B1', 'Element A1', 'Element A2', 'Element A3'], 'Col A');
    });

    test('between first and second elements in Col A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colA = getColumn(page, 'Col A');

      await startDrag(page, 'Element B1');
      await enterFromBelow(page, colA, 4);
      const pos = await elPosition(colA, { between: ['Element A1', 'Element A2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colA, ['Element A1', 'Element B1', 'Element A2', 'Element A3'], 'Col A');
    });

    test('between second and third elements in Col A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colA = getColumn(page, 'Col A');

      await startDrag(page, 'Element B1');
      await enterFromBelow(page, colA, 4);
      const pos = await elPosition(colA, { between: ['Element A2', 'Element A3'] });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colA, ['Element A1', 'Element A2', 'Element B1', 'Element A3'], 'Col A');
    });

    test('after last element in Col A', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colA = getColumn(page, 'Col A');

      await startDrag(page, 'Element B1');
      await enterFromBelow(page, colA, 4);
      const pos = await elPosition(colA, { after: 'Element A3' });
      await dropAndSettle(page, pos.x, pos.y);

      await assertOrderAndPersistence(page, colA, ['Element A1', 'Element A2', 'Element A3', 'Element B1'], 'Col A');
    });
  });

  // --- Edge cases ---

  test.describe('Edge cases', () => {
    test('source column becomes empty after move', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop-single');
      const colA = getColumn(page, 'Col A');
      const colB = getColumn(page, 'Col B');
      await expect(colA.getByTestId('element-card')).toHaveCount(1);

      await startDrag(page, 'Element A1');
      await enterAtFirst(page, colB, 4);
      const pos = await elPosition(colB, { between: ['Element B1', 'Element B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await expect(colA.getByTestId('element-card')).toHaveCount(0);
      await assertOrderAndPersistence(page, colB, ['Element B1', 'Element A1', 'Element B2', 'Element B3'], 'Col B');
    });

    test('cancel mid-drag reverts to original state', async ({ page }) => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colA = getColumn(page, 'Col A');
      const colB = getColumn(page, 'Col B');

      await startDrag(page, 'Element A1');
      await enterAtFirst(page, colB, 4);

      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      await expect(colA.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element A2', 'Element A3']);
      await expect(colB.getByTestId('element-card-title')).toHaveText(['Element B1', 'Element B2', 'Element B3']);
    });
  });
});
