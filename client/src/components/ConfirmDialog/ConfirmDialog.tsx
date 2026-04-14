import { useCallback, useEffect, useRef } from 'react';
import './ConfirmDialog.scss';

interface ConfirmDialogProps {
  readonly isOpen: boolean;
  readonly title: string;
  readonly message: string;
  readonly confirmLabel: string;
  readonly onConfirm: () => void;
  readonly onCancel: () => void;
  readonly destructive?: boolean;
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
    onCancel();
  }, [onCancel]);

  return (
    <dialog
      ref={dialogRef}
      className="confirm-dialog"
      data-testid="confirm-dialog"
      onClose={handleClose}
      // onClick guard prevents clicks inside the dialog from bubbling to
      // ancestor ElementCard anchors. Both preventDefault and stopPropagation
      // are required: stopPropagation blocks React handlers on the anchor,
      // preventDefault cancels the browser's default anchor navigation. Rule
      // disabled for this file via biome.json overrides (<dialog> is natively
      // interactive; biome's a11y rules do not recognize it).
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
      }}
    >
      <div className="confirm-dialog__header">
        <h3 className="confirm-dialog__title">{title}</h3>
      </div>
      <div className="confirm-dialog__body">
        <p className="confirm-dialog__message">{message}</p>
      </div>
      <div className="confirm-dialog__footer">
        <button
          type="button"
          className="confirm-dialog__button confirm-dialog__button--cancel"
          onClick={handleClose}
        >
          Cancel
        </button>
        <button
          type="button"
          className={`confirm-dialog__button confirm-dialog__button--confirm${destructive ? ' confirm-dialog__button--destructive' : ''}`}
          onClick={onConfirm}
        >
          {confirmLabel}
        </button>
      </div>
    </dialog>
  );
}
