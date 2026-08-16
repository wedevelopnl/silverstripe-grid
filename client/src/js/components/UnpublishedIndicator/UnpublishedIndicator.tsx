import { t } from '@/i18n'

interface UnpublishedIndicatorProps {
  readonly testId: string
}

/**
 * Announces that a container holds unpublished work further down the tree.
 * Assistive tech only — it renders no visible mark.
 *
 * Sighted users already read this off the unpublished ring. The ring fires for
 * "at or below" (see `status-rings` in `_publish-status.scss`), so a ring with
 * no "Draft"/"Modified" pill can only mean the work is below; and where the
 * pill *is* present the extra bit is unactionable, because publishing is
 * recursive and clears the element and its subtree in one action either way.
 * A visible dot on top of that was redundant in every state.
 *
 * The ring is a border colour, invisible to a screen reader, and the pill's
 * *absence* is not something a screen reader can report — so without this span
 * a collapsed container hiding unpublished work would be silent.
 */
export default function UnpublishedIndicator({ testId }: UnpublishedIndicatorProps) {
  return (
    <span className="ssgrid-unpublished-note" data-testid={testId}>
      {t('WeDevelopGrid.UnpublishedIndicator.CONTAINS', 'Contains unpublished changes')}
    </span>
  )
}
