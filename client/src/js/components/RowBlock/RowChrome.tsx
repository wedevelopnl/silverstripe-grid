import type { ReactNode } from 'react'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import StatusBadge from '@/components/StatusBadge/StatusBadge'
import UnpublishedIndicator from '@/components/UnpublishedIndicator/UnpublishedIndicator'
import { t } from '@/i18n'
import type { RowNode } from '@/types/elements'

interface RowChromeProps {
  readonly status: RowNode['status']
  /** Derived, not from the wire: whether anything below this row is unpublished. */
  readonly hasUnpublishedDescendant: boolean
  readonly title: string
  readonly titleHref?: string
  readonly isCollapsed: boolean
  readonly onToggle: () => void
  readonly columnCount: number
  readonly dropTarget?: boolean
  readonly setNodeRef?: (node: HTMLElement | null) => void
  readonly style?: React.CSSProperties
  readonly leading?: ReactNode
  readonly trailing?: ReactNode
  readonly children: ReactNode
}

/**
 * Presentational shell for a row. Owns the frame + the (reconciled, flat)
 * header — leading slot, collapse toggle, title, modified dot, column-count
 * meta, trailing slot. The columns body differs structurally between modes, so
 * it is an opaque `children` slot. Never branches on mode, only on data.
 */
export default function RowChrome({
  status,
  hasUnpublishedDescendant,
  title,
  titleHref,
  isCollapsed,
  onToggle,
  columnCount,
  dropTarget,
  setNodeRef,
  style,
  leading,
  trailing,
  children,
}: RowChromeProps) {
  return (
    <div
      ref={setNodeRef}
      style={style}
      className="ssgrid-row"
      data-testid="row-block"
      data-status={status}
      data-descendant-unpublished={hasUnpublishedDescendant ? '' : undefined}
      data-collapsed={isCollapsed ? '' : undefined}
      data-drop-target={dropTarget ? '' : undefined}
    >
      <div className="ssgrid-row__header" data-testid="row-header">
        {leading}
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={title} />
        <h3 className="ssgrid-row__title" data-testid="row-title">
          {titleHref !== undefined ? (
            <a href={titleHref} data-testid="row-edit-link">
              {title}
            </a>
          ) : (
            title
          )}
        </h3>
        <StatusBadge status={status} testId="row-status-badge" />
        {hasUnpublishedDescendant && <UnpublishedIndicator testId="row-unpublished-indicator" />}
        {columnCount > 0 && (
          <span className="ssgrid-row__meta" data-testid="row-column-count">
            {columnCount === 1
              ? t('WeDevelopGrid.RowBlock.COLUMN_COUNT_ONE', '{count} column', {
                  count: columnCount,
                })
              : t('WeDevelopGrid.RowBlock.COLUMN_COUNT_MANY', '{count} columns', {
                  count: columnCount,
                })}
          </span>
        )}
        {trailing}
      </div>
      {children}
    </div>
  )
}
