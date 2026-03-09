import { useCallback } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import type { ViewportSettings } from '@/types/elements';
import type { EnrichedColumnNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { useViewportContext } from '@/hooks/ViewportContext';
import { useUpdateGridSettings } from '@/hooks/useElementMutations';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { buildBlockClasses } from '@/utils/blockClasses';
import { getColumnCount, getWidthClass, getOffsetClass, getWidthOptions, getOffsetOptions, resolveViewportSettings } from '@/utils/gridAdapter';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker';
import ElementCard from '@/components/ElementCard/ElementCard';
import EmptyState from '@/components/EmptyState/EmptyState';

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

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } = useSortable({ id: column.sortableId });

  const outerClasses = [getWidthClass(settings.width)];
  if (settings.offset > 0) {
    outerClasses.push(getOffsetClass(settings.offset));
  }

  const showDropTarget = isOver && activeType === 'column';
  const isDragActive = activeType !== null;
  const isPickerDisabled = isDragActive || updateGridSettings.isPending;

  const innerClasses = buildBlockClasses('column-block', status, {
    hidden: !settings.visible,
    collapsed: isCollapsed,
    'drop-target': showDropTarget,
  });

  const sortableStyle = buildSortableStyle(transform, transition, isDragging);

  const widthOptions = getWidthOptions();
  const offsetOptions = getOffsetOptions();

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
        updateSettings({ width: value, visible: true });
      }
    },
    [updateSettings],
  );

  const handleOffsetSelect = useCallback(
    (value: number | 'hidden') => {
      if (typeof value === 'number') {
        updateSettings({ offset: value });
      }
    },
    [updateSettings],
  );

  return (
    <div ref={setNodeRef} style={sortableStyle} className={outerClasses.join(' ')}>
      <div className={innerClasses} data-testid="column-block">
        <div className="column-block__header" data-testid="column-header">
          <DragHandle listeners={listeners} attributes={attributes} label={`Move ${column.title}`} />
          <CollapseToggle isCollapsed={isCollapsed} onToggle={toggle} label={column.title} />
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
        </div>
        <div className="column-block__body">
          <SortableContext items={column.childSortableIds} strategy={verticalListSortingStrategy}>
            {column.children !== null && column.children.length > 0
              ? column.children.map((child) => (
                <ElementCard key={child.id} element={child} />
              ))
              : <EmptyState message="No content blocks" />}
          </SortableContext>
        </div>
      </div>
    </div>
  );
}
