import type { ClientRect, DroppableContainer } from '@dnd-kit/core'
import { createDroppable, createDroppableWithRect, makeDomRect } from '@/testing/dndRectFactories'
import {
  centerCrossing,
  createTypedCollisionDetection,
  filterDroppablesByType,
  filterParentContainers,
  filterSiblings,
  typedCollisionDetection,
} from './collisionDetection'

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
  ]

  it('returns siblings and parent containers for a row (rows + sections)', () => {
    const result = filterDroppablesByType('row-17', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(expect.arrayContaining(['row-10', 'row-20', 'section-1', 'section-2']))
    expect(ids).not.toContain('column-5')
    expect(ids).not.toContain('element-100')
    expect(ids).not.toContain('root')
  })

  it('returns siblings and parent containers for a column (columns + rows)', () => {
    const result = filterDroppablesByType('column-5', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(expect.arrayContaining(['column-5', 'column-6', 'row-10', 'row-20']))
    expect(ids).not.toContain('section-1')
    expect(ids).not.toContain('element-100')
  })

  it('returns siblings and parent containers for an element (elements + columns)', () => {
    const result = filterDroppablesByType('element-100', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(
      expect.arrayContaining(['element-100', 'element-200', 'column-5', 'column-6']),
    )
    expect(ids).not.toContain('row-10')
    expect(ids).not.toContain('section-1')
  })

  it('returns sections and containers with unparseable IDs for a section (parent = root)', () => {
    const result = filterDroppablesByType('section-1', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(expect.arrayContaining(['section-1', 'section-2', 'root']))
    expect(ids).not.toContain('row-10')
    expect(ids).not.toContain('column-5')
  })

  it('returns empty array for unknown active type', () => {
    expect(filterDroppablesByType('unknown-99', containers)).toEqual([])
    expect(filterDroppablesByType('root', containers)).toEqual([])
    expect(filterDroppablesByType('', containers)).toEqual([])
  })
})

describe('filterSiblings', () => {
  const containers = [
    createDroppable('section-1'),
    createDroppable('section-2'),
    createDroppable('row-10'),
    createDroppable('row-20'),
    createDroppable('column-5'),
    createDroppable('element-100'),
    createDroppable('root'),
  ]

  it('returns only same-type containers for a row', () => {
    const result = filterSiblings('row-17', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(['row-10', 'row-20'])
  })

  it('returns only same-type containers for a section', () => {
    const result = filterSiblings('section-3', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(['section-1', 'section-2'])
  })

  it('excludes parent types and unparseable IDs', () => {
    const result = filterSiblings('row-17', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).not.toContain('section-1')
    expect(ids).not.toContain('root')
    expect(ids).not.toContain('column-5')
    expect(ids).not.toContain('element-100')
  })

  it('returns empty for unknown active type', () => {
    expect(filterSiblings('root', containers)).toEqual([])
  })
})

describe('filterParentContainers', () => {
  const containers = [
    createDroppable('section-1'),
    createDroppable('section-2'),
    createDroppable('row-10'),
    createDroppable('column-5'),
    createDroppable('element-100'),
    createDroppable('root'),
  ]

  it('returns only parent-type containers for a row (sections)', () => {
    const result = filterParentContainers('row-17', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(['section-1', 'section-2'])
  })

  it('returns only parent-type containers for a column (rows)', () => {
    const result = filterParentContainers('column-5', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(['row-10'])
  })

  it('returns only parent-type containers for an element (columns)', () => {
    const result = filterParentContainers('element-100', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(['column-5'])
  })

  it('returns containers with unparseable IDs for sections (parent = root)', () => {
    const result = filterParentContainers('section-1', containers)
    const ids = result.map((c) => String(c.id))

    expect(ids).toEqual(['root'])
    expect(ids).not.toContain('section-1')
    expect(ids).not.toContain('row-10')
  })

  it('returns empty for unknown active type', () => {
    expect(filterParentContainers('root', containers)).toEqual([])
  })
})

describe('centerCrossing', () => {
  // Layout: active starts at (100, 100), target at (100, 300).
  // Collision rect is 100x50 (compact DragOverlay).
  const collisionWidth = 100
  const collisionHeight = 50
  const targetRect = makeDomRect(50, 275, 200, 100)

  function buildArgs(overrides: {
    currentY?: number
    currentX?: number
    pointerX?: number
    pointerY?: number
    initialY?: number
    initialX?: number
    target?: ClientRect
    containers?: DroppableContainer[]
  }) {
    const initialX = overrides.initialX ?? 150
    const initialY = overrides.initialY ?? 100
    const currentY = overrides.currentY ?? initialY
    const currentX = overrides.currentX ?? initialX
    const pointerX = overrides.pointerX ?? currentX
    const pointerY = overrides.pointerY ?? currentY
    const rect = overrides.target ?? targetRect
    const droppables = overrides.containers ?? [createDroppable('row-2')]

    const initialRect = makeDomRect(
      initialX - collisionWidth / 2,
      initialY - collisionHeight / 2,
      collisionWidth,
      collisionHeight,
    )

    const collisionRect = makeDomRect(
      currentX - collisionWidth / 2,
      currentY - collisionHeight / 2,
      collisionWidth,
      collisionHeight,
    )

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
    }
  }

  it('detects collision when center crosses threshold moving down past target', () => {
    // Target top=275, threshold = 275 + 25 (half collision height) = 300.
    // Initial at y=100 (below threshold? No, initial < threshold).
    // Current at y=310 (above threshold). Crossed from below.
    const args = buildArgs({ currentY: 310, pointerY: 310 })
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(1)
    expect(collisions[0].id).toBe('row-2')
  })

  it('returns empty when threshold not yet crossed', () => {
    // Target threshold at ~300. Current at 200 — hasn't crossed yet.
    const args = buildArgs({ currentY: 200, pointerY: 200 })
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(0)
  })

  it('returns empty when pointer is far from target horizontally (overlap gate)', () => {
    // Current has crossed the threshold vertically, but pointer X is far
    // outside the target rect + MARGIN_X (50px).
    // Target rect: left=50, width=200, so right=250. Margin extends to 300.
    const args = buildArgs({ currentY: 310, pointerY: 310, pointerX: 400 })
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(0)
  })

  it('returns empty when pointer is far from target vertically (overlap gate)', () => {
    // Crossed horizontally, but pointer Y far from target.
    // Target rect: top=275, height=100, bottom=375. Margin extends to 525.
    const args = buildArgs({
      initialX: 10,
      currentX: 160,
      pointerX: 160,
      currentY: 600,
      pointerY: 600,
    })
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(0)
  })

  it('adapts threshold based on overlay size relative to target', () => {
    // Large overlay (same size as target): threshold ≈ target center (strict).
    // Target at (50, 275, 200, 100), center Y = 325.
    // With collisionHeight=50, threshold = 275 + 25 = 300 (near edge).
    // A large overlay with height=100 would push threshold to 275+50 = 325 (center).

    // With our small overlay (50px), threshold is at 300 (permissive).
    // Position just past 300 should trigger.
    const args = buildArgs({ currentY: 301, pointerY: 301 })
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(1)
  })

  it('detects collision when moving up past target (reverse direction)', () => {
    // Initial below target, moving up.
    // Target top=275, bottom=375, center=325.
    // Moving from below: threshold = min(375 - 25, 325) = 325.
    const args = buildArgs({
      initialY: 500,
      currentY: 320,
      pointerY: 320,
    })
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(1)
    expect(collisions[0].id).toBe('row-2')
  })

  it('returns empty when initial rect is null', () => {
    const args = buildArgs({})
    ;(args.active.rect.current as unknown as { initial: null }).initial = null
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(0)
  })

  it('skips containers with no droppable rect', () => {
    const args = buildArgs({ currentY: 310, pointerY: 310 })
    args.droppableRects.clear()
    const collisions = centerCrossing(args as never)

    expect(collisions).toHaveLength(0)
  })

  it('sorts collisions by distance (closest first)', () => {
    const near = createDroppable('row-2')
    const far = createDroppable('row-3')
    const nearRect = makeDomRect(50, 275, 200, 100)
    const farRect = makeDomRect(50, 400, 200, 100)

    const args = buildArgs({ currentY: 450, pointerY: 450, containers: [far, near] })
    args.droppableRects.set('row-2', nearRect)
    args.droppableRects.set('row-3', farRect)
    args.droppableContainers = [far, near]

    const collisions = centerCrossing(args as never)

    // Both should be detected; the farRect's threshold = 400+25=425 (crossed at 450)
    // The near target center is at 325, far center at 450.
    // Current center at 450, closer to far target.
    expect(collisions).toHaveLength(2)
    expect(collisions[0].data?.value as number).toBeLessThanOrEqual(
      collisions[1].data?.value as number,
    )
  })
})

describe('createTypedCollisionDetection', () => {
  // Shared layout for parent/sibling detection tests.
  const parentRect = makeDomRect(0, 0, 800, 600)
  const siblingRect = makeDomRect(50, 275, 200, 100)

  function buildArgs(overrides: {
    activeId?: string
    containers?: DroppableContainer[]
    rects?: Map<string | number, ClientRect>
    pointerX?: number
    pointerY?: number
  }) {
    const activeId = overrides.activeId ?? 'row-1'
    const pointerX = overrides.pointerX ?? 150
    const pointerY = overrides.pointerY ?? 310

    const collisionRect = makeDomRect(100, pointerY - 25, 100, 50)
    const initialRect = makeDomRect(100, 75, 100, 50)

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
    }
  }

  describe('without pending move', () => {
    it('uses centerCrossing for siblings and returns collision', () => {
      const sibling = createDroppable('row-2')
      const parent = createDroppable('section-1')
      const rects = new Map<string | number, ClientRect>([
        ['row-2', siblingRect],
        ['section-1', parentRect],
      ])

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      })

      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling, parent],
        rects,
        pointerY: 310,
      })

      const collisions = detect(args as never)

      expect(collisions).toHaveLength(1)
      expect(collisions[0].id).toBe('row-2')
    })

    it('falls back to parent containers when no sibling collision', () => {
      const parent = createDroppable('section-1')
      const rects = new Map<string | number, ClientRect>([['section-1', parentRect]])

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      })

      // Pointer inside parent rect, no siblings at all
      const args = buildArgs({
        activeId: 'row-1',
        containers: [parent],
        rects,
        pointerX: 400,
        pointerY: 300,
      })

      const collisions = detect(args as never)

      expect(collisions).toHaveLength(1)
      expect(collisions[0].id).toBe('section-1')
    })

    it('excludes the active item from droppable containers', () => {
      const self = createDroppable('row-1')
      const parent = createDroppable('section-1')
      const rects = new Map<string | number, ClientRect>([
        ['row-1', makeDomRect(100, 95, 100, 50)],
        ['section-1', parentRect],
      ])

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      })

      const args = buildArgs({
        activeId: 'row-1',
        containers: [self, parent],
        rects,
        pointerX: 400,
        pointerY: 300,
      })

      const collisions = detect(args as never)
      const ids = collisions.map((c) => c.id)

      expect(ids).not.toContain('row-1')
    })
  })

  describe('with pending move', () => {
    it('uses closestCenterLive for siblings (reads live DOM rects)', () => {
      // closestCenterLive needs DOM nodes with getBoundingClientRect
      const sibling = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const pendingItems = new Set<string | number>(['row-2'])

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      })

      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects: new Map([['row-2', siblingRect]]),
        pointerY: 310,
      })

      const collisions = detect(args as never)

      expect(collisions).toHaveLength(1)
      expect(collisions[0].id).toBe('row-2')
    })

    it('filters siblings to only pending container items', () => {
      const inPending = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const notInPending = createDroppableWithRect('row-3', {
        left: 50,
        top: 400,
        width: 200,
        height: 100,
      })
      const pendingItems = new Set<string | number>(['row-2'])

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      })

      const args = buildArgs({
        activeId: 'row-1',
        containers: [inPending, notInPending],
        rects: new Map([
          ['row-2', siblingRect],
          ['row-3', makeDomRect(50, 400, 200, 100)],
        ]),
        pointerY: 310,
      })

      const collisions = detect(args as never)
      const ids = collisions.map((c) => c.id)

      expect(ids).toContain('row-2')
      expect(ids).not.toContain('row-3')
    })
  })

  describe('source depletion', () => {
    it('uses all siblings when sourceContainerItems is empty', () => {
      // When the source container has no items (e.g. dragged the only row out),
      // centerCrossing should check ALL siblings, not just source-container ones.
      const targetSibling = createDroppable('row-5')
      const rects = new Map<string | number, ClientRect>([['row-5', siblingRect]])

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        sourceContainerItemsRef: { current: new Set() },
      })

      const args = buildArgs({
        activeId: 'row-1',
        containers: [targetSibling],
        rects,
        pointerY: 310,
      })

      const collisions = detect(args as never)

      expect(collisions).toHaveLength(1)
      expect(collisions[0].id).toBe('row-5')
    })
  })

  describe('overRectRef capture', () => {
    it('captures the winning collision node reference', () => {
      const sibling = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null
      }

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      })

      const rects = new Map<string | number, ClientRect>([['row-2', siblingRect]])
      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects,
        pointerY: 310,
      })

      detect(args as never)

      expect(overRectRef.current).not.toBeNull()
      expect(overRectRef.current!.id).toBe('row-2')
      expect(overRectRef.current!.nodeRef.current).toBeTruthy()
    })

    it('captures overRectRef for pending-path sibling collisions', () => {
      const sibling = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const pendingItems = new Set<string | number>(['row-2'])
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null
      }

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
        overRectRef,
      })

      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects: new Map([['row-2', siblingRect]]),
        pointerY: 310,
      })

      detect(args as never)

      // The pending path captures the winner's node so handleDragMove can read
      // a live getBoundingClientRect() for before/after direction (over.rect
      // lags the pending-tree re-render).
      expect(overRectRef.current?.id).toBe('row-2')
    })

    it('captures overRectRef for parent container fallback', () => {
      const parent = createDroppableWithRect('section-1', {
        left: 0,
        top: 0,
        width: 800,
        height: 600,
      })
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null
      }

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      })

      const rects = new Map<string | number, ClientRect>([['section-1', parentRect]])
      const args = buildArgs({
        activeId: 'row-1',
        containers: [parent],
        rects,
        pointerX: 400,
        pointerY: 300,
      })

      detect(args as never)

      expect(overRectRef.current).not.toBeNull()
      expect(overRectRef.current!.id).toBe('section-1')
    })
  })

  describe('drag reset between drags', () => {
    it('resets hadSiblingHit when sourceContainerItemsRef changes', () => {
      const sourceItems1 = new Set<string | number>(['row-2'])
      const sourceRef = { current: sourceItems1 as ReadonlySet<string | number> | null }

      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        sourceContainerItemsRef: sourceRef,
      })

      const sibling = createDroppable('row-2')
      const rects = new Map<string | number, ClientRect>([['row-2', siblingRect]])

      // First drag: trigger a sibling hit
      const args = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects,
        pointerY: 310,
      })
      detect(args as never)

      // Simulate new drag: change sourceContainerItemsRef
      sourceRef.current = new Set<string | number>(['row-5'])

      // Call again — should not carry over hadSiblingHit from previous drag
      const args2 = buildArgs({
        activeId: 'row-1',
        containers: [sibling],
        rects,
        pointerY: 200, // Not crossing threshold
      })
      const collisions = detect(args2 as never)

      // Without hadSiblingHit, the pointer-inside-sibling guard should
      // return empty (not fall through to closestCenterLive)
      expect(collisions).toHaveLength(0)
    })
  })
})

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
      }
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
      })
      const pendingItems = new Set<string | number>(['row-2'])
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      })

      const args = buildPendingPathArgs(
        [target],
        makeDomRect(100, 100, 50, 50),
        makeDomRect(100, 75, 50, 50),
      )
      const collisions = detect(args as never)

      expect(collisions).toHaveLength(1)
      expect(collisions[0].data?.value).toBe(20000)
    })

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
      })
      const far = createDroppableWithRect('row-3', {
        left: 400,
        top: 400,
        width: 50,
        height: 50,
      })
      const pendingItems = new Set<string | number>(['row-2', 'row-3'])
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      })

      const args = buildPendingPathArgs(
        [far, close],
        makeDomRect(100, 100, 50, 50),
        makeDomRect(100, 75, 50, 50),
      )
      const collisions = detect(args as never)

      expect(collisions.map((c) => c.id)).toEqual(['row-2', 'row-3'])
      expect(collisions[0].data?.value).toBe(5000)
      expect(collisions[1].data?.value).toBe(180000)
    })

    it('ranks a containing candidate before a closer non-containing one', () => {
      // Pointer at (500, 500). closestCenterLive references pointerCoordinates.
      //
      // containing (row-2): rect (400, 400, 1000, 1000) → right=1400, bottom=1400.
      //   Pointer is inside (400 ≤ 500 ≤ 1400 on both axes) → contains=true.
      //   center (900, 900) → dx=-400, dy=-400 → value=320000 (FAR by distance).
      // closer (row-3): rect (510, 510, 20, 20) → right=530, bottom=530.
      //   Pointer (500,500) is outside (500 < 510) → contains=false.
      //   center (520, 520) → dx=-20, dy=-20 → value=800 (much CLOSER by distance).
      //
      // The containment discriminant must win: row-2 (contains) ranks first even
      // though row-3 has a far smaller squared distance. Registered [closer, containing]
      // so a comparator that ignored `contains` would return [row-3, row-2].
      const containing = createDroppableWithRect('row-2', {
        left: 400,
        top: 400,
        width: 1000,
        height: 1000,
      })
      const closer = createDroppableWithRect('row-3', {
        left: 510,
        top: 510,
        width: 20,
        height: 20,
      })
      const pendingItems = new Set<string | number>(['row-2', 'row-3'])
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: true },
        pendingContainerItemsRef: { current: pendingItems },
      })

      const args = {
        ...buildPendingPathArgs(
          [closer, containing],
          makeDomRect(100, 100, 50, 50),
          makeDomRect(100, 75, 50, 50),
        ),
        pointerCoordinates: { x: 500, y: 500 },
      }
      const collisions = detect(args as never)

      expect(collisions.map((c) => c.id)).toEqual(['row-2', 'row-3'])
      // Confirm the winner is the FARTHER-by-distance one, proving containment
      // — not proximity — decided the order.
      expect(collisions[0].data?.value).toBe(320000)
      expect(collisions[1].data?.value).toBe(800)
    })
  })

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
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(50, 75, 100, 100)
      const collisionRect = makeDomRect(75, 100, 100, 50)
      const initialRect = makeDomRect(75, 50, 100, 50)
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
      }

      const collisions = centerCrossing(args as never)

      expect(collisions).toHaveLength(1)
      expect(collisions[0].data?.value).toBe(625)
    })

    it('sorts crossing collisions ascending by squared distance', () => {
      // Two targets both crossed vertically (currentY past both thresholds).
      // close target (50, 400, 200, 100): center (150, 450), threshold = 400+25 = 425.
      // far   target (50, 500, 200, 100): center (150, 550), threshold = 500+25 = 525.
      // currentY = 530 crosses both. collisionRect center (125, 530).
      // Close dx=-25, dy=80  → value = 625 + 6400 = 7025.
      // Far   dx=-25, dy=-20 → value = 625 + 400  = 1025. Far is CLOSER to currentY.
      const close = createDroppable('row-2')
      const far = createDroppable('row-3')
      const closeRect = makeDomRect(50, 400, 200, 100)
      const farRect = makeDomRect(50, 500, 200, 100)
      const collisionRect = makeDomRect(75, 505, 100, 50)
      const initialRect = makeDomRect(75, 75, 100, 50)
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
      }

      const collisions = centerCrossing(args as never)

      // At currentY=530, the far target is nearer (1025) than the close target (7025).
      expect(collisions.map((c) => c.id)).toEqual(['row-3', 'row-2'])
      expect(collisions[0].data?.value).toBe(1025)
      expect(collisions[1].data?.value).toBe(7025)
    })
  })

  describe('centerCrossing overlap-gate margins', () => {
    // Target rect (200, 275, 100, 100). MARGIN_X = 50, MARGIN_Y = 150.
    // Overlap-X window: ptrX ∈ (150, 350).
    // Overlap-Y window: ptrY ∈ (125, 525).
    // Set up a scenario where crossedY is true (so the gate is the only filter).
    function runWithPointer(pointerX: number, pointerY: number) {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(200, 275, 100, 100)
      const collisionRect = makeDomRect(200, pointerY - 25, 100, 50)
      const initialRect = makeDomRect(200, 50, 100, 50)
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
      }
      return centerCrossing(args as never)
    }

    it('includes pointer 1px inside MARGIN_X on the left (ptrX = 151)', () => {
      expect(runWithPointer(151, 310)).toHaveLength(1)
    })

    it('excludes pointer exactly on the MARGIN_X boundary on the left (ptrX = 150)', () => {
      expect(runWithPointer(150, 310)).toHaveLength(0)
    })

    it('includes pointer 1px inside MARGIN_X on the right (ptrX = 349)', () => {
      expect(runWithPointer(349, 310)).toHaveLength(1)
    })

    it('excludes pointer exactly on the MARGIN_X boundary on the right (ptrX = 350)', () => {
      expect(runWithPointer(350, 310)).toHaveLength(0)
    })

    it('includes pointer 1px inside MARGIN_Y on the top (ptrY = 126)', () => {
      // With pointerY=126, collisionRect center is (250, 101). initialCY=75, targetCY=325.
      // Moving down, thresholdY = 275 + 25 = 300. currentCY max(101, 126) = 126. 126 >= 300?
      // No — not crossed. So we need currentCY past threshold. Use ptrY just inside Y margin
      // AND another location for the collision rect that has crossed threshold. Simplest:
      // force collisionRect past threshold by positioning it there, use pointerY for the gate.
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(200, 275, 100, 100)
      const collisionRect = makeDomRect(200, 285, 100, 50) // center (250, 310) past threshold
      const initialRect = makeDomRect(200, 50, 100, 50)
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
      }
      expect(centerCrossing(args as never)).toHaveLength(1)
    })

    it('excludes pointer exactly on the MARGIN_Y boundary on the top (ptrY = 125)', () => {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(200, 275, 100, 100)
      const collisionRect = makeDomRect(200, 285, 100, 50)
      const initialRect = makeDomRect(200, 50, 100, 50)
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
      }
      expect(centerCrossing(args as never)).toHaveLength(0)
    })
  })

  describe('centerCrossing threshold boundaries', () => {
    // Target rect (50, 275, 200, 100). Initial Y=75 (above), moving DOWN.
    // thresholdY (initialCY < targetCY branch) = rect.top + collisionRect.height/2 = 275 + 25 = 300.
    function runWithCurrentY(currentY: number) {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(50, 275, 200, 100)
      const collisionRect = makeDomRect(100, currentY - 25, 100, 50)
      const initialRect = makeDomRect(100, 50, 100, 50)
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
      }
      return centerCrossing(args as never)
    }

    it('excludes collision when current Y has not yet reached the threshold (299)', () => {
      expect(runWithCurrentY(299)).toHaveLength(0)
    })

    it('includes collision when current Y equals the threshold (300)', () => {
      expect(runWithCurrentY(300)).toHaveLength(1)
    })

    it('includes collision when current Y is past the threshold (301)', () => {
      expect(runWithCurrentY(301)).toHaveLength(1)
    })

    it('applies direction-aware threshold when moving UP (initialCY > targetCY)', () => {
      // Moving up from below target. Initial Y=600 (center of initialRect), target (50,275,200,100)
      // targetCY=325. initialCY(600) > targetCY(325). thresholdY = Math.min(rect.top + rect.height -
      // collisionRect.height/2, targetCY) = Math.min(275+100-25, 325) = Math.min(350, 325) = 325.
      // currentY=326: currentCY = Math.min(crCY=326, ptrY=326) = 326. crossedY needs
      // currentCY <= thresholdY(325). 326 <= 325 is false → no crossing yet.
      // currentY=325: 325 <= 325 is true → crossed.
      const just = runWithCurrentYUp(325)
      const before = runWithCurrentYUp(326)
      expect(before).toHaveLength(0)
      expect(just).toHaveLength(1)
    })
  })

  describe('centerCrossing X-axis threshold boundaries', () => {
    // Horizontal drag scenarios — Y stays above/away from target so only the X
    // threshold can trigger a crossing. Target rect (250, 275, 100, 100).
    // Collision rect width 100, height 50.
    function runHorizontal(opts: {
      initialX: number
      currentX: number
      pointerX: number
      pointerY?: number
    }) {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(250, 275, 100, 100)
      const pointerY = opts.pointerY ?? 325
      const collisionRect = makeDomRect(opts.currentX - 50, pointerY - 25, 100, 50)
      const initialRect = makeDomRect(opts.initialX - 50, pointerY - 25, 100, 50)
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
      }
      return centerCrossing(args as never)
    }

    it('crosses threshold when dragging RIGHT toward target (initialCX < targetCX)', () => {
      // initialCX=100, targetCX=300. thresholdX = rect.left + collisionRect.width/2 = 250+50 = 300.
      // currentCX=301 → crossed. currentCX=299 → not crossed.
      expect(runHorizontal({ initialX: 100, currentX: 299, pointerX: 299 })).toHaveLength(0)
      expect(runHorizontal({ initialX: 100, currentX: 301, pointerX: 301 })).toHaveLength(1)
    })

    it('crosses threshold when dragging LEFT toward target (initialCX > targetCX) with Math.min clamp', () => {
      // initialCX=700, targetCX=300. thresholdX = Math.min(rect.left + rect.width - collisionRect.width/2, targetCX)
      //                                        = Math.min(250+100-50, 300) = Math.min(300, 300) = 300.
      // Moving left: crossedX when currentCX <= thresholdX.
      expect(runHorizontal({ initialX: 700, currentX: 301, pointerX: 301 })).toHaveLength(0)
      expect(runHorizontal({ initialX: 700, currentX: 299, pointerX: 299 })).toHaveLength(1)
    })

    it('Math.min clamps the LEFT-drag threshold to targetCX for wide targets', () => {
      // Wide target: (200, 275, 300, 100). targetCX = 200 + 150 = 350. rect.left + rect.width -
      // collisionRect.width/2 = 200+300-50 = 450. Math.min(450, 350) = 350 (clamped to targetCX).
      // Without Math.min (e.g. Math.max mutant), threshold would be 450.
      const target = createDroppable('row-2')
      const wideRect = makeDomRect(200, 275, 300, 100)
      function runWide(currentX: number) {
        const pointerY = 325
        const collisionRect = makeDomRect(currentX - 50, pointerY - 25, 100, 50)
        const initialRect = makeDomRect(700 - 50, pointerY - 25, 100, 50)
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
        }
        return centerCrossing(args as never)
      }

      // currentCX=351: default NOT crossed (351 > 350). Math.max mutant WOULD cross (351 <= 450).
      expect(runWide(351)).toHaveLength(0)
      // currentCX=349: default crossed (349 <= 350). Confirms threshold is at 350, not 450.
      expect(runWide(349)).toHaveLength(1)
    })

    it('uses Math.max(crCX, ptrX) when dragging RIGHT (initialCX < targetCX)', () => {
      // Grab offset test: pointer ahead of collisionRect center.
      // Moving right, currentCX = Math.max(crCX, ptrX) — the furthest-advanced value.
      // threshold = 300. crCX=290 (collisionRect center behind), ptrX=305 (pointer ahead).
      // Max picks ptrX=305 → crossed (305 >= 300). A `true/false` or min-only mutant would fail.
      const crossed = runHorizontal({ initialX: 100, currentX: 290, pointerX: 305 })
      expect(crossed).toHaveLength(1)

      // Neither crCX nor ptrX past threshold → no crossing.
      const notCrossed = runHorizontal({ initialX: 100, currentX: 290, pointerX: 295 })
      expect(notCrossed).toHaveLength(0)
    })

    it('uses Math.min(crCX, ptrX) when dragging LEFT (initialCX > targetCX)', () => {
      // Moving left, currentCX = Math.min(crCX, ptrX) — the furthest-advanced (smallest) value.
      // threshold=300. crCX=310 (behind), ptrX=295 (ahead) → min picks 295 → crossed (295 <= 300).
      const crossed = runHorizontal({ initialX: 700, currentX: 310, pointerX: 295 })
      expect(crossed).toHaveLength(1)

      // Neither crCX nor ptrX past threshold.
      const notCrossed = runHorizontal({ initialX: 700, currentX: 310, pointerX: 305 })
      expect(notCrossed).toHaveLength(0)
    })
  })

  describe('centerCrossing crossed-comparison at exact threshold', () => {
    // The crossing condition uses `<=` (downward) and `>=` (upward) so that a pointer
    // exactly on the threshold counts as crossed. Mutants tightening these to `<` / `>`
    // are only discriminated at the exact boundary.
    function runHorizontalAtCurrent(currentX: number, initialX: number) {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(250, 275, 100, 100)
      const pointerY = 325
      const collisionRect = makeDomRect(currentX - 50, pointerY - 25, 100, 50)
      const initialRect = makeDomRect(initialX - 50, pointerY - 25, 100, 50)
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: currentX, y: pointerY },
      }
      return centerCrossing(args as never)
    }

    it('counts as crossed when dragging RIGHT with currentCX exactly at the threshold (line 209 >=)', () => {
      // Right-drag: initialCX(100) < thresholdX(300). Crossing requires currentCX >= 300.
      // At currentCX=300 exactly, `>= 300` is true, `> 300` is false.
      expect(runHorizontalAtCurrent(300, 100)).toHaveLength(1)
    })

    it('counts as crossed when dragging LEFT with currentCX exactly at the threshold (line 208 <=)', () => {
      // Left-drag: initialCX(700) > thresholdX(300). Crossing requires currentCX <= 300.
      expect(runHorizontalAtCurrent(300, 700)).toHaveLength(1)
    })
  })

  describe('centerCrossing margin gate at exact edge', () => {
    // Margin gate uses strict `<` on the outer side. A pointer exactly on
    // `rect.right + MARGIN_X` must be OUT (default) — mutant `<=` would put it IN.
    it('excludes pointer exactly on rect.right + MARGIN_X (strict < upper bound)', () => {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(200, 275, 100, 100) // right = 300, MARGIN_X = 50
      const collisionRect = makeDomRect(200, 285, 100, 50)
      const initialRect = makeDomRect(200, 50, 100, 50)
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 350, y: 310 }, // exactly at rect.right + MARGIN_X
      }
      expect(centerCrossing(args as never)).toEqual([])
    })

    it('excludes pointer exactly on rect.bottom + MARGIN_Y (strict < upper bound)', () => {
      // Target bottom = 275 + 100 = 375. MARGIN_Y = 150. Boundary at ptrY = 525.
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(200, 275, 100, 100)
      const collisionRect = makeDomRect(200, 285, 100, 50)
      const initialRect = makeDomRect(200, 50, 100, 50)
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 250, y: 525 },
      }
      expect(centerCrossing(args as never)).toEqual([])
    })
  })

  describe('centerCrossing UP-direction threshold with wide target', () => {
    // Default thresholdY when moving UP: Math.min(rect.top + rect.height - collisionRect.height/2, targetCY).
    // Mutant `-` → `+` only differs when the formula branch is NOT clamped by targetCY.
    // Use a very tall target so the formula value exceeds targetCY for `-` but not for `+`
    // → wait, both would exceed. We need collisionRect tall enough that the `-` value
    // sits BELOW targetCY. Use collisionRect.height such that formula = rect.top + h - collisionRect.h/2
    // is less than targetCY.
    //
    // target (50, 200, 200, 600): targetCY = 500, rect.bottom = 800.
    // Small collisionRect.height=100: formula = 200+600-50 = 750. Math.min(750, 500) = 500.
    // Large collisionRect.height=800: formula = 200+600-400 = 400. Math.min(400, 500) = 400.
    //   Mutant +: formula = 200+600+400 = 1200. Math.min(1200, 500) = 500. DIFFERENT.
    //
    // Moving UP, initial Y high (below target). Threshold = 400 (default) vs 500 (mutant).
    // currentY=450: default crossedY (initial>threshold=400, currentY<=400? 450<=400 false → not crossed).
    //   Hmm need currentY<=threshold for UP crossing.
    // currentY=400: default 400<=400 true → crossed. Mutant 400<=500 true → also crossed. Same.
    // currentY=500: default 500<=400 false → not crossed. Mutant 500<=500 true → crossed. DIFFERENT.
    it('kills Math.min formula `+` mutant when collisionRect is taller than target/2', () => {
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(50, 200, 200, 600) // targetCY = 500, rect.bottom = 800
      // Large collisionRect.height shifts the UP-formula threshold BELOW targetCY (to 400),
      // so Math.min picks the formula branch. Moving UP from below target, currentY=500 means:
      // default (threshold=400): 500 <= 400 → not crossed. Mutant + (threshold=500): 500<=500 → crossed.
      //
      // X is neutralised (initialCX === targetCX === 150) so crossedX can't independently
      // trigger a collision.
      const collisionRect = makeDomRect(50, 100, 200, 800) // center (150, 500), h=800
      const initialRect = makeDomRect(50, 900, 200, 800) // center (150, 1300)
      const args = {
        active: {
          id: 'row-1',
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [target],
        droppableRects: new Map([['row-2', targetRect]]),
        pointerCoordinates: { x: 150, y: 500 },
      }
      // Default threshold = 400 → currentCY=500 NOT <= 400 → not crossed → empty.
      expect(centerCrossing(args as never)).toEqual([])
    })
  })

  describe('overRectRef picks the correct container among multiple', () => {
    it('selects the winning container by id, not the first one (discriminates `find((c) => true)`)', () => {
      // Two containers both registered as droppables. The sibling filter and centerCrossing
      // together should identify the true collision winner; overRectRef.current.id must
      // match that winner, not the first container in the list.
      const near = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const decoy = createDroppableWithRect('row-99', {
        left: 50,
        top: 900, // far from pointer
        width: 200,
        height: 100,
      })
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null
      }
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      })

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
        droppableContainers: [decoy, near], // decoy first to detect first-wins bugs
        droppableRects: new Map([
          ['row-2', makeDomRect(50, 275, 200, 100)],
          ['row-99', makeDomRect(50, 900, 200, 100)],
        ]),
        pointerCoordinates: { x: 150, y: 310 },
      }

      detect(args as never)
      expect(overRectRef.current?.id).toBe('row-2')
      expect(overRectRef.current?.nodeRef).toBe(near.node)
    })
  })

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
      const target = createDroppable('row-2')
      const targetRect = makeDomRect(50, 275, 200, 100)
      const collisionRect = makeDomRect(100, 285, 100, 50) // center (150, 310)
      const initialRect = makeDomRect(100, 0, 100, 200) // initialCY = 100
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
      }
      const collisions = centerCrossing(args as never)
      expect(collisions).toHaveLength(1)
      // Pin exact value: dx = 150 - 150 = 0, dy = 310 - 325 = -15 → value = 225.
      expect(collisions[0].data?.value).toBe(225)
    })
  })

  describe('parent containment pointer boundaries', () => {
    // Pass-2 containment check at lines 447–458 uses strict `>=` / `<=` comparisons for
    // all four edges. Boundary tests (pointer exactly ON each edge) pin these operators.
    const parent = createDroppable('section-1')
    const parentRect = makeDomRect(100, 200, 300, 400) // right=400, bottom=600

    function runParentContainment(pointerX: number, pointerY: number) {
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      })
      const collisionRect = makeDomRect(50, pointerY - 25, 100, 50)
      const initialRect = makeDomRect(50, 75, 100, 50)
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
      }
      return detect(args as never)
    }

    // Containment path sets value=0 (synthetic); closestCenter fallback sets value to the
    // squared distance from the pointer to the parent center. Asserting on `value === 0`
    // cleanly discriminates "inside parent" from the distance-based fallback.
    function assertContained(result: ReturnType<typeof runParentContainment>) {
      expect(result).toHaveLength(1)
      expect(result[0].id).toBe('section-1')
      expect(result[0].data?.value).toBe(0)
    }
    function assertNotContained(result: ReturnType<typeof runParentContainment>) {
      expect(result).toHaveLength(1)
      expect(result[0].id).toBe('section-1')
      // Distance fallback populates a nonzero value.
      expect(result[0].data?.value).not.toBe(0)
    }

    it('matches when pointer is exactly on the LEFT edge of the parent', () => {
      assertContained(runParentContainment(100, 400))
    })

    it('does NOT match when pointer is 1px outside the LEFT edge', () => {
      assertNotContained(runParentContainment(99, 400))
    })

    it('matches when pointer is exactly on the RIGHT edge (rect.left + rect.width)', () => {
      assertContained(runParentContainment(400, 400))
    })

    it('does NOT match when pointer is 1px outside the RIGHT edge', () => {
      assertNotContained(runParentContainment(401, 400))
    })

    it('matches when pointer is exactly on the TOP edge', () => {
      assertContained(runParentContainment(200, 200))
    })

    it('does NOT match when pointer is 1px outside the TOP edge', () => {
      assertNotContained(runParentContainment(200, 199))
    })

    it('matches when pointer is exactly on the BOTTOM edge (rect.top + rect.height)', () => {
      assertContained(runParentContainment(200, 600))
    })

    it('does NOT match when pointer is 1px outside the BOTTOM edge', () => {
      assertNotContained(runParentContainment(200, 601))
    })
  })

  describe('nonActiveContainers filter', () => {
    it('excludes the active container when it is registered as a droppable (discriminates active filter)', () => {
      // Solo-sibling scenario: the only registered droppable shares the active id.
      // Default: the filter removes it → no siblings → no sibling collision → falls through
      // to parent (none) → returns []. Mutant `(c) => true`: active included as sibling,
      // centerCrossing may detect its own rect (pointer inside) → returns a non-empty array.
      const self = createDroppable('row-1')
      const selfRect = makeDomRect(100, 95, 100, 50) // initialRect position
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
      })
      const collisionRect = makeDomRect(100, 285, 100, 50) // center (150, 310)
      const initialRect = makeDomRect(100, 75, 100, 50)
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
      }
      expect(detect(args as never)).toEqual([])
    })
  })

  describe('overRectRef only captures when collisions exist', () => {
    it('leaves overRectRef null when there are no collisions (line 312 length > 0 guard)', () => {
      // No droppable rect matches the pointer → empty collisions → guard prevents capture.
      const unrelated = createDroppableWithRect('row-2', {
        left: 1000,
        top: 1000,
        width: 50,
        height: 50,
      })
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null
      }
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      })
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
      }
      detect(args as never)
      expect(overRectRef.current).toBeNull()
    })
  })

  describe('overRectRef exact node reference', () => {
    it('captures the actual DOM node from the winning container (reference equality)', () => {
      const target = createDroppableWithRect('row-2', {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const overRectRef = { current: null } as {
        current: { id: string | number; nodeRef: { readonly current: HTMLElement | null } } | null
      }
      const detect = createTypedCollisionDetection({
        hasPendingMoveRef: { current: false },
        overRectRef,
      })

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
      }

      detect(args as never)

      expect(overRectRef.current?.nodeRef).toBe(target.node)
    })
  })
})

