import { getAdapterConfig } from '@/api/config';
import type { AdapterConfig, ViewportConfig } from '@/types/adapter';
import type { GridSettings, ViewportSettings } from '@/types/elements';
import type { GridSettingsOption } from '@/types/gridSettings';

let cachedConfig: AdapterConfig | null = null;
let cachedWidthOptions: readonly GridSettingsOption[] | null = null;
let cachedOffsetOptions: readonly GridSettingsOption[] | null = null;

function config(): AdapterConfig {
  if (cachedConfig === null) {
    cachedConfig = getAdapterConfig();
  }
  return cachedConfig;
}

export function getViewports(): readonly ViewportConfig[] {
  return config().viewports;
}

export function getDefaultViewport(): string {
  return config().defaultViewport;
}

export function getColumnCount(): number {
  return config().columnCount;
}

export function getRowClasses(): string {
  return config().rowClasses;
}

export function getWidthClass(width: number): string {
  return config().baseWidthClasses[String(width)] ?? '';
}

export function getOffsetClass(offset: number): string {
  return config().baseOffsetClasses[String(offset)] ?? '';
}

export function getWidthOptions(): readonly GridSettingsOption[] {
  if (cachedWidthOptions === null) {
    const columnCount = config().columnCount;
    const options: GridSettingsOption[] = [];

    for (let n = 1; n <= columnCount; n++) {
      options.push({ value: n, label: `${n}/${columnCount}` });
    }

    options.push({ value: 'hidden', label: 'hidden' });
    cachedWidthOptions = options;
  }

  return cachedWidthOptions;
}

export function getOffsetOptions(): readonly GridSettingsOption[] {
  if (cachedOffsetOptions === null) {
    const columnCount = config().columnCount;
    const options: GridSettingsOption[] = [];

    for (let n = 0; n < columnCount; n++) {
      options.push({ value: n, label: n === 0 ? 'none' : `+${n}` });
    }

    cachedOffsetOptions = options;
  }

  return cachedOffsetOptions;
}

/**
 * Resolve effective viewport settings via mobile-first cascade.
 *
 * Walks ordered viewports from smallest up to (and including) activeViewport,
 * accumulating explicit overrides. Returns the effective settings at the
 * active viewport. Defaults: full-width, no offset, visible.
 */
export function resolveViewportSettings(
  gridSettings: GridSettings,
  activeViewport: string,
): ViewportSettings {
  const viewports = getViewports();
  const columnCount = getColumnCount();

  let effective: ViewportSettings = {
    width: columnCount,
    offset: 0,
    visible: true,
  };

  for (const vp of viewports) {
    const override = gridSettings[vp.key];
    if (override !== undefined) {
      effective = { ...effective, ...override };
    }

    if (vp.key === activeViewport) {
      break;
    }
  }

  return effective;
}
