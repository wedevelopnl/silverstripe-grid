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
