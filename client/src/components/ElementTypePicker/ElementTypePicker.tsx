import { useCallback, useEffect, useRef } from 'react';
import type { AllowedTypeInfo } from '@/types/elements';

interface ElementTypePickerProps {
  readonly allowedTypes: Record<string, AllowedTypeInfo>;
  readonly isOpen: boolean;
  readonly onClose: () => void;
  readonly onSelect: (className: string) => void;
}

export default function ElementTypePicker({
  allowedTypes,
  isOpen,
  onClose,
  onSelect,
}: ElementTypePickerProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog === null) return;

    if (isOpen && !dialog.open) {
      dialog.showModal();
    } else if (!isOpen && dialog.open) {
      dialog.close();
    }
  }, [isOpen]);

  const handleClose = useCallback(() => {
    onClose();
  }, [onClose]);

  const handleTileClick = useCallback(
    (className: string) => {
      onSelect(className);
      onClose();
    },
    [onSelect, onClose],
  );

  const entries = Object.entries(allowedTypes);

  return (
    <dialog
      ref={dialogRef}
      className="element-type-picker"
      data-testid="element-type-picker"
      onClose={handleClose}
    >
      <div className="element-type-picker__header">
        <h3 className="element-type-picker__title">Add content element</h3>
        <button
          type="button"
          className="element-type-picker__close"
          data-testid="element-type-picker-close"
          onClick={handleClose}
          aria-label="Close"
        >
          &times;
        </button>
      </div>
      <div className="element-type-picker__body">
        {entries.length > 0 ? (
          <div className="element-type-picker__grid">
            {entries.map(([className, info]) => (
              <button
                key={className}
                type="button"
                className="element-type-picker__tile"
                data-testid="element-type-tile"
                onClick={() => handleTileClick(className)}
              >
                <span className={`element-type-picker__icon ${info.icon}`} />
                <span className="element-type-picker__label">{info.label}</span>
                {info.description !== '' && (
                  <span className="element-type-picker__description">{info.description}</span>
                )}
              </button>
            ))}
          </div>
        ) : (
          <p className="element-type-picker__empty">No content element types available</p>
        )}
      </div>
    </dialog>
  );
}
