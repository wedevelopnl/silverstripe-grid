/**
 * Zod schemas for API responses.
 *
 * These describe the JSON shape on the wire — what the PHP `GridController`
 * emits — and are used at the API boundary in `client/src/api/endpoints.ts`
 * to validate every server response before it flows into the rest of the
 * frontend.
 *
 * The in-memory types in `./elements.ts` enrich nodes with derived fields
 * (`nodeKey`, `parentKey`, `id`) that the wire shape does not include.
 * `normaliseTreeResponse` parses with these schemas first, then layers the
 * derived fields on top.
 */

import { z } from 'zod'
import { CONTAINER_TYPES } from './elements'
import { NODE_TYPES } from './identity'

const nodeTypeSchema = z.enum(NODE_TYPES)
const containerTypeSchema = z.enum(CONTAINER_TYPES)

export const nodeRefSchema = z.object({
  type: nodeTypeSchema,
  id: z.number().int().positive(),
})

const elementStatusSchema = z.enum(['draft', 'published', 'modified', 'removed'])

const blockSchemaSchema = z.object({
  typeName: z.string(),
  label: z.string(),
  icon: z.string(),
  type: z.string(),
  title: z.string(),
})

const viewportSettingsSchema = z.object({
  width: z.number(),
  offset: z.number(),
  visible: z.boolean(),
})

/**
 * PHP `json_encode([])` emits `[]` for empty associative arrays — there is no
 * way to distinguish an empty list from an empty map at encode time. The
 * affected response fields (`gridSettings.overrides`, `allowedTypes`) are
 * conceptually maps, so coerce the empty-array case back to an empty object
 * before validating.
 */
function emptyArrayToObject(value: unknown): unknown {
  return Array.isArray(value) && value.length === 0 ? {} : value
}

const gridSettingsSchema = z.object({
  default: viewportSettingsSchema,
  overrides: z.preprocess(emptyArrayToObject, z.record(z.string(), viewportSettingsSchema)),
})

const allowedTypeInfoSchema = z.object({
  label: z.string(),
  icon: z.string(),
  description: z.string(),
})

const baseFieldsWireSchema = z.object({
  self: nodeRefSchema,
  parent: nodeRefSchema,
  title: z.string(),
  blockSchema: blockSchemaSchema,
  obsoleteClassName: z.string().nullable(),
  version: z.number().int(),
  canDelete: z.boolean(),
  canPublish: z.boolean(),
  canUnpublish: z.boolean(),
  canCreate: z.boolean(),
  editLink: z.string().nullable(),
  status: elementStatusSchema,
  summary: z.string().min(1).optional(),
  extensions: z.record(z.string(), z.unknown()).optional(),
})

/**
 * Recursive node schema. PHP's `GridNode::jsonSerialize()` emits
 * `containerType`/`allowedTypes`/`children` only on container nodes and
 * `gridSettings` only on columns; leaf elements omit all four fields.
 *
 * Modeled as a discriminated union once `containerType` is widened with
 * `.optional()` for the leaf variant — Zod's `discriminatedUnion` requires
 * every variant to declare the discriminant key, so leaves declare it as
 * the literal `undefined`.
 */
type ElementNodeWire = z.infer<typeof baseFieldsWireSchema> &
  (
    | { containerType?: undefined }
    | {
        containerType: 'section' | 'row'
        allowedTypes: Record<string, z.infer<typeof allowedTypeInfoSchema>> | null
        children: ElementNodeWire[] | null
      }
    | {
        containerType: 'column'
        allowedTypes: Record<string, z.infer<typeof allowedTypeInfoSchema>> | null
        children: ElementNodeWire[] | null
        gridSettings: z.infer<typeof gridSettingsSchema>
      }
  )

const childrenSchema: z.ZodType<ElementNodeWire[] | null> = z.lazy(() =>
  // eslint-disable-next-line @typescript-eslint/no-use-before-define
  z.array(elementNodeWireSchema).nullable(),
)

const allowedTypesSchema = z.preprocess(
  emptyArrayToObject,
  z.record(z.string(), allowedTypeInfoSchema).nullable(),
)

const sectionWireSchema = baseFieldsWireSchema.extend({
  containerType: z.literal('section'),
  allowedTypes: allowedTypesSchema,
  children: childrenSchema,
})

const rowWireSchema = baseFieldsWireSchema.extend({
  containerType: z.literal('row'),
  allowedTypes: allowedTypesSchema,
  children: childrenSchema,
})

const columnWireSchema = baseFieldsWireSchema.extend({
  containerType: z.literal('column'),
  allowedTypes: allowedTypesSchema,
  children: childrenSchema,
  gridSettings: gridSettingsSchema,
})

const simpleElementWireSchema = baseFieldsWireSchema.extend({
  containerType: z.undefined().optional(),
})

export const elementNodeWireSchema: z.ZodType<ElementNodeWire> = z.union([
  sectionWireSchema,
  rowWireSchema,
  columnWireSchema,
  simpleElementWireSchema,
])

export const treeApiResponseWireSchema = z.object({
  rootParent: nodeRefSchema,
  nodes: z.array(elementNodeWireSchema),
})

// --- Response schemas for non-tree endpoints ---

export const acceptableContainerSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  type: containerTypeSchema,
})

export const acceptableContainerListSchema = z.array(acceptableContainerSchema)

export const pageEntrySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  parentId: z.number().int().nonnegative(),
  hasGridZones: z.boolean(),
})

export const pageEntryListSchema = z.array(pageEntrySchema)

export const zoneListSchema = z.array(z.string().min(1))
