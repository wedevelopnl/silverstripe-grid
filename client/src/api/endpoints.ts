import { z } from 'zod/v4-mini';
import {
  type ContainerType,
  type ElementTreeResponse,
  elementTreeResponseSchema,
} from '@/types/elements';
import { apiDelete, apiGet, apiPatch, apiPost } from './client';
import { getControllerLink } from './config';

/**
 * Fetch the full element tree for a CMS page.
 * Response is validated through the Zod schema.
 */
export async function fetchElementTree(
  pageId: number,
  zone: string,
): Promise<ElementTreeResponse> {
  const base = getControllerLink();
  const data = await apiGet<unknown>(`${base}/api/readTree/${pageId}/${encodeURIComponent(zone)}`);

  return z.parse(elementTreeResponseSchema, data);
}

export interface CreateElementParams {
  containerType: ContainerType;
  parentId: number;
  insertAfterElementID?: number;
  zone?: string;
}

export async function createElement(
  params: CreateElementParams,
): Promise<void> {
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
  elementID: number;
  targetParentId: number;
  afterElementID: number | null;
}

export async function reorderElement(
  params: ReorderElementParams,
): Promise<void> {
  const base = getControllerLink();
  await apiPatch(`${base}/api/reorder`, params);
}

export interface CreateContentElementParams {
  className: string;
  parentId: number;
  insertAfterElementID?: number;
}

export async function createContentElement(
  params: CreateContentElementParams,
): Promise<void> {
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

export async function updateGridSettings(
  params: UpdateGridSettingsParams,
): Promise<void> {
  const base = getControllerLink();
  await apiPatch(`${base}/api/updateGridSettings`, params);
}

// --- Duplicate To ---

export interface DuplicateToParams {
  id: number;
  targetPageId: number;
  targetZone: string;
  targetParentId: number;
}

export async function duplicateToElement(
  params: DuplicateToParams,
): Promise<void> {
  const base = getControllerLink();
  await apiPost(`${base}/api/duplicateTo`, params);
}

// --- Acceptable Containers ---

export interface AcceptableContainer {
  id: number;
  title: string;
  type: string;
}

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

export interface PageEntry {
  id: number;
  title: string;
  parentId: number;
  hasGridZones: boolean;
}

export async function fetchPages(search?: string): Promise<PageEntry[]> {
  const base = getControllerLink();
  const params = search ? `?search=${encodeURIComponent(search)}` : '';
  return apiGet<PageEntry[]>(`${base}/api/pages${params}`);
}