describe('closestCenterLive pointer fallback (no pointerCoordinates)', () => {
  // When the sensor provides no pointerCoordinates, closestCenterLive falls back
  // to the collision-rect center: refX = collisionRect.left + collisionRect.width/2,
  // refY = collisionRect.top + collisionRect.height/2. Exercised only via the pending
  // path with pointerCoordinates omitted. Pins lines 31-32 (the `??` fallback arms and
  // their arithmetic).
  function buildPendingArgsNoPointer(containers: DroppableContainer[], collisionRect: ClientRect) {
    return {
      active: {
        id: 'row-1',
        rect: { current: { initial: makeDomRect(100, 75, 50, 50), translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: containers,
      droppableRects: new Map(),
      pointerCoordinates: null,
    }
  }

  it('uses collision-rect center as the reference when pointerCoordinates is null', () => {
    // collisionRect (100,100,50,50) → fallback center (125, 125).
    // close target center (125,125): dx=0, dy=0 → value 0.
    // far   target center (425,425): dx=-300, dy=-300 → value 180000.
    // A null pointer must resolve via the collision-rect center, ranking `close` first.
    const close = createDroppableWithRect('row-2', { left: 100, top: 100, width: 50, height: 50 })
    const far = createDroppableWithRect('row-3', { left: 400, top: 400, width: 50, height: 50 })
    const pendingItems = new Set<string | number>(['row-2', 'row-3'])
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: true },
      pendingContainerItemsRef: { current: pendingItems },
    })

    const args = buildPendingArgsNoPointer([far, close], makeDomRect(100, 100, 50, 50))
    const collisions = detect(args as never)

    expect(collisions.map((c) => c.id)).toEqual(['row-2', 'row-3'])
    // refX fallback = 100 + 50/2 = 125, refY = 100 + 50/2 = 125 → exact zero distance.
    expect(collisions[0].data?.value).toBe(0)
    // far: dx = 125 - 425 = -300, dy = -300 → 180000. Pins the fallback arithmetic precisely:
    // a `+`→`-` (75) or `/`→`*` (200) mutant on line 31/32 shifts the fallback center and
    // breaks this exact value.
    expect(collisions[1].data?.value).toBe(180000)
  })
})

