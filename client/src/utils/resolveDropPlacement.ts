import { isContainerNode } from '@/types/elements';
import { buildDraggableId } from '@/types/dnd';
import type { ParsedDraggableId } from '@/types/dnd';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { resolveInsertDirection } from '@/utils/resolveInsertDirection';
import { resolveReorderParams } from '@/utils/resolveReorderParams';
import type { ReorderElementParams } from '@/api/endpoints';

interface RectLike {
  readonly left: number;
  readonly top: number;
  readonly width: number;
  readonly height: number;
}

export interface DropContext {
  readonly activeParsed: ParsedDraggableId;
  readonly overParsed: ParsedDraggableId;
  readonly pointer: { readonly x: number; readonly y: number } | null;
  readonly maps: ElementMaps;
  readonly sourceParentId: number;
  readonly sourceIndex: number;
  readonly overRect: RectLike;
}

/**
 * Pure function that resolves a drag-and-drop event into API reorder parameters.
 *
 * Handles both same-container reordering and cross-container moves:
 * - Same container (sourceParentId === targetParentId): places at the over
 *   element's index without direction adjustment (SortableContext handles
 *   visual positioning).
 * - Cross container: applies pointer-based direction (before/after) relative
 *   to the over element's rect.
 *
 * Returns null for no-ops (same position) or invalid states (missing nodes).
 */
export function resolveDropPlacement(ctx: DropContext): ReorderElementParams | null {
  const { activeParsed, overParsed, pointer, maps, sourceParentId, sourceIndex, overRect } = ctx;

  let targetParentId: number;
  let insertIndex: number;

  const activeCompositeId = buildDraggableId(activeParsed.type, activeParsed.id);

  if (overParsed.type === activeParsed.type) {
    // Over a sibling — use the sibling's parent
    const overNode = maps.nodeMap.get(overParsed.id);
    if (!overNode) return null;

    targetParentId = overNode.parentId;
    const siblings = maps.childrenByParentId.get(targetParentId) ?? [];
    const compositeIds = siblings.map((n) => buildDraggableId(activeParsed.type, n.id));
    const filtered = compositeIds.filter((id) => id !== activeCompositeId);

    const overCompositeId = buildDraggableId(overParsed.type, overParsed.id);

    if (sourceParentId === targetParentId) {
      // Same container: use the over element's index in the full list.
      // SortableContext handles visual positioning, so no direction needed.
      const overOriginalIdx = compositeIds.indexOf(overCompositeId);
      if (overOriginalIdx === -1) {
        insertIndex = filtered.length;
      } else {
        insertIndex = overOriginalIdx;
      }
      filtered.splice(insertIndex, 0, activeCompositeId);
    } else {
      // Cross container: find position in filtered list, apply direction
      const overIdx = filtered.indexOf(overCompositeId);
      if (overIdx === -1) {
        insertIndex = filtered.length;
      } else {
        insertIndex = overIdx;

        if (pointer !== null) {
          if (resolveInsertDirection(pointer, overRect, activeParsed.type) === 'after') {
            insertIndex += 1;
          }
        }
      }

      const clampedIndex = Math.min(insertIndex, filtered.length);
      filtered.splice(clampedIndex, 0, activeCompositeId);
    }

    return resolveReorderParams({
      activeId: activeCompositeId,
      overContainerParentId: targetParentId,
      overIndex: filtered.indexOf(activeCompositeId),
      containerItems: filtered,
      sourceContainerParentId: sourceParentId,
      sourceIndex,
    });
  }

  // Over a container — drop into it
  const containerNode = maps.nodeMap.get(overParsed.id);
  if (!containerNode || !isContainerNode(containerNode)) return null;

  targetParentId = containerNode.id;
  const children = containerNode.children ?? [];
  const compositeIds = children.map((n) => buildDraggableId(activeParsed.type, n.id));
  const filtered = compositeIds.filter((id) => id !== activeCompositeId);

  insertIndex = filtered.length;
  filtered.splice(insertIndex, 0, activeCompositeId);

  return resolveReorderParams({
    activeId: activeCompositeId,
    overContainerParentId: targetParentId,
    overIndex: filtered.indexOf(activeCompositeId),
    containerItems: filtered,
    sourceContainerParentId: sourceParentId,
    sourceIndex,
  });
}
