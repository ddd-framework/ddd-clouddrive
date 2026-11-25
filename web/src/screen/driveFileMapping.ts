// The drive's list payloads (mgamadeus/ddd-clouddrive ≥ 0.1.0-rc.10) as the view models of `./files`. Pure: no React,
// no request; the adapter hook (`useCloudDriveFileAdapter`) and the tests use them.
import { m, translateText } from '@mgamadeus/ddd-admin-ui/i18n';
import type { FileListOrder, FileListQuery, FileLocationMiss, FileLocationResolution } from '@mgamadeus/ddd-admin-ui/files';
import {
  type FileAreaRefView,
  type FileAreaUsagePart,
  type FileAreaView,
  type FileEventView,
  type FileFamily,
  type FileScanStatus,
  type FileVersionSystemNote,
  type FileVersionView,
  type FileView,
  type FolderView,
  fileFamilyOfContentType,
  fileFamilyOfExtension,
} from '@mgamadeus/ddd-admin-ui/files';
import type {
  DriveChangeEventListItem,
  DriveChildrenArg,
  DriveFileFamily,
  DriveFileVersionListItem,
  DriveLocationNotFoundBody,
  DriveLocationResolveResponse,
  DriveNodeBulkResult,
  DriveNodeListItem,
  DriveScanStatus,
  DriveSpaceListItem,
} from '../types.ts';

/** The page size of a growing list; each "more" asks for one page more, up to `DRIVE_LIST_MAX_LIMIT`. */
export const DRIVE_LIST_PAGE_SIZE = 100;

/** The largest `limit` the drive answers in one call. */
export const DRIVE_LIST_MAX_LIMIT = 1000;

/** The most calls one "empty trash" makes (each removes one batch of the server). */
export const DRIVE_EMPTY_TRASH_MAX_CALLS = 200;

const DRIVE_FAMILIES: readonly DriveFileFamily[] = ['PDF', 'SPREADSHEET', 'DOCUMENT', 'PRESENTATION', 'TEXT', 'IMAGE', 'VECTOR', 'VIDEO', 'ARCHIVE'];

/** The labels of the drive's own event types the file manager does not know. */
export const DRIVE_EVENT_LABELS: Readonly<Record<string, string>> = {
  READ_BY_AGENT: m('Read by the agent'),
  VIEWED: m('Viewed', { context: 'file-event' }),
  DOWNLOADED: m('Downloaded', { context: 'file-event' }),
};

/** The scan state the file manager shows: `PURGING` (the content is being removed) reads as not available. */
export const driveScanStatusOf = (scanStatus: DriveScanStatus | null | undefined): FileScanStatus => {
  switch (scanStatus) {
    case 'CLEAN':
    case 'PENDING':
    case 'UPLOADING':
    case 'INFECTED':
    case 'BLOCKED':
    case 'FAILED':
      return scanStatus;
    case 'PURGING':
      return 'FAILED';
    default:
      return 'PENDING';
  }
};

/** The family of a drive item: the server's family, else from the extension, else from the content type. */
export const driveFamilyOf = (item: Pick<DriveNodeListItem, 'family' | 'extension' | 'contentType' | 'name'>): FileFamily => {
  if (item.family && (DRIVE_FAMILIES as readonly string[]).includes(item.family)) return item.family;
  const extension = (item.extension ?? '') || extensionOfName(item.name);
  const byExtension = fileFamilyOfExtension(extension);
  if (byExtension !== 'OTHER') return byExtension;
  return fileFamilyOfContentType(item.contentType) ?? 'OTHER';
};

const extensionOfName = (name: string): string => {
  const dot = name.lastIndexOf('.');
  return dot > 0 && dot < name.length - 1 ? name.slice(dot + 1).toLowerCase() : '';
};

/** The folder a node sits in as the file manager names it: the space's root is `null`. */
export const driveFolderIdOf = (parentNodeId: number | null | undefined, rootNodeId: number | null | undefined): number | null =>
  parentNodeId === null || parentNodeId === undefined || parentNodeId === rootNodeId ? null : parentNodeId;

