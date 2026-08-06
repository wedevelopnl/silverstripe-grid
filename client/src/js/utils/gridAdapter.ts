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
    options.push({ value: n, label: `${n}/${columnCount}` })
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
    options.push({
      value: n,
      label: n === 0 ? t('WeDevelopGrid.GridSettings.OFFSET_NONE', 'none') : `+${n}`,
    })
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
