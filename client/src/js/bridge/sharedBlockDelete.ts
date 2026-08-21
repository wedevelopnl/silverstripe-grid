import { createElement, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'

import SharedBlockDeleteDialog from '@/components/SharedBlockDeleteDialog/SharedBlockDeleteDialog'
import GridQueryProvider from '@/hooks/QueryProvider'

const TRIGGER_SELECTOR = '[data-grid-shared-block-delete]'
const HOST_ID = 'ssgrid-shared-block-delete-host'

let host: HTMLElement | null = null
let root: Root | null = null

interface Trigger {
  readonly blockId: number
  readonly blockTitle: string
  readonly returnLink: string
}

function readTrigger(button: HTMLElement): Trigger | null {
  const blockId = Number.parseInt(button.dataset['gridSharedBlockDelete'] ?? '', 10)

  if (!Number.isInteger(blockId) || blockId < 1) {
    return null
  }

  return {
    blockId,
    blockTitle: button.dataset['gridSharedBlockTitle'] ?? '',
    returnLink: button.dataset['gridSharedBlockReturn'] ?? '',
  }
}

/**
 * The dialog lives on document.body rather than inside the CMS form: a
 * SilverStripe form is replaced wholesale by Pjax navigation, which would tear
 * the React root out mid-request while a delete is in flight.
 */
function ensureHost(): HTMLElement {
  if (host !== null && host.isConnected) {
    return host
  }

  host = document.createElement('div')
  host.id = HOST_ID
  document.body.appendChild(host)

  return host
}

export function closeSharedBlockDeleteDialog(): void {
  root?.unmount()
  root = null
  host?.remove()
  host = null
}

export function openSharedBlockDeleteDialog(trigger: Trigger): void {
  closeSharedBlockDeleteDialog()

  root = createRoot(ensureHost())
  root.render(
    createElement(
      StrictMode,
      null,
      createElement(
        GridQueryProvider,
        null,
        createElement(SharedBlockDeleteDialog, {
          isOpen: true,
          blockId: trigger.blockId,
          blockTitle: trigger.blockTitle,
          onCancel: closeSharedBlockDeleteDialog,
          onDeleted: () => {
            closeSharedBlockDeleteDialog()

            // The record the form is editing no longer exists, so staying put
            // would leave the author on a form that 404s on save.
            if (trigger.returnLink !== '') {
              window.location.assign(trigger.returnLink)
            } else {
              window.location.reload()
            }
          },
        }),
      ),
    ),
  )
}

/**
 * Bind the library's delete button to the dialog.
 *
 * Delegated from the document so it survives every CMS navigation: the button
 * is server-rendered into a form that Pjax swaps in and out, and a per-element
 * listener would have to be re-attached on each swap.
 */
export function registerSharedBlockDeleteBridge(): void {
  if (typeof document === 'undefined') {
    return
  }

  document.addEventListener('click', (event: MouseEvent) => {
    const target = event.target
    if (!(target instanceof Element)) {
      return
    }

    const button = target.closest<HTMLElement>(TRIGGER_SELECTOR)
    if (button === null) {
      return
    }

    const trigger = readTrigger(button)
    if (trigger === null) {
      return
    }

    // The button sits in a CMS form; without this the click submits it.
    event.preventDefault()
    openSharedBlockDeleteDialog(trigger)
  })
}
