import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { memo, useCallback, useMemo, useState } from 'react'
import ColumnInsertButton from '@/components/ColumnInsertButton/ColumnInsertButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import EditableElementCard from '@/components/ElementCard/EditableElementCard'
import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker'
import EmptyState from '@/components/EmptyState/EmptyState'
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import { useCreateContentElement, useUpdateGridSettings } from '@/hooks/useElementMutations'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import type { ColumnNode, ViewportSettings } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import {
  getColumnCount,
  getDefaultViewport,
  getOffsetOptions,
  getOffsetStrategy,
  getWidthOptions,
  resolveViewportSettings,
} from '@/utils/gridAdapter'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'
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

function useChildElementKeys(column: ColumnNode): NodeKey[] {
  // biome-ignore lint/suspicious/noUnnecessaryConditions: column.children is `SimpleElementNode[] | null`; the ?? [] fallback is required — dropping it fails typecheck.
  return useMemo(() => column.children?.map((e) => e.nodeKey) ?? [], [column.children])
}

const EditableColumnBlock = memo(function EditableColumnBlockComponent({
  column,
  insertBefore,
}: EditableColumnBlockProps) {
  // No selection yet resolves to the adapter default — the same viewport whose
  // base layout `default` settings represent — so edits target `default`.
  const { activeViewport: selectedViewport } = useViewportContext()
  const activeViewport = selectedViewport ?? getDefaultViewport()
  const { pageId, zone } = useGridEditorContext()
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

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: column.nodeKey })

  const childKeys = useChildElementKeys(column)

  const showDropTarget = isOver && activeType === 'column'
  const isDragActive = activeType !== null
  const isPickerDisabled = isDragActive || updateGridSettings.isPending

  const columnStyle = useMemo(
    () => buildColumnStyle(settings, buildSortableStyle(transform, transition, isDragging)),
    [settings, transform, transition, isDragging],
  )

  // A margin offset before this column widens the gutter the "+ insert here"
  // handle sits in; shift the handle (as a % of the column width) back to that
  // gutter's centre so it doesn't hug the column edge. Grid-placement offsets
  // are left alone — see _column-insert.scss.
  const gutterShiftPct =
    getOffsetStrategy() === 'margin' && settings.offset > 0
      ? (settings.offset / settings.width) * 50
      : 0

  const widthOptions = getWidthOptions()
  const offsetOptions = getOffsetOptions(settings.width)

  const widthLabel = settings.visible
    ? `${settings.width}/${columnCount}`
    : t('WeDevelopGrid.GridSettings.HIDDEN', 'hidden')

  // 'hidden' here is the option sentinel value (matched in the change handlers),
  // NOT a display label, so it must stay the literal string.
  const widthSelectedValue = settings.visible ? settings.width : ('hidden' as const)

  const offsetLabel =
    settings.offset === 0 ? t('WeDevelopGrid.GridSettings.OFFSET_NONE', 'none') : `+${settings.offset}`
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

  const children = column.children ?? []
  const allowedTypes = column.allowedTypes ?? {}
  const hasChildren = children.length > 0
  const hasAllowedTypes = Object.keys(allowedTypes).length > 0

  return (
    <ColumnChrome
      status={status}
      title={column.title}
      titleHref={column.editLink ?? undefined}
      icon={column.blockSchema.icon}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      columnStyle={columnStyle}
      hidden={!settings.visible}
      dropTarget={showDropTarget}
      setNodeRef={setNodeRef}
      insertBefore={
        insertBefore !== undefined ? (
          <ColumnInsertButton
            rowId={insertBefore.rowId}
            placement="between"
            afterColumnId={insertBefore.afterColumnId}
            gutterShiftPct={gutterShiftPct}
          />
        ) : undefined
      }
      leading={
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.ColumnBlock.MOVE_LABEL', 'Move {title}', {
            title: column.title,
          })}
        />
      }
      trailing={<ElementActions node={column} kebabOnly />}
      layoutSettings={
        <>
          <GridSettingsPicker
            className="ssgrid-column__badge"
            label={widthLabel}
            options={widthOptions}
            selectedValue={widthSelectedValue}
            disabled={isPickerDisabled}
            testId="column-badge"
            onSelect={handleWidthSelect}
          />
          <GridSettingsPicker
            className="ssgrid-column__badge"
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
        hasAllowedTypes ? (
          <button
            type="button"
            className="ssgrid-column__add-content"
            data-testid="add-content-button"
            onClick={handleOpenPicker}
          >
            {t('WeDevelopGrid.ColumnBlock.ADD_CONTENT_BUTTON', '+ Add content')}
          </button>
        ) : undefined
      }
      overlay={
        hasAllowedTypes && isPickerOpen ? (
          <ElementTypePicker
            allowedTypes={allowedTypes}
            isOpen={isPickerOpen}
            onClose={handleClosePicker}
            onSelect={handleTypeSelect}
          />
        ) : undefined
      }
    >
      <SortableContext
        items={childKeys}
        strategy={pendingActive ? noopSortingStrategy : verticalListSortingStrategy}
      >
        {hasChildren
          ? children.map((child) => <EditableElementCard key={child.nodeKey} element={child} />)
          : !hasAllowedTypes && (
              <EmptyState
                message={t('WeDevelopGrid.ColumnBlock.NO_CONTENT_BLOCKS', 'No content blocks')}
              />
            )}
      </SortableContext>
    </ColumnChrome>
  )
})

export default EditableColumnBlock
