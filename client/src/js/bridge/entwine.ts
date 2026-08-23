import { createElement, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'

import GridEditorErrorBoundary from '@/components/GridEditorErrorBoundary/GridEditorErrorBoundary'
import GridQueryProvider from '@/hooks/QueryProvider'
import type { EditorRoot } from '@/types/editorRoot'
import { loadComponent } from './Injector'

interface BridgeSchema {
  root: EditorRoot | null
  readonly: boolean
}

const MOUNT_SELECTOR = '[data-react-mount="grid-editor"]'
const MOUNTED_ATTR = 'data-grid-editor-mounted'

// Track roots keyed by the host element so both the entwine path and the
// MutationObserver path can unmount cleanly without double-mounting. The
// entwine path additionally stores the root via setReactRoot for backwards
// compatibility with any CMS code that might read it, but this map is the
// single source of truth for lifecycle.
const mountedRoots = new WeakMap<HTMLElement, Root>()

// Companion to mountedRoots: WeakMaps aren't iterable, and the Pjax observer
// needs "is this mutation inside an already-mounted editor?" against every
// mounted host. Kept in sync by mountGridEditor/unmountGridEditor.
const mountedHosts = new Set<HTMLElement>()

function parseBridgeData(data: unknown): BridgeSchema {
  const record = (typeof data === 'object' && data !== null ? data : {}) as Record<string, unknown>
  const rawId = record['grid-page-id']
  const readonly = record['grid-readonly'] === true

  if (typeof rawId !== 'number') {
    return { root: null, readonly }
  }

  // A block-rooted editor has no zones and no version — the union has nowhere
  // to put them, which is what retired the empty-zone sentinel this used to
  // have to invent for the 'main' default to skip.
  if (record['grid-root-type'] === 'sharedBlock') {
    return { root: { kind: 'sharedBlock', blockId: rawId }, readonly }
  }

  const rawZone = record['grid-zone']
  const rawVersion = record['grid-version']

  return {
    root: {
      kind: 'page',
      pageId: rawId,
      zone: typeof rawZone === 'string' && rawZone !== '' ? rawZone : 'main',
      version: typeof rawVersion === 'number' ? rawVersion : undefined,
    },
    readonly,
  }
}

/**
 * Read bridge schema from a DOM element. entwine exposes `data-schema` as a
 * JSON blob via SilverStripe's FormField schema mechanism. When entwine is
 * present it parses that JSON for us via `this.data('schema')`; the
 * MutationObserver path has to parse it directly from the attribute.
 */
function readSchemaFromElement(element: HTMLElement): unknown {
  const raw = element.getAttribute('data-schema')
  if (raw === null || raw === '') {
    return null
  }

  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

/**
 * Mount the grid editor into the given element. Idempotent: a second call
 * on an already-mounted element is a no-op.
 */
export function mountGridEditor(element: HTMLElement, schemaData: unknown): void {
  if (mountedRoots.has(element)) {
    return
  }

  try {
    const GridEditor = loadComponent('GridEditor')
    const { root: editorRoot, readonly } = parseBridgeData(schemaData)

    const root = createRoot(element)
    mountedRoots.set(element, root)
    mountedHosts.add(element)
    element.setAttribute(MOUNTED_ATTR, 'true')

    // StrictMode documents the intent to run under React's strict checks, but
    // is inert in the CMS: `react-dom` is externalized to silverstripe/admin's
    // *production* React global, whose reconciler has no double-invoke logic.
    // It only activates if a development React build is ever provided (e.g. the
    // Vitest suite, which uses react-dom.development).
    root.render(
      createElement(
        StrictMode,
        null,
        createElement(
          GridQueryProvider,
          null,
          createElement(
            GridEditorErrorBoundary,
            null,
            createElement(GridEditor, { root: editorRoot, readonly }),
          ),
        ),
      ),
    )
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — surfaces a grid-editor mount failure in the CMS bridge.
    console.warn('[GridEditor] Failed to mount grid editor.', error)
  }
}

/**
 * Unmount the grid editor from the given element if it was previously
 * mounted by this bridge. Safe to call on elements that were never mounted.
 */
export function unmountGridEditor(element: HTMLElement): void {
  const root = mountedRoots.get(element)
  if (root === undefined) {
    return
  }

  mountedRoots.delete(element)
  mountedHosts.delete(element)
  element.removeAttribute(MOUNTED_ATTR)

  // React's synchronous unmount walks the rendered subtree and calls
  // removeChild on nodes that may already be detached (e.g. when a CMS Pjax
  // swap detaches the host before our observer fires). Swallow the jsdom
  // NotFoundError from that cleanup — the root is already gone either way.
  try {
    root.unmount()
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — logs the swallowed unmount error (element already detached).
    console.warn('[GridEditor] Error during unmount (element already detached).', error)
  }
}

/**
 * jQuery entwine bridge that mounts the React grid editor inside CMS pages.
 *
 * Entwine's onmatch/onunmatch hooks fire when the CMS replaces page content
 * via standard AJAX navigation, BUT they do NOT fire for Pjax-loaded content
 * — the `.cms-content` replacement path skips entwine's match scan. To cover
 * both cases we ALSO run a vanilla MutationObserver against document.body
 * that mounts/unmounts on DOM insertion/removal. Both paths call the same
 * `mountGridEditor` helper and de-duplicate via a shared WeakMap so an
 * element loaded through entwine is never double-mounted.
 */
function registerEntwineBridge(): void {
  if (typeof window === 'undefined' || window.jQuery?.entwine === undefined) {
    return
  }

  window.jQuery.entwine('ss', ($) => {
    $(`.js-injector-boot ${MOUNT_SELECTOR}`).entwine({
      onmatch() {
        const element = this[0]
        mountGridEditor(element, this.data('schema'))
        const root = mountedRoots.get(element) ?? null
        this.setReactRoot(root)
      },

      onunmatch() {
        const element = this[0]
        unmountGridEditor(element)
        this.setReactRoot(null)
      },
    })
  })
}

function observeForPjax(): void {
  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
    return
  }

  const handleAdded = (node: Node): void => {
    if (!(node instanceof HTMLElement)) {
      return
    }
    if (node.matches(MOUNT_SELECTOR)) {
      mountGridEditor(node, readSchemaFromElement(node))
    }
    for (const candidate of node.querySelectorAll<HTMLElement>(MOUNT_SELECTOR)) {
      mountGridEditor(candidate, readSchemaFromElement(candidate))
    }
  }

  const handleRemoved = (node: Node): void => {
    if (!(node instanceof HTMLElement)) {
      return
    }
    if (node.matches(MOUNT_SELECTOR)) {
      unmountGridEditor(node)
    }
    for (const candidate of node.querySelectorAll<HTMLElement>(MOUNT_SELECTOR)) {
      unmountGridEditor(candidate)
    }
  }

  const isInsideMountedHost = (node: Node): boolean => {
    for (const host of mountedHosts) {
      if (host.contains(node)) return true
    }
    return false
  }

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      // React-driven churn inside a mounted editor (drag previews, optimistic
      // updates — every commit, ~per pointer move during drags) can never add
      // or remove a mount host, so skip those records instead of subtree-
      // scanning every touched block. Removal of a host itself is still seen:
      // that record's target is the host's PARENT, which is outside the host.
      if (isInsideMountedHost(mutation.target)) continue
      mutation.addedNodes.forEach(handleAdded)
      mutation.removedNodes.forEach(handleRemoved)
    }
  })

  observer.observe(document.body, { childList: true, subtree: true })

  // Initial pass: handle any hosts already present when the bridge loads.
  for (const host of document.querySelectorAll<HTMLElement>(MOUNT_SELECTOR)) {
    mountGridEditor(host, readSchemaFromElement(host))
  }
}

registerEntwineBridge()
observeForPjax()
