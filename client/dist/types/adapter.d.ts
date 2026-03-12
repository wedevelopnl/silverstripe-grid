import { z } from 'zod/v4-mini';
declare const viewportConfigSchema: z.ZodMiniObject<{
    key: z.ZodMiniString<string>;
    label: z.ZodMiniString<string>;
}, z.core.$strip>;
export declare const adapterConfigSchema: z.ZodMiniObject<{
    viewports: z.ZodMiniArray<z.ZodMiniObject<{
        key: z.ZodMiniString<string>;
        label: z.ZodMiniString<string>;
    }, z.core.$strip>>;
    defaultViewport: z.ZodMiniString<string>;
    columnCount: z.ZodMiniNumberFormat;
    rowClasses: z.ZodMiniString<string>;
    offsetStrategy: z.ZodMiniEnum<{
        margin: "margin";
        "grid-placement": "grid-placement";
    }>;
    baseWidthClasses: z.ZodMiniRecord<z.ZodMiniString<string>, z.ZodMiniString<string>>;
    baseOffsetClasses: z.ZodMiniRecord<z.ZodMiniString<string>, z.ZodMiniString<string>>;
}, z.core.$strip>;
export type ViewportConfig = z.infer<typeof viewportConfigSchema>;
export type AdapterConfig = z.infer<typeof adapterConfigSchema>;
export {};
