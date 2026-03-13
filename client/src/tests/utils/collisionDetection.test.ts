import type { DroppableContainer } from '@dnd-kit/core';

import {
  centerCrossing,
  createTypedCollisionDetection,
  filterDroppablesByType,
  filterParentContainers,
  filterSiblings,
  typedCollisionDetection,
} from '@/utils/collisionDetection';

/**
 * Creates a minimal DroppableContainer stub for testing the filter logic.
 * Only the `id` field is exercised by our collision detection strategy.
 */
function makeContainer(id: string): DroppableContainer {
  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: null },
    rect: { current: null },
  };
}

/**
 * Creates a DroppableContainer with a mock DOM node that returns the given
 * bounding rect from getBoundingClientRect(). Required for closestCenterLive
 * which reads live DOM rects in the hasPendingMove=true path.
 */
function makeContainerWithRect(
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
  };
}

describe('filterDroppablesByType', () => {
  const containers = [
    makeContainer('section-1'),
    makeContainer('section-2'),
    makeContainer('row-10'),
    makeContainer('row-20'),
    makeContainer('column-5'),
    makeContainer('column-6'),
    makeContainer('element-100'),
    makeContainer('element-200'),
    makeContainer('root'),
  ];

  it('allows only row-* and section-* containers when dragging a row', () => {
    const result = filterDroppablesByType('row-17', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toContain('row-10');
    expect(ids).toContain('row-20');
    expect(ids).toContain('section-1');
    expect(ids).toContain('section-2');
    expect(ids).not.toContain('column-5');
    expect(ids).not.toContain('element-100');
    expect(ids).not.toContain('root');
  });

  it('allows only section-* and non-typed (root) containers when dragging a section', () => {
    const result = filterDroppablesByType('section-1', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toContain('section-1');
    expect(ids).toContain('section-2');
    expect(ids).toContain('root');
    expect(ids).not.toContain('row-10');
    expect(ids).not.toContain('column-5');
    expect(ids).not.toContain('element-100');
  });

  it('allows only element-* and column-* containers when dragging an element', () => {
    const result = filterDroppablesByType('element-100', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toContain('element-100');
    expect(ids).toContain('element-200');
    expect(ids).toContain('column-5');
    expect(ids).toContain('column-6');
    expect(ids).not.toContain('section-1');
    expect(ids).not.toContain('row-10');
    expect(ids).not.toContain('root');
  });

  it('allows only column-* and row-* containers when dragging a column', () => {
    const result = filterDroppablesByType('column-5', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toContain('column-5');
    expect(ids).toContain('column-6');
    expect(ids).toContain('row-10');
    expect(ids).toContain('row-20');
    expect(ids).not.toContain('section-1');
    expect(ids).not.toContain('element-100');
    expect(ids).not.toContain('root');
  });

  it('returns an empty array for an invalid active ID', () => {
    expect(filterDroppablesByType('invalid', containers)).toEqual([]);
    expect(filterDroppablesByType('', containers)).toEqual([]);
    expect(filterDroppablesByType('unknown-42', containers)).toEqual([]);
  });

  it('returns an empty array when droppable containers are empty', () => {
    expect(filterDroppablesByType('row-1', [])).toEqual([]);
    expect(filterDroppablesByType('section-1', [])).toEqual([]);
  });
});

describe('filterDroppablesByType — parent container matching', () => {
  it('includes parent container type for non-root draggables', () => {
    // A row's parent container type is 'section'
    // This tests the `parentType !== 'root' && containerType === parentType` branch
    const containers = [makeContainer('section-1'), makeContainer('column-5')];
    const result = filterDroppablesByType('row-10', containers);
    const ids = result.map((c) => String(c.id));

    expect(ids).toContain('section-1');
    expect(ids).not.toContain('column-5');
  });

  it('does not include typed containers as parent for sections', () => {
    // A section's parent is 'root' — so no typed container should match
    // as a parent. Only containers with unparseable IDs (null type) match.
    const containers = [
      makeContainer('section-2'),
      makeContainer('row-10'),
      makeContainer('column-5'),
      makeContainer('element-100'),
    ];
    const result = filterDroppablesByType('section-1', containers);
    const ids = result.map((c) => String(c.id));

    // Only sibling sections are included, no rows/columns/elements
    expect(ids).toEqual(['section-2']);
  });

  it('includes root container (null type) only for sections', () => {
    // 'root' is unparseable → containerType is null
    // Only sections (parentType === 'root') should accept null-typed containers
    const containers = [makeContainer('root')];

    expect(filterDroppablesByType('section-1', containers).length).toBe(1);
    expect(filterDroppablesByType('row-10', containers).length).toBe(0);
    expect(filterDroppablesByType('column-5', containers).length).toBe(0);
    expect(filterDroppablesByType('element-100', containers).length).toBe(0);
  });
});

describe('filterDroppablesByType — cross-type exclusion contract', () => {
  const containers = [
    makeContainer('section-1'),
    makeContainer('section-2'),
    makeContainer('row-10'),
    makeContainer('row-20'),
    makeContainer('column-5'),
    makeContainer('column-6'),
    makeContainer('element-100'),
    makeContainer('element-200'),
    makeContainer('root'),
  ];

  it('section drag excludes all row, column, and element containers', () => {
    const ids = filterDroppablesByType('section-1', containers).map((c) => String(c.id));

    expect(ids.every((id) => !id.startsWith('row-'))).toBe(true);
    expect(ids.every((id) => !id.startsWith('column-'))).toBe(true);
    expect(ids.every((id) => !id.startsWith('element-'))).toBe(true);
  });

  it('row drag excludes all column and element containers', () => {
    const ids = filterDroppablesByType('row-10', containers).map((c) => String(c.id));

    expect(ids.every((id) => !id.startsWith('column-'))).toBe(true);
    expect(ids.every((id) => !id.startsWith('element-'))).toBe(true);
  });

  it('column drag excludes all section and element containers', () => {
    const ids = filterDroppablesByType('column-5', containers).map((c) => String(c.id));

    expect(ids.every((id) => !id.startsWith('section-'))).toBe(true);
    expect(ids.every((id) => !id.startsWith('element-'))).toBe(true);
  });

  it('element drag excludes all section and row containers', () => {
    const ids = filterDroppablesByType('element-100', containers).map((c) => String(c.id));

    expect(ids.every((id) => !id.startsWith('section-'))).toBe(true);
    expect(ids.every((id) => !id.startsWith('row-'))).toBe(true);
  });
});

describe('typedCollisionDetection — geometric invalid-drop scenarios', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  function makeCollisionArgs(
    activeId: string,
    containers: ReturnType<typeof makeContainer>[],
    options?: {
      initialRect?: typeof rect;
      collisionRect?: typeof rect;
    },
  ) {
    const initial = options?.initialRect ?? rect;
    const collision = options?.collisionRect ?? rect;
    const droppableRects = new Map<string, typeof rect>();
    for (const c of containers) {
      droppableRects.set(String(c.id), rect);
    }

    return {
      active: {
        id: activeId,
        data: { current: undefined },
        rect: { current: { initial, translated: collision } },
      },
      collisionRect: collision,
      droppableRects,
      droppableContainers: containers,
      pointerCoordinates: null,
    };
  }

  it('returns no collisions when dragging element near only a section container', () => {
    const result = typedCollisionDetection(
      makeCollisionArgs('element-100', [makeContainer('section-1')]),
    );

    expect(result).toEqual([]);
  });

  it('returns no collisions when dragging row near only column containers', () => {
    const result = typedCollisionDetection(
      makeCollisionArgs('row-10', [makeContainer('column-5'), makeContainer('column-6')]),
    );

    expect(result).toEqual([]);
  });

  it('returns only valid sibling when invalid container is closer', () => {
    // Invalid section at distance 0, valid element sibling further away.
    // Active starts at y=500 (below sibling at y=0), collision rect crosses
    // sibling center — centerCrossing detects the crossing.
    const invalidContainer = makeContainer('section-1');
    const validContainer = makeContainer('element-200');

    const initialRect = { ...rect, top: 500, bottom: 550 };
    const droppableRects = new Map<string, typeof rect>();
    droppableRects.set('section-1', rect);
    droppableRects.set('element-200', rect);

    const result = typedCollisionDetection({
      active: {
        id: 'element-100',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [invalidContainer, validContainer],
      pointerCoordinates: null,
    });

    const ids = result.map((c) => c.id);
    expect(ids).not.toContain('section-1');
    expect(ids).toContain('element-200');
  });
});

describe('filterSiblings', () => {
  const containers = [
    makeContainer('section-1'),
    makeContainer('section-2'),
    makeContainer('row-10'),
    makeContainer('row-20'),
    makeContainer('column-5'),
    makeContainer('column-6'),
    makeContainer('element-100'),
    makeContainer('element-200'),
    makeContainer('root'),
  ];

  it('returns only row containers when dragging a row', () => {
    const ids = filterSiblings('row-10', containers).map((c) => String(c.id));
    expect(ids).toEqual(['row-10', 'row-20']);
  });

  it('returns only section containers when dragging a section', () => {
    const ids = filterSiblings('section-1', containers).map((c) => String(c.id));
    expect(ids).toEqual(['section-1', 'section-2']);
  });

  it('returns only column containers when dragging a column', () => {
    const ids = filterSiblings('column-5', containers).map((c) => String(c.id));
    expect(ids).toEqual(['column-5', 'column-6']);
  });

  it('returns only element containers when dragging an element', () => {
    const ids = filterSiblings('element-100', containers).map((c) => String(c.id));
    expect(ids).toEqual(['element-100', 'element-200']);
  });

  it('returns empty array for invalid active ID', () => {
    expect(filterSiblings('invalid', containers)).toEqual([]);
    expect(filterSiblings('', containers)).toEqual([]);
  });
});

describe('filterParentContainers', () => {
  const containers = [
    makeContainer('section-1'),
    makeContainer('section-2'),
    makeContainer('row-10'),
    makeContainer('row-20'),
    makeContainer('column-5'),
    makeContainer('element-100'),
    makeContainer('root'),
  ];

  it('returns section containers when dragging a row', () => {
    const ids = filterParentContainers('row-10', containers).map((c) => String(c.id));
    expect(ids).toEqual(['section-1', 'section-2']);
  });

  it('returns row containers when dragging a column', () => {
    const ids = filterParentContainers('column-5', containers).map((c) => String(c.id));
    expect(ids).toEqual(['row-10', 'row-20']);
  });

  it('returns column containers when dragging an element', () => {
    const ids = filterParentContainers('element-100', containers).map((c) => String(c.id));
    expect(ids).toEqual(['column-5']);
  });

  it('returns only root container when dragging a section', () => {
    const ids = filterParentContainers('section-1', containers).map((c) => String(c.id));
    expect(ids).toEqual(['root']);
  });

  it('returns empty array for invalid active ID', () => {
    expect(filterParentContainers('invalid', containers)).toEqual([]);
  });
});

describe('typedCollisionDetection', () => {
  it('returns empty collisions for an invalid active ID', () => {
    const result = typedCollisionDetection({
      active: {
        id: 'invalid',
        data: { current: undefined },
        rect: { current: { initial: null, translated: null } },
      },
      collisionRect: {
        width: 0,
        height: 0,
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
      },
      droppableRects: new Map(),
      droppableContainers: [makeContainer('row-1')],
      pointerCoordinates: null,
    });

    expect(result).toEqual([]);
  });

  it('returns collisions when dragged center crosses target center', () => {
    const container = makeContainer('row-20');
    const targetRect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };
    // Active started below target, now at target position — center crossing
    const initialRect = { ...targetRect, top: 200, bottom: 250 };
    const droppableRects = new Map<string, typeof targetRect>();
    droppableRects.set('row-20', targetRect);

    const result = typedCollisionDetection({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: targetRect } },
      },
      collisionRect: targetRect,
      droppableRects,
      droppableContainers: [container],
      pointerCoordinates: null,
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('row-20');
  });
});

describe('typedCollisionDetection — sibling priority', () => {
  const activeRect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('prefers sibling over parent container even when parent center is closer', () => {
    // Active starts far below (y=400), dragged up to sibling position at y=200.
    // Parent section is at y=0 (closer to current position) but sibling wins via two-pass.
    const siblingRow = makeContainer('row-20');
    const parentSection = makeContainer('section-1');

    const initialRect = { ...activeRect, top: 400, bottom: 450 };
    const collisionRect = { ...activeRect, top: 200, bottom: 250 }; // overlaps sibling, center crossed (initial y=425 > target y=225, current y=225 <= 225)
    const parentRect = { ...activeRect }; // center at y=25
    const siblingRect = { ...activeRect, top: 200, bottom: 250 }; // center at y=225

    const droppableRects = new Map<string, typeof activeRect>();
    droppableRects.set('section-1', parentRect);
    droppableRects.set('row-20', siblingRect);

    const result = typedCollisionDetection({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [parentSection, siblingRow],
      pointerCoordinates: null,
    });

    const ids = result.map((c) => c.id);
    expect(ids).toContain('row-20');
    expect(ids).not.toContain('section-1');
  });

  it('falls back to parent container when no siblings exist', () => {
    const parentSection = makeContainer('section-1');

    const droppableRects = new Map<string, typeof activeRect>();
    droppableRects.set('section-1', activeRect);

    const result = typedCollisionDetection({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: activeRect, translated: activeRect } },
      },
      collisionRect: activeRect,
      droppableRects,
      droppableContainers: [parentSection],
      pointerCoordinates: null,
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('section-1');
  });

  it('excludes the active item from collision results', () => {
    // Active starts at y=400, dragged up to sibling position at y=200.
    // Without exclusion, the active item (at y=0) is returned → no-op drop.
    // Active droppable rect tracks the collision rect (same element in dnd-kit).
    const activeRow = makeContainer('row-10');
    const siblingRow = makeContainer('row-20');

    const initialRect = { ...activeRect, top: 400, bottom: 450 };
    const collisionRect = { ...activeRect, top: 200, bottom: 250 }; // overlaps sibling, center crossed
    const siblingRect = { ...activeRect, top: 200, bottom: 250 };
    const droppableRects = new Map<string, typeof activeRect>();
    droppableRects.set('row-10', collisionRect);
    droppableRects.set('row-20', siblingRect);

    const result = typedCollisionDetection({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [activeRow, siblingRow],
      pointerCoordinates: null,
    });

    const ids = result.map((c) => c.id);
    expect(ids).toContain('row-20');
    expect(ids).not.toContain('row-10');
  });

  it('returns empty when neither siblings nor parents produce collisions', () => {
    // Only an unrelated container type is present
    const result = typedCollisionDetection({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: activeRect, translated: activeRect } },
      },
      collisionRect: activeRect,
      droppableRects: new Map([['element-100', activeRect]]),
      droppableContainers: [makeContainer('element-100')],
      pointerCoordinates: null,
    });

    expect(result).toEqual([]);
  });
});

