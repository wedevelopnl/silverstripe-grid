import { useCallback, useMemo } from 'react';
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
import { getColumnCount, getWidthClass, getOffsetClass } from '@/utils/gridAdapter';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import GridSettingsPicker from '@/components/GridSettingsPicker/GridSettingsPicker';
import type { GridSettingsOption } from '@/components/GridSettingsPicker/GridSettingsPicker';
import ElementCard from '@/components/ElementCard/ElementCard';
import EmptyState from '@/components/EmptyState/EmptyState';

interface ColumnBlockProps {
  readonly column: EnrichedColumnNode;
}

const VIEWPORT_HIDDEN_LABEL = 'hidden';

function resolveViewportSettings(
  column: EnrichedColumnNode,
  activeViewport: string,
  columnCount: number,
): ViewportSettings {
  return column.gridSettings[activeViewport] ?? {
    width: columnCount,
    offset: 0,
    visible: true,
  };
}

function buildWidthOptions(columnCount: number): readonly GridSettingsOption[] {
  const options: GridSettingsOption[] = [];

  for (let n = 1; n <= columnCount; n++) {
    options.push({ value: n, label: `${n}/${columnCount}` });
  }

  options.push({ value: -1, label: VIEWPORT_HIDDEN_LABEL, separator: true });

  return options;
}

function buildOffsetOptions(columnCount: number): readonly GridSettingsOption[] {
  const options: GridSettingsOption[] = [];

  for (let n = 0; n < columnCount; n++) {
    options.push({ value: n, label: n === 0 ? 'none' : `+${n}` });
  }

  return options;
}

export default function ColumnBlock({ column }: ColumnBlockProps) {
  const { activeViewport } = useViewportContext();
  const { pageId, zone } = useGridEditorContext();
  const columnCount = getColumnCount();
  const settings = resolveViewportSettings(column, activeViewport, columnCount);
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

  const widthOptions = useMemo(() => buildWidthOptions(columnCount), [columnCount]);
  const offsetOptions = useMemo(() => buildOffsetOptions(columnCount), [columnCount]);

  const widthLabel = settings.visible
    ? `${settings.width}/${columnCount}`
    : VIEWPORT_HIDDEN_LABEL;

  // -1 sentinel when hidden, so no width option appears selected
  const widthSelectedValue = settings.visible ? settings.width : -1;

  const offsetLabel = settings.offset === 0 ? 'none' : `+${settings.offset}`;
  const isOffsetDisabled = isPickerDisabled || settings.width === columnCount || !settings.visible;

  const handleWidthSelect = useCallback((value: number) => {
    if (value === -1) {
      // "hidden" selected: preserve width/offset, set visible=false
      updateGridSettings.mutate({
        id: column.id,
        viewport: activeViewport,
        width: settings.width,
        offset: settings.offset,
        visible: false,
      });
    } else {
      updateGridSettings.mutate({
        id: column.id,
        viewport: activeViewport,
        width: value,
        offset: settings.offset,
        visible: true,
      });
    }
  }, [column.id, activeViewport, settings.width, settings.offset, updateGridSettings]);

  const handleOffsetSelect = useCallback((value: number) => {
    updateGridSettings.mutate({
      id: column.id,
      viewport: activeViewport,
      width: settings.width,
      offset: value,
      visible: settings.visible,
    });
  }, [column.id, activeViewport, settings.width, settings.visible, updateGridSettings]);

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
