import { NodeRef } from './identity';
/**
 * What the grid editor is rooted at.
 *
 * One value instead of the former `pageId` + `zone` + `rootType` triple: those
 * three could spell states that do not exist (a block id carrying a zone), and
 * every consumer had to re-derive which of the two trees it was looking at.
 * The union makes the impossible states unrepresentable and forces each
 * consumer to say what it does in a block-rooted editor.
 */
export type EditorRoot = PageRoot | SharedBlockRoot;
export interface PageRoot {
    readonly kind: 'page';
    readonly pageId: number;
    readonly zone: string;
    /** Archived page version. Set only by the readonly history viewer. */
    readonly version?: number;
}
/**
 * The library editor. A SharedBlock owns exactly one subtree and has no zones,
 * so neither field exists here — which is what stops a zone reaching an
 * endpoint that rejects it.
 */
export interface SharedBlockRoot {
    readonly kind: 'sharedBlock';
    readonly blockId: number;
}
/**
 * The root as a {@link NodeRef} — what the tree reports as its `rootParent`,
 * and the parent a first-level child attaches to. Page and block differ only
 * in the type string here, which is the whole point of the union.
 */
export declare function rootParentRef(root: EditorRoot): NodeRef;