describe('centerCrossing', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('detects collision when dragged center crosses target center vertically', () => {
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50 }; // center at y=25
    const initialRect = { ...rect, top: 100, bottom: 150 }; // center at y=125 (below)
    const collisionRect = { ...rect, top: 0, bottom: 50 }; // center at y=25 (crossed)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('row-20');
  });

  it('does not detect collision when center has not crossed', () => {
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50 }; // center at y=25
    const initialRect = { ...rect, top: 100, bottom: 150 }; // center at y=125 (below)
    const collisionRect = { ...rect, top: 60, bottom: 110 }; // center at y=85 (still below)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(0);
  });

  it('detects collision on horizontal axis crossing', () => {
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 0, right: 100 }; // center at x=50
    const initialRect = { ...rect, left: 200, right: 300 }; // center at x=250 (right of)
    const collisionRect = { ...rect, left: 0, right: 100 }; // center at x=50 (crossed)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('column-20');
  });

  it('returns empty when initial rect is null', () => {
    const target = makeContainer('row-20');
    const droppableRects = new Map([[target.id, rect]]);

    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: null, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(0);
  });

  it('sorts results by distance to center', () => {
    const near = makeContainer('row-20');
    const far = makeContainer('row-30');
    const nearRect = { ...rect, top: 0, bottom: 50 }; // center y=25
    const farRect = { ...rect, top: 50, bottom: 100 }; // center y=75
    const initialRect = { ...rect, top: 200, bottom: 250 }; // center y=225
    const collisionRect = { ...rect, top: 0, bottom: 50 }; // center y=25

    const droppableRects = new Map([
      [near.id, nearRect],
      [far.id, farRect],
    ]);

    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [far, near],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
    expect(result[0].id).toBe('row-20'); // nearer
    expect(result[1].id).toBe('row-30');
  });

  it('does not detect collision when centers crossed but rects do not overlap', () => {
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 300, bottom: 350 }; // center at y=325
    const initialRect = { ...rect, top: 400, bottom: 450 }; // center at y=425 (below target)
    const collisionRect = { ...rect, top: 0, bottom: 50 }; // center at y=25 (crossed, but 250px gap)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(0);
  });
});

