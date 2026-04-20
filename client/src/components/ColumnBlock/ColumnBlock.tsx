import { useCallback, useMemo, useState } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import type { ColumnNode, ViewportSettings } from '@/types/elements';
import type { NodeKey } from '@/types/identity';
import { getElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { useReadonly } from '@/hooks/ReadonlyContext';
import { useViewportContext } from '@/hooks/ViewportContext';
import { useCollapse } from '@/hooks/useCollapseState';
import { useUpdateGridSettings, useCreateContentElement } from '@/hooks/useElementMutations';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { buildBlockClasses } from '@/utils/blockClasses';
import {
  getColumnCount,
  getOffsetStrategy,
  getWidthOptions,
  getOffsetOptions,
  resolveViewportSettings,
} from '@/utils/gridAdapter';
import { t } from '@/i18n';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import ElementActions from '@/components/ElementActions/ElementActions';
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker';
import ElementCard from '@/components/ElementCard/ElementCard';
import EmptyState from '@/components/EmptyState/EmptyState';
import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker';

interface ColumnBlockProps {
  readonly column: ColumnNode;
}

function useColumnCollapse(column: ColumnNode) {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse();
  const isCollapsed = isCollapsedFn(column.nodeKey);
  const onToggle = useCallback(() => toggle(column.nodeKey), [toggle, column.nodeKey]);
  return { isCollapsed, onToggle };
}

function useChildElementKeys(column: ColumnNode): NodeKey[] {
  return useMemo(() => column.children?.map((e) => e.nodeKey) ?? [], [column.children]);
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
export default function ColumnBlock({ column }: ColumnBlockProps) {
  const readonly = useReadonly();
  return readonly ? (
    <ReadonlyColumnBlock column={column} />
  ) : (
    <EditableColumnBlock column={column} />
  );
}

function buildColumnStyle(
  settings: ViewportSettings,
  sortableStyle: React.CSSProperties,
): React.CSSProperties {
  const columnCount = getColumnCount();
  const strategy = getOffsetStrategy();

  if (strategy === 'margin') {
    return {
      ...sortableStyle,
      '--col-width': `${(settings.width / columnCount) * 100}%`,
      ...(settings.offset > 0
        ? { '--col-offset': `${(settings.offset / columnCount) * 100}%` }
        : {}),
    } as React.CSSProperties;
  }

  return {
    ...sortableStyle,
    '--col-span': String(settings.width),
    ...(settings.offset > 0 ? { '--col-start': String(settings.offset + 1) } : {}),
  } as React.CSSProperties;
}

function EditableColumnBlock({ column }: ColumnBlockProps) {
  const { activeViewport } = useViewportContext();
  const { pageId, zone } = useGridEditorContext();
  const columnCount = getColumnCount();
  const settings = resolveViewportSettings(column.gridSettings, activeViewport);
  const status = getElementStatus(column.statusFlags);
  const { isCollapsed, onToggle } = useColumnCollapse(column);
  const { activeType } = useDragContext();
  const updateGridSettings = useUpdateGridSettings(pageId, zone);
  const createContentElement = useCreateContentElement(pageId, zone);
  const [isPickerOpen, setPickerOpen] = useState(false);

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: column.nodeKey });

  const childKeys = useChildElementKeys(column);

  const showDropTarget = isOver && activeType === 'column';
  const isDragActive = activeType !== null;
  const isPickerDisabled = isDragActive || updateGridSettings.isPending;

  const innerClasses = buildBlockClasses('column-block', status, {
    hidden: !settings.visible,
    collapsed: isCollapsed,
    'drop-target': showDropTarget,
  });

  const sortableStyle = buildSortableStyle(transform, transition, isDragging);
  const columnStyle = buildColumnStyle(settings, sortableStyle);

  const widthOptions = getWidthOptions();
  const offsetOptions = getOffsetOptions(settings.width);

  const widthLabel = settings.visible ? `${settings.width}/${columnCount}` : 'hidden';

  const widthSelectedValue = settings.visible ? settings.width : ('hidden' as const);

  const offsetLabel = settings.offset === 0 ? 'none' : `+${settings.offset}`;
  const isOffsetDisabled = isPickerDisabled || settings.width === columnCount || !settings.visible;

  const updateSettings = useCallback(
    (patch: Partial<ViewportSettings>) => {
      updateGridSettings.mutate({
        id: column.id,
        viewport: activeViewport,
        ...settings,
        ...patch,
      });
    },
    [column.id, activeViewport, settings, updateGridSettings],
  );

  const handleWidthSelect = useCallback(
    (value: number | 'hidden') => {
      if (value === 'hidden') {
        updateSettings({ visible: false });
      } else {
        const clampedOffset = Math.min(settings.offset, columnCount - value);
        updateSettings({ width: value, visible: true, offset: clampedOffset });
      }
    },
    [updateSettings, columnCount, settings.offset],
  );

  const handleOffsetSelect = useCallback(
    (value: number | 'hidden') => {
      if (typeof value === 'number') {
        updateSettings({ offset: value });
      }
    },
    [updateSettings],
  );

  const handleOpenPicker = useCallback(() => {
    setPickerOpen(true);
  }, []);

  const handleClosePicker = useCallback(() => {
    setPickerOpen(false);
  }, []);

  const handleTypeSelect = useCallback(
    (className: string) => {
      createContentElement.mutate({
        className,
        parentId: column.id,
      });
    },
    [createContentElement, column.id],
  );

  const children = column.children ?? [];
  const allowedTypes = column.allowedTypes ?? {};
  const hasChildren = children.length > 0;
  const hasAllowedTypes = Object.keys(allowedTypes).length > 0;

  return (
    <div ref={setNodeRef} style={columnStyle} className="row-block__column">
      <div className={innerClasses} data-testid="column-block">
        <div className="column-block__header" data-testid="column-header">
          <DragHandle
            listeners={listeners}
            attributes={attributes}
            label={t('WeDevelopGrid.ColumnBlock.MOVE_LABEL', 'Move {title}', {
              title: column.title,
            })}
          />
          <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={column.title} />
          <i className={`column-block__icon ${column.blockSchema.icon}`} />
          <span className="column-block__title" data-testid="column-title">
            {column.editLink !== null ? (
              <a href={column.editLink} data-testid="column-edit-link">
                {column.title}
              </a>
            ) : (
              column.title
            )}
          </span>
          <div className="column-block__header-meta">
            <GridSettingsPicker
              label={widthLabel}
              options={widthOptions}
              selectedValue={widthSelectedValue}
              disabled={isPickerDisabled}
              testId="column-badge"
              onSelect={handleWidthSelect}
            />
            <GridSettingsPicker
              label={offsetLabel}
              options={offsetOptions}
              selectedValue={settings.offset}
              disabled={isOffsetDisabled}
              testId="column-offset-badge"
              onSelect={handleOffsetSelect}
            />
            <ElementActions node={column} />
          </div>
        </div>
        <div className="column-block__body">
          <SortableContext items={childKeys} strategy={verticalListSortingStrategy}>
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
              className="column-block__add-button"
              data-testid="add-content-button"
              onClick={handleOpenPicker}
            >
              {t('WeDevelopGrid.ColumnBlock.ADD_CONTENT_BUTTON', '+ Add content')}
            </button>
          )}
        </div>
      </div>
      {hasAllowedTypes && (
        <ElementTypePicker
          allowedTypes={allowedTypes}
          isOpen={isPickerOpen}
          onClose={handleClosePicker}
          onSelect={handleTypeSelect}
        />
      )}
    </div>
  );
}

function ReadonlyColumnBlock({ column }: ColumnBlockProps) {
  const { activeViewport } = useViewportContext();
  const settings = resolveViewportSettings(column.gridSettings, activeViewport);
  const status = getElementStatus(column.statusFlags);
  const { isCollapsed, onToggle } = useColumnCollapse(column);

  const innerClasses = buildBlockClasses('column-block', status, {
    hidden: !settings.visible,
    collapsed: isCollapsed,
  });

  // No sortable transform in readonly mode — pass empty style and let
  // buildColumnStyle layer the --col-width / --col-span variables on top.
  const columnStyle = buildColumnStyle(settings, {});

  const children = column.children ?? [];

  return (
    <div style={columnStyle} className="row-block__column">
      <div className={innerClasses} data-testid="column-block">
        <div className="column-block__header" data-testid="column-header">
          <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={column.title} />
          <i className={`column-block__icon ${column.blockSchema.icon}`} />
          <span className="column-block__title" data-testid="column-title">
            {column.title}
          </span>
        </div>
        <div className="column-block__body">
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
  );
}
