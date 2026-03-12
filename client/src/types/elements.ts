import { z } from 'zod/v4-mini';

// --- Container type constants ---

export const CONTAINER_TYPES = ['section', 'row', 'column'] as const;

export type ContainerType = (typeof CONTAINER_TYPES)[number];

// --- Shared schemas ---

export const blockSchemaSchema = z.object({
  typeName: z.string(),
  label: z.string(),
  icon: z.string(),
  type: z.string(),
  title: z.string(),
  summary: z.string(),
});

const statusFlagValueSchema = z.object({
  text: z.string(),
  title: z.string(),
});

export const statusFlagsSchema = z.object({
  addedtodraft: z.optional(statusFlagValueSchema),
  modified: z.optional(statusFlagValueSchema),
  removedfromdraft: z.optional(statusFlagValueSchema),
});

const baseFieldsSchema = z.object({
  id: z.int(),
  parentId: z.int().check(z.positive()),
  title: z.string().check(z.minLength(1)),
  blockSchema: blockSchemaSchema,
  obsoleteClassName: z.nullable(z.string()),
  version: z.int(),
  canDelete: z.boolean(),
  canPublish: z.boolean(),
  canUnpublish: z.boolean(),
  canCreate: z.boolean(),
  editLink: z.nullable(z.string()),
  statusFlags: statusFlagsSchema,
  extensions: z.optional(z.record(z.string(), z.unknown())),
});

// --- Leaf node schema (no containerType field) ---
// Passthrough allows extra keys from extension enrichers while still
// rejecting container nodes via the discriminated union ordering.

export const simpleElementNodeSchema = z.looseObject(baseFieldsSchema.shape);

// --- Grid settings schema (column-specific) ---

const viewportSettingsSchema = z.object({
  width: z.int(),
  offset: z.int(),
  visible: z.boolean(),
});

export const gridSettingsSchema = z.record(z.string(), viewportSettingsSchema);

// --- Allowed type info schema ---

export const allowedTypeInfoSchema = z.object({
  label: z.string(),
  icon: z.string(),
  description: z.string(),
});

export type AllowedTypeInfo = z.infer<typeof allowedTypeInfoSchema>;

// --- Container node schemas (bottom-up: column → row → section) ---

export const columnNodeSchema = z.extend(baseFieldsSchema, {
  containerType: z.literal('column'),
  allowedTypes: z.nullable(z.record(z.string(), allowedTypeInfoSchema)),
  children: z.nullable(z.array(simpleElementNodeSchema)),
  gridSettings: gridSettingsSchema,
});

export const rowNodeSchema = z.extend(baseFieldsSchema, {
  containerType: z.literal('row'),
  allowedTypes: z.nullable(z.record(z.string(), allowedTypeInfoSchema)),
  children: z.nullable(z.array(columnNodeSchema)),
});

export const sectionNodeSchema = z.extend(baseFieldsSchema, {
  containerType: z.literal('section'),
  allowedTypes: z.nullable(z.record(z.string(), allowedTypeInfoSchema)),
  children: z.nullable(z.array(rowNodeSchema)),
});

// --- Union schema ---
// Containers first: their literal containerType discriminates them before
// the simpler schema can match (simpleElementNodeSchema is a structural
// subset of any container schema).

export const elementNodeSchema = z.union([
  sectionNodeSchema,
  rowNodeSchema,
  columnNodeSchema,
  simpleElementNodeSchema,
]);

// --- Response schema ---

export const elementTreeResponseSchema = z.record(
  z.string(),
  z.array(elementNodeSchema),
);

// --- Inferred types ---

export type SimpleElementNode = z.infer<typeof simpleElementNodeSchema>;
export type ColumnNode = z.infer<typeof columnNodeSchema>;
export type RowNode = z.infer<typeof rowNodeSchema>;
export type SectionNode = z.infer<typeof sectionNodeSchema>;
export type ElementNode = z.infer<typeof elementNodeSchema>;
export type ContainerNode = SectionNode | RowNode | ColumnNode;
export type ElementTreeResponse = z.infer<typeof elementTreeResponseSchema>;
export type StatusFlags = z.infer<typeof statusFlagsSchema>;
export type BlockSchema = z.infer<typeof blockSchemaSchema>;
export type GridSettings = z.infer<typeof gridSettingsSchema>;
export type ViewportSettings = z.infer<typeof viewportSettingsSchema>;

// --- Type guards ---

export function isContainerNode(node: ElementNode): node is ContainerNode {
  return 'containerType' in node;
}

export function isSectionNode(node: ElementNode): node is SectionNode {
  return 'containerType' in node && node.containerType === 'section';
}

export function isRowNode(node: ElementNode): node is RowNode {
  return 'containerType' in node && node.containerType === 'row';
}

export function isColumnNode(node: ElementNode): node is ColumnNode {
  return 'containerType' in node && node.containerType === 'column';
}

export function isSimpleElementNode(
  node: ElementNode,
): node is SimpleElementNode {
  return !('containerType' in node);
}
