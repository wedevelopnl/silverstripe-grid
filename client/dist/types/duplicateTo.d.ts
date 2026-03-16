import { z } from 'zod/v4-mini';
export declare const acceptableContainerSchema: z.ZodMiniObject<{
    id: z.ZodMiniNumber<number>;
    title: z.ZodMiniString<string>;
    type: z.ZodMiniString<string>;
}, z.core.$strip>;
export declare const pageEntrySchema: z.ZodMiniObject<{
    id: z.ZodMiniNumber<number>;
    title: z.ZodMiniString<string>;
    parentId: z.ZodMiniNumber<number>;
    hasGridZones: z.ZodMiniBoolean<boolean>;
}, z.core.$strip>;
export declare const zonesResponseSchema: z.ZodMiniArray<z.ZodMiniString<string>>;
export declare const acceptableContainersResponseSchema: z.ZodMiniArray<z.ZodMiniObject<{
    id: z.ZodMiniNumber<number>;
    title: z.ZodMiniString<string>;
    type: z.ZodMiniString<string>;
}, z.core.$strip>>;
export declare const pagesResponseSchema: z.ZodMiniArray<z.ZodMiniObject<{
    id: z.ZodMiniNumber<number>;
    title: z.ZodMiniString<string>;
    parentId: z.ZodMiniNumber<number>;
    hasGridZones: z.ZodMiniBoolean<boolean>;
}, z.core.$strip>>;
export type AcceptableContainer = z.infer<typeof acceptableContainerSchema>;
export type PageEntry = z.infer<typeof pageEntrySchema>;
