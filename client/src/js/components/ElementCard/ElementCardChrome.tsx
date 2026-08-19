import type { MouseEvent, ReactNode } from 'react'
import StatusBadge from '@/components/StatusBadge/StatusBadge'
import type { SimpleElementNode } from '@/types/elements'

interface ElementCardChromeProps {
  readonly status: SimpleElementNode['status']
  readonly icon: string
  readonly title: string
  readonly summary?: string
  readonly href?: string
  readonly onClick?: (event: MouseEvent<HTMLAnchorElement>) => void
  readonly setNodeRef?: (node: HTMLElement | null) => void
  readonly style?: React.CSSProperties
  readonly leading?: ReactNode
  readonly trailing?: ReactNode
  /** Measured to decide whether the actions still fit as an icon row. */
  readonly headerRef?: (node: HTMLElement | null) => void
}

/**
 * Presentational shell for an element card. Renders an <a> when `href` is
 * given (the clickable edit-link card) or a <div> otherwise — chosen by data,
 * never by mode. `leading`/`trailing` are opaque slots (drag handle / actions).
 */
export default function ElementCardChrome({
  status,
  icon,
  title,
  summary,
  href,
  onClick,
  setNodeRef,
  style,
  leading,
  trailing,
  headerRef,
}: ElementCardChromeProps) {
  const header = (
    <>
      <div className="ssgrid-block-header" ref={headerRef}>
        {leading}
        <i
          className={`ssgrid-block-icon ssgrid-type-icon-chip ${icon}`}
          data-testid="element-card-icon"
          aria-hidden="true"
        />
        <h4 className="ssgrid-block-title ssgrid-title" data-testid="element-card-title">
          {title}
        </h4>
        <StatusBadge status={status} testId="element-card-status-badge" />
        {trailing}
      </div>
      {summary ? (
        <p className="ssgrid-block-body" data-testid="element-card-summary">
          {summary}
        </p>
      ) : null}
    </>
  )

  if (href !== undefined) {
    return (
      <a
        ref={setNodeRef}
        href={href}
        style={style}
        className="ssgrid-block"
        data-testid="element-card"
        data-status={status}
        onClick={onClick}
      >
        {header}
      </a>
    )
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="ssgrid-block"
      data-testid="element-card"
      data-status={status}
    >
      {header}
    </div>
  )
}
