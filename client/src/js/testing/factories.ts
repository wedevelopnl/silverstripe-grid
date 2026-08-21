import type { ViewportKey } from '@/types/adapter'
import { buildDraggableId, type DraggableType, type ParsedDraggableId } from '@/types/dnd'
import type {
  AllowedTypeInfo,
  BlockSchema,
  ChildOf,
  ColumnNode,
  ElementNode,
  GridSettings,
  RowNode,
  SectionNode,
  SharedBlockReferenceNode,
  SimpleElementNode,
  TreeApiResponse,
  ViewportSettings,
} from '@/types/elements'
import { NodeIdentity, type NodeKey, type NodeRef, type NodeType } from '@/types/identity'
import type { SharedBlockMeta } from '@/types/sharedBlocks'

export function createParsedDraggableId(type: DraggableType, nodeId: number): ParsedDraggableId {
  return { type, id: nodeId, key: buildDraggableId(type, nodeId) }
}

/**
 * Test-only helper: mint a {@link ViewportKey} from a plain string. Fixtures
 * are a trust boundary — the test author vouches for the key — so the brand is
 * asserted here rather than derived from a real adapter config.
 */
export function viewportKey(key: string): ViewportKey {
  return key as ViewportKey
}

let nextId = 1

export function resetIdCounter(): void {
  nextId = 1
}

function id(): number {
  return nextId++
}

function defaultBlockSchema(typeName: string): BlockSchema {
  return {
    typeName,
    label: typeName,
    icon: `font-icon-${typeName.toLowerCase()}`,
    type: typeName,
    title: typeName,
  }
}

function defaultViewportSettings(overrides?: Partial<ViewportSettings>): ViewportSettings {
  return {
    width: 12,
    offset: 0,
    visible: true,
    ...overrides,
  }
}

function defaultGridSettings(overrides?: Partial<GridSettings>): GridSettings {
  return {
    default: defaultViewportSettings(overrides?.default),
    overrides: overrides?.overrides ?? {},
  }
}

interface NodeOverrideBase {
  id?: number
  parent?: NodeRef
}

function resolveSelf(nodeType: NodeType, overrideId?: number): NodeRef {
  return { type: nodeType, id: overrideId ?? id() }
}

type BaseNodeFields = Omit<SimpleElementNode, 'containerType'>

function baseNodeFields(
  self: NodeRef,
  parent: NodeRef,
  title: string,
  blockSchema: BlockSchema,
  editLink: string | null,
): BaseNodeFields {
  return {
    self,
    parent,
    nodeKey: NodeIdentity.toKey(self.type, self.id),
    parentKey: NodeIdentity.toKey(parent.type, parent.id),
    title,
    blockSchema,
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink,
    status: 'published',
  }
}

function resolveChildren<T>(
  explicit: T[] | null | undefined,
  count: number,
  make: () => T,
): T[] | null {
  if (explicit !== undefined) {
    return explicit
  }
  if (count > 0) {
    return Array.from({ length: count }, make)
  }
  return null
}

export function createSimpleElement(
  overrides?: Partial<SimpleElementNode> & NodeOverrideBase,
): SimpleElementNode {
  const self = resolveSelf('element', overrides?.id)
  const parent = overrides?.parent ?? { type: 'column', id: 100 }
  return {
    ...baseNodeFields(
      self,
      parent,
      overrides?.title ?? `Element ${self.id}`,
      overrides?.blockSchema ?? defaultBlockSchema('Content'),
      `/admin/pages/edit/show/${self.id}`,
    ),
    ...overrides,
  }
}

/**
 * A shared block placement wrapping a single root child.
 *
 * The child (and its whole subtree) is stamped with `sharedBlockKey` exactly as
 * the API normalisation does, so tests exercise the same boundary the drag
 * filtering reads.
 */
