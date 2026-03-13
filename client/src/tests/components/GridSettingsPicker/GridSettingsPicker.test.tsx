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

  it('assigns correct id to the listbox element', async () => {
    const user = userEvent.setup();
    renderPicker({ testId: 'my-picker' });

    await user.click(screen.getByTestId('my-picker'));

    const listbox = screen.getByTestId('my-picker-listbox');
    expect(listbox.getAttribute('id')).toBe('my-picker-listbox');
  });

  it('does not close on click-outside when picker is already closed', () => {
    renderPicker();

    // Picker is closed — fire mousedown outside
    fireEvent.mouseDown(document.body);

    // Should remain closed without errors
    expect(screen.queryByTestId('width-picker-listbox')).toBeNull();
  });

  it('does not close on non-Escape keys when open', async () => {
    const user = userEvent.setup();
    renderPicker();

    await user.click(screen.getByTestId('width-picker'));
    expect(screen.getByTestId('width-picker-listbox')).toBeDefined();

    await user.keyboard('{Tab}');

    // Listbox should still be open — only Escape closes it
    expect(screen.getByTestId('width-picker-listbox')).toBeDefined();
  });

  it('applies disabled class to trigger button when disabled', () => {
    renderPicker({ disabled: true });

    const trigger = screen.getByTestId('width-picker');
    expect(trigger.classList.contains('grid-settings-picker__trigger--disabled')).toBe(true);
  });

  it('does not apply disabled class to trigger button when enabled', () => {
    renderPicker({ disabled: false });

    const trigger = screen.getByTestId('width-picker');
    expect(trigger.classList.contains('grid-settings-picker__trigger--disabled')).toBe(false);
  });

  it('applies selected class to the currently selected option', async () => {
    const user = userEvent.setup();
    renderPicker({ selectedValue: 6 });

    await user.click(screen.getByTestId('width-picker'));

    const selectedOption = screen.getByRole('option', { name: '6 columns' });
    expect(selectedOption.classList.contains('grid-settings-picker__option--selected')).toBe(true);

    const unselectedOption = screen.getByRole('option', { name: '1 column' });
    expect(unselectedOption.classList.contains('grid-settings-picker__option--selected')).toBe(false);
  });

  it('applies separator class to hidden option', async () => {
    const user = userEvent.setup();
    renderPicker();

    await user.click(screen.getByTestId('width-picker'));

    const hiddenOption = screen.getByRole('option', { name: 'Hidden' });
    expect(hiddenOption.classList.contains('grid-settings-picker__option--separator')).toBe(true);

    const normalOption = screen.getByRole('option', { name: '1 column' });
    expect(normalOption.classList.contains('grid-settings-picker__option--separator')).toBe(false);
  });

  it('sets aria-selected="true" on the selected option and "false" on others', async () => {
    const user = userEvent.setup();
    renderPicker({ selectedValue: 12 });

    await user.click(screen.getByTestId('width-picker'));

    const selected = screen.getByRole('option', { name: '12 columns' });
    expect(selected.getAttribute('aria-selected')).toBe('true');

    const unselected = screen.getByRole('option', { name: '1 column' });
    expect(unselected.getAttribute('aria-selected')).toBe('false');
  });

  it('sets aria-controls on trigger when open', async () => {
    const user = userEvent.setup();
    renderPicker({ testId: 'test-picker' });

    const trigger = screen.getByTestId('test-picker');
    expect(trigger.getAttribute('aria-controls')).toBeNull();

    await user.click(trigger);
    expect(trigger.getAttribute('aria-controls')).toBe('test-picker-listbox');
  });
});
