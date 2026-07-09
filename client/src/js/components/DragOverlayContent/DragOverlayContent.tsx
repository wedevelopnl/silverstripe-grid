import { t } from '@/i18n'
import type { DraggableType } from '@/types/dnd'
import type { ElementNode } from '@/types/elements'
import { isContainerNode } from '@/types/elements'

interface DragOverlayContentProps {
  readonly node: ElementNode
  readonly type: DraggableType
}

function getChildCount(node: ElementNode): number {
  if (!isContainerNode(node)) return 0
  // biome-ignore lint/suspicious/noUnnecessaryConditions: container children are `… | null`; the ?? 0 fallback is required — dropping it fails typecheck.
  return node.children?.length ?? 0
}

/**
 * Sections and rows show a child-count meta line; columns and leaf elements
 * render icon + title only.
 *
 * Each count uses a full singular/plural message pair with a {count}
 * placeholder, selected in code. English pluralisation cannot be produced by
 * appending 's' to a translated noun (Dutch "rij" → "rijen", not "rijs"), and
 * the i18n collector requires literal key arguments, so the keys are inlined.
 */
function getMetaLabel(node: ElementNode, type: DraggableType): string | null {
  const count = getChildCount(node)

  if (type === 'section') {
    return count === 1
      ? t('WeDevelopGrid.DragOverlayContent.ROW_COUNT_ONE', '{count} row', { count })
      : t('WeDevelopGrid.DragOverlayContent.ROW_COUNT_MANY', '{count} rows', { count })
  }
  if (type === 'row') {
    return count === 1
      ? t('WeDevelopGrid.DragOverlayContent.COLUMN_COUNT_ONE', '{count} column', { count })
      : t('WeDevelopGrid.DragOverlayContent.COLUMN_COUNT_MANY', '{count} columns', { count })
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
