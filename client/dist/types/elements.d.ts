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
    id: number;
    parentId: number;
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
export type ElementTreeResponse = Record<string, ElementNode[]>;
export interface TreeApiResponse {
    tree: ElementTreeResponse;
    overrideCounts: Record<string, number>;
}
export declare function isContainerNode(node: ElementNode): node is ContainerNode;
export declare function isSectionNode(node: ElementNode): node is SectionNode;
export declare function isRowNode(node: ElementNode): node is RowNode;
export declare function isColumnNode(node: ElementNode): node is ColumnNode;
export declare function isSimpleElementNode(node: ElementNode): node is SimpleElementNode;
export {};
