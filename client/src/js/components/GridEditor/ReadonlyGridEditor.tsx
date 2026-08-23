import { Fragment, useMemo } from 'react'
import { useEditorTree } from '@/hooks/useEditorTree'
import { t } from '@/i18n'
import type { EditorRoot } from '@/types/editorRoot'
import GridEditorShell, { resolveGridEditorStatus } from './GridEditorShell'
import { renderRootEntry } from './renderRootEntry'
import { selectSections } from './selectSections'

interface ReadonlyGridEditorProps {
  readonly root: EditorRoot
}

export default function ReadonlyGridEditor({ root }: ReadonlyGridEditorProps) {
  const { data, error } = useEditorTree(root)
  const sections = useMemo(() => selectSections(data), [data])

  const status = resolveGridEditorStatus(data, error)

  return (
    <GridEditorShell root={root} readonly status={status} error={error} sections={sections}>
      {sections.length > 0 ? (
        sections.map((entry) => (
          <Fragment key={entry.nodeKey}>
            {renderRootEntry(entry, { siblings: sections, readonly: true })}
          </Fragment>
        ))
      ) : (
        <p className="ssgrid-empty-state" data-testid="grid-editor-empty">
          {t('WeDevelopGrid.GridEditor.NO_SECTIONS_READONLY', 'No sections in this version')}
        </p>
      )}
    </GridEditorShell>
  )
}
