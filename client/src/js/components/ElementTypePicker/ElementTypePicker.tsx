import { useCallback, useEffect, useId, useRef } from 'react'
import { t } from '@/i18n'
import type { AllowedTypeInfo } from '@/types/elements'

interface ElementTypePickerProps {
  readonly allowedTypes: Record<string, AllowedTypeInfo>
  readonly isOpen: boolean
  readonly onClose: () => void
  readonly onSelect: (className: string) => void
}

export default function ElementTypePicker({
  allowedTypes,
  isOpen,
  onClose,
  onSelect,
}: ElementTypePickerProps) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = dialogRef.current
    if (dialog === null) return

    if (isOpen && !dialog.open) {
      dialog.showModal()
    } else if (!isOpen && dialog.open) {
      dialog.close()
    }
  }, [isOpen])

  const handleClose = useCallback(() => {
    onClose()
  }, [onClose])

  const handleTileClick = useCallback(
    (className: string) => {
      onSelect(className)
      onClose()
    },
    [onSelect, onClose],
  )

  const entries = Object.entries(allowedTypes)

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="element-type-picker"
      aria-labelledby={titleId}
      onClose={handleClose}
    >
      <div className="ssgrid-dialog__header">
        <h3 id={titleId}>{t('WeDevelopGrid.ElementTypePicker.TITLE', 'Add content element')}</h3>
        <button
          type="button"
          className="ssgrid-dialog__close"
          data-testid="element-type-picker-close"
          onClick={handleClose}
          aria-label={t('WeDevelopGrid.ElementTypePicker.CLOSE_LABEL', 'Close')}
        >
          &times;
        </button>
      </div>
      <div className="ssgrid-dialog__body">
        {entries.length > 0 ? (
          <div className="ssgrid-dialog__grid">
            {entries.map(([className, info]) => (
              <button
                key={className}
                type="button"
                className="ssgrid-dialog__tile"
                data-testid="element-type-tile"
                onClick={() => handleTileClick(className)}
              >
                <span
                  className={`ssgrid-dialog__tile-icon ${info.icon}`}
                  data-testid="element-type-icon"
                />
                <span className="ssgrid-dialog__tile-label">{info.label}</span>
                {info.description !== '' && (
                  <span
                    className="ssgrid-dialog__tile-description"
                    data-testid="element-type-description"
                  >
                    {info.description}
                  </span>
                )}
              </button>
            ))}
          </div>
        ) : (
          <p className="ssgrid-dialog__empty">
            {t(
              'WeDevelopGrid.ElementTypePicker.EMPTY_MESSAGE',
              'No content element types available',
            )}
          </p>
        )}
      </div>
    </dialog>
  )
}
