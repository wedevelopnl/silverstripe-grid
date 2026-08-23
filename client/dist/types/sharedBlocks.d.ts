/**
 * Wire types for the shared block library.
 *
 * The status values mirror PHP's `SharedBlockStatus`: a block is only as
 * published as its least published part, so `modified` covers both the block
 * record and anything in its subtree being out of sync with live.
 */
import * as v from 'valibot';
export declare const sharedBlockStatusSchema: v.PicklistSchema<["notPublished", "modified", "published"], undefined>;
export declare const sharedBlockMetaWireSchema: v.ObjectSchema<{
    readonly blockId: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    /** Distinct consuming PAGES, not placements. */
    readonly usageCount: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
    readonly status: v.PicklistSchema<["notPublished", "modified", "published"], undefined>;
    /** The block's own form in the library — where a placement's actions lead. */
    readonly editLink: v.SchemaWithPipe<readonly [v.NullableSchema<v.StringSchema<undefined>, undefined>, v.CheckAction<string | null, "editLink must be a relative path or an http(s) URL">]>;
}, undefined>;
/** Root type of a block's subtree; null while the block is still empty. */
export declare const sharedBlockRootTypeSchema: v.NullableSchema<v.PicklistSchema<["section", "row", "column", "element"], undefined>, undefined>;
export declare const sharedBlockListEntrySchema: v.ObjectSchema<{
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    readonly rootType: v.NullableSchema<v.PicklistSchema<["section", "row", "column", "element"], undefined>, undefined>;
    readonly usageCount: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
    readonly status: v.PicklistSchema<["notPublished", "modified", "published"], undefined>;
}, undefined>;
export declare const sharedBlockListSchema: v.ArraySchema<v.ObjectSchema<{
    readonly id: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 1, undefined>]>;
    readonly title: v.StringSchema<undefined>;
    readonly rootType: v.NullableSchema<v.PicklistSchema<["section", "row", "column", "element"], undefined>, undefined>;
    readonly usageCount: v.SchemaWithPipe<readonly [v.NumberSchema<undefined>, v.IntegerAction<number, undefined>, v.MinValueAction<number, 0, undefined>]>;
    readonly status: v.PicklistSchema<["notPublished", "modified", "published"], undefined>;
}, undefined>, undefined>;
export type SharedBlockStatus = v.InferOutput<typeof sharedBlockStatusSchema>;
export type SharedBlockMeta = v.InferOutput<typeof sharedBlockMetaWireSchema>;
export type SharedBlockRootType = v.InferOutput<typeof sharedBlockRootTypeSchema>;
export type SharedBlockListEntry = v.InferOutput<typeof sharedBlockListEntrySchema>;
/** The parent kinds a block can be placed under, as the list endpoint filters them. */
export type SharedBlockParentType = 'page' | 'section' | 'row' | 'column';
