import EmptyState from '@/components/EmptyState/EmptyState'
import { t } from '@/i18n'
import type { EditorRoot } from '@/types/editorRoot'
import EditableGridEditor from './EditableGridEditor'
import ReadonlyGridEditor from './ReadonlyGridEditor'

interface GridEditorProps {
  /**
   * What the tree is rooted at — a page zone, or a block in the library.
   * Optional at the boundary because the entwine bridge can be mounted before
   * `data-schema` is parsed.
   */
  readonly root: EditorRoot | null
  readonly readonly?: boolean
}

/**
 * Root component for the grid editor.
 *
 * Mounted by:
 * - The legacy entwine bridge (`client/src/js/bridge/entwine.ts`) on elements
 *   matching `[data-react-mount="grid-editor"]` in the main CMS edit view —
 *   always runs in editable mode.
 * - The React `GridEditorField` wrapper (`client/src/js/components/GridEditorField`)
 *   when `FormBuilder` serializes the history viewer's form schema — always
 *   runs in readonly mode with a specific page version.
 *
 * Mode is decided once here and dispatched to a dedicated root:
 * `EditableGridEditor` mounts the DnD machinery; `ReadonlyGridEditor` never
 * instantiates `useSortable`/mutations and renders no `DndContext`.
 *
 * The null root early-returns an empty-state sentinel, so every hook below this
 * guard sees a guaranteed root — no `?? 0` / `?? 1` placeholders flowing into
 * query keys or tree fabrications.
 */
export default function GridEditor({ root, readonly = false }: GridEditorProps) {
  if (root === null) {
    return (
      <div className="grid-editor" data-testid="grid-editor">
        <EmptyState
          message={t('WeDevelopGrid.GridEditor.NO_SECTIONS', 'No sections yet')}
          variant="centered"
        />
      </div>
    )
  }

  return readonly ? <ReadonlyGridEditor root={root} /> : <EditableGridEditor root={root} />
}