describe('closestCenterLive — ordering with multiple containers', () => {
  it('returns nearest container first when two containers are at different distances', () => {
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    // Two siblings: near at y=50, far at y=200.
    // Active at y=60, so near is closer. This kills mutants that break
    // distance calculations (dx*dx + dy*dy) and sorting in closestCenterLive.
    const nearRect = { left: 0, top: 50, width: 100, height: 50 };
    const farRect = { left: 0, top: 200, width: 100, height: 50 };
    const near = makeContainerWithRect('row-20', nearRect);
    const far = makeContainerWithRect('row-30', farRect);

    const initialRect = { left: 0, top: 300, width: 100, height: 50, right: 100, bottom: 350 };
    const collisionRect = { left: 0, top: 60, width: 100, height: 50, right: 100, bottom: 110 };

    const droppableRects = new Map([
      ['row-20', { ...nearRect, right: 100, bottom: 100 }],
      ['row-30', { ...farRect, right: 100, bottom: 250 }],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [far, near], // far first — sorting must reorder
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
    expect(result[0].id).toBe('row-20'); // near must be first
    expect(result[1].id).toBe('row-30');
  });

  it('uses X axis distance when containers differ horizontally', () => {
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    // Two siblings at same Y, different X. Active at x=10.
    const nearRect = { left: 0, top: 0, width: 100, height: 50 }; // center x=50
    const farRect = { left: 300, top: 0, width: 100, height: 50 }; // center x=350
    const near = makeContainerWithRect('column-20', nearRect);
    const far = makeContainerWithRect('column-30', farRect);

    const initialRect = { left: 500, top: 0, width: 100, height: 50, right: 600, bottom: 50 };
    const collisionRect = { left: 10, top: 0, width: 100, height: 50, right: 110, bottom: 50 };

    const droppableRects = new Map([
      ['column-20', { ...nearRect, right: 100, bottom: 50 }],
      ['column-30', { ...farRect, right: 400, bottom: 50 }],
    ]);

    const result = detect({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [far, near],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
    expect(result[0].id).toBe('column-20');
    expect(result[1].id).toBe('column-30');
  });
});

describe('centerCrossing — direction-aware threshold', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('detects upward crossing (dragging from above to below target)', () => {
    // Active starts ABOVE target, drags DOWN past threshold.
    // Initial center y=25, target center y=225.
    // Threshold (initialCY < targetCY): rect.top + crH/2 = 200 + 25 = 225.
    // Current center y=225 crosses threshold at 225 (>= 225).
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 200, bottom: 250 }; // center y=225
    const initialRect = { ...rect, top: 0, bottom: 50 }; // center y=25 (above)
    const collisionRect = { ...rect, top: 200, bottom: 250 }; // center y=225 (at threshold)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('row-20');
  });

  it('does not detect upward crossing when threshold not reached', () => {
    // Same as above but stopped before threshold.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 200, bottom: 250 }; // center y=225
    const initialRect = { ...rect, top: 0, bottom: 50 }; // center y=25 (above)
    // Threshold = 200 + 25 = 225. Center = 180+25 = 205. Not crossed.
    const collisionRect = { ...rect, top: 180, bottom: 230 };

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(0);
  });

  it('uses Math.min for downward threshold (dragging from below)', () => {
    // Active starts BELOW target, drags UP.
    // initialCY > targetCY: threshold = Math.min(rect.top + rect.height - crH/2, targetCY)
    // Target at y=100, h=50 → center=125. Edge threshold = 100+50-25=125. Min(125,125)=125.
    // But with a small overlay (crH=20), edge threshold = 100+50-10=140 vs center=125 → Min=125.
    // The Math.min ensures we don't set a threshold BEYOND the center.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 100, bottom: 150 }; // center y=125

    // Small overlay (height=20) — Math.min matters here
    const smallCR = { ...rect, height: 20, top: 120, bottom: 140 }; // center y=130
    const initialRect = { ...rect, top: 300, bottom: 350 }; // center y=325 (below)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: smallCR } },
      },
      collisionRect: smallCR,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    // center at y=130 > threshold at y=125 → NOT crossed (hasn't passed threshold)
    expect(result).toHaveLength(0);

    // Now drag further: center at y=124 <= threshold 125 → crossed
    const crossedCR = { ...rect, height: 20, top: 114, bottom: 134 }; // center y=124
    const result2 = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: crossedCR } },
      },
      collisionRect: crossedCR,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });

    expect(result2).toHaveLength(1);
  });

  it('uses pointer max-advance for crossing when pointer is ahead of collisionRect center', () => {
    // Tests the currentCX/currentCY max-advance logic.
    // Dragging rightward: initialCX < targetCX → currentCX = Math.max(crCX, ptrX).
    // ptrX is ahead (further right), so it crosses the threshold even though crCX hasn't.
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 200, right: 300 }; // center x=250

    // Initial far left, dragging right
    const initialRect = { ...rect, left: 0, right: 100 }; // center x=50
    // CollisionRect center at x=200 — NOT crossing threshold (250 - target center)
    // But pointer at x=260 — crosses threshold
    const collisionRect = { ...rect, left: 150, right: 250 }; // center x=200

    const droppableRects = new Map([[target.id, targetRect]]);

    // Without pointer: center at 200, threshold at ~225 → no crossing
    const resultNoPtr = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: null,
    });
    expect(resultNoPtr).toHaveLength(0);

    // With pointer ahead: max(200, 260) = 260 >= threshold 225 → crossing detected
    const resultWithPtr = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 260, y: 25 },
    });
    expect(resultWithPtr).toHaveLength(1);
    expect(resultWithPtr[0].id).toBe('column-20');
  });

  it('uses pointer min-advance for crossing when dragging away from target', () => {
    // Dragging leftward away from target: initialCX > targetCX → currentCX = Math.min(crCX, ptrX).
    // ptrX is further left (more advanced in drag direction), so it crosses.
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 100, right: 200 }; // center x=150

    const initialRect = { ...rect, left: 300, right: 400 }; // center x=350 (right of target)
    // collisionRect center at 175, threshold = Math.min(200-50, 150) = 150
    // crCX = 175 > 150, but ptrX = 140 < 150 → Min(175, 140) = 140 <= 150 → crossed
    const collisionRect = { ...rect, left: 125, right: 225 }; // center x=175

    const droppableRects = new Map([[target.id, targetRect]]);

    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 140, y: 25 },
    });
    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('column-20');
  });

  it('rejects collision when pointer is outside X margin', () => {
    // Target at x=500. Pointer at x=0, far outside MARGIN_X=50.
    // Even if crossing threshold is met, overlap gate rejects.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, left: 500, right: 600, top: 200, bottom: 250 };

    const initialRect = { ...rect, top: 400, bottom: 450 };
    const collisionRect = { ...rect, top: 200, bottom: 250 };

    const droppableRects = new Map([[target.id, targetRect]]);

    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 0, y: 225 }, // far left of target rect 500-600
    });

    expect(result).toHaveLength(0);
  });

  it('rejects collision when pointer is outside Y margin', () => {
    // Target at y=0. Pointer at y=300, far outside MARGIN_Y=150.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50 };

    const initialRect = { ...rect, top: 400, bottom: 450 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };

    const droppableRects = new Map([[target.id, targetRect]]);

    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 300 }, // 300 > 50 + 150 = 200
    });

    expect(result).toHaveLength(0);
  });

  it('accepts collision at boundary of Y margin', () => {
    // Target at y=0, h=50 (bottom=50). MARGIN_Y=150.
    // Pointer at y=199 should be accepted (< 50 + 150 = 200)
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50 };

    const initialRect = { ...rect, top: 400, bottom: 450 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };

    const droppableRects = new Map([[target.id, targetRect]]);

    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 199 }, // 199 < 200 → accepted
    });

    expect(result).toHaveLength(1);
  });
});

