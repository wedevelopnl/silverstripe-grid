import { memo, useId } from 'react'
import ReadonlyColumnBlock from '@/components/ColumnBlock/ReadonlyColumnBlock'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
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
  const bodyId = useId()

  return (
    <RowChrome
      status={status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(row)}
      title={row.title}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      columnCount={row.children?.length ?? 0}
      bodyId={bodyId}
    >
      <div
        id={bodyId}
        className="ssgrid-row-columns"
        data-testid="row-block-columns"
        data-layout-mode={layoutMode}
        style={
          layoutMode === 'grid'
            ? ({ '--grid-columns': String(getColumnCount()) } as React.CSSProperties)
            : undefined
        }
      >
        {row.children?.map((column) => (
          <SharedChild
            key={column.nodeKey}
            child={column}
            siblings={row.children ?? []}
            readonly
            render={(node) => <ReadonlyColumnBlock column={node} />}
          />
        ))}
      </div>
    </RowChrome>
  )
})

export default ReadonlyRowBlock
