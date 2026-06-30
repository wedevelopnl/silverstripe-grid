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

interface PreviewProps {
  readonly node: ElementNode
  readonly type: DraggableType
}

function SectionPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i
        className={`ssgrid-drag-overlay__icon ${node.blockSchema.icon}`}
        data-testid={`drag-overlay-${type}-icon`}
      />
      <span className="ssgrid-drag-overlay__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
      <span className="ssgrid-drag-overlay__meta" data-testid={`drag-overlay-${type}-meta`}>
        {pluralize(getChildCount(node), t('WeDevelopGrid.DragOverlayContent.ROW_SINGULAR', 'row'))}
      </span>
    </>
  )
}

function RowPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i
        className={`ssgrid-drag-overlay__icon ${node.blockSchema.icon}`}
        data-testid={`drag-overlay-${type}-icon`}
      />
      <span className="ssgrid-drag-overlay__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
      <span className="ssgrid-drag-overlay__meta" data-testid={`drag-overlay-${type}-meta`}>
        {pluralize(
          getChildCount(node),
          t('WeDevelopGrid.DragOverlayContent.COLUMN_SINGULAR', 'column'),
        )}
      </span>
    </>
  )
}

function ColumnPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i
        className={`ssgrid-drag-overlay__icon ${node.blockSchema.icon}`}
        data-testid={`drag-overlay-${type}-icon`}
      />
      <span className="ssgrid-drag-overlay__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
    </>
  )
}

function ElementPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i
        className={`ssgrid-drag-overlay__icon ${node.blockSchema.icon}`}
        data-testid={`drag-overlay-${type}-icon`}
      />
      <span className="ssgrid-drag-overlay__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
    </>
  )
}

const PREVIEW_BY_TYPE: Record<DraggableType, React.ComponentType<PreviewProps>> = {
  section: SectionPreview,
  row: RowPreview,
  column: ColumnPreview,
  element: ElementPreview,
}

export default function DragOverlayContent({
  node,
  type,
}: DragOverlayContentProps): React.JSX.Element {
  const Preview = PREVIEW_BY_TYPE[type]

  return (
    <div className="ssgrid-drag-overlay" data-testid={`drag-overlay-${type}`}>
      <Preview node={node} type={type} />
    </div>
  )
}
