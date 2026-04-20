import { ElementNode } from './elements';
import { NodeKey } from './identity';
export declare const DRAGGABLE_TYPES: readonly ["section", "row", "column", "element"];
export type DraggableType = (typeof DRAGGABLE_TYPES)[number];
export interface ParsedDraggableId {
    readonly type: DraggableType;
    readonly id: number;
    /** The validated NodeKey form of the input string — safe to use at map/set boundaries. */
    readonly key: NodeKey;
}
/**
 * Build a dnd-kit sortable ID. Always returns a {@link NodeKey} string —
 * dnd-kit IDs and node keys are literally the same format for draggable types,
 * so there is no translation layer between the two spaces.
 */
export declare function buildDraggableId(type: DraggableType, id: number): NodeKey;
export declare function parseDraggableId(compositeId: string): ParsedDraggableId | null;
export declare function getDraggableType(compositeId: string): DraggableType | null;
/**
 * Derives the draggable type from a node's shape: container nodes use their
 * containerType, leaf nodes are always 'element'.
 */
export declare function getDraggableTypeForNode(node: ElementNode): DraggableType;
/**
 * Maps a draggable type to the parent type that holds its siblings.
 * Sections live under a page, rows in sections, columns in rows, elements in columns.
 */
export declare const PARENT_CONTAINER_TYPE: {
    readonly section: "page";
    readonly row: "section";
    readonly column: "row";
    readonly element: "column";
};
