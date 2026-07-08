import { getAdapterConfig } from '@/api/config'
import { t } from '@/i18n'
import type { AdapterConfig, OffsetStrategy, ViewportConfig, ViewportKey } from '@/types/adapter'
import type { GridSettings, ViewportSettings } from '@/types/elements'
import type { GridSettingsOption } from '@/types/gridSettings'

let cachedConfig: AdapterConfig | null = null

export function resetAdapterCache(): void {
  cachedConfig = null
}

function config(): AdapterConfig {
  if (cachedConfig === null) {
    cachedConfig = getAdapterConfig()
  }
  return cachedConfig
}

export function getViewports(): readonly ViewportConfig[] {
  return config().viewports
}

export function getDefaultViewport(): ViewportKey {
  return config().defaultViewport
}

export function getColumnCount(): number {
  return config().columnCount
}

export function getRowClasses(): string {
  return config().rowClasses
}

export function getOffsetStrategy(): OffsetStrategy {
  return config().offsetStrategy
}

export function getWidthClass(width: number): string {
  return config().baseWidthClasses[String(width)] ?? ''
}

export function getOffsetClass(offset: number): string {
  return config().baseOffsetClasses[String(offset)] ?? ''
}

// Not cached: the hidden label must be translated at call time (a module-level
// cache filled before ss.i18n loads its lang files would pin the English
// fallback for the session), matching getOffsetOptions' per-call behavior.
// Building ≤ columnCount + 1 items per call is trivial.
export function getWidthOptions(): readonly GridSettingsOption[] {
  const columnCount = config().columnCount
  const options: GridSettingsOption[] = []

  for (let n = 1; n <= columnCount; n++) {
    options.push({ value: n, label: `${n}/${columnCount}` })
  }

  options.push({ value: 'hidden', label: t('WeDevelopGrid.GridSettings.HIDDEN', 'hidden') })

  return options
}

export function getOffsetOptions(currentWidth?: number): readonly GridSettingsOption[] {
  const columnCount = config().columnCount
  const maxOffset = currentWidth !== undefined ? columnCount - currentWidth : columnCount - 1
  const options: GridSettingsOption[] = []

  for (let n = 0; n <= maxOffset; n++) {
    options.push({
      value: n,
      label: n === 0 ? t('WeDevelopGrid.GridSettings.OFFSET_NONE', 'none') : `+${n}`,
    })
  }

  return options
}

/**
 * Resolve effective viewport settings for a given viewport.
 *
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