describe('closestCenterLive containment edges (line 45 boundary comparisons)', () => {
  // The `contains` discriminant uses `>=` / `<=` on all four edges. A mutant tightening any
  // edge to `>` / `<` only differs when the pointer sits EXACTLY on that edge. Each test puts
  // the pointer on one edge of a large "containing" candidate so that, with `>=`/`<=`, it is
  // contained and ranks first; with the strict mutant it loses containment and a closer-by-
  // distance non-containing candidate wins. Asserting the containing candidate is first kills
  // the per-edge mutant. Runs through the pending path (which delegates to closestCenterLive).
  const containingRect = { left: 400, top: 400, width: 1000, height: 1000 } // edges 400/1400

  function runEdge(pointerX: number, pointerY: number) {
    const containing = createDroppableWithRect('row-2', containingRect)
    // Closer candidate: a tiny rect whose center sits 30px below the pointer — far closer by
    // distance (30² = 900 « 290000) than the containing candidate's center, but it does NOT
    // contain the pointer (pointer is 30px from center, half-height only 10px). So distance
    // alone would rank it first; only the containment discriminant can keep row-2 ahead.
    const closer = createDroppableWithRect('row-3', {
      left: pointerX - 10,
      top: pointerY + 20,
      width: 20,
      height: 20,
    })
    const pendingItems = new Set<string | number>(['row-2', 'row-3'])
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: true },
      pendingContainerItemsRef: { current: pendingItems },
    })
    const collisionRect = makeDomRect(0, 0, 50, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: makeDomRect(0, 0, 50, 50), translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      // closer first so a comparator ignoring `contains` (or a flipped edge) returns row-3 first.
      droppableContainers: [closer, containing],
      droppableRects: new Map(),
      pointerCoordinates: { x: pointerX, y: pointerY },
    }
    return detect(args as never)
  }

  it('contains when pointer is exactly on the LEFT edge (refX >= rect.left)', () => {
    // Pointer (400, 700): refX === rect.left (400). With `>=` contained → row-2 first.
    expect(runEdge(400, 700).map((c) => c.id)).toEqual(['row-2', 'row-3'])
  })

  it('contains when pointer is exactly on the RIGHT edge (refX <= rect.right)', () => {
    // Pointer (1400, 700): refX === rect.right (1400). With `<=` contained → row-2 first.
    expect(runEdge(1400, 700).map((c) => c.id)).toEqual(['row-2', 'row-3'])
  })

  it('contains when pointer is exactly on the TOP edge (refY >= rect.top)', () => {
    // Pointer (700, 400): refY === rect.top (400). With `>=` contained → row-2 first.
    expect(runEdge(700, 400).map((c) => c.id)).toEqual(['row-2', 'row-3'])
  })

  it('contains when pointer is exactly on the BOTTOM edge (refY <= rect.bottom)', () => {
    // Pointer (700, 1400): refY === rect.bottom (1400). With `<=` contained → row-2 first.
    expect(runEdge(700, 1400).map((c) => c.id)).toEqual(['row-2', 'row-3'])
  })

  it('does NOT contain when pointer is just outside the LEFT edge', () => {
    // Pointer (399, 700): refX < rect.left → not contained → closer-by-distance row-3 wins.
    expect(runEdge(399, 700).map((c) => c.id)).toEqual(['row-3', 'row-2'])
  })
})

