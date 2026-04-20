import type { ContainerType, ElementNode, TreeApiResponse } from '@/types/elements';
import { isContainerNode } from '@/types/elements';
import type { AcceptableContainer, PageEntry } from '@/types/duplicateTo';
import { NodeIdentity, type NodeRef } from '@/types/identity';
import { apiDelete, apiGet, apiPatch, apiPost } from './client';
import { getControllerLink } from './config';

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
  const base = getControllerLink();
  const encodedZone = encodeURIComponent(zone);
  const url =
    version !== undefined
      ? `${base}/api/readTree/${pageId}/${encodedZone}/version/${version}`
      : `${base}/api/readTree/${pageId}/${encodedZone}`;
  const raw = await apiGet<unknown>(url);
  return normaliseTreeResponse(raw);
}

/**
 * Validate the tree response shape from the server and attach derived
 * `nodeKey`/`parentKey` fields to every node in the tree.
 *
 * The server emits `self` and `parent` as `{type, id}` tuples — the frontend
 * caches the composite string keys alongside so downstream lookups never have
 * to reconstruct them per render.
 */
export function normaliseTreeResponse(raw: unknown): TreeApiResponse {
  if (typeof raw !== 'object' || raw === null) {
    throw new TypeError('tree response: expected an object');
  }
  const data = raw as Record<string, unknown>;
  const rootParent = NodeIdentity.assert(data.rootParent, 'tree response.rootParent');
  if (!Array.isArray(data.nodes)) {
    throw new TypeError('tree response: expected `nodes` to be an array');
  }
  const overrideCounts: Record<string, number> = {};
  const rawCounts = data.overrideCounts;
  if (typeof rawCounts === 'object' && rawCounts !== null) {
    for (const [key, value] of Object.entries(rawCounts)) {
      if (typeof value === 'number') overrideCounts[key] = value;
    }
  }

  const nodes = data.nodes.map((node: unknown) => normaliseNode(node));

  return { rootParent, nodes, overrideCounts };
}

/**
 * Assert that a validated raw node object is a well-formed ElementNode.
 *
 * This assertion is safe after normaliseNode has validated `self` and
 * `parent` via NodeIdentity.assert and attached computed keys. The remaining
 * fields (title, blockSchema, containerType, etc.) are trusted from the
 * server.
 */
function assertElementNode(value: Record<string, unknown>): ElementNode {
  return value as unknown as ElementNode;
}

function normaliseNode(raw: unknown): ElementNode {
  if (typeof raw !== 'object' || raw === null) {
    throw new TypeError('tree node: expected an object');
  }
  const node = raw as Record<string, unknown> & { children?: unknown };
  const self = NodeIdentity.assert(node.self, 'tree node.self');
  const parent = NodeIdentity.assert(node.parent, 'tree node.parent');

  const normalised = assertElementNode({
    ...node,
    self,
    parent,
    nodeKey: NodeIdentity.toKey(self.type, self.id),
    parentKey: NodeIdentity.toKey(parent.type, parent.id),
    id: self.id,
  });

  if (isContainerNode(normalised) && Array.isArray(node.children)) {
    // Reassign children with the normalised variants. Cast is safe: the type
    // guard above confirms `normalised` is a container node.
    (normalised as { children: ElementNode[] | null }).children = node.children.map((child) =>
      normaliseNode(child),
    );
  }

  return normalised;
}

export interface CreateElementParams {
  containerType: ContainerType;
  parent: NodeRef;
  insertAfterElementID?: number;
  zone?: string;
}

export async function createElement(params: CreateElementParams): Promise<void> {
  const base = getControllerLink();
  await apiPost(`${base}/api/create`, params);
}

export async function publishElement(id: number): Promise<void> {
  const base = getControllerLink();
  await apiPatch(`${base}/api/publish`, { id });
}

export async function unpublishElement(id: number): Promise<void> {
  const base = getControllerLink();
  await apiPatch(`${base}/api/unpublish`, { id });
}

export async function archiveElement(id: number): Promise<void> {
  const base = getControllerLink();
  await apiDelete(`${base}/api/delete`, { id });
}

export async function duplicateElement(id: number): Promise<void> {
  const base = getControllerLink();
  await apiPost(`${base}/api/duplicate`, { id });
}

export interface ReorderElementParams {
  element: NodeRef;
  parent: NodeRef;
  after: NodeRef | null;
}

export async function reorderElement(params: ReorderElementParams): Promise<void> {
  const base = getControllerLink();
  await apiPatch(`${base}/api/reorder`, params);
}

export interface CreateContentElementParams {
  className: string;
  parentId: number;
  insertAfterElementID?: number;
}

export async function createContentElement(params: CreateContentElementParams): Promise<void> {
  const base = getControllerLink();
  await apiPost(`${base}/api/createContent`, params);
}

export interface UpdateGridSettingsParams {
  id: number;
  viewport: string;
  width: number;
  offset: number;
  visible: boolean;
}

export async function updateGridSettings(params: UpdateGridSettingsParams): Promise<void> {
  const base = getControllerLink();
  await apiPatch(`${base}/api/updateGridSettings`, params);
}

// --- Reset Grid Settings Overrides ---

export interface ResetGridSettingsOverridesParams {
  pageId: number;
  zone: string;
  viewport?: string;
}

export async function resetGridSettingsOverrides(
  params: ResetGridSettingsOverridesParams,
): Promise<void> {
  const base = getControllerLink();
  await apiDelete(`${base}/api/resetGridSettingsOverrides`, {
    pageId: params.pageId,
    zone: params.zone,
    viewport: params.viewport,
  });
}

// --- Duplicate To ---

export interface DuplicateToParams {
  id: number;
  targetPageId: number;
  targetZone: string;
  targetParent: NodeRef;
}

export async function duplicateToElement(params: DuplicateToParams): Promise<void> {
  const base = getControllerLink();
  await apiPost(`${base}/api/duplicateTo`, params);
}

// --- Acceptable Containers ---

export type { AcceptableContainer } from '@/types/duplicateTo';

export async function fetchAcceptableContainers(
  pageId: number,
  zone: string,
  elementType: string,
): Promise<AcceptableContainer[]> {
  const base = getControllerLink();
  return apiGet<AcceptableContainer[]>(
    `${base}/api/acceptableContainers/${pageId}/${encodeURIComponent(zone)}/${encodeURIComponent(elementType)}`,
  );
}

// --- Zones ---

export async function fetchZones(pageId: number): Promise<string[]> {
  const base = getControllerLink();
  return apiGet<string[]>(`${base}/api/zones/${pageId}`);
}

// --- Pages ---

export type { PageEntry } from '@/types/duplicateTo';

export async function fetchPages(search?: string): Promise<PageEntry[]> {
  const base = getControllerLink();
  const params = search ? `?search=${encodeURIComponent(search)}` : '';
  return apiGet<PageEntry[]>(`${base}/api/pages${params}`);
}
