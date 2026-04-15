import type { ElementNode, TreeApiResponse } from '@/types/elements';
import { buildMaps } from '@/hooks/useElementMaps';
import type { NodeKey, NodeRef } from '@/types/identity';

/**
 * Apply a reorder operation to the element tree and return a new tree
 * with the element moved to the specified position.
 *
 * Returns the SAME reference for no-ops (element already at target), missing
 * element/parent, or any error state — keeping React memoisation stable.
 */
export function applyReorder(
  tree: TreeApiResponse,
  elementKey: NodeKey,
  parentKey: NodeKey,
  afterKey: NodeKey | null,
): TreeApiResponse {
  const maps = buildMaps(tree);

  const element = maps.nodeMap.get(elementKey);
  if (!element) return tree;

  const sourceParentKey = element.parentKey;
  const sourceChildren = maps.childrenByParentKey.get(sourceParentKey);
  if (!sourceChildren) return tree;

  const sourceIndex = sourceChildren.findIndex((n) => n.nodeKey === elementKey);
  if (sourceIndex === -1) return tree;

  const targetChildren = maps.childrenByParentKey.get(parentKey);
  if (!targetChildren) return tree;

  if (isNoOp(sourceParentKey, sourceIndex, sourceChildren, parentKey, afterKey)) {
    return tree;
  }

  // Deep-clone and rebuild maps to mutate safely.
  const cloned: TreeApiResponse = {
    rootParent: tree.rootParent,
    nodes: structuredClone(tree.nodes),
    overrideCounts: { ...tree.overrideCounts },
  };
  const clonedMaps = buildMaps(cloned);

  const clonedSourceChildren = clonedMaps.childrenByParentKey.get(sourceParentKey);
  if (!clonedSourceChildren) return tree;

  const clonedSourceIndex = clonedSourceChildren.findIndex((n) => n.nodeKey === elementKey);
  if (clonedSourceIndex === -1) return tree;

  const [movedElement] = clonedSourceChildren.splice(clonedSourceIndex, 1);

  if (sourceParentKey !== parentKey) {
    const targetParentRef = parseParentRef(parentKey);
    if (targetParentRef === null) return tree;

    (movedElement as { parent: NodeRef; parentKey: NodeKey }).parent = targetParentRef;
    (movedElement as { parent: NodeRef; parentKey: NodeKey }).parentKey = parentKey;
  }

  const clonedTargetChildren = clonedMaps.childrenByParentKey.get(parentKey);
  if (!clonedTargetChildren) return tree;

  insertIntoArray(clonedTargetChildren, movedElement, afterKey);

  return cloned;
}

function isNoOp(
  sourceParentKey: NodeKey,
  sourceIndex: number,
  sourceChildren: ElementNode[],
  parentKey: NodeKey,
  afterKey: NodeKey | null,
): boolean {
  if (sourceParentKey !== parentKey) return false;

  if (afterKey === null) {
    return sourceIndex === 0;
  }

  const afterIndex = sourceChildren.findIndex((n) => n.nodeKey === afterKey);
  if (afterIndex === -1) return false;

  return afterIndex + 1 === sourceIndex;
}

function parseParentRef(parentKey: NodeKey): NodeRef | null {
  const separatorIndex = parentKey.indexOf('-');
  if (separatorIndex <= 0) return null;
  const type = parentKey.slice(0, separatorIndex);
  const idNum = Number(parentKey.slice(separatorIndex + 1));
  if (!Number.isInteger(idNum) || idNum <= 0) return null;
  if (
    type !== 'page' &&
    type !== 'section' &&
    type !== 'row' &&
    type !== 'column' &&
    type !== 'element'
  ) {
    return null;
  }
  return { type, id: idNum };
}

function insertIntoArray(
  arr: ElementNode[],
  element: ElementNode,
  afterKey: NodeKey | null,
): void {
  if (afterKey === null) {
    arr.unshift(element);
    return;
  }

  const afterIndex = arr.findIndex((n) => n.nodeKey === afterKey);
  if (afterIndex === -1) {
    arr.push(element);
    return;
  }

  arr.splice(afterIndex + 1, 0, element);
}