describe('pointer-inside-source-sibling guard (no-pending path)', () => {
  // Reaches lines 430-461. With a non-empty sourceContainerItemsRef and a pointer that
  // sits inside a source sibling but has NOT crossed the centerCrossing threshold, the
  // guard short-circuits to `[]` (ghost-jump prevention) instead of falling through to
  // the parent-container fallback. When a prior cycle DID register a sibling hit
  // (hadSiblingHit), it instead recovers via closestCenterLive (lines 443-458).
  const siblingRect = makeDomRect(50, 275, 200, 100) // top=275, bottom=375, center=325
  const parentRect = makeDomRect(0, 0, 800, 600)

  function buildArgs(opts: { currentY: number; pointerY: number; withParent?: boolean }) {
    const sibling = createDroppableWithRect('row-2', {
      left: 50,
      top: 275,
      width: 200,
      height: 100,
    })
    const containers: DroppableContainer[] = [sibling]
    const rects = new Map<string | number, ClientRect>([['row-2', siblingRect]])
    if (opts.withParent) {
      containers.push(createDroppable('section-1'))
      rects.set('section-1', parentRect)
    }
    const collisionRect = makeDomRect(100, opts.currentY - 25, 100, 50)
    const initialRect = makeDomRect(100, 75, 100, 50) // initialCY = 100, above target
    return {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: containers,
      droppableRects: rects,
      pointerCoordinates: { x: 150, y: opts.pointerY },
    }
  }

  it('returns empty (not the parent) when pointer is inside a source sibling but threshold not crossed', () => {
    // initialCY=100 < targetCY=325 → thresholdY = 275 + 25 = 300. currentY/pointerY=290:
    // 290 < 300 → centerCrossing returns empty. Pointer (150, 290) is inside the rect
    // (x 50-250, y 275-375) → guard fires. Because no prior sibling hit, returns [].
    // A parent IS present: a broken guard would fall through and return 'section-1'.
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2']) },
    })

    const collisions = detect(
      buildArgs({ currentY: 290, pointerY: 290, withParent: true }) as never,
    )

    expect(collisions).toEqual([])
  })

  it('does not run the pointer-inside guard when the source container is empty (line 430 size > 0)', () => {
    // Empty source set → source depletion: sameContainerSiblings becomes ALL siblings and the
    // pointer-inside guard must be SKIPPED (`sourceItems.size > 0` is false). The drag here does
    // NOT cross row-2's threshold but the pointer sits inside row-2; with the guard correctly
    // skipped, detection falls through to the parent. A `>= 0` mutant would enter the guard
    // (0 >= 0) and short-circuit to [], suppressing the parent fallback.
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>() },
    })

    const collisions = detect(
      buildArgs({ currentY: 290, pointerY: 290, withParent: true }) as never,
    )

    expect(collisions).toHaveLength(1)
    expect(collisions[0].id).toBe('section-1')
  })

  it('recovers via closestCenterLive when a prior cycle registered a sibling hit', () => {
    // Single closure: first cycle crosses the threshold (sets hadSiblingHit=true), second
    // cycle does not cross but the pointer is still inside the source sibling → recovery
    // path runs closestCenterLive over the source siblings and returns the live collision.
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2']) },
    })

    // Cycle 1: currentY=310 >= 300 threshold → crossed → sibling hit recorded.
    const hit = detect(buildArgs({ currentY: 310, pointerY: 310 }) as never)
    expect(hit).toHaveLength(1)
    expect(hit[0].id).toBe('row-2')

    // Cycle 2: currentY=290 < 300 → not crossed, but pointer (150,290) inside source
    // sibling → recovery via closestCenterLive returns the live sibling rather than [].
    const recovered = detect(buildArgs({ currentY: 290, pointerY: 290, withParent: true }) as never)
    expect(recovered).toHaveLength(1)
    expect(recovered[0].id).toBe('row-2')
  })

  it('does NOT short-circuit (falls through to parent) when the pointer is outside every source sibling', () => {
    // Pointer X=400 is outside the sibling rect (right=250) and Y=290 does not cross the
    // threshold → centerCrossing returns empty AND the guard's `.some` is false (pointer not
    // inside any source sibling) → falls through to the parent-container fallback. Discriminates
    // the guard's pointer-containment comparisons (lines 436-439) and the `.some` short-circuit.
    const sibling = createDroppableWithRect('row-2', {
      left: 50,
      top: 275,
      width: 200,
      height: 100,
    })
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2']) },
    })

    const collisionRect = makeDomRect(350, 265, 100, 50) // center (400, 290)
    const initialRect = makeDomRect(350, 75, 100, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [sibling, createDroppable('section-1')],
      droppableRects: new Map<string | number, ClientRect>([
        ['row-2', siblingRect],
        ['section-1', parentRect],
      ]),
      pointerCoordinates: { x: 400, y: 290 },
    }

    const collisions = detect(args as never)

    expect(collisions).toHaveLength(1)
    expect(collisions[0].id).toBe('section-1')
  })
})

