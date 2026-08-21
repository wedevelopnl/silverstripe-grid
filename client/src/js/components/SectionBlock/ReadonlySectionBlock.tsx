import { memo } from 'react'
import ReadonlyRowBlock from '@/components/RowBlock/ReadonlyRowBlock'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import type { SectionNode } from '@/types/elements'
import SectionChrome from './SectionChrome'
import { hasUnpublishedDescendant } from '@/utils/publishStatus'

interface ReadonlySectionBlockProps {
  readonly section: SectionNode
}

const ReadonlySectionBlock = memo(function ReadonlySectionBlockComponent({
  section,
}: ReadonlySectionBlockProps) {
  const status = section.status
  const { isCollapsed, onToggle } = useElementCollapse(section.nodeKey)

  return (
    <SectionChrome
      status={status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(section)}
      title={section.title}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
    >
      {section.children?.map((row) => (
        <SharedChild
          key={row.nodeKey}
          child={row}
          siblings={section.children ?? []}
          readonly
          render={(node) => <ReadonlyRowBlock row={node} />}
        />
      ))}
    </SectionChrome>
  )
})

export default ReadonlySectionBlock
