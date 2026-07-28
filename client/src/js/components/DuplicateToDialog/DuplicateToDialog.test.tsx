import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createEvent, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StrictMode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/testing/renderWithProviders'

import DuplicateToDialog from './DuplicateToDialog'

function resolveRequestUrl(input: string | URL | Request): string {
  if (typeof input === 'string') return input
  if (input instanceof URL) return input.toString()
  return input.url
}

// jsdom doesn't support native dialog showModal/close — stub them
beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })
})

const PAGES = [
  { id: 1, title: 'Home', parentId: 0, hasGridZones: true },
  { id: 2, title: 'About', parentId: 0, hasGridZones: true },
  { id: 3, title: 'Legacy', parentId: 0, hasGridZones: false },
]

const ZONES = ['main', 'sidebar']

const CONTAINERS = [
  { id: 100, title: 'Row 1', type: 'row' as const },
  { id: 101, title: 'Row 2', type: 'row' as const },
]

/**
 * URL-based fetch mock that routes by API path.
 * Simulates the real API endpoints each query hook calls.
 */
function mockApiRoutes(overrides?: {
  pages?: typeof PAGES
  zones?: string[]
  containers?: typeof CONTAINERS
}) {
  const pages = overrides?.pages ?? PAGES
  const zones = overrides?.zones ?? ZONES
  const containers = overrides?.containers ?? CONTAINERS

  vi.spyOn(globalThis, 'fetch').mockImplementation((input: string | URL | Request) => {
    const url = resolveRequestUrl(input)

    let body: unknown = {}
    if (url.includes('/api/pages')) body = pages
    else if (url.includes('/api/zones/')) body = zones
    else if (url.includes('/api/acceptableContainers/')) body = containers

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: 'OK',
      json: () => Promise.resolve(body),
      headers: new Headers(),
      redirected: false,
      type: 'basic',
      url: '',
      clone() {
        return this
      },
      body: null,
      bodyUsed: false,
      arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
      blob: () => Promise.resolve(new Blob()),
      bytes: () => Promise.resolve(new Uint8Array()),
      formData: () => Promise.resolve(new FormData()),
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response)
  })
}

function renderDialog(overrides: Partial<React.ComponentProps<typeof DuplicateToDialog>> = {}) {
  const props: React.ComponentProps<typeof DuplicateToDialog> = {
    isOpen: true,
    elementType: 'row',
    currentPageId: 1,
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    error: null,
    ...overrides,
  }

  return { ...renderWithProviders(<DuplicateToDialog {...props} />), props }
}

/**
 * Render the dialog with a stable QueryClient so the component can be
 * re-rendered (e.g. toggling `isOpen`) without remounting the provider tree.
 * RTL's own `rerender` only re-renders the bare element passed to `render`,
 * which would drop the providers — so we expose a prop-merging `rerender`.
 */
function renderToggleableDialog(
  overrides: Partial<React.ComponentProps<typeof DuplicateToDialog>> = {},
) {
  const props: React.ComponentProps<typeof DuplicateToDialog> = {
    isOpen: true,
    elementType: 'row',
    currentPageId: 1,
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    error: null,
    ...overrides,
  }

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })

  function renderTree(treeProps: React.ComponentProps<typeof DuplicateToDialog>) {
    return (
      <StrictMode>
        <QueryClientProvider client={queryClient}>
          <DuplicateToDialog {...treeProps} />
        </QueryClientProvider>
      </StrictMode>
    )
  }

  let currentProps = props
  const result = render(renderTree(currentProps))

  function rerender(next: Partial<React.ComponentProps<typeof DuplicateToDialog>>) {
    currentProps = { ...currentProps, ...next }
    result.rerender(renderTree(currentProps))
  }

  return { ...result, rerender, props }
}

async function goToPageStep() {
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-page-list')).toBeInTheDocument()
  })
}

async function selectPageAndAdvance(user: ReturnType<typeof userEvent.setup>, pageTitle = 'About') {
  await goToPageStep()
  await user.click(screen.getByText(pageTitle))
  await user.click(screen.getByTestId('duplicate-to-next'))
}

