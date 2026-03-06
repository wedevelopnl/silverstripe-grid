import type { DroppableContainer } from '@dnd-kit/core';

import {
  centerCrossing,
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
