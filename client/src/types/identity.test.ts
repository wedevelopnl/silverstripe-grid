import { describe, it, expect } from 'vitest';
import { NodeIdentity, type NodeRef, type NodeType } from './identity';

describe('NodeIdentity.toKey (from parts)', () => {
  it.each<[NodeType, number, string]>([
    ['page', 1, 'page-1'],
    ['section', 42, 'section-42'],
    ['row', 17, 'row-17'],
    ['column', 99, 'column-99'],
    ['element', 7, 'element-7'],
  ])('builds "%s-%d" as "%s"', (type, id, expected) => {
    expect(NodeIdentity.toKey(type, id)).toBe(expected);
  });
});

describe('NodeIdentity.toKey (from NodeRef)', () => {
  it('matches the parts-form result for the same type/id', () => {
    const ref: NodeRef = { type: 'section', id: 3 };
    expect(NodeIdentity.toKey(ref)).toBe('section-3');
    expect(NodeIdentity.toKey(ref)).toBe(NodeIdentity.toKey(ref.type, ref.id));
  });
});

describe('NodeIdentity.fromKey', () => {
  it.each<[string, NodeRef]>([
    ['page-1', { type: 'page', id: 1 }],
    ['section-42', { type: 'section', id: 42 }],
    ['row-17', { type: 'row', id: 17 }],
    ['column-99', { type: 'column', id: 99 }],
    ['element-7', { type: 'element', id: 7 }],
  ])('parses "%s" → %o', (key, expected) => {
    expect(NodeIdentity.fromKey(key)).toEqual(expected);
  });

  it('round-trips with toKey', () => {
    const ref: NodeRef = { type: 'page', id: 1 };
    expect(NodeIdentity.fromKey(NodeIdentity.toKey(ref.type, ref.id))).toEqual(ref);
  });

  it.each<[string, string]>([
    ['empty string', ''],
    ['no separator', 'section'],
    ['unknown type', 'unknown-5'],
    ['non-numeric id', 'row-abc'],
    ['zero id', 'row-0'],
    ['negative id', 'row--1'],
    ['floating id', 'row-1.5'],
    ['leading separator', '-5'],
  ])('returns null for %s', (_label, key) => {
    expect(NodeIdentity.fromKey(key)).toBeNull();
  });
});

describe('NodeIdentity.equals', () => {
  it('is true for identical refs', () => {
    expect(NodeIdentity.equals({ type: 'row', id: 1 }, { type: 'row', id: 1 })).toBe(true);
  });

  it('is false when type differs', () => {
    expect(NodeIdentity.equals({ type: 'page', id: 1 }, { type: 'section', id: 1 })).toBe(false);
  });

  it('is false when id differs', () => {
    expect(NodeIdentity.equals({ type: 'row', id: 1 }, { type: 'row', id: 2 })).toBe(false);
  });
});

describe('NodeIdentity.assert', () => {
  it('returns a clean NodeRef for a valid shape', () => {
    const ref = NodeIdentity.assert({ type: 'section', id: 5 }, 'test');
    expect(ref).toEqual({ type: 'section', id: 5 });
  });

  it.each<[string, unknown]>([
    ['null', null],
    ['string', 'section-1'],
    ['missing id', { type: 'section' }],
    ['missing type', { id: 1 }],
    ['invalid type', { type: 'unknown', id: 1 }],
    ['zero id', { type: 'section', id: 0 }],
    ['negative id', { type: 'section', id: -1 }],
    ['float id', { type: 'section', id: 1.5 }],
    ['string id', { type: 'section', id: '1' }],
  ])('throws for %s', (_label, value) => {
    expect(() => NodeIdentity.assert(value, 'test')).toThrow(TypeError);
  });
});