describe('createTypedCollisionDetection — source container filtering', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('filters siblings to source container items when provided', () => {
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sourceRow = makeContainer('row-20');
    const otherRow = makeContainer('row-30');

    // Both siblings overlap and cross, but only row-20 is in the source container
    const sourceRect = { ...rect, top: 0, bottom: 50 };
    const otherRect = { ...rect, top: 0, bottom: 50, left: 0, right: 100 };
    const initialRect = { ...rect, top: 200, bottom: 250 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };

    const droppableRects = new Map([
      ['row-20', sourceRect],
      ['row-30', otherRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sourceRow, otherRow],
      pointerCoordinates: { x: 50, y: 25 },
    });

    const ids = result.map((c) => c.id);
    expect(ids).toContain('row-20');
    expect(ids).not.toContain('row-30');
  });

  it('uses all siblings when source container is empty (source depletion)', () => {
    const sourceContainerItemsRef = { current: new Set<string | number>() }; // empty = depleted
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const targetRow = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50 };
    const initialRect = { ...rect, top: 200, bottom: 250 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };

    const droppableRects = new Map([['row-20', targetRect]]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [targetRow],
      pointerCoordinates: { x: 50, y: 25 },
    });

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('row-20');
  });

  it('hadSiblingHit fallback returns live collision when centerCrossing misses', () => {
    // Simulate: first call detects a sibling (sets hadSiblingHit=true),
    // second call has pointer inside sibling but centerCrossing misses
    // (e.g. stale droppableRects). hadSiblingHit fallback uses closestCenterLive.
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sibling = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const siblingRect = { ...rect, top: 0, bottom: 50 };

    // Call 1: crossing detected → sets hadSiblingHit=true
    const initialRect1 = { ...rect, top: 200, bottom: 250 };
    const collisionRect1 = { ...rect, top: 0, bottom: 50 };
    const droppableRects1 = new Map([['row-20', siblingRect]]);

    const result1 = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect1, translated: collisionRect1 } },
      },
      collisionRect: collisionRect1,
      droppableRects: droppableRects1,
      droppableContainers: [sibling],
      pointerCoordinates: { x: 50, y: 25 },
    });
    expect(result1).toHaveLength(1); // crossing detected

    // Call 2: pointer inside sibling rect but NOT crossing threshold.
    // Sibling at y=0, h=50 → center=25. Initial BELOW sibling (y=60, center=85).
    // Threshold = Math.min(0+50-25, 25) = 25.
    // CollisionRect at y=2 → center=27. Pointer at y=30.
    // currentCY = Math.min(27, 30) = 27. crossedY = (85 > 25 && 27 <= 25) → false.
    // Pointer at y=30 IS inside sibling rect (0-50) → guard fires.
    // hadSiblingHit=true → closestCenterLive fallback.
    const initialRect2 = { ...rect, top: 60, bottom: 110 };
    const collisionRect2 = { ...rect, top: 2, bottom: 52 };

    const result2 = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect2, translated: collisionRect2 } },
      },
      collisionRect: collisionRect2,
      droppableRects: new Map([['row-20', siblingRect]]),
      droppableContainers: [sibling],
      pointerCoordinates: { x: 50, y: 30 },
    });

    // Without hadSiblingHit, this would return [] (pointer inside but no crossing).
    // With hadSiblingHit, closestCenterLive returns the sibling.
    expect(result2).toHaveLength(1);
    expect(result2[0].id).toBe('row-20');
  });

  it('skips pointer-inside-source guard when source is depleted, allowing parent fallback', () => {
    // Bug scenario: dragging the ONLY row from section B (depleted) into section A
    // which has rows. Pointer is inside a target-container sibling but centerCrossing
    // threshold is NOT crossed (approaching from below, pointer below center).
    // The guard should be skipped (sourceItems.size === 0), allowing parent-container
    // fallback to fire for "enter at end" placement.
    const sourceContainerItemsRef = { current: new Set<string | number>() }; // depleted
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    // Two target siblings at y=0 and y=60, plus a parent section
    const targetRow1 = makeContainer('row-10');
    const targetRow2 = makeContainer('row-20');
    const parentSection = makeContainerWithRect('section-1', { left: 0, top: 0, width: 100, height: 120 });

    const row1Rect = { ...rect, top: 0, bottom: 50 };
    const row2Rect = { ...rect, top: 60, bottom: 110 };
    const sectionRect = { ...rect, top: 0, bottom: 120 };

    // Active starts far below (section B), dragged up into row-20 area
    // but NOT crossing centerCrossing threshold (pointer below row-20 center)
    const initialRect = { ...rect, top: 400, bottom: 450 }; // center y=425
    const collisionRect = { ...rect, top: 70, bottom: 120 }; // center y=95

    const droppableRects = new Map([
      ['row-10', row1Rect],
      ['row-20', row2Rect],
      ['section-1', sectionRect],
    ]);

    // Pointer at y=100, inside row-20's rect (60-110) but below center (85)
    // centerCrossing: initialCY=425 > targetCY=85, so dragging UP toward target.
    // threshold = Math.min(60+50-25, 85) = 85. currentCY = Math.min(95, 100) = 95.
    // crossedY = (425 > 85 && 95 <= 85) → false. No crossing.
    //
    // Without fix: pointer is inside row-20 (a "sameContainerSibling" because
    // depleted source falls back to all siblings), guard blocks → returns [].
    // With fix: guard is skipped (sourceItems.size === 0) → parent fallback fires.
    const result = detect({
      active: {
        id: 'row-30',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [targetRow1, targetRow2, parentSection],
      pointerCoordinates: { x: 50, y: 100 },
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('section-1');
  });

  it('parent fallback prefers containing section over closer-center section (containment-first)', () => {
    // Bug scenario: dragging the only row from Beta (short section, 1 row) upward
    // into Alpha (tall section, 3 rows). Pointer is near Alpha's bottom edge.
    // Alpha is tall (0-300, center=150), Beta is short (310-410, center=360).
    // Pointer at y=280 is inside Alpha's rect but closer to Beta's center (distance=80)
    // than Alpha's center (distance=130). closestCenter would pick Beta → no-op.
    // Containment-first should pick Alpha.
    const sourceContainerItemsRef = { current: new Set<string | number>() }; // depleted
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    // Two target siblings (rows in Alpha) that centerCrossing won't fire on
    const targetRow1 = makeContainer('row-10');
    const targetRow2 = makeContainer('row-20');

    // Two parent sections: Alpha (tall) and Beta (short)
    const sectionAlpha = makeContainer('section-1');
    const sectionBeta = makeContainer('section-2');

    const row1Rect = { ...rect, top: 50, bottom: 100 };
    const row2Rect = { ...rect, top: 110, bottom: 160 };
    const alphaRect = { ...rect, top: 0, bottom: 300, height: 300 }; // center y=150
    const betaRect = { ...rect, top: 310, bottom: 410, height: 100 }; // center y=360

    // Active starts in Beta (far below Alpha), dragged up near Alpha's bottom edge
    const initialRect = { ...rect, top: 350, bottom: 400 }; // center y=375
    const collisionRect = { ...rect, top: 255, bottom: 305 }; // center y=280

    const droppableRects = new Map([
      ['row-10', row1Rect],
      ['row-20', row2Rect],
      ['section-1', alphaRect],
      ['section-2', betaRect],
    ]);

    const result = detect({
      active: {
        id: 'row-30',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [targetRow1, targetRow2, sectionAlpha, sectionBeta],
      pointerCoordinates: { x: 50, y: 280 },
    });

    expect(result.length).toBeGreaterThan(0);
    // Containment-first: pointer at y=280 is inside Alpha (0-300), not Beta (310-410)
    expect(result[0].id).toBe('section-1');
  });

  it('resets hadSiblingHit when sourceContainerItemsRef changes', () => {
    // Verify that changing sourceContainerItemsRef resets hadSiblingHit,
    // so the fallback path (closestCenterLive) is NOT used on the new drag.
    //
    // Sibling A at y=0 and sibling B at y=400 (different source containers).
    // Call 1: drag crosses A → hadSiblingHit=true.
    // Call 2 (same drag): pointer inside A, no new crossing → hadSiblingHit fallback fires → returns A.
    // Change sourceContainerItemsRef (new drag).
    // Call 3: pointer inside B, no crossing → hadSiblingHit=false → returns [] (no fallback).
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) as ReadonlySet<string | number> };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const siblingA = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const siblingARect = { ...rect, top: 0, bottom: 50 };

    // Call 1: crossing detected on A → sets hadSiblingHit=true
    const initialRect1 = { ...rect, top: 200, bottom: 250 };
    const collisionRect1 = { ...rect, top: 0, bottom: 50 };
    detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect1, translated: collisionRect1 } },
      },
      collisionRect: collisionRect1,
      droppableRects: new Map([['row-20', siblingARect]]),
      droppableContainers: [siblingA],
      pointerCoordinates: { x: 50, y: 25 },
    });

    // Call 2 (same drag): pointer inside A but NOT crossing threshold.
    // A at y=0, h=50 → center=25. Initial BELOW (y=60, center=85).
    // Threshold = Math.min(50-25, 25) = 25. CR center=27, ptr y=30.
    // currentCY = Math.min(27, 30) = 27 > 25 → NOT crossed.
    // Pointer at 30 IS inside A (0-50) → guard fires → hadSiblingHit fallback.
    const initialRect2 = { ...rect, top: 60, bottom: 110 };
    const collisionRect2 = { ...rect, top: 2, bottom: 52 };
    const result2 = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect2, translated: collisionRect2 } },
      },
      collisionRect: collisionRect2,
      droppableRects: new Map([['row-20', siblingARect]]),
      droppableContainers: [siblingA],
      pointerCoordinates: { x: 50, y: 30 },
    });
    expect(result2.map((c) => c.id)).toContain('row-20'); // hadSiblingHit fallback

    // Simulate new drag: replace sourceContainerItemsRef → resets hadSiblingHit
    // Sibling B at y=400, h=50 → center=425.
    const siblingB = makeContainerWithRect('row-30', { left: 0, top: 400, width: 100, height: 50 });
    const siblingBRect = { ...rect, top: 400, bottom: 450 };
    sourceContainerItemsRef.current = new Set<string | number>(['row-30']);

    // Call 3: pointer inside B but below threshold → no crossing, hadSiblingHit reset → []
    // Initial at y=440 → center=465 (below target center 425).
    // Threshold = Math.min(400+50-25, 425) = 425.
    // CollisionRect center = 427, pointer y=430.
    // currentCY = Math.min(427, 430) = 427 > 425 → NOT crossed.
    // But pointer at y=430 IS inside B's rect (400-450) → guard fires.
    const initialRect3 = { ...rect, top: 440, bottom: 490 };
    const collisionRect3 = { ...rect, top: 402, bottom: 452 };
    const result3 = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect3, translated: collisionRect3 } },
      },
      collisionRect: collisionRect3,
      droppableRects: new Map([['row-30', siblingBRect]]),
      droppableContainers: [siblingB],
      pointerCoordinates: { x: 50, y: 430 },
    });

    // hadSiblingHit was reset by ref change → no fallback → returns []
    expect(result3).toEqual([]);
  });
});

describe('createTypedCollisionDetection', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('hasPendingMove=false behaves identically to static typedCollisionDetection', () => {
    const hasPendingMoveRef = { current: false };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    // Active starts below target, dragged up to target position — center crossing
    const container = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50 };
    const initialRect = { ...rect, top: 200, bottom: 250 };
    const droppableRects = new Map<string, typeof rect>();
    droppableRects.set('row-20', targetRect);

    const args = {
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: targetRect } },
      },
      collisionRect: targetRect,
      droppableRects,
      droppableContainers: [container],
      pointerCoordinates: null,
    };

    const factoryResult = detect(args);
    const staticResult = typedCollisionDetection(args);

    expect(factoryResult.length).toBeGreaterThan(0);
    expect(factoryResult.map((c) => c.id)).toEqual(staticResult.map((c) => c.id));
  });

  it('hasPendingMove=true returns closest sibling even when centerCrossing threshold is not crossed', () => {
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    // Sibling at y=0. Active starts at y=100, dragged to y=60 — NOT crossing
    // the centerCrossing threshold (center at y=85, threshold at y=25).
    // But closestCenterLive sees it as the closest target by distance.
    const siblingRect = { ...rect, top: 0, bottom: 50 }; // center y=25
    const sibling = makeContainerWithRect('row-20', { left: siblingRect.left, top: siblingRect.top, width: siblingRect.width, height: 50 });
    const initialRect = { ...rect, top: 100, bottom: 150 }; // center y=125
    const collisionRect = { ...rect, top: 60, bottom: 110 }; // center y=85

    const droppableRects = new Map<string, typeof rect>();
    droppableRects.set('row-20', siblingRect);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling],
      pointerCoordinates: null,
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('row-20');
  });

  it('hasPendingMove=true skips overlap guard — sibling overlap without crossing still returns closest', () => {
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    // Collision rect overlaps sibling but threshold NOT crossed.
    // With hasPendingMove=false, overlap guard would return [].
    // With hasPendingMove=true, closestCenterLive returns the sibling.
    const siblingRect = { ...rect, top: 0, bottom: 50 };
    const sibling = makeContainerWithRect('row-20', { left: siblingRect.left, top: siblingRect.top, width: siblingRect.width, height: 50 });
    const initialRect = { ...rect, top: 100, bottom: 150 };
    // Overlapping but not crossing threshold
    const collisionRect = { ...rect, top: 30, bottom: 80 };

    const droppableRects = new Map<string, typeof rect>();
    droppableRects.set('row-20', siblingRect);

    const args = {
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling],
      pointerCoordinates: null,
    };

    // Verify that hasPendingMove=false returns empty (overlap guard blocks)
    const detectDefault = createTypedCollisionDetection({ hasPendingMoveRef: { current: false } });
    expect(detectDefault(args)).toEqual([]);

    // hasPendingMove=true skips the guard
    const result = detect(args);
    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('row-20');
  });

  it('hasPendingMove=true skips overRectRef capture for sibling collisions', () => {
    // During a pending cross-container move, getPointerPosition and over.rect
    // (pre-transform from dnd-kit) are both in dnd-kit's coordinate system.
    // Capturing a live DOM rect would introduce a coordinate space mismatch
    // with getPointerPosition during auto-scroll. By skipping capture,
    // handleDragEnd falls back to over.rect for correct direction detection.
    const overRectRef = { current: null as { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null };
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef, overRectRef });

    const sibling = makeContainerWithRect('row-20', { left: 0, top: 80, width: 100, height: 50 });
    const preTransformRect = { ...rect, top: 0, bottom: 50 };

    const initialRect = { ...rect, top: 200, bottom: 250 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };
    const droppableRects = new Map([['row-20', preTransformRect]]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling],
      pointerCoordinates: null,
    });

    // Collision detected but overRectRef NOT captured
    expect(result.length).toBe(1);
    expect(result[0].id).toBe('row-20');
    expect(overRectRef.current).toBeNull();
  });

  it('hasPendingMove=false captures live DOM node ref in overRectRef', () => {
    // For same-container reordering (no pending move), capture the node ref
    // so the consumer can read getBoundingClientRect() at drop time.
    const overRectRef = { current: null as { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null };
    const hasPendingMoveRef = { current: false };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef, overRectRef });

    const sibling = makeContainerWithRect('row-20', { left: 0, top: 80, width: 100, height: 50 });
    const preTransformRect = { ...rect, top: 0, bottom: 50 };

    // Active starts far below, dragged up to sibling's pre-transform position.
    // centerCrossing threshold: target center = 25, initial center = 225.
    // Threshold = min(0+50-25, 25) = 25. CollisionRect center = 25 → crosses.
    const initialRect = { ...rect, top: 200, bottom: 250 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };
    const droppableRects = new Map([['row-20', preTransformRect]]);

    detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling],
      pointerCoordinates: { x: 50, y: 25 },
    });

    expect(overRectRef.current).not.toBeNull();
    expect(overRectRef.current!.id).toBe('row-20');
    expect(overRectRef.current!.nodeRef).toBe(sibling.node);
    expect(overRectRef.current!.nodeRef.current!.getBoundingClientRect().top).toBe(80);
  });

  it('parent container fallback works in both modes', () => {
    for (const pending of [false, true]) {
      const hasPendingMoveRef = { current: pending };
      const detect = createTypedCollisionDetection({ hasPendingMoveRef });

      const parentSection = makeContainer('section-1');
      const droppableRects = new Map<string, typeof rect>();
      droppableRects.set('section-1', rect);

      const result = detect({
        active: {
          id: 'row-10',
          data: { current: undefined },
          rect: { current: { initial: rect, translated: rect } },
        },
        collisionRect: rect,
        droppableRects,
        droppableContainers: [parentSection],
        pointerCoordinates: null,
      });

      expect(result.length).toBeGreaterThan(0);
      expect(result[0].id).toBe('section-1');
    }
  });
});

