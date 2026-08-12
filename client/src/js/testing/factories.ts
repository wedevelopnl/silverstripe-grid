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

export function resetIdCounter(start = 1): void {
  nextId = start
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

/**
 * Shorthand factory input that lets tests mix the old-style numeric parent id
 * with the new-style `parent: NodeRef`.
 */
interface NodeOverrideBase {
  id?: number
  parent?: NodeRef
  /**
   * Legacy shorthand — if provided and `parent` is omitted, constructs a
   * NodeRef using the inferred parent type.
   */
  parentId?: number
}

function resolveSelf(nodeType: NodeType, overrideId?: number): NodeRef {
  return { type: nodeType, id: overrideId ?? id() }
}

function resolveParent(
  defaultType: NodeType,
  override: NodeOverrideBase,
  fallbackId: number,
): NodeRef {
  if (override.parent) return override.parent
  return { type: defaultType, id: override.parentId ?? fallbackId }
}

export function createSimpleElement(
  overrides?: Partial<SimpleElementNode> & NodeOverrideBase,
): SimpleElementNode {
  const self = resolveSelf('element', overrides?.id)
  const parent = resolveParent('column', overrides ?? {}, 100)
  const { parentId: _omitParentId, ...rest } = overrides ?? {}
  return {
    self,
    parent,
    nodeKey: NodeIdentity.toKey(self.type, self.id),
    parentKey: NodeIdentity.toKey(parent.type, parent.id),
    title: overrides?.title ?? `Element ${self.id}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Content'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: `/admin/pages/edit/show/${self.id}`,
    status: 'published',
    ...rest,
  }
}

export function createColumnNode(
  overrides?: Partial<ColumnNode> & NodeOverrideBase & { childCount?: number },
): ColumnNode {
  const self = resolveSelf('column', overrides?.id)
  const parent = resolveParent('row', overrides ?? {}, 100)
  const childCount = overrides?.childCount ?? 1

  const children: SimpleElementNode[] | null = ((): SimpleElementNode[] | null => {
    if (overrides?.children !== undefined) {
      return overrides.children
    }
    if (childCount > 0) {
      return Array.from({ length: childCount }, () =>
        createSimpleElement({ parent: { type: 'column', id: self.id } }),
      )
    }
    return null
  })()

  const { parentId: _omitParentId, childCount: _omitChildCount, ...rest } = overrides ?? {}

  return {
    self,
    parent,
    nodeKey: NodeIdentity.toKey(self.type, self.id),
    parentKey: NodeIdentity.toKey(parent.type, parent.id),
    title: overrides?.title ?? `Column ${self.id}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Column'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    status: 'published',
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
  const parent = resolveParent('section', overrides ?? {}, 100)
  const columnCount = overrides?.columnCount ?? 1

  const children: ColumnNode[] | null = ((): ColumnNode[] | null => {
    if (overrides?.children !== undefined) {
      return overrides.children
    }
    if (columnCount > 0) {
      return Array.from({ length: columnCount }, () =>
        createColumnNode({ parent: { type: 'row', id: self.id } }),
      )
    }
    return null
  })()

  const { parentId: _omitParentId, columnCount: _omitColumnCount, ...rest } = overrides ?? {}

  return {
    self,
    parent,
    nodeKey: NodeIdentity.toKey(self.type, self.id),
    parentKey: NodeIdentity.toKey(parent.type, parent.id),
    title: overrides?.title ?? `Row ${self.id}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Row'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    status: 'published',
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
  const parent = resolveParent('page', overrides ?? {}, 1)
  const rowCount = overrides?.rowCount ?? 1

  const children: RowNode[] | null = ((): RowNode[] | null => {
    if (overrides?.children !== undefined) {
      return overrides.children
    }
    if (rowCount > 0) {
      return Array.from({ length: rowCount }, () =>
        createRowNode({ parent: { type: 'section', id: self.id } }),
      )
    }
    return null
  })()

  const { parentId: _omitParentId, rowCount: _omitRowCount, ...rest } = overrides ?? {}

  return {
    self,
    parent,
    nodeKey: NodeIdentity.toKey(self.type, self.id),
    parentKey: NodeIdentity.toKey(parent.type, parent.id),
    title: overrides?.title ?? `Section ${self.id}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Section'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    status: 'published',
    containerType: 'section',
    allowedTypes: overrides?.allowedTypes ?? null,
    ...rest,
    children,
  }
}

/**
 * Build a flat list of root sections. The `pageId` parameter is retained to
 * keep the legacy positional API stable across test files, even though the
 * tree response now carries `rootParent` explicitly via
 * {@link createTreeApiResponse}.
 */
export function createTree(sections?: SectionNode[], pageId = 1): SectionNode[] {
  return sections ?? [createSectionNode({ parent: { type: 'page', id: pageId } })]
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
  const sections = overrides?.sections ?? overrides?.nodes ?? createTree(undefined, pageId)
  return {
    rootParent: overrides?.rootParent ?? { type: 'page', id: pageId },
    allowedTypes: overrides?.allowedTypes ?? { section: {}, row: {}, column: {} },
    nodes: sections as ElementNode[],
  }
}
