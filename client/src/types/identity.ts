/**
 * Scoped identity for every node in the grid.
 *
 * SilverStripe's polymorphic parent (`ParentID` + `ParentClass`) means a page
 * and an element can share the same numeric ID — they live in different tables
 * with independent auto-increment sequences. Anywhere identity is stored as a
 * bare number is a latent collision bug. `NodeRef` (structural) and `NodeKey`
 * (string, for Map/Set keys) always carry the type alongside the id.
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

export function buildNodeKey(type: NodeType, id: number): NodeKey {
  return `${type}${SEPARATOR}${id}`;
}

export function nodeRefToKey(ref: NodeRef): NodeKey {
  return buildNodeKey(ref.type, ref.id);
}

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
export function parseNodeKey(key: string): NodeRef | null {
  const separatorIndex = key.indexOf(SEPARATOR);
  if (separatorIndex <= 0) return null;

  const type = key.slice(0, separatorIndex);
  if (!isNodeType(type)) return null;

  const numericId = Number(key.slice(separatorIndex + 1));
  if (!Number.isInteger(numericId) || numericId <= 0) return null;

  return { type, id: numericId };
}

export function nodeRefEquals(a: NodeRef, b: NodeRef): boolean {
  return a.type === b.type && a.id === b.id;
}

export function assertNodeRef(value: unknown, context: string): NodeRef {
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
