import type { NodeKey, NodeRef } from './identity';
import type { ElementStatus } from './status';

// --- Container type constants ---

export const CONTAINER_TYPES = ['section', 'row', 'column'] as const;

export type ContainerType = (typeof CONTAINER_TYPES)[number];

// --- Shared types ---

export interface BlockSchema {
  typeName: string;
  label: string;
  icon: string;
  type: string;
  title: string;
}

interface BaseFields {
  /**
   * Scoped identity of this node. Canonical form for lookups and API payloads
   * — always prefer `self`/`nodeKey` over the bare `id` to avoid polymorphic
   * collisions with pages.
   */
  self: NodeRef;
  /**
   * Scoped identity of this node's parent. `parent.type` is `'page'` for
   * sections, and the matching container type for all other levels.
   */
  parent: NodeRef;
  /** Precomputed composite key for this node (equal to `NodeIdentity.toKey(self)`). */
  nodeKey: NodeKey;
  /** Precomputed composite key for this node's parent. */
  parentKey: NodeKey;
  /**
   * Numeric record ID of this node. Equal to `self.id`. Safe to use for the
   * unambiguous GridElement-only endpoints (publish, unpublish, delete,
   * duplicate, updateGridSettings) that accept a bare id because they query
   * `GridElement::get()` exclusively. **Do not use as a Map key or for
   * display identity** — use `nodeKey` instead so page/element ID collisions
   * are eliminated.
   */
  id: number;
  title: string;
  blockSchema: BlockSchema;
  obsoleteClassName: string | null;
  version: number;
  canDelete: boolean;
  canPublish: boolean;
  canUnpublish: boolean;
  canCreate: boolean;
  editLink: string | null;
  status: ElementStatus;
  /**
   * Optional plain-text content summary shown on leaf element cards.
   * Absent when the element's `getSummary()` returned null or ''; the
   * backend drops empty values from the JSON, so this is either a
   * non-empty string or missing. HTML is not supported — render as text.
   */
  summary?: string;
  extensions?: Record<string, unknown>;
}

// --- Leaf node type ---

export interface SimpleElementNode extends BaseFields {}

// --- Grid settings (column-specific) ---

export interface ViewportSettings {
  width: number;
  offset: number;
  visible: boolean;
}

export interface GridSettings {
  default: ViewportSettings;
  overrides: Record<string, ViewportSettings>;
}

// --- Allowed type info ---

export interface AllowedTypeInfo {
  label: string;
  icon: string;
  description: string;
}

// --- Container node types (column → row → section) ---

export interface ColumnNode extends BaseFields {
  containerType: 'column';
  allowedTypes: Record<string, AllowedTypeInfo> | null;
  children: SimpleElementNode[] | null;
  gridSettings: GridSettings;
}

export interface RowNode extends BaseFields {
  containerType: 'row';
  allowedTypes: Record<string, AllowedTypeInfo> | null;
  children: ColumnNode[] | null;
}

export interface SectionNode extends BaseFields {
  containerType: 'section';
  allowedTypes: Record<string, AllowedTypeInfo> | null;
  children: RowNode[] | null;
}

// --- Union types ---

export type ElementNode = SectionNode | RowNode | ColumnNode | SimpleElementNode;
export type ContainerNode = SectionNode | RowNode | ColumnNode;

/**
 * Root sections for a single page/zone — flat list. The old `Record<string,
 * ElementNode[]>` shape has been retired in favour of the structured
 * `TreeApiResponse` that carries `rootParent` explicitly.
 */
export type ElementTreeResponse = ElementNode[];

// --- API response wrapper ---

export interface TreeApiResponse {
  /** Identity of the root container (always a page for the current API). */
  rootParent: NodeRef;
  /** Flat list of root-level nodes (sections). */
  nodes: ElementNode[];
  overrideCounts: Record<string, number>;
}

// --- Type guards ---

export function isContainerNode(node: ElementNode): node is ContainerNode {
  return 'containerType' in node;
}

export function isSectionNode(node: ElementNode): node is SectionNode {
  return 'containerType' in node && node.containerType === 'section';
}

export function isRowNode(node: ElementNode): node is RowNode {
  return 'containerType' in node && node.containerType === 'row';
}

export function isColumnNode(node: ElementNode): node is ColumnNode {
  return 'containerType' in node && node.containerType === 'column';
}

export function isSimpleElementNode(node: ElementNode): node is SimpleElementNode {
  return !('containerType' in node);
}
