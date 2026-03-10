import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { GridSettingsOption } from '@/types/gridSettings';
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker';

const options: GridSettingsOption[] = [
  { value: 1, label: '1 column' },
  { value: 6, label: '6 columns' },
  { value: 12, label: '12 columns' },
  { value: 'hidden', label: 'Hidden' },
];

const defaultProps = {
  label: 'Width',
  options,
  selectedValue: 6 as number | 'hidden',
  disabled: false,
  testId: 'width-picker',
  onSelect: vi.fn(),
};

function renderPicker(overrides: Partial<typeof defaultProps> = {}) {
  return render(<GridSettingsPicker {...defaultProps} {...overrides} />);
}

describe('GridSettingsPicker', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders trigger button with label', () => {
    renderPicker();

    const trigger = screen.getByTestId('width-picker');
    expect(trigger.textContent).toBe('Width');
    expect(trigger.getAttribute('aria-haspopup')).toBe('listbox');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });

  it('opens listbox on trigger click and shows options', async () => {
    const user = userEvent.setup();
    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    const listbox = screen.getByTestId('width-picker-listbox');
    expect(listbox).toBeDefined();
    expect(screen.getByRole('option', { name: '1 column' })).toBeDefined();
    expect(screen.getByRole('option', { name: '6 columns' })).toBeDefined();
    expect(screen.getByRole('option', { name: '12 columns' })).toBeDefined();
    expect(screen.getByRole('option', { name: 'Hidden' })).toBeDefined();
  });

  it('calls onSelect and closes when clicking an option', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    renderPicker({ onSelect });

    await user.click(screen.getByTestId('width-picker'));
    await user.click(screen.getByRole('option', { name: '12 columns' }));

    expect(onSelect).toHaveBeenCalledWith(12);
    expect(screen.queryByTestId('width-picker-listbox')).toBeNull();
  });

  it('closes when clicking outside the picker', async () => {
    const user = userEvent.setup();
    renderPicker();

    await user.click(screen.getByTestId('width-picker'));
    expect(screen.getByTestId('width-picker-listbox')).toBeDefined();

    fireEvent.mouseDown(document.body);

    expect(screen.queryByTestId('width-picker-listbox')).toBeNull();
  });

  it('closes when pressing Escape', async () => {
    const user = userEvent.setup();
    renderPicker();

    await user.click(screen.getByTestId('width-picker'));
    expect(screen.getByTestId('width-picker-listbox')).toBeDefined();

    await user.keyboard('{Escape}');

    expect(screen.queryByTestId('width-picker-listbox')).toBeNull();
  });

  it('does not open when disabled', async () => {
    const user = userEvent.setup();
    renderPicker({ disabled: true });

    await user.click(screen.getByTestId('width-picker'));

    expect(screen.queryByTestId('width-picker-listbox')).toBeNull();
  });
});
