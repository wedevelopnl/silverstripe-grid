/**
 * Shared mock data constants for test files.
 *
 * These are plain values (no Vitest APIs) so they can be imported inside
 * vi.mock() factory functions via `await import()`.
 */

export const MOCK_VIEWPORTS = [
  { key: 'xs', label: 'XS' },
  { key: 'sm', label: 'SM' },
  { key: 'md', label: 'MD' },
  { key: 'lg', label: 'LG' },
  { key: 'xl', label: 'XL' },
  { key: 'xxl', label: 'XXL' },
];

export function resolveViewportSettingsImpl(
  gridSettings: {
    default: { width: number; offset: number; visible: boolean };
    overrides: Record<string, { width: number; offset: number; visible: boolean }>;
  },
  activeViewport: string,
): { width: number; offset: number; visible: boolean } {
  return gridSettings.overrides[activeViewport] ?? gridSettings.default;
}

export const DEFAULT_SORTABLE_RETURN = {
  attributes: {},
  listeners: undefined,
  setNodeRef: () => {},
  transform: null,
  transition: null,
  isDragging: false,
};

export function defaultWidthOptions(columnCount = 12) {
  return [
    ...Array.from({ length: columnCount }, (_, i) => ({
      value: i + 1,
      label: `${i + 1}/${columnCount}`,
    })),
    { value: 'hidden' as const, label: 'hidden' },
  ];
}

export function defaultOffsetOptions(currentWidth?: number, columnCount = 12) {
  const maxOffset = currentWidth !== undefined ? columnCount - currentWidth : columnCount - 1;
  return Array.from({ length: maxOffset + 1 }, (_, i) => ({
    value: i,
    label: i === 0 ? 'none' : `+${i}`,
  }));
}
