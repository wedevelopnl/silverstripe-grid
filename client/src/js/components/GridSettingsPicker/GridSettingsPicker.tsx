// biome-ignore-all lint/suspicious/noUnnecessaryConditions: biome's type inference treats the Enter/' ' comparisons as unreachable, but they handle real KeyboardEvent.key values at runtime.
import { useMemo } from 'react'
import { useRovingPopup } from '@/hooks/useRovingPopup'
import type { GridSettingsOption } from '@/types/gridSettings'

export type { GridSettingsOption } from '@/types/gridSettings'

interface GridSettingsPickerProps {
  readonly label: string
  readonly options: readonly GridSettingsOption[]
  readonly selectedValue: number | 'hidden'
  readonly disabled: boolean
  readonly testId: string
  readonly className?: string
  readonly onSelect: (value: number | 'hidden') => void
}

export default function GridSettingsPicker({
  label,
  options,
  selectedValue,
  disabled,
  testId,
  className,
  onSelect,
}: GridSettingsPickerProps) {
  const listboxId = `${testId}-listbox`

  // Keyboard navigation starts from the user's current choice rather than the
  // top of the list.
  const seedIndex = useMemo(() => {
    const selectedIdx = options.findIndex((o) => o.value === selectedValue)
    // Stryker disable next-line EqualityOperator: Equivalent — `>= 0` vs `> 0` both yield 0 when selectedIdx is 0 (the ternary's else fallback is also 0)
    return selectedIdx >= 0 ? selectedIdx : 0
  }, [options, selectedValue])

  const popup = useRovingPopup({ itemCount: options.length, seedIndex })

  function handleTriggerClick(e: React.MouseEvent) {
    e.stopPropagation()
    // Stryker disable next-line ConditionalExpression: Equivalent — the trigger button carries disabled={disabled}, so React suppresses the click and this guard is unreachable dead-defense
    if (!disabled) {
      popup.toggle()
    }
  }

  function handleOptionClick(value: number | 'hidden') {
    onSelect(value)
    popup.close()
  }

  function handleListboxKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (popup.handleNavigationKeyDown(e)) return

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      const current = options[popup.activeIndex]
      if (current) {
        handleOptionClick(current.value)
      }
    }
  }

  return (
    <div ref={popup.wrapperRef} className="ssgrid-settings-picker ssgrid-popover-anchor">
      <button
        ref={popup.triggerRef}
        type="button"
        className={
          className !== undefined
            ? `ssgrid-settings-picker-trigger ${className}`
            : 'ssgrid-settings-picker-trigger'
        }
        data-testid={testId}
        aria-haspopup="listbox"
        aria-expanded={popup.isOpen}
        aria-controls={popup.isOpen ? listboxId : undefined}
        disabled={disabled}
        onClick={handleTriggerClick}
      >
        <span className="ssgrid-settings-picker-label ssgrid-truncate">{label}</span>
        <i
          className="ssgrid-glyph ssgrid-settings-picker-caret font-icon-down-open"
          aria-hidden="true"
        />
      </button>
      {popup.isOpen && (
        <div
          id={listboxId}
          ref={popup.popupRef}
          className="ssgrid-settings-picker-listbox ssgrid-popover-surface"
          role="listbox"
          tabIndex={-1}
          aria-activedescendant={popup.getItemId(popup.activeIndex)}
          data-testid={`${testId}-listbox`}
          onKeyDown={handleListboxKeyDown}
        >
          {options.map((option, index) => (
            // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the listbox (aria-activedescendant pattern per W3C APG); options are not focusable themselves
            <div
              key={option.value}
              id={popup.getItemId(index)}
              className="ssgrid-settings-picker-option ssgrid-popover-item"
              role="option"
              aria-selected={option.value === selectedValue}
              data-separator={option.value === 'hidden' ? 'true' : undefined}
              tabIndex={index === popup.activeIndex ? 0 : -1}
              onClick={() => handleOptionClick(option.value)}
            >
              {option.label}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
