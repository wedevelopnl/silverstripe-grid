import { StatusFlags } from './elements';
export type ElementStatus = 'draft' | 'published' | 'modified' | 'removed';
export declare function getElementStatus(statusFlags: StatusFlags): ElementStatus;
