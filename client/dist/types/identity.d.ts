/**
 * Scoped identity for every node in the grid.
 *
 * SilverStripe's polymorphic parent (`ParentID` + `ParentClass`) means a page
 * and an element can share the same numeric ID — they live in different tables
 * with independent auto-increment sequences. Anywhere identity is stored as a
 * bare number is a latent collision bug. `NodeRef` (structural) and `NodeKey`
 * (string, for Map/Set keys) always carry the type alongside the id.
 */
export declare const NODE_TYPES: readonly ["page", "section", "row", "column", "element"];
export type NodeType = (typeof NODE_TYPES)[number];
export interface NodeRef {
    readonly type: NodeType;
    readonly id: number;
}
export type NodeKey = string;
export declare function buildNodeKey(type: NodeType, id: number): NodeKey;
export declare function nodeRefToKey(ref: NodeRef): NodeKey;
export declare function parseNodeKey(key: NodeKey): NodeRef | null;
export declare function nodeRefEquals(a: NodeRef, b: NodeRef): boolean;
export declare function assertNodeRef(value: unknown, context: string): NodeRef;
