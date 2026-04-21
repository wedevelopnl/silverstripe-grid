import { describe, it, expect } from 'vitest';
import { createBlockClasses } from './blockClasses';

describe('createBlockClasses', () => {
  const build = createBlockClasses<
    'published' | 'draft' | 'modified' | 'removed' | 'active' | 'highlighted' | 'dragging'
  >('card');

  it('emits the block name with a single modifier', () => {
    expect(build('published')).toBe('card card--published');
  });

  it('appends every truthy modifier', () => {
    expect(build('draft', 'active', 'highlighted')).toBe(
      'card card--draft card--active card--highlighted',
    );
  });

  it('drops falsy modifiers', () => {
    expect(build('modified', false, 'dragging')).toBe('card card--modified card--dragging');
  });

  it('emits the bare block name when no modifiers are passed', () => {
    expect(createBlockClasses('block')()).toBe('block');
  });
});
