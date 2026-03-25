export interface AcceptableContainer {
  id: number;
  title: string;
  type: string;
}

export interface PageEntry {
  id: number;
  title: string;
  parentId: number;
  hasGridZones: boolean;
}
