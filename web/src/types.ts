// Payloads of the cloud drive endpoints (`CloudDriveControllerTrait` of mgamadeus/ddd-clouddrive).

export type DriveNodeType = 'FOLDER' | 'FILE' | 'LINK';
export type DriveSpaceAudience = 'INTERNAL' | 'SHARED';
/**
 * What a content URL is for (ddd-clouddrive ≥ rc.11 records `INLINE` as "viewed" and `DOWNLOAD` as "downloaded";
 * `THUMBNAIL` is never recorded).
 */
export type DriveContentPurpose = 'DOWNLOAD' | 'INLINE' | 'THUMBNAIL';

/** The state of a PDF the server renders of a document (ddd-clouddrive ≥ 0.3.5). */
export type DriveRenditionState = 'READY' | 'PENDING' | 'FAILED';

/** Who the drive answers as: staff (`internal`, default) or a partner audience (`shared`, ddd-clouddrive ≥ rc.11). */
export type CloudDriveAudience = 'internal' | 'shared';

export interface DriveSpace {
  id: number;
  title?: string | null;
  spaceKind?: string | null;
  audience?: DriveSpaceAudience | null;
  rootNodeId?: number | null;
  usedBytes?: number | null;
  quotaBytes?: number | null;
  fileCount?: number | null;
  folderCount?: number | null;
}

export interface DriveNode {
  id: number;
  driveSpaceId: number;
  parentNodeId?: number | null;
  nodeType: DriveNodeType;
  name: string;
  sizeBytes?: number | null;
  contentType?: string | null;
  sha256?: string | null;
  versionNumber?: number | null;
  currentVersionId?: number | null;
  latestVersionId?: number | null;
  tags?: string | null;
  description?: string | null;
  isSystem?: boolean | null;
  isContentUntrusted?: boolean | null;
  childCount?: number | null;
  trashedAt?: string | null;
  changeHistory?: { createdTime?: string | null; modifiedTime?: string | null } | null;
}

export interface DriveFileVersion {
  id: number;
  versionNumber: number;
  sizeBytes?: number | null;
  comment?: string | null;
  origin?: string | null;
  changeHistory?: { createdTime?: string | null } | null;
}

/**
 * A reserved upload: the bytes go to the backend — in one request (`PUT …/uploads/{driveBlobId}/content`) or in
 * parts of `partSizeBytes` (`PUT …/uploads/{driveBlobId}/parts/{partNumber}`); a resumed upload skips
 * `uploadedPartNumbers`. No URL of the object store ever reaches the browser.
 */
export interface DriveUploadReservation {
  driveBlobId: number;
  sizeBytes: number;
  isMultipart?: boolean;
  partSizeBytes?: number;
  partCount?: number;
  /** Parts the store holds already (a resumed upload sends only the others). */
  uploadedPartNumbers?: number[];
  isContentKnown?: boolean;
}

/** The bytes of an upload (or of one part) arrived. */
export interface DriveUploadBytesResponse {
  driveBlobId: number;
  partNumber?: number | null;
  sizeBytes: number;
}

export interface DriveUploadContentArg {
  driveBlobId: number;
  /** The bytes, typed `application/octet-stream` (the backend streams such a body without reading it into memory). */
  body: Blob;
}

export interface DriveUploadPartArg {
  driveBlobId: number;
  partNumber: number;
  body: Blob;
}

export interface DriveUploadStateArg {
  driveBlobId: number;
}

export interface DriveContentGrant {
  purpose: string;
  url: string;
  expiresAt?: string | null;
  fileName: string;
  sizeBytes?: number | null;
  contentType?: string | null;
}

export interface DriveFileCard {
  reference: string;
  driveSpaceId: number;
  name: string;
  path: string;
  isFolder: boolean;
  sizeBytes?: number | null;
  contentType?: string | null;
  modifiedAt?: string | null;
  versionNumber?: number | null;
  childCount?: number | null;
  state?: string | null;
  inlineUrl?: string | null;
  downloadUrl?: string | null;
  thumbnailUrl?: string | null;
  urlsExpireAt?: string | null;
  /** The file's place in the host's drive screen (`CloudDrive.settings.adminLinkTemplate`; ddd-clouddrive ≥ 0.1.0-rc.14). */
  adminLink?: string | null;
  /** The server renders this document as a PDF for the preview (ddd-clouddrive ≥ 0.3.5). */
  hasPdfRendition?: boolean;
}

