import type { ReactNode } from 'react'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import ModifiedIndicator from '@/components/ModifiedIndicator/ModifiedIndicator'
import type { SectionNode } from '@/types/elements'

interface SectionChromeProps {
  readonly status: SectionNode['status']
  readonly title: string
  readonly titleHref?: string
  readonly isCollapsed: boolean
  readonly onToggle: () => void
  readonly dropTarget?: boolean
  readonly setNodeRef?: (node: HTMLElement | null) => void
  readonly style?: React.CSSProperties
  readonly dragHandle?: ReactNode
  readonly actions?: ReactNode
  readonly children: ReactNode
}

/**
 * Presentational shell for a section. Owns the <section> frame and header
 * (drag handle, collapse toggle, title, modified dot, actions); body is an
 * opaque `children` slot. Never branches on mode, only on data.
 */
export default function SectionChrome({
  status,
  title,
  titleHref,
  isCollapsed,
  onToggle,
  dropTarget,
  setNodeRef,
  style,
  dragHandle,
  actions,
  children,
}: SectionChromeProps) {
  return (
    <section
      ref={setNodeRef}
      style={style}
      className="ssgrid-section"
      data-testid="section-block"
      data-status={status}
      data-collapsed={isCollapsed ? '' : undefined}
      data-drop-target={dropTarget ? '' : undefined}
    >
      <div className="ssgrid-section__header" data-testid="section-header">
        {dragHandle}
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
        {status === 'modified' && <ModifiedIndicator testId="section-modified-indicator" />}
        {actions}
      </div>
      <div className="ssgrid-section__body">{children}</div>
    </section>
  )
}
