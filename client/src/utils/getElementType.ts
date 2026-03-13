import type { ElementNode } from '@/types/elements';
import { isContainerNode } from '@/types/elements';

export function getElementType(node: ElementNode): string {
  if (isContainerNode(node)) {
    return node.containerType;
  }
  return 'element';
}
