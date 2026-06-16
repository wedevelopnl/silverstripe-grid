import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { memo, useCallback, useMemo, useState } from 'react'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import ColumnInsertButton from '@/components/ColumnInsertButton/ColumnInsertButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import ElementCard from '@/components/ElementCard/ElementCard'
import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker'
import EmptyState from '@/components/EmptyState/EmptyState'
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useReadonly } from '@/hooks/ReadonlyContext'
import { useCollapse } from '@/hooks/useCollapseState'
import { useDragContext } from '@/hooks/useDragAndDrop'
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

/** Identifies the column gutter just before this column as a "+ insert a column here" slot. */
interface ColumnInsertBeforeRef {
  readonly rowId: number
  readonly afterColumnId: number
}

interface ColumnBlockProps {
  readonly column: ColumnNode
  readonly insertBefore?: ColumnInsertBeforeRef
}

function useColumnCollapse(column: ColumnNode) {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse()
  const isCollapsed = isCollapsedFn(column.nodeKey)
  const onToggle = useCallback(() => toggle(column.nodeKey), [toggle, column.nodeKey])
  return { isCollapsed, onToggle }
}

function useChildElementKeys(column: ColumnNode): NodeKey[] {
  return useMemo(() => column.children?.map((e) => e.nodeKey) ?? [], [column.children])
}

/**
 * Column block dispatcher: picks the editable or readonly variant based
 * on the `ReadonlyContext`. The readonly variant drops `useSortable`,
 * both mutation hooks (`useUpdateGridSettings`, `useCreateContentElement`),
 * the grid settings pickers, the element type picker, and the "Add
 * content" button — but keeps the responsive column layout driven by
 * `resolveViewportSettings` so that viewport switching in the history
 * viewer still re-layouts the readonly tree.
 */
const ColumnBlock = memo(function ColumnBlockComponent({ column, insertBefore }: ColumnBlockProps) {
  const readonly = useReadonly()
  return readonly ? (
    <ReadonlyColumnBlock column={column} />
  ) : (
    <EditableColumnBlock column={column} insertBefore={insertBefore} />
  )
})

export default ColumnBlock

function buildColumnStyle(
  settings: ViewportSettings,
  sortableStyle: React.CSSProperties,
): React.CSSProperties {
  const columnCount = getColumnCount()
  const strategy = getOffsetStrategy()

  if (strategy === 'margin') {
    return {
      ...sortableStyle,
      '--col-width': `${(settings.width / columnCount) * 100}%`,
      ...(settings.offset > 0
        ? { '--col-offset': `${(settings.offset / columnCount) * 100}%` }
        : {}),
    } as React.CSSProperties
  }

  return {
    ...sortableStyle,
    '--col-span': String(settings.width),
    ...(settings.offset > 0 ? { '--col-start': String(settings.offset + 1) } : {}),
  } as React.CSSProperties
}

