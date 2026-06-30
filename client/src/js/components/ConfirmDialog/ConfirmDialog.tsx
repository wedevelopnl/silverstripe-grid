import { useCallback, useEffect, useRef } from 'react'
import { t } from '@/i18n'

interface ConfirmDialogProps {
  readonly isOpen: boolean
  readonly title: string
  readonly message: string
  readonly confirmLabel: string
  readonly onConfirm: () => void
  readonly onCancel: () => void
  readonly destructive?: boolean
}

export default function ConfirmDialog({
  isOpen,
  title,
  message,
  confirmLabel,
  onConfirm,
  onCancel,
  destructive = false,
}: ConfirmDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null)

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
    onCancel()
  }, [onCancel])

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="confirm-dialog"
      onClose={handleClose}
      // onClick guard prevents clicks inside the dialog from bubbling to
      // ancestor ElementCard anchors. Both preventDefault and stopPropagation
      // are required: stopPropagation blocks React handlers on the anchor,
      // preventDefault cancels the browser's default anchor navigation. Rule
      // disabled for this file via biome.json overrides (<dialog> is natively
      // interactive; biome's a11y rules do not recognize it).
      onClick={(e) => {
        e.preventDefault()
        e.stopPropagation()
      }}
    >
      <div className="ssgrid-dialog__header">
        <h3>{title}</h3>
      </div>
      <div className="ssgrid-dialog__body">
        <p>{message}</p>
      </div>
      <div className="ssgrid-dialog__footer">
        <div className="ssgrid-dialog__actions">
          <button
            type="button"
            className="ssgrid-button ssgrid-button--ghost"
            onClick={handleClose}
          >
            {t('WeDevelopGrid.ConfirmDialog.CANCEL_BUTTON', 'Cancel')}
          </button>
          <button
            type="button"
            className={`ssgrid-button ${destructive ? 'ssgrid-button--danger' : 'ssgrid-button--primary'}`}
            data-destructive={destructive ? 'true' : undefined}
            onClick={onConfirm}
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </dialog>
  )
}
