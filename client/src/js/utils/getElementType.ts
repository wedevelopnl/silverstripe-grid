import type { ContainerType, ElementNode } from '@/types/elements'
import { isContainerNode } from '@/types/elements'

/**
 * Element types as understood by the duplicate-to dialog and acceptable-
 * containers API: container nodes are identified by their {@link ContainerType},
 * leaf nodes use the literal `'element'`. This is structurally identical to
 * `DraggableType` from `@/types/dnd` but kept distinct so the duplicate-to
 * surface doesn't accidentally couple to drag-and-drop semantics.
 */
export type ElementTypeKey = ContainerType | 'element'

export function getElementType(node: ElementNode): ElementTypeKey {
  if (isContainerNode(node)) {
    return node.containerType
  }
  return 'element'
}