/** A file of the drive as a `FileView` (trash facts included when present; a trashed folder is marked `isFolder`). */
export const driveFileViewOf = (item: DriveNodeListItem, rootNodeId: number | null | undefined): FileView => ({
  id: item.id,
  areaKey: item.driveSpaceId,
  folderId: driveFolderIdOf(item.parentNodeId, rootNodeId),
  name: item.name,
  extension: (item.extension ?? '') || extensionOfName(item.name),
  family: driveFamilyOf(item),
  mimeType: item.contentType ?? null,
  sizeBytes: item.sizeBytes ?? 0,
  contentVersion: item.versionNumber ?? null,
  description: item.description ?? null,
  scanStatus: item.scanStatus ? driveScanStatusOf(item.scanStatus) : 'UPLOADING',
  hasPendingVersion: item.hasPendingVersion === true,
  newestVersionStatus: item.latestVersionScanStatus ? driveScanStatusOf(item.latestVersionScanStatus) : null,
  newestVersionNumber: item.latestVersionNumber ?? null,
  newestVersionReason: item.latestVersionStateReason ?? null,
  hasWorkingCopyChanges: item.hasWorkingCopyChanges === true,
  thumbnailUrl: item.thumbnailUrl ?? null,
  ...(item.hasPdfRendition === true ? { hasPdfRendition: true } : {}),
  ...(item.hasSanitizedImage === true ? { hasSanitizedImage: true } : {}),
  uploadedBy: item.createdByName ?? null,
  uploadedOnBehalfOf: item.createdOnBehalfOfName ?? null,
  createdAt: item.createdAt ?? null,
  updatedAt: item.updatedAt ?? null,
  deletedAt: item.trashedAt ?? null,
  deletedBy: item.trashedByName ?? null,
  purgeAfter: item.purgeAfter ?? null,
  ...(item.nodeType === 'FOLDER' ? { isFolder: true } : {}),
});

/** The folders of a space as the tree reads them: the root itself is left out, its children hang at `null`. */
export const driveFolderViewsOf = (items: readonly DriveNodeListItem[], rootNodeId: number | null | undefined): FolderView[] =>
  items
    .filter(item => item.nodeType === 'FOLDER' && item.parentNodeId !== null && item.parentNodeId !== undefined && item.id !== rootNodeId)
    .map(item => ({
      id: item.id,
      areaKey: item.driveSpaceId,
      parentId: driveFolderIdOf(item.parentNodeId, rootNodeId),
      name: item.name,
      entryCount: item.childCount ?? null,
      createdBy: item.createdByName ?? null,
      createdOnBehalfOf: item.createdOnBehalfOfName ?? null,
      createdAt: item.createdAt ?? null,
      updatedAt: item.updatedAt ?? null,
    }));

/** The drive's note codes as the file manager names them (a move between spaces is a move from another area). */
const DRIVE_VERSION_NOTE_CODES: Readonly<Record<string, string>> = {
  RESTORED_FROM_VERSION: 'RESTORED_FROM_VERSION',
  TRANSFERRED_FROM_SPACE: 'MOVED_FROM_AREA',
  COPIED_FROM_PATH: 'COPIED_FROM_PATH',
};

/** A version's system note; null for free text or a code this package does not know (then `comment` is drawn). */
export const driveVersionNoteOf = (item: DriveFileVersionListItem): FileVersionSystemNote | null => {
  const code = item.commentCode ? DRIVE_VERSION_NOTE_CODES[item.commentCode] : undefined;
  if (!code) return null;
  return {
    code,
    versionNumber: item.commentParameters?.versionNumber ?? null,
    sourceAreaName: item.commentParameters?.sourceSpaceDisplayName ?? null,
    sourcePath: item.commentParameters?.sourcePath ?? null,
  };
};

/** A version of a file as the drawer lists it. */
export const driveVersionViewOf = (item: DriveFileVersionListItem): FileVersionView => ({
  id: item.id,
  versionNumber: item.versionNumber,
  sizeBytes: item.sizeBytes ?? 0,
  scanStatus: driveScanStatusOf(item.scanStatus),
  scanResultDetail: item.scanDetail ?? null,
  isCurrent: item.isCurrent,
  uploadedBy: item.authorName ?? null,
  uploadedOnBehalfOf: item.onBehalfOfName ?? null,
  createdAt: item.createdAt ?? null,
  comment: item.comment ?? null,
  systemNote: driveVersionNoteOf(item),
});

