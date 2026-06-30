import type { z } from 'zod'
import type { acceptableContainerSchema, pageEntrySchema } from './schemas'

export type AcceptableContainer = z.infer<typeof acceptableContainerSchema>
export type PageEntry = z.infer<typeof pageEntrySchema>
