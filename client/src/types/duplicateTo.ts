import type { ContainerType } from './elements'

export interface AcceptableContainer {
  id: number
  title: string
  type: ContainerType
}

export interface PageEntry {
  id: number
  title: string
  parentId: number
  hasGridZones: boolean
}
