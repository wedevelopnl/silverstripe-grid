import type { ReactNode } from 'react'
import { useMemo } from 'react'
import ViewportSwitcher from '@/components/ViewportSwitcher/ViewportSwitcher'
import type { ApiError } from '@/api/errors'
import { GridEditorProvider } from '@/hooks/GridEditorContext'
import { ReadonlyProvider } from '@/hooks/ReadonlyContext'
import { CollapseContext, useCollapseState } from '@/hooks/useCollapseState'
import { t } from '@/i18n'
import type { SectionNode } from '@/types/elements'
import GridAreaHeader from './GridAreaHeader'

export type GridEditorStatus = 'loading' | 'error' | 'ready'

interface GridEditorShellProps {
  readonly pageId: number
  readonly zone: string
  readonly readonly: boolean
  readonly status: GridEditorStatus
  readonly error: ApiError | null
  readonly sections: SectionNode[]
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
  children,
}: GridEditorShellProps) {
  const collapseState = useCollapseState(pageId)
  const gridEditorContextValue = useMemo(() => ({ pageId, zone }), [pageId, zone])
  const anyModified = sections.some((section) => section.status === 'modified')

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
          <ReadonlyProvider value={readonly}>
            <CollapseContext.Provider value={collapseState}>
              <div className="ssgrid-editor">
                <ViewportSwitcher />
                <GridAreaHeader sections={sections} readonly={readonly} />
                <div
                  className="ssgrid-editor__canvas"
                  data-testid="grid-editor-canvas"
                  data-status={anyModified ? 'modified' : undefined}
                >
                  {children}
                </div>
              </div>
            </CollapseContext.Provider>
          </ReadonlyProvider>
        </GridEditorProvider>
      )}
    </div>
  )
}
