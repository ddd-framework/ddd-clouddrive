// The writes of the drive's file manager adapter, built from the mutation triggers of `createCloudDriveApi`. Pure: no
// React; `useCloudDriveFileAdapter` binds the triggers, the tests pass fakes.
import { translateText } from '@mgamadeus/ddd-admin-ui/i18n';
import type { FileDownloadOptions, FileManagerActions, FileTextSaveResult } from '@mgamadeus/ddd-admin-ui/files';
import type { FileAreaKey, FileItemId, FileView } from '@mgamadeus/ddd-admin-ui/files';
import type { UploadProgressCallbacks } from '@mgamadeus/ddd-admin-ui/files';
import type { CloudDriveApi } from '../api.ts';
import { driveFileViewOf, driveFolderIdOf, emptyDriveTrashFully } from './driveFileMapping.ts';
import type { DriveContentGrant, DriveContentPurpose, DriveNodeListItem, DriveNodeResponse } from '../types.ts';
import { driveUploadIdempotencyKeyOf, sendDriveUpload, type DriveUploadSender } from './upload.ts';

type TriggerOf<THook> = THook extends () => readonly [infer TTrigger, unknown] ? TTrigger : never;

/** The mutation triggers the writes call (the first element of each mutation hook). */
export interface CloudDriveFileActionTriggers {
  reserveUpload: TriggerOf<CloudDriveApi['useReserveDriveUploadMutation']>;
  putUploadContent: TriggerOf<CloudDriveApi['usePutDriveUploadContentMutation']>;
  putUploadPart: TriggerOf<CloudDriveApi['usePutDriveUploadPartMutation']>;
  completeUpload: TriggerOf<CloudDriveApi['useCompleteDriveUploadMutation']>;
  requestContent: TriggerOf<CloudDriveApi['useDriveContentMutation']>;
  requestRendition: TriggerOf<CloudDriveApi['useDriveRenditionMutation']>;
  moveNode: TriggerOf<CloudDriveApi['useMoveDriveNodeMutation']>;
  updateMetadata: TriggerOf<CloudDriveApi['useUpdateDriveNodeMetadataMutation']>;
  createFolder: TriggerOf<CloudDriveApi['useCreateDriveFolderMutation']>;
  moveNodes: TriggerOf<CloudDriveApi['useMoveDriveNodesMutation']>;
  trashNodes: TriggerOf<CloudDriveApi['useTrashDriveNodesMutation']>;
  restoreNodes: TriggerOf<CloudDriveApi['useRestoreDriveNodesMutation']>;
  purgeNodes: TriggerOf<CloudDriveApi['usePurgeDriveNodesMutation']>;
  emptyTrash: TriggerOf<CloudDriveApi['useEmptyDriveTrashMutation']>;
  restoreVersion: TriggerOf<CloudDriveApi['useRestoreDriveVersionMutation']>;
}

/** The folder and name of a node the writes need and the views do not carry (a new version of a file). */
export interface DriveNodeFact {
  parentNodeId: number | null;
  name: string;
  /** The stored type of the file's content, kept for a text saved as its next version. */
  contentType?: string | null;
}

export interface CloudDriveFileActionContext {
  /** The open space: writes without an area (move, trash, restore, purge) go to it. */
  spaceId: number;
  /** The space's root folder (`null` folder of the file manager). */
  rootNodeId: number | null;
  /** The viewer may purge and empty the trash (the space's manage right). */
  canManage: boolean;
  /** Facts of the nodes the lists showed, by id. */
  nodeFacts: Map<number, DriveNodeFact>;
  /**
   * The agent conversation the manager is shown beside (a file or folder canvas), read at each text save: the server
   * notes the new version there. Absent or `null`: no note.
   */
  getAgentConversationId?: () => number | null;
}

/** How many names "save as a copy" tries when the proposed one is taken (`Notes (copy).md`, `Notes (copy 2).md`, …). */
export const DRIVE_COPY_NAME_ATTEMPTS = 6;

/** The next name of a copy whose proposed name is taken: `Notes (copy).md` → `Notes (copy 2).md` (attempt 2). */
export const nextDriveCopyName = (proposedName: string, attempt: number): string => {
  const match = /^(.*)\)(\.[^.()]*)?$/.exec(proposedName);
  if (!match) return `${proposedName} ${attempt}`;
  return `${match[1]} ${attempt})${match[2] ?? ''}`;
};

