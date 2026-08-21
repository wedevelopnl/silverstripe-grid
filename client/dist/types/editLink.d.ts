import * as v from 'valibot';
/**
 * Guard for `editLink` values. CMS edit URLs ("/admin/pages/edit/show/5") are
 * root-relative and safe. Absolute URLs must use http/https; javascript:,
 * data:, vbscript:, and other schemes are rejected. Protocol-relative URLs
 * ("//evil.com") are also rejected — they start with "/" but also with "//",
 * so we require a single-slash prefix (!value.startsWith('//')).
 */
export declare function isSafeEditLink(value: string): boolean;
/** A nullable CMS link that has passed {@link isSafeEditLink}. */
export declare const editLinkWireSchema: v.SchemaWithPipe<readonly [v.NullableSchema<v.StringSchema<undefined>, undefined>, v.CheckAction<string | null, "editLink must be a relative path or an http(s) URL">]>;
