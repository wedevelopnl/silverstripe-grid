import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useRovingList } from '@/hooks/useRovingList'
import { usePlaceSharedBlock } from '@/hooks/useSharedBlockMutations'
import { useSharedBlocks } from '@/hooks/useSharedBlockQueries'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { t } from '@/i18n'
import type { NodeRef } from '@/types/identity'
import type {
  SharedBlockParentType,
  SharedBlockRootType,
  SharedBlockStatus,
} from '@/types/sharedBlocks'

/**
 * The block's root type, as a name rather than the wire enum. It is what decides
 * where the block may be placed, so it earns a spot on every row.
 */
function rootTypeLabel(rootType: SharedBlockRootType): string | null {
  switch (rootType) {
    case 'section':
      return t('WeDevelopGrid.SharedBlockPickerDialog.KIND_SECTION', 'Section')
    case 'row':
      return t('WeDevelopGrid.SharedBlockPickerDialog.KIND_ROW', 'Row')
    case 'column':
      return t('WeDevelopGrid.SharedBlockPickerDialog.KIND_COLUMN', 'Column')
    case 'element':
      return t('WeDevelopGrid.SharedBlockPickerDialog.KIND_ELEMENT', 'Content')
    // An empty block has no root yet; it is filtered out server-side, so this
    // arm only guards the wire type.
    case null:
      return null
  }
}

function usageLabel(count: number): string {
  return count === 1
    ? t('WeDevelopGrid.SharedBlockPickerDialog.USED_ON_ONE', 'Used on {count} page', { count })
    : t('WeDevelopGrid.SharedBlockPickerDialog.USED_ON_MANY', 'Used on {count} pages', { count })
}

/**
 * Reuses the frame's wording rather than minting a parallel key: it is the same
 * fact about the same block, and a second string would drift in translation.
 * `published` needs no mark — a row without one is ready to place.
 */
function statusLabel(status: SharedBlockStatus): string | null {
  switch (status) {
    case 'notPublished':
      return t('WeDevelopGrid.SharedBlockFrame.NOT_PUBLISHED', 'Not published yet')
    case 'modified':
      return t('WeDevelopGrid.SharedBlockFrame.PENDING_CHANGES', 'Unpublished changes')
    case 'published':
      return null
  }
}

interface SharedBlockPickerDialogProps {
  /** Which parent the block lands under — the server filters by root type. */
  readonly parentType: SharedBlockParentType
  readonly parent: NodeRef
  readonly zone?: string
  readonly insertAfterElementID?: number
  /** Place before every existing sibling. Mutually exclusive with insertAfterElementID. */
  readonly insertAtStart?: boolean
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
  insertAtStart,
  isOpen,
  onClose,
}: SharedBlockPickerDialogProps) {
  const { pageId, zone: editorZone } = useGridEditorContext()
  const placeSharedBlock = usePlaceSharedBlock(pageId, editorZone)
  const { data: blocks, isPending, error } = useSharedBlocks(isOpen ? parentType : null)

  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()
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

  const list = useRovingList({ itemCount: visible.length })

  const handleSelect = useCallback(
    (blockId: number) => {
      placeSharedBlock.mutate({ blockId, parent, zone, insertAfterElementID, insertAtStart })
      onClose()
    },
    [insertAfterElementID, insertAtStart, onClose, parent, placeSharedBlock, zone],
  )

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="shared-block-picker"
      aria-labelledby={titleId}
      onClose={onClose}
    >
      <div className="ssgrid-dialog-header">
        <h3 id={titleId}>
          {t('WeDevelopGrid.SharedBlockPickerDialog.TITLE', 'Place a shared block')}
        </h3>
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
          <div
            ref={list.listRef}
            className="ssgrid-dialog-list ssgrid-focus-ring"
            role="listbox"
            aria-label={t('WeDevelopGrid.SharedBlockPickerDialog.LIST_LABEL', 'Shared blocks')}
            tabIndex={0}
            aria-activedescendant={list.getItemId(list.activeIndex)}
            onKeyDown={(e) => {
              if (list.handleNavigationKeyDown(e)) return
              if (e.key === 'Enter' || e.key === ' ') {
                // Cancel Space-scroll / stray <dialog> submit before placing.
                e.preventDefault()
                const block = visible[list.activeIndex]
                if (block !== undefined) {
                  handleSelect(block.id)
                }
              }
            }}
          >
            {visible.map((block, index) => {
              const kind = rootTypeLabel(block.rootType)
              const status = statusLabel(block.status)

              return (
                <div
                  key={block.id}
                  id={list.getItemId(index)}
                  className="ssgrid-dialog-option ssgrid-dialog-option-block"
                  role="option"
                  aria-selected={false}
                  data-active={index === list.activeIndex ? 'true' : undefined}
                  data-testid="shared-block-picker-row"
                  data-block-id={block.id}
                  data-status={block.status}
                  // -1 keeps the listbox the single tab stop: focus stays on the
                  // container and aria-activedescendant does the moving.
                  tabIndex={-1}
                  onClick={() => {
                    list.setActiveIndex(index)
                    handleSelect(block.id)
                  }}
                >
                  <span className="ssgrid-dialog-option-main">
                    <span className="ssgrid-dialog-option-title ssgrid-truncate">
                      {block.title}
                    </span>
                    <span className="ssgrid-dialog-option-meta">
                      {usageLabel(block.usageCount)}
                    </span>
                  </span>
                  <span className="ssgrid-dialog-option-tags">
                    {kind !== null && <span className="ssgrid-dialog-option-kind">{kind}</span>}
                    {status !== null && (
                      <span
                        className="ssgrid-status-badge"
                        data-testid="shared-block-picker-status"
                      >
                        {status}
                      </span>
                    )}
                  </span>
                </div>
              )
            })}
          </div>
        )}
      </div>
    </dialog>
  )
}