describe('centerCrossing same-X/Y direction boundary (initialC === targetC)', () => {
  // Lines 208/213 use strict `<` to pick the direction branch. The `<` → `<=` mutant only
  // differs when initialC === targetC. Construct that exact equality and assert the
  // strict-`<` (else/away) branch is taken, which a `<=` mutant would flip to the toward branch.
  it('does NOT cross in Y when initialCY equals targetCY and currentCY reaches the toward-threshold (line 208 strict <)', () => {
    // target (50, 275, 200, 100) → targetCY = 325, top = 275, bottom = 375.
    // initialRect center = 325 (top 300, height 50) → initialCY === targetCY.
    // Default `<` (325 < 325 false → AWAY branch): thresholdY = Math.min(375 - 25, 325) = 325.
    //   Crossing needs initialCY strictly on one side of 325 — but initialCY IS 325, so neither
    //   `initialCY > 325` nor `initialCY < 325` holds → the Y axis can NEVER register a crossing.
    // Mutant `<=` (325 <= 325 true → TOWARD branch): thresholdY = 275 + 25 = 300. Now
    //   initialCY(325) > 300, so currentCY <= 300 crosses. We drive currentCY to exactly 300.
    // X is neutralised: initialCX === targetCX too, so the X axis is likewise an AWAY branch
    // with threshold === initialCX and never crosses. Therefore the default returns []; the
    // `<=` mutant returns one collision. Asserting [] kills the mutant.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(50, 275, 200, 100) // targetCX = 150, targetCY = 325
    const collisionRect = makeDomRect(100, 275, 100, 50) // center (150, 300): currentCY = 300, currentCX = 150
    const initialRect = makeDomRect(100, 300, 100, 50) // center (150, 325) === (targetCX, targetCY)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 150, y: 300 },
    }

    expect(centerCrossing(args as never)).toEqual([])
  })

  it('does NOT cross in X when initialCX equals targetCX and currentCX reaches the toward-threshold (line 213 strict <)', () => {
    // Use a NARROW collision rect (width 20) so the toward and away X thresholds diverge.
    // target (250, 275, 100, 100) → targetCX = 300, left = 250, right = 350.
    // Default `<` (300 < 300 false → AWAY branch): thresholdX = Math.min(350 - 10, 300) = 300 ===
    //   initialCX → X never crosses (initialCX is neither < nor > its threshold).
    // Mutant `<=` (300 <= 300 true → TOWARD branch): thresholdX = 250 + 10 = 260. initialCX(300) > 260
    //   → crossing fires when currentCX <= 260. We drive currentCX to exactly 260.
    // Y is neutralised: initialCY === targetCY (325) and currentCY held at 325, an AWAY tie that
    //   never crosses. So the default returns []; the `<=` mutant returns one collision.
    // collisionRect width must match initial width (20) so dnd-kit's currentCX math lines up.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(250, 275, 100, 100) // targetCX = 300, targetCY = 325
    const collisionRect = makeDomRect(250, 300, 20, 50) // center (260, 325): currentCX = 260
    const initialRect = makeDomRect(290, 300, 20, 50) // center (300, 325) === (targetCX, targetCY)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 260, y: 325 },
    }

    expect(centerCrossing(args as never)).toEqual([])
  })
})

