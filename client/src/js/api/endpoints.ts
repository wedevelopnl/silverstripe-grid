import type { AcceptableContainer, PageEntry } from '@/types/duplicateTo'
import type {
  ColumnNode,
  ContainerType,
  ElementNode,
  RowNode,
  SectionNode,
  SimpleElementNode,
  TreeApiResponse,
} from '@/types/elements'
import { NodeIdentity, type NodeRef } from '@/types/identity'
import * as v from 'valibot'
import {
  acceptableContainerListSchema,
  pageEntryListSchema,
  treeApiResponseWireSchema,
  zoneListSchema,
} from '@/types/schemas'
import { apiDelete, apiGet, apiPatch, apiPost } from './client'
import { getControllerLink } from './config'

/**
 * Fetch the full element tree for a CMS page.
 *
 * When `version` is omitted, the draft tree is returned from
 * `/api/readTree/{pageId}/{zone}`. When `version` is provided, the archived
 * tree at that page version is returned from
 * `/api/readTree/{pageId}/{zone}/version/{version}` — used by the readonly
 * history viewer.
 */
export async function fetchElementTree(
  pageId: number,
  zone: string,
  version?: number,
): Promise<TreeApiResponse> {
  const base = getControllerLink()
  const encodedZone = encodeURIComponent(zone)
  const url =
    version !== undefined
      ? `${base}/api/readTree/${pageId}/${encodedZone}/version/${version}`
      : `${base}/api/readTree/${pageId}/${encodedZone}`
  const raw = await apiGet<unknown>(url)
  return normaliseTreeResponse(raw)
}

/**
 * Validate the tree response shape from the server with valibot, then attach the
 * derived `nodeKey`/`parentKey`/`id` fields that downstream code expects on
 * every node. The wire schema rejects invalid shapes with a structured error;
 * the post-parse step is purely additive.
 */
export function normaliseTreeResponse(raw: unknown): TreeApiResponse {
  const parsed = v.parse(treeApiResponseWireSchema, raw)

  return {
    rootParent: parsed.rootParent,
    nodes: parsed.nodes.map((node) => attachDerivedFields(node)),
  }
}

type NodeWire = v.InferOutput<typeof treeApiResponseWireSchema>['nodes'][number]

function attachDerivedFields(node: NodeWire): ElementNode {
  const nodeKey = NodeIdentity.toKey(node.self.type, node.self.id)
  const parentKey = NodeIdentity.toKey(node.parent.type, node.parent.id)

  if (node.containerType === 'section') {
    // The PHP domain layer enforces the Section→Row→Column→leaf hierarchy.
    // The wire schema's childrenSchema is shared across all container variants
    // and does NOT distinguish children by type, so this cast relies on the
    // server's hierarchy invariant, not on valibot. TypeScript cannot express the
    // narrower RowNode[] invariant without a cast because attachDerivedFields
    // returns the wide ElementNode union.
    const children =
      node.children !== null ? (node.children.map(attachDerivedFields) as RowNode[]) : null
    return {
      ...node,
      nodeKey,
      parentKey,
      containerType: 'section',
      allowedTypes: node.allowedTypes,
      children,
    } satisfies SectionNode
  }

  if (node.containerType === 'row') {
    // Same cast rationale as section: the server's hierarchy invariant (not valibot)
    // guarantees a row's children are columns.
    const children =
      node.children !== null ? (node.children.map(attachDerivedFields) as ColumnNode[]) : null
    return {
      ...node,
      nodeKey,
      parentKey,
      containerType: 'row',
      allowedTypes: node.allowedTypes,
      children,
    } satisfies RowNode
  }

  if (node.containerType === 'column') {
    // Same cast rationale as section: the server's hierarchy invariant (not valibot)
    // guarantees a column's children are simple (leaf) elements.
    const children =
      node.children !== null
        ? (node.children.map(attachDerivedFields) as SimpleElementNode[])
        : null
    return {
      ...node,
      nodeKey,
      parentKey,
      containerType: 'column',
      allowedTypes: node.allowedTypes,
      children,
      gridSettings: node.gridSettings,
    } satisfies ColumnNode
  }

  // Leaf element — containerType is absent on the wire shape. Destructure it
  // out so the spread does not carry the union's containerType variants into
  // the returned object, which would conflict with SimpleElementNode's
  // `containerType?: never` declaration.
  const { containerType: _ct, ...leafFields } = node
  return {
    ...leafFields,
    nodeKey,
    parentKey,
  } satisfies SimpleElementNode
}