/** The text types a saved text keeps from its file; never a type a browser runs or renders (HTML, XML, SVG, script). */
const KEPT_TEXT_CONTENT_TYPES: ReadonlySet<string> = new Set(['text/plain', 'text/markdown', 'text/csv', 'text/yaml', 'application/json', 'application/yaml']);

/** The type a text is uploaded as: the file's stored type when it is a plain text type, else by its name, else plain text. */
export const driveTextContentTypeOf = (name: string, storedContentType: string | null | undefined): string => {
  const stored = (storedContentType ?? '').split(';')[0]?.trim().toLowerCase() ?? '';
  if (KEPT_TEXT_CONTENT_TYPES.has(stored)) return stored;
  const extension = name.includes('.') ? name.slice(name.lastIndexOf('.') + 1).toLowerCase() : '';
  if (extension === 'md' || extension === 'markdown') return 'text/markdown';
  if (extension === 'csv') return 'text/csv';
  if (extension === 'json') return 'application/json';
  return 'text/plain';
};

const toSpaceId = (areaKey: FileAreaKey): number => {
  const spaceId = Number(areaKey);
  return Number.isInteger(spaceId) && spaceId > 0 ? spaceId : 0;
};

const toNodeIds = (ids: readonly FileItemId[]): number[] => ids.map(id => Number(id)).filter(id => Number.isInteger(id) && id > 0);

/** Remembers the folder and name of listed nodes (for a new version of a file). */
export const rememberDriveNodes = (nodeFacts: Map<number, DriveNodeFact>, items: readonly DriveNodeListItem[]): void => {
  for (const item of items) nodeFacts.set(item.id, { parentNodeId: item.parentNodeId ?? null, name: item.name, contentType: item.contentType ?? null });
};

/**
 * The writes of the drive's file manager. Purge and empty trash exist only with `canManage` (so the manager offers
 * them only to managers); every other write is offered and the server checks the space's write right.
 */
/**
 * The content purpose of a download request: a list picture is a `THUMBNAIL` (never recorded), a preview `INLINE`
 * (recorded as viewed; the server serves non-raster types as attachment anyway — an SVG as its sanitised copy, which
 * is what `SANITIZED_IMAGE` asks for), an original `DOWNLOAD` (recorded as downloaded).
 */
export const drivePurposeOf = (options: FileDownloadOptions): DriveContentPurpose =>
  options.variant === 'THUMBNAIL'
    ? 'THUMBNAIL'
    : options.variant === 'PREVIEW' || options.variant === 'SANITIZED_IMAGE' || (options.variant === undefined && options.disposition === 'inline')
      ? 'INLINE'
      : 'DOWNLOAD';

/** The content type of an SVG, as the server names its sanitised copy. */
const SVG_CONTENT_TYPE = 'image/svg+xml';

/**
 * Whether a grant serves the sanitised copy of an SVG as a picture: the server made (or found) the copy and serves it
 * inline as `image/svg+xml`. Any other answer for an SVG — an attachment of the uploaded bytes, another type, a
 * server that does not say `isInline` — is no picture: the viewer shows no preview.
 */
export const isSanitizedImageGrant = (grant: Pick<DriveContentGrant, 'isInline' | 'contentType'>): boolean =>
  grant.isInline === true && (grant.contentType ?? '').split(';')[0].trim().toLowerCase() === SVG_CONTENT_TYPE;

/** The result of a mutation trigger; `signal` aborts its request. */
const unwrapAbortable = <TResult>(request: { unwrap: () => Promise<TResult>; abort?: () => void }, signal?: AbortSignal): Promise<TResult> => {
  if (signal) {
    if (signal.aborted) request.abort?.();
    else signal.addEventListener('abort', () => request.abort?.(), { once: true });
  }
  return request.unwrap();
};