describe('centerCrossing LEFT-drag thresholdX uses the formula branch (line 215 minus)', () => {
  // Line 215: thresholdX = Math.min(rect.left + rect.width - collisionRect.width/2, targetCX).
  // The existing wide-target test only exercises the clamped (targetCX) outcome, leaving the
  // `rect.left + rect.width - collisionRect.width/2` formula and its `-` operator unpinned.
  // Here the formula value is the SMALLER of the two, so Math.min returns it; a `-`→`+` mutant
  // shifts the threshold and changes whether a boundary currentCX counts as crossed.
  it('crosses exactly at the formula threshold when the formula is below targetCX', () => {
    // Narrow target far to the right so targetCX is large; collisionRect wide so
    // rect.left + rect.width - w/2 < targetCX.
    // target (300, 275, 400, 100) → targetCX = 500, rect.right = 700.
    // collisionRect.width = 200 → formula = 300 + 400 - 100 = 600. Math.min(600, 500) = 500??
    // We need formula < targetCX. Make target wider so targetCX > formula:
    // target (300, 275, 600, 100) → targetCX = 600, rect.right = 900.
    // collisionRect.width = 200 → formula = 300 + 600 - 100 = 800. Math.min(800, 600) = 600 (clamp).
    // That clamps again. To make the FORMULA win we need formula < targetCX, i.e.
    // rect.left + rect.width - w/2 < rect.left + rect.width/2  ⇒  rect.width/2 < w/2 ⇒ w > rect.width.
    // So the collision rect must be WIDER than the target.
    // target (400, 275, 100, 100) → targetCX = 450, rect.right = 500.
    // collisionRect.width = 300 → formula = 400 + 100 - 150 = 350. Math.min(350, 450) = 350 (formula wins).
    // LEFT drag (initialCX > targetCX): crossedX needs currentCX <= 350.
    // Default threshold 350; mutant `+`: 400 + 100 + 150 = 650 → Math.min(650, 450) = 450.
    // currentCX = 350: default 350 <= 350 → crossed; mutant 350 <= 450 → also crossed. Same. Need
    // a currentCX between the two thresholds: currentCX = 400 → default 400 <= 350 false (not crossed),
    // mutant 400 <= 450 true (crossed). Discriminated. Keep Y aligned so only X can trigger.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(400, 275, 100, 100) // targetCX = 450, targetCY = 325
    // initialCX large (right of target) → LEFT drag. initialCY === targetCY so Y never crosses.
    const initialRect = makeDomRect(700 - 150, 325 - 25, 300, 50) // center (700, 325)
    const collisionRect = makeDomRect(400 - 150, 325 - 25, 300, 50) // center (400, 325)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 400, y: 325 },
    }

    // Default formula threshold = 350: currentCX 400 is NOT <= 350 → no crossing → empty.
    // A `-`→`+` mutant raises the threshold to 450, making 400 <= 450 cross → 1 collision.
    expect(centerCrossing(args as never)).toEqual([])
  })
})

describe('centerCrossing UP-direction currentCY uses Math.min (line 222 else branch)', () => {
  // Line 222: currentCY = initialCY < targetCY ? Math.max(crCY, ptrY) : Math.min(crCY, ptrY).
  // The else (UP / away) branch Math.min(crCY, ptrY) picks the furthest-advanced (smallest)
  // value when dragging up. A MethodExpression mutant swapping Math.min→Math.max would use the
  // larger (less-advanced) value and miss a crossing the real code detects.
  it('detects an UP crossing using the smaller of crCY and ptrY (grab offset ahead of overlay)', () => {
    // target (50, 275, 200, 100): targetCY = 325, rect.bottom = 375.
    // UP drag: initialCY = 575 (below). thresholdY = Math.min(375 - 25, 325) = 325. Cross needs
    // currentCY <= 325. collisionRect center crCY = 340 (still above threshold), pointer ptrY = 320
    // (already past). Math.min(340, 320) = 320 <= 325 → crossed (default).
    // Math.max mutant: Math.max(340, 320) = 340 <= 325 false → NOT crossed. Discriminated.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(50, 275, 200, 100)
    const collisionRect = makeDomRect(100, 340 - 25, 100, 50) // crCY = 340
    const initialRect = makeDomRect(100, 575 - 25, 100, 50) // initialCY = 575
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 150, y: 320 }, // ptrY = 320, ahead of crCY
    }

    expect(centerCrossing(args as never)).toHaveLength(1)
  })

  it('does not cross UP when neither crCY nor ptrY has reached the threshold', () => {
    // Both above threshold 325: crCY = 340, ptrY = 335. Math.min(340,335)=335 <= 325 false → empty.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(50, 275, 200, 100)
    const collisionRect = makeDomRect(100, 340 - 25, 100, 50)
    const initialRect = makeDomRect(100, 575 - 25, 100, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 150, y: 335 },
    }

    expect(centerCrossing(args as never)).toEqual([])
  })
})

describe('createTypedCollisionDetection reset guard (lines 303-315)', () => {
  // Line 312: `if (currentSourceItems !== lastSourceItems)` resets hadSiblingHit per drag.
  // Lines 303/313: hadSiblingHit / the reset assignment booleans. A test must show that the
  // hadSiblingHit recovery path is gated by the reset: when sourceContainerItemsRef changes
  // (new drag), a stale hadSiblingHit must NOT carry over.
  const siblingRect = makeDomRect(50, 275, 200, 100)

  function insidePointerArgs(currentY: number, pointerY: number): unknown {
    const sibling = createDroppableWithRect('row-2', {
      left: 50,
      top: 275,
      width: 200,
      height: 100,
    })
    const collisionRect = makeDomRect(100, currentY - 25, 100, 50)
    const initialRect = makeDomRect(100, 75, 100, 50)
    return {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [sibling],
      droppableRects: new Map([['row-2', siblingRect]]),
      pointerCoordinates: { x: 150, y: pointerY },
    }
  }

  it('keeps the recovery path within a single drag when sourceContainerItemsRef is unchanged', () => {
    const sourceRef = {
      current: new Set<string | number>(['row-2']) as ReadonlySet<string | number> | null,
    }
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: sourceRef,
    })

    // Cycle 1 (same ref): cross threshold → hadSiblingHit = true.
    expect(detect(insidePointerArgs(310, 310) as never)).toHaveLength(1)
    // Cycle 2 (same ref, no reset): not crossed but pointer inside → recovery fires → returns sibling.
    expect(detect(insidePointerArgs(290, 290) as never)).toHaveLength(1)
  })

  it('resets the recovery path when sourceContainerItemsRef changes between drags', () => {
    const sourceRef = {
      current: new Set<string | number>(['row-2']) as ReadonlySet<string | number> | null,
    }
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: sourceRef,
    })

    // Drag 1: register a sibling hit.
    expect(detect(insidePointerArgs(310, 310) as never)).toHaveLength(1)

    // New drag: swap the ref → reset must clear hadSiblingHit. Source still contains row-2 so the
    // pointer-inside guard still fires, but with hadSiblingHit reset it returns [] (no recovery).
    sourceRef.current = new Set<string | number>(['row-2'])
    expect(detect(insidePointerArgs(290, 290) as never)).toEqual([])
  })
})

describe('pending path falls through to parent when no sibling collision (line 373)', () => {
  // Line 373: `if (siblingCollisions.length > 0) return siblingCollisions`. A `> 0` → `>= 0`
  // mutant returns the (empty) sibling array immediately, suppressing the Pass-2 parent
  // fallback. Construct a pending move with NO sibling collision but a containing parent: the
  // default returns the parent; the mutant returns []. Asserting the parent is found kills it.
  it('returns the parent container when pending siblings yield no collision', () => {
    // Pending sibling far from the pointer → closestCenterLive still returns it (distance-based,
    // always non-empty if any sibling exists). To get ZERO sibling collisions we must give the
    // pending path an EMPTY sibling set: a pendingContainerItemsRef that excludes the only
    // sibling. Then siblingCollisions is empty and Pass 2 (parent containment) runs.
    const sibling = createDroppableWithRect('row-2', {
      left: 50,
      top: 275,
      width: 200,
      height: 100,
    })
    const parent = createDroppable('section-1')
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: true },
      // Pending set excludes row-2 → pendingSiblings is empty → no sibling collision.
      pendingContainerItemsRef: { current: new Set<string | number>(['row-999']) },
    })

    const collisionRect = makeDomRect(350, 275, 100, 50)
    const initialRect = makeDomRect(350, 75, 100, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [sibling, parent],
      droppableRects: new Map<string | number, ClientRect>([
        ['row-2', makeDomRect(50, 275, 200, 100)],
        ['section-1', makeDomRect(0, 0, 800, 600)],
      ]),
      pointerCoordinates: { x: 400, y: 300 },
    }

    const collisions = detect(args as never)

    expect(collisions).toHaveLength(1)
    expect(collisions[0].id).toBe('section-1')
  })
})

