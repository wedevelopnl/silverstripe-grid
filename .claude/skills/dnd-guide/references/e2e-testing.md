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

These are not arbitrary — each wait corresponds to a real async operation:

| Wait | Duration | What it's waiting for |
|------|----------|----------------------|
| Activation | 150ms | dnd-kit processes pointer events → React renders DragOverlay |
| Pointer steps to target | 20 steps | Smooth tracking for collision detection (too few = detection misses) |
| Collision settlement | 150-200ms | Collision detection settles, pending tree applies if cross-container |
| API settlement | 500ms after PATCH + GET | TanStack Query cache update → React re-render → dnd-kit re-registers droppable rects |

**The 500ms API settlement is critical for journey tests**: Without it, the next drag starts with stale collision rects from the previous tree state. This manifests as intermittent test failures where the second drag in a sequence drops in the wrong position.

## Helper Functions

All helpers live in `tests/E2E/helpers/drag.ts`.

### `startDrag(page, source, target, options?)`

Orchestrates mid-drag assertions. Returns a handle with `release()`.

**Sequence**:
1. Scroll target first, then source (prevents source position shift from target scroll)
2. Calculate 10px movement toward target to exceed PointerSensor's 8px activation threshold
3. Move with 3 steps to activation point
4. Wait 150ms for DragOverlay to render
5. Move to target with 20 steps
6. Wait 150ms for collision detection to settle

**Usage for mid-drag assertions**:
```typescript
const drag = await startDrag(page, sourceHandle, targetHandle);
await expect(overlay).toBeVisible();  // mid-drag assertion
await drag.release();
```

### `performDrag(page, source, target)`

Wrapper: `startDrag` + immediate release. Use for simple drags without mid-drag assertions.

### `waitForMutationSettlement(page)`

Waits for the complete mutation lifecycle:
1. Intercepts PATCH `/api/reorder` response (success)
2. Intercepts GET `/api/readTree/` refetch (success)
3. Pauses 500ms for cache → render → rect re-registration

### `dropAndSettle(page, source, target)`

Combines: move 15 steps to target → wait 200ms → release → `waitForMutationSettlement`. Use for cross-container drops in journey tests.

### `activateDragByTitle(page, title, options?)`

Locates element by drag handle `aria-label`, activates drag with optional axis selection. Returns element locator for further assertions.

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

**Outer vs inner**: The droppable rect for columns is the SortableContext wrapper div, not the `column-block` inner div. Position calculations must use the outer element's bounding box.

### Container Entry

For cross-container moves, enter the target container before positioning within it:

- `enterColumn()` — Move to column CENTER (not a specific child). Ensures trajectory reliably triggers container entry regardless of prior pointer position.
- `enterAtFirst()` — For downward entry (top of container)
- `enterFromBelow()` — For upward entry; offset -40px past center to ensure `centerCrossing`'s threshold reliably crosses (avoids floating-point rounding at boundary)

**Bidirectional entry matters**: Moving UP into a container requires a different entry point than moving DOWN. The `-40px` offset past center for upward entry was added to fix intermittent failures from floating-point precision at the threshold boundary.

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
  await dropAndSettle(page, sourceHandle, targetHandle);
  await expect(targetChildren).toHaveCount(expectedCount);

  // Operation 2: Reverse (B→A), between
  await dropAndSettle(page, sourceHandle2, targetHandle2);
  await expect(targetChildren2).toHaveCount(expectedCount2);

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

**Bidirectional depletion**: Test moving the lone item both downward (from above) and upward (from below), as these exercise different collision detection paths.

### Cancel Mid-Drag

Press Escape during a cross-container drag to cancel:

- Both source and target containers should revert to their initial state
- No API call should fire
- The pending tree should clear

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
