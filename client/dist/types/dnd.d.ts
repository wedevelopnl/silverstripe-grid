import { ElementNode } from './elements';
export declare const DRAGGABLE_TYPES: readonly ["section", "row", "column", "element"];
export type DraggableType = (typeof DRAGGABLE_TYPES)[number];
export interface ParsedDraggableId {
    readonly type: DraggableType;
    readonly id: number;
}
export declare function buildDraggableId(type: DraggableType, id: number): string;
export declare function parseDraggableId(compositeId: string): ParsedDraggableId | null;
export declare function getDraggableType(compositeId: string): DraggableType | null;
/**
 * Derives the draggable type from a node's shape: container nodes use their
 * containerType, leaf nodes are always 'element'.
 */
export declare function getDraggableTypeForNode(node: ElementNode): DraggableType;
/**
 * Maps a draggable type to the container type that holds its siblings.
 * Sections live in the root area, rows in sections, columns in rows, elements in columns.
 */
export declare const PARENT_CONTAINER_TYPE: Record<DraggableType, DraggableType | 'root'>;
