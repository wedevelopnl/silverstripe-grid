import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { createResponse, getFetchCalls } from '@/testing/mockFetch'
import { createTestQueryClient } from '@/testing/renderWithProviders'
import SharedBlockDeleteDialog from './SharedBlockDeleteDialog'

function resolveRequestUrl(input: string | URL | Request): string {
  if (typeof input === 'string') return input
  if (input instanceof URL) return input.toString()
  return input.url
}

function mockRoutes(options?: {
  usage?: { usageCount: number; liveUsageCount: number }
  usageStatus?: number
  deleteStatus?: number
}) {
  const usage = options?.usage ?? { usageCount: 3, liveUsageCount: 2 }

  vi.spyOn(globalThis, 'fetch').mockImplementation((input: string | URL | Request) => {
    const url = resolveRequestUrl(input)

    if (url.includes('/admin/grid-shared-blocks/api/usage/')) {
      return Promise.resolve(createResponse({ status: options?.usageStatus ?? 200, body: usage }))
    }

    return Promise.resolve(createResponse({ status: options?.deleteStatus ?? 204 }))
  })
}

function renderDialog(overrides?: { onDeleted?: () => void; onCancel?: () => void }) {
  const onDeleted = overrides?.onDeleted ?? vi.fn()
  const onCancel = overrides?.onCancel ?? vi.fn()

  const client = createTestQueryClient()

  render(
    <QueryClientProvider client={client}>
      <SharedBlockDeleteDialog
        isOpen
        blockId={7}
        blockTitle="Promo banner"
        onCancel={onCancel}
        onDeleted={onDeleted}
      />
    </QueryClientProvider>,
  )

  return { onDeleted, onCancel }
}

function deleteRequests() {
  return getFetchCalls().filter(([input, init]) => {
    const url = resolveRequestUrl(input)
    return url.includes('/admin/grid-shared-blocks/api/delete') || init?.method === 'DELETE'
  })
}

describe('SharedBlockDeleteDialog', () => {
  it('names the dialog by its heading', async () => {
    mockRoutes()
    renderDialog()

    expect(
      await screen.findByRole('dialog', { name: 'Delete "Promo banner"?' }),
    ).toBeInTheDocument()
  })

  it('reports how many pages place the block and how many are published', async () => {
    mockRoutes({ usage: { usageCount: 7, liveUsageCount: 4 } })
    renderDialog()

    const summary = await screen.findByTestId('shared-block-delete-usage')
    expect(summary.textContent).toContain('7')
    expect(summary.textContent).toContain('4')
  })

  it('keeps the delete disabled until a mode is chosen for a placed block', async () => {
    mockRoutes()
    renderDialog()

    const confirm = await screen.findByTestId('shared-block-delete-confirm')
    expect(confirm).toBeDisabled()

    await userEvent.click(screen.getByRole('radio', { name: /remove it/i }))
    expect(confirm).toBeEnabled()
  })

  it('sends the remove mode when the author chooses to drop the content', async () => {
    mockRoutes()
    const { onDeleted } = renderDialog()

    await screen.findByTestId('shared-block-delete-usage')
    await userEvent.click(screen.getByRole('radio', { name: /remove it/i }))
    await userEvent.click(screen.getByTestId('shared-block-delete-confirm'))

    await waitFor(() => expect(onDeleted).toHaveBeenCalledOnce())

    const [input] = deleteRequests()[0]
    expect(resolveRequestUrl(input)).toContain('mode=remove')
    expect(resolveRequestUrl(input)).toContain('blockId=7')
  })

  it('sends the unshare mode when the author chooses to keep the content', async () => {
    mockRoutes()
    const { onDeleted } = renderDialog()

    await screen.findByTestId('shared-block-delete-usage')
    await userEvent.click(screen.getByRole('radio', { name: /own copy/i }))
    await userEvent.click(screen.getByTestId('shared-block-delete-confirm'))

    await waitFor(() => expect(onDeleted).toHaveBeenCalledOnce())

    expect(resolveRequestUrl(deleteRequests()[0][0])).toContain('mode=unshare')
  })

  it('asks for no choice when the block is placed nowhere', async () => {
    mockRoutes({ usage: { usageCount: 0, liveUsageCount: 0 } })
    const { onDeleted } = renderDialog()

    await screen.findByTestId('shared-block-delete-unused')
    expect(screen.queryByRole('radio')).toBeNull()

    const confirm = screen.getByTestId('shared-block-delete-confirm')
    expect(confirm).toBeEnabled()

    await userEvent.click(confirm)
    await waitFor(() => expect(onDeleted).toHaveBeenCalledOnce())
  })

  it('refuses to delete while the usage lookup is failing', async () => {
    mockRoutes({ usageStatus: 500 })
    renderDialog()

    await screen.findByTestId('shared-block-delete-usage-error')
    expect(screen.getByTestId('shared-block-delete-confirm')).toBeDisabled()
    expect(deleteRequests()).toHaveLength(0)
  })

  it('keeps the dialog open and reports the failure when the delete is rejected', async () => {
    mockRoutes({ deleteStatus: 403 })
    const { onDeleted } = renderDialog()

    await screen.findByTestId('shared-block-delete-usage')
    await userEvent.click(screen.getByRole('radio', { name: /remove it/i }))
    await userEvent.click(screen.getByTestId('shared-block-delete-confirm'))

    await screen.findByTestId('shared-block-delete-error')
    expect(onDeleted).not.toHaveBeenCalled()
    expect(screen.getByTestId('shared-block-delete-dialog')).toBeInTheDocument()
  })

  it('does not delete anything when cancelled', async () => {
    mockRoutes()
    const { onCancel } = renderDialog()

    await screen.findByTestId('shared-block-delete-usage')
    await userEvent.click(screen.getByTestId('shared-block-delete-cancel'))

    expect(onCancel).toHaveBeenCalledOnce()
    expect(deleteRequests()).toHaveLength(0)
  })
})
