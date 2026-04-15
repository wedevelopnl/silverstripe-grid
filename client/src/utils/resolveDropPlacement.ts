import { isContainerNode } from '@/types/elements';
import type { ParsedDraggableId } from '@/types/dnd';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { buildNodeKey, nodeRefToKey, type NodeKey } from '@/types/identity';
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
  readonly sourceParentKey: NodeKey;
  readonly sourceIndex: number;
  readonly overRect: RectLike;
}

/**
 * Pure function that resolves a drag-and-drop event into API reorder parameters.
 *
 * Same-container moves use index-based placement (SortableContext handles the
 * visual shuffle). Cross-container moves apply pointer-based direction
 * (before/after) relative to the over element's rect.
 *
 * Returns null for no-ops (same position) or invalid states (missing nodes).
 */
export function resolveDropPlacement(ctx: DropContext): ReorderElementParams | null {
  const { activeParsed, overParsed, pointer, maps, sourceParentKey, sourceIndex, overRect } = ctx;

  const activeKey = buildNodeKey(activeParsed.type, activeParsed.id);

  if (overParsed.type === activeParsed.type) {
    const overKey = buildNodeKey(overParsed.type, overParsed.id);
    const overNode = maps.nodeMap.get(overKey);
    if (!overNode) return null;

    const targetParent = overNode.parent;
    const targetParentKey = overNode.parentKey;
    const siblings = maps.childrenByParentKey.get(targetParentKey) ?? [];
    const compositeIds: NodeKey[] = siblings.map((n) => n.nodeKey);
    const filtered = compositeIds.filter((id) => id !== activeKey);

    let insertIndex: number;
    if (sourceParentKey === targetParentKey) {
      // Same container: use the over element's index in the full list.
      const overOriginalIdx = compositeIds.indexOf(overKey);
      insertIndex = overOriginalIdx === -1 ? filtered.length : overOriginalIdx;
      filtered.splice(insertIndex, 0, activeKey);
    } else {
      const overIdx = filtered.indexOf(overKey);
      if (overIdx === -1) {
        insertIndex = filtered.length;
      } else {
        insertIndex = overIdx;
        if (
          pointer !== null &&
          resolveInsertDirection(pointer, overRect, activeParsed.type) === 'after'
        ) {
          insertIndex += 1;
        }
      }

      const clampedIndex = Math.min(insertIndex, filtered.length);
      filtered.splice(clampedIndex, 0, activeKey);
    }

    return resolveReorderParams({
      activeId: activeKey,
      targetParent,
      targetParentKey,
      overIndex: filtered.indexOf(activeKey),
      containerItems: filtered,
      sourceParentKey,
      sourceIndex,
      maps,
    });
  }

  // Over a container — drop at its end.
  const overKey = buildNodeKey(overParsed.type, overParsed.id);
  const containerNode = maps.nodeMap.get(overKey);
  if (!containerNode || !isContainerNode(containerNode)) return null;

  const targetParent = containerNode.self;
  const targetParentKey = nodeRefToKey(targetParent);
  const children = containerNode.children ?? [];
  const compositeIds = children.map((n) => n.nodeKey);
  const filtered = compositeIds.filter((id) => id !== activeKey);

  filtered.push(activeKey);

  return resolveReorderParams({
    activeId: activeKey,
    targetParent,
    targetParentKey,
    overIndex: filtered.indexOf(activeKey),
    containerItems: filtered,
    sourceParentKey,
    sourceIndex,
    maps,
  });
}
