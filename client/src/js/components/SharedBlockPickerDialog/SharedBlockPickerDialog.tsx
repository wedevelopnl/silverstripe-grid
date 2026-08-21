import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { usePlaceSharedBlock } from '@/hooks/useSharedBlockMutations'
import { useSharedBlocks } from '@/hooks/useSharedBlockQueries'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { t } from '@/i18n'
import type { NodeRef } from '@/types/identity'
import type { SharedBlockParentType } from '@/types/sharedBlocks'

interface SharedBlockPickerDialogProps {
  /** Which parent the block lands under — the server filters by root type. */
  readonly parentType: SharedBlockParentType
  readonly parent: NodeRef
  readonly zone?: string
  readonly insertAfterElementID?: number
  readonly isOpen: boolean
  readonly onClose: () => void
}

/**
 * Choose a block from the library and place it.
 *
 * The list is already narrowed server-side to blocks whose root type fits this
 * parent, so nothing offered here can be rejected on submit. Search filters the
 * fetched list client-side: the library is a curated, human-sized set, so a
 * round trip per keystroke would buy nothing.
 */
export default function SharedBlockPickerDialog({
  parentType,
  parent,
  zone,
  insertAfterElementID,
  isOpen,
  onClose,
}: SharedBlockPickerDialogProps) {
  const { pageId, zone: editorZone } = useGridEditorContext()
  const placeSharedBlock = usePlaceSharedBlock(pageId, editorZone)
  const { data: blocks, isPending, error } = useSharedBlocks(isOpen ? parentType : null)

  const dialogRef = useRef<HTMLDialogElement>(null)
  const [search, setSearch] = useState('')

  useEffect(() => {
    const dialog = dialogRef.current
    if (dialog === null) return

    if (isOpen && !dialog.open) {
      dialog.showModal()
    } else if (!isOpen && dialog.open) {
      dialog.close()
    }
  }, [isOpen])

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase()
    const all = blocks ?? []

    return needle === '' ? all : all.filter((block) => block.title.toLowerCase().includes(needle))
  }, [blocks, search])

  const handleSelect = useCallback(
    (blockId: number) => {
      placeSharedBlock.mutate({ blockId, parent, zone, insertAfterElementID })
      onClose()
    },
    [insertAfterElementID, onClose, parent, placeSharedBlock, zone],
  )

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="shared-block-picker"
      onClose={onClose}
    >
      <div className="ssgrid-dialog-header">
        <h3>{t('WeDevelopGrid.SharedBlockPickerDialog.TITLE', 'Place a shared block')}</h3>
        <button
          type="button"
          className="ssgrid-dialog-close ssgrid-focus-ring"
          data-testid="shared-block-picker-close"
          onClick={onClose}
          aria-label={t('WeDevelopGrid.SharedBlockPickerDialog.CLOSE_LABEL', 'Close')}
        >
          &times;
        </button>
      </div>

      <div className="ssgrid-dialog-body">
        <input
          type="search"
          className="ssgrid-dialog-search ssgrid-focus-ring"
          data-testid="shared-block-picker-search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder={t('WeDevelopGrid.SharedBlockPickerDialog.SEARCH', 'Search blocks')}
          aria-label={t('WeDevelopGrid.SharedBlockPickerDialog.SEARCH', 'Search blocks')}
        />

        {error !== null && (
          <p className="ssgrid-dialog-error">
            {t(
              'WeDevelopGrid.SharedBlockPickerDialog.ERROR',
              'Failed to load shared blocks: {message}',
              {
                message: error.message,
              },
            )}
          </p>
        )}

        {error === null && isPending && (
          <p className="ssgrid-dialog-empty">
            {t('WeDevelopGrid.SharedBlockPickerDialog.LOADING', 'Loading shared blocks...')}
          </p>
        )}

        {error === null && !isPending && visible.length === 0 && (
          <p className="ssgrid-dialog-empty">
            {t(
              'WeDevelopGrid.SharedBlockPickerDialog.EMPTY',
              'No shared block fits here yet. Create one from an existing element with "Convert to shared block".',
            )}
          </p>
        )}

        {visible.length > 0 && (
          <div className="ssgrid-dialog-list ssgrid-focus-ring" role="listbox">
            {visible.map((block) => (
              <button
                key={block.id}
                type="button"
                className="ssgrid-dialog-option ssgrid-focus-ring"
                data-testid="shared-block-picker-row"
                data-block-id={block.id}
                data-status={block.status}
                onClick={() => handleSelect(block.id)}
              >
                <span>{block.title}</span>
                <span>
                  {t(
                    'WeDevelopGrid.SharedBlockPickerDialog.ROW_META',
                    '{rootType} · used on {count} pages',
                    { rootType: block.rootType ?? '—', count: block.usageCount },
                  )}
                </span>
              </button>
            ))}
          </div>
        )}
      </div>
    </dialog>
  )
}
