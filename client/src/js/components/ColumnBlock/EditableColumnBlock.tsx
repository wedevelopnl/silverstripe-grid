import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { memo, useCallback, useMemo, useState } from 'react'
import ColumnInsertButton from '@/components/ColumnInsertButton/ColumnInsertButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import EditableElementCard from '@/components/ElementCard/EditableElementCard'
import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker'
import EmptyState from '@/components/EmptyState/EmptyState'
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker'
import { usePlacement } from '@/components/SharedBlockFrame/PlacementContext'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
import SharedBlockPickerDialog from '@/components/SharedBlockPickerDialog/SharedBlockPickerDialog'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import { useCreateContentElement, useUpdateGridSettings } from '@/hooks/useElementMutations'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import {
  type ColumnNode,
  isSharedBlockReferenceNode,
  isSharedBlockRootNode,
  type ViewportSettings,
} from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import {
  formatOffsetLabel,
  formatWidthLabel,
  getColumnCount,
  getDefaultViewport,
  getOffsetOptions,
  getOffsetStrategy,
  getWidthOptions,
  resolveViewportSettings,
} from '@/utils/gridAdapter'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'
import { hasUnpublishedDescendant } from '@/utils/publishStatus'
import { buildColumnStyle } from './buildColumnStyle'
import ColumnChrome from './ColumnChrome'

/** Identifies the column gutter just before this column as a "+ insert a column here" slot. */
interface ColumnInsertBeforeRef {
  readonly rowId: number
  readonly afterColumnId: number
}

interface EditableColumnBlockProps {
  readonly column: ColumnNode
  readonly insertBefore?: ColumnInsertBeforeRef
}

/**
 * Sortable ids for this container's children, excluding shared block
 * placements.
 *
 * A placement registers no sortable — `SharedBlockFrame` is deliberately not
 * one, and the sortable rendered inside it carries the block ROOT's nodeKey.
 * Leaving its key in `items` puts a hole in dnd-kit's `getSortedRects`, so
 * `getItemGap` reads no rect on either side of it and the siblings after it
 * shift by the wrong distance during a same-container drag. `useGridEditorDnd`
 * filters the page root for exactly this reason.
 */
function useChildElementKeys(column: ColumnNode): NodeKey[] {
  return useMemo(
    () =>
      column.children
        ?.filter((child) => !isSharedBlockReferenceNode(child))
        .map((e) => e.nodeKey) ?? [],
    [column.children],
  )
}

