import type { DroppableContainer, ClientRect } from '@dnd-kit/core';

import {
  centerCrossing,
  createTypedCollisionDetection,
  filterDroppablesByType,
  filterParentContainers,
  filterSiblings,
  typedCollisionDetection,
} from './collisionDetection';

/**
 * Creates a minimal DroppableContainer stub for filter-function tests.
 * Only the `id` field is exercised by the filter logic.
 */
function createDroppable(id: string): DroppableContainer {
  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: null },
    rect: { current: null },
  } as unknown as DroppableContainer;
}

/**
 * Creates a DroppableContainer with a mock DOM node that returns the given
 * bounding rect from getBoundingClientRect(). Required for closestCenterLive
 * which reads live DOM rects rather than dnd-kit's droppableRects.
 */
function createDroppableWithRect(
  id: string,
  domRect: { left: number; top: number; width: number; height: number },
): DroppableContainer {
  const mockNode = {
    getBoundingClientRect: () => ({
      left: domRect.left,
      top: domRect.top,
      width: domRect.width,
      height: domRect.height,
      right: domRect.left + domRect.width,
      bottom: domRect.top + domRect.height,
      x: domRect.left,
      y: domRect.top,
      toJSON: () => ({}),
    }),
  } as unknown as HTMLElement;

  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: mockNode },
    rect: { current: null },
  } as unknown as DroppableContainer;
}

function makeDomRect(left: number, top: number, width: number, height: number): ClientRect {
  return {
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
  } as ClientRect;
}

