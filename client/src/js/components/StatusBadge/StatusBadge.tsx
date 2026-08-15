import { t } from '@/i18n'
import type { ElementStatus } from '@/types/status'

interface StatusBadgeProps {
  readonly status: ElementStatus
  readonly testId: string
}

/**
 * The pill marking an element that itself has unpublished work — as opposed to
 * {@link UnpublishedIndicator}'s dot, which says the work is somewhere *inside*
 * the element.
 *
 * Draft and modified share one look because they call for the same action
 * (publish) and, in the CMS page tree, the same colour family; the label is what
 * separates "never published" from "published then changed". Any other status
 * renders nothing, so callers need no guard of their own.
 */
export default function StatusBadge({ status, testId }: StatusBadgeProps) {
  if (status !== 'draft' && status !== 'modified') {
    return null
  }

  const label =
    status === 'draft'
      ? t('WeDevelopGrid.StatusBadge.DRAFT', 'Draft')
      : t('WeDevelopGrid.StatusBadge.MODIFIED', 'Modified')

  return (
    <span className="ssgrid-status-badge" data-testid={testId} data-status={status}>
      {label}
    </span>
  )
}
