import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useDeleteSharedBlock, useSharedBlockUsage } from '@/hooks/useSharedBlockDelete'
import { t } from '@/i18n'
import type { SharedBlockDeleteMode } from '@/types/sharedBlocks'

interface SharedBlockDeleteDialogProps {
  readonly isOpen: boolean
  readonly blockId: number
  readonly blockTitle: string
  readonly onCancel: () => void
  readonly onDeleted: () => void
}

/**
 * Confirmation for retiring a block from the library.
 *
 * The block is deletable whatever its usage, so the dialog's job is not to
 * grant permission but to make the reach visible and force the one decision
 * that cannot be inferred: whether the consuming pages keep the content. There
 * is deliberately no preselected mode — a default would be chosen by accident
 * on exactly the blocks where the choice matters most.
 */
export default function SharedBlockDeleteDialog({
  isOpen,
  blockId,
  blockTitle,
  onCancel,
  onDeleted,
}: SharedBlockDeleteDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()
  const [mode, setMode] = useState<SharedBlockDeleteMode | null>(null)

  const usage = useSharedBlockUsage(blockId, isOpen)
  const remove = useDeleteSharedBlock(blockId)

  useEffect(() => {
    if (isOpen) {
      setMode(null)
      remove.reset()
    }
    // remove.reset is stable across renders; listing the mutation object would
    // re-run this on every state change it makes, wiping the error it just set.
  }, [isOpen, remove.reset])

  useEffect(() => {
    const dialog = dialogRef.current
    if (dialog === null) return

    if (isOpen && !dialog.open) {
      dialog.showModal()
    } else if (!isOpen && dialog.open) {
      dialog.close()
    }
  }, [isOpen])

  const usageCount = usage.data?.usageCount ?? 0
  const liveUsageCount = usage.data?.liveUsageCount ?? 0

  const handleConfirm = useCallback(() => {
    // With no placements the two modes do the same thing, so no choice is
    // asked for and either value is correct.
    const chosen = usageCount > 0 ? mode : 'remove'
    if (chosen === null) return

    remove.mutate(chosen, { onSuccess: onDeleted })
  }, [mode, usageCount, remove, onDeleted])

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="shared-block-delete-dialog"
      aria-labelledby={titleId}
      onClose={onCancel}
    >
      <div className="ssgrid-dialog-header">
        <h3 id={titleId}>
          {t('WeDevelopGrid.SharedBlockDeleteDialog.TITLE', 'Delete "{title}"?', {
            title: blockTitle,
          })}
        </h3>
      </div>

      <div className="ssgrid-dialog-body">
        {usage.isLoading && (
          <p data-testid="shared-block-delete-loading">
            {t(
              'WeDevelopGrid.SharedBlockDeleteDialog.LOADING',
              'Checking where this block is used…',
            )}
          </p>
        )}

        {usage.isError && (
          <p
            className="ssgrid-dialog-error"
            role="alert"
            data-testid="shared-block-delete-usage-error"
          >
            {t(
              'WeDevelopGrid.SharedBlockDeleteDialog.USAGE_FAILED',
              'Could not check where this block is used, so it cannot be deleted safely right now: {message}',
              { message: usage.error.message },
            )}
          </p>
        )}

        {usage.data !== undefined && usageCount === 0 && (
          <p data-testid="shared-block-delete-unused">
            {t(
              'WeDevelopGrid.SharedBlockDeleteDialog.NOT_PLACED',
              'This block is not placed on any page. Deleting it affects nothing else.',
            )}
          </p>
        )}

        {usage.data !== undefined && usageCount > 0 && (
          <>
            <p data-testid="shared-block-delete-usage">
              {liveUsageCount > 0
                ? t(
                    'WeDevelopGrid.SharedBlockDeleteDialog.USAGE_WITH_LIVE',
                    'It is placed on {count} pages, {live} of them published. Choose what happens to that content:',
                    { count: String(usageCount), live: String(liveUsageCount) },
                  )
                : t(
                    'WeDevelopGrid.SharedBlockDeleteDialog.USAGE',
                    'It is placed on {count} pages, none of them published. Choose what happens to that content:',
                    { count: String(usageCount) },
                  )}
            </p>

            <div className="ssgrid-dialog-choices">
              <label className="ssgrid-dialog-choice">
                <input
                  type="radio"
                  className="ssgrid-focus-ring"
                  name="shared-block-delete-mode"
                  value="remove"
                  checked={mode === 'remove'}
                  onChange={() => setMode('remove')}
                />
                <span>
                  {t(
                    'WeDevelopGrid.SharedBlockDeleteDialog.MODE_REMOVE',
                    'Remove it from all {count} pages',
                    { count: String(usageCount) },
                  )}
                </span>
              </label>

              <label className="ssgrid-dialog-choice">
                <input
                  type="radio"
                  className="ssgrid-focus-ring"
                  name="shared-block-delete-mode"
                  value="unshare"
                  checked={mode === 'unshare'}
                  onChange={() => setMode('unshare')}
                />
                <span>
                  {t(
                    'WeDevelopGrid.SharedBlockDeleteDialog.MODE_UNSHARE',
                    'Keep it on each page as its own copy',
                  )}
                </span>
              </label>
            </div>
          </>
        )}
      </div>

      <div className="ssgrid-dialog-footer">
        {remove.isError && (
          <p className="ssgrid-dialog-error" role="alert" data-testid="shared-block-delete-error">
            {remove.error.message}
          </p>
        )}
        <div className="ssgrid-dialog-actions">
          <button
            type="button"
            className="ssgrid-button ssgrid-focus-ring"
            data-variant="ghost"
            data-testid="shared-block-delete-cancel"
            onClick={onCancel}
          >
            {t('WeDevelopGrid.SharedBlockDeleteDialog.CANCEL_BUTTON', 'Cancel')}
          </button>
          <button
            type="button"
            className="ssgrid-button ssgrid-focus-ring"
            data-variant="danger"
            data-destructive="true"
            data-testid="shared-block-delete-confirm"
            // An unplaced block needs no choice, so its delete is one click; a
            // placed one stays disabled until the author has made theirs.
            disabled={
              usage.data === undefined || remove.isPending || (usageCount > 0 && mode === null)
            }
            onClick={handleConfirm}
          >
            {t('WeDevelopGrid.SharedBlockDeleteDialog.CONFIRM_BUTTON', 'Delete block')}
          </button>
        </div>
      </div>
    </dialog>
  )
}