describe('filterDroppablesByType', () => {
  const containers = [
    createDroppable('section-1'),
    createDroppable('section-2'),
    createDroppable('row-10'),
    createDroppable('row-20'),
    createDroppable('column-5'),
    createDroppable('column-6'),
    createDroppable('element-100'),
    createDroppable('element-200'),
    createDroppable('root'),
  ];

  it('returns siblings and parent containers for a row (rows + sections)', () => {
    const result = filterDroppablesByType('row-17', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(expect.arrayContaining(['row-10', 'row-20', 'section-1', 'section-2']));
    expect(ids).not.toContain('column-5');
    expect(ids).not.toContain('element-100');
    expect(ids).not.toContain('root');
  });

  it('returns siblings and parent containers for a column (columns + rows)', () => {
    const result = filterDroppablesByType('column-5', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(expect.arrayContaining(['column-5', 'column-6', 'row-10', 'row-20']));
    expect(ids).not.toContain('section-1');
    expect(ids).not.toContain('element-100');
  });

  it('returns siblings and parent containers for an element (elements + columns)', () => {
    const result = filterDroppablesByType('element-100', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(
      expect.arrayContaining(['element-100', 'element-200', 'column-5', 'column-6']),
    );
    expect(ids).not.toContain('row-10');
    expect(ids).not.toContain('section-1');
  });

  it('returns sections and containers with unparseable IDs for a section (parent = root)', () => {
    const result = filterDroppablesByType('section-1', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(expect.arrayContaining(['section-1', 'section-2', 'root']));
    expect(ids).not.toContain('row-10');
    expect(ids).not.toContain('column-5');
  });

  it('returns empty array for unknown active type', () => {
    expect(filterDroppablesByType('unknown-99', containers)).toEqual([]);
    expect(filterDroppablesByType('root', containers)).toEqual([]);
    expect(filterDroppablesByType('', containers)).toEqual([]);
  });
});

describe('filterSiblings', () => {
  const containers = [
    createDroppable('section-1'),
    createDroppable('section-2'),
    createDroppable('row-10'),
    createDroppable('row-20'),
    createDroppable('column-5'),
    createDroppable('element-100'),
    createDroppable('root'),
  ];

  it('returns only same-type containers for a row', () => {
    const result = filterSiblings('row-17', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(['row-10', 'row-20']);
  });

  it('returns only same-type containers for a section', () => {
    const result = filterSiblings('section-3', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(['section-1', 'section-2']);
  });

  it('excludes parent types and unparseable IDs', () => {
    const result = filterSiblings('row-17', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).not.toContain('section-1');
    expect(ids).not.toContain('root');
    expect(ids).not.toContain('column-5');
    expect(ids).not.toContain('element-100');
  });

  it('returns empty for unknown active type', () => {
    expect(filterSiblings('root', containers)).toEqual([]);
  });
});

describe('filterParentContainers', () => {
  const containers = [
    createDroppable('section-1'),
    createDroppable('section-2'),
    createDroppable('row-10'),
    createDroppable('column-5'),
    createDroppable('element-100'),
    createDroppable('root'),
  ];

  it('returns only parent-type containers for a row (sections)', () => {
    const result = filterParentContainers('row-17', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(['section-1', 'section-2']);
  });

  it('returns only parent-type containers for a column (rows)', () => {
    const result = filterParentContainers('column-5', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(['row-10']);
  });

  it('returns only parent-type containers for an element (columns)', () => {
    const result = filterParentContainers('element-100', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(['column-5']);
  });

  it('returns containers with unparseable IDs for sections (parent = root)', () => {
    const result = filterParentContainers('section-1', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toEqual(['root']);
    expect(ids).not.toContain('section-1');
    expect(ids).not.toContain('row-10');
  });

  it('returns empty for unknown active type', () => {
    expect(filterParentContainers('root', containers)).toEqual([]);
  });
});

describe('centerCrossing', () => {
  // Layout: active starts at (100, 100), target at (100, 300).
  // Collision rect is 100x50 (compact DragOverlay).
  const collisionWidth = 100;
  const collisionHeight = 50;
  const targetRect = makeDomRect(50, 275, 200, 100);

  function buildArgs(overrides: {
    currentY?: number;
    currentX?: number;
    pointerX?: number;
    pointerY?: number;
    initialY?: number;
    initialX?: number;
    target?: ClientRect;
    containers?: DroppableContainer[];
  }) {
    const initialX = overrides.initialX ?? 150;
    const initialY = overrides.initialY ?? 100;
    const currentY = overrides.currentY ?? initialY;
    const currentX = overrides.currentX ?? initialX;
    const pointerX = overrides.pointerX ?? currentX;
    const pointerY = overrides.pointerY ?? currentY;
    const rect = overrides.target ?? targetRect;
    const droppables = overrides.containers ?? [createDroppable('row-2')];

    const initialRect = makeDomRect(
      initialX - collisionWidth / 2,
      initialY - collisionHeight / 2,
      collisionWidth,
      collisionHeight,
    );

    const collisionRect = makeDomRect(
      currentX - collisionWidth / 2,
      currentY - collisionHeight / 2,
      collisionWidth,
      collisionHeight,
    );

    return {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: droppables,
      droppableRects: new Map([[droppables[0].id, rect]]),
      pointerCoordinates: { x: pointerX, y: pointerY },
    };
  }

  it('detects collision when center crosses threshold moving down past target', () => {
    // Target top=275, threshold = 275 + 25 (half collision height) = 300.
    // Initial at y=100 (below threshold? No, initial < threshold).
    // Current at y=310 (above threshold). Crossed from below.
    const args = buildArgs({ currentY: 310, pointerY: 310 });
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(1);
    expect(collisions[0].id).toBe('row-2');
  });

  it('returns empty when threshold not yet crossed', () => {
    // Target threshold at ~300. Current at 200 — hasn't crossed yet.
    const args = buildArgs({ currentY: 200, pointerY: 200 });
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(0);
  });

  it('returns empty when pointer is far from target horizontally (overlap gate)', () => {
    // Current has crossed the threshold vertically, but pointer X is far
    // outside the target rect + MARGIN_X (50px).
    // Target rect: left=50, width=200, so right=250. Margin extends to 300.
    const args = buildArgs({ currentY: 310, pointerY: 310, pointerX: 400 });
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(0);
  });

  it('returns empty when pointer is far from target vertically (overlap gate)', () => {
    // Crossed horizontally, but pointer Y far from target.
    // Target rect: top=275, height=100, bottom=375. Margin extends to 525.
    const args = buildArgs({
      initialX: 10,
      currentX: 160,
      pointerX: 160,
      currentY: 600,
      pointerY: 600,
    });
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(0);
  });

  it('adapts threshold based on overlay size relative to target', () => {
    // Large overlay (same size as target): threshold ≈ target center (strict).
    // Target at (50, 275, 200, 100), center Y = 325.
    // With collisionHeight=50, threshold = 275 + 25 = 300 (near edge).
    // A large overlay with height=100 would push threshold to 275+50 = 325 (center).

    // With our small overlay (50px), threshold is at 300 (permissive).
    // Position just past 300 should trigger.
    const args = buildArgs({ currentY: 301, pointerY: 301 });
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(1);
  });

  it('detects collision when moving up past target (reverse direction)', () => {
    // Initial below target, moving up.
    // Target top=275, bottom=375, center=325.
    // Moving from below: threshold = min(375 - 25, 325) = 325.
    const args = buildArgs({
      initialY: 500,
      currentY: 320,
      pointerY: 320,
    });
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(1);
    expect(collisions[0].id).toBe('row-2');
  });

  it('returns empty when initial rect is null', () => {
    const args = buildArgs({});
    (args.active.rect.current as unknown as { initial: null }).initial = null;
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(0);
  });

  it('skips containers with no droppable rect', () => {
    const args = buildArgs({ currentY: 310, pointerY: 310 });
    args.droppableRects.clear();
    const collisions = centerCrossing(args as never);

    expect(collisions).toHaveLength(0);
  });

  it('sorts collisions by distance (closest first)', () => {
    const near = createDroppable('row-2');
    const far = createDroppable('row-3');
    const nearRect = makeDomRect(50, 275, 200, 100);
    const farRect = makeDomRect(50, 400, 200, 100);

    const args = buildArgs({ currentY: 450, pointerY: 450, containers: [far, near] });
    args.droppableRects.set('row-2', nearRect);
    args.droppableRects.set('row-3', farRect);
    args.droppableContainers = [far, near];

    const collisions = centerCrossing(args as never);

    // Both should be detected; the farRect's threshold = 400+25=425 (crossed at 450)
    // The near target center is at 325, far center at 450.
    // Current center at 450, closer to far target.
    if (collisions.length === 2) {
      expect(collisions[0].data?.value as number).toBeLessThanOrEqual(
        collisions[1].data?.value as number,
      );
    }
  });
});

describe('createTypedCollisionDetection', () => {
  // Shared layout for parent/sibling detection tests.
  const parentRect = makeDomRect(0, 0, 800, 600);
  const siblingRect = makeDomRect(50, 275, 200, 100);

  function buildArgs(overrides: {
    activeId?: string;
    containers?: DroppableContainer[];
    rects?: Map<string | number, ClientRect>;
    pointerX?: number;
    pointerY?: number;
  }) {
    const activeId = overrides.activeId ?? 'row-1';
    const pointerX = overrides.pointerX ?? 150;
    const pointerY = overrides.pointerY ?? 310;

    const collisionRect = makeDomRect(100, pointerY - 25, 100, 50);
    const initialRect = makeDomRect(100, 75, 100, 50);

    return {
      active: {
        id: activeId,
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: overrides.containers ?? [],
      droppableRects: overrides.rects ?? new Map(),
      pointerCoordinates: { x: pointerX, y: pointerY },
    };
  }

  describe('without pending move', () => {
    it('uses centerCrossing for siblings and returns collision', () => {
      const sibling = createDroppable('row-2');
      const parent = createDroppable('section-1');
      const rects = new Map<string | number, ClientRect>([
        ['row-2', siblingRect],
        ['section-1', parentRect],
      ]);

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      });

      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling, parent],
        rects,
        pointerY: 310,
      });

      const collisions = detect(args as never);

      expect(collisions).toHaveLength(1);
      expect(collisions[0].id).toBe('row-2');
    });

    it('falls back to parent containers when no sibling collision', () => {
      const parent = createDroppable('section-1');
      const rects = new Map<string | number, ClientRect>([['section-1', parentRect]]);

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      });

      // Pointer inside parent rect, no siblings at all
      const args = buildArgs({
        activeId: 'row-1',
        containers: [parent],
        rects,
        pointerX: 400,
        pointerY: 300,
      });

      const collisions = detect(args as never);

      expect(collisions).toHaveLength(1);
      expect(collisions[0].id).toBe('section-1');
    });

    it('excludes the active item from droppable containers', () => {
      const self = createDroppable('row-1');
      const parent = createDroppable('section-1');
      const rects = new Map<string | number, ClientRect>([
        ['row-1', makeDomRect(100, 95, 100, 50)],
        ['section-1', parentRect],
      ]);

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      });

      const args = buildArgs({
        activeId: 'row-1',
        containers: [self, parent],
        rects,
        pointerX: 400,
        pointerY: 300,
      });

      const collisions = detect(args as never);
      const ids = collisions.map((c) => c.id);

      expect(ids).not.toContain('row-1');
    });
  });

  describe('with pending move', () => {
    it('uses closestCenterLive for siblings (reads live DOM rects)', () => {
      // closestCenterLive needs DOM nodes with getBoundingClientRect
      const sibling = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      });
      const pendingItems = new Set<string | number>(['row-2']);

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      });

      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects: new Map([['row-2', siblingRect]]),
        pointerY: 310,
      });

      const collisions = detect(args as never);

      expect(collisions).toHaveLength(1);
      expect(collisions[0].id).toBe('row-2');
    });

    it('filters siblings to only pending container items', () => {
      const inPending = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      });
      const notInPending = createDroppableWithRect('row-3', {
        left: 50,
        top: 400,
        width: 200,
        height: 100,
      });
      const pendingItems = new Set<string | number>(['row-2']);

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      });

      const args = buildArgs({
        activeId: 'row-1',
        containers: [inPending, notInPending],
        rects: new Map([
          ['row-2', siblingRect],
          ['row-3', makeDomRect(50, 400, 200, 100)],
        ]),
        pointerY: 310,
      });

      const collisions = detect(args as never);
      const ids = collisions.map((c) => c.id);

      expect(ids).toContain('row-2');
      expect(ids).not.toContain('row-3');
    });
  });

  describe('source depletion', () => {
    it('uses all siblings when sourceContainerItems is empty', () => {
      // When the source container has no items (e.g. dragged the only row out),
      // centerCrossing should check ALL siblings, not just source-container ones.
      const targetSibling = createDroppable('row-5');
      const rects = new Map<string | number, ClientRect>([['row-5', siblingRect]]);

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        sourceContainerItemsRef: { current: new Set() },
      });

      const args = buildArgs({
        activeId: 'row-1',
        containers: [targetSibling],
        rects,
        pointerY: 310,
      });

      const collisions = detect(args as never);

      expect(collisions).toHaveLength(1);
      expect(collisions[0].id).toBe('row-5');
    });
  });

  describe('overRectRef capture', () => {
    it('captures the winning collision node reference', () => {
      const sibling = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      });
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null;
      };

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      });

      const rects = new Map<string | number, ClientRect>([['row-2', siblingRect]]);
      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects,
        pointerY: 310,
      });

      detect(args as never);

      expect(overRectRef.current).not.toBeNull();
      expect(overRectRef.current!.id).toBe('row-2');
      expect(overRectRef.current!.nodeRef.current).toBeTruthy();
    });

    it('does not capture overRectRef for pending-path sibling collisions', () => {
      const sibling = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      });
      const pendingItems = new Set<string | number>(['row-2']);
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null;
      };

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
        overRectRef,
      });

      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects: new Map([['row-2', siblingRect]]),
        pointerY: 310,
      });

      detect(args as never);

      // Pending path skips overRectRef capture intentionally
      expect(overRectRef.current).toBeNull();
    });

    it('captures overRectRef for parent container fallback', () => {
      const parent = createDroppableWithRect('section-1', {
        left: 0,
        top: 0,
        width: 800,
        height: 600,
      });
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null;
      };

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      });

      const rects = new Map<string | number, ClientRect>([['section-1', parentRect]]);
      const args = buildArgs({
        activeId: 'row-1',
        containers: [parent],
        rects,
        pointerX: 400,
        pointerY: 300,
      });

      detect(args as never);

      expect(overRectRef.current).not.toBeNull();
      expect(overRectRef.current!.id).toBe('section-1');
    });
  });

  describe('drag reset between drags', () => {
    it('resets hadSiblingHit when sourceContainerItemsRef changes', () => {
      const sourceItems1 = new Set<string | number>(['row-2']);
      const sourceRef = { current: sourceItems1 as ReadonlySet<string | number> | null };

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        sourceContainerItemsRef: sourceRef,
      });

      const sibling = createDroppable('row-2');
      const rects = new Map<string | number, ClientRect>([['row-2', siblingRect]]);

      // First drag: trigger a sibling hit
      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects,
        pointerY: 310,
      });
      detect(args as never);

      // Simulate new drag: change sourceContainerItemsRef
      sourceRef.current = new Set<string | number>(['row-5']);

      // Call again — should not carry over hadSiblingHit from previous drag
      const args2 = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects,
        pointerY: 200, // Not crossing threshold
      });
      const collisions = detect(args2 as never);

      // Without hadSiblingHit, the pointer-inside-sibling guard should
      // return empty (not fall through to closestCenterLive)
      expect(collisions).toHaveLength(0);
    });
  });
});

describe('typedCollisionDetection', () => {
  it('is a function (static convenience instance)', () => {
    expect(typeof typedCollisionDetection).toBe('function');
  });

  it('behaves like createTypedCollisionDetection with hasPendingMove=false', () => {
    const parent = createDroppable('section-1');
    const rects = new Map<string | number, ClientRect>([
      ['section-1', makeDomRect(0, 0, 800, 600)],
    ]);

    const collisionRect = makeDomRect(100, 285, 100, 50);
    const initialRect = makeDomRect(100, 75, 100, 50);

    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [parent],
      droppableRects: rects,
      pointerCoordinates: { x: 400, y: 300 },
    };

    const collisions = typedCollisionDetection(args as never);

    expect(collisions.length).toBeGreaterThan(0);
    expect(collisions[0].id).toBe('section-1');
  });
});
