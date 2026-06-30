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
import * as v from 'valibot';
export declare const nodeRefSchema: v.ObjectSchema<{
    readonly type: v.PicklistSchema<readonly ["page", "section", "row", "column", "element"], undefined>;
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
}, undefined>;
export declare const viewportSettingsSchema: v.ObjectSchema<{
    readonly width: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly offset: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
    readonly visible: v.BooleanSchema<undefined>;
}, undefined>;
declare const gridSettingsSchema: v.ObjectSchema<{
    readonly default: v.ObjectSchema<{
        readonly width: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
        readonly offset: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
        readonly visible: v.BooleanSchema<undefined>;
    }, undefined>;
    readonly overrides: v.SchemaWithPipe<readonly [v.CustomSchema<unknown, undefined>, v.TransformAction<unknown, unknown>, v.RecordSchema<v.StringSchema<undefined>, v.ObjectSchema<{
        readonly width: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
        readonly offset: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
        readonly visible: v.BooleanSchema<undefined>;
    }, undefined>, undefined>]>;
}, undefined>;
declare const allowedTypeInfoSchema: v.ObjectSchema<{
    readonly label: v.StringSchema<undefined>;
    readonly icon: v.StringSchema<undefined>;
    readonly description: v.StringSchema<undefined>;
}, undefined>;
declare const baseFieldsWireSchema: v.ObjectSchema<{
    readonly self: v.ObjectSchema<{
        readonly type: v.PicklistSchema<readonly ["page", "section", "row", "column", "element"], undefined>;
        readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    }, undefined>;
    readonly parent: v.ObjectSchema<{
        readonly type: v.PicklistSchema<readonly ["page", "section", "row", "column", "element"], undefined>;
        readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    }, undefined>;
    readonly title: v.StringSchema<undefined>;
    readonly blockSchema: v.ObjectSchema<{
        readonly typeName: v.StringSchema<undefined>;
        readonly label: v.StringSchema<undefined>;
        readonly icon: v.StringSchema<undefined>;
        readonly type: v.StringSchema<undefined>;
        readonly title: v.StringSchema<undefined>;
    }, undefined>;
    readonly obsoleteClassName: v.NullableSchema<v.StringSchema<undefined>, undefined>;
    readonly version: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>]>;
    readonly canDelete: v.BooleanSchema<undefined>;
    readonly canPublish: v.BooleanSchema<undefined>;
    readonly canUnpublish: v.BooleanSchema<undefined>;
    readonly canCreate: v.BooleanSchema<undefined>;
    readonly editLink: v.SchemaWithPipe<readonly [v.NullableSchema<v.StringSchema<undefined>, undefined>, v.CheckAction<string | null, "editLink must be a relative path or an http(s) URL">]>;
    readonly status: v.PicklistSchema<["draft", "published", "modified", "removed"], undefined>;
    readonly summary: v.OptionalSchema<v.SchemaWithPipe<readonly [v.StringSchema<undefined>, v.MinLengthAction<string, 1, undefined>]>, undefined>;
    readonly extensions: v.OptionalSchema<v.SchemaWithPipe<readonly [v.CustomSchema<unknown, undefined>, v.RecordSchema<v.StringSchema<undefined>, v.UnknownSchema, undefined>]>, undefined>;
}, undefined>;
/**
 * Recursive node schema. PHP's `GridNode::jsonSerialize()` emits
 * `containerType`/`allowedTypes`/`children` only on container nodes and
 * `gridSettings` only on columns; leaf elements omit all four fields.
 *
 * Modeled as a union of four variants. The leaf variant declares
 * `containerType` as optional-`undefined`, so a node carrying a container type
 * but missing the container fields matches no variant and is rejected.
 */
type ElementNodeWire = v.InferOutput<typeof baseFieldsWireSchema> & ({
    containerType?: undefined;
} | {
    containerType: 'section' | 'row';
    allowedTypes: Record<string, v.InferOutput<typeof allowedTypeInfoSchema>> | null;
    children: ElementNodeWire[] | null;
} | {
    containerType: 'column';
    allowedTypes: Record<string, v.InferOutput<typeof allowedTypeInfoSchema>> | null;
    children: ElementNodeWire[] | null;
    gridSettings: v.InferOutput<typeof gridSettingsSchema>;
});
/**
 * Cast to `GenericSchema<ElementNodeWire>` at the recursive boundary. valibot's
 * `~standard` (StandardSchema) output inference cannot resolve this recursive
 * discriminated union — it widens the PHP-map fields (`allowedTypes`) to
 * `unknown` even though `InferOutput` resolves them correctly and `v.record`
 * validates them at runtime. The cast pins the precise `ElementNodeWire` output
 * that downstream consumers (`endpoints.ts`) depend on.
 */
export declare const elementNodeWireSchema: v.GenericSchema<ElementNodeWire>;
export declare const treeApiResponseWireSchema: v.ObjectSchema<{
    readonly rootParent: v.ObjectSchema<{
        readonly type: v.PicklistSchema<readonly ["page", "section", "row", "column", "element"], undefined>;
        readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    }, undefined>;
    readonly nodes: v.ArraySchema<v.GenericSchema<ElementNodeWire>, undefined>;
}, undefined>;
export declare const acceptableContainerSchema: v.ObjectSchema<{
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    readonly type: v.PicklistSchema<readonly ["section", "row", "column"], undefined>;
}, undefined>;
export declare const acceptableContainerListSchema: v.ArraySchema<v.ObjectSchema<{
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    readonly type: v.PicklistSchema<readonly ["section", "row", "column"], undefined>;
}, undefined>, undefined>;
export declare const pageEntrySchema: v.ObjectSchema<{
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    readonly parentId: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
    readonly hasGridZones: v.BooleanSchema<undefined>;
}, undefined>;
export declare const pageEntryListSchema: v.ArraySchema<v.ObjectSchema<{
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    readonly parentId: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
    readonly hasGridZones: v.BooleanSchema<undefined>;
}, undefined>, undefined>;
export declare const zoneListSchema: v.ArraySchema<v.SchemaWithPipe<readonly [v.StringSchema<undefined>, v.MinLengthAction<string, 1, undefined>]>, undefined>;
export {};
