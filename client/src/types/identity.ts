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

export const NODE_TYPES = ['page', 'section', 'row', 'column', 'element'] as const;

export type NodeType = (typeof NODE_TYPES)[number];

export interface NodeRef {
  readonly type: NodeType;
  readonly id: number;
}

export type NodeKey = `${NodeType}-${number}`;

const SEPARATOR = '-';

function isNodeType(value: string): value is NodeType {
  return (NODE_TYPES as readonly string[]).includes(value);
}

function toKey(type: NodeType, id: number): NodeKey;
function toKey(ref: NodeRef): NodeKey;
function toKey(typeOrRef: NodeType | NodeRef, id?: number): NodeKey {
  if (typeof typeOrRef === 'string') {
    return `${typeOrRef}${SEPARATOR}${id as number}`;
  }
  return `${typeOrRef.type}${SEPARATOR}${typeOrRef.id}`;
}

function fromKey(key: string): NodeRef | null {
  const separatorIndex = key.indexOf(SEPARATOR);
  if (separatorIndex <= 0) return null;

  const type = key.slice(0, separatorIndex);
  if (!isNodeType(type)) return null;

  const numericId = Number(key.slice(separatorIndex + 1));
  if (!Number.isInteger(numericId) || numericId <= 0) return null;

  return { type, id: numericId };
}

function equals(a: NodeRef, b: NodeRef): boolean {
  return a.type === b.type && a.id === b.id;
}

function assert(value: unknown, context: string): NodeRef {
  if (typeof value !== 'object' || value === null) {
    throw new TypeError(`${context}: expected NodeRef object, got ${typeof value}`);
  }
  const candidate = value as Record<string, unknown>;
  const type = candidate.type;
  const id = candidate.id;
  if (typeof type !== 'string' || !isNodeType(type)) {
    throw new TypeError(`${context}: invalid NodeRef.type ${JSON.stringify(type)}`);
  }
  if (typeof id !== 'number' || !Number.isInteger(id) || id <= 0) {
    throw new TypeError(`${context}: invalid NodeRef.id ${JSON.stringify(id)}`);
  }
  return { type, id };
}

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
export const NodeIdentity = {
  toKey,
  fromKey,
  equals,
  assert,
} as const;
