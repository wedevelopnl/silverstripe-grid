/**
 * Scoped identity for every node in the grid.
 *
 * SilverStripe's polymorphic parent (`ParentID` + `ParentClass`) means a page
 * and an element can share the same numeric ID — they live in different tables
 * with independent auto-increment sequences. Anywhere identity is stored as a
 * bare number is a latent collision bug. `NodeRef` (structural) and `NodeKey`
 * (string, for Map/Set keys and dnd-kit IDs) always carry the type alongside
 * the id.
 *
 * All operations on these identities live on the {@link NodeIdentity} namespace
 * object below — there's no free-function API surface. Contributors only need
 * to discover `NodeIdentity.*` to work with node identities.
 */
export declare const NODE_TYPES: readonly ["page", "section", "row", "column", "element"];
export type NodeType = (typeof NODE_TYPES)[number];
export interface NodeRef {
    readonly type: NodeType;
    readonly id: number;
}
export type NodeKey = `${NodeType}-${number}`;
declare function toKey(type: NodeType, id: number): NodeKey;
declare function toKey(ref: NodeRef): NodeKey;
declare function fromKey(key: string): NodeRef | null;
declare function equals(a: NodeRef, b: NodeRef): boolean;
declare function assert(value: unknown, context: string): NodeRef;
/**
 * All operations on {@link NodeRef} / {@link NodeKey} live here so the API
 * surface stays small and discoverable. Types remain top-level exports
 * because interfaces/unions are what consumers reference for parameters and
 * return types — the namespace is purely for *behavior*.
 *
 * @example
 * const key = NodeIdentity.toKey('column', 42);        // 'column-42'
 * const same = NodeIdentity.toKey(node.self);          // overload for refs
 * const ref = NodeIdentity.fromKey(untrustedString);   // NodeRef | null
 * const eq = NodeIdentity.equals(a.self, b.self);
 * const validated = NodeIdentity.assert(json, 'rootParent');
 */
export declare const NodeIdentity: {
    readonly toKey: typeof toKey;
    readonly fromKey: typeof fromKey;
    readonly equals: typeof equals;
    readonly assert: typeof assert;
};
export {};
