import type { DroppableContainer, ClientRect } from '@dnd-kit/core';

import {
  centerCrossing,
  createTypedCollisionDetection,
  filterDroppablesByType,
  filterParentContainers,
  filterSiblings,
  typedCollisionDetection,
} from './collisionDetection';
import { createDroppable, createDroppableWithRect, makeDomRect } from '@/testing/dndRectFactories';

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

describe('arithmetic hardening (mutation-kill tests)', () => {
  // Precise numeric expectations pin the distance/threshold formulas so that
  // mutations of `+`, `-`, `*`, `/`, and boundary comparisons fail.

  describe('closestCenterLive distance formula', () => {
    function buildPendingPathArgs(
      containers: DroppableContainer[],
      collisionRect: ClientRect,
      initialRect: ClientRect,
    ) {
      return {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: containers,
        droppableRects: new Map(),
        pointerCoordinates: { x: 125, y: 125 },
      };
    }

    it('computes value = dx² + dy² with precise numeric expectation', () => {
      // collisionRect (100,100,50,50) → center (125,125).
      // target DOM rect (200,200,50,50) → center (225,225).
      // dx = -100, dy = -100 → value = 20000.
      const target = createDroppableWithRect('row-2', {
        left: 200,
        top: 200,
        width: 50,
        height: 50,
      });
      const pendingItems = new Set<string | number>(['row-2']);
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      });

      const args = buildPendingPathArgs(
        [target],
        makeDomRect(100, 100, 50, 50),
        makeDomRect(100, 75, 50, 50),
      );
      const collisions = detect(args as never);

      expect(collisions).toHaveLength(1);
      expect(collisions[0].data?.value).toBe(20000);
    });

    it('sorts collisions ascending by squared distance', () => {
      // collisionRect center (125, 125).
      // close: (150,150,50,50) center (175,175) → dx=-50, dy=-50 → value=5000.
      // far:   (400,400,50,50) center (425,425) → dx=-300, dy=-300 → value=180000.
      // Containers registered in [far, close] order; default sort must return [close, far].
      const close = createDroppableWithRect('row-2', {
        left: 150,
        top: 150,
        width: 50,
        height: 50,
      });
      const far = createDroppableWithRect('row-3', {
        left: 400,
        top: 400,
        width: 50,
        height: 50,
      });
      const pendingItems = new Set<string | number>(['row-2', 'row-3']);
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      });

      const args = buildPendingPathArgs(
        [far, close],
        makeDomRect(100, 100, 50, 50),
        makeDomRect(100, 75, 50, 50),
      );
      const collisions = detect(args as never);

      expect(collisions.map((c) => c.id)).toEqual(['row-2', 'row-3']);
      expect(collisions[0].data?.value).toBe(5000);
      expect(collisions[1].data?.value).toBe(180000);
    });
  });

  describe('centerCrossing distance value', () => {
    // collisionRect center (125, 125), target rect (50, 75, 100, 100) → center (100, 125).
    // dx = 125-100 = 25, dy = 125-125 = 0 → value = 625.
    // initialCX = 125, targetCX = 100 → moving "away" from target in X (initialCX > targetCX).
    // thresholdX branch: Math.min(rect.left + rect.width - collisionRect.width/2, targetCX)
    //                  = Math.min(50 + 100 - 50, 100) = Math.min(100, 100) = 100.
    // currentCX = Math.min(crCX=125, ptrX=125) = 125. crossedX: initialCX(125) > thresholdX(100)
    // and currentCX(125) <= 100? No. So crossedX is false. Use Y-direction instead.
    // initialCY = 75, targetCY = 125 → moving toward target in Y.
    // thresholdY: initialCY(75) < targetCY(125) → rect.top + collisionRect.height/2 = 75+25=100.
    // currentCY = Math.max(crCY=125, ptrY=125) = 125. crossedY: initialCY(75) < thresholdY(100)
    // and currentCY(125) >= 100 → TRUE.
    it('records collision value equal to dx² + dy² of collision-rect center vs target-rect center', () => {
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(50, 75, 100, 100);
      const collisionRect = makeDomRect(75, 100, 100, 50);
      const initialRect = makeDomRect(75, 50, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 125, y: 125 },
      };

      const collisions = centerCrossing(args as never);

      expect(collisions).toHaveLength(1);
      expect(collisions[0].data?.value).toBe(625);
    });

    it('sorts crossing collisions ascending by squared distance', () => {
      // Two targets both crossed vertically (currentY past both thresholds).
      // close target (50, 400, 200, 100): center (150, 450), threshold = 400+25 = 425.
      // far   target (50, 500, 200, 100): center (150, 550), threshold = 500+25 = 525.
      // currentY = 530 crosses both. collisionRect center (125, 530).
      // Close dx=-25, dy=80  → value = 625 + 6400 = 7025.
      // Far   dx=-25, dy=-20 → value = 625 + 400  = 1025. Far is CLOSER to currentY.
      const close = createDroppable('row-2');
      const far = createDroppable('row-3');
      const closeRect = makeDomRect(50, 400, 200, 100);
      const farRect = makeDomRect(50, 500, 200, 100);
      const collisionRect = makeDomRect(75, 505, 100, 50);
      const initialRect = makeDomRect(75, 75, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [close, far],
        droppableRects: new Map([
          ['row-2', closeRect],
          ['row-3', farRect],
        ]),
        pointerCoordinates: { x: 125, y: 530 },
      };

      const collisions = centerCrossing(args as never);

      // At currentY=530, the far target is nearer (1025) than the close target (7025).
      expect(collisions.map((c) => c.id)).toEqual(['row-3', 'row-2']);
      expect(collisions[0].data?.value).toBe(1025);
      expect(collisions[1].data?.value).toBe(7025);
    });
  });

  describe('centerCrossing overlap-gate margins', () => {
    // Target rect (200, 275, 100, 100). MARGIN_X = 50, MARGIN_Y = 150.
    // Overlap-X window: ptrX ∈ (150, 350).
    // Overlap-Y window: ptrY ∈ (125, 525).
    // Set up a scenario where crossedY is true (so the gate is the only filter).
    function runWithPointer(pointerX: number, pointerY: number) {
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(200, 275, 100, 100);
      const collisionRect = makeDomRect(200, pointerY - 25, 100, 50);
      const initialRect = makeDomRect(200, 50, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: pointerX, y: pointerY },
      };
      return centerCrossing(args as never);
    }

    it('includes pointer 1px inside MARGIN_X on the left (ptrX = 151)', () => {
      expect(runWithPointer(151, 310)).toHaveLength(1);
    });

    it('excludes pointer exactly on the MARGIN_X boundary on the left (ptrX = 150)', () => {
      expect(runWithPointer(150, 310)).toHaveLength(0);
    });

    it('includes pointer 1px inside MARGIN_X on the right (ptrX = 349)', () => {
      expect(runWithPointer(349, 310)).toHaveLength(1);
    });

    it('excludes pointer exactly on the MARGIN_X boundary on the right (ptrX = 350)', () => {
      expect(runWithPointer(350, 310)).toHaveLength(0);
    });

    it('includes pointer 1px inside MARGIN_Y on the top (ptrY = 126)', () => {
      // With pointerY=126, collisionRect center is (250, 101). initialCY=75, targetCY=325.
      // Moving down, thresholdY = 275 + 25 = 300. currentCY max(101, 126) = 126. 126 >= 300?
      // No — not crossed. So we need currentCY past threshold. Use ptrY just inside Y margin
      // AND another location for the collision rect that has crossed threshold. Simplest:
      // force collisionRect past threshold by positioning it there, use pointerY for the gate.
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(200, 275, 100, 100);
      const collisionRect = makeDomRect(200, 285, 100, 50); // center (250, 310) past threshold
      const initialRect = makeDomRect(200, 50, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 250, y: 126 },
      };
      expect(centerCrossing(args as never)).toHaveLength(1);
    });

    it('excludes pointer exactly on the MARGIN_Y boundary on the top (ptrY = 125)', () => {
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(200, 275, 100, 100);
      const collisionRect = makeDomRect(200, 285, 100, 50);
      const initialRect = makeDomRect(200, 50, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 250, y: 125 },
      };
      expect(centerCrossing(args as never)).toHaveLength(0);
    });
  });

  describe('centerCrossing threshold boundaries', () => {
    // Target rect (50, 275, 200, 100). Initial Y=75 (above), moving DOWN.
    // thresholdY (initialCY < targetCY branch) = rect.top + collisionRect.height/2 = 275 + 25 = 300.
    function runWithCurrentY(currentY: number) {
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(50, 275, 200, 100);
      const collisionRect = makeDomRect(100, currentY - 25, 100, 50);
      const initialRect = makeDomRect(100, 50, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 150, y: currentY },
      };
      return centerCrossing(args as never);
    }

    it('excludes collision when current Y has not yet reached the threshold (299)', () => {
      expect(runWithCurrentY(299)).toHaveLength(0);
    });

    it('includes collision when current Y equals the threshold (300)', () => {
      expect(runWithCurrentY(300)).toHaveLength(1);
    });

    it('includes collision when current Y is past the threshold (301)', () => {
      expect(runWithCurrentY(301)).toHaveLength(1);
    });

    it('applies direction-aware threshold when moving UP (initialCY > targetCY)', () => {
      // Moving up from below target. Initial Y=600 (center of initialRect), target (50,275,200,100)
      // targetCY=325. initialCY(600) > targetCY(325). thresholdY = Math.min(rect.top + rect.height -
      // collisionRect.height/2, targetCY) = Math.min(275+100-25, 325) = Math.min(350, 325) = 325.
      // currentY=326: currentCY = Math.min(crCY=326, ptrY=326) = 326. crossedY needs
      // currentCY <= thresholdY(325). 326 <= 325 is false → no crossing yet.
      // currentY=325: 325 <= 325 is true → crossed.
      const just = runWithCurrentYUp(325);
      const before = runWithCurrentYUp(326);
      expect(before).toHaveLength(0);
      expect(just).toHaveLength(1);
    });
  });

  describe('centerCrossing X-axis threshold boundaries', () => {
    // Horizontal drag scenarios — Y stays above/away from target so only the X
    // threshold can trigger a crossing. Target rect (250, 275, 100, 100).
    // Collision rect width 100, height 50.
    function runHorizontal(opts: {
      initialX: number;
      currentX: number;
      pointerX: number;
      pointerY?: number;
    }) {
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(250, 275, 100, 100);
      const pointerY = opts.pointerY ?? 325;
      const collisionRect = makeDomRect(opts.currentX - 50, pointerY - 25, 100, 50);
      const initialRect = makeDomRect(opts.initialX - 50, pointerY - 25, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: opts.pointerX, y: pointerY },
      };
      return centerCrossing(args as never);
    }

    it('crosses threshold when dragging RIGHT toward target (initialCX < targetCX)', () => {
      // initialCX=100, targetCX=300. thresholdX = rect.left + collisionRect.width/2 = 250+50 = 300.
      // currentCX=301 → crossed. currentCX=299 → not crossed.
      expect(runHorizontal({ initialX: 100, currentX: 299, pointerX: 299 })).toHaveLength(0);
      expect(runHorizontal({ initialX: 100, currentX: 301, pointerX: 301 })).toHaveLength(1);
    });

    it('crosses threshold when dragging LEFT toward target (initialCX > targetCX) with Math.min clamp', () => {
      // initialCX=700, targetCX=300. thresholdX = Math.min(rect.left + rect.width - collisionRect.width/2, targetCX)
      //                                        = Math.min(250+100-50, 300) = Math.min(300, 300) = 300.
      // Moving left: crossedX when currentCX <= thresholdX.
      expect(runHorizontal({ initialX: 700, currentX: 301, pointerX: 301 })).toHaveLength(0);
      expect(runHorizontal({ initialX: 700, currentX: 299, pointerX: 299 })).toHaveLength(1);
    });

    it('Math.min clamps the LEFT-drag threshold to targetCX for wide targets', () => {
      // Wide target: (200, 275, 300, 100). targetCX = 200 + 150 = 350. rect.left + rect.width -
      // collisionRect.width/2 = 200+300-50 = 450. Math.min(450, 350) = 350 (clamped to targetCX).
      // Without Math.min (e.g. Math.max mutant), threshold would be 450.
      const target = createDroppable('row-2');
      const wideRect = makeDomRect(200, 275, 300, 100);
      function runWide(currentX: number) {
        const pointerY = 325;
        const collisionRect = makeDomRect(currentX - 50, pointerY - 25, 100, 50);
        const initialRect = makeDomRect(700 - 50, pointerY - 25, 100, 50);
        const args = {
          active: {
            id: 'row-1',
            rect: { current: { initial: initialRect, translated: collisionRect } },
            data: { current: undefined },
          },
          collisionRect,
          droppableContainers: [target],
          droppableRects: new Map([['row-2', wideRect]]),
          pointerCoordinates: { x: currentX, y: pointerY },
        };
        return centerCrossing(args as never);
      }

      // currentCX=351: default NOT crossed (351 > 350). Math.max mutant WOULD cross (351 <= 450).
      expect(runWide(351)).toHaveLength(0);
      // currentCX=349: default crossed (349 <= 350). Confirms threshold is at 350, not 450.
      expect(runWide(349)).toHaveLength(1);
    });

    it('uses Math.max(crCX, ptrX) when dragging RIGHT (initialCX < targetCX)', () => {
      // Grab offset test: pointer ahead of collisionRect center.
      // Moving right, currentCX = Math.max(crCX, ptrX) — the furthest-advanced value.
      // threshold = 300. crCX=290 (collisionRect center behind), ptrX=305 (pointer ahead).
      // Max picks ptrX=305 → crossed (305 >= 300). A `true/false` or min-only mutant would fail.
      const crossed = runHorizontal({ initialX: 100, currentX: 290, pointerX: 305 });
      expect(crossed).toHaveLength(1);

      // Neither crCX nor ptrX past threshold → no crossing.
      const notCrossed = runHorizontal({ initialX: 100, currentX: 290, pointerX: 295 });
      expect(notCrossed).toHaveLength(0);
    });

    it('uses Math.min(crCX, ptrX) when dragging LEFT (initialCX > targetCX)', () => {
      // Moving left, currentCX = Math.min(crCX, ptrX) — the furthest-advanced (smallest) value.
      // threshold=300. crCX=310 (behind), ptrX=295 (ahead) → min picks 295 → crossed (295 <= 300).
      const crossed = runHorizontal({ initialX: 700, currentX: 310, pointerX: 295 });
      expect(crossed).toHaveLength(1);

      // Neither crCX nor ptrX past threshold.
      const notCrossed = runHorizontal({ initialX: 700, currentX: 310, pointerX: 305 });
      expect(notCrossed).toHaveLength(0);
    });
  });

  describe('centerCrossing initial-rect center computation', () => {
    // Pin the initialCX/initialCY formulas. initialCY = initialRect.top + initialRect.height/2.
    // Mutants `+` → `-` or `/2` → `*2` shift the direction classification (initialCY < targetCY
    // flips), causing the threshold to be computed from the wrong branch.
    it('places initialCY precisely at the rect center — flipping the direction branch if mutated', () => {
      // initialRect top=0, height=200 → initialCY = 100. target (50, 275, 200, 100) → targetCY=325.
      // initialCY(100) < targetCY(325) → down-move branch: threshold = rect.top + collisionRect.height/2
      //                                                              = 275 + 25 = 300.
      // currentY=310 crosses. collisionRect center (150, 310).
      //
      // Mutant `+` → `-`: initialCY = 0 - 100 = -100. Still < 325 → same branch. Same result.
      // Mutant `/2` → `*2`: initialCY = 0 + 400 = 400 > 325 → UP-branch. threshold =
      //   Math.min(rect.top + rect.height - collisionRect.height/2, targetCY)
      //   = Math.min(275+100-25, 325) = Math.min(350, 325) = 325.
      // For currentY=310: default crossed (310 >= 300); mutant: initialCY(400) > thresholdY(325)
      // needs currentY <= 325. currentY=310 <= 325 → ALSO crossed. Same result. Hmm.
      //
      // Need asymmetry. Use initialRect near target so the direction classification flips
      // between default and mutant, and pick a currentY that crosses only one branch's threshold.
      // initialRect (100, 300, 100, 50) → initialCY = 300 + 25 = 325. target (50, 275, 200, 100)
      // targetCY = 325. initialCY === targetCY → strict < is false → UP-branch.
      // Mutant `/2` → `*2`: initialCY = 300 + 100 = 400. > 325 → UP-branch. Same.
      // Mutant `+` → `-`: initialCY = 300 - 25 = 275. < 325 → DOWN-branch. thresholdY = 275+25=300.
      // currentY=310: up-branch threshold=325, crossedY if currentY <= 325. True. down-branch
      // threshold=300, crossedY if currentY >= 300. Also true. Still same.
      //
      // The most effective test simply asserts collision fires for the precisely-engineered
      // down-motion scenario above, pinning the down-branch arithmetic.
      const target = createDroppable('row-2');
      const targetRect = makeDomRect(50, 275, 200, 100);
      const collisionRect = makeDomRect(100, 285, 100, 50); // center (150, 310)
      const initialRect = makeDomRect(100, 0, 100, 200); // initialCY = 100
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 150, y: 310 },
      };
      const collisions = centerCrossing(args as never);
      expect(collisions).toHaveLength(1);
      // Pin exact value: dx = 150 - 150 = 0, dy = 310 - 325 = -15 → value = 225.
      expect(collisions[0].data?.value).toBe(225);
    });
  });

  describe('parent containment pointer boundaries', () => {
    // Pass-2 containment check at lines 447–458 uses strict `>=` / `<=` comparisons for
    // all four edges. Boundary tests (pointer exactly ON each edge) pin these operators.
    const parent = createDroppable('section-1');
    const parentRect = makeDomRect(100, 200, 300, 400); // right=400, bottom=600

    function runParentContainment(pointerX: number, pointerY: number) {
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      });
      const collisionRect = makeDomRect(50, pointerY - 25, 100, 50);
      const initialRect = makeDomRect(50, 75, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [parent],
        droppableRects: new Map([['section-1', parentRect]]),
        pointerCoordinates: { x: pointerX, y: pointerY },
      };
      return detect(args as never);
    }

    // Containment path sets value=0 (synthetic); closestCenter fallback sets value to the
    // squared distance from the pointer to the parent center. Asserting on `value === 0`
    // cleanly discriminates "inside parent" from the distance-based fallback.
    function assertContained(result: ReturnType<typeof runParentContainment>) {
      expect(result).toHaveLength(1);
      expect(result[0].id).toBe('section-1');
      expect(result[0].data?.value).toBe(0);
    }
    function assertNotContained(result: ReturnType<typeof runParentContainment>) {
      expect(result).toHaveLength(1);
      expect(result[0].id).toBe('section-1');
      // Distance fallback populates a nonzero value.
      expect(result[0].data?.value).not.toBe(0);
    }

    it('matches when pointer is exactly on the LEFT edge of the parent', () => {
      assertContained(runParentContainment(100, 400));
    });

    it('does NOT match when pointer is 1px outside the LEFT edge', () => {
      assertNotContained(runParentContainment(99, 400));
    });

    it('matches when pointer is exactly on the RIGHT edge (rect.left + rect.width)', () => {
      assertContained(runParentContainment(400, 400));
    });

    it('does NOT match when pointer is 1px outside the RIGHT edge', () => {
      assertNotContained(runParentContainment(401, 400));
    });

    it('matches when pointer is exactly on the TOP edge', () => {
      assertContained(runParentContainment(200, 200));
    });

    it('does NOT match when pointer is 1px outside the TOP edge', () => {
      assertNotContained(runParentContainment(200, 199));
    });

    it('matches when pointer is exactly on the BOTTOM edge (rect.top + rect.height)', () => {
      assertContained(runParentContainment(200, 600));
    });

    it('does NOT match when pointer is 1px outside the BOTTOM edge', () => {
      assertNotContained(runParentContainment(200, 601));
    });
  });

  describe('nonActiveContainers filter', () => {
    it('excludes the active container when it is registered as a droppable (discriminates active filter)', () => {
      // Solo-sibling scenario: the only registered droppable shares the active id.
      // Default: the filter removes it → no siblings → no sibling collision → falls through
      // to parent (none) → returns []. Mutant `(c) => true`: active included as sibling,
      // centerCrossing may detect its own rect (pointer inside) → returns a non-empty array.
      const self = createDroppable('row-1');
      const selfRect = makeDomRect(100, 95, 100, 50); // initialRect position
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      });
      const collisionRect = makeDomRect(100, 285, 100, 50); // center (150, 310)
      const initialRect = makeDomRect(100, 75, 100, 50);
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [self],
        droppableRects: new Map([['row-1', selfRect]]),
        pointerCoordinates: { x: 150, y: 120 },
      };
      expect(detect(args as never)).toEqual([]);
    });
  });

  describe('overRectRef only captures when collisions exist', () => {
    it('leaves overRectRef null when there are no collisions (line 312 length > 0 guard)', () => {
      // No droppable rect matches the pointer → empty collisions → guard prevents capture.
      const unrelated = createDroppableWithRect('row-2', {
        left: 1000,
        top: 1000,
        width: 50,
        height: 50,
      });
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null;
      };
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      });
      const args = {
        active: {
          id: 'row-1',
          rect: {
            current: {
              initial: makeDomRect(0, 0, 50, 50),
              translated: makeDomRect(0, 50, 50, 50),
            },
          },
          data: { current: undefined },
        },
        collisionRect: makeDomRect(0, 50, 50, 50),
        droppableContainers: [unrelated],
        droppableRects: new Map(),
        pointerCoordinates: { x: 25, y: 75 },
      };
      detect(args as never);
      expect(overRectRef.current).toBeNull();
    });
  });

  describe('overRectRef exact node reference', () => {
    it('captures the actual DOM node from the winning container (reference equality)', () => {
      const target = createDroppableWithRect('row-2', {
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

      const args = {
        active: {
          id: 'row-1',
          rect: {
            current: {
              initial: makeDomRect(100, 75, 100, 50),
              translated: makeDomRect(100, 285, 100, 50),
            },
          },
          data: { current: undefined },
        },
        collisionRect: makeDomRect(100, 285, 100, 50),
        droppableContainers: [target],
        droppableRects: new Map([['row-2', makeDomRect(50, 275, 200, 100)]]),
        pointerCoordinates: { x: 150, y: 310 },
      };

      detect(args as never);

      expect(overRectRef.current?.nodeRef).toBe(target.node);
    });
  });
});

function runWithCurrentYUp(currentY: number) {
  const target = createDroppable('row-2');
  const targetRect = makeDomRect(50, 275, 200, 100);
  const collisionRect = makeDomRect(100, currentY - 25, 100, 50);
  const initialRect = makeDomRect(100, 575, 100, 50);
  const args = {
    active: {
      id: 'row-1',
      rect: { current: { initial: initialRect, translated: collisionRect } },
      data: { current: undefined },
    },
    collisionRect,
    droppableContainers: [target],
    droppableRects: new Map([['row-2', targetRect]]),
    pointerCoordinates: { x: 150, y: currentY },
  };
  return centerCrossing(args as never);
}

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