export function createSharedBlockReferenceNode(
  overrides?: Partial<SharedBlockReferenceNode> & NodeOverrideBase & { root?: ElementNode },
): SharedBlockReferenceNode {
  const self = resolveSelf('element', overrides?.id)
  const parent = overrides?.parent ?? { type: 'page', id: 100 }
  const nodeKey = NodeIdentity.toKey(self.type, self.id)

  const sharedBlock: SharedBlockMeta = overrides?.sharedBlock ?? {
    blockId: self.id,
    title: `Shared block ${self.id}`,
    usageCount: 1,
    status: 'published',
    editLink: `/admin/shared-blocks/item/${self.id}/edit`,
  }

  const root = overrides?.root ?? overrides?.children?.[0]
  const children: [ElementNode] | [] =
    root !== undefined ? [stampSharedBlockKey(root, nodeKey)] : []

  const { root: _omitRoot, children: _omitChildren, ...rest } = overrides ?? {}

  return {
    ...baseNodeFields(
      self,
      parent,
      overrides?.title ?? sharedBlock.title,
      overrides?.blockSchema ?? defaultBlockSchema('Shared block'),
      null,
    ),
    ...rest,
    sharedBlock,
    children,
  }
}

/** Recursively stamp `sharedBlockKey` on a subtree, mirroring attachDerivedFields. */
function stampSharedBlockKey<TNode extends ElementNode>(
  node: TNode,
  sharedBlockKey: NodeKey,
): TNode {
  const children =
    'children' in node && node.children !== null && node.children !== undefined
      ? node.children.map((child) => stampSharedBlockKey(child as ElementNode, sharedBlockKey))
      : undefined

  return {
    ...node,
    sharedBlockKey,
    ...(children !== undefined ? { children } : {}),
  } as TNode
}

export function createColumnNode(
  overrides?: Partial<ColumnNode> & NodeOverrideBase & { childCount?: number },
): ColumnNode {
  const self = resolveSelf('column', overrides?.id)
  const parent = overrides?.parent ?? { type: 'row', id: 100 }
  const children = resolveChildren<ChildOf<SimpleElementNode>>(
    overrides?.children,
    overrides?.childCount ?? 1,
    () => createSimpleElement({ parent: { type: 'column', id: self.id } }),
  )
  const { childCount: _omitChildCount, ...rest } = overrides ?? {}

  return {
    ...baseNodeFields(
      self,
      parent,
      overrides?.title ?? `Column ${self.id}`,
      overrides?.blockSchema ?? defaultBlockSchema('Column'),
      null,
    ),
    containerType: 'column',
    allowedTypes: overrides?.allowedTypes ?? null,
    gridSettings: defaultGridSettings(overrides?.gridSettings),
    ...rest,
    children,
  }
}

export function createRowNode(
  overrides?: Partial<RowNode> & NodeOverrideBase & { columnCount?: number },
): RowNode {
  const self = resolveSelf('row', overrides?.id)
  const parent = overrides?.parent ?? { type: 'section', id: 100 }
  const children = resolveChildren<ChildOf<ColumnNode>>(
    overrides?.children,
    overrides?.columnCount ?? 1,
    () => createColumnNode({ parent: { type: 'row', id: self.id } }),
  )
  const { columnCount: _omitColumnCount, ...rest } = overrides ?? {}

  return {
    ...baseNodeFields(
      self,
      parent,
      overrides?.title ?? `Row ${self.id}`,
      overrides?.blockSchema ?? defaultBlockSchema('Row'),
      null,
    ),
    containerType: 'row',
    allowedTypes: overrides?.allowedTypes ?? null,
    ...rest,
    children,
  }
}

export function createSectionNode(
  overrides?: Partial<SectionNode> & NodeOverrideBase & { rowCount?: number },
): SectionNode {
  const self = resolveSelf('section', overrides?.id)
  const parent = overrides?.parent ?? { type: 'page', id: 1 }
  const children = resolveChildren<ChildOf<RowNode>>(
    overrides?.children,
    overrides?.rowCount ?? 1,
    () => createRowNode({ parent: { type: 'section', id: self.id } }),
  )
  const { rowCount: _omitRowCount, ...rest } = overrides ?? {}

  return {
    ...baseNodeFields(
      self,
      parent,
      overrides?.title ?? `Section ${self.id}`,
      overrides?.blockSchema ?? defaultBlockSchema('Section'),
      null,
    ),
    containerType: 'section',
    allowedTypes: overrides?.allowedTypes ?? null,
    ...rest,
    children,
  }
}

