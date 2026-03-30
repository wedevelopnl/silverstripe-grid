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
});
