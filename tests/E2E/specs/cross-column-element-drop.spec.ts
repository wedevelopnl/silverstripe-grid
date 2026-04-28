import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures';
import { activateDragByTitle, dropAndSettle } from '../helpers/drag';

/**
 * Cross-column element drop positions — journey tests.
 *
 * Verifies that content elements dragged between columns land at the exact
 * target position (before first, between any pair, after last) in both
 * directions. Elements use vertical layout (Y-axis) within columns, so
 * direction-aware placement compares pointer Y against center Y.
 */
test.describe('Cross-column element drop positions', () => {
  test.skip(({ browserName }) => browserName !== 'chromium',
    'DnD pointer simulation is Chromium-specific');

  test.use({ viewport: { width: 1280, height: 1400 } });

  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  // --- Hierarchy-specific helpers ---

  /** Locate a column by its title (via drag handle aria-label). */
  function getColumn(page: Page, colTitle: string) {
    return page.getByTestId('column-block').filter({
      has: page.locator(`[aria-label="Move ${colTitle}"]`),
    });
  }

  /**
   * Enter the target column by moving the pointer to the column's center.
   * Uses the container center (not a specific child) so the entry trajectory
   * reliably triggers collision detection regardless of the pointer's starting
   * position — critical in journey tests where multiple prior operations leave
   * the pointer at unpredictable coordinates.
   */
  async function enterColumn(page: Page, targetCol: Locator, expectedElCount: number) {
    await targetCol.scrollIntoViewIfNeeded();
    const box = await targetCol.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2, { steps: 30 });
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
   * - "between": midpoint of the gap between two elements
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
    const el1 = targetCol.getByTestId('element-card').filter({ hasText: position.between[0] });
    const el2 = targetCol.getByTestId('element-card').filter({ hasText: position.between[1] });
    const box1 = await el1.boundingBox();
    const box2 = await el2.boundingBox();
    expect(box1).not.toBeNull();
    expect(box2).not.toBeNull();
    return { x: centerX, y: (box1!.y + box1!.height + box2!.y) / 2 };
  }

  // --- Journey tests ---

  test('moves elements across columns in both directions', async ({ page }) => {
    await loadAndNavigate(page, 'cross-column-element-drop');
    const colA = getColumn(page, 'Col A');
    const colB = getColumn(page, 'Col B');

    // Col A [A1, A2, A3]   Col B [B1, B2, B3]
    await expect(colA.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element A2', 'Element A3']);
    await expect(colB.getByTestId('element-card-title')).toHaveText(['Element B1', 'Element B2', 'Element B3']);

    // Col A [A1, A2, A3]  →  Col A [A2, A3]
    // Col B [B1, B2, B3]  →  Col B [*A1*, B1, B2, B3]
    await test.step('Forward, before-first: A1 → Col B before B1', async () => {
      await activateDragByTitle(page, 'Element A1', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colB, 4);
      const pos = await elPosition(colB, { before: 'Element B1' });
      await dropAndSettle(page, pos.x, pos.y);
      await expect(colB.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element B1', 'Element B2', 'Element B3']);
    });

    // Col B [A1, B1, B2, B3]  →  Col B [A1, B1, B2]
    // Col A [A2, A3]           →  Col A [A2, *B3*, A3]
    await test.step('Reverse, between: B3 → Col A between A2,A3', async () => {
      await activateDragByTitle(page, 'Element B3', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colA, 3);
      const pos = await elPosition(colA, { between: ['Element A2', 'Element A3'] });
      await dropAndSettle(page, pos.x, pos.y);
      await expect(colA.getByTestId('element-card-title')).toHaveText(['Element A2', 'Element B3', 'Element A3']);
    });

    // Col B [A1, B1, B2]        →  Col B [A1, B1]
    // Col A [A2, B3, A3]        →  Col A [A2, B3, A3, *B2*]
    await test.step('Reverse, after-last: B2 → Col A after A3', async () => {
      await activateDragByTitle(page, 'Element B2', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colA, 4);
      const pos = await elPosition(colA, { after: 'Element A3' });
      await dropAndSettle(page, pos.x, pos.y);
      await expect(colA.getByTestId('element-card-title')).toHaveText(['Element A2', 'Element B3', 'Element A3', 'Element B2']);
    });

    // Col A [A2, B3, A3, B2]  →  Col A [A2, B3, A3]
    // Col B [A1, B1]           →  Col B [A1, *B2*, B1]
    await test.step('Forward, between: B2 → Col B between A1,B1', async () => {
      await activateDragByTitle(page, 'Element B2', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colB, 3);
      const pos = await elPosition(colB, { between: ['Element A1', 'Element B1'] });
      await dropAndSettle(page, pos.x, pos.y);
      await expect(colB.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element B2', 'Element B1']);
    });

    // Col A [A2, B3, A3]     →  Col A [A2, B3]
    // Col B [A1, B2, B1]     →  Col B [A1, B2, B1, *A3*]
    await test.step('Forward, after-last: A3 → Col B after B1', async () => {
      await activateDragByTitle(page, 'Element A3', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colB, 4);
      const pos = await elPosition(colB, { after: 'Element B1' });
      await dropAndSettle(page, pos.x, pos.y);
      await expect(colB.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element B2', 'Element B1', 'Element A3']);
    });

    // Col A [A2, B3]  →  Col A [*B3*, A2]
    await test.step('Intra-container: B3 before A2 in Col A', async () => {
      await activateDragByTitle(page, 'Element B3', { overlayTestId: 'drag-overlay-element' });
      const pos = await elPosition(colA, { before: 'Element A2' });
      await dropAndSettle(page, pos.x, pos.y);
      await expect(colA.getByTestId('element-card-title')).toHaveText(['Element B3', 'Element A2']);
    });

    // Verify persistence after reload
    await page.reload();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    const colAReloaded = getColumn(page, 'Col A');
    const colBReloaded = getColumn(page, 'Col B');
    await expect(colAReloaded.getByTestId('element-card-title')).toHaveText(['Element B3', 'Element A2']);
    await expect(colBReloaded.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element B2', 'Element B1', 'Element A3']);
  });

  test('handles edge cases: source depletion and cancel mid-drag', async ({ page }) => {
    // Col A [A1]           →  Col A []
    // Col B [B1, B2, B3]  →  Col B [B1, *A1*, B2, B3]
    await test.step('Source depletion: move lone element to target', async () => {
      await loadAndNavigate(page, 'cross-column-element-drop-single');
      const colA = getColumn(page, 'Col A');
      const colB = getColumn(page, 'Col B');
      await expect(colA.getByTestId('element-card')).toHaveCount(1);

      await activateDragByTitle(page, 'Element A1', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colB, 4);
      const pos = await elPosition(colB, { between: ['Element B1', 'Element B2'] });
      await dropAndSettle(page, pos.x, pos.y);

      await expect(colA.getByTestId('element-card')).toHaveCount(0);
      await expect(colB.getByTestId('element-card-title')).toHaveText(['Element B1', 'Element A1', 'Element B2', 'Element B3']);

      // Verify persistence
      await page.reload();
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
      const colBReloaded = getColumn(page, 'Col B');
      await expect(colBReloaded.getByTestId('element-card-title')).toHaveText(['Element B1', 'Element A1', 'Element B2', 'Element B3']);
    });

    // Drag A1 into Col B, press Escape → both columns revert to initial state
    await test.step('Cancel mid-drag reverts to original state', async () => {
      await loadAndNavigate(page, 'cross-column-element-drop');
      const colA = getColumn(page, 'Col A');
      const colB = getColumn(page, 'Col B');

      await activateDragByTitle(page, 'Element A1', { overlayTestId: 'drag-overlay-element' });
      await enterColumn(page, colB, 4);

      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      await expect(colA.getByTestId('element-card-title')).toHaveText(['Element A1', 'Element A2', 'Element A3']);
      await expect(colB.getByTestId('element-card-title')).toHaveText(['Element B1', 'Element B2', 'Element B3']);
    });
  });
});
