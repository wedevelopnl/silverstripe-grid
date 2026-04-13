import GridQueryProvider from '@/hooks/QueryProvider';
import GridEditor from '@/components/GridEditor/GridEditor';
import GridEditorErrorBoundary from '@/components/GridEditorErrorBoundary/GridEditorErrorBoundary';

/**
 * Shape of the schema data sub-object that PHP's
 * `GridEditorField::getSchemaDataDefaults()` ships under the `data` key.
 */
interface GridEditorFieldSchemaData {
  readonly pageId?: number;
  readonly zone?: string;
  readonly readonly?: boolean;
  readonly version?: number;
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
  readonly name: string;
  readonly id: string;
  readonly data?: GridEditorFieldSchemaData;
  readonly readOnly?: boolean;
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
 * The wrapper is intentionally thin: it unpacks pageId/zone/version
 * from the nested `data` sub-object and forwards them to the existing
 * `<GridEditor>` component. The legacy entwine bridge (used by the
 * main edit view) mounts the same `<GridEditor>` via a different
 * entry point.
 */
export default function GridEditorField({ data, readOnly }: GridEditorFieldProps) {
  const pageId = typeof data?.pageId === 'number' ? data.pageId : null;
  const zone = typeof data?.zone === 'string' ? data.zone : 'main';
  const version = typeof data?.version === 'number' && data.version > 0 ? data.version : undefined;

  // FormBuilder's readOnly is the source of truth — it reflects
  // Form::makeReadonly() state. data.readonly is a safety fallback.
  const isReadonly = readOnly === true || data?.readonly === true;

  if (pageId === null) {
    return null;
  }

  return (
    <GridQueryProvider>
      <GridEditorErrorBoundary>
        <GridEditor pageId={pageId} zone={zone} readonly={isReadonly} version={version} />
      </GridEditorErrorBoundary>
    </GridQueryProvider>
  );
}