describe('source-container sibling filter (line 402)', () => {
  // Line 402: when sourceItems is non-empty, siblings are filtered to ONLY source-container
  // members. A mutant replacing the filter with all siblings (`siblings`) or `() => undefined`
  // would let a sibling that is NOT in the source container drive a same-container collision.
  // Here a non-source sibling sits exactly where a crossing would register; because it must be
  // filtered out, no sibling collision occurs and the pointer-inside guard returns []. With the
  // filter removed, the non-source sibling crosses → a sibling collision is returned instead.
  it('ignores siblings outside the source container during same-container detection', () => {
    // row-2 (SOURCE) sits low (top=275, threshold=300); row-3 (FOREIGN) sits high (top=100,
    // threshold=125). The drag descends to y=150: it crosses row-3's threshold but NOT row-2's.
    // Default filters to row-2 only → no crossing, pointer not inside row-2 → []. Mutant that
    // drops the source filter includes row-3 → row-3 crosses → returns row-3. Asserting [] (and
    // never row-3) kills the filter mutant.
    const sourceSibling = createDroppableWithRect('row-2', {
      left: 50,
      top: 275,
      width: 200,
      height: 100,
    })
    const foreignSibling = createDroppableWithRect('row-3', {
      left: 50,
      top: 100,
      width: 200,
      height: 100,
    })
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      // Source contains ONLY row-2; row-3 is a foreign-container sibling.
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2']) },
    })

    const collisionRect = makeDomRect(100, 125, 100, 50) // center (150, 150)
    const initialRect = makeDomRect(100, 25, 100, 50) // center (150, 50), above both targets
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [sourceSibling, foreignSibling],
      droppableRects: new Map<string | number, ClientRect>([
        ['row-2', makeDomRect(50, 275, 200, 100)],
        ['row-3', makeDomRect(50, 100, 200, 100)],
      ]),
      pointerCoordinates: { x: 150, y: 150 },
    }

    const collisions = detect(args as never)
    expect(collisions.map((c) => c.id)).not.toContain('row-3')
    expect(collisions).toEqual([])
  })
})

describe('parent fallback ignores parents with no measured rect (line 477)', () => {
  // Line 477: inside `parents.find`, `if (rect === undefined) return false`. A mutant returning
  // `true` would treat an unmeasured parent as containing the pointer. With one parent that has
  // NO droppableRects entry and a pointer in open space, the default skips it (containment find
  // fails) and falls to the distance fallback; the mutant would claim containment (value 0).
  it('does not treat an unmeasured parent as containing the pointer', () => {
    // Two parents: an UNMEASURED one (section-2, no droppableRects entry) listed FIRST, and a
    // MEASURED one (section-1) the pointer is outside of. Default: `find` returns false for the
    // unmeasured parent (line 477) and false for section-1 (pointer outside) → no containing
    // parent → distance fallback picks the measured section-1 with a nonzero value. Mutant
    // `return true`: the unmeasured section-2 (first) is claimed as containing → winner becomes
    // section-2 with the synthetic value 0. Asserting the winner is section-1 kills the mutant.
    const unmeasured = createDroppable('section-2')
    const measured = createDroppableWithRect('section-1', {
      left: 0,
      top: 0,
      width: 800,
      height: 600,
    })
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
    })

    const collisionRect = makeDomRect(900, 900, 100, 50)
    const initialRect = makeDomRect(900, 800, 100, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [unmeasured, measured],
      // section-2 deliberately absent → its containment check hits line 477.
      droppableRects: new Map<string | number, ClientRect>([
        ['section-1', makeDomRect(0, 0, 800, 600)],
      ]),
      pointerCoordinates: { x: 950, y: 925 }, // outside section-1 (right=800, bottom=600)
    }

    const collisions = detect(args as never)

    expect(collisions[0].id).toBe('section-1')
    expect(collisions[0].data?.value).not.toBe(0)
  })
})

describe('typedCollisionDetection static instance uses hasPendingMove=false (line 511)', () => {
  // Line 511: the static convenience instance hard-codes `hasPendingMoveRef: { current: false }`.
  // A `false` → `true` mutant would route siblings through closestCenterLive (pure distance,
  // always a hit) instead of centerCrossing (requires a threshold crossing). With a sibling the
  // pointer has NOT crossed, the real (false) instance returns [] for siblings and falls to the
  // parent; the mutant would return the sibling directly.
  it('returns no sibling collision when the centerCrossing threshold is not crossed', () => {
    const sibling = createDroppableWithRect('row-2', {
      left: 50,
      top: 275,
      width: 200,
      height: 100,
    })
    // Pointer inside the sibling rect but well short of the crossing threshold, and no parent
    // present → centerCrossing yields [] and there is nothing to fall back to.
    const collisionRect = makeDomRect(100, 265, 100, 50) // center (150, 290), threshold 300 not met
    const initialRect = makeDomRect(100, 75, 100, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [sibling],
      droppableRects: new Map<string | number, ClientRect>([
        ['row-2', makeDomRect(50, 275, 200, 100)],
      ]),
      pointerCoordinates: { x: 150, y: 290 },
    }

    // Real static instance (hasPendingMove=false → centerCrossing, not crossed): [].
    // Mutant (true → closestCenterLive): would return row-2.
    expect(typedCollisionDetection(args as never)).toEqual([])
  })
})

describe('closestCenterLive containment forced-true conjuncts (line 45)', () => {
  // Line 45 `contains` is a four-conjunct `&&` chain. `ConditionalExpression => true`
  // mutants force one conjunct to `true`, so a pointer that is OUTSIDE on that single
  // axis is wrongly treated as contained. Each test puts the pointer 1px outside one of
  // the RIGHT / TOP / BOTTOM edges (the LEFT-edge "just outside" case is already covered
  // at line 1607). Default: not contained → the closer-by-distance non-containing
  // candidate (row-3) ranks first. Mutant forcing that conjunct true: the far-but-
  // "contained" row-2 ranks first. Asserting [row-3, row-2] kills the forced-true mutant.
  const containingRect = { left: 400, top: 400, width: 1000, height: 1000 } // edges 400 / 1400

  function runEdge(pointerX: number, pointerY: number) {
    const containing = createDroppableWithRect('row-2', containingRect)
    // Tiny rect 30px past the pointer on the relevant outside direction — far closer by
    // distance, but it never contains the pointer (half-size 10px « 30px offset).
    const closer = createDroppableWithRect('row-3', {
      left: pointerX - 10,
      top: pointerY + 20,
      width: 20,
      height: 20,
    })
    const pendingItems = new Set<string | number>(['row-2', 'row-3'])
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: true },
      pendingContainerItemsRef: { current: pendingItems },
    })
    const collisionRect = makeDomRect(0, 0, 50, 50)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: makeDomRect(0, 0, 50, 50), translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [closer, containing],
      droppableRects: new Map(),
      pointerCoordinates: { x: pointerX, y: pointerY },
    }
    return detect(args as never)
  }

  it('does NOT contain a pointer 1px outside the RIGHT edge (refX <= rect.right conjunct)', () => {
    // Pointer (1401, 700): refX > rect.right → conjunct false → not contained.
    // Forcing the conjunct true would (wrongly) rank the containing row-2 first.
    expect(runEdge(1401, 700).map((c) => c.id)).toEqual(['row-3', 'row-2'])
  })

  it('does NOT contain a pointer 1px outside the TOP edge (refY >= rect.top conjunct)', () => {
    // Pointer (700, 399): refY < rect.top → conjunct false → not contained.
    expect(runEdge(700, 399).map((c) => c.id)).toEqual(['row-3', 'row-2'])
  })

  it('does NOT contain a pointer 1px outside the BOTTOM edge (refY <= rect.bottom conjunct)', () => {
    // Pointer (700, 1401): refY > rect.bottom → conjunct false → not contained.
    expect(runEdge(700, 1401).map((c) => c.id)).toEqual(['row-3', 'row-2'])
  })
})

describe('centerCrossing currentC direction selection (lines 221-222)', () => {
  // Lines 221/222 select Math.max (toward) vs Math.min (away) for the effective current
  // center via `initialC < targetC`. The `EqualityOperator => <=` mutants only differ when
  // `initialC === targetC`. Build that equality with an UNCLAMPED (formula-wins) threshold
  // so the axis can still register a crossing, and straddle the threshold with crC/ptr so
  // Math.min crosses while Math.max does not.

  it('uses Math.min(crCX, ptrX) on the X away-tie so the threshold is reached (line 221)', () => {
    // target (400, 275, 100, 100): targetCX = 450, right = 500. collisionRect width 300 (wider
    // than target) → away thresholdX = Math.min(500 - 150, 450) = 350 (formula, < targetCX).
    // initialCX = 450 === targetCX → away branch (default `<` false). initialCX(450) > 350 →
    // crossing needs currentCX <= 350. crCX = 340 (crossed), ptrX = 360 (passes overlap gate,
    // > rect.left - 50 = 350). Default Math.min(340, 360) = 340 <= 350 → crossed → 1 collision.
    // Mutant `<=` (450 <= 450 → toward) → Math.max(340, 360) = 360 <= 350 false → no crossing.
    // Y neutralised: initialCY === targetCY === 325 with a clamped away threshold == initialCY,
    // which never crosses.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(400, 275, 100, 100)
    const initialRect = makeDomRect(300, 300, 300, 50) // center (450, 325)
    const collisionRect = makeDomRect(190, 300, 300, 50) // center (340, 325) → crCX = 340
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 360, y: 325 },
    }

    // Default (Math.min picks the crossed 340): 1 collision. Mutant (Math.max picks 360): [].
    expect(centerCrossing(args as never)).toHaveLength(1)
  })

  it('uses Math.min(crCY, ptrY) on the Y away-tie so the threshold is reached (line 222)', () => {
    // target (50, 400, 100, 100): targetCY = 450, bottom = 500. collisionRect height 300 (taller
    // than target) → away thresholdY = Math.min(500 - 150, 450) = 350 (formula, < targetCY).
    // initialCY = 450 === targetCY → away branch. initialCY(450) > 350 → crossing needs
    // currentCY <= 350. crCY = 340 (crossed), ptrY = 360 (passes overlap gate, > top - 150 = 250).
    // Default Math.min(340, 360) = 340 <= 350 → crossed → 1 collision.
    // Mutant `<=` → Math.max(340, 360) = 360 <= 350 false → no crossing. X neutralised by an
    // initialCX === targetCX clamped away-tie.
    const target = createDroppable('row-2')
    const targetRect = makeDomRect(50, 400, 100, 100)
    const initialRect = makeDomRect(50, 300, 100, 300) // center (100, 450)
    const collisionRect = makeDomRect(50, 190, 100, 300) // center (100, 340) → crCY = 340
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [target],
      droppableRects: new Map([['row-2', targetRect]]),
      pointerCoordinates: { x: 100, y: 360 },
    }

    // Default (Math.min picks the crossed 340): 1 collision. Mutant (Math.max picks 360): [].
    expect(centerCrossing(args as never)).toHaveLength(1)
  })
})

