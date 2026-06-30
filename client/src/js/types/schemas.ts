/**
 * Valibot schemas for API responses.
 *
 * These describe the JSON shape on the wire — what the PHP `GridController`
 * emits — and are used at the API boundary in `client/src/js/api/endpoints.ts`
 * to validate every server response before it flows into the rest of the
 * frontend.
 *
 * The in-memory types in `./elements.ts` enrich nodes with derived fields
 * (`nodeKey`, `parentKey`, `id`) that the wire shape does not include.
 * `normaliseTreeResponse` parses with these schemas first, then layers the
 * derived fields on top.
 */

import * as v from 'valibot'
import { CONTAINER_TYPES } from './elements'
import { NODE_TYPES } from './identity'

const nodeTypeSchema = v.picklist(NODE_TYPES)
const containerTypeSchema = v.picklist(CONTAINER_TYPES)

export const nodeRefSchema = v.object({
  type: nodeTypeSchema,
  id: v.pipe(v.number(), v.integer(), v.minValue(1)),
})

const elementStatusSchema = v.picklist(['draft', 'published', 'modified', 'removed'])

const blockSchemaSchema = v.object({
  typeName: v.string(),
  label: v.string(),
  icon: v.string(),
  type: v.string(),
  title: v.string(),
})

export const viewportSettingsSchema = v.object({
  width: v.pipe(v.number(), v.integer(), v.minValue(1)),
  offset: v.pipe(v.number(), v.integer(), v.minValue(0)),
  visible: v.boolean(),
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

/**
 * Build a schema for a PHP-encoded string-keyed map. Coerces the empty-array
 * sentinel (`[]`) to `{}`, and rejects NON-empty arrays up front — valibot's
 * `v.record` otherwise accepts an array as a record keyed by `"0"`, `"1"`, …
 * (verified at runtime, unlike zod's `z.record`, which rejects arrays). The
 * array guard runs before the empty-array→`{}` transform, so the trailing
 * `v.record` only ever validates an object.
 */
function phpMapSchema<TValue extends v.GenericSchema>(valueSchema: TValue) {
  return v.pipe(
    v.custom<unknown>((value) => !Array.isArray(value) || value.length === 0),
    v.transform(emptyArrayToObject),
    v.record(v.string(), valueSchema),
  )
}

const gridSettingsSchema = v.object({
  default: viewportSettingsSchema,
  overrides: phpMapSchema(viewportSettingsSchema),
})

const allowedTypeInfoSchema = v.object({
  label: v.string(),
  icon: v.string(),
  description: v.string(),
})

/**
 * Guard for `editLink` values. CMS edit URLs ("/admin/pages/edit/show/5") are
 * root-relative and safe. Absolute URLs must use http/https; javascript:,
 * data:, vbscript:, and other schemes are rejected. Protocol-relative URLs
 * ("//evil.com") are also rejected — they start with "/" but also with "//",
 * so we require a single-slash prefix (!value.startsWith('//')).
 */
function isSafeEditLink(value: string): boolean {
  // Relative links (CMS edit URLs like "/admin/pages/edit/show/5") are safe.
  // Absolute URLs must use http/https; reject javascript:, data:, vbscript:, etc.
  // Backslash rejection prevents browser backslash→slash normalisation open-redirect
  // (e.g. /\evil.com → https://evil.com/ per WHATWG URL spec).
  // Tab/LF/CR rejection prevents WHATWG-stripping open-redirect: browsers strip these
  // control characters from URLs before routing, so "/\t/evil.com" normalises to
  // "//evil.com" — a protocol-relative off-origin redirect.
  if (
    value.startsWith('/') &&
    !value.startsWith('//') &&
    !value.includes('\\') &&
    !/[\t\n\r]/.test(value)
  )
    return true
  try {
    const url = new URL(value)
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    // Not a parseable absolute URL and not root-relative → reject.
    return false
  }
}

const baseFieldsWireSchema = v.object({
  self: nodeRefSchema,
  parent: nodeRefSchema,
  title: v.string(),
  blockSchema: blockSchemaSchema,
  obsoleteClassName: v.nullable(v.string()),
  version: v.pipe(v.number(), v.integer()),
  canDelete: v.boolean(),
  canPublish: v.boolean(),
  canUnpublish: v.boolean(),
  canCreate: v.boolean(),
  editLink: v.pipe(
    v.nullable(v.string()),
    v.check(
      (value) => value === null || isSafeEditLink(value),
      'editLink must be a relative path or an http(s) URL',
    ),
  ),
  status: elementStatusSchema,
  summary: v.optional(v.pipe(v.string(), v.minLength(1))),
  // Reject arrays before `v.record`: valibot's `v.record` accepts an array as a
  // record keyed "0", "1", … (unlike zod's `z.record`, which rejects arrays).
  // `extensions` is never PHP's empty-map `[]` sentinel, so — unlike the
  // `phpMapSchema` fields — it rejects ALL arrays with no empty→`{}` coercion,
  // matching the original zod `z.record(...)` (which rejected `[]` and `[x]`).
  extensions: v.optional(
    v.pipe(
      v.custom<unknown>((value) => !Array.isArray(value)),
      v.record(v.string(), v.unknown()),
    ),
  ),
})

/**
 * Recursive node schema. PHP's `GridNode::jsonSerialize()` emits
 * `containerType`/`allowedTypes`/`children` only on container nodes and
 * `gridSettings` only on columns; leaf elements omit all four fields.
 *
 * Modeled as a union of four variants. The leaf variant declares
 * `containerType` as optional-`undefined`, so a node carrying a container type
 * but missing the container fields matches no variant and is rejected.
 */
type ElementNodeWire = v.InferOutput<typeof baseFieldsWireSchema> &
  (
    | { containerType?: undefined }
    | {
        containerType: 'section' | 'row'
        allowedTypes: Record<string, v.InferOutput<typeof allowedTypeInfoSchema>> | null
        children: ElementNodeWire[] | null
      }
    | {
        containerType: 'column'
        allowedTypes: Record<string, v.InferOutput<typeof allowedTypeInfoSchema>> | null
        children: ElementNodeWire[] | null
        gridSettings: v.InferOutput<typeof gridSettingsSchema>
      }
  )

const childrenSchema: v.GenericSchema<ElementNodeWire[] | null> = v.lazy(() =>
  // eslint-disable-next-line @typescript-eslint/no-use-before-define
  v.nullable(v.array(elementNodeWireSchema)),
)

const allowedTypesSchema = v.nullable(phpMapSchema(allowedTypeInfoSchema))

const sectionWireSchema = v.object({
  ...baseFieldsWireSchema.entries,
  containerType: v.literal('section'),
  allowedTypes: allowedTypesSchema,
  children: childrenSchema,
})

const rowWireSchema = v.object({
  ...baseFieldsWireSchema.entries,
  containerType: v.literal('row'),
  allowedTypes: allowedTypesSchema,
  children: childrenSchema,
})

const columnWireSchema = v.object({
  ...baseFieldsWireSchema.entries,
  containerType: v.literal('column'),
  allowedTypes: allowedTypesSchema,
  children: childrenSchema,
  gridSettings: gridSettingsSchema,
})

const simpleElementWireSchema = v.object({
  ...baseFieldsWireSchema.entries,
  containerType: v.optional(v.undefined_()),
})

/**
 * Cast to `GenericSchema<ElementNodeWire>` at the recursive boundary. valibot's
 * `~standard` (StandardSchema) output inference cannot resolve this recursive
 * discriminated union — it widens the PHP-map fields (`allowedTypes`) to
 * `unknown` even though `InferOutput` resolves them correctly and `v.record`
 * validates them at runtime. The cast pins the precise `ElementNodeWire` output
 * that downstream consumers (`endpoints.ts`) depend on.
 */
export const elementNodeWireSchema = v.union([
  sectionWireSchema,
  rowWireSchema,
  columnWireSchema,
  simpleElementWireSchema,
]) as v.GenericSchema<ElementNodeWire>

export const treeApiResponseWireSchema = v.object({
  rootParent: nodeRefSchema,
  nodes: v.array(elementNodeWireSchema),
})

// --- Response schemas for non-tree endpoints ---

export const acceptableContainerSchema = v.object({
  id: v.pipe(v.number(), v.integer(), v.minValue(1)),
  title: v.string(),
  type: containerTypeSchema,
})

export const acceptableContainerListSchema = v.array(acceptableContainerSchema)

export const pageEntrySchema = v.object({
  id: v.pipe(v.number(), v.integer(), v.minValue(1)),
  title: v.string(),
  parentId: v.pipe(v.number(), v.integer(), v.minValue(0)),
  hasGridZones: v.boolean(),
})

export const pageEntryListSchema = v.array(pageEntrySchema)

export const zoneListSchema = v.array(v.pipe(v.string(), v.minLength(1)))
