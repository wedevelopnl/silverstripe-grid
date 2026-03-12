import { z } from 'zod';
declare const viewportConfigSchema: z.ZodObject<{
    key: z.ZodString;
    label: z.ZodString;
}, z.core.$strip>;
export declare const adapterConfigSchema: z.ZodObject<{
    viewports: z.ZodArray<z.ZodObject<{
        key: z.ZodString;
        label: z.ZodString;
    }, z.core.$strip>>;
    defaultViewport: z.ZodString;
    columnCount: z.ZodNumber;
    rowClasses: z.ZodString;
    offsetStrategy: z.ZodEnum<{
        margin: "margin";
        "grid-placement": "grid-placement";
    }>;
    baseWidthClasses: z.ZodRecord<z.ZodString, z.ZodString>;
    baseOffsetClasses: z.ZodRecord<z.ZodString, z.ZodString>;
}, z.core.$strip>;
export type ViewportConfig = z.infer<typeof viewportConfigSchema>;
export type AdapterConfig = z.infer<typeof adapterConfigSchema>;
export {};