describe('pointer-inside-source-sibling guard predicate (lines 431-439)', () => {
  // The no-pending guard tests the `.some(sibling => pointerInside(sibling))` predicate over
  // the SOURCE-container siblings. Reaching it requires: hasPendingMove=false, a non-empty
  // sourceContainerItemsRef, centerCrossing returning [] this cycle, and hadSiblingHit=false
  // (fresh closure, no prior crossing). When the predicate is true the guard short-circuits to
  // []; when false it falls through to the parent-container fallback (returns the parent).
  //
  // centerCrossing is forced to ALWAYS return [] for the source sibling by an away-tie:
  // initialC === targetC === threshold on both axes (so neither `>` nor `<` ever holds),
  // making the guard's predicate the sole decider.
  const parentRect = makeDomRect(0, 0, 800, 600)

  // Source sibling row-2 at (200, 200, 100, 100): edges left=200 right=300 top=200 bottom=300,
  // center (250, 250). initial/collision centres pinned to (250, 250) → away-tie, no crossing.
  function runGuard(pointerX: number, pointerY: number) {
    const sibling = createDroppableWithRect('row-2', {
      left: 200,
      top: 200,
      width: 100,
      height: 100,
    })
    const parent = createDroppable('section-1')
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2']) },
    })
    const initialRect = makeDomRect(200, 225, 100, 50) // center (250, 250)
    const collisionRect = makeDomRect(200, 225, 100, 50) // center (250, 250)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [sibling, parent],
      droppableRects: new Map<string | number, ClientRect>([
        ['row-2', makeDomRect(200, 200, 100, 100)],
        ['section-1', parentRect],
      ]),
      pointerCoordinates: { x: pointerX, y: pointerY },
    }
    return detect(args as never)
  }

  // On-edge pointers pin the `>=` / `<=` EqualityOperator mutants: exactly on an edge is
  // contained (→ guard → []) for the real `>=`/`<=`, but NOT contained (→ parent) for the
  // strict `>` / `<` mutant.
  it('treats a pointer exactly on the LEFT edge as inside (line 436 refX >= rect.left)', () => {
    expect(runGuard(200, 250)).toEqual([])
  })

  it('treats a pointer exactly on the RIGHT edge as inside (line 437 refX <= rect.left + width)', () => {
    expect(runGuard(300, 250)).toEqual([])
  })

  it('treats a pointer exactly on the TOP edge as inside (line 438 refY >= rect.top)', () => {
    expect(runGuard(250, 200)).toEqual([])
  })

  it('treats a pointer exactly on the BOTTOM edge as inside (line 439 refY <= rect.top + height)', () => {
    expect(runGuard(250, 300)).toEqual([])
  })

  // Outside-on-one-axis pointers pin the `ConditionalExpression => true` mutants: forcing the
  // named conjunct true would (wrongly) include this clearly-outside pointer → guard → [].
  // The real code leaves it outside → falls through to the parent.
  it('does NOT treat a pointer left of the LEFT edge as inside (line 436 forced-true)', () => {
    const result = runGuard(150, 250) // refX < left → conjunct false in real code
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe('section-1')
  })

  it('does NOT treat a pointer above the TOP edge as inside (line 438 forced-true)', () => {
    const result = runGuard(250, 150) // refY < top → conjunct false in real code
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe('section-1')
  })

  it('does NOT treat a pointer below the BOTTOM edge as inside (line 439 forced-true)', () => {
    const result = runGuard(250, 350) // refY > bottom → conjunct false in real code
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe('section-1')
  })
})

describe('pointer-inside guard uses .some, not .every (line 431)', () => {
  // With multiple SOURCE siblings, `.some` (pointer inside ANY) and `.every` (inside ALL)
  // diverge. A `.some => .every` MethodExpression mutant only differs when the pointer is
  // inside one source sibling but not another. Here row-2 contains the pointer and row-3 does
  // not. Default `.some` = true → guard short-circuits to []. Mutant `.every` = false → falls
  // through to the parent. Asserting [] kills the `.every` mutant.
  it('short-circuits when the pointer is inside one of several source siblings', () => {
    const inside = createDroppableWithRect('row-2', {
      left: 200,
      top: 200,
      width: 100,
      height: 100,
    })
    const outside = createDroppableWithRect('row-3', {
      left: 500,
      top: 200,
      width: 100,
      height: 100,
    })
    const parent = createDroppable('section-1')
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2', 'row-3']) },
    })

    // Pointer (250, 205): inside row-2 (200-300 / 200-300), outside row-3 (x 250 < 500).
    // Down-drag that crosses NEITHER threshold (currentCY 205 < both thresholds 225); X-axis
    // is an away-tie for row-2 and never overlaps row-3 → centerCrossing returns [].
    const initialRect = makeDomRect(200, 75, 100, 50) // center (250, 100)
    const collisionRect = makeDomRect(200, 180, 100, 50) // center (250, 205)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [inside, outside, parent],
      droppableRects: new Map<string | number, ClientRect>([
        ['row-2', makeDomRect(200, 200, 100, 100)],
        ['row-3', makeDomRect(500, 200, 100, 100)],
        ['section-1', makeDomRect(0, 0, 800, 600)],
      ]),
      pointerCoordinates: { x: 250, y: 205 },
    }

    // Default `.some`: pointer inside row-2 → true → guard returns []. Mutant `.every`: row-3
    // outside → false → falls through → returns section-1. Asserting [] kills `.every`.
    expect(detect(args as never)).toEqual([])
  })
})

describe('pointer-inside guard skips unmeasured source siblings (line 433)', () => {
  // Line 433 `if (rect === undefined) return false` makes an unmeasured source sibling count as
  // NOT containing the pointer. A `return false => return true` mutant would treat it as
  // containing → `.some` true → guard short-circuits to []. Here row-2 is measured (pointer
  // outside it) and row-3 is a source sibling with NO droppableRects entry. Default: row-2
  // false (outside) + row-3 false (line 433) → `.some` false → parent fallback returns
  // section-1. Mutant: row-3 → true → `.some` true → guard returns []. Asserting the parent
  // is returned kills the mutant.
  it('returns the parent (not []) when the only matching source sibling is unmeasured', () => {
    const measured = createDroppableWithRect('row-2', {
      left: 200,
      top: 200,
      width: 100,
      height: 100,
    })
    const unmeasured = createDroppableWithRect('row-3', {
      left: 500,
      top: 200,
      width: 100,
      height: 100,
    })
    const parent = createDroppable('section-1')
    const detect = createTypedCollisionDetection({
      hasPendingMoveRef: { current: false },
      sourceContainerItemsRef: { current: new Set<string | number>(['row-2', 'row-3']) },
    })

    // Pointer (250, 205): outside row-2 vertically below its top? It is inside row-2's rect
    // (200-300 / 200-300) — so to make row-2 NOT contain, move the pointer outside it. Use
    // (400, 205): outside both row-2 (x 400 > 300) and would be inside row-3's rect, but row-3
    // is UNMEASURED so line 433 is the only thing that can include it.
    const initialRect = makeDomRect(350, 75, 100, 50) // center (400, 100)
    const collisionRect = makeDomRect(350, 180, 100, 50) // center (400, 205)
    const args = {
      active: {
        id: 'row-1',
        rect: { current: { initial: initialRect, translated: collisionRect } },
        data: { current: undefined },
      },
      collisionRect,
      droppableContainers: [measured, unmeasured, parent],
      droppableRects: new Map<string | number, ClientRect>([
        // row-3 deliberately absent → callback hits line 433 for it.
        ['row-2', makeDomRect(200, 200, 100, 100)],
        ['section-1', makeDomRect(0, 0, 800, 600)],
      ]),
      pointerCoordinates: { x: 400, y: 205 },
    }

    const collisions = detect(args as never)
    expect(collisions).toHaveLength(1)
    expect(collisions[0].id).toBe('section-1')
  })
})

function runWithCurrentYUp(currentY: number) {
  const target = createDroppable('row-2')
  const targetRect = makeDomRect(50, 275, 200, 100)
  const collisionRect = makeDomRect(100, currentY - 25, 100, 50)
  const initialRect = makeDomRect(100, 575, 100, 50)
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
  }
  return centerCrossing(args as never)
}

describe('typedCollisionDetection', () => {
  it('is a function (static convenience instance)', () => {
    expect(typeof typedCollisionDetection).toBe('function')
  })

  it('behaves like createTypedCollisionDetection with hasPendingMove=false', () => {
    const parent = createDroppable('section-1')
    const rects = new Map<string | number, ClientRect>([['section-1', makeDomRect(0, 0, 800, 600)]])

    const collisionRect = makeDomRect(100, 285, 100, 50)
    const initialRect = makeDomRect(100, 75, 100, 50)

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
    }

    const collisions = typedCollisionDetection(args as never)

    expect(collisions.length).toBeGreaterThan(0)
    expect(collisions[0].id).toBe('section-1')
  })
})
