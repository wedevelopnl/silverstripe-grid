import { describe, it, expect } from 'vitest';
import { queryKeys } from './queryKeys';

describe('queryKeys', () => {
  describe('elementTree', () => {
    it('should return base key for all()', () => {
      expect(queryKeys.elementTree.all()).toEqual(['elementTree']);
    });

    it('should return page and zone tuple for byPage()', () => {
      expect(queryKeys.elementTree.byPage(1, 'main')).toEqual(['elementTree', 1, 'main']);
    });

    it('should include version in tuple when version is provided', () => {
      expect(queryKeys.elementTree.byPage(1, 'main', 5)).toEqual(['elementTree', 1, 'main', 5]);
    });

    it('should omit version from tuple when version is undefined', () => {
      expect(queryKeys.elementTree.byPage(1, 'main', undefined)).toEqual([
        'elementTree',
        1,
        'main',
      ]);
    });
  });

  describe('pages', () => {
    it('should return base key for all()', () => {
      expect(queryKeys.pages.all()).toEqual(['pages']);
    });

    it('should return search term tuple for search()', () => {
      expect(queryKeys.pages.search('test')).toEqual(['pages', 'test']);
    });
  });

  describe('zones', () => {
    it('should return page tuple for byPage()', () => {
      expect(queryKeys.zones.byPage(1)).toEqual(['zones', 1]);
    });
  });

  describe('acceptableContainers', () => {
    it('should return full tuple for byTarget()', () => {
      expect(queryKeys.acceptableContainers.byTarget(1, 'main', 'Section')).toEqual([
        'acceptableContainers',
        1,
        'main',
        'Section',
      ]);
    });
  });
});
