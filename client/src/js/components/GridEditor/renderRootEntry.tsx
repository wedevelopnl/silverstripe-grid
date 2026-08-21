import EditableSectionBlock from '@/components/SectionBlock/EditableSectionBlock'
import ReadonlySectionBlock from '@/components/SectionBlock/ReadonlySectionBlock'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
import type { ElementNode, SectionNode } from '@/types/elements'
import { isSectionNode } from '@/types/elements'

/**
 * Render one root-level entry of a zone.
 *
 * A zone holds two kinds of root: an ordinary Section, and a shared block
 * placement whose single child is the block's root — itself a normal section
 * node. Both go through the SAME section component; only the frame differs,
 * which is why the shared subtree needed no new components of its own.
 */
function renderSection(section: SectionNode, readonly: boolean) {
  return readonly ? (
    <ReadonlySectionBlock section={section} />
  ) : (
    <EditableSectionBlock section={section} />
  )
}

export function renderRootEntry(
  entry: ElementNode,
  options: { readonly siblings: readonly ElementNode[]; readonly readonly?: boolean },
) {
  const { siblings, readonly = false } = options

  return (
    <SharedChild
      child={entry as SectionNode}
      siblings={siblings}
      readonly={readonly}
      render={(section) => (isSectionNode(section) ? renderSection(section, readonly) : null)}
    />
  )
}
