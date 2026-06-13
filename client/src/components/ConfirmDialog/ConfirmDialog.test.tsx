import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import ConfirmDialog from './ConfirmDialog'

beforeEach(() => {
  // jsdom does not implement HTMLDialogElement.showModal/close
  HTMLDialogElement.prototype.showModal = vi.fn()
  HTMLDialogElement.prototype.close = vi.fn()
})

const defaultProps = {
  isOpen: true,
  title: 'Delete element',
  message: 'Are you sure you want to delete this element?',
  confirmLabel: 'Delete',
  onConfirm: vi.fn(),
  onCancel: vi.fn(),
}

describe('ConfirmDialog', () => {
  it('renders title, message, and confirm label', () => {
    render(<ConfirmDialog {...defaultProps} />)

    expect(screen.getByText('Delete element')).toBeInTheDocument()
    expect(screen.getByText('Are you sure you want to delete this element?')).toBeInTheDocument()
    expect(screen.getByText('Delete')).toBeInTheDocument()
  })

  it('calls onCancel when Cancel button is clicked', async () => {
    const user = userEvent.setup()
    const onCancel = vi.fn()

    render(<ConfirmDialog {...defaultProps} onCancel={onCancel} />)

    await user.click(screen.getByText('Cancel'))

    expect(onCancel).toHaveBeenCalledOnce()
  })

  it('calls onConfirm when confirm button is clicked', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()

    render(<ConfirmDialog {...defaultProps} onConfirm={onConfirm} />)

    await user.click(screen.getByText('Delete'))

    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('applies destructive state to confirm button when destructive', () => {
    render(<ConfirmDialog {...defaultProps} destructive={true} />)

    const confirmButton = screen.getByText('Delete')
    expect(confirmButton).toHaveAttribute('data-destructive', 'true')
  })

  it('does not apply destructive state by default', () => {
    render(<ConfirmDialog {...defaultProps} />)

    const confirmButton = screen.getByText('Delete')
    expect(confirmButton).not.toHaveAttribute('data-destructive')
  })

  it('calls onCancel when dialog native close event fires', () => {
    const onCancel = vi.fn()

    render(<ConfirmDialog {...defaultProps} onCancel={onCancel} />)

    const dialog = screen.getByTestId('confirm-dialog')
    dialog.dispatchEvent(new Event('close', { bubbles: false }))

    expect(onCancel).toHaveBeenCalledOnce()
  })

  it('stops clicks inside the dialog from bubbling to ancestors and prevents default', async () => {
    const user = userEvent.setup()
    const onAncestorClick = vi.fn()

    // The dialog is rendered inside an interactive ElementCard anchor in
    // production; the guard exists to stop clicks reaching that anchor's
    // React onClick handler. Model that with an anchor ancestor here.
    render(
      <a href="/edit" onClick={onAncestorClick} data-testid="ancestor">
        <ConfirmDialog {...defaultProps} />
      </a>,
    )

    // Click the message paragraph (a non-button region of the dialog).
    await user.click(screen.getByText('Are you sure you want to delete this element?'))

    expect(onAncestorClick).not.toHaveBeenCalled()
  })

  it('calls showModal when isOpen transitions to true', () => {
    const { rerender } = render(<ConfirmDialog {...defaultProps} isOpen={false} />)

    rerender(<ConfirmDialog {...defaultProps} isOpen={true} />)

    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled()
  })

  it('calls close when isOpen transitions to false', () => {
    const { rerender } = render(<ConfirmDialog {...defaultProps} isOpen={true} />)

    // showModal was called, now the mock thinks dialog.open is truthy
    // We need to simulate dialog.open for the close branch
    const dialog = screen.getByTestId('confirm-dialog') as HTMLDialogElement
    Object.defineProperty(dialog, 'open', { value: true, writable: true })

    rerender(<ConfirmDialog {...defaultProps} isOpen={false} />)

    expect(HTMLDialogElement.prototype.close).toHaveBeenCalled()
  })
})
