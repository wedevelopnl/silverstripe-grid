import { MOBILE_FIRST_PREVIEW_WIDTH } from '@/state/activeViewport'
import { getViewports } from '@/utils/gridAdapter'

/**
 * Pure CSS generator for the CMS preview device frame.
 *
 * Given the active adapter's viewports, emits a `<style>` tag that
 * overrides the vendor `.cms-preview.tablet` rules on `.preview-device-outer`
 * and the `.preview__device::after` dimension readout, keyed by our
 * own `grid-<key>` class. This file knows nothing about jQuery, React,
 * or the bridge lifecycle.
 */

export const STYLE_TAG_ID = 'grid-preview-viewport-styles'

/**
 * Monotonic device-frame height for a given preview width.
 *
 * A wider viewport always gets a taller (or equal) frame so no
 * "smaller" breakpoint ever appears visually bigger than a "larger"
 * one. The 500px floor keeps narrow mobile views usable; the 900px
 * cap keeps wide desktop previews from overflowing a typical
 * split-mode panel.
 */
export function heightForWidth(width: number): number {
  const floor = 500
  const cap = 900
  return Math.min(cap, Math.max(floor, Math.round(width * 0.75)))
}

/**
 * Escape a value for a single-quoted CSS string (the `content` property). Without
 * this, a viewport label containing an apostrophe (e.g. the French "L'écran")
 * terminates the string early and invalidates the generated rule. All four
 * characters forbidden in CSS strings are covered: backslash, the quote, and
 * the unescaped newline family (LF \A, FF \C, CR \D — hex escapes with a
 * terminating space).
 */
export function escapeCssString(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'")
    .replace(/\n/g, '\\A ')
    .replace(/\f/g, '\\C ')
    .replace(/\r/g, '\\D ')
}

function buildRules(): string {
  return getViewports()
    .map((vp) => {
      const width = vp.minWidth > 0 ? vp.minWidth : MOBILE_FIRST_PREVIEW_WIDTH
      const height = heightForWidth(width)
      // CSS.escape: the key has the same config provenance as the label the
      // content string escapes — a space or colon in it must not invalidate
      // the whole rule block.
      const selectorBase = `.cms-preview.grid-${CSS.escape(vp.key)}`
      return [
        `${selectorBase} .preview-device-outer {`,
        `  width: ${width}px;`,
        `  height: ${height}px;`,
        `  min-height: 0;`,
        `}`,
        `${selectorBase} .preview__device {`,
        `  min-width: calc(${width}px + 4 * 8px);`,
        `}`,
        `${selectorBase} .preview__device::after {`,
        `  content: '${escapeCssString(vp.label)} · ${width}px × ${height}px';`,
        `}`,
      ].join('\n')
    })
    .join('\n')
}

/**
 * Install the per-viewport stylesheet into `<head>`. Idempotent: calling
 * twice with the same adapter config replaces the existing content.
 */
export function installViewportStyles(): void {
  let style = document.getElementById(STYLE_TAG_ID) as HTMLStyleElement | null
  if (style === null) {
    style = document.createElement('style')
    style.id = STYLE_TAG_ID
    document.head.appendChild(style)
  }
  style.textContent = buildRules()
}

/** Remove the stylesheet if present. Safe to call when it isn't installed. */
export function removeViewportStyles(): void {
  const style = document.getElementById(STYLE_TAG_ID)
  if (style !== null) {
    style.remove()
  }
}
