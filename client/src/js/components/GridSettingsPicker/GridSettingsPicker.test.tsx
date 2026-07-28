import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { GridSettingsOption } from '@/types/gridSettings'

import GridSettingsPicker from './GridSettingsPicker'

const OPTIONS: readonly GridSettingsOption[] = [
  { value: 6, label: '6 columns' },
  { value: 12, label: '12 columns' },
  { value: 'hidden', label: 'Hidden' },
]

function renderPicker(overrides: Partial<React.ComponentProps<typeof GridSettingsPicker>> = {}) {
  const props: React.ComponentProps<typeof GridSettingsPicker> = {
    label: 'Width',
    options: OPTIONS,
    selectedValue: 6,
    disabled: false,
    testId: 'width-picker',
    onSelect: vi.fn(),
    ...overrides,
  }

  return { ...render(<GridSettingsPicker {...props} />), props }
}

describe('GridSettingsPicker', () => {
  it('opens listbox on trigger click', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByRole('listbox')).toBeInTheDocument()
  })

  it('closes listbox on second trigger click', async () => {
    const user = userEvent.setup()

    renderPicker()

    const trigger = screen.getByTestId('width-picker')

    await user.click(trigger)
    expect(screen.getByRole('listbox')).toBeInTheDocument()

    await user.click(trigger)
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('selects option and closes menu', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))
    await user.click(screen.getByText('12 columns'))

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('calls onSelect with correct value', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()

    renderPicker({ onSelect })

    await user.click(screen.getByTestId('width-picker'))
    await user.click(screen.getByText('12 columns'))

    expect(onSelect).toHaveBeenCalledWith(12)
  })

  it('calls onSelect with "hidden" for hidden option', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()

    renderPicker({ onSelect })

    await user.click(screen.getByTestId('width-picker'))
    await user.click(screen.getByText('Hidden'))

    expect(onSelect).toHaveBeenCalledWith('hidden')
  })

  it('does not open when disabled', async () => {
    const user = userEvent.setup()

    renderPicker({ disabled: true })

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('closes on outside click', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))
    expect(screen.getByRole('listbox')).toBeInTheDocument()

    await user.click(document.body)

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('closes on Escape key', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))
    expect(screen.getByRole('listbox')).toBeInTheDocument()

    await user.keyboard('{Escape}')

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('marks selected option with aria-selected', async () => {
    const user = userEvent.setup()

    renderPicker({ selectedValue: 12 })

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByText('12 columns')).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('6 columns')).toHaveAttribute('aria-selected', 'false')
  })

  it('shows disabled state on button when disabled', () => {
    renderPicker({ disabled: true })

    const trigger = screen.getByTestId('width-picker')

    expect(trigger).toBeDisabled()
  })

  it('trigger button displays the label text', () => {
    renderPicker({ label: 'Width' })

    expect(screen.getByTestId('width-picker')).toHaveTextContent('Width')
  })

  it('option items show correct labels from options prop', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByText('6 columns')).toBeInTheDocument()
    expect(screen.getByText('12 columns')).toBeInTheDocument()
    expect(screen.getByText('Hidden')).toBeInTheDocument()
  })

  it('listbox has role="listbox"', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByRole('listbox')).toBeInTheDocument()
  })

  it('options have role="option"', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    const options = screen.getAllByRole('option')
    expect(options).toHaveLength(3)
  })

  it('trigger has aria-haspopup="listbox"', () => {
    renderPicker()

    expect(screen.getByTestId('width-picker')).toHaveAttribute('aria-haspopup', 'listbox')
  })

  it('trigger has aria-expanded=false when closed', () => {
    renderPicker()

    expect(screen.getByTestId('width-picker')).toHaveAttribute('aria-expanded', 'false')
  })

  it('trigger has aria-controls when open', async () => {
    const user = userEvent.setup()

    renderPicker()

    const trigger = screen.getByTestId('width-picker')
    expect(trigger).not.toHaveAttribute('aria-controls')

    await user.click(trigger)

    expect(trigger).toHaveAttribute('aria-controls', 'width-picker-listbox')
  })

  it('hidden option has separator attribute', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByText('Hidden')).toHaveAttribute('data-separator', 'true')
  })

  it('non-hidden options do not have a separator attribute', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByText('6 columns')).not.toHaveAttribute('data-separator')
    expect(screen.getByText('12 columns')).not.toHaveAttribute('data-separator')
  })

  it('exposes the listbox under the derived listbox testId', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByTestId('width-picker-listbox')).toBe(screen.getByRole('listbox'))
  })

  it('appends a provided className to the trigger class list', () => {
    renderPicker({ className: 'custom-class' })

    expect(screen.getByTestId('width-picker')).toHaveClass(
      'ssgrid-settings-picker__trigger',
      'custom-class',
    )
  })

  it('omits any extra trigger class when no className is provided', () => {
    renderPicker()

    expect(screen.getByTestId('width-picker').className).toBe('ssgrid-settings-picker__trigger')
  })

  it('keeps the listbox itself out of the tab order', async () => {
    const user = userEvent.setup()

    renderPicker()

    await user.click(screen.getByTestId('width-picker'))

    expect(screen.getByRole('listbox').tabIndex).toBe(-1)
  })

  it('outside click while picker is closed does not open it', async () => {
    const user = userEvent.setup()

    renderPicker()

    // Picker starts closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()

    // Click outside
    await user.click(document.body)

    // Should still be closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('Escape key while picker is closed does not cause errors', async () => {
    const user = userEvent.setup()

    renderPicker()

    // Picker starts closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()

    // Press Escape while closed
    await user.keyboard('{Escape}')

    // Should still be closed
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('Escape while the picker is closed does not steal focus to the trigger', async () => {
    // Pins the `if (!isOpen) return` guard at GridSettingsPicker.tsx:68 — the
    // document keydown handler must NOT be registered while the listbox is
    // closed. Without the guard, a global Escape would run close() +
    // triggerRef.focus(), grabbing focus onto a trigger the user never opened.
    const user = userEvent.setup()

    render(
      <>
        <button type="button" data-testid="outside-button">
          Outside
        </button>
        <GridSettingsPicker
          label="Width"
          options={OPTIONS}
          selectedValue={6}
          disabled={false}
          testId="width-picker"
          onSelect={vi.fn()}
        />
      </>,
    )

    const outside = screen.getByTestId('outside-button')
    outside.focus()
    expect(document.activeElement).toBe(outside)

    await user.keyboard('{Escape}')

    // Focus must remain on the outside button, not jump to the picker trigger.
    expect(document.activeElement).toBe(outside)
  })

  describe('roving tabindex and focus management', () => {
    it('sets roving tabindex with exactly one option tab-reachable on open', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))

      const options = screen.getAllByRole('option')
      const reachable = options.filter((o) => o.tabIndex === 0)
      expect(reachable).toHaveLength(1)
    })

    it('sets aria-activedescendant on the listbox pointing to the active option', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))

      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id)
      expect(options[0].id).toBeTruthy()
    })

    it('ArrowDown moves active option forward and updates aria-activedescendant', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))
      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')

      await user.keyboard('{ArrowDown}')

      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[1].id)
      expect(options[1].tabIndex).toBe(0)
      expect(options[0].tabIndex).toBe(-1)
    })

    it('ArrowUp moves active option backward', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))
      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')

      await user.keyboard('{ArrowDown}{ArrowDown}{ArrowUp}')

      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[1].id)
    })

    it('Home/End jump active option to first/last', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))
      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')

      await user.keyboard('{End}')
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[options.length - 1].id)

      await user.keyboard('{Home}')
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id)
    })

    it('Enter selects the active option', async () => {
      const user = userEvent.setup()
      const onSelect = vi.fn()

      renderPicker({ onSelect })

      await user.click(screen.getByTestId('width-picker'))
      await user.keyboard('{ArrowDown}{Enter}')

      expect(onSelect).toHaveBeenCalledWith(12)
    })

    it('Escape returns focus to the trigger', async () => {
      const user = userEvent.setup()

      renderPicker()

      const trigger = screen.getByTestId('width-picker')
      await user.click(trigger)
      await user.keyboard('{Escape}')

      expect(document.activeElement).toBe(trigger)
    })

    it('seeds the active option to the currently-selected value on open', async () => {
      const user = userEvent.setup()

      renderPicker({ selectedValue: 12 })

      await user.click(screen.getByTestId('width-picker'))

      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')
      // selectedValue 12 is the second option, so it should be active, not the first.
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[1].id)
      expect(options[1].tabIndex).toBe(0)
    })

    it('seeds the active option to the first option when the selected value is absent', async () => {
      const user = userEvent.setup()

      renderPicker({ selectedValue: 99 })

      await user.click(screen.getByTestId('width-picker'))

      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id)
    })

    it('ArrowDown at the last option keeps the active option on the last', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))
      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')

      await user.keyboard('{End}{ArrowDown}')

      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[options.length - 1].id)
    })

    it('ArrowUp at the first option keeps the active option on the first', async () => {
      const user = userEvent.setup()

      renderPicker()

      await user.click(screen.getByTestId('width-picker'))
      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')

      await user.keyboard('{Home}{ArrowUp}')

      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id)
    })

    it('Space selects the active option', async () => {
      const user = userEvent.setup()
      const onSelect = vi.fn()

      renderPicker({ onSelect })

      await user.click(screen.getByTestId('width-picker'))
      await user.keyboard('{ArrowDown}[Space]')

      expect(onSelect).toHaveBeenCalledWith(12)
      expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    it('ignores unhandled keys without selecting or closing the listbox', async () => {
      const user = userEvent.setup()
      const onSelect = vi.fn()

      renderPicker({ onSelect })

      await user.click(screen.getByTestId('width-picker'))
      const listbox = screen.getByRole('listbox')
      const options = screen.getAllByRole('option')

      await user.keyboard('a')

      expect(onSelect).not.toHaveBeenCalled()
      expect(screen.getByRole('listbox')).toBeInTheDocument()
      // Active option is unchanged by an unhandled key.
      expect(listbox.getAttribute('aria-activedescendant')).toBe(options[0].id)
    })

    it('Enter with no resolvable active option is an inert no-op', async () => {
      // Pins the `if (current)` guard at GridSettingsPicker.tsx:117. When the
      // listbox is open but `options` is empty, activeIndex (re-seeded to 0)
      // points at no option, so options[activeIndex] is undefined. Enter must
      // hit the falsy guard and do nothing. The `if (true)` mutant would call
      // handleOptionClick(undefined.value) and throw a TypeError.
      const user = userEvent.setup()
      const onSelect = vi.fn()

      const { rerender } = render(
        <GridSettingsPicker
          label="Width"
          options={OPTIONS}
          selectedValue={6}
          disabled={false}
          testId="width-picker"
          onSelect={onSelect}
        />,
      )

      await user.click(screen.getByTestId('width-picker'))
      expect(screen.getByRole('listbox')).toBeInTheDocument()

      // Drop all options while open — the active index no longer resolves to any
      // option element.
      rerender(
        <GridSettingsPicker
          label="Width"
          options={[]}
          selectedValue={6}
          disabled={false}
          testId="width-picker"
          onSelect={onSelect}
        />,
      )

      expect(screen.queryAllByRole('option')).toHaveLength(0)

      await user.keyboard('{Enter}')

      // No option resolved at the active index, so nothing is selected and the
      // listbox stays open (handleOptionClick → onSelect + close() never ran).
      expect(onSelect).not.toHaveBeenCalled()
      expect(screen.getByRole('listbox')).toBeInTheDocument()
    })
  })
})
