import { buildMaps, type ElementMaps } from '@/hooks/useElementMaps'
import type { ElementNode, TreeApiResponse } from '@/types/elements'
import { NodeIdentity, type NodeKey } from '@/types/identity'

/**
 * Apply a reorder operation to the element tree and return a new tree
 * with the element moved to the specified position.
 *
 * Returns the SAME reference for no-ops (element already at target), missing
 * element/parent, or any error state — keeping React memoisation stable.
 *
 * `maps` MUST be the lookup maps built from `tree` (callers on the drag path
 * already hold the matching pair). Taking them as a parameter keeps the
 * frequent no-op exit — hit on every pointer move while a cross-container
 * preview hovers an unchanged slot — free of a full tree walk; only a real
 * move pays for cloning and re-mapping.
 */
export function applyReorder(
  tree: TreeApiResponse,
  maps: ElementMaps,
  elementKey: NodeKey,
  parentKey: NodeKey,
  afterKey: NodeKey | null,
): TreeApiResponse {
  const element = maps.nodeMap.get(elementKey)
  if (!element) return tree

  const sourceParentKey = element.parentKey
  const sourceChildren = maps.childrenByParentKey.get(sourceParentKey)
  // Stryker disable next-line ConditionalExpression: Equivalent — an in-tree element (checked above) always has a childrenByParentKey entry for its parent, so this guard is unreachable (same invariant as the sourceIndex guard below)
  if (!sourceChildren) return tree

  const sourceIndex = maps.indexByNodeKey.get(elementKey)
  if (sourceIndex === undefined) return tree

  if (isNoOp(sourceParentKey, sourceIndex, parentKey, afterKey, maps)) {
    return tree
  }

  // Deep-clone and rebuild maps to mutate safely.
  const cloned: TreeApiResponse = {
    rootParent: tree.rootParent,
    nodes: structuredClone(tree.nodes),
  }
  const clonedMaps = buildMaps(cloned)

  const clonedSourceChildren = clonedMaps.childrenByParentKey.get(sourceParentKey)
  if (!clonedSourceChildren) return tree

  const clonedSourceIndex = clonedMaps.indexByNodeKey.get(elementKey)
  // Stryker disable next-line ConditionalExpression: Equivalent — unreachable after structuredClone + buildMaps rebuild (same invariant as the original tree lookup above)
  if (clonedSourceIndex === undefined) return tree

  // Resolve afterKey's position in the target parent BEFORE the splice runs.
  // The splice can invalidate positions when source and target share a parent.
  const afterIndexInTarget = resolveAfterIndexInTarget(
    clonedMaps,
    parentKey,
    afterKey,
    sourceParentKey,
    clonedSourceIndex,
  )

  const [movedElement] = clonedSourceChildren.splice(clonedSourceIndex, 1)

  const targetParentRef = NodeIdentity.fromKey(parentKey)
  if (targetParentRef === null) return tree

  const repositionedElement: ElementNode = {
    ...movedElement,
    parent: targetParentRef,
    parentKey,
  }

  const clonedTargetChildren = clonedMaps.childrenByParentKey.get(parentKey)
  if (!clonedTargetChildren) return tree

  if (afterKey === null) {
    clonedTargetChildren.unshift(repositionedElement)
  } else if (afterIndexInTarget === null) {
    clonedTargetChildren.push(repositionedElement)
  } else {
    clonedTargetChildren.splice(afterIndexInTarget + 1, 0, repositionedElement)
  }

  return cloned
}

/**
 * Returns afterKey's index in the target parent's children, adjusted for an
 * imminent splice on the source. Returns `null` when afterKey is not a sibling
 * of the target (caller should append).
 */
function resolveAfterIndexInTarget(
  maps: ElementMaps,
  targetParentKey: NodeKey,
  afterKey: NodeKey | null,
  sourceParentKey: NodeKey,
  sourceIndexBeforeSplice: number,
): number | null {
  if (afterKey === null) return null

  const afterNode = maps.nodeMap.get(afterKey)
  if (afterNode === undefined || afterNode.parentKey !== targetParentKey) return null

  const afterIndex = maps.indexByNodeKey.get(afterKey)
  // Stryker disable next-line ConditionalExpression: Equivalent — afterKey is in nodeMap, so it must also be in indexByNodeKey (both populated by the same walk)
  if (afterIndex === undefined) return null

  // Stryker disable next-line EqualityOperator: Equivalent — `afterIndex > sourceIndexBeforeSplice` only differs at afterIndex === sourceIndexBeforeSplice, which requires afterKey to be the moved element itself; resolveDropPlacement never passes the active node as its own afterKey
  if (sourceParentKey === targetParentKey && afterIndex > sourceIndexBeforeSplice) {
    return afterIndex - 1
  }
  return afterIndex
}

function isNoOp(
  sourceParentKey: NodeKey,
  sourceIndex: number,
  parentKey: NodeKey,
  afterKey: NodeKey | null,
  maps: ElementMaps,
): boolean {
  if (sourceParentKey !== parentKey) return false

  if (afterKey === null) {
    return sourceIndex === 0
  }

  // afterKey must be a sibling of the source for the "already in place" check
  // to be meaningful — otherwise the move is genuinely cross-position.
  if (maps.nodeMap.get(afterKey)?.parentKey !== sourceParentKey) return false

  const afterIndex = maps.indexByNodeKey.get(afterKey)
  // Stryker disable next-line ConditionalExpression,BooleanLiteral: Equivalent — afterKey is in nodeMap (checked above), so it must also be in indexByNodeKey (both populated by the same walk); the guard never fires, so both the condition and the `return false` value are unreachable
  if (afterIndex === undefined) return false

  return afterIndex + 1 === sourceIndex
}
