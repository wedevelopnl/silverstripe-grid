export interface ViewportConfig {
  key: string
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
  defaultViewport: string
  columnCount: number
  rowClasses: string
  offsetStrategy: OffsetStrategy
  baseWidthClasses: Record<string, string>
  baseOffsetClasses: Record<string, string>
}
