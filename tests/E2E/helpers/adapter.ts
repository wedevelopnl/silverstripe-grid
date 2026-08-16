import type { Locator, Page } from '@playwright/test'

/**
 * Shape of the adapter config exposed by GridController::buildAdapterConfig()
 * onto window.ss.config.sections[<grid controller>].gridAdapter.
 *
 * Specs that need adapter-specific knowledge (viewport count, default viewport
 * key, form field names) should read this at test time rather than hardcoding
 * values tied to the Bootstrap preset.
 */
export interface AdapterConfig {
  viewports: { key: string; label: string; minWidth: number }[]
  defaultViewport: string
  columnCount: number
  rowClasses: string
  offsetStrategy: 'margin' | 'grid-placement'
}

const GRID_CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\GridController'

/**
 * Read the active adapter's runtime config from the CMS page.
 *
 * Must be called from a page that has loaded the SilverStripe admin bundle
 * (`window.ss.config` is populated). Frontend-only pages do not expose this.
 */
export function readAdapterConfig(page: Page): Promise<AdapterConfig> {
  return page.evaluate((controllerFqcn) => {
    const ss = (window as unknown as { ss?: { config?: { sections: { name: string }[] } } }).ss
    if (!ss?.config) {
      throw new Error('window.ss.config is not available — load an admin page first.')
    }

    const section = ss.config.sections.find((s) => s.name === controllerFqcn) as
      | { gridAdapter?: AdapterConfig }
      | undefined

    if (!section?.gridAdapter) {
      throw new Error(`Grid adapter config missing from section "${controllerFqcn}".`)
    }

    return section.gridAdapter
  }, GRID_CONTROLLER_FQCN)
}

/**
 * The editor's label for a column width.
 *
 * Mirrors `formatWidthLabel` in `client/src/js/utils/gridAdapter.ts`: the badge
 * and its listbox options label a width by its span ("6 columns"), not as the
 * "6/12" fraction the editor used to show. Kept adapter-agnostic — callers pass
 * a span derived from `columnCount`.
 */
export function widthLabel(span: number): string {
  return span === 1 ? `${span} column` : `${span} columns`
}

/**
 * Locator for the viewport switcher button corresponding to `key`.
 *
 * Relies on the composed testid `viewport-button-<key>` emitted by
 * ViewportSwitcher.tsx — adapter-agnostic: each adapter's viewport keys
 * produce their own unique testids at render time.
 */
export function viewportButton(page: Page, key: string): Locator {
  return page.getByTestId(`viewport-button-${key}`)
}

/**
 * Ensure the given viewport is the active one. No-op if already active.
 *
 * The active viewport button renders with `aria-disabled="true"`, which
 * Playwright's auto-wait treats as unclickable. Loops that iterate every
 * adapter viewport must use this helper rather than an unconditional click.
 */
export async function activateViewport(page: Page, key: string): Promise<void> {
  const button = viewportButton(page, key)
  if ((await button.getAttribute('aria-pressed')) === 'true') {
    return
  }
  await button.click()
}

/**
 * Pick a viewport key from the active adapter's config that is not its default.
 *
 * Returns the first non-default key ordered smallest-to-largest. Specs that
 * need two distinct non-default keys should call this once then pick another
 * from `config.viewports`.
 */
export function firstNonDefaultViewport(config: AdapterConfig): string {
  const nonDefault = config.viewports.find((vp) => vp.key !== config.defaultViewport)
  if (nonDefault === undefined) {
    throw new Error('Adapter has only one viewport — no non-default to pick.')
  }
  return nonDefault.key
}

/**
 * Two distinct non-default viewport keys, ordered smallest-to-largest.
 * Throws if the adapter has fewer than three viewports.
 */
export function twoNonDefaultViewports(config: AdapterConfig): [string, string] {
  const nonDefault = config.viewports
    .filter((vp) => vp.key !== config.defaultViewport)
    .map((vp) => vp.key)
  if (nonDefault.length < 2) {
    throw new Error(
      `Adapter exposes ${nonDefault.length} non-default viewport(s); need at least 2.`,
    )
  }
  return [nonDefault[0], nonDefault[1]]
}

/**
 * The adapter's display label for a viewport key — the wording the reset menu
 * lists its scopes under. Adapter-driven, so specs stay framework-agnostic.
 */
export function viewportLabel(config: AdapterConfig, key: string): string {
  const viewport = config.viewports.find((vp) => vp.key === key)
  if (viewport === undefined) {
    throw new Error(`Adapter exposes no viewport "${key}".`)
  }
  return viewport.label
}
