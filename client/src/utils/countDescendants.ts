import type { ElementNode } from '@/types/elements';
import { isContainerNode } from '@/types/elements';

/**
 * Recursively counts all descendants of a container node.
 * Returns 0 for leaf elements (no children).
 */
export function countDescendants(node: ElementNode): number {
  if (!isContainerNode(node) || node.children === null) {
    return 0;
  }

  let count = 0;
  for (const child of node.children) {
    count += 1 + countDescendants(child);
  }
  return count;
}
