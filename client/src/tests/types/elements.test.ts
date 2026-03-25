import {
  isContainerNode,
  isSectionNode,
  isRowNode,
  isColumnNode,
  isSimpleElementNode,
} from '@/types/elements';
import type {
  SimpleElementNode,
  ColumnNode,
  RowNode,
  SectionNode,
} from '@/types/elements';

// --- Test fixtures ---

const validBlockSchema = {
  typeName: String.raw`WeDevelop\Grid\Model\ContentElement`,
  label: 'Content',
  icon: 'font-icon-block-content',
  type: 'Content',
  title: '',
  summary: '<p>Hello world</p>',
};

function makeSimpleNode(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    parentId: 42,
    title: 'Text block',
    blockSchema: validBlockSchema,
    obsoleteClassName: null,
    version: 3,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    ...overrides,
  };
}

function makeColumnNode(
  children: unknown[] | null = null,
  overrides: Record<string, unknown> = {},
) {
  return {
    ...makeSimpleNode(),
    id: 10,
    parentId: 200,
    title: 'Column',
    containerType: 'column' as const,
    allowedTypes: { 'App\\Model\\ElementContent': { label: 'Content', icon: 'font-icon-block-content', description: '' } },
    children,
    gridSettings: {
      xs: { width: 12, offset: 0, visible: true },
      sm: { width: 12, offset: 0, visible: true },
      md: { width: 12, offset: 0, visible: true },
      lg: { width: 12, offset: 0, visible: true },
      xl: { width: 12, offset: 0, visible: true },
    },
    ...overrides,
  };
}

function makeRowNode(
  children: unknown[] | null = null,
  overrides: Record<string, unknown> = {},
) {
  return {
    ...makeSimpleNode(),
    id: 20,
    parentId: 300,
    title: 'Row',
    containerType: 'row' as const,
    allowedTypes: null,
    children,
    ...overrides,
  };
}

function makeSectionNode(
  children: unknown[] | null = null,
  overrides: Record<string, unknown> = {},
) {
  return {
    ...makeSimpleNode(),
    id: 30,
    parentId: 42,
    title: 'Section',
    containerType: 'section' as const,
    allowedTypes: null,
    children,
    ...overrides,
  };
}

// --- Type guards ---

describe('type guards', () => {
  const simple = makeSimpleNode() as SimpleElementNode;
  const column = makeColumnNode() as ColumnNode;
  const row = makeRowNode() as RowNode;
  const section = makeSectionNode() as SectionNode;

  describe('isContainerNode', () => {
    it('returns true for container nodes', () => {
      expect(isContainerNode(section)).toBe(true);
      expect(isContainerNode(row)).toBe(true);
      expect(isContainerNode(column)).toBe(true);
    });

    it('returns false for simple elements', () => {
      expect(isContainerNode(simple)).toBe(false);
    });
  });

  describe('isSectionNode', () => {
    it('returns true only for sections', () => {
      expect(isSectionNode(section)).toBe(true);
      expect(isSectionNode(row)).toBe(false);
      expect(isSectionNode(column)).toBe(false);
      expect(isSectionNode(simple)).toBe(false);
    });
  });

  describe('isRowNode', () => {
    it('returns true only for rows', () => {
      expect(isRowNode(row)).toBe(true);
      expect(isRowNode(section)).toBe(false);
      expect(isRowNode(column)).toBe(false);
      expect(isRowNode(simple)).toBe(false);
    });
  });

  describe('isColumnNode', () => {
    it('returns true only for columns', () => {
      expect(isColumnNode(column)).toBe(true);
      expect(isColumnNode(section)).toBe(false);
      expect(isColumnNode(row)).toBe(false);
      expect(isColumnNode(simple)).toBe(false);
    });
  });

  describe('isSimpleElementNode', () => {
    it('returns true only for simple elements', () => {
      expect(isSimpleElementNode(simple)).toBe(true);
      expect(isSimpleElementNode(section)).toBe(false);
      expect(isSimpleElementNode(row)).toBe(false);
      expect(isSimpleElementNode(column)).toBe(false);
    });
  });

  describe('type narrowing', () => {
    it('narrows to SectionNode with correct children type', () => {
      if (isSectionNode(section)) {
        const children = section.children;
        expect(children).toBeNull();
      }
    });

    it('narrows to ColumnNode with correct children type', () => {
      const col = makeColumnNode([makeSimpleNode()]) as ColumnNode;
      if (isColumnNode(col)) {
        expect(col.children).toHaveLength(1);
      }
    });
  });
});