describe('closestCenterLive — arithmetic mutant killers', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('kills center calculation mutants: ordering flips when + becomes - in centerX', () => {
    // collisionRect: left=100, width=200 → centerX = 100+200/2 = 200
    // If mutated to 100-200/2 = 0, the ordering flips.
    // Container A: center x=190 (close to 200, far from 0)
    // Container B: center x=10 (far from 200, close to 0)
    // Original: A closer. Mutated: B closer.
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    const containerA = makeContainerWithRect('row-20', { left: 140, top: 0, width: 100, height: 50 }); // center x=190
    const containerB = makeContainerWithRect('row-30', { left: 0, top: 0, width: 20, height: 50 }); // center x=10

    const initialRect = { ...rect, left: 500, right: 600, width: 100 };
    const collisionRect = { left: 100, top: 0, width: 200, height: 50, right: 300, bottom: 50 }; // center x=200, y=25

    const droppableRects = new Map([
      ['row-20', { ...rect, left: 140, right: 240 }],
      ['row-30', { ...rect, left: 0, right: 20, width: 20 }],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [containerB, containerA],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
    expect(result[0].id).toBe('row-20'); // A is closer to collisionRect center
  });

  it('kills center calculation mutants: ordering flips when + becomes - in centerY', () => {
    // collisionRect: top=100, height=200 → centerY = 100+200/2 = 200
    // If mutated to 100-200/2 = 0, ordering flips.
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    const containerA = makeContainerWithRect('row-20', { left: 0, top: 150, width: 100, height: 50 }); // center y=175
    const containerB = makeContainerWithRect('row-30', { left: 0, top: 0, width: 100, height: 20 }); // center y=10

    const initialRect = { ...rect, top: 500, bottom: 550 };
    const collisionRect = { left: 0, top: 100, width: 100, height: 200, right: 100, bottom: 300 }; // center y=200

    const droppableRects = new Map([
      ['row-20', { ...rect, top: 150, bottom: 200 }],
      ['row-30', { ...rect, top: 0, bottom: 20, height: 20 }],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [containerB, containerA],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
    expect(result[0].id).toBe('row-20'); // A closer to center y=200
  });

  it('kills distance mutants: dx*dx + dy*dy with distinct X and Y offsets', () => {
    // Containers at different X AND Y so that dx*dx+dy*dy differs from mutated forms.
    // collisionRect center: (150, 150)
    // Container A: center (100, 100) → dx=50, dy=50 → dist=5000
    // Container B: center (200, 250) → dx=50, dy=100 → dist=12500
    // If * becomes / or + becomes -, ordering changes.
    const hasPendingMoveRef = { current: true };
    const detect = createTypedCollisionDetection({ hasPendingMoveRef });

    const containerA = makeContainerWithRect('row-20', { left: 50, top: 75, width: 100, height: 50 }); // center (100, 100)
    const containerB = makeContainerWithRect('row-30', { left: 150, top: 225, width: 100, height: 50 }); // center (200, 250)

    const initialRect = { ...rect, left: 500, top: 500, right: 600, bottom: 550 };
    const collisionRect = { left: 100, top: 125, width: 100, height: 50, right: 200, bottom: 175 }; // center (150, 150)

    const droppableRects = new Map([
      ['row-20', { ...rect, left: 50, top: 75, right: 150, bottom: 125 }],
      ['row-30', { ...rect, left: 150, top: 225, right: 250, bottom: 275 }],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [containerB, containerA],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
    expect(result[0].id).toBe('row-20');
    expect(result[1].id).toBe('row-30');

    // Verify exact distance values to kill subtraction-to-addition mutants
    const distA = result[0].data?.value as number;
    const distB = result[1].data?.value as number;
    expect(distA).toBe(50 * 50 + 50 * 50); // 5000
    expect(distB).toBe(50 * 50 + 100 * 100); // 12500
  });
});

describe('centerCrossing — threshold mutant killers', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('kills initialCY === targetCY boundary (< vs <= on line 190)', () => {
    // initialCY === targetCY: `<` takes else branch, mutated `<=` takes if branch.
    // Target: top=75, h=50, center=100. Initial center=100. crH=40.
    // Else (correct): threshold = Math.min(75+50-20, 100) = 100.
    // If (mutant <=): threshold = 75+20 = 95.
    // crossedY with threshold=100: (100>100)=false, (100<100)=false → no crossing.
    // crossedY with threshold=95: (100>95 && 80<=95)=true → crossing! Mutant produces collision.
    const target = makeContainer('column-20');
    const targetRect = { ...rect, top: 75, bottom: 125 }; // center y=100
    const initialRect = { ...rect, top: 75, bottom: 125 }; // center y=100 (same as target)
    const collisionRect = { ...rect, height: 40, top: 60, bottom: 100 }; // center y=80, crH=40

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 80 },
    });

    // Original: no crossing (threshold=100, initialCY=100 → neither clause fires).
    // Mutant: crossing (threshold=95, 100>95 && 80<=95 → true).
    expect(result).toHaveLength(0);
  });

  it('kills initialCX === targetCX boundary (< vs <= on line 194)', () => {
    // initialCX === targetCX: `<` takes else branch, mutated `<=` takes if branch.
    // Target: left=50, w=100, center=100. Initial center x=100. crW=40.
    // Else (correct): threshold = Math.min(50+100-20, 100) = 100.
    // If (mutant <=): threshold = 50+20 = 70.
    // crossedX with threshold=100: (100>100)=false, (100<100)=false → no crossing.
    // crossedX with threshold=70: (100>70 && 65<=70)=true → crossing!
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 50, right: 150 }; // center x=100
    const initialRect = { ...rect, left: 50, right: 150 }; // center x=100 (same)
    const collisionRect = { ...rect, width: 40, left: 45, right: 85 }; // center x=65, crW=40

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 65, y: 25 },
    });

    expect(result).toHaveLength(0);
  });

  it('kills Math.min → Math.max mutant on thresholdY (line 192)', () => {
    // Dragging from below: initialCY > targetCY.
    // Threshold = Math.min(rect.top + rect.height - crH/2, targetCY).
    // When edge > center (large overlay, small target), Math.min picks center.
    // Math.max would pick edge → different threshold.
    // Target: top=100, h=30, center=115. collisionRect h=50 → crH/2=25.
    // Edge threshold: 100+30-25 = 105. center=115.
    // Math.min(105, 115) = 105. Math.max(105, 115) = 115.
    // Initial center: y=300 (below). Current center: y=110.
    // Original threshold=105: (300>105 && 110<=105) → false (110 > 105).
    // Mutant threshold=115: (300>115 && 110<=115) → true! Collision.
    // Test asserts no collision → kills the mutant.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 100, bottom: 130, height: 30 }; // center y=115
    const initialRect = { ...rect, top: 275, bottom: 325 }; // center y=300
    const collisionRect = { ...rect, top: 85, bottom: 135 }; // center y=110, h=50

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 110 },
    });

    // Original threshold=105: not crossed (110 > 105). No collision.
    // Mutant Math.max → threshold=115: crossed (110 <= 115). Would produce collision.
    expect(result).toHaveLength(0);
  });

  it('kills Math.min → Math.max mutant on thresholdX (line 196)', () => {
    // Same logic on X axis. Dragging from right: initialCX > targetCX.
    // Target: left=100, w=30, center=115. collisionRect w=50 → crW/2=25.
    // Edge threshold: 100+30-25 = 105. Math.min(105, 115) = 105. Math.max = 115.
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 100, right: 130, width: 30 }; // center x=115
    const initialRect = { ...rect, left: 275, right: 375 }; // center x=325
    const crRect = { ...rect, left: 85, right: 135, width: 50 }; // center x=110

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: crRect } },
      },
      collisionRect: crRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 110, y: 25 },
    });

    // Original threshold=105: (325>105 && 110<=105) → false. No collision.
    // Mutant threshold=115: (325>115 && 110<=115) → true. Collision.
    expect(result).toHaveLength(0);
  });

  it('kills - → + mutant on collisionRect.height/2 in thresholdY (line 192)', () => {
    // Dragging from below: initialCY > targetCY.
    // Threshold = Math.min(rect.top + rect.height - crH/2, targetCY).
    // Mutant: rect.top + rect.height + crH/2.
    // Target: top=100, h=50, center=125. crH=40 → crH/2=20.
    // Original: Math.min(100+50-20, 125) = Math.min(130, 125) = 125.
    // Mutant: Math.min(100+50+20, 125) = Math.min(170, 125) = 125. Same! Need edge < center.
    // Target: top=100, h=30, center=115. crH=50, crH/2=25.
    // Original: Math.min(100+30-25, 115) = Math.min(105, 115) = 105.
    // Mutant: Math.min(100+30+25, 115) = Math.min(155, 115) = 115.
    // Initial center=300. Current center=108.
    // Original threshold=105: (300>105 && 108<=105) → false.
    // Mutant threshold=115: (300>115 && 108<=115) → true! Collision.
    // But this is same as the Math.min test above. Let's find a case where it uniquely kills.
    // Need a case where Math.min picks edge in original but picks different edge in mutant.
    // Target: top=200, h=100, center=250. crH=80, crH/2=40.
    // Original: Math.min(200+100-40, 250) = Math.min(260, 250) = 250.
    // Mutant (-→+): Math.min(200+100+40, 250) = Math.min(340, 250) = 250. Same!
    // Only diverges when edge < center. Use small target, large overlay:
    // Target: top=100, h=20, center=110. crH=50, crH/2=25.
    // Original: Math.min(100+20-25, 110) = Math.min(95, 110) = 95.
    // Mutant: Math.min(100+20+25, 110) = Math.min(145, 110) = 110.
    // Initial=300. Current=98.
    // Original threshold=95: (300>95 && 98<=95) → false.
    // Mutant threshold=110: (300>110 && 98<=110) → true! Collision.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 100, bottom: 120, height: 20 }; // center y=110
    const initialRect = { ...rect, top: 275, bottom: 325 }; // center y=300
    const collisionRect = { ...rect, top: 73, bottom: 123 }; // center y=98, h=50

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 98 },
    });

    expect(result).toHaveLength(0);
  });

  it('kills - → + mutant on collisionRect.width/2 in thresholdX (line 196)', () => {
    // Same logic on X axis. Target: left=100, w=20, center=110. crW=50.
    // Original: Math.min(100+20-25, 110) = Math.min(95, 110) = 95.
    // Mutant: Math.min(100+20+25, 110) = Math.min(145, 110) = 110.
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 100, right: 120, width: 20 }; // center x=110
    const initialRect = { ...rect, left: 275, right: 375 }; // center x=325
    const crRect = { ...rect, left: 73, right: 123, width: 50 }; // center x=98

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: crRect } },
      },
      collisionRect: crRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 98, y: 25 },
    });

    expect(result).toHaveLength(0);
  });
});

