import { memo } from 'react'
import type { SimpleElementNode } from '@/types/elements'
import ElementCardChrome from './ElementCardChrome'

interface ReadonlyElementCardProps {
  readonly element: SimpleElementNode
}

const ReadonlyElementCard = memo(function ReadonlyElementCardComponent({
  element,
}: ReadonlyElementCardProps) {
  return (
    <ElementCardChrome
      status={element.status}
      icon={element.blockSchema.icon}
      title={element.title}
      summary={element.summary}
    />
  )
})

export default ReadonlyElementCard
