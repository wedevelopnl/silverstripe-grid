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
}

/**
 * Presentational shell for an element card. The card is always a <div>; when
 * `href` is given the icon+title become an <a> that a CSS "stretched link"
 * (`.ssgrid-block__link::after`) expands over the whole card. This keeps the
 * interactive controls (drag handle, actions, dialogs) as SIBLINGS of the
 * anchor rather than descendants — nesting buttons/dialogs inside an <a> is
 * non-conforming HTML and forced the click-swallowing shims the card used to
 * carry. `leading`/`trailing` are opaque slots (drag handle / actions).
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
}: ElementCardChromeProps) {
  const titleContent = (
    <>
      <i
        className={`ssgrid-block__icon ${icon}`}
        data-testid="element-card-icon"
        aria-hidden="true"
      />
      <h4 className="ssgrid-block__title" data-testid="element-card-title">
        {title}
      </h4>
    </>
  )

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="ssgrid-block"
      data-testid="element-card"
      data-state={href !== undefined ? 'clickable' : undefined}
      data-status={status}
    >
      <div className="ssgrid-block__header">
        {leading}
        {href !== undefined ? (
          <a
            href={href}
            className="ssgrid-block__link"
            data-testid="element-card-link"
            onClick={onClick}
          >
            {titleContent}
          </a>
        ) : (
          titleContent
        )}
        {status === 'modified' && <ModifiedIndicator testId="element-card-modified-indicator" />}
        {trailing}
      </div>
      {summary ? (
        <p className="ssgrid-block__body" data-testid="element-card-summary">
          {summary}
        </p>
      ) : null}
    </div>
  )
}