function EditableColumnBlock({ column, insertBefore }: ColumnBlockProps) {
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
  const { isCollapsed, onToggle } = useColumnCollapse(column)
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

  const widthLabel = settings.visible ? `${settings.width}/${columnCount}` : 'hidden'

  const widthSelectedValue = settings.visible ? settings.width : ('hidden' as const)

  const offsetLabel = settings.offset === 0 ? 'none' : `+${settings.offset}`
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
    <div
      ref={setNodeRef}
      style={columnStyle}
      className="ssgrid-column"
      data-testid="column-block-outer"
    >
      {insertBefore !== undefined && (
        <ColumnInsertButton
          rowId={insertBefore.rowId}
          placement="between"
          afterColumnId={insertBefore.afterColumnId}
          gutterShiftPct={gutterShiftPct}
        />
      )}
      <div
        className="ssgrid-column__card"
        data-testid="column-block"
        data-status={status}
        data-collapsed={isCollapsed ? '' : undefined}
        data-drop-target={showDropTarget ? '' : undefined}
        data-hidden={!settings.visible ? '' : undefined}
      >
        <div className="ssgrid-column__header" data-testid="column-header">
          <div className="ssgrid-column__toolbar">
            <DragHandle
              listeners={listeners}
              attributes={attributes}
              label={t('WeDevelopGrid.ColumnBlock.MOVE_LABEL', 'Move {title}', {
                title: column.title,
              })}
            />
            <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={column.title} />
            <i className={`ssgrid-column__icon ${column.blockSchema.icon}`} aria-hidden="true" />
            <span className="ssgrid-column__title" data-testid="column-title">
              {column.editLink !== null ? (
                <a href={column.editLink} data-testid="column-edit-link">
                  {column.title}
                </a>
              ) : (
                column.title
              )}
            </span>
            {status === 'modified' && (
              <span
                className="ssgrid-modified-dot"
                data-testid="column-modified-indicator"
                aria-label={t(
                  'WeDevelopGrid.ColumnBlock.MODIFIED_LABEL',
                  'Has unpublished changes',
                )}
                role="img"
              />
            )}
            <ElementActions node={column} kebabOnly />
          </div>
          <div className="ssgrid-column__layout-settings">
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
          </div>
        </div>
        <div className="ssgrid-column__body">
          <SortableContext
            items={childKeys}
            strategy={pendingActive ? noopSortingStrategy : verticalListSortingStrategy}
          >
            {hasChildren
              ? children.map((child) => <ElementCard key={child.nodeKey} element={child} />)
              : !hasAllowedTypes && (
                  <EmptyState
                    message={t('WeDevelopGrid.ColumnBlock.NO_CONTENT_BLOCKS', 'No content blocks')}
                  />
                )}
          </SortableContext>
          {hasAllowedTypes && (
            <button
              type="button"
              className="ssgrid-column__add-content"
              data-testid="add-content-button"
              onClick={handleOpenPicker}
            >
              {t('WeDevelopGrid.ColumnBlock.ADD_CONTENT_BUTTON', '+ Add content')}
            </button>
          )}
        </div>
      </div>
      {hasAllowedTypes && isPickerOpen && (
        <ElementTypePicker
          allowedTypes={allowedTypes}
          isOpen={isPickerOpen}
          onClose={handleClosePicker}
          onSelect={handleTypeSelect}
        />
      )}
    </div>
  )
}

function ReadonlyColumnBlock({ column }: ColumnBlockProps) {
  const { activeViewport } = useViewportContext()
  const settings = resolveViewportSettings(column.gridSettings, activeViewport)
  const status = column.status
  const { isCollapsed, onToggle } = useColumnCollapse(column)

  // No sortable transform in readonly mode — pass empty style and let
  // buildColumnStyle layer the --col-width / --col-span variables on top.
  const columnStyle = buildColumnStyle(settings, {})

  const children = column.children ?? []

  return (
    <div style={columnStyle} className="ssgrid-column" data-testid="column-block-outer">
      <div
        className="ssgrid-column__card"
        data-testid="column-block"
        data-status={status}
        data-collapsed={isCollapsed ? '' : undefined}
        data-hidden={!settings.visible ? '' : undefined}
      >
        <div className="ssgrid-column__header" data-testid="column-header">
          <div className="ssgrid-column__toolbar">
            <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={column.title} />
            <i className={`ssgrid-column__icon ${column.blockSchema.icon}`} aria-hidden="true" />
            <span className="ssgrid-column__title" data-testid="column-title">
              {column.title}
            </span>
            {status === 'modified' && (
              <span
                className="ssgrid-modified-dot"
                data-testid="column-modified-indicator"
                aria-label={t(
                  'WeDevelopGrid.ColumnBlock.MODIFIED_LABEL',
                  'Has unpublished changes',
                )}
                role="img"
              />
            )}
          </div>
        </div>
        <div className="ssgrid-column__body">
          {children.length > 0 ? (
            children.map((child) => <ElementCard key={child.nodeKey} element={child} />)
          ) : (
            <EmptyState
              message={t('WeDevelopGrid.ColumnBlock.NO_CONTENT_BLOCKS', 'No content blocks')}
            />
          )}
        </div>
      </div>
    </div>
  )
}
