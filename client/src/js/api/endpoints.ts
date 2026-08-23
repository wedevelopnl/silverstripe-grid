import type { AcceptableContainer, PageEntry } from '@/types/duplicateTo'
import type { EditorRoot } from '@/types/editorRoot'
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
 * Fetch the element tree the editor renders, whichever record it is rooted at.
 *
 * A page zone reads `GridController`'s `/api/readTree/{pageId}/{zone}`, or its
 * `/version/{version}` variant for the readonly history viewer. A block reads
 * `SharedBlockController`'s `/api/readTree/{blockId}` — a different controller
 * under its own admin URL. Both answer the same tree shape, so everything
 * downstream of {@link normaliseTreeResponse} is host-agnostic.
 */
export async function fetchTree(root: EditorRoot): Promise<TreeApiResponse> {
  const raw = await apiGet<unknown>(treeUrl(root))
  return normaliseTreeResponse(raw)
}

function treeUrl(root: EditorRoot): string {
  if (root.kind === 'sharedBlock') {
    return `${getSharedBlockControllerLink()}/api/readTree/${root.blockId}`
  }

  const url = `${getControllerLink()}/api/readTree/${root.pageId}/${encodeURIComponent(root.zone)}`
  return root.version !== undefined ? `${url}/version/${root.version}` : url
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

  // Built once and spread into every arm. Assembled per-arm, a new derived
  // field only has to be missed in one of the five for that node shape to lose
  // it silently: they are all optional on ElementNode, so nothing would fail to
  // compile — and a node missing `sharedBlockKey` reads as page-local to drag
  // collision filtering.
  const derived = {
    nodeKey,
    parentKey,
    ...(sharedBlockKey !== undefined ? { sharedBlockKey } : {}),
  }

  // The PHP domain layer enforces the Section→Row→Column→leaf hierarchy. The
  // wire schema's childrenSchema is shared across all container variants and
  // does NOT distinguish children by type, so each arm's cast below rests on
  // the server's hierarchy invariant rather than on valibot — TypeScript cannot
  // express the narrower per-level invariant while this returns the wide
  // ElementNode union.
  const mapChildren = (children: NodeWire[] | null): ElementNode[] | null =>
    children?.map((child) => attachDerivedFields(child, allowedTypes, sharedBlockKey)) ?? null

  if (node.sharedBlock !== undefined) {
    // The placement itself is page-local — it is the boundary, not inside it —
    // so its own sharedBlockKey stays unset while everything below inherits it.
    const { containerType: _ct, ...referenceFields } = node
    return {
      ...referenceFields,
      ...derived,
      children: node.children.map((child) => attachDerivedFields(child, allowedTypes, nodeKey)) as
        | [ElementNode]
        | [],
    } satisfies SharedBlockReferenceNode
  }

  if (node.containerType === 'section') {
    return {
      ...node,
      ...derived,
      // Restated because section and row share ONE wire variant, whose
      // `containerType` is the wider `'section' | 'row'`: narrowing the
      // property does not narrow what the spread carries.
      containerType: 'section',
      allowedTypes: allowedTypes.section,
      children: mapChildren(node.children) as RowNode[] | null,
    } satisfies SectionNode
  }

  if (node.containerType === 'row') {
    return {
      ...node,
      ...derived,
      // Restated for the same reason as section, above.
      containerType: 'row',
      allowedTypes: allowedTypes.row,
      children: mapChildren(node.children) as ColumnNode[] | null,
    } satisfies RowNode
  }

  if (node.containerType === 'column') {
    return {
      ...node,
      ...derived,
      allowedTypes: allowedTypes.column,
      children: mapChildren(node.children) as SimpleElementNode[] | null,
    } satisfies ColumnNode
  }

  // Leaf element — containerType is absent on the wire shape. Destructure it
  // out so the spread does not carry the union's containerType variants into
  // the returned object, which would conflict with SimpleElementNode's
  // `containerType?: never` declaration.
  const { containerType: _ct, ...leafFields } = node
  return { ...leafFields, ...derived } satisfies SimpleElementNode
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
 * stay on the grid endpoints above (archiveElement, reorderElement); the
 * block-rooted tree is one of fetchTree's two hosts, not an endpoint of
 * its own.
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

export interface PlaceSharedBlockParams {
  blockId: number
  parent: NodeRef
  zone?: string
  insertAfterElementID?: number
  insertAtStart?: boolean
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
