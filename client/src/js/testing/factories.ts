import type { ViewportKey } from '@/types/adapter'
import { buildDraggableId, type DraggableType, type ParsedDraggableId } from '@/types/dnd'
import type {
  AllowedTypeInfo,
  BlockSchema,
  ColumnNode,
  ElementNode,
  GridSettings,
  RowNode,
  SectionNode,
  SimpleElementNode,
  TreeApiResponse,
  ViewportSettings,
} from '@/types/elements'
import { NodeIdentity, type NodeRef, type NodeType } from '@/types/identity'

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

export function createColumnNode(
  overrides?: Partial<ColumnNode> & NodeOverrideBase & { childCount?: number },
): ColumnNode {
  const self = resolveSelf('column', overrides?.id)
  const parent = overrides?.parent ?? { type: 'row', id: 100 }
  const children = resolveChildren(overrides?.children, overrides?.childCount ?? 1, () =>
    createSimpleElement({ parent: { type: 'column', id: self.id } }),
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
  const children = resolveChildren(overrides?.children, overrides?.columnCount ?? 1, () =>
    createColumnNode({ parent: { type: 'row', id: self.id } }),
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
  const children = resolveChildren(overrides?.children, overrides?.rowCount ?? 1, () =>
    createRowNode({ parent: { type: 'section', id: self.id } }),
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
  const sections = overrides?.sections ?? [
    createSectionNode({ parent: { type: 'page', id: pageId } }),
  ]
  return {
    rootParent: overrides?.rootParent ?? { type: 'page', id: pageId },
    allowedTypes: overrides?.allowedTypes ?? { section: {}, row: {}, column: {} },
    nodes: sections as ElementNode[],
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

  const rows = (options.rows ?? [{}]).map((rowSpec) => {
    const rowId = rowSpec.id ?? id()
    const columns = (rowSpec.columns ?? [{}]).map((columnSpec) =>
      createColumnNode({
        id: columnSpec.id,
        parent: { type: 'row', id: rowId },
        ...(columnSpec.children !== undefined ? { children: columnSpec.children } : {}),
        ...(columnSpec.childCount !== undefined ? { childCount: columnSpec.childCount } : {}),
      }),
    )
    return createRowNode({
      id: rowId,
      parent: { type: 'section', id: sectionId },
      children: columns,
    })
  })

  const section = createSectionNode({
    id: sectionId,
    parent: { type: 'page', id: pageId },
    children: rows,
  })

  return {
    tree: createTreeApiResponse({ pageId, sections: [section] }),
    section,
    rows,
    columns: rows.flatMap((row) => row.children ?? []),
  }
}
