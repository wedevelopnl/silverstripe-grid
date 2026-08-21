import type { ReactNode } from 'react'
import EditableColumnBlock from '@/components/ColumnBlock/EditableColumnBlock'
import ReadonlyColumnBlock from '@/components/ColumnBlock/ReadonlyColumnBlock'
import EditableElementCard from '@/components/ElementCard/EditableElementCard'
import ReadonlyElementCard from '@/components/ElementCard/ReadonlyElementCard'
import EditableRowBlock from '@/components/RowBlock/EditableRowBlock'
import ReadonlyRowBlock from '@/components/RowBlock/ReadonlyRowBlock'
import EditableSectionBlock from '@/components/SectionBlock/EditableSectionBlock'
import ReadonlySectionBlock from '@/components/SectionBlock/ReadonlySectionBlock'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
import type { ElementNode } from '@/types/elements'
import { isColumnNode, isRowNode, isSectionNode, isSimpleElementNode } from '@/types/elements'

/**
 * Render one root-level entry of a tree.
 *
 * A page zone holds two kinds of root: an ordinary Section, and a shared block
 * placement whose single child is the block's root — itself a normal section
 * node. Both go through the SAME section component; only the frame differs,
 * which is why the shared subtree needed no new components of its own.
 *
 * The library editor's root is the one place the other three shapes surface: a
 * block roots a subtree of any shape, so its editor has to render a Row, a
 * Column or a lone element where a page zone only ever has Sections. Each is
 * the same component that shape uses inside a page — they take only their own
 * node, so nothing about them assumes a container above.
 */
function renderNode(node: ElementNode, readonly: boolean): ReactNode {
  if (isSectionNode(node)) {
    return readonly ? (
      <ReadonlySectionBlock section={node} />
    ) : (
      <EditableSectionBlock section={node} />
    )
  }

  if (isRowNode(node)) {
    return readonly ? <ReadonlyRowBlock row={node} /> : <EditableRowBlock row={node} />
  }

  if (isColumnNode(node)) {
    return readonly ? <ReadonlyColumnBlock column={node} /> : <EditableColumnBlock column={node} />
  }

  if (isSimpleElementNode(node)) {
    return readonly ? (
      <ReadonlyElementCard element={node} />
    ) : (
      <EditableElementCard element={node} />
    )
  }

  // A placement, which SharedChild has already unwrapped — so only a nested
  // one could arrive here, and the server forbids those outright.
  return null
}

export function renderRootEntry(
  entry: ElementNode,
  options: { readonly siblings: readonly ElementNode[]; readonly readonly?: boolean },
) {
  const { siblings, readonly = false } = options

  return (
    <SharedChild
      child={entry}
      siblings={siblings}
      readonly={readonly}
      render={(node) => renderNode(node, readonly)}
    />
  )
}
