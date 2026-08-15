import { t } from '@/i18n'

interface UnpublishedIndicatorProps {
  readonly testId: string
}

/**
 * The dot marking a container that *holds* unpublished work further down the
 * tree, whether draft or modified. The element's own status is marked by
 * StatusBadge instead — the two are separate facts and a container can show
 * both at once.
 *
 * One dot covers both kinds on purpose: at a collapsed container the actionable
 * fact is only "there is something unpublished in here", and expanding it shows
 * which.
 *
 * Callers gate on the derived descendant status; this only draws the dot.
 */
export default function UnpublishedIndicator({ testId }: UnpublishedIndicatorProps) {
  return (
    <span
      className="ssgrid-unpublished-dot"
      data-testid={testId}
      aria-label={t('WeDevelopGrid.UnpublishedIndicator.CONTAINS', 'Contains unpublished changes')}
      role="img"
    />
  )
}
