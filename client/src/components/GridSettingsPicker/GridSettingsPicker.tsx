import { useCallback, useEffect, useRef, useState } from 'react';
import type { GridSettingsOption } from '@/types/gridSettings';
import './GridSettingsPicker.scss';

export type { GridSettingsOption } from '@/types/gridSettings';

interface GridSettingsPickerProps {
  readonly label: string;
  readonly options: readonly GridSettingsOption[];
  readonly selectedValue: number | 'hidden';
  readonly disabled: boolean;
  readonly testId: string;
  readonly onSelect: (value: number | 'hidden') => void;
}

export default function GridSettingsPicker({
  label,
  options,
  selectedValue,
  disabled,
  testId,
  onSelect,
}: GridSettingsPickerProps) {
  const [isOpen, setIsOpen] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const listboxId = `${testId}-listbox`;

  const close = useCallback(() => setIsOpen(false), []);

  // Close on outside click
  useEffect(() => {
    if (!isOpen) return;

    function handleMouseDown(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        close();
      }
    }

    document.addEventListener('mousedown', handleMouseDown);
    return () => document.removeEventListener('mousedown', handleMouseDown);
  }, [isOpen, close]);

  // Close on Escape
  useEffect(() => {
    if (!isOpen) return;

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation();
        close();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, close]);

  function handleTriggerClick(e: React.MouseEvent) {
    e.stopPropagation();
    if (!disabled) {
      setIsOpen((prev) => !prev);
    }
  }

  function handleOptionClick(value: number | 'hidden') {
    onSelect(value);
    close();
  }

  return (
    <div ref={wrapperRef} className="grid-settings-picker">
      <button
        type="button"
        className={`grid-settings-picker__trigger${disabled ? ' grid-settings-picker__trigger--disabled' : ''}`}
        data-testid={testId}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={isOpen ? listboxId : undefined}
        disabled={disabled}
        onClick={handleTriggerClick}
      >
        {label}
      </button>
      {isOpen && (
        <ul
          id={listboxId}
          className="grid-settings-picker__options"
          role="listbox"
          data-testid={`${testId}-listbox`}
        >
          {options.map((option) => (
            <li
              key={option.value}
              className={`grid-settings-picker__option${option.value === selectedValue ? ' grid-settings-picker__option--selected' : ''}${option.value === 'hidden' ? ' grid-settings-picker__option--separator' : ''}`}
              role="option"
              aria-selected={option.value === selectedValue}
              onClick={() => handleOptionClick(option.value)}
            >
              {option.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
