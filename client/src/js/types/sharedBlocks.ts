/**
 * Wire types for the shared block library.
 *
 * The status values mirror PHP's `SharedBlockStatus`: a block is only as
 * published as its least published part, so `modified` covers both the block
 * record and anything in its subtree being out of sync with live.
 */

import * as v from 'valibot'
import { editLinkWireSchema, isSafeEditLink } from './editLink'

export const sharedBlockStatusSchema = v.picklist(['notPublished', 'modified', 'published'])

export const sharedBlockMetaWireSchema = v.object({
  blockId: v.pipe(v.number(), v.integer(), v.minValue(1)),
  title: v.string(),
  /** Distinct consuming PAGES, not placements. */
  usageCount: v.pipe(v.number(), v.integer(), v.minValue(0)),
  status: sharedBlockStatusSchema,
  /** The block's own form in the library — where a placement's actions lead. */
  editLink: editLinkWireSchema,
})

/** Root type of a block's subtree; null while the block is still empty. */
export const sharedBlockRootTypeSchema = v.nullable(
  v.picklist(['section', 'row', 'column', 'element']),
)

export const sharedBlockListEntrySchema = v.object({
  id: v.pipe(v.number(), v.integer(), v.minValue(1)),
  title: v.string(),
  rootType: sharedBlockRootTypeSchema,
  usageCount: v.pipe(v.number(), v.integer(), v.minValue(0)),
  status: sharedBlockStatusSchema,
})

export const sharedBlockListSchema = v.array(sharedBlockListEntrySchema)

/**
 * How far a delete would reach. `liveUsageCount` is reported separately because
 * that half of the damage is already public and does not wait for a publish.
 */
export const sharedBlockUsageSchema = v.object({
  usageCount: v.pipe(v.number(), v.integer(), v.minValue(0)),
  liveUsageCount: v.pipe(v.number(), v.integer(), v.minValue(0)),
})

/** Mirrors PHP's `SharedBlockDeleteMode`. */
export const sharedBlockDeleteModeSchema = v.picklist(['remove', 'unshare'])

/**
 * A block the library's add button just created. Unlike a node's `editLink`
 * this is never null — the block exists by the time it is reported — and it is
 * navigated to, so it carries the same safety guard.
 */
export const sharedBlockCreatedSchema = v.object({
  id: v.pipe(v.number(), v.integer(), v.minValue(1)),
  editLink: v.pipe(
    v.string(),
    v.check(isSafeEditLink, 'editLink must be a relative path or an http(s) URL'),
  ),
})

export type SharedBlockStatus = v.InferOutput<typeof sharedBlockStatusSchema>
export type SharedBlockMeta = v.InferOutput<typeof sharedBlockMetaWireSchema>
export type SharedBlockRootType = v.InferOutput<typeof sharedBlockRootTypeSchema>
export type SharedBlockListEntry = v.InferOutput<typeof sharedBlockListEntrySchema>
export type SharedBlockUsage = v.InferOutput<typeof sharedBlockUsageSchema>
export type SharedBlockDeleteMode = v.InferOutput<typeof sharedBlockDeleteModeSchema>
export type SharedBlockCreated = v.InferOutput<typeof sharedBlockCreatedSchema>

/** The parent kinds a block can be placed under, as the list endpoint filters them. */
export type SharedBlockParentType = 'page' | 'section' | 'row' | 'column'
