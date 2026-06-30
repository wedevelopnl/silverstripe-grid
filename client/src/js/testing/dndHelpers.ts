import type { Active, Over } from '@dnd-kit/core'

interface RectConfig {
  top?: number
  left?: number
  width?: number
  height?: number
}

function createDOMRect(config: RectConfig = {}): DOMRect {
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

function createActiveRect(rect: RectConfig = {}) {
  const domRect = createDOMRect(rect)
  return {
    current: {
      initial: domRect,
      translated: domRect,
    },
  }
}

export function createActive(id: string, rect?: RectConfig): Active {
  return {
    id,
    rect: createActiveRect(rect),
    data: { current: undefined },
  } as Active
}

export function createOver(id: string, rect?: RectConfig): Over {
  return {
    id,
    rect: createDOMRect(rect),
    data: { current: undefined },
    disabled: false,
  } as Over
}

export interface DragStartEventLike {
  active: Active
}

export interface DragOverEventLike {
  active: Active
  over: Over | null
  collisions: null
}

export interface DragEndEventLike {
  active: Active
  over: Over | null
  collisions: null
  delta: { x: number; y: number }
}

export function createDragStartEvent(activeId: string, rect?: RectConfig): DragStartEventLike {
  return {
    active: createActive(activeId, rect),
  }
}

export function createDragOverEvent(
  activeId: string,
  overId: string | null,
  overRect?: RectConfig,
  activeRect?: RectConfig,
): DragOverEventLike {
  return {
    active: createActive(activeId, activeRect),
    over: overId !== null ? createOver(overId, overRect) : null,
    collisions: null,
  }
}

export function createDragEndEvent(
  activeId: string,
  overId: string | null,
  overRect?: RectConfig,
  activeRect?: RectConfig,
): DragEndEventLike {
  return {
    active: createActive(activeId, activeRect),
    over: overId !== null ? createOver(overId, overRect) : null,
    collisions: null,
    delta: { x: 0, y: 0 },
  }
}
