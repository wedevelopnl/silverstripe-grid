// biome-ignore-all lint/suspicious/noUnnecessaryConditions: biome's type inference treats the switch(e.key) cases as unreachable, but they handle real KeyboardEvent.key values (arrow/Home/End/Enter) at runtime.
import { useCallback, useEffect, useId, useRef, useState } from 'react'
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
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const wrapperRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const listboxRef = useRef<HTMLDivElement>(null)
  const listboxId = `${testId}-listbox`
  // Stable prefix for option DOM ids so aria-activedescendant has a target to reference.
  const optionIdPrefix = useId()

  const getOptionId = useCallback(
    (index: number) => `${optionIdPrefix}opt-${index}`,
    [optionIdPrefix],
  )

  const close = useCallback(() => setIsOpen(false), [])

  // Seed activeIndex to the currently-selected option each time the listbox opens
  // so keyboard navigation starts from the user's current choice.
  useEffect(() => {
    // Stryker disable next-line ConditionalExpression: Equivalent — on close the {isOpen && …} listbox unmounts (listboxRef null → focus no-op) and the seed index is re-applied on the next open, so a close-time run is unobservable
    if (!isOpen) return
    const selectedIdx = options.findIndex((o) => o.value === selectedValue)
    // Stryker disable next-line EqualityOperator: Equivalent — `>= 0` vs `> 0` both yield 0 when selectedIdx is 0 (the ternary's else fallback is also 0)
    setActiveIndex(selectedIdx >= 0 ? selectedIdx : 0)
    // Move focus to the listbox so Arrow keys target it (aria-activedescendant pattern).
    listboxRef.current?.focus()
  }, [isOpen, options, selectedValue])

  // Close on outside click
  useEffect(() => {
    // Stryker disable next-line ConditionalExpression: Equivalent — the outside-mousedown listener's only side effect is the idempotent close(), so registering it while closed is inert
    if (!isOpen) return

    function handleMouseDown(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        close()
      }
    }

    document.addEventListener('mousedown', handleMouseDown)
    return () => document.removeEventListener('mousedown', handleMouseDown)
  }, [isOpen, close])

  // Close on Escape (window-level so it works regardless of focus target) and restore trigger focus.
  useEffect(() => {
    if (!isOpen) return

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        close()
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [isOpen, close])

  function handleTriggerClick(e: React.MouseEvent) {
    e.stopPropagation()
    // Stryker disable next-line ConditionalExpression: Equivalent — the trigger button carries disabled={disabled}, so React suppresses the click and this guard is unreachable dead-defense
    if (!disabled) {
      setIsOpen((prev) => !prev)
    }
  }

  function handleOptionClick(value: number | 'hidden') {
    onSelect(value)
    close()
  }

  function handleListboxKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    const last = options.length - 1
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        setActiveIndex((i) => (i >= last ? last : i + 1))
        return
      case 'ArrowUp':
        e.preventDefault()
        setActiveIndex((i) => (i <= 0 ? 0 : i - 1))
        return
      case 'Home':
        e.preventDefault()
        setActiveIndex(0)
        return
      case 'End':
        e.preventDefault()
        setActiveIndex(last)
        return
      case 'Enter':
      case ' ': {
        e.preventDefault()
        const current = options[activeIndex]
        if (current) {
          handleOptionClick(current.value)
        }
        return
      }
      // Stryker disable next-line ConditionalExpression: Equivalent — default is the final switch clause; dropping its `return` is a no-op (no code follows the switch)
      default:
        return
    }
  }

  return (
    <div ref={wrapperRef} className="ssgrid-settings-picker">
      <button
        ref={triggerRef}
        type="button"
        className={
          className !== undefined
            ? `ssgrid-settings-picker__trigger ${className}`
            : 'ssgrid-settings-picker__trigger'
        }
        data-testid={testId}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={isOpen ? listboxId : undefined}
        disabled={disabled}
        onClick={handleTriggerClick}
      >
        <span className="ssgrid-settings-picker__label">{label}</span>
        <i className="ssgrid-settings-picker__caret font-icon-down-open" aria-hidden="true" />
      </button>
      {isOpen && (
        <div
          id={listboxId}
          ref={listboxRef}
          className="ssgrid-settings-picker__listbox"
          role="listbox"
          tabIndex={-1}
          aria-activedescendant={getOptionId(activeIndex)}
          data-testid={`${testId}-listbox`}
          onKeyDown={handleListboxKeyDown}
        >
          {options.map((option, index) => (
            // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the listbox (aria-activedescendant pattern per W3C APG); options are not focusable themselves
            <div
              key={option.value}
              id={getOptionId(index)}
              className="ssgrid-settings-picker__option"
              role="option"
              aria-selected={option.value === selectedValue}
              data-separator={option.value === 'hidden' ? 'true' : undefined}
              tabIndex={index === activeIndex ? 0 : -1}
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
