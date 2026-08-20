import type {
  Active,
  ClientRect,
  CollisionDetection,
  DroppableContainer,
  Over,
} from '@dnd-kit/core'

/**
 * DOMRect-compatible factory for dnd-kit tests.
 */
export function makeDomRect(left: number, top: number, width: number, height: number): ClientRect {
  return {
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
  } as ClientRect
}

interface RectConfig {
  top?: number
  left?: number
  width?: number
  height?: number
}

function toDomRect(config: RectConfig = {}): DOMRect {
  const { top = 0, left = 0, width = 200, height = 50 } = config
  return {
    top,
    left,
    width,
    height,
    right: left + width,
    bottom: top + height,
    x: left,
    y: top,
    toJSON: () => ({}),
  }
}

export function createActive(id: string): Active {
  const domRect = toDomRect()
  return {
    id,
    rect: { current: { initial: domRect, translated: domRect } },
    data: { current: undefined },
  } as Active
}

export function createOver(id: string, rect?: RectConfig): Over {
  return {
    id,
    rect: toDomRect(rect),
    data: { current: undefined },
    disabled: false,
  } as Over
}

/**
 * Minimal DroppableContainer stub for filter-function tests. Only the `id`
 * field is exercised by the filter logic — no DOM node attached.
 */
export function createDroppable(id: string): DroppableContainer {
  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: null },
    rect: { current: null },
  } as unknown as DroppableContainer
}

/**
 * DroppableContainer with a mock DOM node whose getBoundingClientRect() returns
 * the given rect. Required for closestCenterLive and overRectRef capture tests.
 */
export function createDroppableWithRect(
  id: string,
  domRect: { left: number; top: number; width: number; height: number },
): DroppableContainer {
  const mockNode = {
    getBoundingClientRect: () => toDomRect(domRect),
  } as unknown as HTMLElement

  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: mockNode },
    rect: { current: null },
  } as unknown as DroppableContainer
}

export type CollisionArgs = Parameters<CollisionDetection>[0]

export interface CollisionArgsOptions {
  /** Composite id of the dragged item. Defaults to 'row-1'. */
  activeId?: string
  /** The active rect at drag start. */
  initialRect: ClientRect
  /** The active rect at the current pointer position (also `args.collisionRect`). */
  collisionRect: ClientRect
  containers: DroppableContainer[]
  /** Entries for `droppableRects`. Omit for live-rect (closestCenterLive) scenarios. */
  rects?: ReadonlyArray<readonly [string, ClientRect]>
  /** Defaults to null (no pointer), matching keyboard/sensor-less cycles. */
  pointer?: { x: number; y: number } | null
}

/**
 * Typed CollisionDetection args builder — the single owner of the
 * active/collisionRect/droppableRects wiring every collision test needs.
 * The one cast lives here so call sites stay cast-free.
 */
export function buildCollisionArgs(options: CollisionArgsOptions): CollisionArgs {
  const {
    activeId = 'row-1',
    initialRect,
    collisionRect,
    containers,
    rects = [],
    pointer = null,
  } = options
  return {
    active: {
      id: activeId,
      rect: { current: { initial: initialRect, translated: collisionRect } },
      data: { current: undefined },
    },
    collisionRect,
    droppableContainers: containers,
    droppableRects: new Map(rects),
    pointerCoordinates: pointer,
  } as unknown as CollisionArgs
}
