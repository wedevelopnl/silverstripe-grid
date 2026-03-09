import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * Returns the viewport-relative center coordinates of a locator.
 * The element must already be visible in the viewport — call
 * `scrollIntoViewIfNeeded()` on both source and target before
 * measuring to avoid scroll-induced coordinate drift.
 */
async function getCenter(locator: Locator): Promise<{ x: number; y: number }> {
  const box = await locator.boundingBox();
  if (box === null) {
    throw new Error('Element not visible — cannot compute center for drag');
  }
  return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
}

/**
 * Small delay for the browser event loop and React reconciliation.
 * dnd-kit processes pointer events synchronously but React state
 * updates are batched and rendered asynchronously.
 */
function tick(page: Page, ms = 100): Promise<void> {
  return page.waitForTimeout(ms);
}

/**
 * dnd-kit uses PointerSensor with an 8px activation threshold.
 * Playwright's built-in dragTo() fires HTML5 DragEvents which dnd-kit
 * ignores. Instead, we simulate raw pointer moves that exceed the
 * activation distance and follow the drag lifecycle.
 *
 * Timing delays between steps are critical: dnd-kit processes pointer
 * events to update internal state, then React re-renders to update
 * droppable positions used by collision detection. Without delays,
 * the pointer reaches the target before React has re-rendered, causing
 * stale collision results and dropped events.
 */

interface DragHandle {
  /** Release the mouse to complete the drop. */
  release: () => Promise<void>;
}

/**
 * Start a drag from `source` toward `target` and pause mid-drag,
 * hovering over the target. Returns a handle to release the mouse,
 * enabling mid-drag assertions (e.g. overlay visibility, drop-target
 * highlighting).
 *
 * Both `source` and `target` should be drag-handle locators
 * (or any visible element whose center is the desired pointer position).
 */
export async function startDrag(
  page: Page,
  source: Locator,
  target: Locator,
): Promise<DragHandle> {
  // Scroll both elements into view before measuring. Target first (further
  // down), then source (closer to top) — scrolling the source last ensures
  // its coordinates are fresh for the imminent mouse-down. Without this,
  // scrolling the target can shift the source out of its measured position.
  await target.scrollIntoViewIfNeeded();
  await source.scrollIntoViewIfNeeded();

  const from = await getCenter(source);
  const to = await getCenter(target);

  // Move to source center and press
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();

  // Move 10px toward target to exceed PointerSensor's 8px activation threshold
  const dx = to.x - from.x;
  const dy = to.y - from.y;
  const dist = Math.sqrt(dx * dx + dy * dy);
  const activationX = from.x + (dx / dist) * 10;
  const activationY = from.y + (dy / dist) * 10;

  await page.mouse.move(activationX, activationY, { steps: 3 });

  // Let dnd-kit activate the drag and React render the DragOverlay
  await tick(page, 150);

  // Move to target center with many steps for smooth pointer tracking.
  // dnd-kit updates collision detection on each pointermove event.
  await page.mouse.move(to.x, to.y, { steps: 20 });

  // Let collision detection settle at the final position
  await tick(page, 150);

  return {
    release: async () => {
      await page.mouse.up();
      // Let React process the onDragEnd state update
      await tick(page, 150);
    },
  };
}

/**
 * Perform a complete drag-and-drop from `source` to `target`.
 *
 * Both `source` and `target` should be drag-handle locators
 * (or any visible element whose center is the desired pointer position).
 */
export async function performDrag(
  page: Page,
  source: Locator,
  target: Locator,
): Promise<void> {
  const handle = await startDrag(page, source, target);
  await handle.release();
}

/**
 * Register response listeners for the reorder mutation lifecycle.
 * Must be called BEFORE the action that triggers the mutation (drag release).
 *
 * Returns an async settle function that awaits both the PATCH /api/reorder
 * and the subsequent GET /api/readTree/ refetch, then pauses for React to
 * reconcile TanStack Query's cache update and dnd-kit to re-register
 * droppable rects. Without this, the next drag can start while droppable
 * positions are stale, causing collision detection to resolve incorrectly.
 */
export function waitForMutationSettlement(page: Page) {
  const reorderDone = page.waitForResponse(
    (resp) => resp.url().includes('/api/reorder') && resp.ok(),
  );
  const refetchDone = page.waitForResponse(
    (resp) => resp.url().includes('/api/readTree/') && resp.ok(),
  );

  return async () => {
    await reorderDone;
    await refetchDone;
    // After the refetch response arrives, TanStack Query updates its
    // cache asynchronously, React batches a re-render, and dnd-kit
    // re-registers droppable rects. A 500ms pause lets this full
    // chain settle before the next drag measures element positions.
    await page.waitForTimeout(500);
  };
}

/**
 * Move the mouse to target coordinates, release, and await mutation settlement.
 * Combines the final positioning move, mouse release, and API round-trip wait
 * into a single call for cross-container drop tests.
 */
export async function dropAndSettle(page: Page, targetX: number, targetY: number) {
  await page.mouse.move(targetX, targetY, { steps: 15 });
  await page.waitForTimeout(200);

  const settle = waitForMutationSettlement(page);
  await page.mouse.up();
  await settle();
}

interface ActivateDragOptions {
  /** Axis for the 10px activation move. Default: 'vertical' (y+10). */
  axis?: 'vertical' | 'horizontal';
  /** If provided, asserts this overlay test ID is visible after activation. */
  overlayTestId?: string;
}

/**
 * Activate a drag by locating the drag handle for the given title,
 * pressing mouse down, and moving 10px on the specified axis to exceed
 * dnd-kit's 8px PointerSensor activation threshold.
 *
 * Returns the handle's center coordinates, useful for computing
 * subsequent mouse moves relative to the drag origin.
 */
export async function activateDragByTitle(
  page: Page,
  title: string,
  options: ActivateDragOptions = {},
): Promise<{ x: number; y: number }> {
  const { axis = 'vertical', overlayTestId } = options;

  const handle = page.locator(`[data-testid="drag-handle"][aria-label="Move ${title}"]`);
  await handle.scrollIntoViewIfNeeded();
  const box = await handle.boundingBox();
  if (box === null) {
    throw new Error(`Drag handle for "${title}" not visible — cannot activate drag`);
  }
  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;

  await page.mouse.move(x, y);
  await page.mouse.down();

  const moveX = axis === 'horizontal' ? x + 10 : x;
  const moveY = axis === 'vertical' ? y + 10 : y;
  await page.mouse.move(moveX, moveY, { steps: 3 });
  await page.waitForTimeout(150);

  if (overlayTestId) {
    await expect(page.getByTestId(overlayTestId)).toBeVisible();
  }

  return { x, y };
}
