import { NodeKey, NodeRef } from './identity';
export declare const CONTAINER_TYPES: readonly ["section", "row", "column"];
export type ContainerType = (typeof CONTAINER_TYPES)[number];
export interface BlockSchema {
    typeName: string;
    label: string;
    icon: string;
    type: string;
    title: string;
    summary: string;
}
interface StatusFlagValue {
    text: string;
    title: string;
}
export interface StatusFlags {
    addedtodraft?: StatusFlagValue;
    modified?: StatusFlagValue;
    removedfromdraft?: StatusFlagValue;
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
    /** Precomputed composite key for this node (equal to `buildNodeKey(self)`). */
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
    statusFlags: StatusFlags;
    extensions?: Record<string, unknown>;
}
export interface SimpleElementNode extends BaseFields {
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
/**
 * Root sections for a single page/zone — flat list. The old `Record<string,
 * ElementNode[]>` shape has been retired in favour of the structured
 * `TreeApiResponse` that carries `rootParent` explicitly.
 */
export type ElementTreeResponse = ElementNode[];
export interface TreeApiResponse {
    /** Identity of the root container (always a page for the current API). */
    rootParent: NodeRef;
    /** Flat list of root-level nodes (sections). */
    nodes: ElementNode[];
    overrideCounts: Record<string, number>;
}
export declare function isContainerNode(node: ElementNode): node is ContainerNode;
export declare function isSectionNode(node: ElementNode): node is SectionNode;
export declare function isRowNode(node: ElementNode): node is RowNode;
export declare function isColumnNode(node: ElementNode): node is ColumnNode;
export declare function isSimpleElementNode(node: ElementNode): node is SimpleElementNode;
export {};