/** A change of a node as the drawer's activity lists it; a move or rename shows `old path → new path`. */
export const driveEventViewOf = (item: DriveChangeEventListItem): FileEventView => ({
  id: item.eventSource ? `${item.eventSource}-${item.id}` : item.id,
  type: item.eventType,
  occurredAt: item.occurredAt ?? null,
  actor: item.actorName ?? null,
  impersonatedBy: item.onBehalfOfName ?? null,
  detail: item.previousPath && item.path && item.previousPath !== item.path ? `${item.previousPath} → ${item.path}` : (item.detail ?? null),
  audience: item.audience ?? null,
  variant: item.contentVariant ?? null,
  clientIp: item.clientIp ?? null,
});

/**
 * Where a resolve answer leads, for the file manager: the space, the folder to open (a file's folder; `null` = the
 * root), the file whose details open, and the path with a leading slash.
 */
export const driveLocationResolutionOf = (response: DriveLocationResolveResponse): FileLocationResolution => {
  const item = response.item ?? null;
  const rootNodeId = response.ancestors?.elements?.[0]?.id ?? (item && (item.parentNodeId === null || item.parentNodeId === undefined) ? item.id : null);
  const isFile = item !== null && item.nodeType === 'FILE';
  const folderId = item === null ? null : isFile ? driveFolderIdOf(item.parentNodeId, rootNodeId) : item.id === rootNodeId ? null : item.id;
  return {
    found: true,
    areaKey: response.driveSpaceId,
    folderId,
    fileId: isFile && item ? item.id : null,
    path: `/${response.path.replace(/^\/+/, '')}`,
  };
};

/** The miss of a resolve request (its 404 body), for the path bar's message. */
export const driveLocationMissOf = (error: unknown): FileLocationMiss | null => {
  const failure = error as { status?: unknown; data?: DriveLocationNotFoundBody } | null;
  if (!failure || failure.status !== 404) return null;
  const body = failure.data ?? {};
  const deepest = body.deepestFolder ?? null;
  return {
    found: false,
    deepestFolderPath: deepest ? (deepest.parentNodeId === null || deepest.parentNodeId === undefined ? '/' : deepest.name) : null,
    missingSegment: body.missingSegment ?? null,
    message: body.error ?? null,
  };
};

/** The labels of the usage split of a space (the quota pill's tooltip). */
export const DRIVE_USAGE_PART_LABELS = {
  currentVersionBytes: m('Current versions'),
  olderVersionBytes: m('Older versions'),
  trashedBytes: m('Trash', { context: 'drive-usage' }),
  pendingBytes: m('Uploads being checked'),
} as const;

/** The usage split of a space when the server sends it (ddd-clouddrive ≥ rc.12), translated; `null` otherwise. */
export const driveUsagePartsOf = (quota: DriveSpaceListItem['quota']): FileAreaUsagePart[] | null => {
  const parts: FileAreaUsagePart[] = [];
  for (const key of Object.keys(DRIVE_USAGE_PART_LABELS) as (keyof typeof DRIVE_USAGE_PART_LABELS)[]) {
    const bytes = quota[key];
    if (typeof bytes === 'number') parts.push({ label: translateText(DRIVE_USAGE_PART_LABELS[key], key === 'trashedBytes' ? { context: 'drive-usage' } : {}), bytes });
  }
  return parts.length > 0 ? parts : null;
};

/** The quota that applies (`effectiveQuotaBytes`, else `quotaBytes`); `null` = no limit. */
export const effectiveQuotaOf = (quota: DriveSpaceListItem['quota']): number | null => {
  const quotaBytes = quota.effectiveQuotaBytes ?? quota.quotaBytes;
  return typeof quotaBytes === 'number' && quotaBytes > 0 ? quotaBytes : null;
};

/** A space as the file manager's area facts. */
export const driveAreaViewOf = (item: DriveSpaceListItem): FileAreaView => ({
  areaKey: item.id,
  isEnabled: true,
  quotaBytes: effectiveQuotaOf(item.quota),
  usedBytes: item.quota.usedBytes,
  maxFileSizeBytes: item.quota.maxFileSizeBytes > 0 ? item.quota.maxFileSizeBytes : null,
  trashRetentionDays: item.quota.trashRetentionDays > 0 ? item.quota.trashRetentionDays : null,
  fileCount: item.quota.fileCount,
  folderCount: item.quota.folderCount,
  usageParts: driveUsagePartsOf(item.quota),
});

