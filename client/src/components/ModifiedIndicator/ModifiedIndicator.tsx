import { t } from '@/i18n'

interface ModifiedIndicatorProps {
  readonly testId: string
}

/**
 * The "has unpublished changes" dot shown in every block header/card. Render it
 * only when the element's status is 'modified' — the component does NOT gate on
 * status (callers do); it just draws the dot with the caller's testid.
 */
export default function ModifiedIndicator({ testId }: ModifiedIndicatorProps) {
  return (
    <span
      className="ssgrid-modified-dot"
      data-testid={testId}
      aria-label={t('WeDevelopGrid.ModifiedIndicator.LABEL', 'Has unpublished changes')}
      role="img"
    />
  )
}
