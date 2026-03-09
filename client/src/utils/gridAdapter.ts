import { getAdapterConfig } from '@/api/config';
import type { AdapterConfig, ViewportConfig } from '@/types/adapter';
import type { GridSettingsOption } from '@/components/GridSettingsPicker/GridSettingsPicker';

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
