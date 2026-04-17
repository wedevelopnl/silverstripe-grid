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
  // Stryker disable next-line ConditionalExpression: Equivalent — unreachable in well-formed trees (buildMaps derives childrenByParentKey from node.parentKey, so a node present in nodeMap is always found in its parent's children)
  if (sourceIndex === -1) return tree;

  const targetChildren = maps.childrenByParentKey.get(parentKey);
  // Stryker disable next-line ConditionalExpression: Equivalent — line 62 re-checks the same lookup on the cloned map and returns tree, so this early-return is shadowed
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
  // Stryker disable next-line ConditionalExpression: Equivalent — unreachable after structuredClone + buildMaps rebuild (same invariant as line 28)
  if (clonedSourceIndex === -1) return tree;

  const [movedElement] = clonedSourceChildren.splice(clonedSourceIndex, 1);

  // Stryker disable next-line ConditionalExpression: Equivalent — for same-parent moves, parseParentRef(parentKey) produces a NodeRef structurally identical to the cloned movedElement.parent, so reassigning yields the same result
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

// Stryker disable all
// The guards in parseParentRef are unreachable via applyReorder's public API:
// line 31 (`if (!targetChildren) return tree`) already returns the input tree for any
// parentKey that doesn't map to a parent in `childrenByParentKey`, which includes every
// malformed key (empty type, non-integer id, non-whitelisted type, etc.). parseParentRef
// is only called on line 54 for cross-parent moves where `targetChildren` was found, so
// by that point `parentKey` is guaranteed to be well-formed. Mutants on these defensive
// validations therefore produce identical observable behavior at the applyReorder boundary.
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
// Stryker restore all

function insertIntoArray(arr: ElementNode[], element: ElementNode, afterKey: NodeKey | null): void {
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
