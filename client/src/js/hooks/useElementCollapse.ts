import { useCallback } from 'react'
import { useCollapse } from '@/hooks/useCollapseState'
import type { NodeKey } from '@/types/identity'

/**
 * Collapse state for a single grid node, keyed by NodeKey. Unifies the
 * byte-identical per-block hooks (useSectionCollapse / useRowCollapse /
 * useColumnCollapse). `onToggle` is stable per `nodeKey` so it can feed a
 * memoised chrome's toggle button without churning its props.
 */
export function useElementCollapse(nodeKey: NodeKey): {
  isCollapsed: boolean
  onToggle: () => void
} {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse()
  const isCollapsed = isCollapsedFn(nodeKey)
  const onToggle = useCallback(() => toggle(nodeKey), [toggle, nodeKey])
  return { isCollapsed, onToggle }
}
