import { NodeKey, NodeRef } from './identity';
import { ElementStatus } from './status';
export declare const CONTAINER_TYPES: readonly ["section", "row", "column"];
export type ContainerType = (typeof CONTAINER_TYPES)[number];
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
     * — always prefer `self`/`nodeKey` over a bare numeric id to avoid
     * polymorphic collisions with pages.
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
/**
 * Leaf (non-container) element. Carries an explicit `containerType?: never`
 * so the {@link ElementNode} discriminated union narrows correctly via the
 * `containerType in node` checks in the type guards below — without this,
 * any future field accidentally named `containerType` would silently break
 * narrowing.
 */
export interface SimpleElementNode extends BaseFields {
    containerType?: never;
}
export interface ViewportSettings {
    width: number;
    offset: number;
    visible: boolean;
}
export interface GridSettings {
    default: ViewportSettings;
    overrides: Record<string, ViewportSettings>;
}
export interface AllowedTypeInfo {
    label: string;
    icon: string;
    description: string;
}
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
export type ElementNode = SectionNode | RowNode | ColumnNode | SimpleElementNode;
export type ContainerNode = SectionNode | RowNode | ColumnNode;
export interface TreeApiResponse {
    /** Identity of the root container (always a page for the current API). */
    rootParent: NodeRef;
    /** Flat list of root-level nodes (sections). */
    nodes: ElementNode[];
}
export declare function isContainerNode(node: ElementNode): node is ContainerNode;
export declare function isSectionNode(node: ElementNode): node is SectionNode;
export declare function isRowNode(node: ElementNode): node is RowNode;
export declare function isColumnNode(node: ElementNode): node is ColumnNode;
export declare function isSimpleElementNode(node: ElementNode): node is SimpleElementNode;
export {};