export interface DriveSpacesResponse {
  driveSpaces: { elements?: DriveSpace[] };
  /** The spaces with display name, group, quota and the viewer's rights (ddd-clouddrive ≥ 0.1.0-rc.10). */
  items?: { elements?: DriveSpaceListItem[] } | null;
}

export interface DriveNodesResponse {
  driveNodes: { elements?: DriveNode[] };
  /** The same nodes as list items (ddd-clouddrive ≥ 0.1.0-rc.10). */
  items?: { elements?: DriveNodeListItem[] } | null;
  nextCursor?: string | null;
}

export interface DriveNodeResponse {
  driveNode: DriveNode;
  path: string;
  /** The folders above the node, the root first and its own folder last (mgamadeus/ddd-clouddrive ≥ 0.1.0-rc.3). */
  ancestors?: { elements?: DriveNode[] } | null;
  driveFileVersions?: { elements?: DriveFileVersion[] } | null;
  /** The node as a list item (ddd-clouddrive ≥ 0.1.0-rc.10). */
  item?: DriveNodeListItem | null;
  isConflict?: boolean | null;
  conflictReason?: string | null;
}

export interface DriveUploadReservationResponse {
  reservation: DriveUploadReservation;
}

export interface DriveContentGrantResponse {
  grant: DriveContentGrant;
}

/** `GET /nodes/{id}/rendition`: the PDF rendition of a document — its content URL once it is `READY`. */
export interface DriveRenditionResponse {
  state: DriveRenditionState;
  /** Why none could be made (`FAILED`), in plain words. */
  reason?: string | null;
  grant?: DriveContentGrant | null;
}

export interface DriveRenditionArg {
  driveNodeId: number;
  versionId?: number | null;
}

export interface DriveFileCardsResponse {
  cards: { elements?: DriveFileCard[] };
}

export interface DriveChildrenArg extends DriveListFilters {
  driveSpaceId: number;
  folderNodeId?: number | null;
  cursor?: string | null;
  limit?: number;
  includeSystem?: boolean;
}

export interface DriveSearchArg extends Omit<DriveListFilters, 'query' | 'recursive'> {
  driveSpaceId: number;
  query: string;
  cursor?: string | null;
  limit?: number;
}

export interface DriveNodeIdArg {
  driveNodeId: number;
}

export interface DriveFolderCreateArg {
  driveSpaceId: number;
  parentNodeId?: number | null;
  name: string;
}

export interface DriveUploadReserveArg {
  driveSpaceId: number;
  sizeBytes: number;
  contentType?: string | null;
  contentMd5?: string | null;
  sha256?: string | null;
  /** The file's name: the space's type policy checks its extension before any byte is sent. */
  fileName?: string | null;
  /** A reserve with a known key answers the open upload (`uploadedPartNumbers`): the client sends the missing parts only. */
  idempotencyKey?: string | null;
}

export interface DriveUploadCompleteArg {
  driveBlobId: number;
  folderNodeId?: number | null;
  name: string;
  comment?: string | null;
  expectedLatestVersionId?: number | null;
  replaceExisting?: boolean;
  /**
   * The agent conversation this write happens beside (the file is open in its canvas): the server notes the new
   * version there, so the agent's next turn knows the file changed (ddd-clouddrive ≥ 0.3.5; ignored without ddd-agents).
   */
  agentConversationId?: number | null;
}

export interface DriveContentArg {
  driveNodeId: number;
  purpose?: DriveContentPurpose;
  versionId?: number | null;
}

export interface DriveNodeMoveArg {
  driveNodeId: number;
  targetFolderNodeId?: number | null;
  name?: string | null;
}

export interface DriveFileCardsArg {
  references: string[];
}

// ─── File manager payloads (mgamadeus/ddd-clouddrive ≥ 0.1.0-rc.10) ────────────────────────────────────────────────

/** The file families of the drive (from the content type and the extension). */
export type DriveFileFamily = 'PDF' | 'SPREADSHEET' | 'DOCUMENT' | 'PRESENTATION' | 'TEXT' | 'IMAGE' | 'VECTOR' | 'VIDEO' | 'ARCHIVE';

/** The scan state a file shows (of its current version, else of its newest one). */
/** `BLOCKED` (ddd-clouddrive ≥ rc.12): refused by the space's type policy; `INFECTED`: malware. */
export type DriveScanStatus = 'UPLOADING' | 'PENDING' | 'CLEAN' | 'INFECTED' | 'BLOCKED' | 'FAILED' | 'PURGING';

