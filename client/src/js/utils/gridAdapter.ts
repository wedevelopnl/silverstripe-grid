import { getAdapterConfig } from '@/api/config'
import { t } from '@/i18n'
import type { OffsetStrategy, ViewportConfig, ViewportKey } from '@/types/adapter'
import type { GridSettings, ViewportSettings } from '@/types/elements'
import type { GridSettingsOption } from '@/types/gridSettings'

export function getViewports(): readonly ViewportConfig[] {
  return getAdapterConfig().viewports
}

export function getDefaultViewport(): ViewportKey {
  return getAdapterConfig().defaultViewport
}

export function getColumnCount(): number {
  return getAdapterConfig().columnCount
}

export function getOffsetStrategy(): OffsetStrategy {
  return getAdapterConfig().offsetStrategy
}

/**
 * Option arrays are requested per column per render but depend only on the
 * adapter config (and, for offsets, the column's width). Cache them keyed on
 * the config object identity — the CMS bootstraps it once per admin page
 * load, and tests reinstalling window.ss.config invalidate it naturally.
 * i18n labels resolve once per config lifetime; dictionaries load with the
 * admin bundle before anything renders.
 */
type AdapterConfig = ReturnType<typeof getAdapterConfig>

/**
 * Human label for a column width, e.g. "4 columns".
 *
 * The design labels widths by span rather than as the "4/12" fraction the
 * editor used to show. Exported so the picker's trigger and its options are
 * formatted by the same function — they sat in separate code paths and could
 * drift into showing the value two different ways.
 */
export function formatWidthLabel(width: number): string {
  return width === 1
    ? t('WeDevelopGrid.GridSettings.WIDTH_ONE', '{count} column', { count: width })
    : t('WeDevelopGrid.GridSettings.WIDTH_MANY', '{count} columns', { count: width })
}

/**
 * The 1-based grid line a column carrying this offset starts on.
 *
 * Grid-placement adapters express an offset as `col-start-N` — Tailwind sets
 * `offset_adjustment = 1`, so a stored offset of 2 renders `col-start-3`. That
 * adjustment is not on the wire, so the value is fixed here and shared by the
 * CSS custom property and the picker label: the number an author reads has to
 * be the number the emitted class uses.
 */
export function getOffsetStartLine(offset: number): number {
  return offset + 1
}

/**
 * Human label for a column offset, e.g. "Offset 2" or "Start 3".
 *
 * Which reading is correct depends on the adapter. Margin strategies (Bootstrap,
 * Bulma) push the column with `offset-N`, so the number is an offset. Grid
 * placement (Tailwind) emits `col-start-N`, where the meaningful number is the
 * line the column starts on — labelling that "Offset 2" would contradict the
 * `col-start-3` it generates.
 *
 * Neither form is pluralised: both read as "<attribute> <value>", so the zero
 * case keeps the same shape as every other.
 */
export function formatOffsetLabel(offset: number): string {
  return getOffsetStrategy() === 'grid-placement'
    ? t('WeDevelopGrid.GridSettings.OFFSET_START', 'Start {count}', {
        count: getOffsetStartLine(offset),
      })
    : t('WeDevelopGrid.GridSettings.OFFSET_MARGIN', 'Offset {count}', { count: offset })
}

let widthOptionsCache: { config: AdapterConfig; options: readonly GridSettingsOption[] } | null =
  null

export function getWidthOptions(): readonly GridSettingsOption[] {
  const config = getAdapterConfig()
  if (widthOptionsCache !== null && widthOptionsCache.config === config) {
    return widthOptionsCache.options
  }

  const columnCount = config.columnCount
  const options: GridSettingsOption[] = []

  for (let n = 1; n <= columnCount; n++) {
    options.push({ value: n, label: formatWidthLabel(n) })
  }

  options.push({ value: 'hidden', label: t('WeDevelopGrid.GridSettings.HIDDEN', 'hidden') })

  widthOptionsCache = { config, options }
  return options
}

let offsetOptionsCache: {
  config: AdapterConfig
  byMaxOffset: Map<number, readonly GridSettingsOption[]>
} | null = null

export function getOffsetOptions(currentWidth?: number): readonly GridSettingsOption[] {
  const config = getAdapterConfig()
  const columnCount = config.columnCount
  const maxOffset = currentWidth !== undefined ? columnCount - currentWidth : columnCount - 1

  if (offsetOptionsCache === null || offsetOptionsCache.config !== config) {
    offsetOptionsCache = { config, byMaxOffset: new Map() }
  }
  const cached = offsetOptionsCache.byMaxOffset.get(maxOffset)
  if (cached !== undefined) return cached

  const options: GridSettingsOption[] = []

  for (let n = 0; n <= maxOffset; n++) {
    options.push({ value: n, label: formatOffsetLabel(n) })
  }

  offsetOptionsCache.byMaxOffset.set(maxOffset, options)
  return options
}

/**
 * Returns the viewport's override if present, otherwise the default settings.
 * A `null` viewport (no selection yet / adapter unavailable) resolves to the
 * default — there is no per-viewport override to apply.
 */
export function resolveViewportSettings(
  gridSettings: GridSettings,
  activeViewport: ViewportKey | null,
): ViewportSettings {
  if (activeViewport === null) {
    return gridSettings.default
  }
  return gridSettings.overrides[activeViewport] ?? gridSettings.default
}