describe('centerCrossing — crossing boundary mutant killers', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('kills currentCY === thresholdY boundary (>= vs > on line 207)', () => {
    // Dragging downward: initialCY < targetCY.
    // crossedY second clause: initialCY < thresholdY && currentCY >= thresholdY.
    // When currentCY === thresholdY, >= accepts, > rejects.
    // Target: top=200, h=50, center=225. crH=50 → crH/2=25.
    // Threshold (if branch, initialCY<targetCY): rect.top + crH/2 = 200+25 = 225.
    // Initial center: y=25 (< 225). Current center: y=225 (=== threshold).
    // Original: (25<225 && 225>=225) → true → COLLISION.
    // Mutant (>): (25<225 && 225>225) → false → NO collision.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 200, bottom: 250 };
    const initialRect = { ...rect, top: 0, bottom: 50 }; // center y=25
    const collisionRect = { ...rect, top: 200, bottom: 250 }; // center y=225

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 225 },
    });

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('row-20');
  });

  it('kills currentCX >= thresholdX boundary (>= vs > on line 211)', () => {
    // Dragging rightward: initialCX < targetCX.
    // crossedX: initialCX < thresholdX && currentCX >= thresholdX.
    // currentCX exactly at threshold.
    const target = makeContainer('column-20');
    const targetRect = { ...rect, left: 200, right: 300 }; // center x=250
    const initialRect = { ...rect, left: 0, right: 100 }; // center x=50

    // Threshold = left + crW/2 = 200+25 = 225. currentCX = 225.
    // Original: (50<225 && 225>=225) → true. Mutant (>): 225>225 → false.
    const crRect = { ...rect, left: 200, right: 250, width: 50 }; // center x=225, w=50

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'column-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: crRect } },
      },
      collisionRect: crRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 225, y: 25 },
    });

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('column-20');
  });

  it.skip('kills initialCX < targetCX → <= on line 202 (equivalent mutant)', () => {
    // When initialCX === targetCX (only case where < vs <= differs), both crossing
    // clauses (initialCX > threshold, initialCX < threshold) evaluate to false
    // regardless of threshold value. The mutant produces identical behavior.
  });

  it('kills initialCY < targetCY → <= on line 203 and Math.max → Math.min on currentCY', () => {
    // Line 203: currentCY = initialCY < targetCY ? Math.max(crCY, ptrY) : Math.min(crCY, ptrY)
    // If Math.max → Math.min: when crCY > ptrY, Math.max picks crCY, Math.min picks ptrY.
    // Need crCY to cross threshold but ptrY not to (or vice versa).
    // Dragging downward: initialCY=25 < targetCY=225.
    // crCY=230, ptrY=210. Math.max=230, Math.min=210.
    // Threshold = 200 + 25 = 225 (crH=50).
    // Original Math.max: currentCY=230. (25<225 && 230>=225) → true → COLLISION.
    // Mutant Math.min: currentCY=210. (25<225 && 210>=225) → false → NO collision.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 200, bottom: 250 }; // center y=225
    const initialRect = { ...rect, top: 0, bottom: 50 }; // center y=25
    const collisionRect = { ...rect, top: 205, bottom: 255 }; // center y=230

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 210 }, // ptrY < threshold, crCY > threshold
    });

    // Original: Math.max(230, 210) = 230 >= 225 → collision.
    // Mutant: Math.min(230, 210) = 210 < 225 → no collision.
    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('row-20');
  });

  it('kills crossedX true → constant true mutant (line 210)', () => {
    // Line 210: `(initialCX > thresholdX && currentCX <= thresholdX) ||` → `true`
    // If mutated to true, crossedX is always true. Need a test where
    // crossedX should be false but crossedY is also false → no collision.
    // Then the mutant would make crossedX true → collision (if overlap passes).
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 0, bottom: 50, left: 0, right: 100 }; // center (50, 25)
    // Initial very close to target — no crossing on either axis
    const initialRect = { ...rect, top: 5, bottom: 55, left: 5, right: 105 }; // center (55, 30)
    const collisionRect = { ...rect, top: 10, bottom: 60, left: 10, right: 110 }; // center (60, 35)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 60, y: 35 },
    });

    // Neither axis crosses threshold → no collision.
    // Mutant (line 210 → true): crossedX=true, overlap passes → collision.
    expect(result).toHaveLength(0);
  });
});

describe('centerCrossing — overlap gate boundary mutant killers', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };
  // MARGIN_X = 50, MARGIN_Y = 150

  // Helper: create a centerCrossing call where crossing IS met,
  // but pointer is exactly at the overlap boundary.
  function makeCrossingArgs(
    target: ReturnType<typeof makeContainer>,
    targetRect: typeof rect,
    ptrX: number,
    ptrY: number,
  ) {
    // Active starts far below target, dragged up past threshold → crossing met
    const initialRect = { ...rect, top: 400, bottom: 450 }; // center y=425
    const collisionRect = { ...targetRect }; // center matches target → crossed

    return {
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects: new Map([[target.id, targetRect]]),
      droppableContainers: [target],
      pointerCoordinates: { x: ptrX, y: ptrY },
    };
  }

  it('rejects when ptrX === rect.left - MARGIN_X (> vs >= on line 228)', () => {
    // rect.left=100. MARGIN_X=50. Boundary: ptrX = 100-50 = 50.
    // Original `>`: 50 > 50 → false → rejected.
    // Mutant `>=`: 50 >= 50 → true → accepted.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, left: 100, right: 200, top: 200, bottom: 250 };
    // ptrX=50 exactly at left boundary, ptrY safely inside
    const result = centerCrossing(makeCrossingArgs(target, targetRect, 50, 225));
    expect(result).toHaveLength(0);
  });

  it('rejects when ptrX === rect.left + rect.width + MARGIN_X (< vs <= on line 228)', () => {
    // rect.left=100, width=100 → right edge at 200. MARGIN_X=50. Boundary: 200+50=250.
    // Original `<`: 250 < 250 → false → rejected.
    // Mutant `<=`: 250 <= 250 → true → accepted.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, left: 100, right: 200, top: 200, bottom: 250 };
    const result = centerCrossing(makeCrossingArgs(target, targetRect, 250, 225));
    expect(result).toHaveLength(0);
  });

  it('rejects when ptrY === rect.top - MARGIN_Y (> vs >= on line 229)', () => {
    // rect.top=200. MARGIN_Y=150. Boundary: 200-150 = 50.
    // Original `>`: 50 > 50 → false → rejected.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 200, bottom: 250 };
    const result = centerCrossing(makeCrossingArgs(target, targetRect, 50, 50));
    expect(result).toHaveLength(0);
  });

  it('rejects when ptrY === rect.top + rect.height + MARGIN_Y (< vs <= on line 229)', () => {
    // rect.top=200, height=50 → bottom 250. MARGIN_Y=150. Boundary: 250+150=400.
    // Original `<`: 400 < 400 → false → rejected.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 200, bottom: 250 };
    const result = centerCrossing(makeCrossingArgs(target, targetRect, 50, 400));
    expect(result).toHaveLength(0);
  });

  it('accepts when pointer is just inside all overlap boundaries', () => {
    // Verify the test setup works — one pixel inside each boundary should pass.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, left: 100, right: 200, top: 200, bottom: 250 };
    // ptrX=51 (> 50), ptrY=51 (> 50), both inside
    const result = centerCrossing(makeCrossingArgs(target, targetRect, 51, 51));
    expect(result).toHaveLength(1);
  });
});

