import { memo } from 'react'
import ReadonlyRowBlock from '@/components/RowBlock/ReadonlyRowBlock'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import type { SectionNode } from '@/types/elements'
import SectionChrome from './SectionChrome'

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
      title={section.title}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
    >
      {section.children?.map((row) => (
        <ReadonlyRowBlock key={row.nodeKey} row={row} />
      ))}
    </SectionChrome>
  )
})

export default ReadonlySectionBlock
