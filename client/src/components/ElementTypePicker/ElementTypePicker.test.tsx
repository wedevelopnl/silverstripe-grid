import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { AllowedTypeInfo } from '@/types/elements';

import ElementTypePicker from './ElementTypePicker';

beforeEach(() => {
  // jsdom does not implement HTMLDialogElement.showModal/close
  HTMLDialogElement.prototype.showModal = vi.fn();
  HTMLDialogElement.prototype.close = vi.fn();
});

const allowedTypes: Record<string, AllowedTypeInfo> = {
  'App\\TextBlock': {
    label: 'Text Block',
    icon: 'font-icon-block-content',
    description: 'A rich text block',
  },
  'App\\ImageBlock': {
    label: 'Image Block',
    icon: 'font-icon-block-media',
    description: '',
  },
};

const defaultProps = {
  allowedTypes,
  isOpen: true,
  onClose: vi.fn(),
  onSelect: vi.fn(),
};

describe('ElementTypePicker', () => {
  it('renders a tile for each allowed type', () => {
    render(<ElementTypePicker {...defaultProps} />);

    expect(screen.getByText('Text Block')).toBeInTheDocument();
    expect(screen.getByText('Image Block')).toBeInTheDocument();
    expect(screen.getAllByTestId('element-type-tile')).toHaveLength(2);
  });

  it('calls onSelect with className when tile is clicked', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} />);

    await user.click(screen.getByText('Text Block'));

    expect(onSelect).toHaveBeenCalledWith('App\\TextBlock');
  });

  it('calls onClose when close button is clicked', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<ElementTypePicker {...defaultProps} onClose={onClose} />);

    await user.click(screen.getByTestId('element-type-picker-close'));

    expect(onClose).toHaveBeenCalledOnce();
  });

  it('shows empty state when no allowed types', () => {
    render(<ElementTypePicker {...defaultProps} allowedTypes={{}} />);

    expect(screen.getByText('No content element types available')).toBeInTheDocument();
    expect(screen.queryAllByTestId('element-type-tile')).toHaveLength(0);
  });

  it('shows description only when not empty string', () => {
    render(<ElementTypePicker {...defaultProps} />);

    expect(screen.getByText('A rich text block')).toBeInTheDocument();
    // ImageBlock has description '' so no description element should render for it
    const descriptions = screen.getAllByText(/rich text/);
    expect(descriptions).toHaveLength(1);
  });

  it('tile icons have correct class from allowedTypes icon field', () => {
    render(<ElementTypePicker {...defaultProps} />);

    const tiles = screen.getAllByTestId('element-type-tile');

    const textBlockIcon = tiles[0].querySelector('.element-type-picker__icon');
    expect(textBlockIcon).toHaveClass('element-type-picker__icon', 'font-icon-block-content');

    const imageBlockIcon = tiles[1].querySelector('.element-type-picker__icon');
    expect(imageBlockIcon).toHaveClass('element-type-picker__icon', 'font-icon-block-media');
  });

  it('renders the dialog title "Add content element"', () => {
    render(<ElementTypePicker {...defaultProps} />);

    expect(screen.getByText('Add content element')).toBeInTheDocument();
  });

  it('calls onClose when tile is clicked (in addition to onSelect)', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<ElementTypePicker {...defaultProps} onClose={onClose} />);

    await user.click(screen.getByText('Text Block'));

    expect(onClose).toHaveBeenCalledOnce();
  });

  it('calls showModal when isOpen transitions to true', () => {
    const { rerender } = render(<ElementTypePicker {...defaultProps} isOpen={false} />);

    rerender(<ElementTypePicker {...defaultProps} isOpen={true} />);

    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled();
  });

  it('tile labels render inside element-type-picker__label span', () => {
    render(<ElementTypePicker {...defaultProps} />);

    const labels = document.querySelectorAll('.element-type-picker__label');
    expect(labels).toHaveLength(2);
    expect(labels[0]).toHaveTextContent('Text Block');
    expect(labels[1]).toHaveTextContent('Image Block');
  });
});