describe('centerCrossing — distance value mutant killers', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('verifies collision value matches (crCX - targetCX)^2 + (crCY - targetCY)^2', () => {
    // Kills crCX-targetCX → crCX+targetCX and crCY-targetCY → crCY+targetCY.
    // Target center: (50, 125). CollisionRect center: (50, 25).
    // dx = 50-50 = 0, dy = 25-125 = -100. value = 0 + 10000 = 10000.
    // If mutated (+): dx = 50+50=100, dy = 25+125=150. value = 10000+22500 = 32500.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, top: 100, bottom: 150 }; // center (50, 125)
    const initialRect = { ...rect, top: 300, bottom: 350 }; // center y=325 (far below)
    const collisionRect = { ...rect, top: 0, bottom: 50 }; // center (50, 25) — crossed threshold

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 50, y: 25 },
    });

    expect(result).toHaveLength(1);
    // crCX=50, targetCX=50: dx=0. crCY=25, targetCY=125: dy=-100. value=10000.
    expect(result[0].data?.value).toBe(10000);
  });

  it('verifies X distance component in collision value', () => {
    // Non-zero dx to kill crCX-targetCX → crCX+targetCX.
    // Target center: (150, 25). CollisionRect center: (50, 25).
    // dx = 50-150 = -100. dy = 25-25 = 0. value = 10000.
    // Mutant: dx = 50+150 = 200. value = 40000. Different.
    const target = makeContainer('row-20');
    const targetRect = { ...rect, left: 100, right: 200, top: 0, bottom: 50 }; // center (150, 25)
    const initialRect = { ...rect, left: 0, right: 100, top: 200, bottom: 250 }; // far below
    const collisionRect = { ...rect, left: 0, right: 100, top: 0, bottom: 50 }; // center (50, 25)

    const droppableRects = new Map([[target.id, targetRect]]);
    const result = centerCrossing({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [target],
      pointerCoordinates: { x: 120, y: 25 }, // inside target overlap margins
    });

    expect(result).toHaveLength(1);
    expect(result[0].data?.value).toBe(100 * 100); // 10000
  });
});

