import { expect } from '@playwright/test'
import type { Locator, Page, Request } from '@playwright/test'

/**
 * Returns the viewport-relative center coordinates of a locator.
 * The element must already be visible in the viewport — call
 * `scrollIntoViewIfNeeded()` on both source and target before
 * measuring to avoid scroll-induced coordinate drift.
 */
async function getCenter(locator: Locator): Promise<{ x: number; y: number }> {
  const box = await locator.boundingBox()
  if (box === null) {
    throw new Error('Element not visible — cannot compute center for drag')
  }
  return { x: box.x + box.width / 2, y: box.y + box.height / 2 }
}

/**
 * Matches the drag-overlay *container* dnd-kit renders for any node type
 * (element/row/column/section). The container's testid is exactly
 * `drag-overlay-<type>`; its icon/title/meta children carry the same prefix
 * plus a `-icon`/`-title`/`-meta` suffix, so a prefix match (`^=`) would
 * resolve to multiple elements and break strict-mode `toBeVisible`. The
 * anchored regex matches the four container ids and none of the children.
 */
function dragOverlay(page: Page): Locator {
  return page.getByTestId(/^drag-overlay-(element|row|column|section)$/)
}

/** Locate a grid drag handle by its accessible label (e.g. "Move Row A1"). */
export function dragHandle(page: Page, name: string): Locator {
  return page.locator(`[data-testid="drag-handle"][aria-label="Move ${name}"]`)
}

/**
 * Wait until the drag overlay is mounted — the user-visible signal that
 * dnd-kit has activated the drag and React has rendered the DragOverlay.
 * Replaces a fixed post-activation sleep with the condition it stood in for.
 */
async function waitForDragOverlayVisible(page: Page): Promise<void> {
  await expect(dragOverlay(page)).toBeVisible()
}

/**
 * Wait until the drag overlay is gone — the user-visible signal that the
 * drop completed and dnd-kit tore down the DragOverlay after onDragEnd.
 */
async function waitForDragOverlayHidden(page: Page): Promise<void> {
  await expect(dragOverlay(page)).toBeHidden()
}

/**
 * Bounded pause for a dnd-kit/React reconciliation step that has no
 * user-visible end-state — collision detection settling at a hover
 * position before the DOM order changes. Kept as a short timeout because
 * there is no element whose visibility or text flips when this completes.
 */
function settleCollision(page: Page, ms = 150): Promise<void> {
  // biome-ignore lint/nursery/noPlaywrightWaitForTimeout: intentional bounded pause for a dnd-kit collision-settling step with no user-visible end-state (see doc comment above).
  return page.waitForTimeout(ms)
}

/**
 * Wait until the cross-container ghost PREVIEW has stopped re-rendering.
 *
 * The preview is driven by onDragMove: as the pointer settles at a drop target,
 * the pending tree re-renders the moved element into its slot, which shifts the
 * surrounding layout and can trigger one or two further re-renders before it
 * converges. Releasing during that convergence commits an intermediate slot —
 * a real user releases once the ghost is where they want it.
 *
 * Observes the grid editor's childList/subtree (structural reorders only; the
 * DragOverlay's transform is a style change and is ignored) and resolves once no
 * structural mutation has occurred for `quietMs`, or after `timeoutMs` as a
 * backstop. This is a condition-based settle — robust across browser timings
 * where a fixed pause is not (Firefox re-renders slower than Chromium).
 */
function waitForPreviewStable(page: Page, quietMs = 200, timeoutMs = 2000): Promise<void> {
  return page.evaluate(
    ({ quietDelay, hardDelay }) =>
      new Promise<void>((resolve) => {
        const roots = document.querySelectorAll('[data-testid="grid-editor"]')
        const observed = roots.length > 0 ? Array.from(roots) : [document.body]
        let quietTimer = 0
        const finish = () => {
          clearTimeout(quietTimer)
          clearTimeout(hardTimer)
          observer.disconnect()
          resolve()
        }
        const observer = new MutationObserver(() => {
          clearTimeout(quietTimer)
          quietTimer = window.setTimeout(finish, quietDelay)
        })
        for (const root of observed) {
          observer.observe(root, { childList: true, subtree: true })
        }
        const hardTimer = window.setTimeout(finish, hardDelay)
        quietTimer = window.setTimeout(finish, quietDelay)
      }),
    { quietDelay: quietMs, hardDelay: timeoutMs },
  )
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
  release: () => Promise<void>
}

