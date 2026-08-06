import { NodeIdentity, type NodeKey, type NodeType } from './identity'

export const DRAGGABLE_TYPES = ['section', 'row', 'column', 'element'] as const

export type DraggableType = (typeof DRAGGABLE_TYPES)[number]

/**
 * Minimal viewport rectangle used by drop-placement logic. Compatible with
 * {@link DOMRect} and dnd-kit's `ClientRect` — both have these four fields
 * plus extras we don't read. Defined here so consumers don't each reinvent
 * a `RectLike` / `Rect` shape.
 */
export interface ViewportRect {
  readonly left: number
  readonly top: number
  readonly width: number
  readonly height: number
}

export interface ParsedDraggableId {
  readonly type: DraggableType
  readonly id: number
  /** The validated NodeKey form of the input string — safe to use at map/set boundaries. */
  readonly key: NodeKey
}

function isDraggableType(value: NodeType): value is DraggableType {
  return value !== 'page'
}

/**
 * Build a dnd-kit sortable ID. Always returns a {@link NodeKey} string —
 * dnd-kit IDs and node keys are literally the same format for draggable types,
 * so there is no translation layer between the two spaces.
 */
export function buildDraggableId(type: DraggableType, id: number): NodeKey {
  return NodeIdentity.toKey(type, id)
}

/**
 * Composite IDs are immutable for an element's lifetime, but drag handlers and
 * collision filters parse them on every pointer-move cycle (60Hz+). Cache the
 * parse per unique ID — all fields are readonly, so sharing one instance is
 * safe, and the cache is bounded by the distinct IDs seen in the session.
 */
const parseCache = new Map<string, ParsedDraggableId | null>()

export function parseDraggableId(compositeId: string): ParsedDraggableId | null {
  const cached = parseCache.get(compositeId)
  if (cached !== undefined) return cached

  const ref = NodeIdentity.fromKey(compositeId)
  const parsed =
    ref === null || !isDraggableType(ref.type)
      ? null
      : // Safe: NodeIdentity.fromKey validated the template-literal shape, so
        // compositeId is structurally a NodeKey.
        { type: ref.type, id: ref.id, key: compositeId as NodeKey }
  parseCache.set(compositeId, parsed)
  return parsed
}

export function getDraggableType(compositeId: string): DraggableType | null {
  return parseDraggableId(compositeId)?.type ?? null
}

/** Maps a draggable type to the parent type that holds its siblings. */
export const PARENT_CONTAINER_TYPE = {
  section: 'page',
  row: 'section',
  column: 'row',
  element: 'column',
} as const satisfies Record<DraggableType, NodeType>
