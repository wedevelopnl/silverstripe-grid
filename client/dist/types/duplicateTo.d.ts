import { acceptableContainerSchema, pageEntrySchema } from './schemas';
import type * as v from 'valibot';
export type AcceptableContainer = v.InferOutput<typeof acceptableContainerSchema>;
export type PageEntry = v.InferOutput<typeof pageEntrySchema>;
