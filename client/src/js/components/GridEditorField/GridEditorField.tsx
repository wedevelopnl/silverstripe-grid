import { useMemo } from 'react'
import GridEditor from '@/components/GridEditor/GridEditor'
import GridEditorErrorBoundary from '@/components/GridEditorErrorBoundary/GridEditorErrorBoundary'
import GridQueryProvider from '@/hooks/QueryProvider'
import type { EditorRoot } from '@/types/editorRoot'

/**
 * Shape of the schema data sub-object that PHP's
 * `GridEditorField::getSchemaDataDefaults()` ships under the `data` key.
 */
interface GridEditorFieldSchemaData {
  readonly pageId?: number
  readonly zone?: string
  readonly readonly?: boolean
  readonly version?: number
}

/**
 * Props passed by SilverStripe's React `FormBuilder` to every field
 * component it renders. FormBuilder spreads all keys from the PHP
 * `getSchemaDataDefaults()` as top-level props; we only declare the
 * ones we use.
 *
 * Reference: vendor/silverstripe/framework/src/Forms/FormField.php:1472-1510
 */
interface GridEditorFieldProps {
  readonly name: string
  readonly id: string
  readonly data?: GridEditorFieldSchemaData
  readonly readOnly?: boolean
}

/**
 * React FormBuilder entry point for the grid editor.
 *
 * Registered with the CMS Injector under the key `GridEditorField`.
 * `DataObjectVersionFormFactory` serializes the history-view form as
 * JSON schema; React `FormBuilder` iterates that schema and looks up
 * each field's `component` name in the Injector. When it finds this
 * wrapper, it renders it with the schema data as props.
 *
 * The wrapper is intentionally thin: it assembles the page root out of the
 * nested `data` sub-object and forwards it to the existing `<GridEditor>`.
 * The legacy entwine bridge (used by the main edit view) builds the same root
 * from `data-schema` attributes instead. This host only ever renders a page —
 * the library editor mounts through the bridge.
 */
export default function GridEditorField({ data, readOnly }: GridEditorFieldProps) {
  const pageId = typeof data?.pageId === 'number' ? data.pageId : null
  const zone = typeof data?.zone === 'string' ? data.zone : 'main'
  const version = typeof data?.version === 'number' && data.version > 0 ? data.version : undefined

  // Memoised because the root IS the editor context value: a fresh object per
  // FormBuilder render would re-render every consumer of it.
  const root = useMemo<EditorRoot | null>(
    () => (pageId === null ? null : { kind: 'page', pageId, zone, version }),
    [pageId, zone, version],
  )

  // FormBuilder's readOnly is the source of truth — it reflects
  // Form::makeReadonly() state. data.readonly is a safety fallback.
  const isReadonly = readOnly === true || data?.readonly === true

  if (root === null) {
    return null
  }

  return (
    <GridQueryProvider>
      <GridEditorErrorBoundary>
        <GridEditor root={root} readonly={isReadonly} />
      </GridEditorErrorBoundary>
    </GridQueryProvider>
  )
}
