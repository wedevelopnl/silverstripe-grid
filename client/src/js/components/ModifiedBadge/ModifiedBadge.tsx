import { t } from '@/i18n'

interface ModifiedBadgeProps {
  readonly testId: string
}

/**
 * The brand-tinted "Modified" pill marking an element that itself has
 * unpublished changes — as opposed to {@link ModifiedIndicator}'s orange dot,
 * which says the change is somewhere *inside* the element.
 *
 * Callers gate on status; this only draws the pill.
 */
export default function ModifiedBadge({ testId }: ModifiedBadgeProps) {
  return (
    <span className="ssgrid-modified-badge" data-testid={testId}>
      {t('WeDevelopGrid.ModifiedBadge.LABEL', 'Modified')}
    </span>
  )
}