export interface CreateElementParams {
  containerType: ContainerType
  parent: NodeRef
  insertAfterElementID?: number
  /** Place the new element before all existing siblings. Mutually exclusive with `insertAfterElementID`. */
  insertAtStart?: boolean
  zone?: string
}

export async function createElement(params: CreateElementParams): Promise<void> {
  const base = getControllerLink()
  await apiPost(`${base}/api/create`, params)
}

export async function publishElement(element: NodeRef): Promise<void> {
  const base = getControllerLink()
  await apiPatch(`${base}/api/setPublished`, { element, published: true })
}

export async function unpublishElement(element: NodeRef): Promise<void> {
  const base = getControllerLink()
  await apiPatch(`${base}/api/setPublished`, { element, published: false })
}

export async function archiveElement(element: NodeRef): Promise<void> {
  const base = getControllerLink()
  // DELETE bodies are not universally honoured by intermediaries — send the
  // identity components on the query string instead.
  await apiDelete(`${base}/api/delete`, { type: element.type, id: element.id })
}

export async function duplicateElement(element: NodeRef): Promise<void> {
  const base = getControllerLink()
  await apiPost(`${base}/api/duplicate`, { element })
}

export interface ReorderElementParams {
  element: NodeRef
  parent: NodeRef
  after: NodeRef | null
}

export async function reorderElement(params: ReorderElementParams): Promise<void> {
  const base = getControllerLink()
  await apiPatch(`${base}/api/reorder`, params)
}

export interface CreateContentElementParams {
  className: string
  parent: NodeRef
  insertAfterElementID?: number
}

export async function createContentElement(params: CreateContentElementParams): Promise<void> {
  const base = getControllerLink()
  await apiPost(`${base}/api/create`, params)
}

export interface UpdateGridSettingsParams {
  element: NodeRef
  viewport: string
  width: number
  offset: number
  visible: boolean
}

export async function updateGridSettings(params: UpdateGridSettingsParams): Promise<void> {
  const base = getControllerLink()
  await apiPatch(`${base}/api/updateGridSettings`, params)
}

export interface ResetGridSettingsOverridesParams {
  pageId: number
  zone: string
  viewport?: string
}

export async function resetGridSettingsOverrides(
  params: ResetGridSettingsOverridesParams,
): Promise<void> {
  const base = getControllerLink()
  await apiDelete(`${base}/api/resetGridSettingsOverrides`, {
    pageId: params.pageId,
    zone: params.zone,
    viewport: params.viewport,
  })
}

export interface DuplicateToParams {
  element: NodeRef
  targetPageId: number
  targetZone: string
  targetParent: NodeRef
}

export async function duplicateToElement(params: DuplicateToParams): Promise<void> {
  const base = getControllerLink()
  await apiPost(`${base}/api/duplicateTo`, params)
}

export type { AcceptableContainer } from '@/types/duplicateTo'

export async function fetchAcceptableContainers(
  pageId: number,
  zone: string,
  elementType: string,
): Promise<AcceptableContainer[]> {
  const base = getControllerLink()
  const raw = await apiGet<unknown>(
    `${base}/api/acceptableContainers/${pageId}/${encodeURIComponent(zone)}/${encodeURIComponent(elementType)}`,
  )
  return v.parse(acceptableContainerListSchema, raw)
}

export async function fetchZones(pageId: number): Promise<string[]> {
  const base = getControllerLink()
  const raw = await apiGet<unknown>(`${base}/api/zones/${pageId}`)
  return v.parse(zoneListSchema, raw)
}

export type { PageEntry } from '@/types/duplicateTo'

export async function fetchPages(search?: string): Promise<PageEntry[]> {
  const base = getControllerLink()
  const params = search ? `?search=${encodeURIComponent(search)}` : ''
  const raw = await apiGet<unknown>(`${base}/api/pages${params}`)
  return v.parse(pageEntryListSchema, raw)
}
