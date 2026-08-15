import type { ReactNode } from 'react'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import StatusBadge from '@/components/StatusBadge/StatusBadge'
import UnpublishedIndicator from '@/components/UnpublishedIndicator/UnpublishedIndicator'
import type { SectionNode } from '@/types/elements'

interface SectionChromeProps {
  readonly status: SectionNode['status']
  /** Derived, not from the wire: whether anything below this section is unpublished. */
  readonly hasUnpublishedDescendant: boolean
  readonly title: string
  readonly titleHref?: string
  readonly isCollapsed: boolean
  readonly onToggle: () => void
  readonly dropTarget?: boolean
  readonly setNodeRef?: (node: HTMLElement | null) => void
  readonly style?: React.CSSProperties
  readonly leading?: ReactNode
  readonly trailing?: ReactNode
  readonly children: ReactNode
}

/**
 * Presentational shell for a section. Owns the <section> frame and header
 * (leading slot, collapse toggle, title, modified dot, trailing slot); body is
 * an opaque `children` slot. Never branches on mode, only on data.
 */
export default function SectionChrome({
  status,
  hasUnpublishedDescendant,
  title,
  titleHref,
  isCollapsed,
  onToggle,
  dropTarget,
  setNodeRef,
  style,
  leading,
  trailing,
  children,
}: SectionChromeProps) {
  return (
    <section
      ref={setNodeRef}
      style={style}
      className="ssgrid-section"
      data-testid="section-block"
      data-status={status}
      data-descendant-unpublished={hasUnpublishedDescendant ? '' : undefined}
      data-collapsed={isCollapsed ? '' : undefined}
      data-drop-target={dropTarget ? '' : undefined}
    >
      <div className="ssgrid-section__header" data-testid="section-header">
        {leading}
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={title} />
        <h2 className="ssgrid-section__title" data-testid="section-title">
          {titleHref !== undefined ? (
            <a href={titleHref} data-testid="section-edit-link">
              {title}
            </a>
          ) : (
            title
          )}
        </h2>
        <StatusBadge status={status} testId="section-status-badge" />
        {hasUnpublishedDescendant && (
          <UnpublishedIndicator testId="section-unpublished-indicator" />
        )}
        {trailing}
      </div>
      <div className="ssgrid-section__body" data-testid="section-body" data-dnd-container="">
        {children}
      </div>
    </section>
  )
}
