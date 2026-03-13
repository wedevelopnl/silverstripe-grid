import { z } from 'zod/v4-mini';

export const acceptableContainerSchema = z.object({
  id: z.number(),
  title: z.string(),
  type: z.string(),
});

export const pageEntrySchema = z.object({
  id: z.number(),
  title: z.string(),
  parentId: z.number(),
  hasGridZones: z.boolean(),
});

export const zonesResponseSchema = z.array(z.string());
export const acceptableContainersResponseSchema = z.array(acceptableContainerSchema);
export const pagesResponseSchema = z.array(pageEntrySchema);

export type AcceptableContainer = z.infer<typeof acceptableContainerSchema>;
export type PageEntry = z.infer<typeof pageEntrySchema>;