const EditableColumnBlock = memo(function EditableColumnBlockComponent({
  column,
  insertBefore,
}: EditableColumnBlockProps) {
  // No selection yet resolves to the adapter default — the same viewport whose
  // base layout `default` settings represent — so edits target `default`.
  const { activeViewport: selectedViewport } = useViewportContext()
  const activeViewport = selectedViewport ?? getDefaultViewport()
  const { pageId, zone, rootType } = useGridEditorContext()
  // A block may not contain a block — see AddChildButton for the same gate.
  const isLibraryEditor = rootType === 'sharedBlock'
  const columnCount = getColumnCount()
  // Stabilise `settings` so downstream useCallback/useMemo dependencies don't
  // see a fresh object identity on every render of an unrelated parent.
  const settings = useMemo(
    () => resolveViewportSettings(column.gridSettings, activeViewport),
    [column.gridSettings, activeViewport],
  )
  const status = column.status
  const { isCollapsed, onToggle } = useElementCollapse(column.nodeKey)
  const { activeType, pendingActive } = useDragContext()
  const updateGridSettings = useUpdateGridSettings(pageId, zone)
  const createContentElement = useCreateContentElement(pageId, zone)
  const [isPickerOpen, setPickerOpen] = useState(false)
  const [isSharedPickerOpen, setSharedPickerOpen] = useState(false)

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: column.nodeKey })

  const childKeys = useChildElementKeys(column)

  // Inside a placed shared block the content is read-only on the page: edits
  // belong in the library. The size/offset pickers stay visible — they carry
  // layout information — but disabled.
  const insideShared = usePlacement() !== null

  // A block's root has nowhere to be dragged to — see isSharedBlockRootNode.
  const isBlockRoot = isSharedBlockRootNode(column)

  const showDropTarget = isOver && activeType === 'column'
  const isDragActive = activeType !== null
  const isPickerDisabled = isDragActive || updateGridSettings.isPending || insideShared

  const columnStyle = useMemo(
    () => buildColumnStyle(settings, buildSortableStyle(transform, transition, isDragging)),
    [settings, transform, transition, isDragging],
  )

  // A margin offset before this column widens the gutter the "+ insert here"
  // handle sits in; shift the handle (as a % of the column width) back to that
  // gutter's centre so it doesn't hug the column edge. Grid-placement offsets
  // are left alone — see column-insert.css.
  const gutterShiftPct =
    // Stryker disable next-line ConditionalExpression,EqualityOperator: Equivalent — `offset > 0` only differs at offset === 0, where the true branch (0/width)*50 equals the else 0; ColumnInsertButton omits the shift var for a falsy 0 either way
    getOffsetStrategy() === 'margin' && settings.offset > 0
      ? (settings.offset / settings.width) * 50
      : 0

  const widthOptions = getWidthOptions()
  const offsetOptions = getOffsetOptions(settings.width)

  const widthLabel = settings.visible
    ? formatWidthLabel(settings.width)
    : t('WeDevelopGrid.GridSettings.HIDDEN', 'hidden')

  // 'hidden' here is the option sentinel value (matched in the change handlers),
  // NOT a display label, so it must stay the literal string.
  const widthSelectedValue = settings.visible ? settings.width : ('hidden' as const)

  const offsetLabel = formatOffsetLabel(settings.offset)
  const isOffsetDisabled = isPickerDisabled || settings.width === columnCount || !settings.visible

  const updateSettings = useCallback(
    (patch: Partial<ViewportSettings>) => {
      updateGridSettings.mutate({
        element: column.self,
        viewport: activeViewport,
        ...settings,
        ...patch,
      })
    },
    [column.self, activeViewport, settings, updateGridSettings],
  )

  const handleWidthSelect = useCallback(
    (value: number | 'hidden') => {
      if (value === 'hidden') {
        updateSettings({ visible: false })
      } else {
        const clampedOffset = Math.min(settings.offset, columnCount - value)
        updateSettings({ width: value, visible: true, offset: clampedOffset })
      }
    },
    [updateSettings, columnCount, settings.offset],
  )

  const handleOffsetSelect = useCallback(
    (value: number | 'hidden') => {
      if (typeof value === 'number') {
        updateSettings({ offset: value })
      }
    },
    [updateSettings],
  )

  const handleOpenPicker = useCallback(() => {
    setPickerOpen(true)
  }, [])

  const handleClosePicker = useCallback(() => {
    setPickerOpen(false)
  }, [])

  const handleTypeSelect = useCallback(
    (className: string) => {
      createContentElement.mutate({
        className,
        parent: column.self,
      })
    },
    [createContentElement, column.self],
  )

  const handleSelectSharedBlock = useCallback(() => {
    setSharedPickerOpen(true)
  }, [])

  const handleCloseSharedPicker = useCallback(() => {
    setSharedPickerOpen(false)
  }, [])

  const children = column.children ?? []
  const allowedTypes = column.allowedTypes ?? {}
  const hasChildren = children.length > 0
  const hasAllowedTypes = Object.keys(allowedTypes).length > 0

  const trailing = insideShared ? undefined : <ElementActions node={column} kebabOnly />

  // The type picker hands off to the block chooser, so only one of the two is
  // ever mounted; picking the dialog here keeps the JSX below flat.
  const overlay = (() => {
    if (isSharedPickerOpen) {
      return (
        <SharedBlockPickerDialog
          parentType="column"
          parent={column.self}
          isOpen={isSharedPickerOpen}
          onClose={handleCloseSharedPicker}
        />
      )
    }

    if (hasAllowedTypes && isPickerOpen) {
      return (
        <ElementTypePicker
          allowedTypes={allowedTypes}
          isOpen={isPickerOpen}
          onClose={handleClosePicker}
          onSelect={handleTypeSelect}
          onSelectSharedBlock={isLibraryEditor ? undefined : handleSelectSharedBlock}
        />
      )
    }
  })()

  return (
    <ColumnChrome
      status={status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(column)}
      title={column.title}
      titleHref={insideShared ? undefined : (column.editLink ?? undefined)}
      icon={column.blockSchema.icon}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      columnStyle={columnStyle}
      hidden={!settings.visible}
      dropTarget={showDropTarget}
      setNodeRef={setNodeRef}
      insertBefore={
        insertBefore !== undefined && !insideShared ? (
          <ColumnInsertButton
            rowId={insertBefore.rowId}
            placement="between"
            afterColumnId={insertBefore.afterColumnId}
            gutterShiftPct={gutterShiftPct}
          />
        ) : undefined
      }
      leading={
        insideShared || isBlockRoot ? undefined : (
          <DragHandle
            listeners={listeners}
            attributes={attributes}
            label={t('WeDevelopGrid.ColumnBlock.MOVE_LABEL', 'Move {title}', {
              title: column.title,
            })}
          />
        )
      }
      trailing={trailing}
      layoutSettings={
        <>
          <GridSettingsPicker
            className="ssgrid-column-badge ssgrid-focus-ring"
            label={widthLabel}
            options={widthOptions}
            selectedValue={widthSelectedValue}
            disabled={isPickerDisabled}
            testId="column-badge"
            onSelect={handleWidthSelect}
          />
          <GridSettingsPicker
            className="ssgrid-column-badge ssgrid-focus-ring"
            label={offsetLabel}
            options={offsetOptions}
            selectedValue={settings.offset}
            disabled={isOffsetDisabled}
            testId="column-offset-badge"
            onSelect={handleOffsetSelect}
          />
        </>
      }
      footer={
        hasAllowedTypes && !insideShared ? (
          <button
            type="button"
            className="ssgrid-add-child-button ssgrid-focus-ring"
            data-testid="add-content-button"
            onClick={handleOpenPicker}
          >
            {t('WeDevelopGrid.ColumnBlock.ADD_CONTENT_BUTTON', '+ Add content')}
          </button>
        ) : undefined
      }
      overlay={overlay}
    >
      <SortableContext
        items={childKeys}
        strategy={pendingActive ? noopSortingStrategy : verticalListSortingStrategy}
      >
        {hasChildren
          ? children.map((child) => (
              <SharedChild
                key={child.nodeKey}
                child={child}
                siblings={children}
                render={(element) => <EditableElementCard element={element} />}
              />
            ))
          : (!hasAllowedTypes || insideShared) && (
              <EmptyState
                message={t('WeDevelopGrid.ColumnBlock.NO_CONTENT_BLOCKS', 'No content blocks')}
              />
            )}
      </SortableContext>
    </ColumnChrome>
  )
})

export default EditableColumnBlock