/** A space as an entry of the area picker and the manager's `areas`. */
export const driveAreaRefOf = (item: DriveSpaceListItem): FileAreaRefView => ({
  key: item.id,
  name: item.displayName || item.title || `#${item.id}`,
  // `groupLabel` is the translated heading; `displayGroup` is an ordering code (`LIBRARY`), shown only without a label.
  groupLabel: item.groupLabel ?? item.displayGroup ?? null,
});

const DRIVE_ORDERS: readonly FileListOrder[] = ['name', 'created', 'size'];

/**
 * The `children` request of a file manager query. A folder (or `'root'`) lists that folder; no folder, a name search
 * or a filter lists the whole space (`recursive`).
 */
export const driveChildrenArgOf = (query: FileListQuery, rootNodeId: number | null | undefined, limit: number): DriveChildrenArg => {
  const q = query.q?.trim() ?? '';
  const isFolderView = query.folderId !== undefined && q === '';
  const folderNodeId = !isFolderView ? null : query.folderId === 'root' ? (rootNodeId ?? null) : Number(query.folderId);
  return {
    driveSpaceId: Number(query.areaKey),
    folderNodeId: folderNodeId ?? undefined,
    recursive: isFolderView ? undefined : true,
    query: q === '' ? undefined : q,
    family: query.family && (DRIVE_FAMILIES as readonly string[]).includes(query.family) ? (query.family as DriveFileFamily) : undefined,
    createdFrom: query.createdFrom || undefined,
    createdTo: query.createdTo || undefined,
    createdByMe: query.uploadedByMe ? true : undefined,
    orderBy: query.orderBy && DRIVE_ORDERS.includes(query.orderBy) ? query.orderBy : undefined,
    orderDirection: query.orderDirection,
    limit,
    includeSystem: false,
  };
};

/** The files of a list answer (folders, links and system nodes are left out). */
export const driveFileItemsOf = (items: readonly DriveNodeListItem[] | undefined): DriveNodeListItem[] =>
  (items ?? []).filter(item => item.nodeType === 'FILE' && item.isSystem !== true);

/** The next `limit` of a growing list, or `null` when the drive answers no more in one call. */
export const nextDriveListLimit = (limit: number): number | null => (limit >= DRIVE_LIST_MAX_LIMIT ? null : Math.min(DRIVE_LIST_MAX_LIMIT, limit + DRIVE_LIST_PAGE_SIZE));

/** What an "empty trash" removed over all its calls. */
export interface DriveEmptyTrashOutcome {
  calls: number;
  processedCount: number;
  fileCount: number;
  folderCount: number;
  /** The call limit was reached with more left (rare; the user empties again). */
  hasMore: boolean;
}

/** Empties a trash batch by batch: calls `emptyOnce` while the server answers `hasMore`, at most `maxCalls` times. */
export const emptyDriveTrashFully = async (
  emptyOnce: () => Promise<DriveNodeBulkResult>,
  maxCalls: number = DRIVE_EMPTY_TRASH_MAX_CALLS
): Promise<DriveEmptyTrashOutcome> => {
  const outcome: DriveEmptyTrashOutcome = { calls: 0, processedCount: 0, fileCount: 0, folderCount: 0, hasMore: false };
  for (;;) {
    const result = await emptyOnce();
    outcome.calls += 1;
    outcome.processedCount += result.processedCount;
    outcome.fileCount += result.fileCount;
    outcome.folderCount += result.folderCount;
    outcome.hasMore = result.hasMore;
    if (!result.hasMore || result.processedCount === 0 || outcome.calls >= maxCalls) return outcome;
  }
};

/** One group of the space picker. */
export interface DriveSpaceGroup {
  /** `''` = spaces without a group. */
  label: string;
  areas: FileAreaRefView[];
}

/** Groups the spaces by `groupLabel` in the server's order (it sorts by group, then name): nothing is re-sorted. */
export const groupDriveSpaces = (areas: readonly FileAreaRefView[]): DriveSpaceGroup[] => {
  const groups = new Map<string, FileAreaRefView[]>();
  for (const area of areas) {
    const label = area.groupLabel ?? '';
    const group = groups.get(label);
    if (group) group.push(area);
    else groups.set(label, [area]);
  }
  return [...groups.entries()].map(([label, groupAreas]) => ({ label, areas: groupAreas }));
};
