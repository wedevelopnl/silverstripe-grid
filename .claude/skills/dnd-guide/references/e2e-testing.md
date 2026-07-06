# E2E Testing Drag Operations

The DnD E2E tests are among the most timing-sensitive tests in the project. Missing a settlement wait or using too few pointer steps causes intermittent failures from stale collision rects. This reference captures the patterns that make them reliable.

## Table of Contents

1. [Timing Requirements](#timing-requirements)
2. [Helper Functions](#helper-functions)
3. [Positioning Strategies](#positioning-strategies)
4. [Journey Test Patterns](#journey-test-patterns)
5. [Edge Case Scenarios](#edge-case-scenarios)
6. [Common Pitfalls](#common-pitfalls)

## Timing Requirements

These are not arbitrary — each entry corresponds to a real async operation, either a bounded timeout or a condition assertion:

| Wait | Duration | What it's waiting for |
|------|----------|----------------------|
| Activation | DragOverlay-visible assertion | dnd-kit processed pointer events and React rendered the overlay (condition, not duration) |
| Pointer steps to target | 15-30 steps | Smooth tracking for collision detection (too few = detection misses) |
| Collision settlement (`startDrag` mid-drag) | 150-200ms bounded | Collision detection settles at the hover position, no user-visible end-state (used only where a mid-drag assertion follows) |
| Preview stability (`dropAndSettle`) | `MutationObserver` quiet 200ms / 2s backstop | Cross-container pending-tree preview stops re-rendering before release, so the drop commits the intended slot (condition-based, not a fixed pause) |
| API settlement | PATCH + GET + 500ms rect re-measurement | TanStack Query cache update → React re-render → dnd-kit re-registers droppable rects |

**The 500ms API settlement is critical for journey tests**: Without it, the next drag starts with stale collision rects from the previous tree state. This manifests as intermittent test failures where the second drag in a sequence drops in the wrong position.

## Helper Functions

All helpers live in `tests/E2E/helpers/drag.ts`.

### `startDrag(page, source, target)`

Orchestrates a drag from `source` to `target`, pausing mid-drag hovering over the target. Both args are locators (drag-handle locators, or any visible element whose center is the desired pointer position). Returns a handle with `release()`.

**Sequence**:
1. Scroll target into view first, then source (prevents source position shift from target scroll)
2. Move to source center, press mouse down
3. Move 10px toward target (3 steps) to exceed PointerSensor's 8px activation threshold
4. Assert the DragOverlay is visible — the condition that replaced the old activation sleep
5. Move to target center with 20 steps
6. Pause for collision detection to settle at the final position (bounded timeout, no visible end-state to assert)

`release()` calls `releaseDrag` at the target center, then waits for the DragOverlay to be hidden (the signal `onDragEnd` completed).

**Usage for mid-drag assertions**:
```typescript
const drag = await startDrag(page, sourceHandle, targetHandle);
await expect(overlay).toBeVisible();  // mid-drag assertion
await drag.release();
```

### `performDrag(page, source, target)`

Wrapper: `startDrag` + immediate `release()`. Use for simple drags without mid-drag assertions.

### `releaseDrag(page, x, y)`

Completes a drag by releasing at viewport coordinate `(x, y)`. Dispatches a genuine `pointerup` `PointerEvent` at that coordinate before calling `page.mouse.up()` — **never complete a drop with raw `page.mouse.up()`**. Playwright's Firefox driver does not deliver the `pointerup` from `page.mouse.up()` to the page after a synthetic drag (the page sees `pointerdown` and every `pointermove`, but never the release), and dnd-kit's `PointerSensor` listens for that `pointerup` on the document to end the drag. Without the dispatched event, `onDragEnd` never fires on Firefox: no reorder request, DragOverlay stays mounted. The dispatched event runs the sensor's real handler, resolving the drop from the `over` established by the preceding `pointermove`s — the same product code path a real release produces. `page.mouse.up()` still follows to reset Playwright's button state; on engines that already delivered the native release it's a harmless no-op.

### `waitForMutationSettlement(page)`

Registers response listeners for the reorder mutation lifecycle and returns an async settle function. **Must be called BEFORE the action that triggers the mutation** (the drag release) — it registers `page.waitForResponse` listeners that would miss the response if attached after the request already resolved. Calling the returned settle function:
1. Awaits the PATCH `/api/reorder` response (success)
2. Awaits the GET `/api/readTree/` refetch (success)
3. Pauses 500ms for TanStack Query's cache update, React's reconciliation, and dnd-kit re-registering its droppable rects — an internal step with no DOM end-state to assert on

### `dropAndSettle(page, targetX, targetY)`

Combines the final positioning move, mouse release, and API round-trip wait into one call for cross-container drop tests. Takes final viewport coordinates (not locators — call after a prior `activateDragByTitle`/`enterContainerCenter` has already established the mid-drag pointer position). Sequence: move 15 steps to `(targetX, targetY)` → wait for the ghost preview to stop re-rendering via `waitForPreviewStable` (module-private: a `MutationObserver` on the grid editor that resolves after 200ms of no structural mutation, 2s backstop) → register `waitForMutationSettlement` → `releaseDrag(page, targetX, targetY)` → await the settle function.

The `waitForPreviewStable` step is load-bearing, not a cosmetic pause: the drop commits whatever slot the pending-tree preview currently shows, so releasing mid-re-render would commit an intermediate position. It is condition-based (observes structural DOM mutations, ignores the DragOverlay's transform), which is why it is robust across browser timings where Firefox re-renders slower than Chromium.

### `activateDragByTitle(page, title, options?)`

Locates the drag handle by its accessible label (`[data-testid="drag-handle"][aria-label="Move ${title}"]`), scrolls it into view, presses mouse down, and moves 10px (3 steps) on the specified axis to exceed the 8px activation threshold. `options`: `{ axis?: 'vertical' | 'horizontal' }` (default `'vertical'`) and `{ overlayTestId?: string }` — when given, asserts that specific overlay test ID is visible; otherwise asserts any drag overlay rendered. Returns `{ x, y }`, the handle's center coordinates, useful for computing subsequent mouse moves relative to the drag origin.

### `dragHandle(page, name)`

Locates a drag handle by its accessible label — `[data-testid="drag-handle"][aria-label="Move ${name}"]`. A plain locator lookup, not a drag action; pairs directly with `performDrag`/`startDrag` (see `multi-zone.spec.ts`, `drag-and-drop.spec.ts`, `ghost-jump.spec.ts`).

### `enterContainerCenter(page, container, childTestId, expectedCount)`

Enters a target container mid-drag by scrolling it into view and moving the pointer to its bounding-box center (30 steps), then waits for `container.getByTestId(childTestId)` to reach `expectedCount` — the signal the pending tree applied and the incoming ghost is reflected. Used by `cross-column-element-drop.spec.ts` and `cross-row-column-drop.spec.ts` for container entry.

### `watchReorderRequests(page)`

Returns `{ count(), stop() }`. Registers a `page.on('request', ...)` listener counting requests whose URL includes `/api/reorder`. Used by cancel-path tests to assert a cancelled drag fired **no** mutation — the visual revert alone can't distinguish "never sent" from "sent and rejected". Call `stop()` to remove the listener once the assertion is made.

## Positioning Strategies

Direction detection depends on where the pointer lands relative to the target element's center. The strategies differ by axis.

### Vertical (Elements, Rows, Sections — Y-axis)

| Position | Pointer placement | Result |
|----------|------------------|--------|
| Before | Top 15% of target | `resolveInsertDirection` returns `'before'` |
| After | Bottom 65% of target | Returns `'after'` |
| Between two items | Gap midpoint between them | Depends on which element collision detection resolves to |

### Horizontal (Columns — X-axis)

| Position | Pointer placement | Result |
|----------|------------------|--------|
| Before | Left 15% of **outer grid cell** (droppable wrapper) | `'before'` |
| After | Right 65% of **outer grid cell** | `'after'` |
| Between two columns | Right 85% of left column's outer grid cell | Depends on collision resolution |

**Outer vs inner**: The droppable rect for columns is the SortableContext wrapper div, not the `column-block` inner div. Position calculations must use the outer element's bounding box. That outer cell is directly locatable via `data-testid="column-block-outer"` — see `cross-row-column-drop.spec.ts`'s `getOuterBox` helper, which filters it by the column's expand/collapse `aria-label` rather than reaching through `column-block`.

### Container Entry

For cross-container moves, enter the target container before positioning within it. Two strategies exist:

- `enterContainerCenter(page, container, childTestId, expectedCount)` (`helpers/drag.ts`) — move to the container's own bounding-box CENTER. A single center move keeps the trajectory reliable regardless of drag direction. Used by `cross-column-element-drop.spec.ts` and `cross-row-column-drop.spec.ts`.
- `enterAtFirst(page, targetSection, expectedRowCount)` / `enterFromBelow(page, targetSection, expectedRowCount)` — spec-local to `cross-section-drop.spec.ts` (not in `helpers/drag.ts`). Child-anchored entry: move to the target section's FIRST row center for downward entry, or -40px past the LAST row's center for upward entry.

**Bidirectional entry matters**: Moving UP into a container requires a different entry point than moving DOWN. The `-40px` offset past center for upward entry in `enterFromBelow` ensures the `centerCrossing` UP threshold is reliably crossed despite floating-point rounding at the boundary.

## Journey Test Patterns

Journey tests run multiple sequential drag operations in a single test. They are the most fragile DnD tests.

### Rules

1. **Locate elements by title, not DOM index**. After a drag, DOM indices change. The dimmed (dragging) element is still in the DOM during drag — counting by index will be off by one.

2. **Enter containers via their center** before positioning within children. After a previous drag, the pointer may be at an unpredictable position.

3. **Wait for full mutation settlement** between operations. The 500ms pause after API response is not optional.

4. **Account for tree state changes**. After moving Row A2 to Section B, Row A1 becomes the only row in Section A. The next operation must reference the new topology.

### Typical Journey Structure

```typescript
test('moves elements across containers', async ({ page }) => {
  // Operation 1: Forward (A→B), before-first
  await activateDragByTitle(page, 'Element A1', { overlayTestId: 'drag-overlay-element' });
  await enterContainerCenter(page, colB, 'element-card', 4);
  const pos1 = await elPosition(colB, { before: 'Element B1' });
  await dropAndSettle(page, pos1.x, pos1.y);
  await expect(colB.getByTestId('element-card-title')).toHaveText([/* ... */]);

  // Operation 2: Reverse (B→A), between
  await activateDragByTitle(page, 'Element B3', { overlayTestId: 'drag-overlay-element' });
  await enterContainerCenter(page, colA, 'element-card', 3);
  const pos2 = await elPosition(colA, { between: ['Element A2', 'Element A3'] });
  await dropAndSettle(page, pos2.x, pos2.y);
  await expect(colA.getByTestId('element-card-title')).toHaveText([/* ... */]);

  // Final: Verify persistence
  await page.reload();
  await expect(firstElement).toHaveText('Expected Title');
});
```

## Edge Case Scenarios

### Source Depletion

Moving the only item from a container leaves it empty. Test this explicitly:

- The source container should show 0 children after the drop
- The target container should show the expected count
- The drop should land at the correct position (not just "somewhere in the container")
- Verify persistence after reload

**Bidirectional depletion**: Test moving the lone item both downward (from above) and upward (from below), as these exercise different collision detection paths. This coverage now exists at all three hierarchy levels: rows (`cross-section-drop.spec.ts`), columns (`cross-row-column-drop.spec.ts`), and elements (`cross-column-element-drop.spec.ts`) — each has a forward "source depletion" step and a reverse "source depletion from below/reverse" step.

### Cancel Mid-Drag

Press Escape during a cross-container drag to cancel:

- Both source and target containers should revert to their initial state
- No API call should fire — assert this explicitly with `watchReorderRequests(page)` (registered before the drag starts, checked via `.count()` after Escape); the visual revert alone can't prove a request was never sent
- The pending tree should clear

**Overlap-gate ghost-departure regression**: `cross-section-drop.spec.ts`'s first journey step (`'Forward, before-first: A1 → Beta before B1'`) asserts the source section's row count drops to the expected value immediately after the pending move applies — before the drop completes. This is the regression coverage formerly owned by `cross-container-ghost.spec.ts` (since retired): without the overlap gate in `centerCrossing`, stale source-sibling collisions pin the ghost in the source container and this count never drops.

### Persistence Across Reload

Every journey test should end with `page.reload()` and verify the final state survived. This confirms the backend actually persisted the sort values.

### Publish and Frontend Render

For critical flows, publish the page and verify the frontend renders the reordered structure with correct content.

## Common Pitfalls

### Insufficient pointer steps
Using fewer than 15-20 steps for pointer movement causes collision detection to miss intermediate states. The pointer teleports past the target's center without triggering a detection cycle.

### Missing settlement wait
The most common cause of flaky DnD tests. After an API call, the query cache updates asynchronously. Without a 500ms pause, the next drag starts with stale rects.

### Scrolling order
Always scroll the target into view FIRST, then the source. If you scroll source first, scrolling the target may shift the source's position, causing the activation movement to miss the 8px threshold.

### Measuring after scroll
`scrollIntoViewIfNeeded()` can shift the CMS panel. Measure element positions AFTER scrolling, not before. The ghost-jump test was broken by this exact issue.

### Dimmed element interference
During a drag, the source element is dimmed (opacity: 0.3) but still in the DOM. When counting children or finding elements by index, the dimmed element is included. Use title-based selectors instead.
