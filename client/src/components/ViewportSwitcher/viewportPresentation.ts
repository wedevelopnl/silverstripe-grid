import type { ViewportConfig } from '@/types/adapter'

/**
 * Pick a SilverStripe Admin font-icon glyph that approximates the device
 * category for a viewport's minimum width. The cutoffs are chosen so the
 * common framework breakpoints (Bootstrap, Tailwind, Bulma) land in the
 * device category a designer would expect.
 */
export function getViewportIcon(minWidth: number): string {
  if (minWidth < 768) return 'font-icon-mobile'
  if (minWidth < 1024) return 'font-icon-tablet'
  return 'font-icon-monitor'
}

/**
 * The Figma toolbar shows each viewport's *upper* boundary ("<768") rather
 * than its own min-width — except the largest viewport, which has no upper
 * bound and is left blank. We mirror that by reading the next viewport's
 * `minWidth` from the ordered list.
 */
export function getViewportRangeLabel(
  viewport: ViewportConfig,
  viewports: readonly ViewportConfig[],
): string | null {
  const index = viewports.findIndex((v) => v.key === viewport.key)
  if (index === -1) return null
  const next = viewports[index + 1]
  if (next === undefined) return null
  return `<${next.minWidth}`
}
