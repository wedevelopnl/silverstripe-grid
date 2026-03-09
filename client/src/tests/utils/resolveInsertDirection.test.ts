import { resolveInsertDirection } from '@/utils/resolveInsertDirection';
import type { DraggableType } from '@/types/dnd';

// A 100×50 rect positioned at (200, 300) — center is (250, 325)
const overRect = { left: 200, top: 300, width: 100, height: 50 };

describe('resolveInsertDirection', () => {
  describe('Y-axis types (section, row, element)', () => {
    const yAxisTypes: DraggableType[] = ['section', 'row', 'element'];

    it.each(yAxisTypes)('%s: pointer above center → before', (type) => {
      const pointer = { x: 250, y: 310 }; // y < 325 (center)
      expect(resolveInsertDirection(pointer, overRect, type)).toBe('before');
    });

    it.each(yAxisTypes)('%s: pointer below center → after', (type) => {
      const pointer = { x: 250, y: 340 }; // y > 325 (center)
      expect(resolveInsertDirection(pointer, overRect, type)).toBe('after');
    });

    it.each(yAxisTypes)('%s: pointer exactly at center → after', (type) => {
      const pointer = { x: 250, y: 325 }; // y === center
      expect(resolveInsertDirection(pointer, overRect, type)).toBe('after');
    });
  });

  describe('X-axis type (column)', () => {
    it('pointer left of center → before', () => {
      const pointer = { x: 230, y: 325 }; // x < 250 (center)
      expect(resolveInsertDirection(pointer, overRect, 'column')).toBe('before');
    });

    it('pointer right of center → after', () => {
      const pointer = { x: 270, y: 325 }; // x > 250 (center)
      expect(resolveInsertDirection(pointer, overRect, 'column')).toBe('after');
    });

    it('pointer exactly at center → after', () => {
      const pointer = { x: 250, y: 325 }; // x === center
      expect(resolveInsertDirection(pointer, overRect, 'column')).toBe('after');
    });
  });
});