/** One file or folder as the lists draw it (`DriveNodeListItem`); null values are left out of the JSON. */
export interface DriveNodeListItem {
  id: number;
  driveSpaceId: number;
  parentNodeId?: number | null;
  nodeType: DriveNodeType;
  name: string;
  /** Lower case without the dot; `''` for folders. */
  extension?: string;
  family?: DriveFileFamily | null;
  contentType?: string | null;
  sizeBytes?: number | null;
  versionNumber?: number | null;
  childCount?: number | null;
  description?: string | null;
  tags?: string[];
  scanStatus?: DriveScanStatus | null;
  hasPendingVersion?: boolean;
  /** The newest version while it is not the current one (ddd-clouddrive ≥ rc.12): number, check state, reason. */
  latestVersionNumber?: number | null;
  latestVersionScanStatus?: DriveScanStatus | null;
  latestVersionStateReason?: string | null;
  hasWorkingCopyChanges?: boolean;
  isContentUntrusted?: boolean;
  isSystem?: boolean;
  /** Only for released JPEG, PNG, GIF or WebP. */
  thumbnailUrl?: string | null;
  /** The server renders this document as a PDF for the preview (`GET /nodes/{id}/rendition`; ddd-clouddrive ≥ 0.3.5). */
  hasPdfRendition?: boolean;
  createdByAccountId?: number | null;
  createdByName?: string | null;
  /** ddd-clouddrive ≥ rc.20: the account the creator acted for (impersonation); null otherwise. */
  createdOnBehalfOfAccountId?: number | null;
  createdOnBehalfOfName?: string | null;
  createdAt?: string | null;
  updatedAt?: string | null;
  trashedAt?: string | null;
  trashedByAccountId?: number | null;
  trashedByName?: string | null;
  purgeAfter?: string | null;
}

/** What a space holds and allows (`DriveSpaceQuota`). */
export interface DriveSpaceQuota {
  usedBytes: number;
  quotaBytes: number;
  maxFileSizeBytes: number;
  fileCount: number;
  folderCount: number;
  trashRetentionDays: number;
  keptVersionCount: number;
  versionRetentionDays: number;
  /** The quota that applies (ddd-clouddrive ≥ rc.12; equals `quotaBytes`). */
  effectiveQuotaBytes?: number | null;
  /** The usage split (ddd-clouddrive ≥ rc.12; absent before): current versions, older versions, trash, uploads being checked. */
  currentVersionBytes?: number | null;
  olderVersionBytes?: number | null;
  trashedBytes?: number | null;
  pendingBytes?: number | null;
}

/** One space as the picker and the file manager read it (`DriveSpaceListItem`). */
export interface DriveSpaceListItem {
  id: number;
  title?: string | null;
  /** The name to show (the host may set it, e.g. the owning record's name). */
  displayName: string;
  /** The group the picker files the space under; `null` = no group. */
  groupLabel?: string | null;
  /** The group to show (ddd-clouddrive ≥ rc.12; preferred over `groupLabel`). */
  displayGroup?: string | null;
  spaceKind?: string | null;
  audience?: DriveSpaceAudience | null;
  rootNodeId?: number | null;
  quota: DriveSpaceQuota;
  canWrite: boolean;
  canManage: boolean;
}

/** One stored version of a file (`DriveFileVersionListItem`), newest first. */
export interface DriveFileVersionListItem {
  id: number;
  driveNodeId: number;
  versionNumber: number;
  sizeBytes?: number | null;
  contentType?: string | null;
  /** Free text; null when the version carries a system note (ddd-clouddrive ≥ rc.19). */
  comment?: string | null;
  /** RESTORED_FROM_VERSION, TRANSFERRED_FROM_SPACE, COPIED_FROM_PATH (ddd-clouddrive ≥ rc.19). */
  commentCode?: string | null;
  commentParameters?: DriveFileVersionCommentParameters | null;
  origin?: string | null;
  authorAccountId?: number | null;
  authorName?: string | null;
  /** ddd-clouddrive ≥ rc.20: the account the author acted for (impersonation); null otherwise. */
  onBehalfOfAccountId?: number | null;
  onBehalfOfName?: string | null;
  createdAt?: string | null;
  scanStatus?: DriveScanStatus | null;
  scanDetail?: string | null;
  isCurrent: boolean;
  isLatest: boolean;
  isRestorable: boolean;
}

/** The values of a version's system note (`DriveFileVersionCommentParameters`); staff-only values are null for partners. */
export interface DriveFileVersionCommentParameters {
  versionNumber?: number | null;
  sourceSpaceDisplayName?: string | null;
  sourcePath?: string | null;
}