export const buildCloudDriveFileActions = (triggers: CloudDriveFileActionTriggers, context: CloudDriveFileActionContext): FileManagerActions => {
  const { reserveUpload, putUploadContent, putUploadPart, completeUpload, requestContent, requestRendition, moveNode, updateMetadata, createFolder, moveNodes, trashNodes, restoreNodes, purgeNodes, emptyTrash, restoreVersion } = triggers;
  const { spaceId, rootNodeId, canManage, nodeFacts, getAgentConversationId } = context;
  const rememberNodes = (items: readonly DriveNodeListItem[]) => rememberDriveNodes(nodeFacts, items);
  const folderNodeIdOf = (folderId: FileItemId | null): number | null => (folderId === null ? rootNodeId : Number(folderId));
  const fileViewOfResponse = (response: DriveNodeResponse): FileView => {
    if (response.isConflict) {
      throw new Error(translateText('A file with this name exists already in this folder.'));
    }
    const item = response.item;
    if (!item) throw new Error(translateText('The drive answered without the file.'));
    rememberNodes([item]);
    return driveFileViewOf(item, rootNodeId);
  };
  const driveUploadSender: DriveUploadSender = {
    putContent: (driveBlobId, body, signal) => unwrapAbortable(putUploadContent({ driveBlobId, body }), signal),
    putPart: (driveBlobId, partNumber, body, signal) => unwrapAbortable(putUploadPart({ driveBlobId, partNumber, body }), signal),
  };
  /** Sends a file's bytes and completes the upload; the answer may be a conflict (`isConflict`). */
  const sendInto = async (
    driveSpaceId: number,
    folderNodeId: number | null,
    file: File,
    callbacks: UploadProgressCallbacks,
    options: { comment?: string | null; replaceExisting?: boolean; expectedLatestVersionId?: number | null; agentConversationId?: number | null }
  ): Promise<DriveNodeResponse> => {
    // the file's name lets the space refuse a type before any byte is sent; the key resumes an upload cut off before
    const { reservation } = await reserveUpload({
      driveSpaceId,
      sizeBytes: file.size,
      contentType: file.type || null,
      fileName: file.name,
      idempotencyKey: driveUploadIdempotencyKeyOf({ driveSpaceId, folderNodeId, name: file.name, sizeBytes: file.size, lastModified: file.lastModified }),
    }).unwrap();
    await sendDriveUpload(reservation, file, driveUploadSender, (sent, total) => callbacks.onProgress?.(sent, total), callbacks.signal);
    return completeUpload({
      driveBlobId: reservation.driveBlobId,
      folderNodeId,
      name: file.name,
      comment: options.comment ?? null,
      replaceExisting: options.replaceExisting ?? false,
      ...(options.expectedLatestVersionId === undefined ? {} : { expectedLatestVersionId: options.expectedLatestVersionId }),
      ...(options.agentConversationId ? { agentConversationId: options.agentConversationId } : {}),
    }).unwrap();
  };
  const uploadInto = async (
    driveSpaceId: number,
    folderNodeId: number | null,
    file: File,
    callbacks: UploadProgressCallbacks,
    options: { comment?: string | null; replaceExisting?: boolean }
  ): Promise<FileView> => fileViewOfResponse(await sendInto(driveSpaceId, folderNodeId, file, callbacks, options));
  const textFileOf = (text: string, name: string, storedContentType: string | null | undefined): File =>
    new File([text], name, { type: driveTextContentTypeOf(name, storedContentType), lastModified: Date.now() });

  const actions: FileManagerActions = {
    upload: (input, callbacks) =>
      uploadInto(toSpaceId(input.areaKey), folderNodeIdOf(input.folderId), input.file, callbacks, { comment: input.description }),
    uploadVersion: async (fileId, file, callbacks) => {
      const fact = nodeFacts.get(Number(fileId));
      if (!fact) throw new Error(translateText('Open the file again and retry.'));
      return uploadInto(spaceId, fact.parentNodeId, new File([file], fact.name, { type: file.type, lastModified: file.lastModified }), callbacks, { replaceExisting: true });
    },
    getDownload: async (fileId, options) => {
      if (options.variant === 'PDF_RENDITION') {
        const rendition = await requestRendition({ driveNodeId: Number(fileId), versionId: options.versionId === undefined ? null : Number(options.versionId) }).unwrap();
        if (rendition.state === 'READY' && rendition.grant) {
          return { url: rendition.grant.url, fileName: rendition.grant.fileName, expiresAt: rendition.grant.expiresAt ?? null, state: 'ready' };
        }
        return { url: '', fileName: '', state: rendition.state === 'FAILED' ? 'failed' : 'pending', message: rendition.reason ?? null };
      }
      const { grant } = await requestContent({
        driveNodeId: Number(fileId),
        purpose: drivePurposeOf(options),
        versionId: options.versionId === undefined ? null : Number(options.versionId),
      }).unwrap();
      if (options.variant === 'SANITIZED_IMAGE') {
        // only the server's sanitised copy is a picture; the uploaded bytes (an attachment answer) never reach an <img>
        return isSanitizedImageGrant(grant)
          ? { url: grant.url, fileName: grant.fileName, expiresAt: grant.expiresAt ?? null, state: 'ready' }
          : { url: '', fileName: grant.fileName, state: 'failed', message: null };
      }
      return { url: grant.url, fileName: grant.fileName, expiresAt: grant.expiresAt ?? null };
    },
    patchFile: async (fileId, patch) => {
      const driveNodeId = Number(fileId);
      if (patch.name !== undefined || patch.folderId !== undefined) {
        await moveNode({
          driveNodeId,
          targetFolderNodeId: patch.folderId === undefined ? null : folderNodeIdOf(patch.folderId),
          name: patch.name ?? null,
        }).unwrap();
      }
      if (patch.description !== undefined) {
        await updateMetadata({ driveNodeId, description: patch.description }).unwrap();
      }
    },
    moveFiles: async (fileIds, folderId) =>
      (await moveNodes({ driveSpaceId: spaceId, driveNodeIds: toNodeIds(fileIds), targetFolderNodeId: folderNodeIdOf(folderId) }).unwrap()).result,
    trashFiles: async fileIds => (await trashNodes({ driveSpaceId: spaceId, driveNodeIds: toNodeIds(fileIds) }).unwrap()).result,
    restoreFiles: async fileIds => (await restoreNodes({ driveSpaceId: spaceId, driveNodeIds: toNodeIds(fileIds) }).unwrap()).result,
    createFolder: async input => {
      const response = await createFolder({
        driveSpaceId: toSpaceId(input.areaKey),
        parentNodeId: input.parentFolderId === null ? null : Number(input.parentFolderId),
        name: input.name,
      }).unwrap();
      const node = response.item ?? null;
      return {
        id: node?.id ?? response.driveNode.id,
        areaKey: toSpaceId(input.areaKey),
        parentId: driveFolderIdOf(node?.parentNodeId ?? response.driveNode.parentNodeId, rootNodeId),
        name: node?.name ?? response.driveNode.name,
        fileCount: 0,
      };
    },
    patchFolder: async (folderId, patch) => {
      await moveNode({
        driveNodeId: Number(folderId),
        targetFolderNodeId: patch.parentFolderId === undefined ? null : folderNodeIdOf(patch.parentFolderId),
        name: patch.name ?? null,
      }).unwrap();
    },
    deleteFolder: async folderId => {
      const { result } = await trashNodes({ driveSpaceId: spaceId, driveNodeIds: toNodeIds([folderId]) }).unwrap();
      return { trashedFiles: result.fileCount, deletedFolders: result.folderCount };
    },
    restoreVersion: async (fileId, versionId) =>
      restoreVersion({ driveNodeId: Number(fileId), driveFileVersionId: Number(versionId) }).unwrap(),
    // a text edited in place: a new version on top of the version it was read from, never on top of a newer one
    saveText: async (fileId, input): Promise<FileTextSaveResult> => {
      const fact = nodeFacts.get(Number(fileId));
      if (!fact) throw new Error(translateText('Open the file again and retry.'));
      const response = await sendInto(spaceId, fact.parentNodeId, textFileOf(input.text, fact.name, fact.contentType), {}, {
        // without a known base the server compares with "no version": an existing file is then always a conflict
        expectedLatestVersionId: input.baseVersionId === null ? null : Number(input.baseVersionId),
        agentConversationId: getAgentConversationId?.() ?? null,
      });
      if (response.isConflict) return { status: 'conflict', message: response.conflictReason ?? null };
      return { status: 'saved', file: fileViewOfResponse(response) };
    },
    saveTextAsCopy: async (fileId, input) => {
      const fact = nodeFacts.get(Number(fileId));
      if (!fact) throw new Error(translateText('Open the file again and retry.'));
      for (let attempt = 1; attempt <= DRIVE_COPY_NAME_ATTEMPTS; attempt += 1) {
        const name = attempt === 1 ? input.name : nextDriveCopyName(input.name, attempt);
        // a name that exists answers a conflict (the copy never becomes a version of another file): the next name
        const response = await sendInto(spaceId, fact.parentNodeId, textFileOf(input.text, name, fact.contentType), {}, {
          expectedLatestVersionId: null,
          agentConversationId: getAgentConversationId?.() ?? null,
        });
        if (!response.isConflict) return fileViewOfResponse(response);
      }
      throw new Error(translateText('No free name was found for the copy. Rename a copy in this folder and retry.'));
    },
  };
  if (canManage) {
    actions.purgeFiles = async fileIds => (await purgeNodes({ driveSpaceId: spaceId, driveNodeIds: toNodeIds(fileIds) }).unwrap()).result;
    actions.emptyTrash = async areaKey =>
      emptyDriveTrashFully(async () => (await emptyTrash({ driveSpaceId: toSpaceId(areaKey) }).unwrap()).result);
  }
  return actions;
};
