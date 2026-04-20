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
export type NodeKey = `${NodeType}-${number}`;
export declare function buildNodeKey(type: NodeType, id: number): NodeKey;
export declare function nodeRefToKey(ref: NodeRef): NodeKey;
/**
 * Validation boundary: accepts any `string` (dnd-kit IDs, localStorage payloads,
 * URL fragments) and returns a structured {@link NodeRef} only for well-formed
 * `${type}-${id}` keys. Returns null for anything else.
 *
 * The parameter is intentionally `string` rather than {@link NodeKey} — the
 * whole point of this function is to probe whether an untrusted string has the
 * NodeKey shape. Callers that already hold a `NodeKey` (via `buildNodeKey` or
 * narrowed by a previous `parseNodeKey` success) don't need to call this.
 */
export declare function parseNodeKey(key: string): NodeRef | null;
export declare function nodeRefEquals(a: NodeRef, b: NodeRef): boolean;
export declare function assertNodeRef(value: unknown, context: string): NodeRef;
