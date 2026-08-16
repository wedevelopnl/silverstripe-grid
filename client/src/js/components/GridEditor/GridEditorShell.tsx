import type { ReactNode } from 'react'
import { useMemo } from 'react'
import ViewportSwitcher from '@/components/ViewportSwitcher/ViewportSwitcher'
import type { ApiError } from '@/api/errors'
import { GridEditorProvider } from '@/hooks/GridEditorContext'
import { CollapseContext, useCollapseState } from '@/hooks/useCollapseState'
import { t } from '@/i18n'
import type { SectionNode, TreeApiResponse } from '@/types/elements'
import { hasUnpublishedDescendant, isUnpublished } from '@/utils/publishStatus'
import GridAreaHeader from './GridAreaHeader'

export type GridEditorStatus = 'loading' | 'error' | 'ready'

/**
 * Collapse a query's `data`/`error` into the single status the Shell renders.
 * `data` defined wins (a loaded tree renders even mid background-refetch, and a
 * failed background refetch keeps showing the last-good tree rather than
 * replacing it with an error banner); an error with no data shows the error
 * notice; otherwise we're still loading.
 *
 * Assumes the caller's query is always enabled — the editor's is, since
 * `pageId` is guaranteed numeric past the boundary guard. `loading` is the
 * fallback for every non-ready, non-error state, so a disabled/idle query
 * (e.g. `skipToken`) would render a spurious loading notice.
 */
export function resolveGridEditorStatus(
  data: TreeApiResponse | undefined,
  error: ApiError | null,
): GridEditorStatus {
  if (data !== undefined) {
    return 'ready'
  }
  if (error !== null) {
    return 'error'
  }
  return 'loading'
}

interface GridEditorShellProps {
  readonly pageId: number
  readonly zone: string
  readonly readonly: boolean
  readonly status: GridEditorStatus
  readonly error: ApiError | null
  readonly sections: SectionNode[]
  /** Set by the history viewer; identifies which tree the chrome describes. */
  readonly version?: number
  readonly children: ReactNode
}

/**
 * Shared chrome for both editor modes: the outer host element and its
 * `data-*` attributes, the loading/error notices, the provider stack, the
 * ViewportSwitcher, the GridAreaHeader, and the canvas wrapper. `children` is
 * the mode-specific canvas content (a bare section list in readonly, a
 * DndContext-wrapped list in editable).
 */
export default function GridEditorShell({
  pageId,
  zone,
  readonly,
  status,
  error,
  sections,
  version,
  children,
}: GridEditorShellProps) {
  const collapseState = useCollapseState(pageId)
  const gridEditorContextValue = useMemo(() => ({ pageId, zone }), [pageId, zone])
  // Deep, not shallow: an unpublished block several levels down is the case the
  // canvas ring exists to surface, and the old `sections.some(...)` check
  // never saw it.
  const anyUnpublished = useMemo(
    () =>
      sections.some(
        (section) => isUnpublished(section.status) || hasUnpublishedDescendant(section),
      ),
    [sections],
  )

  return (
    <div
      data-page-id={pageId}
      data-zone={zone}
      data-testid="grid-editor"
      data-readonly={readonly ? '' : undefined}
    >
      {status === 'loading' && (
        <p className="ssgrid-editor__notice" data-testid="grid-editor-loading">
          {t('WeDevelopGrid.GridEditor.LOADING', 'Loading elements...')}
        </p>
      )}
      {status === 'error' && error !== null && (
        <p className="ssgrid-editor__notice" data-tone="error" data-testid="grid-editor-error">
          {t('WeDevelopGrid.GridEditor.LOAD_ERROR', 'Failed to load elements: {message}', {
            message: error.message,
          })}
        </p>
      )}
      {status === 'ready' && (
        <GridEditorProvider value={gridEditorContextValue}>
          <CollapseContext.Provider value={collapseState}>
            <div className="ssgrid-editor">
              <ViewportSwitcher readonly={readonly} version={version} />
              <GridAreaHeader sections={sections} readonly={readonly} />
              <div
                className="ssgrid-editor__canvas"
                data-testid="grid-editor-canvas"
                data-dnd-container=""
                data-descendant-unpublished={anyUnpublished ? '' : undefined}
              >
                {children}
              </div>
            </div>
          </CollapseContext.Provider>
        </GridEditorProvider>
      )}
    </div>
  )
}
