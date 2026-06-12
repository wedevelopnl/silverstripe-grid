import type { AcceptableContainer, PageEntry } from '@/types/duplicateTo'
import type { ContainerType, ElementNode, TreeApiResponse } from '@/types/elements'
import { isContainerNode } from '@/types/elements'
import { NodeIdentity, type NodeRef } from '@/types/identity'
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
 * Validate the tree response shape from the server with Zod, then attach the
 * derived `nodeKey`/`parentKey`/`id` fields that downstream code expects on
 * every node. The wire schema rejects invalid shapes with a structured error;
 * the post-parse step is purely additive.
 */
export function normaliseTreeResponse(raw: unknown): TreeApiResponse {
  const parsed = treeApiResponseWireSchema.parse(raw)

  return {
    rootParent: parsed.rootParent,
    nodes: parsed.nodes.map((node) => attachDerivedFields(node)),
  }
}

type NodeWire = (typeof treeApiResponseWireSchema._output)['nodes'][number]

function attachDerivedFields(node: NodeWire): ElementNode {
  const enriched = {
    ...node,
    nodeKey: NodeIdentity.toKey(node.self.type, node.self.id),
    parentKey: NodeIdentity.toKey(node.parent.type, node.parent.id),
  } as ElementNode

  if (isContainerNode(enriched) && enriched.children !== null) {
    // The wire schema validated `children` as recursive NodeWire arrays; the
    // mapped result is a fully-typed ElementNode array of the same length.
    ;(enriched as { children: ElementNode[] }).children = enriched.children.map((child) =>
      attachDerivedFields(child as unknown as NodeWire),
    )
  }

  return enriched
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
  await apiPatch(`${base}/api/publish`, { element })
}

export async function unpublishElement(element: NodeRef): Promise<void> {
  const base = getControllerLink()
  await apiPatch(`${base}/api/unpublish`, { element })
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
  await apiPost(`${base}/api/createContent`, params)
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

// --- Reset Grid Settings Overrides ---

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

// --- Duplicate To ---

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

// --- Acceptable Containers ---

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
  return acceptableContainerListSchema.parse(raw)
}

// --- Zones ---

export async function fetchZones(pageId: number): Promise<string[]> {
  const base = getControllerLink()
  const raw = await apiGet<unknown>(`${base}/api/zones/${pageId}`)
  return zoneListSchema.parse(raw)
}

// --- Pages ---

export type { PageEntry } from '@/types/duplicateTo'

export async function fetchPages(search?: string): Promise<PageEntry[]> {
  const base = getControllerLink()
  const params = search ? `?search=${encodeURIComponent(search)}` : ''
  const raw = await apiGet<unknown>(`${base}/api/pages${params}`)
  return pageEntryListSchema.parse(raw)
}
