import type { ViewportSettings } from '@/types/elements'
import { getColumnCount, getOffsetStrategy } from '@/utils/gridAdapter'

/**
 * Layer the resolved column width/offset onto a (possibly empty) sortable
 * style. Margin strategy emits --col-width/--col-offset percentages; grid
 * strategy emits --col-span/--col-start spans. Moved verbatim from the old
 * ColumnBlock dispatcher so both variants share one implementation.
 */
export function buildColumnStyle(
  settings: ViewportSettings,
  sortableStyle: React.CSSProperties,
): React.CSSProperties {
  const columnCount = getColumnCount()
  const strategy = getOffsetStrategy()

  if (strategy === 'margin') {
    return {
      ...sortableStyle,
      '--col-width': `${(settings.width / columnCount) * 100}%`,
      ...(settings.offset > 0
        ? { '--col-offset': `${(settings.offset / columnCount) * 100}%` }
        : {}),
    } as React.CSSProperties
  }

  return {
    ...sortableStyle,
    '--col-span': String(settings.width),
    ...(settings.offset > 0 ? { '--col-start': String(settings.offset + 1) } : {}),
  } as React.CSSProperties
}
