import { memo } from 'react'
import ReadonlyColumnBlock from '@/components/ColumnBlock/ReadonlyColumnBlock'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import type { RowNode } from '@/types/elements'
import { getColumnCount, getOffsetStrategy } from '@/utils/gridAdapter'
import { hasUnpublishedDescendant } from '@/utils/publishStatus'
import RowChrome from './RowChrome'

interface ReadonlyRowBlockProps {
  readonly row: RowNode
}

const ReadonlyRowBlock = memo(function ReadonlyRowBlockComponent({ row }: ReadonlyRowBlockProps) {
  const layoutMode = getOffsetStrategy() === 'margin' ? 'flex' : 'grid'
  const status = row.status
  const { isCollapsed, onToggle } = useElementCollapse(row.nodeKey)

  return (
    <RowChrome
      status={status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(row)}
      title={row.title}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      columnCount={row.children?.length ?? 0}
    >
      <div
        className="ssgrid-row__columns"
        data-testid="row-block-columns"
        data-layout-mode={layoutMode}
        style={
          layoutMode === 'grid'
            ? ({ '--grid-columns': String(getColumnCount()) } as React.CSSProperties)
            : undefined
        }
      >
        {row.children?.map((column) => (
          <ReadonlyColumnBlock key={column.nodeKey} column={column} />
        ))}
      </div>
    </RowChrome>
  )
})

export default ReadonlyRowBlock