/** Wire-shape root map: allowed child types per container type. */
export interface AllowedTypesByContainerType {
  readonly section: Record<string, AllowedTypeInfo>
  readonly row: Record<string, AllowedTypeInfo>
  readonly column: Record<string, AllowedTypeInfo>
}

export function createTreeApiResponse(
  overrides?: Partial<TreeApiResponse> & {
    pageId?: number
    sections?: SectionNode[]
    allowedTypes?: AllowedTypesByContainerType
  },
  // The wire shape carries `allowedTypes` at the root (required by the wire
  // schema); the internal TreeApiResponse does not. The intersection return
  // type lets one factory serve both consumers: internal-model tests ignore
  // the extra key, fetch-mock tests parse it through normaliseTreeResponse.
): TreeApiResponse & { allowedTypes: AllowedTypesByContainerType } {
  const pageId = overrides?.pageId ?? overrides?.rootParent?.id ?? 1
  // `nodes` as well as `sections`: a page root also holds shared block
  // placements, which are not SectionNodes and so cannot go through `sections`.
  // Without this arm `nodes` would type-check and then be silently dropped,
  // seeding a default section in place of the roots the caller asked for.
  const nodes: ElementNode[] = overrides?.nodes ??
    overrides?.sections ?? [createSectionNode({ parent: { type: 'page', id: pageId } })]
  return {
    rootParent: overrides?.rootParent ?? { type: 'page', id: pageId },
    allowedTypes: overrides?.allowedTypes ?? { section: {}, row: {}, column: {} },
    nodes,
  }
}

interface ColumnSpec {
  id?: number
  /** Explicit leaf children; overrides `childCount`. `null` = empty column. */
  children?: SimpleElementNode[] | null
  childCount?: number
}

interface RowSpec {
  id?: number
  /** One default column when omitted. */
  columns?: ColumnSpec[]
}

export interface BuildTreeOptions {
  pageId?: number
  sectionId?: number
  /** One default row when omitted. */
  rows?: RowSpec[]
}

export interface BuiltTree {
  tree: TreeApiResponse & { allowedTypes: AllowedTypesByContainerType }
  section: SectionNode
  rows: RowNode[]
  columns: ColumnNode[]
}

/**
 * Compose a fully wired single-section tree (section → rows → columns →
 * elements) with matching parent refs at every level. Tests that need a
 * coherent tree should use this instead of hand-wiring the parent chain.
 */
export function buildTree(options: BuildTreeOptions = {}): BuiltTree {
  const pageId = options.pageId ?? 1
  const sectionId = options.sectionId ?? id()

  // Columns are kept as they are built rather than read back out of
  // `row.children`: that field admits a SharedBlockReferenceNode, while
  // buildTree only ever creates plain columns and BuiltTree promises as much.
  const built = (options.rows ?? [{}]).map((rowSpec) => {
    const rowId = rowSpec.id ?? id()
    const columns = (rowSpec.columns ?? [{}]).map((columnSpec) =>
      createColumnNode({
        id: columnSpec.id,
        parent: { type: 'row', id: rowId },
        ...(columnSpec.children !== undefined ? { children: columnSpec.children } : {}),
        ...(columnSpec.childCount !== undefined ? { childCount: columnSpec.childCount } : {}),
      }),
    )
    const row = createRowNode({
      id: rowId,
      parent: { type: 'section', id: sectionId },
      children: columns,
    })
    return { row, columns }
  })

  const rows = built.map((entry) => entry.row)

  const section = createSectionNode({
    id: sectionId,
    parent: { type: 'page', id: pageId },
    children: rows,
  })

  return {
    tree: createTreeApiResponse({ pageId, sections: [section] }),
    section,
    rows,
    columns: built.flatMap((entry) => entry.columns),
  }
}
