import { memo } from 'react'
import ReadonlyElementCard from '@/components/ElementCard/ReadonlyElementCard'
import EmptyState from '@/components/EmptyState/EmptyState'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import type { ColumnNode } from '@/types/elements'
import { hasUnpublishedDescendant } from '@/utils/publishStatus'
import { resolveViewportSettings } from '@/utils/gridAdapter'
import { buildColumnStyle } from './buildColumnStyle'
import ColumnChrome from './ColumnChrome'

interface ReadonlyColumnBlockProps {
  readonly column: ColumnNode
}

const ReadonlyColumnBlock = memo(function ReadonlyColumnBlockComponent({
  column,
}: ReadonlyColumnBlockProps) {
  const { activeViewport } = useViewportContext()
  const settings = resolveViewportSettings(column.gridSettings, activeViewport)
  const { isCollapsed, onToggle } = useElementCollapse(column.nodeKey)
  // No sortable transform in readonly mode — pass empty style and let
  // buildColumnStyle layer the --col-width / --col-span variables on top.
  const columnStyle = buildColumnStyle(settings, {})
  const children = column.children ?? []

  return (
    <ColumnChrome
      status={column.status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(column)}
      title={column.title}
      icon={column.blockSchema.icon}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      columnStyle={columnStyle}
      hidden={!settings.visible}
    >
      {children.length > 0 ? (
        children.map((child) => <ReadonlyElementCard key={child.nodeKey} element={child} />)
      ) : (
        <EmptyState
          message={t('WeDevelopGrid.ColumnBlock.NO_CONTENT_BLOCKS', 'No content blocks')}
        />
      )}
    </ColumnChrome>
  )
})

export default ReadonlyColumnBlock
