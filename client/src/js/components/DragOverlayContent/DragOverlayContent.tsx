import { t } from '@/i18n'
import type { DraggableType } from '@/types/dnd'
import type { ElementNode } from '@/types/elements'
import { isContainerNode } from '@/types/elements'

interface DragOverlayContentProps {
  readonly node: ElementNode
  readonly type: DraggableType
}

function pluralize(count: number, singular: string): string {
  return count === 1 ? `${count} ${singular}` : `${count} ${singular}s`
}

function getChildCount(node: ElementNode): number {
  if (!isContainerNode(node)) return 0
  // biome-ignore lint/suspicious/noUnnecessaryConditions: container children are `… | null`; the ?? 0 fallback is required — dropping it fails typecheck.
  return node.children?.length ?? 0
}

/**
 * Sections and rows show a child-count meta line; columns and leaf elements
 * render icon + title only.
 */
function getMetaLabel(node: ElementNode, type: DraggableType): string | null {
  if (type === 'section') {
    return pluralize(getChildCount(node), t('WeDevelopGrid.DragOverlayContent.ROW_SINGULAR', 'row'))
  }
  if (type === 'row') {
    return pluralize(
      getChildCount(node),
      t('WeDevelopGrid.DragOverlayContent.COLUMN_SINGULAR', 'column'),
    )
  }
  return null
}

export default function DragOverlayContent({
  node,
  type,
}: DragOverlayContentProps): React.JSX.Element {
  const meta = getMetaLabel(node, type)

  return (
    <div className="ssgrid-drag-overlay" data-testid={`drag-overlay-${type}`}>
      <i
        className={`ssgrid-drag-overlay__icon ${node.blockSchema.icon}`}
        data-testid={`drag-overlay-${type}-icon`}
      />
      <span className="ssgrid-drag-overlay__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
      {meta !== null && (
        <span className="ssgrid-drag-overlay__meta" data-testid={`drag-overlay-${type}-meta`}>
          {meta}
        </span>
      )}
    </div>
  )
}
