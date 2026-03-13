import { useCallback, useState } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import type { ViewportSettings } from '@/types/elements';
import type { EnrichedColumnNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { useViewportContext } from '@/hooks/ViewportContext';
import { useUpdateGridSettings, useCreateContentElement } from '@/hooks/useElementMutations';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { buildBlockClasses } from '@/utils/blockClasses';
import { getColumnCount, getOffsetStrategy, getWidthOptions, getOffsetOptions, resolveViewportSettings } from '@/utils/gridAdapter';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import ActionsMenu from '@/components/ActionsMenu/ActionsMenu';
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog';
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker';
import ElementCard from '@/components/ElementCard/ElementCard';
import EmptyState from '@/components/EmptyState/EmptyState';
import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker';
import { useArchiveAction } from '@/hooks/useArchiveAction';
import { useDuplicateAction } from '@/hooks/useDuplicateAction';

interface ColumnBlockProps {
  readonly column: EnrichedColumnNode;
}

export default function ColumnBlock({ column }: ColumnBlockProps) {
  const { activeViewport } = useViewportContext();
  const { pageId, zone } = useGridEditorContext();
  const columnCount = getColumnCount();
  const settings = resolveViewportSettings(column.gridSettings, activeViewport);
  const status = getElementStatus(column.statusFlags);
  const { isCollapsed, toggle } = column;
  const { activeType } = useDragContext();
  const updateGridSettings = useUpdateGridSettings(pageId, zone);
  const createContentElement = useCreateContentElement(pageId, zone);
  const { action: archiveAction, dialog: archiveDialog } = useArchiveAction(column);
  const { action: duplicateAction } = useDuplicateAction(column);
  const actions = [
    ...(duplicateAction !== null ? [duplicateAction] : []),
    ...(archiveAction !== null ? [archiveAction] : []),
  ];
  const [isPickerOpen, setPickerOpen] = useState(false);

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } = useSortable({ id: column.sortableId });

  const strategy = getOffsetStrategy();

  const showDropTarget = isOver && activeType === 'column';
  const isDragActive = activeType !== null;
  const isPickerDisabled = isDragActive || updateGridSettings.isPending;

  const innerClasses = buildBlockClasses('column-block', status, {
    hidden: !settings.visible,
    collapsed: isCollapsed,
    'drop-target': showDropTarget,
  });

  const sortableStyle = buildSortableStyle(transform, transition, isDragging);

  const columnStyle = strategy === 'margin'
    ? {
      ...sortableStyle,
      '--col-width': `${(settings.width / columnCount) * 100}%`,
      ...(settings.offset > 0 ? { '--col-offset': `${(settings.offset / columnCount) * 100}%` } : {}),
    } as React.CSSProperties
    : {
      ...sortableStyle,
      '--col-span': String(settings.width),
      ...(settings.offset > 0 ? { '--col-start': String(settings.offset + 1) } : {}),
    } as React.CSSProperties;

  const widthOptions = getWidthOptions();
  const offsetOptions = getOffsetOptions(settings.width);

  const widthLabel = settings.visible
    ? `${settings.width}/${columnCount}`
    : 'hidden';

  const widthSelectedValue = settings.visible ? settings.width : 'hidden' as const;

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
        const maxOffset = columnCount - value;
        const clampedOffset = settings.offset > maxOffset ? maxOffset : settings.offset;
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

  const hasAllowedTypes = column.allowedTypes !== null && Object.keys(column.allowedTypes).length > 0;
  const hasChildren = column.children !== null && column.children.length > 0;

  return (
    <div ref={setNodeRef} style={columnStyle} className="row-block__column">
      <div className={innerClasses} data-testid="column-block">
        <div className="column-block__header" data-testid="column-header">
          <DragHandle listeners={listeners} attributes={attributes} label={`Move ${column.title}`} />
          <CollapseToggle isCollapsed={isCollapsed} onToggle={toggle} label={column.title} />
          <i className={`column-block__icon ${column.blockSchema.icon}`} />
          <span className="column-block__title" data-testid="column-title">
            {column.editLink !== null
              ? <a href={column.editLink} data-testid="column-edit-link">{column.title}</a>
              : column.title}
          </span>
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
          <ActionsMenu actions={actions} />
        </div>
        {archiveDialog !== null && archiveDialog.isOpen && (
          <ConfirmDialog
            isOpen={archiveDialog.isOpen}
            title={archiveDialog.title}
            message={archiveDialog.message}
            confirmLabel="Archive"
            onConfirm={archiveDialog.onConfirm}
            onCancel={archiveDialog.onCancel}
            destructive
          />
        )}
        <div className="column-block__body">
          <SortableContext items={column.childSortableIds} strategy={verticalListSortingStrategy}>
            {hasChildren
              ? column.children!.map((child) => (
                <ElementCard key={child.id} element={child} />
              ))
              : !hasAllowedTypes && <EmptyState message="No content blocks" />}
          </SortableContext>
          {hasAllowedTypes && (
            <button
              type="button"
              className="column-block__add-button"
              data-testid="add-content-button"
              onClick={handleOpenPicker}
            >
              + Add content
            </button>
          )}
        </div>
      </div>
      {hasAllowedTypes && (
        <ElementTypePicker
          allowedTypes={column.allowedTypes!}
          isOpen={isPickerOpen}
          onClose={handleClosePicker}
          onSelect={handleTypeSelect}
        />
      )}
    </div>
  );
}
