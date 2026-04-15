import { useMemo } from 'react';
import { DndContext, DragOverlay, MeasuringStrategy } from '@dnd-kit/core';
import { t } from '@/i18n';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { useElementTree } from '@/hooks/useElementTree';
import { useTreeEnrichment } from '@/hooks/useTreeEnrichment';
import { useDragAndDrop, DragContext } from '@/hooks/useDragAndDrop';
import { useReorderElement } from '@/hooks/useElementMutations';
import { ViewportProvider } from '@/hooks/ViewportContext';
import { GridEditorProvider } from '@/hooks/GridEditorContext';
import { ReadonlyProvider } from '@/hooks/ReadonlyContext';
import { isSectionNode } from '@/types/elements';
import ViewportSwitcher from '@/components/ViewportSwitcher/ViewportSwitcher';
import SectionBlock from '@/components/SectionBlock/SectionBlock';
import AddChildButton from '@/components/AddChildButton/AddChildButton';
import EmptyState from '@/components/EmptyState/EmptyState';
import DragOverlayContent from '@/components/DragOverlayContent/DragOverlayContent';

interface GridEditorProps {
  readonly pageId: number | null;
  readonly zone: string;
  readonly readonly?: boolean;
  readonly version?: number;
}

/**
 * Root component for the grid editor.
 *
 * Mounted by:
 * - The legacy entwine bridge (`client/src/bridge/entwine.ts`) on the
 *   `.grid-editor__container` element in the main CMS edit view — always
 *   runs in editable mode.
 * - The React `GridEditorField` wrapper (`client/src/components/GridEditorField`)
 *   when `FormBuilder` serializes the history viewer's form schema — always
 *   runs in readonly mode with a specific page version.
 *
 * Block components (`SectionBlock`, `RowBlock`, `ColumnBlock`, `ElementCard`)
 * each dispatch to an editable or readonly variant via `useReadonly()`, so
 * the readonly tree never calls `useSortable` — we simply don't mount a
 * `DndContext` wrapper around it. The ViewportSwitcher renders in both
 * modes so admins can inspect responsive grid settings at every version.
 */
export default function GridEditor({ pageId, zone, readonly = false, version }: GridEditorProps) {
  const { data, isLoading, error } = useElementTree(pageId, zone, readonly ? version : undefined);

  const reorderMutation = useReorderElement(pageId ?? 0, zone);

  const { dndContextProps, dragState, pendingTree } = useDragAndDrop({
    tree: data ?? {},
    onReorder: (elementID, targetParentId, afterElementID, clearPendingTree) => {
      reorderMutation.mutate({
        params: { elementID, targetParentId, afterElementID },
        tree: data ?? {},
        clearPendingTree,
      });
    },
  });

  // Use pending tree during cross-container drags for visual feedback
  const effectiveData = pendingTree ?? data;

  const sections =
    effectiveData === undefined ? [] : (effectiveData[String(pageId)] ?? []).filter(isSectionNode);

  const enrichedSections = useTreeEnrichment(sections, pageId ?? 0);

  const sectionIds = enrichedSections.map((s) => s.sortableId);

  const dragContextValue = useMemo(
    () => ({ activeType: dragState?.activeType ?? null }),
    [dragState?.activeType],
  );

  const rootClassName = readonly ? 'grid-editor grid-editor--readonly' : 'grid-editor';
  const hasSections = enrichedSections.length > 0;

  const sectionList = hasSections ? (
    enrichedSections.map((section) => <SectionBlock key={section.id} section={section} />)
  ) : readonly ? (
    <p className="grid-editor__empty-state">
      {t('WeDevelopGrid.GridEditor.NO_SECTIONS_READONLY', 'No sections in this version')}
    </p>
  ) : (
    pageId !== null && (
      <AddChildButton
        parentId={pageId}
        childType="section"
        childLabel="Section"
        variant="empty-state"
      />
    )
  );

  return (
    <div
      className={rootClassName}
      data-page-id={pageId ?? undefined}
      data-zone={zone}
      data-testid="grid-editor"
    >
      {isLoading && (
        <p className="grid-editor__loading" data-testid="grid-editor-loading">
          {t('WeDevelopGrid.GridEditor.LOADING', 'Loading elements...')}
        </p>
      )}
      {error !== null && (
        <p className="grid-editor__error">
          {t('WeDevelopGrid.GridEditor.LOAD_ERROR', 'Failed to load elements: {message}', {
            message: error.message,
          })}
        </p>
      )}
      {data !== undefined && pageId !== null && (
        <GridEditorProvider value={{ pageId, zone }}>
          <ViewportProvider>
            <ReadonlyProvider value={readonly}>
              <ViewportSwitcher />
              {readonly ? (
                sectionList
              ) : (
                <DndContext
                  {...dndContextProps}
                  measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
                >
                  <DragContext.Provider value={dragContextValue}>
                    <SortableContext items={sectionIds} strategy={verticalListSortingStrategy}>
                      {sectionList}
                      {hasSections && (
                        <AddChildButton
                          parentId={pageId}
                          childType="section"
                          childLabel="Section"
                          variant="append"
                        />
                      )}
                    </SortableContext>
                  </DragContext.Provider>
                  <DragOverlay>
                    {dragState !== null && (
                      <DragOverlayContent node={dragState.activeNode} type={dragState.activeType} />
                    )}
                  </DragOverlay>
                </DndContext>
              )}
            </ReadonlyProvider>
          </ViewportProvider>
        </GridEditorProvider>
      )}
      {data !== undefined && pageId === null && (
        <EmptyState
          message={t('WeDevelopGrid.GridEditor.NO_SECTIONS', 'No sections yet')}
          variant="centered"
        />
      )}
    </div>
  );
}