/**
 * Complete a drag by releasing at viewport coordinate (x, y).
 *
 * Playwright's Firefox driver does not deliver the `pointerup` from
 * `page.mouse.up()` to the page after a synthetic drag — the page sees the
 * `pointerdown` and every `pointermove`, but never the release. dnd-kit's
 * PointerSensor listens for that `pointerup` on the document to end the drag,
 * so without it the drag never finishes: `onDragEnd` never fires, no reorder
 * request is sent, and the DragOverlay stays mounted. (This is a Playwright
 * test-driver limitation, not a product bug — real Firefox delivers the event.)
 *
 * We dispatch a genuine `pointerup` PointerEvent at the release coordinate so
 * the sensor's real handler runs and dnd-kit resolves the drop from the `over`
 * established by the preceding `pointermove`s — i.e. the exact product code path
 * (collision → `handleDragEnd` → reorder API), with the same drop placement a
 * real release produces. `page.mouse.up()` still follows to reset Playwright's
 * button state; engines that deliver the native release have already torn down
 * the sensor's listeners by then, so the duplicate is a harmless no-op.
 */
export async function releaseDrag(page: Page, x: number, y: number): Promise<void> {
  await page.evaluate(
    ({ clientX, clientY }) => {
      const target = document.elementFromPoint(clientX, clientY) ?? document.body
      target.dispatchEvent(
        new PointerEvent('pointerup', {
          bubbles: true,
          cancelable: true,
          clientX,
          clientY,
          button: 0,
          pointerType: 'mouse',
          isPrimary: true,
        }),
      )
    },
    { clientX: x, clientY: y },
  )
  await page.mouse.up()
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
export async function startDrag(page: Page, source: Locator, target: Locator): Promise<DragHandle> {
  // Scroll both elements into view before measuring. Target first (further
  // down), then source (closer to top) — scrolling the source last ensures
  // its coordinates are fresh for the imminent mouse-down. Without this,
  // scrolling the target can shift the source out of its measured position.
  await target.scrollIntoViewIfNeeded()
  await source.scrollIntoViewIfNeeded()

  const from = await getCenter(source)
  const to = await getCenter(target)

  // Move to source center and press
  await page.mouse.move(from.x, from.y)
  await page.mouse.down()

  // Move 10px toward target to exceed PointerSensor's 8px activation threshold
  const dx = to.x - from.x
  const dy = to.y - from.y
  const dist = Math.sqrt(dx * dx + dy * dy)
  const activationX = from.x + (dx / dist) * 10
  const activationY = from.y + (dy / dist) * 10

  await page.mouse.move(activationX, activationY, { steps: 3 })

  // dnd-kit has activated the drag once the DragOverlay is rendered.
  await waitForDragOverlayVisible(page)

  // Move to target center with many steps for smooth pointer tracking.
  // dnd-kit updates collision detection on each pointermove event.
  await page.mouse.move(to.x, to.y, { steps: 20 })

  // Let collision detection settle at the final position (no visible end-state).
  await settleCollision(page)

  return {
    release: async () => {
      await releaseDrag(page, to.x, to.y)
      // onDragEnd has completed once the DragOverlay is torn down.
      await waitForDragOverlayHidden(page)
    },
  }
}

/**
 * Perform a complete drag-and-drop from `source` to `target`.
 *
 * Both `source` and `target` should be drag-handle locators
 * (or any visible element whose center is the desired pointer position).
 */
export async function performDrag(page: Page, source: Locator, target: Locator): Promise<void> {
  const handle = await startDrag(page, source, target)
  await handle.release()
}

/**
 * Enter a target container mid-drag by moving the pointer to its center,
 * then wait for the pending tree to apply (child count reflects the
 * incoming ghost). Center entry keeps the trajectory reliable regardless
 * of where prior journey operations left the pointer.
 */
export async function enterContainerCenter(
  page: Page,
  container: Locator,
  childTestId: string,
  expectedCount: number,
): Promise<void> {
  await container.scrollIntoViewIfNeeded()
  const box = await container.boundingBox()
  if (box === null) {
    throw new Error('Container not visible — cannot enter for drag')
  }
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 30 })
  await expect(container.getByTestId(childTestId)).toHaveCount(expectedCount)
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
  )
  const refetchDone = page.waitForResponse(
    (resp) => resp.url().includes('/api/readTree/') && resp.ok(),
  )

  return async () => {
    await reorderDone
    await refetchDone
    // The PATCH + GET awaits above are the user-observable settle (the
    // network round-trip). The remaining pause covers dnd-kit re-registering
    // its droppable rects after React reconciles the refetched tree — an
    // internal measurement step with NO DOM end-state to assert on. Without
    // it, the next drag in a journey measures stale rects and drops in the
    // wrong position (see dnd-guide e2e-testing reference, "API settlement").
    // biome-ignore lint/nursery/noPlaywrightWaitForTimeout: intentional pause for dnd-kit droppable-rect re-measurement after refetch — no DOM end-state to assert on (see comment above).
    await page.waitForTimeout(500)
  }
}

