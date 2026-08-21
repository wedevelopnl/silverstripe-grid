import type { NodeKey, NodeRef } from './identity'
import type { SharedBlockMeta } from './sharedBlocks'
import type { ElementStatus } from './status'

export const CONTAINER_TYPES = ['section', 'row', 'column'] as const

export type ContainerType = (typeof CONTAINER_TYPES)[number]

export interface BlockSchema {
  typeName: string
  label: string
  icon: string
  type: string
  title: string
}

interface BaseFields {
  /**
   * Scoped identity of this node. Canonical form for lookups and API payloads
   * — always prefer `self`/`nodeKey` over a bare numeric id to avoid
   * polymorphic collisions with pages.
   */
  self: NodeRef
  /**
   * Scoped identity of this node's parent. `parent.type` is `'page'` for
   * sections, and the matching container type for all other levels.
   */
  parent: NodeRef
  /** Precomputed composite key for this node (equal to `NodeIdentity.toKey(self)`). */
  nodeKey: NodeKey
  /** Precomputed composite key for this node's parent. */
  parentKey: NodeKey
  title: string
  blockSchema: BlockSchema
  obsoleteClassName: string | null
  version: number
  canDelete: boolean
  canPublish: boolean
  canUnpublish: boolean
  canCreate: boolean
  editLink: string | null
  status: ElementStatus
  /**
   * Optional plain-text content summary shown on leaf element cards.
   * Absent when the element's `getSummary()` returned null or ''; the
   * backend drops empty values from the JSON, so this is either a
   * non-empty string or missing. HTML is not supported — render as text.
   */
  summary?: string
  extensions?: Record<string, unknown>
  /**
   * Set on every DESCENDANT of a shared block placement — the block's root and
   * everything under it — and never on the placement itself. Two nodes belong
   * to the same context when this field matches, which is what drag collision
   * filtering uses to keep content from crossing the shared boundary.
   */
  sharedBlockKey?: NodeKey
}

/**
 * Leaf (non-container) element. Carries an explicit `containerType?: never`
 * so the {@link ElementNode} discriminated union narrows correctly via the
 * `containerType in node` checks in the type guards below — without this,
 * any future field accidentally named `containerType` would silently break
 * narrowing.
 */
export interface SimpleElementNode extends BaseFields {
  containerType?: never
}

export interface ViewportSettings {
  width: number
  offset: number
  visible: boolean
}

export interface GridSettings {
  default: ViewportSettings
  overrides: Record<string, ViewportSettings>
}

export interface AllowedTypeInfo {
  label: string
  icon: string
  description: string
}

/**
 * Any child slot can instead hold a shared block placement standing in for a
 * subtree of that shape — a row-rooted block inside a section, a leaf-rooted
 * one inside a column. The placement is judged by its block's root class, so it
 * is legal exactly where the type it stands in for is.
 */
export type ChildOf<TNode> = TNode | SharedBlockReferenceNode

export interface ColumnNode extends BaseFields {
  containerType: 'column'
  allowedTypes: Record<string, AllowedTypeInfo> | null
  children: ChildOf<SimpleElementNode>[] | null
  gridSettings: GridSettings
}

export interface RowNode extends BaseFields {
  containerType: 'row'
  allowedTypes: Record<string, AllowedTypeInfo> | null
  children: ChildOf<ColumnNode>[] | null
}

export interface SectionNode extends BaseFields {
  containerType: 'section'
  allowedTypes: Record<string, AllowedTypeInfo> | null
  children: ChildOf<RowNode>[] | null
}

/**
 * A placement of a shared block. Not a container — it holds exactly one child,
 * the block's root element, which is a completely normal typed node. That is
 * what lets the shared subtree render and drag with the existing components:
 * only the frame around it is new.
 */
export interface SharedBlockReferenceNode extends BaseFields {
  containerType?: never
  sharedBlock: SharedBlockMeta
  children: [ElementNode] | []
}

export type ElementNode =
  | SectionNode
  | RowNode
  | ColumnNode
  | SharedBlockReferenceNode
  | SimpleElementNode
export type ContainerNode = SectionNode | RowNode | ColumnNode

export interface TreeApiResponse {
  /** Identity of the root container (always a page for the current API). */
  rootParent: NodeRef
  /** Flat list of root-level nodes (sections). */
  nodes: ElementNode[]
}

export function isContainerNode(node: ElementNode): node is ContainerNode {
  return 'containerType' in node
}

export function isSectionNode(node: ElementNode): node is SectionNode {
  return 'containerType' in node && node.containerType === 'section'
}

export function isRowNode(node: ElementNode): node is RowNode {
  return 'containerType' in node && node.containerType === 'row'
}

export function isColumnNode(node: ElementNode): node is ColumnNode {
  return 'containerType' in node && node.containerType === 'column'
}

export function isSharedBlockReferenceNode(node: ElementNode): node is SharedBlockReferenceNode {
  return 'sharedBlock' in node && node.sharedBlock !== undefined
}

/** Whether this node lives inside a shared block's subtree. */
export function isInsideSharedBlock(node: ElementNode): boolean {
  return node.sharedBlockKey !== undefined
}

/**
 * Whether this node IS a block's single root — the library editor's top node,
 * the only one parented by the SharedBlock itself.
 *
 * It carries no delete, no duplicate and no drag handle. Archiving it would
 * leave the block rootless and duplicating it would give the block a second
 * root, so both are removed from the UI here and refused by the server. The
 * handle goes for a different reason: the root is the only node at its level
 * and every slot below it belongs to a different shape, so a drag from it can
 * never have a legal target.
 */
export function isSharedBlockRootNode(node: ElementNode): boolean {
  return node.parent.type === 'sharedBlock'
}

export function isSimpleElementNode(node: ElementNode): node is SimpleElementNode {
  return !('containerType' in node) && !isSharedBlockReferenceNode(node)
}
