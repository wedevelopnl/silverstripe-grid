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

export function getRowClasses(): string {
  return getAdapterConfig().rowClasses
}

export function getOffsetStrategy(): OffsetStrategy {
  return getAdapterConfig().offsetStrategy
}

export function getWidthClass(width: number): string {
  return getAdapterConfig().baseWidthClasses[String(width)] ?? ''
}

export function getOffsetClass(offset: number): string {
  return getAdapterConfig().baseOffsetClasses[String(offset)] ?? ''
}

export function getWidthOptions(): readonly GridSettingsOption[] {
  const columnCount = getAdapterConfig().columnCount
  const options: GridSettingsOption[] = []

  for (let n = 1; n <= columnCount; n++) {
    options.push({ value: n, label: `${n}/${columnCount}` })
  }

  options.push({ value: 'hidden', label: t('WeDevelopGrid.GridSettings.HIDDEN', 'hidden') })

  return options
}

export function getOffsetOptions(currentWidth?: number): readonly GridSettingsOption[] {
  const columnCount = getAdapterConfig().columnCount
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
