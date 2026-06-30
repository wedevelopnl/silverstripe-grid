/**
 * A viewport key proven to belong to the active adapter's viewport set.
 *
 * Branded so it can only originate at the two trust boundaries that establish
 * validity: the adapter config (every {@link ViewportConfig.key} is valid by
 * definition) and `setActiveViewport`, which validates an arbitrary string
 * against that set before minting. Never produce one with a bare `as` cast —
 * derive it from a `ViewportConfig.key` so the proof is real, not asserted.
 */
export type ViewportKey = string & { readonly __viewportKey: unique symbol }

export interface ViewportConfig {
  key: ViewportKey
  label: string
  /**
   * Framework breakpoint min-width in pixels. 0 indicates the mobile-first
   * default (no `min-width` media query). The CMS preview bridge maps 0 to
   * MOBILE_FIRST_PREVIEW_WIDTH when rendering the iframe.
   */
  minWidth: number
}

export type OffsetStrategy = 'margin' | 'grid-placement'

export interface AdapterConfig {
  viewports: ViewportConfig[]
  defaultViewport: ViewportKey
  columnCount: number
  rowClasses: string
  offsetStrategy: OffsetStrategy
  baseWidthClasses: Record<string, string>
  baseOffsetClasses: Record<string, string>
}
