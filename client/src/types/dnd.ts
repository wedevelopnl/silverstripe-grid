import type { ElementNode } from './elements';
import { isContainerNode } from './elements';
import { buildNodeKey, parseNodeKey, type NodeKey, type NodeType } from './identity';

export const DRAGGABLE_TYPES = ['section', 'row', 'column', 'element'] as const;

export type DraggableType = (typeof DRAGGABLE_TYPES)[number];

export interface ParsedDraggableId {
  readonly type: DraggableType;
  readonly id: number;
}

function isDraggableType(value: NodeType): value is DraggableType {
  return value !== 'page';
}

/**
 * Build a dnd-kit sortable ID. Always returns a {@link NodeKey} string —
 * dnd-kit IDs and node keys are literally the same format for draggable types,
 * so there is no translation layer between the two spaces.
 */
export function buildDraggableId(type: DraggableType, id: number): NodeKey {
  return buildNodeKey(type, id);
}

export function parseDraggableId(compositeId: string): ParsedDraggableId | null {
  const ref = parseNodeKey(compositeId);
  if (ref === null) return null;
  if (!isDraggableType(ref.type)) return null;
  return { type: ref.type, id: ref.id };
}

export function getDraggableType(compositeId: string): DraggableType | null {
  return parseDraggableId(compositeId)?.type ?? null;
}

/**
 * Derives the draggable type from a node's shape: container nodes use their
 * containerType, leaf nodes are always 'element'.
 */
export function getDraggableTypeForNode(node: ElementNode): DraggableType {
  if (!isContainerNode(node)) return 'element';
  return node.containerType;
}

/**
 * Maps a draggable type to the parent type that holds its siblings.
 * Sections live under a page, rows in sections, columns in rows, elements in columns.
 */
export const PARENT_CONTAINER_TYPE: Record<DraggableType, NodeType> = {
  section: 'page',
  row: 'section',
  column: 'row',
  element: 'column',
};
