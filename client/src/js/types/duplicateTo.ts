import type * as v from 'valibot'
import type { acceptableContainerSchema, pageEntrySchema } from './schemas'

export type AcceptableContainer = v.InferOutput<typeof acceptableContainerSchema>
export type PageEntry = v.InferOutput<typeof pageEntrySchema>
