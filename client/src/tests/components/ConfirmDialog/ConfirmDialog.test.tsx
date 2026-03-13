import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog';

// jsdom does not implement HTMLDialogElement.showModal / close
beforeAll(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
  });
  HTMLDialogElement.prototype.close = vi.fn(function (this: HTMLDialogElement) {
    this.removeAttribute('open');
  });
});

describe('ConfirmDialog', () => {
  const defaultProps = {
    isOpen: true,
    title: 'Confirm archive',
    message: 'Archive "Section A" and all 6 child elements?',
    confirmLabel: 'Archive',
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    destructive: true,
  };

  it('renders the title and message', () => {
    render(<ConfirmDialog {...defaultProps} />);

    expect(screen.getByText('Confirm archive')).toBeDefined();
    expect(screen.getByText('Archive "Section A" and all 6 child elements?')).toBeDefined();
  });

  it('renders confirm and cancel buttons', () => {
    render(<ConfirmDialog {...defaultProps} />);

    expect(screen.getByRole('button', { name: 'Archive' })).toBeDefined();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDefined();
  });

  it('calls onConfirm when confirm button is clicked', async () => {
    const onConfirm = vi.fn();
    render(<ConfirmDialog {...defaultProps} onConfirm={onConfirm} />);

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }));
    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it('calls onCancel when cancel button is clicked', async () => {
    const onCancel = vi.fn();
    render(<ConfirmDialog {...defaultProps} onCancel={onCancel} />);

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onCancel).toHaveBeenCalledOnce();
  });

  it('applies destructive class to confirm button when destructive is true', () => {
    render(<ConfirmDialog {...defaultProps} destructive />);

    const confirmButton = screen.getByRole('button', { name: 'Archive' });
    expect(confirmButton.classList.contains('confirm-dialog__button--destructive')).toBe(true);
  });

  it('does not apply destructive class when destructive is false', () => {
    render(<ConfirmDialog {...defaultProps} destructive={false} />);

    const confirmButton = screen.getByRole('button', { name: 'Archive' });
    expect(confirmButton.classList.contains('confirm-dialog__button--destructive')).toBe(false);
  });

  it('calls showModal when isOpen transitions to true', () => {
    const { rerender } = render(<ConfirmDialog {...defaultProps} isOpen={false} />);
    rerender(<ConfirmDialog {...defaultProps} isOpen />);

    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled();
  });

  it('has confirm-dialog test id', () => {
    render(<ConfirmDialog {...defaultProps} />);

    expect(screen.getByTestId('confirm-dialog')).toBeDefined();
  });

  it('calls close when isOpen changes from true to false', () => {
    const { rerender } = render(<ConfirmDialog {...defaultProps} isOpen />);

    vi.mocked(HTMLDialogElement.prototype.close).mockClear();

    rerender(<ConfirmDialog {...defaultProps} isOpen={false} />);

    expect(HTMLDialogElement.prototype.close).toHaveBeenCalledOnce();
  });

  it('stops propagation on dialog click', () => {
    render(<ConfirmDialog {...defaultProps} />);

    const dialog = screen.getByTestId('confirm-dialog');
    const clickEvent = new MouseEvent('click', { bubbles: true });
    const stopPropSpy = vi.spyOn(clickEvent, 'stopPropagation');

    dialog.dispatchEvent(clickEvent);

    expect(stopPropSpy).toHaveBeenCalledOnce();
  });

  it('applies confirm--confirm class to the confirm button', () => {
    render(<ConfirmDialog {...defaultProps} />);

    const confirmButton = screen.getByRole('button', { name: 'Archive' });
    expect(confirmButton.classList.contains('confirm-dialog__button--confirm')).toBe(true);
  });
});
