import { createElement, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import * as v from 'valibot'

import AddSharedBlockButton from '@/components/AddSharedBlockButton/AddSharedBlockButton'
import GridQueryProvider from '@/hooks/QueryProvider'
import type { AllowedTypeInfo } from '@/types/elements'
import { allowedTypeMapSchema } from '@/types/schemas'

const MOUNT_SELECTOR = '[data-grid-add-shared-block]'

const mountedRoots = new Map<HTMLElement, Root>()

/**
 * The element types a leaf-rooted block may be seeded with, server-rendered
 * onto the mount point by GridFieldAddSharedBlockButton.
 *
 * The payload is base64: element types are keyed by class name, and the
 * template layer resolves the `\\` escapes JSON writes those namespaces with,
 * so raw JSON arrives with single backslashes and parses to nothing. Decoded
 * through TextDecoder rather than atob alone, since the labels are translated
 * and may carry non-ASCII.
 *
 * A malformed payload degrades to an empty picker rather than an unmounted
 * button: the three container shapes still work, and the console line names the
 * cause.
 */
function readLeafTypes(element: HTMLElement): Record<string, AllowedTypeInfo> {
  const raw = element.dataset['gridLeafTypes'] ?? ''

  if (raw === '') {
    return {}
  }

  try {
    const bytes = Uint8Array.from(atob(raw), (character) => character.charCodeAt(0))

    return v.parse(allowedTypeMapSchema, JSON.parse(new TextDecoder().decode(bytes)))
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — the element-type list could not be read, so the picker will be empty.
    console.warn('[GridEditor] Could not read the shared block element types.', error)
    return {}
  }
}

/** Idempotent: a second call on an already-mounted element is a no-op. */
export function mountAddSharedBlockButton(element: HTMLElement): void {
  if (mountedRoots.has(element)) {
    return
  }

  const root = createRoot(element)
  mountedRoots.set(element, root)

  // Replaces the server-rendered fallback link the template ships, which is
  // what the author gets if this bundle never loads.
  root.render(
    createElement(
      StrictMode,
      null,
      createElement(
        GridQueryProvider,
        null,
        createElement(AddSharedBlockButton, { leafTypes: readLeafTypes(element) }),
      ),
    ),
  )
}

/** Safe to call on elements this bridge never mounted. */
export function unmountAddSharedBlockButton(element: HTMLElement): void {
  const root = mountedRoots.get(element)

  if (root === undefined) {
    return
  }

  mountedRoots.delete(element)

  try {
    root.unmount()
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — logs the swallowed unmount error (element already detached by a Pjax swap).
    console.warn('[GridEditor] Error during unmount (element already detached).', error)
  }
}

/**
 * Mount the library's add control wherever the CMS renders it.
 *
 * A MutationObserver rather than entwine: the block library is a ModelAdmin
 * screen whose form arrives through Pjax, and entwine's onmatch does not fire
 * for that path.
 */
export function registerAddSharedBlockBridge(): void {
  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
    return
  }

  const handle = (node: Node, action: (element: HTMLElement) => void): void => {
    if (!(node instanceof HTMLElement)) {
      return
    }

    if (node.matches(MOUNT_SELECTOR)) {
      action(node)
    }

    for (const candidate of node.querySelectorAll<HTMLElement>(MOUNT_SELECTOR)) {
      action(candidate)
    }
  }

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach((node) => {
        handle(node, mountAddSharedBlockButton)
      })
      mutation.removedNodes.forEach((node) => {
        handle(node, unmountAddSharedBlockButton)
      })
    }
  })

  observer.observe(document.body, { childList: true, subtree: true })

  // The listing may already be on the page when this bundle finishes loading.
  for (const host of document.querySelectorAll<HTMLElement>(MOUNT_SELECTOR)) {
    mountAddSharedBlockButton(host)
  }
}
