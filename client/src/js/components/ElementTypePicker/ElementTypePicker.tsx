import { useCallback, useEffect, useId, useRef } from 'react'
import { t } from '@/i18n'
import type { AllowedTypeInfo } from '@/types/elements'

interface ElementTypePickerProps {
  readonly allowedTypes: Record<string, AllowedTypeInfo>
  readonly isOpen: boolean
  readonly onClose: () => void
  readonly onSelect: (className: string) => void
  /**
   * Adds a "Shared block" tile after the class tiles. Placements carry the
   * block they stand for, which the create endpoint has no slot for, so
   * choosing this tile hands off to the block chooser instead of creating.
   */
  readonly onSelectSharedBlock?: () => void
}

export default function ElementTypePicker({
  allowedTypes,
  isOpen,
  onClose,
  onSelect,
  onSelectSharedBlock,
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

  const handleSharedClick = useCallback(() => {
    onSelectSharedBlock?.()
    onClose()
  }, [onSelectSharedBlock, onClose])

  const entries = Object.entries(allowedTypes)
  const hasSharedTile = onSelectSharedBlock !== undefined

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="element-type-picker"
      aria-labelledby={titleId}
      onClose={handleClose}
    >
      <div className="ssgrid-dialog-header">
        <h3 id={titleId}>{t('WeDevelopGrid.ElementTypePicker.TITLE', 'Add content element')}</h3>
        <button
          type="button"
          className="ssgrid-dialog-close ssgrid-focus-ring"
          data-testid="element-type-picker-close"
          onClick={handleClose}
          aria-label={t('WeDevelopGrid.ElementTypePicker.CLOSE_LABEL', 'Close')}
        >
          &times;
        </button>
      </div>
      <div className="ssgrid-dialog-body">
        {entries.length > 0 || hasSharedTile ? (
          <div className="ssgrid-dialog-grid">
            {entries.map(([className, info]) => (
              <button
                key={className}
                type="button"
                className="ssgrid-dialog-tile ssgrid-focus-ring"
                data-testid="element-type-tile"
                onClick={() => handleTileClick(className)}
              >
                <span
                  className={`ssgrid-dialog-tile-icon ssgrid-type-icon-chip ${info.icon}`}
                  data-testid="element-type-icon"
                />
                <span className="ssgrid-dialog-tile-label">{info.label}</span>
                {info.description !== '' && (
                  <span
                    className="ssgrid-dialog-tile-description"
                    data-testid="element-type-description"
                  >
                    {info.description}
                  </span>
                )}
              </button>
            ))}
            {hasSharedTile && (
              <button
                type="button"
                className="ssgrid-dialog-tile ssgrid-focus-ring"
                data-testid="element-type-tile-shared"
                onClick={handleSharedClick}
              >
                <span className="ssgrid-dialog-tile-icon ssgrid-type-icon-chip font-icon-block-layout" />
                <span className="ssgrid-dialog-tile-label">
                  {t('WeDevelopGrid.ElementTypePicker.SHARED_BLOCK', 'Shared block')}
                </span>
                <span
                  className="ssgrid-dialog-tile-description"
                  data-testid="element-type-description"
                >
                  {t(
                    'WeDevelopGrid.ElementTypePicker.SHARED_BLOCK_DESCRIPTION',
                    'Place a block maintained once in the shared library',
                  )}
                </span>
              </button>
            )}
          </div>
        ) : (
          <p className="ssgrid-dialog-empty">
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