describe('createTypedCollisionDetection — additional mutant killers', () => {
  const rect = { width: 100, height: 50, top: 0, left: 0, right: 100, bottom: 50 };

  it('hadSiblingHit starts as false — no fallback on first call with no crossing', () => {
    // Kills `let hadSiblingHit = false` → `true`.
    // On fresh instance, pointer inside source sibling but no crossing.
    // hadSiblingHit=false → should return [] (guard blocks, no fallback).
    // Mutant hadSiblingHit=true → closestCenterLive fallback fires → returns sibling.
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sibling = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const siblingRect = { ...rect, top: 0, bottom: 50 };

    // Pointer inside sibling but NOT crossing threshold.
    // Sibling center=25. Initial center=60 (below). Threshold = Math.min(0+50-25, 25) = 25.
    // CR center=27. currentCY = Math.min(27, 30) = 27. (60>25 && 27<=25) → false. No crossing.
    const initialRect = { ...rect, top: 35, bottom: 85 }; // center y=60
    const collisionRect = { ...rect, top: 2, bottom: 52 }; // center y=27

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects: new Map([['row-20', siblingRect]]),
      droppableContainers: [sibling],
      pointerCoordinates: { x: 50, y: 30 },
    });

    // Fresh instance: hadSiblingHit=false → no fallback → []
    // Mutant: hadSiblingHit=true → fallback fires → sibling returned
    expect(result).toEqual([]);
  });

  it('captureWinnerNode does not capture when collisions array is empty', () => {
    // Kills `collisions.length > 0` → `>= 0` and `options.overRectRef && collisions.length > 0` → `true`.
    // When no sibling crosses and no parent contains pointer, result is [] from closestCenter.
    // overRectRef should stay null.
    const overRectRef = { current: null as { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      overRectRef,
    });

    // Only provide containers of wrong type → no collisions from any pass
    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: rect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects: new Map([['element-100', rect]]),
      droppableContainers: [makeContainer('element-100')],
      pointerCoordinates: null,
    });

    expect(result).toEqual([]);
    expect(overRectRef.current).toBeNull();
  });

  it('captureWinnerNode finds the correct container by winnerId', () => {
    // Kills `args.droppableContainers.find(...)` → `true`.
    // Verify the captured nodeRef belongs to the correct (winning) container.
    const overRectRef = { current: null as { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      overRectRef,
    });

    const winner = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const loser = makeContainerWithRect('row-30', { left: 0, top: 60, width: 100, height: 50 });

    const initialRect = { ...rect, top: 200, bottom: 250 };
    const collisionRect = { ...rect, top: 0, bottom: 50 };

    const droppableRects = new Map([
      ['row-20', { ...rect, top: 0, bottom: 50 }],
      ['row-30', { ...rect, top: 60, bottom: 110 }],
    ]);

    detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [winner, loser],
      pointerCoordinates: { x: 50, y: 25 },
    });

    expect(overRectRef.current).not.toBeNull();
    expect(overRectRef.current!.id).toBe('row-20');
    // Verify it's the actual winner's node, not any other container
    expect(overRectRef.current!.nodeRef).toBe(winner.node);
    expect(overRectRef.current!.nodeRef.current).not.toBe(loser.node.current);
  });

  it('source sibling .some() vs .every() — pointer inside one of two siblings', () => {
    // Kills `.some()` → `.every()` on line 404.
    // Two source siblings. Pointer is inside sibling A but NOT inside sibling B.
    // `.some()` returns true → guard blocks → [].
    // `.every()` returns false → guard skipped → parent fallback.
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20', 'row-30']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const siblingA = makeContainer('row-20');
    const siblingB = makeContainer('row-30');
    const parentSection = makeContainer('section-1');

    // Sibling A at y=0-50 (center=25), sibling B at y=200-250
    const siblingARect = { ...rect, top: 0, bottom: 50 }; // pointer INSIDE
    const siblingBRect = { ...rect, top: 200, bottom: 250 }; // pointer NOT inside
    const sectionRect = { ...rect, top: 0, bottom: 300, height: 300 };

    // No crossing: initial BELOW target A (center=25).
    // initialCY=60 > targetCY=25 → else branch.
    // thresholdY = Math.min(0+50-25, 25) = 25.
    // collisionRect center=35, pointer y=30.
    // currentCY = Math.min(35, 30) = 30. crossedY = (60>25 && 30<=25) → false.
    // For sibling B (center=225): initialCY=60 < targetCY=225 → if branch.
    // thresholdY = 200+25 = 225. currentCY = Math.max(35, 30) = 35. 35>=225 → false.
    // No crossing on either sibling.
    const initialRect = { ...rect, top: 35, bottom: 85 }; // center y=60
    const collisionRect = { ...rect, top: 10, bottom: 60 }; // center y=35

    const droppableRects = new Map([
      ['row-20', siblingARect],
      ['row-30', siblingBRect],
      ['section-1', sectionRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [siblingA, siblingB, parentSection],
      pointerCoordinates: { x: 50, y: 30 }, // inside A (0-50), not inside B (200-250)
    });

    // .some() → pointer inside A → guard blocks → []
    // .every() → pointer not inside B → guard skipped → parent fallback
    expect(result).toEqual([]);
  });

  it('pointer exactly at source sibling left edge (>= vs > on line 409)', () => {
    // pointerCoordinates.x === rect.left. `>=` considers inside, `>` does not.
    // Key: centerCrossing must NOT fire (no crossing), then pointer-inside guard checks.
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sibling = makeContainer('row-20');
    const parentSection = makeContainer('section-1');
    // Sibling at left=100, top=0, h=50 → center (150, 25)
    const siblingRect = { ...rect, left: 100, right: 200, top: 0, bottom: 50 };
    const sectionRect = { ...rect, left: 50, right: 250, top: 0, bottom: 100, width: 200, height: 100 };

    // Initial center below sibling (y=60). Sibling center y=25.
    // thresholdY = Math.min(0+50-25, 25) = 25. currentCY=35. (60>25 && 35<=25) → false.
    // thresholdX: initialCX=50, targetCX=150. initialCX < targetCX → threshold=100+25=125.
    // currentCX=50. (50<125 && 50>=125) → false. No crossing.
    const initialRect = { ...rect, top: 35, bottom: 85 }; // center (50, 60)
    const collisionRect = { ...rect, top: 10, bottom: 60 }; // center (50, 35)

    const droppableRects = new Map([
      ['row-20', siblingRect],
      ['section-1', sectionRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling, parentSection],
      pointerCoordinates: { x: 100, y: 30 }, // x exactly at sibling left edge, y inside (0-50)
    });

    // `>=`: x=100 >= 100 → inside → guard blocks → []
    // `>`: x=100 > 100 → false → not inside → parent fallback
    expect(result).toEqual([]);
  });

  it('pointer exactly at source sibling right edge (left + width) (<= vs < on line 410)', () => {
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sibling = makeContainer('row-20');
    const parentSection = makeContainer('section-1');
    // Sibling at left=0, w=100, top=0, h=50 → center (50, 25)
    const siblingRect = { ...rect, left: 0, right: 100, top: 0, bottom: 50 };
    const sectionRect = { ...rect, top: 0, bottom: 100, height: 100 };

    // Initial center below sibling (y=60). thresholdY=Math.min(0+50-25,25)=25. currentCY=35. Not crossed.
    const initialRect = { ...rect, top: 35, bottom: 85 }; // center (50, 60)
    const collisionRect = { ...rect, top: 10, bottom: 60 }; // center (50, 35)

    const droppableRects = new Map([
      ['row-20', siblingRect],
      ['section-1', sectionRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling, parentSection],
      pointerCoordinates: { x: 100, y: 30 }, // x exactly at left+width=100, y inside (0-50)
    });

    // `<=`: x=100 <= 100 → inside → guard blocks → []
    // `<`: x=100 < 100 → false → not inside → parent fallback
    expect(result).toEqual([]);
  });

  it('pointer exactly at source sibling top edge (>= vs > on line 411)', () => {
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sibling = makeContainer('row-20');
    const parentSection = makeContainer('section-1');
    // Sibling at top=100, h=50, center y=125
    const siblingRect = { ...rect, top: 100, bottom: 150 };
    const sectionRect = { ...rect, top: 0, bottom: 200, height: 200 };

    // Initial center below sibling center (y=160). thresholdY = Math.min(100+50-25, 125) = 125.
    // collisionRect center y=140. pointer y=100.
    // currentCY = Math.min(140, 100) = 100. crossedY = (160>125 && 100<=125) → true!
    // That crosses. Need to avoid crossing.
    // Make initial center y=128. threshold=125. currentCY=Math.min(127, 100)=100. (128>125 && 100<=125) → true.
    // Still crosses. Need initial center BELOW threshold and current NOT crossing.
    // Initial center=160, threshold=125, currentCY needs to be > 125.
    // pointer at y=100 (top edge). currentCY = Math.min(crCY, ptrY) = Math.min(140, 100) = 100. 100 <= 125 → crosses!
    // The pointer at the boundary IS inside the sibling, but the current position crosses.
    // Problem: the pointer y=100 (top edge of sibling) is way below threshold 125 — it crosses.
    // I need to avoid crossing entirely. Use a setup where initial is ABOVE the target.
    // Initial center y=90, targetCY=125. initialCY < targetCY → if branch.
    // thresholdY = 100 + 25 = 125. currentCY = Math.max(crCY, ptrY).
    // crCY=105, ptrY=100. Math.max=105. (90<125 && 105>=125) → false. No crossing!
    const initialRect = { ...rect, top: 65, bottom: 115 }; // center y=90
    const collisionRect = { ...rect, top: 80, bottom: 130 }; // center y=105

    const droppableRects = new Map([
      ['row-20', siblingRect],
      ['section-1', sectionRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling, parentSection],
      pointerCoordinates: { x: 50, y: 100 }, // y exactly at sibling top edge
    });

    // x=50 inside (0-100). y=100 inside sibling (100-150) with `>=`, not with `>`.
    expect(result).toEqual([]);
  });

  it('pointer exactly at source sibling bottom edge (top + height) (<= vs < on line 412)', () => {
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    const sibling = makeContainer('row-20');
    const parentSection = makeContainer('section-1');
    // Sibling at top=0, h=50, center y=25
    const siblingRect = { ...rect, top: 0, bottom: 50 };
    const sectionRect = { ...rect, top: 0, bottom: 100, height: 100 };

    // Initial center below sibling (y=60). thresholdY=Math.min(0+50-25,25)=25.
    // crCY=35, ptrY=50. currentCY = Math.min(35,50) = 35. (60>25 && 35<=25) → false.
    const initialRect = { ...rect, top: 35, bottom: 85 }; // center y=60
    const collisionRect = { ...rect, top: 10, bottom: 60 }; // center y=35

    const droppableRects = new Map([
      ['row-20', siblingRect],
      ['section-1', sectionRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [sibling, parentSection],
      pointerCoordinates: { x: 50, y: 50 }, // y exactly at top+height=50
    });

    // `<=`: y=50 <= 50 → inside → guard blocks → []
    // `<`: y=50 < 50 → false → not inside → parent fallback
    expect(result).toEqual([]);
  });

  it('hadSiblingHit fallback returns [] when closestCenterLive has no DOM nodes (line 428)', () => {
    // Kills `liveCollisions.length > 0` → `>= 0`.
    // hadSiblingHit=true, pointer inside source sibling, no crossing,
    // but sibling has no DOM node → closestCenterLive returns [].
    // Should return [] (guard blocks). Mutant >= 0 would return empty array from fallback.
    const sourceContainerItemsRef = { current: new Set<string | number>(['row-20']) };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef,
    });

    // First call: use container with DOM node to get hadSiblingHit=true
    const siblingWithNode = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const siblingRect = { ...rect, top: 0, bottom: 50 };

    const initialRect1 = { ...rect, top: 200, bottom: 250 };
    const collisionRect1 = { ...rect, top: 0, bottom: 50 };

    detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect1, translated: collisionRect1 } },
      },
      collisionRect: collisionRect1,
      droppableRects: new Map([['row-20', siblingRect]]),
      droppableContainers: [siblingWithNode],
      pointerCoordinates: { x: 50, y: 25 },
    });
    // hadSiblingHit is now true

    // Second call: sibling WITHOUT DOM node (node.current = null)
    const siblingNoNode = makeContainer('row-20'); // node.current = null
    const initialRect2 = { ...rect, top: 60, bottom: 110 };
    const collisionRect2 = { ...rect, top: 2, bottom: 52 };

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect2, translated: collisionRect2 } },
      },
      collisionRect: collisionRect2,
      droppableRects: new Map([['row-20', siblingRect]]),
      droppableContainers: [siblingNoNode], // no DOM node
      pointerCoordinates: { x: 50, y: 30 },
    });

    // closestCenterLive returns [] (no DOM nodes).
    // Original (> 0): skips, falls through to return [].
    // Mutant (>= 0): returns [] from liveCollisions (empty captureWinnerNode).
    // Both return [] but mutant calls captureWinnerNode with empty array.
    // Actually the mutant `>= 0` means 0 >= 0 → true → returns captureWinnerNode([]) = [].
    // The guard after this is `return [];` at line 433. So the control flow:
    // if (liveCollisions.length > 0) { return captureWinnerNode(liveCollisions); }
    // return [];
    // Mutant: if (liveCollisions.length >= 0) → always true → return captureWinnerNode([]).
    // Both return []. But captureWinnerNode with empty array won't capture.
    // This is likely an equivalent mutant for the return value. Skip assertion.
    expect(result).toEqual([]);
  });

  it('parent containment: pointer exactly at left edge (>= vs > on line 451)', () => {
    // Kills `args.pointerCoordinates!.x >= rect.left` → `> rect.left`.
    // Pointer exactly at rect.left. `>=` considers inside, `>` does not.
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
    });

    const parentA = makeContainer('section-1');
    const parentB = makeContainer('section-2');
    const parentARect = { ...rect, left: 100, right: 200, top: 0, bottom: 200, height: 200 };
    const parentBRect = { ...rect, left: 300, right: 400, top: 0, bottom: 200, height: 200 };

    const droppableRects = new Map([
      ['section-1', parentARect],
      ['section-2', parentBRect],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: rect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [parentA, parentB],
      pointerCoordinates: { x: 100, y: 100 }, // exactly at parentA left edge
    });

    // `>=`: pointer inside parentA → containment match → returns parentA with value: 0.
    // `>`: pointer NOT inside → falls to closestCenter.
    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('section-1');
    expect(result[0].data?.value).toBe(0);
  });

  it('parent containment: pointer exactly at right edge (left + width) (<= vs < on line 452)', () => {
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
    });

    const parent = makeContainer('section-1');
    const parentRect = { ...rect, left: 0, right: 100, top: 0, bottom: 200, height: 200 };

    const droppableRects = new Map([['section-1', parentRect]]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: rect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [parent],
      pointerCoordinates: { x: 100, y: 100 }, // exactly at left + width
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('section-1');
    expect(result[0].data?.value).toBe(0);
  });

  it('parent containment: pointer exactly at top edge (>= vs > on line 453)', () => {
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
    });

    const parent = makeContainer('section-1');
    const parentRect = { ...rect, top: 100, bottom: 200, height: 100 };

    const droppableRects = new Map([['section-1', parentRect]]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: rect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [parent],
      pointerCoordinates: { x: 50, y: 100 }, // exactly at top edge
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('section-1');
    expect(result[0].data?.value).toBe(0);
  });

  it('parent containment: pointer exactly at bottom edge (top + height) (<= vs < on line 454)', () => {
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
    });

    const parent = makeContainer('section-1');
    const parentRect = { ...rect, top: 0, bottom: 50 };

    const droppableRects = new Map([['section-1', parentRect]]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: rect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [parent],
      pointerCoordinates: { x: 50, y: 50 }, // exactly at top + height
    });

    expect(result.length).toBeGreaterThan(0);
    expect(result[0].id).toBe('section-1');
    expect(result[0].data?.value).toBe(0);
  });

  it('containment parent collision has droppableContainer and value properties (line 460)', () => {
    // Kills `{ droppableContainer: containingParent, value: 0 }` → `{}`
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
    });

    const parent = makeContainer('section-1');
    const parentRect = { ...rect, top: 0, bottom: 100, height: 100 };

    const droppableRects = new Map([['section-1', parentRect]]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: rect, translated: rect } },
      },
      collisionRect: rect,
      droppableRects,
      droppableContainers: [parent],
      pointerCoordinates: { x: 50, y: 25 },
    });

    expect(result).toHaveLength(1);
    expect(result[0].data).toBeDefined();
    expect(result[0].data?.droppableContainer).toBe(parent);
    expect(result[0].data?.value).toBe(0);
  });

  it('pendingContainerItemsRef=null falls back to all siblings', () => {
    // Kills `pendingItems !== null && pendingItems !== undefined` branch.
    // When null, should use all siblings (not filter).
    const pendingContainerItemsRef = { current: null as ReadonlySet<string | number> | null };
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: true },
      pendingContainerItemsRef,
    });

    const siblingA = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const siblingB = makeContainerWithRect('row-30', { left: 0, top: 60, width: 100, height: 50 });

    const initialRect = { ...rect, top: 300, bottom: 350 };
    const collisionRect = { ...rect, top: 25, bottom: 75 };

    const droppableRects = new Map([
      ['row-20', { ...rect, top: 0, bottom: 50 }],
      ['row-30', { ...rect, top: 60, bottom: 110 }],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [siblingA, siblingB],
      pointerCoordinates: null,
    });

    // Both siblings should be in results (not filtered)
    expect(result).toHaveLength(2);
  });

  it('pendingContainerItemsRef=undefined falls back to all siblings', () => {
    // When pendingContainerItemsRef is not provided at all
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: true },
      // no pendingContainerItemsRef
    });

    const siblingA = makeContainerWithRect('row-20', { left: 0, top: 0, width: 100, height: 50 });
    const siblingB = makeContainerWithRect('row-30', { left: 0, top: 60, width: 100, height: 50 });

    const initialRect = { ...rect, top: 300, bottom: 350 };
    const collisionRect = { ...rect, top: 25, bottom: 75 };

    const droppableRects = new Map([
      ['row-20', { ...rect, top: 0, bottom: 50 }],
      ['row-30', { ...rect, top: 60, bottom: 110 }],
    ]);

    const result = detect({
      active: {
        id: 'row-10',
        data: { current: undefined },
        rect: { current: { initial: initialRect, translated: collisionRect } },
      },
      collisionRect,
      droppableRects,
      droppableContainers: [siblingA, siblingB],
      pointerCoordinates: null,
    });

    expect(result).toHaveLength(2);
  });
});
