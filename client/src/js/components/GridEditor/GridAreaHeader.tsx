import { useCallback } from 'react'
import ViewportSwitcher from '@/components/ViewportSwitcher/ViewportSwitcher'
import { useEditorRoot } from '@/hooks/GridEditorContext'
import { useCollapse } from '@/hooks/useCollapseState'
import { t } from '@/i18n'
import type { ElementNode } from '@/types/elements'

/**
 * The header strip above the canvas: the viewport control on the left, a small
 * toolbar of area-level actions on the right.
 *
 * Its "Grid area" title is present for assistive tech but not drawn — it names
 * the region for anyone navigating this long CMS form by heading, while adding
 * nothing a sighted author cannot already see.
 *
 * The collapse/expand-all toggle is fully wired; `reset / open / clear` are
 * placeholders for actions the design mocked but the editor doesn't expose yet
 * (they render disabled rather than absent so the strip matches the design and
 * the wiring has an obvious home later).
 *
 * Both controls are page-editor only. The library editor has no page to open
 * and no section list to reset or clear, and its single block root carries its
 * own collapse toggle — so nothing visible is left there and the strip goes
 * visually hidden rather than occupying a gap above the canvas.
 */
export default function GridAreaHeader({
  sections,
  readonly,
}: {
  readonly sections: ElementNode[]
  readonly readonly: boolean
}) {
  const { isCollapsed, toggle } = useCollapse()
  const root = useEditorRoot()

  // When every section is already collapsed the button flips to "expand all";
  // any expanded section keeps it in "collapse all" mode.
  const allCollapsed =
    sections.length > 0 && sections.every((section) => isCollapsed(section.nodeKey))

  const toggleAll = useCallback(() => {
    for (const section of sections) {
      if (isCollapsed(section.nodeKey) === allCollapsed) {
        toggle(section.nodeKey)
      }
    }
  }, [sections, isCollapsed, toggle, allCollapsed])

  const canToggleAll = !readonly && sections.length > 0

  const toggleAllLabel = allCollapsed
    ? t('WeDevelopGrid.GridEditor.ACTION_EXPAND_ALL', 'Expand all sections')
    : t('WeDevelopGrid.GridEditor.ACTION_COLLAPSE_ALL', 'Collapse all sections')

  const isPageEditor = root.kind === 'page'

  return (
    <header
      className={`ssgrid-editor-header${isPageEditor ? '' : ' ssgrid-visually-hidden'}`}
      data-testid="grid-editor-header"
    >
      <h1 className="ssgrid-editor-title ssgrid-visually-hidden">
        {t('WeDevelopGrid.GridEditor.AREA_TITLE', 'Grid area')}
      </h1>
      {/* A block has no page zone, and the reset scopes the switcher offers
          address a page + zone the server has no block equivalent for — hence
          usePageRoot inside it, which throws rather than degrade if this gate
          is ever removed. */}
      {isPageEditor && <ViewportSwitcher readonly={readonly} />}
      {isPageEditor && (
        <div className="ssgrid-editor-header-actions">
          <button
            type="button"
            className="ssgrid-icon-button ssgrid-focus-ring"
            disabled
            title={t('WeDevelopGrid.GridEditor.ACTION_RESET', 'Reset changes')}
            aria-label={t('WeDevelopGrid.GridEditor.ACTION_RESET', 'Reset changes')}
          >
            <span className="ssgrid-glyph font-icon-back-in-time" aria-hidden="true" />
          </button>
          <button
            type="button"
            className="ssgrid-icon-button ssgrid-focus-ring"
            disabled={!canToggleAll}
            onClick={toggleAll}
            title={toggleAllLabel}
            aria-label={toggleAllLabel}
          >
            <span
              className={`ssgrid-glyph ${allCollapsed ? 'font-icon-down-open-big' : 'font-icon-up-open-big'}`}
              aria-hidden="true"
            />
          </button>
          <button
            type="button"
            className="ssgrid-icon-button ssgrid-focus-ring"
            disabled
            title={t('WeDevelopGrid.GridEditor.ACTION_OPEN', 'Open page')}
            aria-label={t('WeDevelopGrid.GridEditor.ACTION_OPEN', 'Open page')}
          >
            <span className="ssgrid-glyph font-icon-external-link" aria-hidden="true" />
          </button>
          <button
            type="button"
            className="ssgrid-icon-button ssgrid-focus-ring"
            disabled
            title={t('WeDevelopGrid.GridEditor.ACTION_CLEAR', 'Remove all sections')}
            aria-label={t('WeDevelopGrid.GridEditor.ACTION_CLEAR', 'Remove all sections')}
          >
            <span className="ssgrid-glyph font-icon-trash-bin" aria-hidden="true" />
          </button>
        </div>
      )}
    </header>
  )
}