/** One entry of a node's history (`DriveChangeEventListItem`). */
export interface DriveChangeEventListItem {
  id: number;
  eventType: string;
  occurredAt?: string | null;
  actorAccountId?: number | null;
  actorName?: string | null;
  path?: string | null;
  previousPath?: string | null;
  driveFileVersionId?: number | null;
  isFromWorkingCopy?: boolean;
  /** `CHANGE` (a change of the node) or `ACTIVITY` (an access or a host event; ddd-clouddrive ≥ rc.11). */
  eventSource?: string | null;
  onBehalfOfAccountId?: number | null;
  onBehalfOfName?: string | null;
  /** `INTERNAL` or `SHARED` (who acted). */
  audience?: string | null;
  /** What an access opened (`INLINE`, `DOWNLOAD`). */
  contentVariant?: string | null;
  detail?: string | null;
  /** The reader's public address (an access; sent to staff readers only, ddd-clouddrive ≥ rc.13). */
  clientIp?: string | null;
}

/** `GET /spaces/{id}/resolve?path=` (ddd-clouddrive ≥ rc.14): where a path or `drive/<id>` leads. */
export interface DriveLocationResolveResponse {
  /** The space of the location (another one than asked for a mount path or a reference). */
  driveSpaceId: number;
  item?: DriveNodeListItem | null;
  ancestors?: { elements?: DriveNodeListItem[] } | null;
  /** The location inside its space, without a leading slash (`""` = the root). */
  path: string;
  adminLink?: string | null;
}

/** The 404 body of the resolve route. */
export interface DriveLocationNotFoundBody {
  error?: string;
  driveSpaceId?: number | null;
  path?: string | null;
  deepestFolder?: DriveNodeListItem | null;
  missingSegment?: string | null;
}

export interface DriveLocationResolveArg {
  driveSpaceId: number;
  path: string;
}

/** What a bulk action did (`DriveNodeBulkResult`); counts include subtrees. */
export interface DriveNodeBulkResult {
  processedCount: number;
  fileCount: number;
  folderCount: number;
  /** `true`: an empty-trash call stopped at its batch; call again. */
  hasMore: boolean;
  skipped?: { elements?: { driveNodeId: number; reason: string }[] } | null;
}

export interface DriveListItemsResponse<TItem> {
  items?: { elements?: TItem[] } | null;
}

export interface DriveSpaceResponse {
  driveSpace: DriveSpace;
  item?: DriveSpaceListItem | null;
}

export interface DriveFoldersResponse {
  items: { elements?: DriveNodeListItem[] };
  isTruncated: boolean;
}

export interface DriveNodeBulkResponse {
  result: DriveNodeBulkResult;
}

/** The orders of a list. */
export type DriveListOrder = 'name' | 'created' | 'size';

/** The filters of `children` (and `search`); without any the folder answers as before. */
export interface DriveListFilters {
  /** The whole space instead of one folder (search, filters). */
  recursive?: boolean;
  /** A part of the name. */
  query?: string;
  family?: DriveFileFamily;
  /** `Y-m-d`. */
  createdFrom?: string;
  /** `Y-m-d`. */
  createdTo?: string;
  createdByMe?: boolean;
  orderBy?: DriveListOrder;
  orderDirection?: 'asc' | 'desc';
}

export interface DriveTrashArg {
  driveSpaceId: number;
  cursor?: string | null;
  limit?: number;
}

export interface DriveFoldersArg {
  driveSpaceId: number;
  includeSystem?: boolean;
}

export interface DriveSpaceIdArg {
  driveSpaceId: number;
}

export interface DriveEventsArg {
  driveNodeId: number;
  limit?: number;
  /** `changes` or `accesses` (ddd-clouddrive ≥ rc.11). */
  eventGroup?: 'changes' | 'accesses';
  /** Comma-separated event types. */
  eventTypes?: string;
}

export interface DriveVersionRestoreArg {
  driveNodeId: number;
  driveFileVersionId: number;
}

export interface DriveNodesBulkArg {
  driveSpaceId: number;
  driveNodeIds: number[];
}

export interface DriveNodesBulkMoveArg extends DriveNodesBulkArg {
  /** Omitted = the space's root. */
  targetFolderNodeId?: number | null;
}

export interface DriveNodeMetadataArg {
  driveNodeId: number;
  tags?: string[] | null;
  description?: string | null;
}
