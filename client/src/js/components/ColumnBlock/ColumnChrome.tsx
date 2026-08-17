import { type ReactNode, useId } from 'react'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import StatusBadge from '@/components/StatusBadge/StatusBadge'
import UnpublishedIndicator from '@/components/UnpublishedIndicator/UnpublishedIndicator'
import type { ColumnNode } from '@/types/elements'

interface ColumnChromeProps {
  readonly status: ColumnNode['status']
  /** Derived, not from the wire: whether anything below this column is unpublished. */
  readonly hasUnpublishedDescendant: boolean
  readonly title: string
  readonly titleHref?: string
  readonly icon: string
  readonly isCollapsed: boolean
  readonly onToggle: () => void
  readonly columnStyle: React.CSSProperties
  readonly hidden: boolean
  readonly dropTarget?: boolean
  readonly setNodeRef?: (node: HTMLElement | null) => void
  readonly insertBefore?: ReactNode
  readonly leading?: ReactNode
  readonly trailing?: ReactNode
  readonly layoutSettings?: ReactNode
  readonly children: ReactNode
  readonly footer?: ReactNode
  readonly overlay?: ReactNode
}

/**
 * Presentational shell for a column. Owns the outer wrapper (carrying the
 * resolved column width/offset style), the card frame + header toolbar, and the
 * body container. All slots are opaque ReactNode; the chrome never branches on
 * editor mode — only on data (titleHref, status, hidden, dropTarget).
 */
export default function ColumnChrome({
  status,
  hasUnpublishedDescendant,
  title,
  titleHref,
  icon,
  isCollapsed,
  onToggle,
  columnStyle,
  hidden,
  dropTarget,
  setNodeRef,
  insertBefore,
  leading,
  trailing,
  layoutSettings,
  children,
  footer,
  overlay,
}: ColumnChromeProps) {
  const bodyId = useId()

  return (
    <div
      ref={setNodeRef}
      style={columnStyle}
      className="ssgrid-column"
      data-testid="column-block-outer"
    >
      {insertBefore}
      <div
        className="ssgrid-column__card"
        data-testid="column-block"
        data-status={status}
        data-descendant-unpublished={hasUnpublishedDescendant ? '' : undefined}
        data-collapsed={isCollapsed ? '' : undefined}
        data-drop-target={dropTarget ? '' : undefined}
        data-hidden={hidden ? '' : undefined}
      >
        <div className="ssgrid-column__header" data-testid="column-header">
          <div className="ssgrid-column__toolbar">
            {leading}
            <CollapseToggle
              isCollapsed={isCollapsed}
              onToggle={onToggle}
              label={title}
              controlsId={bodyId}
            />
            <i className={`ssgrid-column__icon ${icon}`} aria-hidden="true" />
            <span className="ssgrid-column__title" data-testid="column-title">
              {titleHref !== undefined ? (
                <a href={titleHref} data-testid="column-edit-link">
                  {title}
                </a>
              ) : (
                title
              )}
            </span>
            <StatusBadge status={status} testId="column-status-badge" />
            {hasUnpublishedDescendant && (
              <UnpublishedIndicator testId="column-unpublished-indicator" />
            )}
            {trailing}
          </div>
          {layoutSettings !== undefined && (
            <div className="ssgrid-column__layout-settings">{layoutSettings}</div>
          )}
        </div>
        <div
          id={bodyId}
          className="ssgrid-column__body"
          data-testid="column-body"
          data-dnd-container=""
        >
          {children}
          {footer}
        </div>
      </div>
      {overlay}
    </div>
  )
}