/**
 * Count reorder API requests from this point on. Used by cancel-path tests
 * to assert a cancelled drag fired NO mutation — the visual revert alone
 * cannot distinguish "never sent" from "sent and rejected".
 */
export function watchReorderRequests(page: Page): { count: () => number; stop: () => void } {
  let seen = 0
  const onRequest = (request: Request) => {
    if (request.url().includes('/api/reorder')) seen++
  }
  page.on('request', onRequest)
  return { count: () => seen, stop: () => page.off('request', onRequest) }
}

/**
 * Move the mouse to target coordinates, release, and await mutation settlement.
 * Combines the final positioning move, mouse release, and API round-trip wait
 * into a single call for cross-container drop tests.
 */
export async function dropAndSettle(page: Page, targetX: number, targetY: number) {
  await page.mouse.move(targetX, targetY, { steps: 15 })
  // The cross-container ghost preview re-renders as the pointer settles into its
  // target slot. Wait for that to quiesce before releasing: the drop commits the
  // element to whatever slot the preview currently shows (the pending tree), so
  // releasing mid-re-render would commit an intermediate position — a real user
  // releases once the ghost is where they want it. This is a condition-based
  // settle, robust across browser timings where a fixed pause is not.
  await waitForPreviewStable(page)

  const settle = waitForMutationSettlement(page)
  await releaseDrag(page, targetX, targetY)
  await settle()
}

interface ActivateDragOptions {
  /** Axis for the 10px activation move. Default: 'vertical' (y+10). */
  axis?: 'vertical' | 'horizontal'
  /** If provided, asserts this overlay test ID is visible after activation. */
  overlayTestId?: string
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
  const { axis = 'vertical', overlayTestId } = options

  const handle = page.locator(`[data-testid="drag-handle"][aria-label="Move ${title}"]`)
  await handle.scrollIntoViewIfNeeded()
  const box = await handle.boundingBox()
  if (box === null) {
    throw new Error(`Drag handle for "${title}" not visible — cannot activate drag`)
  }
  const x = box.x + box.width / 2
  const y = box.y + box.height / 2

  await page.mouse.move(x, y)
  await page.mouse.down()

  const moveX = axis === 'horizontal' ? x + 10 : x
  const moveY = axis === 'vertical' ? y + 10 : y
  await page.mouse.move(moveX, moveY, { steps: 3 })

  // The overlay becoming visible is the user-visible signal that dnd-kit
  // activated the drag — assert on it instead of sleeping. When the caller
  // names the specific overlay, assert that one; otherwise assert that any
  // drag overlay rendered.
  if (overlayTestId) {
    await expect(page.getByTestId(overlayTestId)).toBeVisible()
  } else {
    await waitForDragOverlayVisible(page)
  }

  return { x, y }
}
