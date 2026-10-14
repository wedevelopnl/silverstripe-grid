import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useRovingList } from '@/hooks/useRovingList'
import { t } from '@/i18n'
import type { AllowedTypeInfo } from '@/types/elements'

interface ElementTypePickerProps {
  readonly allowedTypes: Record<string, AllowedTypeInfo>
  readonly isOpen: boolean
  readonly onClose: () => void
  readonly onSelect: (className: string) => void
  /**
   * Adds a "Place shared block" action to the footer. Placements carry the
   * block they stand for, which the create endpoint has no slot for, so the
   * action hands off to the block chooser instead of creating.
   */
  readonly onSelectSharedBlock?: () => void
}

/**
 * Search-first picker: a project can register any number of element types, so
 * the list filters on label and description as the editor types, and the
 * search field keeps focus while the arrow keys move through the matches
 * (APG combobox with a listbox popup).
 */
export default function ElementTypePicker({
  allowedTypes,
  isOpen,
  onClose,
  onSelect,
  onSelectSharedBlock,
}: ElementTypePickerProps) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const titleId = useId()
  const listId = useId()
  const [search, setSearch] = useState('')

  useEffect(() => {
    const dialog = dialogRef.current
    if (dialog === null) return

    if (isOpen && !dialog.open) {
      dialog.showModal()
      // showModal focuses the first focusable element, which is the close button.
      searchRef.current?.focus()
    } else if (!isOpen && dialog.open) {
      dialog.close()
    }
  }, [isOpen])

  // Server order is class discovery order, which means nothing to an editor.
  const types = useMemo(
    () => Object.entries(allowedTypes).sort(([, a], [, b]) => a.label.localeCompare(b.label)),
    [allowedTypes],
  )

  const needle = search.trim().toLowerCase()
  const visible = useMemo(
    () =>
      needle === ''
        ? types
        : types.filter(
            ([, info]) =>
              info.label.toLowerCase().includes(needle) ||
              info.description.toLowerCase().includes(needle),
          ),
    [types, needle],
  )

  const list = useRovingList({ itemCount: visible.length })

  const handleSelect = useCallback(
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

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-testid="element-type-picker"
      aria-labelledby={titleId}
      onClose={onClose}
    >
      <div className="ssgrid-dialog-header">
        <h3 id={titleId}>{t('WeDevelopGrid.ElementTypePicker.TITLE', 'Add content element')}</h3>
        <button
          type="button"
          className="ssgrid-dialog-close ssgrid-focus-ring"
          data-testid="element-type-picker-close"
          onClick={onClose}
          aria-label={t('WeDevelopGrid.ElementTypePicker.CLOSE_LABEL', 'Close')}
        >
          &times;
        </button>
      </div>

      {types.length > 0 && (
        <div className="ssgrid-dialog-toolbar">
          <input
            ref={searchRef}
            type="search"
            className="ssgrid-dialog-search ssgrid-focus-ring"
            data-testid="element-type-picker-search"
            role="combobox"
            aria-expanded={visible.length > 0}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={
              visible.length > 0 ? list.getItemId(list.activeIndex) : undefined
            }
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              list.setActiveIndex(0)
            }}
            onKeyDown={(e) => {
              // Home/End stay with the caret; only the arrows drive the list.
              if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                list.handleNavigationKeyDown(e)
                return
              }
              if (e.key === 'Enter') {
                e.preventDefault()
                const entry = visible[list.activeIndex]
                if (entry !== undefined) {
                  handleSelect(entry[0])
                }
              }
            }}
            placeholder={t(
              'WeDevelopGrid.ElementTypePicker.SEARCH_PLACEHOLDER',
              'Search {count} element types',
              { count: types.length },
            )}
            aria-label={t('WeDevelopGrid.ElementTypePicker.SEARCH_LABEL', 'Search element types')}
          />
        </div>
      )}

      <div className="ssgrid-dialog-body">
        {types.length === 0 && (
          <p className="ssgrid-dialog-empty">
            {t(
              'WeDevelopGrid.ElementTypePicker.EMPTY_MESSAGE',
              'No content element types available',
            )}
          </p>
        )}

        {types.length > 0 && visible.length === 0 && (
          <p className="ssgrid-dialog-empty" data-testid="element-type-picker-no-match">
            {t('WeDevelopGrid.ElementTypePicker.NO_MATCH', 'No element type matches "{search}"', {
              search: search.trim(),
            })}
          </p>
        )}

        {visible.length > 0 && (
          <div
            id={listId}
            className="ssgrid-dialog-options"
            role="listbox"
            aria-label={t('WeDevelopGrid.ElementTypePicker.LIST_LABEL', 'Element types')}
          >
            {visible.map(([className, info], index) => (
              // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the search combobox (aria-activedescendant per W3C APG); options are not focusable themselves
              <div
                key={className}
                id={list.getItemId(index)}
                className="ssgrid-dialog-option ssgrid-dialog-option-type"
                role="option"
                aria-selected={false}
                data-active={index === list.activeIndex ? 'true' : undefined}
                data-testid="element-type-option"
                tabIndex={-1}
                onClick={() => handleSelect(className)}
              >
                <span
                  className={`ssgrid-type-icon-chip ${info.icon}`}
                  data-testid="element-type-icon"
                />
                <span className="ssgrid-dialog-option-main">
                  <span className="ssgrid-dialog-option-title">{info.label}</span>
                  {info.description !== '' && (
                    <span
                      className="ssgrid-dialog-option-description"
                      data-testid="element-type-description"
                    >
                      {info.description}
                    </span>
                  )}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

      {onSelectSharedBlock !== undefined && (
        <div className="ssgrid-dialog-footer">
          <div className="ssgrid-dialog-actions">
            <button
              type="button"
              className="ssgrid-button ssgrid-focus-ring"
              data-variant="ghost"
              data-testid="element-type-picker-shared"
              onClick={handleSharedClick}
            >
              {t('WeDevelopGrid.ElementTypePicker.PLACE_SHARED_BLOCK', 'Place shared block…')}
            </button>
          </div>
        </div>
      )}
    </dialog>
  )
}
