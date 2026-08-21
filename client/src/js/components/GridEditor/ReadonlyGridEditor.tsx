import { Fragment, useMemo } from 'react'
import { useElementTree } from '@/hooks/useElementTree'
import { t } from '@/i18n'
import GridEditorShell, { resolveGridEditorStatus } from './GridEditorShell'
import { renderRootEntry } from './renderRootEntry'
import { selectSections } from './selectSections'

interface ReadonlyGridEditorProps {
  readonly pageId: number
  readonly zone: string
  readonly version: number | undefined
}

export default function ReadonlyGridEditor({ pageId, zone, version }: ReadonlyGridEditorProps) {
  const { data, error } = useElementTree(pageId, zone, version)
  const sections = useMemo(() => selectSections(data), [data])

  const status = resolveGridEditorStatus(data, error)

  return (
    <GridEditorShell
      pageId={pageId}
      zone={zone}
      readonly
      status={status}
      error={error}
      sections={sections}
      version={version}
    >
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
