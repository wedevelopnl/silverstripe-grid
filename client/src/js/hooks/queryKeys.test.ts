import { describe, expect, it } from 'vitest'
import { queryKeys } from './queryKeys'

describe('queryKeys', () => {
  describe('elementTree', () => {
    it('should return base key for all()', () => {
      expect(queryKeys.elementTree.all()).toEqual(['elementTree'])
    })

    it('should return page and zone tuple for byPage()', () => {
      expect(queryKeys.elementTree.byPage(1, 'main')).toEqual(['elementTree', 1, 'main'])
    })

    it('should place version keys on a separate branch when version is provided', () => {
      expect(queryKeys.elementTree.byPage(1, 'main', 5)).toEqual([
        'elementTree',
        'version',
        1,
        'main',
        5,
      ])
    })

    it('should keep the draft key from prefix-matching version keys', () => {
      // invalidateQueries prefix-matches. Nesting version keys under the draft
      // key (['elementTree', 1, 'main', 5]) made every mutation's draft
      // invalidation refetch immutable archived versions too.
      const draft = queryKeys.elementTree.byPage(1, 'main')
      const version = queryKeys.elementTree.byPage(1, 'main', 5)

      expect(version.slice(0, draft.length)).not.toEqual([...draft])
    })

    it('should omit version from tuple when version is undefined', () => {
      expect(queryKeys.elementTree.byPage(1, 'main', undefined)).toEqual(['elementTree', 1, 'main'])
    })
  })

  describe('pages', () => {
    it('should return base key for all()', () => {
      expect(queryKeys.pages.all()).toEqual(['pages'])
    })

    it('should return search term tuple for search()', () => {
      expect(queryKeys.pages.search('test')).toEqual(['pages', 'test'])
    })
  })

  describe('zones', () => {
    it('should return page tuple for byPage()', () => {
      expect(queryKeys.zones.byPage(1)).toEqual(['zones', 1])
    })
  })

  describe('acceptableContainers', () => {
    it('should return base key for all()', () => {
      expect(queryKeys.acceptableContainers.all()).toEqual(['acceptableContainers'])
    })

    it('should return full tuple for byTarget()', () => {
      expect(queryKeys.acceptableContainers.byTarget(1, 'main', 'Section')).toEqual([
        'acceptableContainers',
        1,
        'main',
        'Section',
      ])
    })
  })
})
