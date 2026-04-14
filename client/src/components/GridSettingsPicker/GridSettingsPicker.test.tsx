import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type { GridSettingsOption } from '@/types/gridSettings';

import GridSettingsPicker from './GridSettingsPicker';

const OPTIONS: readonly GridSettingsOption[] = [
  { value: 6, label: '6 columns' },
  { value: 12, label: '12 columns' },
  { value: 'hidden', label: 'Hidden' },
];

function renderPicker(overrides: Partial<React.ComponentProps<typeof GridSettingsPicker>> = {}) {
  const props: React.ComponentProps<typeof GridSettingsPicker> = {
    label: 'Width',
    options: OPTIONS,
    selectedValue: 6,
    disabled: false,
    testId: 'width-picker',
    onSelect: vi.fn(),
    ...overrides,
  };

  return { ...render(<GridSettingsPicker {...props} />), props };
}

describe('GridSettingsPicker', () => {
  it('opens listbox on trigger click', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('closes listbox on second trigger click', async () => {
    const user = userEvent.setup();

    renderPicker();

    const trigger = screen.getByTestId('width-picker');

    await user.click(trigger);
    expect(screen.getByRole('listbox')).toBeInTheDocument();

    await user.click(trigger);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('selects option and closes menu', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));
    await user.click(screen.getByText('12 columns'));

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('calls onSelect with correct value', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();

    renderPicker({ onSelect });

    await user.click(screen.getByTestId('width-picker'));
    await user.click(screen.getByText('12 columns'));

    expect(onSelect).toHaveBeenCalledWith(12);
  });

  it('calls onSelect with "hidden" for hidden option', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();

    renderPicker({ onSelect });

    await user.click(screen.getByTestId('width-picker'));
    await user.click(screen.getByText('Hidden'));

    expect(onSelect).toHaveBeenCalledWith('hidden');
  });

  it('does not open when disabled', async () => {
    const user = userEvent.setup();

    renderPicker({ disabled: true });

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('closes on outside click', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();

    await user.click(document.body);

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('closes on Escape key', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();

    await user.keyboard('{Escape}');

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('marks selected option with aria-selected', async () => {
    const user = userEvent.setup();

    renderPicker({ selectedValue: 12 });

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.getByText('12 columns')).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByText('6 columns')).toHaveAttribute('aria-selected', 'false');
  });

  it('shows disabled state on button when disabled', () => {
    renderPicker({ disabled: true });

    const trigger = screen.getByTestId('width-picker');

    expect(trigger).toBeDisabled();
    expect(trigger).toHaveClass('grid-settings-picker__trigger--disabled');
  });

  it('trigger button displays the label text', () => {
    renderPicker({ label: 'Width' });

    expect(screen.getByTestId('width-picker')).toHaveTextContent('Width');
  });

  it('option items show correct labels from options prop', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.getByText('6 columns')).toBeInTheDocument();
    expect(screen.getByText('12 columns')).toBeInTheDocument();
    expect(screen.getByText('Hidden')).toBeInTheDocument();
  });

  it('listbox has role="listbox"', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('options have role="option"', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    const options = screen.getAllByRole('option');
    expect(options).toHaveLength(3);
  });

  it('trigger has aria-haspopup="listbox"', () => {
    renderPicker();

    expect(screen.getByTestId('width-picker')).toHaveAttribute('aria-haspopup', 'listbox');
  });

  it('trigger has aria-expanded=false when closed', () => {
    renderPicker();

    expect(screen.getByTestId('width-picker')).toHaveAttribute('aria-expanded', 'false');
  });

  it('trigger has aria-controls when open', async () => {
    const user = userEvent.setup();

    renderPicker();

    const trigger = screen.getByTestId('width-picker');
    expect(trigger).not.toHaveAttribute('aria-controls');

    await user.click(trigger);

    expect(trigger).toHaveAttribute('aria-controls', 'width-picker-listbox');
  });

  it('hidden option has separator class', async () => {
    const user = userEvent.setup();

    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.getByText('Hidden')).toHaveClass('grid-settings-picker__option--separator');
  });

  it('selected option has selected class', async () => {
    const user = userEvent.setup();

    renderPicker({ selectedValue: 6 });

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.getByText('6 columns')).toHaveClass('grid-settings-picker__option--selected');
    expect(screen.getByText('12 columns')).not.toHaveClass(
      'grid-settings-picker__option--selected',
    );
  });

  it('enabled trigger does not have disabled class', () => {
    renderPicker({ disabled: false });

    expect(screen.getByTestId('width-picker')).not.toHaveClass(
      'grid-settings-picker__trigger--disabled',
    );
  });

  it('outside click while picker is closed does not open it', async () => {
    const user = userEvent.setup();

    renderPicker();

    // Picker starts closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();

    // Click outside
    await user.click(document.body);

    // Should still be closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('Escape key while picker is closed does not cause errors', async () => {
    const user = userEvent.setup();

    renderPicker();

    // Picker starts closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();

    // Press Escape while closed
    await user.keyboard('{Escape}');

    // Should still be closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('option elements have exact base class', async () => {
    const user = userEvent.setup();

    renderPicker({ selectedValue: 12 });

    await user.click(screen.getByTestId('width-picker'));

    const options = screen.getAllByRole('option');
    // Each option has the base class
    for (const option of options) {
      expect(option).toHaveClass('grid-settings-picker__option');
    }
  });

  it('trigger has exact base class when enabled', () => {
    renderPicker({ disabled: false });

    expect(screen.getByTestId('width-picker').className).toBe('grid-settings-picker__trigger');
  });

  it('trigger has exact classes when disabled', () => {
    renderPicker({ disabled: true });

    expect(screen.getByTestId('width-picker').className).toBe(
      'grid-settings-picker__trigger grid-settings-picker__trigger--disabled',
    );
  });

  describe('roving tabindex and focus management', () => {
    it('sets roving tabindex with exactly one option tab-reachable on open', async () => {
      const user = userEvent.setup();

      renderPicker();

      await user.click(screen.getByTestId('width-picker'));

      const options = screen.getAllByRole('option');
      const reachable = options.filter((o) => o.tabIndex === 0);
      expect(reachable).toHaveLength(1);
    });

    it('sets aria-activedescendant on the listbox pointing to the active option', async () => {
      const user = userEvent.setup();

      renderPicker();

      await user.click(screen.getByTestId('width-picker'));

      const listbox = screen.getByRole('listbox');
      const options = screen.getAllByRole('option');
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id);
      expect(options[0].id).toBeTruthy();
    });

    it('ArrowDown moves active option forward and updates aria-activedescendant', async () => {
      const user = userEvent.setup();

      renderPicker();

      await user.click(screen.getByTestId('width-picker'));
      const listbox = screen.getByRole('listbox');
      const options = screen.getAllByRole('option');

      await user.keyboard('{ArrowDown}');

      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[1].id);
      expect(options[1].tabIndex).toBe(0);
      expect(options[0].tabIndex).toBe(-1);
    });

    it('ArrowUp moves active option backward', async () => {
      const user = userEvent.setup();

      renderPicker();

      await user.click(screen.getByTestId('width-picker'));
      const listbox = screen.getByRole('listbox');
      const options = screen.getAllByRole('option');

      await user.keyboard('{ArrowDown}{ArrowDown}{ArrowUp}');

      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[1].id);
    });

    it('Home/End jump active option to first/last', async () => {
      const user = userEvent.setup();

      renderPicker();

      await user.click(screen.getByTestId('width-picker'));
      const listbox = screen.getByRole('listbox');
      const options = screen.getAllByRole('option');

      await user.keyboard('{End}');
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[options.length - 1].id);

      await user.keyboard('{Home}');
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id);
    });

    it('Enter selects the active option', async () => {
      const user = userEvent.setup();
      const onSelect = vi.fn();

      renderPicker({ onSelect });

      await user.click(screen.getByTestId('width-picker'));
      await user.keyboard('{ArrowDown}{Enter}');

      expect(onSelect).toHaveBeenCalledWith(12);
    });

    it('Escape returns focus to the trigger', async () => {
      const user = userEvent.setup();

      renderPicker();

      const trigger = screen.getByTestId('width-picker');
      await user.click(trigger);
      await user.keyboard('{Escape}');

      expect(document.activeElement).toBe(trigger);
    });
  });
});
