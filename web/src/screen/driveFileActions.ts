// The writes of the drive's file manager adapter, built from the mutation triggers of `createCloudDriveApi`. Pure: no
// React; `useCloudDriveFileAdapter` binds the triggers, the tests pass fakes.
import { translateText } from '@mgamadeus/ddd-admin-ui/i18n';
import type { FileDownloadOptions, FileManagerActions } from '@mgamadeus/ddd-admin-ui/files';
import type { FileAreaKey, FileItemId, FileView } from '@mgamadeus/ddd-admin-ui/files';
import type { UploadProgressCallbacks } from '@mgamadeus/ddd-admin-ui/files';
import type { CloudDriveApi } from '../api.ts';
import { driveFileViewOf, driveFolderIdOf, emptyDriveTrashFully } from './driveFileMapping.ts';
import type { DriveContentPurpose, DriveNodeListItem, DriveNodeResponse } from '../types.ts';
import { sendDriveUpload } from './upload.ts';

type TriggerOf<THook> = THook extends () => readonly [infer TTrigger, unknown] ? TTrigger : never;

/** The mutation triggers the writes call (the first element of each mutation hook). */
export interface CloudDriveFileActionTriggers {
  reserveUpload: TriggerOf<CloudDriveApi['useReserveDriveUploadMutation']>;
  completeUpload: TriggerOf<CloudDriveApi['useCompleteDriveUploadMutation']>;
  requestContent: TriggerOf<CloudDriveApi['useDriveContentMutation']>;
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
}

const toSpaceId = (areaKey: FileAreaKey): number => {
  const spaceId = Number(areaKey);
  return Number.isInteger(spaceId) && spaceId > 0 ? spaceId : 0;
};

const toNodeIds = (ids: readonly FileItemId[]): number[] => ids.map(id => Number(id)).filter(id => Number.isInteger(id) && id > 0);

/** Remembers the folder and name of listed nodes (for a new version of a file). */
export const rememberDriveNodes = (nodeFacts: Map<number, DriveNodeFact>, items: readonly DriveNodeListItem[]): void => {
  for (const item of items) nodeFacts.set(item.id, { parentNodeId: item.parentNodeId ?? null, name: item.name });
};

/**
 * The writes of the drive's file manager. Purge and empty trash exist only with `canManage` (so the manager offers
 * them only to managers); every other write is offered and the server checks the space's write right.
 */
/**
 * The content purpose of a download request: a list picture is a `THUMBNAIL` (never recorded), a preview `INLINE`
 * (recorded as viewed; the server serves non-raster types as attachment anyway), an original `DOWNLOAD` (recorded as
 * downloaded).
 */
export const drivePurposeOf = (options: FileDownloadOptions): DriveContentPurpose =>
  options.variant === 'THUMBNAIL'
    ? 'THUMBNAIL'
    : options.variant === 'PREVIEW' || (options.variant === undefined && options.disposition === 'inline')
      ? 'INLINE'
      : 'DOWNLOAD';

export const buildCloudDriveFileActions = (triggers: CloudDriveFileActionTriggers, context: CloudDriveFileActionContext): FileManagerActions => {
  const { reserveUpload, completeUpload, requestContent, moveNode, updateMetadata, createFolder, moveNodes, trashNodes, restoreNodes, purgeNodes, emptyTrash, restoreVersion } = triggers;
  const { spaceId, rootNodeId, canManage, nodeFacts } = context;
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
  const uploadInto = async (
    driveSpaceId: number,
    folderNodeId: number | null,
    file: File,
    callbacks: UploadProgressCallbacks,
    options: { comment?: string | null; replaceExisting?: boolean }
  ): Promise<FileView> => {
    const { reservation } = await reserveUpload({ driveSpaceId, sizeBytes: file.size, contentType: file.type || null }).unwrap();
    await sendDriveUpload(reservation, file, (sent, total) => callbacks.onProgress?.(sent, total), callbacks.signal);
    const response = await completeUpload({
      driveBlobId: reservation.driveBlobId,
      folderNodeId,
      name: file.name,
      comment: options.comment ?? null,
      replaceExisting: options.replaceExisting ?? false,
    }).unwrap();
    return fileViewOfResponse(response);
  };

  const actions: FileManagerActions = {
    upload: (input, callbacks) =>
      uploadInto(toSpaceId(input.areaKey), folderNodeIdOf(input.folderId), input.file, callbacks, { comment: input.description }),
    uploadVersion: async (fileId, file, callbacks) => {
      const fact = nodeFacts.get(Number(fileId));
      if (!fact) throw new Error(translateText('Open the file again and retry.'));
      return uploadInto(spaceId, fact.parentNodeId, new File([file], fact.name, { type: file.type }), callbacks, { replaceExisting: true });
    },
    getDownload: async (fileId, options) => {
      const { grant } = await requestContent({
        driveNodeId: Number(fileId),
        purpose: drivePurposeOf(options),
        versionId: options.versionId === undefined ? null : Number(options.versionId),
      }).unwrap();
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
  };
  if (canManage) {
    actions.purgeFiles = async fileIds => (await purgeNodes({ driveSpaceId: spaceId, driveNodeIds: toNodeIds(fileIds) }).unwrap()).result;
    actions.emptyTrash = async areaKey =>
      emptyDriveTrashFully(async () => (await emptyTrash({ driveSpaceId: toSpaceId(areaKey) }).unwrap()).result);
  }
  return actions;
};
