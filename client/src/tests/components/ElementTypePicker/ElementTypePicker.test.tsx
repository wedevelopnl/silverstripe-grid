import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker';
import type { AllowedTypeInfo } from '@/types/elements';

const sampleTypes: Record<string, AllowedTypeInfo> = {
  'App\\Model\\TextBlock': {
    label: 'Text Block',
    icon: 'font-icon-block-content',
    description: 'A rich text content area',
  },
  'App\\Model\\ImageBlock': {
    label: 'Image Block',
    icon: 'font-icon-block-image',
    description: 'An image with optional caption',
  },
};

describe('ElementTypePicker', () => {
  beforeEach(() => {
    // jsdom does not implement showModal/close on HTMLDialogElement
    HTMLDialogElement.prototype.showModal = vi.fn();
    HTMLDialogElement.prototype.close = vi.fn();
  });

  it('renders type tiles with label, icon class, and description', () => {
    render(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText('Text Block')).toBeDefined();
    expect(screen.getByText('Image Block')).toBeDefined();
    expect(screen.getByText('A rich text content area')).toBeDefined();
    expect(screen.getByText('An image with optional caption')).toBeDefined();

    const tiles = screen.getAllByTestId('element-type-tile');
    expect(tiles).toHaveLength(2);

    const firstIcon = tiles[0].querySelector('.font-icon-block-content');
    expect(firstIcon).not.toBeNull();

    const secondIcon = tiles[1].querySelector('.font-icon-block-image');
    expect(secondIcon).not.toBeNull();
  });

  it('fires onSelect with the className when a tile is clicked', async () => {
    const user = userEvent.setup();
    const handleSelect = vi.fn();

    render(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={handleSelect}
      />,
    );

    await user.click(screen.getByText('Text Block'));

    expect(handleSelect).toHaveBeenCalledOnce();
    expect(handleSelect).toHaveBeenCalledWith('App\\Model\\TextBlock');
  });

  it('fires onClose when a tile is clicked (auto-close)', async () => {
    const user = userEvent.setup();
    const handleClose = vi.fn();

    render(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={true}
        onClose={handleClose}
        onSelect={vi.fn()}
      />,
    );

    await user.click(screen.getByText('Image Block'));

    expect(handleClose).toHaveBeenCalledOnce();
  });

  it('fires onClose when close button is clicked', async () => {
    const user = userEvent.setup();
    const handleClose = vi.fn();

    render(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={true}
        onClose={handleClose}
        onSelect={vi.fn()}
      />,
    );

    await user.click(screen.getByTestId('element-type-picker-close'));

    expect(handleClose).toHaveBeenCalledOnce();
  });

  it('shows empty state message when allowedTypes is empty', () => {
    render(
      <ElementTypePicker
        allowedTypes={{}}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText('No content element types available')).toBeDefined();
  });

  it('does not render tiles when allowedTypes is empty', () => {
    render(
      <ElementTypePicker
        allowedTypes={{}}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.queryAllByTestId('element-type-tile')).toHaveLength(0);
  });

  it('calls showModal when isOpen changes from false to true', () => {
    const { rerender } = render(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={false}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    vi.mocked(HTMLDialogElement.prototype.showModal).mockClear();

    rerender(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalledOnce();
  });

  it('calls close when isOpen changes from true to false', () => {
    // Override showModal to set `open` attribute so the close guard works
    HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    });
    HTMLDialogElement.prototype.close = vi.fn(function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    });

    const { rerender } = render(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    vi.mocked(HTMLDialogElement.prototype.close).mockClear();

    rerender(
      <ElementTypePicker
        allowedTypes={sampleTypes}
        isOpen={false}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    expect(HTMLDialogElement.prototype.close).toHaveBeenCalledOnce();
  });

  it('hides description span when description is empty string', () => {
    const typesWithEmptyDescription: Record<string, AllowedTypeInfo> = {
      'App\\Model\\Spacer': {
        label: 'Spacer',
        icon: 'font-icon-block-layout',
        description: '',
      },
    };

    const { container } = render(
      <ElementTypePicker
        allowedTypes={typesWithEmptyDescription}
        isOpen={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText('Spacer')).toBeDefined();
    expect(container.querySelector('.element-type-picker__description')).toBeNull();
  });
});
