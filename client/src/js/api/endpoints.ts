import type { AcceptableContainer, PageEntry } from '@/types/duplicateTo'
import type {
  ColumnNode,
  ContainerType,
  ElementNode,
  RowNode,
  SectionNode,
  SharedBlockReferenceNode,
  SimpleElementNode,
  TreeApiResponse,
} from '@/types/elements'
import { NodeIdentity, type NodeKey, type NodeRef } from '@/types/identity'
import type { SharedBlockListEntry, SharedBlockParentType } from '@/types/sharedBlocks'
import { sharedBlockListSchema } from '@/types/sharedBlocks'
import * as v from 'valibot'
import {
  acceptableContainerListSchema,
  pageEntryListSchema,
  treeApiResponseWireSchema,
  zoneListSchema,
} from '@/types/schemas'
import { apiDelete, apiGet, apiPatch, apiPost, apiPostJson } from './client'
import { getControllerLink, getSharedBlockControllerLink } from './config'

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
 *
 * The wire carries allowed child types ONCE per container type at the root
 * (they are per-TYPE data — per-node maps were identical copies). This step
 * re-attaches the shared map to every container node BY REFERENCE, so the
 * internal `ElementNode` model and every component consuming
 * `node.allowedTypes` are unchanged, at no per-node memory cost.
 */
export function normaliseTreeResponse(raw: unknown): TreeApiResponse {
  const parsed = v.parse(treeApiResponseWireSchema, raw)

  return {
    rootParent: parsed.rootParent,
    nodes: parsed.nodes.map((node) => attachDerivedFields(node, parsed.allowedTypes)),
  }
}

type ParsedTreeWire = v.InferOutput<typeof treeApiResponseWireSchema>
type NodeWire = ParsedTreeWire['nodes'][number]
type AllowedTypesByContainerType = ParsedTreeWire['allowedTypes']

/**
 * @param sharedBlockKey Key of the enclosing shared block placement, stamped on
 *   every descendant so drag collision filtering can tell the two sides of the
 *   shared boundary apart. Undefined for page-local content.
 */
function attachDerivedFields(
  node: NodeWire,
  allowedTypes: AllowedTypesByContainerType,
  sharedBlockKey?: NodeKey,
): ElementNode {
  const nodeKey = NodeIdentity.toKey(node.self.type, node.self.id)
  const parentKey = NodeIdentity.toKey(node.parent.type, node.parent.id)

  if (node.sharedBlock !== undefined) {
    // The placement itself is page-local — it is the boundary, not inside it —
    // so its own sharedBlockKey stays unset while everything below inherits it.
    const children = node.children.map((child) => attachDerivedFields(child, allowedTypes, nodeKey))
    const { containerType: _ct, ...referenceFields } = node
    return {
      ...referenceFields,
      nodeKey,
      parentKey,
      ...(sharedBlockKey !== undefined ? { sharedBlockKey } : {}),
      sharedBlock: node.sharedBlock,
      children: children as [ElementNode] | [],
    } satisfies SharedBlockReferenceNode
  }

  if (node.containerType === 'section') {
    // The PHP domain layer enforces the Section→Row→Column→leaf hierarchy.
    // The wire schema's childrenSchema is shared across all container variants
    // and does NOT distinguish children by type, so this cast relies on the
    // server's hierarchy invariant, not on valibot. TypeScript cannot express the
    // narrower RowNode[] invariant without a cast because attachDerivedFields
    // returns the wide ElementNode union.
    const children =
      node.children !== null
        ? (node.children.map((child) =>
            attachDerivedFields(child, allowedTypes, sharedBlockKey),
          ) as RowNode[])
        : null
    return {
      ...node,
      nodeKey,
      parentKey,
      ...(sharedBlockKey !== undefined ? { sharedBlockKey } : {}),
      containerType: 'section',
      allowedTypes: allowedTypes.section,
      children,
    } satisfies SectionNode
  }

  if (node.containerType === 'row') {
    // Same cast rationale as section: the server's hierarchy invariant (not valibot)
    // guarantees a row's children are columns.
    const children =
      node.children !== null
        ? (node.children.map((child) =>
            attachDerivedFields(child, allowedTypes, sharedBlockKey),
          ) as ColumnNode[])
        : null
    return {
      ...node,
      nodeKey,
      parentKey,
      ...(sharedBlockKey !== undefined ? { sharedBlockKey } : {}),
      containerType: 'row',
      allowedTypes: allowedTypes.row,
      children,
    } satisfies RowNode
  }

  if (node.containerType === 'column') {
    // Same cast rationale as section: the server's hierarchy invariant (not valibot)
    // guarantees a column's children are simple (leaf) elements.
    const children =
      node.children !== null
        ? (node.children.map((child) =>
            attachDerivedFields(child, allowedTypes, sharedBlockKey),
          ) as SimpleElementNode[])
        : null
    return {
      ...node,
      nodeKey,
      parentKey,
      ...(sharedBlockKey !== undefined ? { sharedBlockKey } : {}),
      containerType: 'column',
      allowedTypes: allowedTypes.column,
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
    ...(sharedBlockKey !== undefined ? { sharedBlockKey } : {}),
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

/* ------------------------------------------------------------------ *
 * Shared blocks — served by SharedBlockController, a separate admin
 * controller with its own base URL. Placements are ordinary elements and
 * stay on the grid endpoints above (archiveElement, reorderElement).
 * ------------------------------------------------------------------ */

/**
 * Blocks the author may place under `parentType`. The server filters by the
 * block's root type, so a row-rooted block is never offered at page level.
 */
export async function fetchSharedBlocks(
  parentType: SharedBlockParentType,
): Promise<SharedBlockListEntry[]> {
  const base = getSharedBlockControllerLink()
  const raw = await apiGet<unknown>(`${base}/api/list?parentType=${encodeURIComponent(parentType)}`)
  return v.parse(sharedBlockListSchema, raw)
}

/** The library editor's tree, rooted at a block instead of a page + zone. */
export async function fetchSharedBlockTree(blockId: number): Promise<TreeApiResponse> {
  const base = getSharedBlockControllerLink()
  const raw = await apiGet<unknown>(`${base}/api/readTree/${blockId}`)
  return normaliseTreeResponse(raw)
}

export interface PlaceSharedBlockParams {
  blockId: number
  parent: NodeRef
  zone?: string
  insertAfterElementID?: number
}

export async function placeSharedBlock(params: PlaceSharedBlockParams): Promise<void> {
  const base = getSharedBlockControllerLink()
  await apiPost(`${base}/api/place`, params)
}

export async function convertToSharedBlock(params: {
  element: NodeRef
  title?: string
}): Promise<{ blockId: number }> {
  const base = getSharedBlockControllerLink()
  return await apiPostJson<{ blockId: number }>(`${base}/api/convert`, params)
}

export async function detachSharedBlock(params: { element: NodeRef }): Promise<void> {
  const base = getSharedBlockControllerLink()
  await apiPost(`${base}/api/detach`, params)
}

export async function setSharedBlockPublished(params: {
  blockId: number
  published: boolean
}): Promise<void> {
  const base = getSharedBlockControllerLink()
  await apiPatch(`${base}/api/setPublished`, params)
}
