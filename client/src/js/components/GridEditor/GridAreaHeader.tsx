import { useCallback } from 'react'
import { useCollapse } from '@/hooks/useCollapseState'
import { t } from '@/i18n'
import type { SectionNode } from '@/types/elements'

/**
 * The "Grid area" header strip from the Figma — a title plus a small toolbar of
 * area-level actions. The collapse/expand-all toggle is fully wired; `reset /
 * open / clear` are placeholders for actions the design mocked but the editor
 * doesn't expose yet (they render disabled rather than absent so the strip
 * matches the design and the wiring has an obvious home later).
 */
export default function GridAreaHeader({
  sections,
  readonly,
}: {
  readonly sections: SectionNode[]
  readonly readonly: boolean
}) {
  const { isCollapsed, toggle } = useCollapse()

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

  return (
    <header className="ssgrid-editor__header">
      <h1 className="ssgrid-editor__title">
        {t('WeDevelopGrid.GridEditor.AREA_TITLE', 'Grid area')}
      </h1>
      <div className="ssgrid-editor__header-actions">
        <button
          type="button"
          className="ssgrid-icon-button"
          disabled
          title={t('WeDevelopGrid.GridEditor.ACTION_RESET', 'Reset changes')}
          aria-label={t('WeDevelopGrid.GridEditor.ACTION_RESET', 'Reset changes')}
        >
          <span className="ssgrid-icon-button__glyph font-icon-back-in-time" aria-hidden="true" />
        </button>
        <button
          type="button"
          className="ssgrid-icon-button"
          disabled={!canToggleAll}
          onClick={toggleAll}
          title={toggleAllLabel}
          aria-label={toggleAllLabel}
        >
          <span
            className={`ssgrid-icon-button__glyph ${allCollapsed ? 'font-icon-down-open-big' : 'font-icon-up-open-big'}`}
            aria-hidden="true"
          />
        </button>
        <button
          type="button"
          className="ssgrid-icon-button"
          disabled
          title={t('WeDevelopGrid.GridEditor.ACTION_OPEN', 'Open page')}
          aria-label={t('WeDevelopGrid.GridEditor.ACTION_OPEN', 'Open page')}
        >
          <span className="ssgrid-icon-button__glyph font-icon-external-link" aria-hidden="true" />
        </button>
        <button
          type="button"
          className="ssgrid-icon-button"
          disabled
          title={t('WeDevelopGrid.GridEditor.ACTION_CLEAR', 'Remove all sections')}
          aria-label={t('WeDevelopGrid.GridEditor.ACTION_CLEAR', 'Remove all sections')}
        >
          <span className="ssgrid-icon-button__glyph font-icon-trash-bin" aria-hidden="true" />
        </button>
      </div>
    </header>
  )
}
