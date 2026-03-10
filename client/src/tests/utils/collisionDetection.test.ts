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