async function goToZoneStep(user: ReturnType<typeof userEvent.setup>) {
  await selectPageAndAdvance(user)
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
  })
}

async function selectZoneAndAdvance(user: ReturnType<typeof userEvent.setup>, zone = 'main') {
  await goToZoneStep(user)
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
  })
  await user.click(screen.getByText(zone))
  await user.click(screen.getByTestId('duplicate-to-next'))
}

async function goToContainerStep(user: ReturnType<typeof userEvent.setup>) {
  await selectZoneAndAdvance(user)
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument()
  })
}

describe('DuplicateToDialog', () => {
  describe('page step', () => {
    it('renders page list with pages from API', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      expect(screen.getAllByTestId('duplicate-to-page-item')).toHaveLength(3)
      expect(screen.getByText('Home')).toBeInTheDocument()
      expect(screen.getByText('About')).toBeInTheDocument()
      expect(screen.getByText('Legacy')).toBeInTheDocument()
    })

    it('shows title "Select target page"', () => {
      mockApiRoutes()
      renderDialog()

      expect(screen.getByText('Select target page')).toBeInTheDocument()
    })

    it('selects a page on click and highlights it', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToPageStep()
      await user.click(screen.getByText('About'))

      expect(screen.getByText('About').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })

    it('marks pages without grid zones as disabled', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      const legacyItem = screen.getByText('Legacy').closest('[role="option"]')
      expect(legacyItem).toHaveAttribute('aria-disabled', 'true')
    })

    it('disabled pages cannot be selected', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      // Select About first, then try to click Legacy (disabled)
      await user.click(screen.getByText('About'))
      await user.click(screen.getByText('Legacy'))

      // About should still be selected
      expect(screen.getByText('About').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })

    it('Next button advances to zone step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await selectPageAndAdvance(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })
      expect(screen.getByText('Select zone')).toBeInTheDocument()
    })

    it('does not show Back button on page step', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      expect(screen.queryByTestId('duplicate-to-back')).not.toBeInTheDocument()
    })

    it('search debounces and updates page list', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      const searchInput = screen.getByTestId('duplicate-to-search')
      await user.type(searchInput, 'About')

      // Wait for debounce to trigger new fetch
      await waitFor(() => {
        const calls = vi.mocked(globalThis.fetch).mock.calls
        const pageSearchCalls = calls.filter(
          ([url]) =>
            typeof url === 'string' && url.includes('/api/pages') && url.includes('search='),
        )
        expect(pageSearchCalls.length).toBeGreaterThan(0)
      })
    })
  })

  describe('zone step', () => {
    it('renders zone list when multiple zones exist', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      expect(screen.getAllByTestId('duplicate-to-zone-item')).toHaveLength(2)
      expect(screen.getByText('main')).toBeInTheDocument()
      expect(screen.getByText('sidebar')).toBeInTheDocument()
    })

    it('selects zone on click and highlights it', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('sidebar'))

      expect(screen.getByText('sidebar').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })

    it('Next button is disabled until zone is selected', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      const nextBtn = screen.getByTestId('duplicate-to-next')
      expect(nextBtn).toBeDisabled()
    })

    it('auto-advances when only one zone exists', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ zones: ['main'] })
      renderDialog()

      await selectPageAndAdvance(user)

      // Should skip zone step and go to container step directly
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument()
      })
    })

    it('auto-advances section type to confirm step when single zone', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ zones: ['main'] })
      renderDialog({ elementType: 'section' })

      await selectPageAndAdvance(user)

      // Sections skip container step entirely
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })
    })

    it('Back button returns to page step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      await user.click(screen.getByTestId('duplicate-to-back'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument()
      })
    })

    it('Next button advances non-section to container step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument()
      })
    })

    it('Next button advances section to confirm step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })
    })
  })

  describe('container step', () => {
    it('renders container list from API', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      expect(screen.getAllByTestId('duplicate-to-container-item')).toHaveLength(2)
      expect(screen.getByText('Row 1')).toBeInTheDocument()
    })

    it('selects container on click', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('Row 1'))

      expect(screen.getByText('Row 1').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })

    it('Confirm button disabled until container selected', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-confirm')).toBeInTheDocument()
      })

      expect(screen.getByTestId('duplicate-to-confirm')).toBeDisabled()
    })

    it('shows empty state when no containers found', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ containers: [] })
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-no-containers')).toBeInTheDocument()
      })

      expect(screen.getByText('No compatible containers found in this zone')).toBeInTheDocument()
    })

    it('Back button returns to zone step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await user.click(screen.getByTestId('duplicate-to-back'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })
    })

    it('Confirm calls onConfirm with selected page, zone, and container', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      const onConfirm = vi.fn()
      renderDialog({ elementType: 'row', onConfirm })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('Row 1'))
      await user.click(screen.getByTestId('duplicate-to-confirm'))

      // Row element → target parent type is 'section'.
      expect(onConfirm).toHaveBeenCalledWith(2, 'main', { type: 'section', id: 100 })
    })
  })

  describe('confirm step (section path)', () => {
    it('shows confirmation summary for section', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })

      expect(screen.getByText('Confirm duplication')).toBeInTheDocument()
      // The summary prefix is the t() fallback text (not the key, which the
      // i18n ignorer covers) — assert it renders so an emptied fallback fails.
      expect(screen.getByText(/Duplicate section to zone/)).toBeInTheDocument()
    })

    it('Confirm calls onConfirm with page as parent for sections', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      const onConfirm = vi.fn()
      renderDialog({ elementType: 'section', onConfirm })

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-confirm')).toBeInTheDocument()
      })

      await user.click(screen.getByTestId('duplicate-to-confirm'))

      // Section: target parent is the page itself.
      expect(onConfirm).toHaveBeenCalledWith(2, 'main', { type: 'page', id: 2 })
    })

    it('Back button from confirm returns to page step when there is only one zone', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ zones: ['main'] })
      renderDialog({ elementType: 'section' })

      // Navigate to confirm step (single-zone auto-advances from page -> confirm)
      await selectPageAndAdvance(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })

      await user.click(screen.getByTestId('duplicate-to-back'))

      // Must land on page step — zone would immediately auto-advance again
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument()
      })
    })

    it('Back button from confirm returns to zone step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      // Navigate to confirm step
      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })

      await user.click(screen.getByTestId('duplicate-to-back'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })
    })
  })

  describe('keyboard navigation', () => {
    it('selects page via Enter key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      const aboutItem = screen.getByText('About').closest('[role="option"]')!
      await user.type(aboutItem, '{Enter}')

      expect(aboutItem).toHaveAttribute('aria-selected', 'true')
    })

    it('selects page via Space key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      const aboutItem = screen.getByText('About').closest('[role="option"]')!
      await user.type(aboutItem, ' ')

      expect(aboutItem).toHaveAttribute('aria-selected', 'true')
    })

    it('selects zone via Enter key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!
      await user.type(sidebarItem, '{Enter}')

      expect(sidebarItem).toHaveAttribute('aria-selected', 'true')
    })

    it('selects zone via Space key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!
      await user.type(sidebarItem, ' ')

      expect(sidebarItem).toHaveAttribute('aria-selected', 'true')
    })

    it('selects container via Enter key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!
      await user.type(row1Item, '{Enter}')

      expect(row1Item).toHaveAttribute('aria-selected', 'true')
    })

    it('selects container via Space key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!
      await user.type(row1Item, ' ')

      expect(row1Item).toHaveAttribute('aria-selected', 'true')
    })

    it('calls preventDefault on Space for a page option (no scroll / stray submit)', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      const aboutItem = screen.getByText('About').closest('[role="option"]')!
      const spaceEvent = createEvent.keyDown(aboutItem, { key: ' ' })
      fireEvent(aboutItem, spaceEvent)

      expect(spaceEvent.defaultPrevented).toBe(true)
    })

    it('calls preventDefault on Space for a zone option', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!
      const spaceEvent = createEvent.keyDown(sidebarItem, { key: ' ' })
      fireEvent(sidebarItem, spaceEvent)

      expect(spaceEvent.defaultPrevented).toBe(true)
    })

    it('calls preventDefault on Space for a container option', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!
      const spaceEvent = createEvent.keyDown(row1Item, { key: ' ' })
      fireEvent(row1Item, spaceEvent)

      expect(spaceEvent.defaultPrevented).toBe(true)
    })
  })

  describe('loading states', () => {
    it('shows "Loading pages..." before page data arrives', () => {
      // Fetch that never resolves simulates loading
      vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))
      renderDialog()

      expect(screen.getByTestId('duplicate-to-loading')).toBeInTheDocument()
      expect(screen.getByTestId('duplicate-to-loading')).toHaveTextContent(/Loading pages/)
    })

    it('shows "Loading zones..." before zone data arrives', async () => {
      const user = userEvent.setup()

      function createUrlResponse(body: unknown): Response {
        return {
          ok: true,
          status: 200,
          statusText: 'OK',
          json: () => Promise.resolve(body),
          headers: new Headers(),
          redirected: false,
          type: 'basic' as ResponseType,
          url: '',
          clone() {
            return this
          },
          body: null,
          bodyUsed: false,
          arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
          blob: () => Promise.resolve(new Blob()),
          bytes: () => Promise.resolve(new Uint8Array()),
          formData: () => Promise.resolve(new FormData()),
          text: () => Promise.resolve(JSON.stringify(body)),
        } as Response
      }

      vi.spyOn(globalThis, 'fetch').mockImplementation((input: string | URL | Request) => {
        const url = resolveRequestUrl(input)

        if (url.includes('/api/pages')) {
          return Promise.resolve(createUrlResponse(PAGES))
        }

        // Zones never resolve
        return new Promise(() => {})
      })

      renderDialog()

      await goToPageStep()
      await user.click(screen.getByText('About'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })

      expect(screen.getByTestId('duplicate-to-loading')).toBeInTheDocument()
      expect(screen.getByTestId('duplicate-to-loading')).toHaveTextContent(/Loading zones/)
    })

    it('shows "Loading containers..." before container data arrives', async () => {
      const user = userEvent.setup()

      function createUrlResponse(body: unknown): Response {
        return {
          ok: true,
          status: 200,
          statusText: 'OK',
          json: () => Promise.resolve(body),
          headers: new Headers(),
          redirected: false,
          type: 'basic' as ResponseType,
          url: '',
          clone() {
            return this
          },
          body: null,
          bodyUsed: false,
          arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
          blob: () => Promise.resolve(new Blob()),
          bytes: () => Promise.resolve(new Uint8Array()),
          formData: () => Promise.resolve(new FormData()),
          text: () => Promise.resolve(JSON.stringify(body)),
        } as Response
      }

      vi.spyOn(globalThis, 'fetch').mockImplementation((input: string | URL | Request) => {
        const url = resolveRequestUrl(input)

        if (url.includes('/api/pages')) return Promise.resolve(createUrlResponse(PAGES))
        if (url.includes('/api/zones/')) return Promise.resolve(createUrlResponse(ZONES))

        // Containers never resolve
        return new Promise(() => {})
      })

      renderDialog({ elementType: 'row' })

      await goToPageStep()
      await user.click(screen.getByText('About'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument()
      })

      expect(screen.getByTestId('duplicate-to-loading')).toBeInTheDocument()
      expect(screen.getByTestId('duplicate-to-loading')).toHaveTextContent(/Loading containers/)
    })
  })

  describe('step titles', () => {
    it('shows "Select target page" on page step', () => {
      mockApiRoutes()
      renderDialog()

      expect(screen.getByText('Select target page')).toBeInTheDocument()
    })

    it('shows "Select zone" on zone step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await selectPageAndAdvance(user)

      await waitFor(() => {
        expect(screen.getByText('Select zone')).toBeInTheDocument()
      })
    })

    it('shows "Select container" on container step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      expect(screen.getByText('Select container')).toBeInTheDocument()
    })

    it('shows "Confirm duplication" on confirm step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByText('Confirm duplication')).toBeInTheDocument()
      })
    })
  })

  describe('back button clears selections', () => {
    it('going back from zone clears selectedZone', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      // Select a zone
      await user.click(screen.getByText('sidebar'))
      expect(screen.getByText('sidebar').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )

      // Go back
      await user.click(screen.getByTestId('duplicate-to-back'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument()
      })

      // Advance again — zone should not be pre-selected
      await user.click(screen.getByText('About'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      // Next button should be disabled (no zone selected)
      expect(screen.getByTestId('duplicate-to-next')).toBeDisabled()
    })

    it('going back from container clears selectedContainerId', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      // Select a container
      await user.click(screen.getByText('Row 1'))
      expect(screen.getByText('Row 1').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )

      // Go back to zone
      await user.click(screen.getByTestId('duplicate-to-back'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })

      // Go forward again
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      // Confirm should be disabled (no container selected after back)
      expect(screen.getByTestId('duplicate-to-confirm')).toBeDisabled()
    })
  })

  describe('container item metadata', () => {
    it('displays container type alongside title', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      // Each container shows both title and type
      expect(screen.getByText('Row 1')).toBeInTheDocument()
      expect(screen.getAllByText('row')).toHaveLength(2)
    })
  })

  describe('confirm step summary', () => {
    it('shows selected zone name in summary', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('sidebar'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })

      const summary = screen.getByText(/Duplicate section to zone/)
      expect(summary).toBeInTheDocument()
      expect(screen.getByText('sidebar')).toBeInTheDocument()
    })
  })

  describe('initial state', () => {
    it('starts with an empty search field', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      // L41 searchTerm initial value: a non-empty initial would prefill the input.
      const searchInput = screen.getByTestId<HTMLInputElement>('duplicate-to-search')
      expect(searchInput).toHaveValue('')
    })

    it('issues the initial page query without a search term', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      // L42 debouncedSearch initial value feeds usePages(): a non-empty initial
      // would attach a `search=` param to the very first /api/pages request.
      const pageCalls = vi
        .mocked(globalThis.fetch)
        .mock.calls.map(([input]) => resolveRequestUrl(input))
        .filter((url) => url.includes('/api/pages'))

      expect(pageCalls.length).toBeGreaterThan(0)
      for (const url of pageCalls) {
        expect(url).not.toContain('search=')
      }
    })
  })

  describe('dialog open/close behavior', () => {
    it('opens the native dialog when isOpen is true', async () => {
      mockApiRoutes()
      renderDialog({ isOpen: true })

      // L68 showModal effect body: emptying it leaves the <dialog> closed.
      await waitFor(() => {
        expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled()
      })
      expect(screen.getByTestId('duplicate-to-dialog')).toHaveAttribute('open')
    })

    it('closes the native dialog when isOpen flips to false', async () => {
      mockApiRoutes()
      const { rerender } = renderToggleableDialog({ isOpen: true })

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-dialog')).toHaveAttribute('open')
      })

      rerender({ isOpen: false })

      await waitFor(() => {
        expect(HTMLDialogElement.prototype.close).toHaveBeenCalled()
      })
      expect(screen.getByTestId('duplicate-to-dialog')).not.toHaveAttribute('open')
    })
  })

  describe('reset on reopen', () => {
    it('returns to the page step and clears state when reopened', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      const { rerender } = renderToggleableDialog()

      // Navigate forward and type a search term.
      await goToPageStep()
      await user.type(screen.getByTestId('duplicate-to-search'), 'About')
      await user.click(screen.getByText('About'))
      await user.click(screen.getByTestId('duplicate-to-next'))
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })

      // Close, then reopen (component stays mounted — dialog.close only hides it).
      rerender({ isOpen: false })
      rerender({ isOpen: true })

      // L57 reset block / L58 isOpen guard / L60-L61 search resets:
      // reopening must drop us back on the page step with a cleared search.
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument()
      })
      expect(screen.getByTestId('duplicate-to-search')).toHaveValue('')
    })

    it('keeps the current step while isOpen stays true', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      const { rerender } = renderToggleableDialog()

      await goToZoneStep(user)

      // Re-render without changing isOpen — the reset must NOT fire, so we stay
      // on the zone step. (Distinguishes L58 isOpen guard forced to `true`.)
      rerender({ isOpen: true })

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
    })
  })

  describe('multi-zone does not auto-advance', () => {
    it('stays on the zone step when more than one zone exists', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ zones: ['main', 'sidebar'] })
      renderDialog({ elementType: 'row' })

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      // L108 auto-advance guard forced true (or its length===1 boundary):
      // with two zones we must remain on the zone step, never jumping to
      // container, and no zone is pre-selected.
      expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      expect(screen.queryByTestId('duplicate-to-step-container')).not.toBeInTheDocument()
      expect(screen.getByTestId('duplicate-to-next')).toBeDisabled()
    })
  })

  describe('only the active step renders', () => {
    it('hides page-step content once on the zone step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      // L195 `step === 'page'` forced true would keep the page step mounted.
      expect(screen.queryByTestId('duplicate-to-step-page')).not.toBeInTheDocument()
    })

    it('hides container-step content while on the zone step', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToZoneStep(user)

      // L288 `step === 'container'` forced true would mount the container step early.
      expect(screen.queryByTestId('duplicate-to-step-container')).not.toBeInTheDocument()
    })
  })

  describe('labels and placeholders', () => {
    it('renders the search placeholder text', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      // L203 placeholder fallback.
      expect(screen.getByTestId('duplicate-to-search')).toHaveAttribute(
        'placeholder',
        'Search pages…',
      )
    })

    it('labels the page-step primary button "Next"', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      // L382 Next-button fallback (page step).
      expect(screen.getByTestId('duplicate-to-next')).toHaveTextContent('Next')
    })

    it('labels the Back and zone-step Next buttons', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)

      // L364 Back-button fallback, L393 zone-step Next fallback.
      expect(screen.getByTestId('duplicate-to-back')).toHaveTextContent('Back')
      expect(screen.getByTestId('duplicate-to-next')).toHaveTextContent('Next')
    })

    it('labels the container-step primary button "Confirm"', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-confirm')).toBeInTheDocument()
      })

      // L404 container-step Confirm fallback.
      expect(screen.getByTestId('duplicate-to-confirm')).toHaveTextContent('Confirm')
    })

    it('labels the confirm-step primary button "Confirm" and shows the summary prefix', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })

      // L343 summary-prefix fallback, L414 confirm-step Confirm fallback.
      expect(screen.getByTestId('duplicate-to-step-confirm')).toHaveTextContent(
        'Duplicate section to zone',
      )
      expect(screen.getByTestId('duplicate-to-confirm')).toHaveTextContent('Confirm')
    })
  })

  describe('selection highlighting is exclusive', () => {
    it('marks only the clicked page as selected', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToPageStep()
      await user.click(screen.getByText('About'))

      // L224 `page.id === selectedPageId` forced true would select every page.
      expect(screen.getByText('Home').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'false',
      )
      expect(screen.getByText('About').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })

    it('marks only the clicked zone as selected', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('sidebar'))

      // L269 `zone === selectedZone` forced true would select every zone.
      expect(screen.getByText('main').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'false',
      )
      expect(screen.getByText('sidebar').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })

    it('marks only the clicked container as selected', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('Row 1'))

      // L317 `container.id === selectedContainerId` forced true would select all.
      expect(screen.getByText('Row 2').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'false',
      )
      expect(screen.getByText('Row 1').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
    })
  })

  describe('keyboard selection ignores unrelated keys', () => {
    it('does not select a page on an unrelated key', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()
      const aboutItem = screen.getByText('About').closest('[role="option"]')!
      fireEvent.keyDown(aboutItem, { key: 'a' })

      // L231 keydown guard: only Enter / Space select; 'a' must not.
      expect(aboutItem).toHaveAttribute('aria-selected', 'false')
    })

    it('does not select a zone on an unrelated key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!
      fireEvent.keyDown(sidebarItem, { key: 'a' })

      // L273 keydown guard.
      expect(sidebarItem).toHaveAttribute('aria-selected', 'false')
    })

    it('does not select a container on an unrelated key', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })
      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!
      fireEvent.keyDown(row1Item, { key: 'a' })

      // L321 keydown guard.
      expect(row1Item).toHaveAttribute('aria-selected', 'false')
    })
  })

  describe('disabled page focusability and gating', () => {
    it('keeps disabled pages out of the tab order', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      // L241 tabIndex `-1` for pages without grid zones.
      const legacyItem = screen.getByText('Legacy').closest('[role="option"]')
      expect(legacyItem).toHaveAttribute('tabindex', '-1')
      const aboutItem = screen.getByText('About').closest('[role="option"]')
      expect(aboutItem).toHaveAttribute('tabindex', '0')
    })

    it('disables Next while the selected page id is 0', async () => {
      mockApiRoutes()
      renderDialog({ currentPageId: 0 })

      await goToPageStep()

      // L379 `disabled={selectedPageId === 0}` — forced false would enable it.
      expect(screen.getByTestId('duplicate-to-next')).toBeDisabled()
    })
  })

  describe('container empty-state boundary', () => {
    it('shows the list and no empty-state when containers exist', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      // L298 empty-state guard: must NOT show when containers exist.
      expect(screen.queryByTestId('duplicate-to-no-containers')).not.toBeInTheDocument()
    })

    it('shows neither list nor items when there are no containers', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ containers: [] })
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-no-containers')).toBeInTheDocument()
      })

      // L306 `length > 0` guard: an empty list must NOT render the list/items.
      expect(screen.queryByTestId('duplicate-to-container-list')).not.toBeInTheDocument()
      expect(screen.queryByTestId('duplicate-to-container-item')).not.toBeInTheDocument()
    })
  })

  describe('dialog click guard', () => {
    it('prevents default and stops propagation on clicks inside the dialog', () => {
      mockApiRoutes()
      renderDialog()

      const dialog = screen.getByTestId('duplicate-to-dialog')
      const clickEvent = createEvent.click(dialog)
      fireEvent(dialog, clickEvent)

      // L177 onClick body: both preventDefault and stopPropagation must run so
      // the click does not bubble to an ancestor ElementCard anchor.
      expect(clickEvent.defaultPrevented).toBe(true)
    })
  })

  describe('error and cancel', () => {
    it('displays error message when error prop is set', () => {
      mockApiRoutes()
      renderDialog({ error: 'Something went wrong' })

      expect(screen.getByTestId('duplicate-to-error')).toHaveTextContent('Something went wrong')
    })

    it('does not show error when error is null', () => {
      mockApiRoutes()
      renderDialog({ error: null })

      expect(screen.queryByTestId('duplicate-to-error')).not.toBeInTheDocument()
    })

    it('onCancel called when cancel button clicked', async () => {
      const user = userEvent.setup()
      const onCancel = vi.fn()
      mockApiRoutes()
      renderDialog({ onCancel })

      await user.click(screen.getByText('Cancel'))

      expect(onCancel).toHaveBeenCalledOnce()
    })

    it('hides the error box when no error prop is supplied', () => {
      mockApiRoutes()
      // Omit `error` entirely so it is `undefined` (not `null`). The footer guard
      // is `error !== undefined && error !== null`; the left half is the only
      // thing that suppresses the box in this case — dropping it (mutating
      // `error !== undefined` to `true`) would render `<p>{undefined}</p>` with
      // the error testid present. null vs undefined both hide originally, so
      // only the undefined case discriminates the left operand.
      renderDialog({ error: undefined })

      expect(screen.queryByTestId('duplicate-to-error')).not.toBeInTheDocument()
    })
  })

  describe('keyboard selection via direct keydown (isolates the keydown branch)', () => {
    // The committed Enter tests drive `user.type(item, '{Enter}')`, which
    // userEvent routes through the option's `onClick` as well — so they pass
    // even when the keydown handler's Enter arm is broken. Firing keydown
    // directly (no synthetic click) exercises only the `e.key === 'Enter'`
    // branch, killing both `'Enter' -> ''` (StringLiteral) and
    // `e.key === 'Enter' -> false` (ConditionalExpression) on each option.

    it('selects a page when Enter is pressed (no click fallback)', async () => {
      mockApiRoutes()
      renderDialog()

      await goToPageStep()

      const aboutItem = screen.getByText('About').closest('[role="option"]')!
      fireEvent.keyDown(aboutItem, { key: 'Enter' })

      expect(aboutItem).toHaveAttribute('aria-selected', 'true')
    })

    it('selects a zone when Enter is pressed (no click fallback)', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog()

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })

      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!
      fireEvent.keyDown(sidebarItem, { key: 'Enter' })

      expect(sidebarItem).toHaveAttribute('aria-selected', 'true')
    })

    it('selects a container when Enter is pressed (no click fallback)', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'row' })

      await goToContainerStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument()
      })

      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!
      fireEvent.keyDown(row1Item, { key: 'Enter' })

      expect(row1Item).toHaveAttribute('aria-selected', 'true')
    })
  })

  describe('reset guard only fires while the dialog is open', () => {
    it('keeps the initial empty search when the dialog mounts closed', async () => {
      mockApiRoutes()
      // With isOpen=false the reset effect's body is skipped, so the initial
      // useState value for searchTerm is observable in the input. The reset
      // effect (which runs on isOpen=true) otherwise masks the initial value by
      // setting it to ''. Mutating `useState('')` to a non-empty literal would
      // pre-fill the input here.
      renderDialog({ isOpen: false })

      await goToPageStep()

      expect(screen.getByTestId<HTMLInputElement>('duplicate-to-search')).toHaveValue('')
    })

    it('does not reset the step when a closed dialog’s source page changes', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      // Open=false throughout: navigate to the zone step, then change
      // currentPageId. The reset effect depends on [isOpen, currentPageId], so
      // the page change re-runs it — but its body is guarded by `if (isOpen)`.
      // Dropping that guard (mutating to `if (true)`) would reset us back to the
      // page step. The guard keeps a closed dialog's in-progress step intact.
      const { rerender } = renderToggleableDialog({ isOpen: false, elementType: 'row' })

      await goToPageStep()
      await user.click(screen.getByText('About'))
      await user.click(screen.getByTestId('duplicate-to-next'))
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })

      rerender({ currentPageId: 2 })

      // Give the reset effect a chance to run (it would on the mutant).
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      })
      expect(screen.queryByTestId('duplicate-to-step-page')).not.toBeInTheDocument()
    })
  })

  describe('zone list rendering boundary', () => {
    it('does not render the zone list when the page has no zones', async () => {
      const user = userEvent.setup()
      mockApiRoutes({ zones: [] })
      renderDialog({ elementType: 'row' })

      await goToZoneStep(user)

      // An empty zone set never trips the auto-advance guard (length === 1), so
      // we linger on the zone step. Wait for the zones query to resolve so the
      // list guard is evaluated against real (empty) data, not `undefined`.
      await waitFor(() => {
        expect(screen.queryByTestId('duplicate-to-loading')).not.toBeInTheDocument()
      })

      // L253 list guard `zones.data.length > 1`: mutating the length check to
      // `true` (dropping it) would render an empty zone-list container here.
      // With zero zones the list and its items must stay absent.
      expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument()
      expect(screen.queryByTestId('duplicate-to-zone-list')).not.toBeInTheDocument()
      expect(screen.queryByTestId('duplicate-to-zone-item')).not.toBeInTheDocument()
    })
  })

  describe('confirm summary spacing', () => {
    it('separates the summary prefix from the zone name with a space', async () => {
      const user = userEvent.setup()
      mockApiRoutes()
      renderDialog({ elementType: 'section' })

      await goToZoneStep(user)
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument()
      })
      await user.click(screen.getByText('main'))
      await user.click(screen.getByTestId('duplicate-to-next'))

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument()
      })

      // L338 `{' '}` separates the prefix from the <strong>zone</strong>.
      // Mutating it to `{""}` collapses "zone main?" into "zonemain?".
      expect(screen.getByTestId('duplicate-to-step-confirm')).toHaveTextContent(
        'Duplicate section to zone main?',
      )
    })
  })
})
