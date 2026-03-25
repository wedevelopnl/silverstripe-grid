export interface ViewportConfig {
    key: string;
    label: string;
}
export type OffsetStrategy = 'margin' | 'grid-placement';
export interface AdapterConfig {
    viewports: ViewportConfig[];
    defaultViewport: string;
    columnCount: number;
    rowClasses: string;
    offsetStrategy: OffsetStrategy;
    baseWidthClasses: Record<string, string>;
    baseOffsetClasses: Record<string, string>;
}
