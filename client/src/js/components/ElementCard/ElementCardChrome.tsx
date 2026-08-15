import type { MouseEvent, ReactNode } from 'react'
import ModifiedIndicator from '@/components/ModifiedIndicator/ModifiedIndicator'
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
      <div className="ssgrid-block__header" ref={headerRef}>
        {leading}
        <i
          className={`ssgrid-block__icon ${icon}`}
          data-testid="element-card-icon"
          aria-hidden="true"
        />
        <h4 className="ssgrid-block__title" data-testid="element-card-title">
          {title}
        </h4>
        {status === 'modified' && <ModifiedIndicator testId="element-card-modified-indicator" />}
        {trailing}
      </div>
      {summary ? (
        <p className="ssgrid-block__body" data-testid="element-card-summary">
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
        data-state="clickable"
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
