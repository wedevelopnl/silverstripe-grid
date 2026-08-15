import { t } from '@/i18n'

interface ModifiedIndicatorProps {
  readonly testId: string
}

/**
 * The orange dot marking a container that *holds* unpublished changes further
 * down the tree. The element's own change is marked by ModifiedBadge instead —
 * the two are separate facts and a container can show both at once.
 *
 * Callers gate on the derived descendant status; this only draws the dot.
 */
export default function ModifiedIndicator({ testId }: ModifiedIndicatorProps) {
  return (
    <span
      className="ssgrid-modified-dot"
      data-testid={testId}
      aria-label={t('WeDevelopGrid.ModifiedIndicator.CONTAINS', 'Contains unpublished changes')}
      role="img"
    />
  )
}
