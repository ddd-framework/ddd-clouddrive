import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { resolveFileManagerFeatures } from '@mgamadeus/ddd-admin-ui/files';
import { buildCloudDriveFileActions, type CloudDriveFileActionTriggers, type DriveNodeFact } from './driveFileActions.ts';
import { newestVersionStateOf } from '@mgamadeus/ddd-admin-ui/files';
import {
  DRIVE_LIST_MAX_LIMIT,
  driveAreaRefOf,
  driveAreaViewOf,
  driveChildrenArgOf,
  driveEventViewOf,
  driveFileItemsOf,
  driveFileViewOf,
  driveFolderViewsOf,
  driveScanStatusOf,
  driveVersionViewOf,
  emptyDriveTrashFully,
  groupDriveSpaces,
  nextDriveListLimit,
} from './driveFileMapping.ts';
import type { DriveNodeBulkResult, DriveNodeListItem, DriveSpaceListItem } from '../types.ts';

const ROOT = 10;

const file = (overrides: Partial<DriveNodeListItem> = {}): DriveNodeListItem => ({
  id: 41,
  driveSpaceId: 3,
  parentNodeId: ROOT,
  nodeType: 'FILE',
  name: 'Budget 2026.xlsx',
  extension: 'xlsx',
  family: 'SPREADSHEET',
  sizeBytes: 2048,
  scanStatus: 'CLEAN',
  ...overrides,
});

const space: DriveSpaceListItem = {
  id: 3,
  title: 'Team',
  displayName: 'Team A',
  groupLabel: 'Teams',
  rootNodeId: ROOT,
  quota: { usedBytes: 100, quotaBytes: 1000, maxFileSizeBytes: 50, fileCount: 2, folderCount: 1, trashRetentionDays: 30, keptVersionCount: 5, versionRetentionDays: 0 },
  canWrite: true,
  canManage: false,
};

describe('drive payloads as file manager views', () => {
  it('maps a file: the root folder is null, the facts and flags carry over', () => {
    const view = driveFileViewOf(file({ hasWorkingCopyChanges: true, hasPendingVersion: true, thumbnailUrl: null, createdByName: 'Ann' }), ROOT);
    assert.equal(view.folderId, null);
    assert.equal(view.areaKey, 3);
    assert.equal(view.family, 'SPREADSHEET');
    assert.equal(view.hasWorkingCopyChanges, true);
    assert.equal(view.hasPendingVersion, true);
    assert.equal(view.uploadedBy, 'Ann');
    assert.equal(driveFileViewOf(file({ parentNodeId: 12 }), ROOT).folderId, 12);
  });

  it('carries the facts of what the server derives: the PDF rendition and the sanitised picture of an SVG', () => {
    const svg = driveFileViewOf(file({ name: 'logo.svg', extension: 'svg', contentType: 'image/svg+xml', family: null, hasSanitizedImage: true }), ROOT);
    assert.equal(svg.family, 'VECTOR');
    assert.equal(svg.hasSanitizedImage, true);
    assert.equal('hasSanitizedImage' in driveFileViewOf(file({ name: 'logo.svg', hasSanitizedImage: false }), ROOT), false);
    assert.equal('hasSanitizedImage' in driveFileViewOf(file({ name: 'logo.svg' }), ROOT), false, 'an older server: no picture');
    assert.equal(driveFileViewOf(file({ name: 'letter.docx', hasPdfRendition: true }), ROOT).hasPdfRendition, true);
  });

  it('derives the family and extension when the server sends none', () => {
    const view = driveFileViewOf(file({ family: null, extension: '', name: 'photo.PNG', contentType: 'image/png' }), ROOT);
    assert.equal(view.extension, 'png');
    assert.equal(view.family, 'IMAGE');
  });

  it('maps the scan states; PURGING is not available, a file without a state is still uploading', () => {
    assert.equal(driveScanStatusOf('PURGING'), 'FAILED');
    assert.equal(driveScanStatusOf('INFECTED'), 'INFECTED');
    assert.equal(driveFileViewOf(file({ scanStatus: null }), ROOT).scanStatus, 'UPLOADING');
  });

  it('builds the folder tree without the root', () => {
    const folders = driveFolderViewsOf(
      [
        { id: ROOT, driveSpaceId: 3, parentNodeId: null, nodeType: 'FOLDER', name: '' },
        { id: 12, driveSpaceId: 3, parentNodeId: ROOT, nodeType: 'FOLDER', name: 'Reports', childCount: 4 },
        { id: 13, driveSpaceId: 3, parentNodeId: 12, nodeType: 'FOLDER', name: '2026' },
      ],
      ROOT
    );
    assert.deepEqual(folders.map(folder => [folder.id, folder.parentId]), [[12, null], [13, 12]]);
    assert.equal(folders[0]?.entryCount, 4, 'the drive counts entries (files and folders), not files');
  });

  it('keeps only files of a list answer', () => {
    const items = driveFileItemsOf([file(), file({ id: 2, nodeType: 'FOLDER' }), file({ id: 3, isSystem: true }), file({ id: 4, nodeType: 'LINK' })]);
    assert.deepEqual(items.map(item => item.id), [41]);
  });

  it('maps the folder creator and who they acted for', () => {
    const [folder] = driveFolderViewsOf(
      [file({ id: 5, nodeType: 'FOLDER', createdByName: 'Staff', createdOnBehalfOfName: 'Partner GmbH', createdAt: '2026-11-23 10:00:00', updatedAt: '2026-11-23 11:00:00' })],
      ROOT
    );
    assert.equal(folder?.createdBy, 'Staff');
    assert.equal(folder?.createdOnBehalfOf, 'Partner GmbH');
    assert.equal(folder?.createdAt, '2026-11-23 10:00:00');
    assert.equal(folder?.updatedAt, '2026-11-23 11:00:00');
  });

  it('keeps a trashed folder in the trash as a folder row (empty or not)', () => {
    const view = driveFileViewOf(file({ id: 6, nodeType: 'FOLDER', name: 'ob-ordner', extension: '', trashedAt: '2026-11-23 21:00:00' }), ROOT);
    assert.equal(view.isFolder, true);
    assert.equal(view.deletedAt, '2026-11-23 21:00:00');
    assert.equal(driveFileViewOf(file(), ROOT).isFolder, undefined);
  });

  it('maps who the uploader and the version author acted for (ddd-clouddrive rc.20)', () => {
    const view = driveFileViewOf(file({ createdByName: 'Staff', createdOnBehalfOfName: 'Partner GmbH' }), ROOT);
    assert.equal(view.uploadedBy, 'Staff');
    assert.equal(view.uploadedOnBehalfOf, 'Partner GmbH');
    assert.equal(driveFileViewOf(file({ createdByName: 'Ann' }), ROOT).uploadedOnBehalfOf, null);
    const version = driveVersionViewOf({ id: 7, driveNodeId: 41, versionNumber: 2, isCurrent: true, isLatest: true, isRestorable: false, authorName: 'Staff', onBehalfOfName: 'Partner GmbH' });
    assert.equal(version.uploadedBy, 'Staff');
    assert.equal(version.uploadedOnBehalfOf, 'Partner GmbH');
  });

  it('maps versions, events and the space', () => {
    const version = driveVersionViewOf({ id: 7, driveNodeId: 41, versionNumber: 2, scanStatus: 'CLEAN', isCurrent: true, isLatest: true, isRestorable: false, comment: 'Restored from v1' });
    assert.equal(version.comment, 'Restored from v1');
    assert.equal(version.isCurrent, true);
    const event = driveEventViewOf({ id: 1, eventType: 'MOVED', actorName: 'Ann', previousPath: '/a.txt', path: '/Reports/a.txt' });
    assert.equal(event.detail, '/a.txt → /Reports/a.txt');
    assert.equal(driveEventViewOf({ id: 2, eventType: 'CREATED', path: '/a.txt' }).detail, null);
    const area = driveAreaViewOf(space);
    assert.equal(area.quotaBytes, 1000);
    assert.equal(area.trashRetentionDays, 30);
    assert.equal(driveAreaViewOf({ ...space, quota: { ...space.quota, quotaBytes: 0 } }).quotaBytes, null);
    assert.deepEqual(driveAreaRefOf(space), { key: 3, name: 'Team A', groupLabel: 'Teams' });
    assert.equal(driveAreaRefOf({ ...space, displayGroup: 'TEAM' }).groupLabel, 'Teams', 'the translated label wins over the code');
    assert.equal(driveAreaRefOf({ ...space, groupLabel: null, displayGroup: 'TEAM' }).groupLabel, 'TEAM');
    assert.equal(driveAreaViewOf({ ...space, quota: { ...space.quota, quotaBytes: 0, effectiveQuotaBytes: 4096 } }).quotaBytes, 4096);
  });

  it('turns the drive notes into system notes without internal ids, keeps free text', () => {
    const restored = driveVersionViewOf({ id: 8, driveNodeId: 41, versionNumber: 3, isCurrent: true, isLatest: true, isRestorable: false, comment: null, commentCode: 'RESTORED_FROM_VERSION', commentParameters: { versionNumber: 1 } });
    assert.deepEqual(restored.systemNote, { code: 'RESTORED_FROM_VERSION', versionNumber: 1, sourceAreaName: null, sourcePath: null });
    const moved = driveVersionViewOf({ id: 9, driveNodeId: 41, versionNumber: 1, isCurrent: true, isLatest: true, isRestorable: false, commentCode: 'TRANSFERRED_FROM_SPACE', commentParameters: { sourceSpaceDisplayName: null } });
    assert.equal(moved.systemNote?.code, 'MOVED_FROM_AREA');
    assert.equal(moved.systemNote?.sourceAreaName, null, 'a partner gets no source space');
    const unknown = driveVersionViewOf({ id: 10, driveNodeId: 41, versionNumber: 1, isCurrent: true, isLatest: true, isRestorable: false, comment: 'checked', commentCode: 'SOMETHING_NEW' });
    assert.equal(unknown.systemNote, null);
    assert.equal(unknown.comment, 'checked');
  });

  it('names the current content, so a preview is fetched again after a restore', () => {
    assert.equal(driveFileViewOf(file({ versionNumber: 2 }), ROOT).contentVersion, 2);
    assert.equal(driveFileViewOf(file({ versionNumber: 3 }), ROOT).contentVersion, 3);
  });

  it('asks for a folder, or for the whole space on a search or without a folder', () => {
    const folderArg = driveChildrenArgOf({ areaKey: 3, folderId: 'root' }, ROOT, 100);
    assert.equal(folderArg.folderNodeId, ROOT);
    assert.equal(folderArg.recursive, undefined);
    const searchArg = driveChildrenArgOf({ areaKey: 3, folderId: 12, q: ' budget ', uploadedByMe: true, family: 'PDF', orderBy: 'size', orderDirection: 'desc' }, ROOT, 200);
    assert.equal(searchArg.recursive, true);
    assert.equal(searchArg.folderNodeId, undefined);
    assert.equal(searchArg.query, 'budget');
    assert.equal(searchArg.createdByMe, true);
    assert.equal(searchArg.family, 'PDF');
    assert.equal(searchArg.orderBy, 'size');
    assert.equal(searchArg.limit, 200);
    assert.equal(driveChildrenArgOf({ areaKey: 3, family: 'AUDIO' }, ROOT, 100).family, undefined, 'a family the drive does not know is no filter');
  });

  it('names the state of a newest version that is not current', () => {
    assert.equal(newestVersionStateOf(driveFileViewOf(file({ hasPendingVersion: true }), ROOT)), 'checking');
    assert.equal(newestVersionStateOf(driveFileViewOf(file({ hasPendingVersion: true, latestVersionScanStatus: 'INFECTED' }), ROOT)), 'infected');
    const refused = driveFileViewOf(file({ latestVersionNumber: 3, latestVersionScanStatus: 'BLOCKED', latestVersionStateReason: 'text/html is not allowed' }), ROOT);
    assert.equal(newestVersionStateOf(refused), 'blocked');
    assert.equal(refused.newestVersionNumber, 3);
    assert.equal(refused.newestVersionReason, 'text/html is not allowed');
    assert.equal(driveScanStatusOf('BLOCKED'), 'BLOCKED');
    assert.equal(newestVersionStateOf(driveFileViewOf(file({ hasPendingVersion: true, latestVersionScanStatus: 'FAILED' }), ROOT)), 'failed');
    assert.equal(newestVersionStateOf(driveFileViewOf(file(), ROOT)), null);
  });

  it('splits the usage only when the server sends the parts', () => {
    assert.equal(driveAreaViewOf(space).usageParts, null);
    const parts = driveAreaViewOf({ ...space, quota: { ...space.quota, currentVersionBytes: 60, olderVersionBytes: 20, trashedBytes: 15, pendingBytes: 5 } }).usageParts;
    assert.deepEqual(parts?.map(part => part.bytes), [60, 20, 15, 5]);
  });

  it('groups the spaces in the server order', () => {
    const groups = groupDriveSpaces([
      { key: 1, name: 'B', groupLabel: 'Teams' },
      { key: 2, name: 'A', groupLabel: 'Teams' },
      { key: 3, name: 'Z', groupLabel: null },
      { key: 4, name: 'C', groupLabel: 'Archive' },
    ]);
    assert.deepEqual(groups.map(group => [group.label, group.areas.map(area => area.key)]), [['Teams', [1, 2]], ['', [3]], ['Archive', [4]]]);
  });

  it('grows a list page by page up to the largest answer', () => {
    assert.equal(nextDriveListLimit(100), 200);
    assert.equal(nextDriveListLimit(DRIVE_LIST_MAX_LIMIT), null);
  });
});

describe('emptying the drive trash', () => {
  const result = (processedCount: number, hasMore: boolean): DriveNodeBulkResult => ({ processedCount, fileCount: processedCount, folderCount: 0, hasMore });

  it('calls again while the server answers hasMore and adds the counts', async () => {
    const answers = [result(200, true), result(200, true), result(13, false)];
    const outcome = await emptyDriveTrashFully(async () => answers.shift() ?? result(0, false));
    assert.equal(outcome.calls, 3);
    assert.equal(outcome.processedCount, 413);
    assert.equal(outcome.hasMore, false);
  });

  it('stops at the call limit and when a batch removed nothing', async () => {
    const limited = await emptyDriveTrashFully(async () => result(200, true), 2);
    assert.equal(limited.calls, 2);
    assert.equal(limited.hasMore, true);
    const stuck = await emptyDriveTrashFully(async () => result(0, true));
    assert.equal(stuck.calls, 1);
  });
});

describe('drive file actions', () => {
  const calls: { name: string; arg: unknown }[] = [];
  const trigger = (name: string, answer: unknown) => (arg: unknown) => {
    calls.push({ name, arg });
    return { unwrap: () => Promise.resolve(answer), abort: () => undefined };
  };
  const bulk = { result: { processedCount: 1, fileCount: 1, folderCount: 0, hasMore: false } };
  const triggers = {
    reserveUpload: trigger('reserveUpload', {}),
    putUploadContent: trigger('putUploadContent', {}),
    putUploadPart: trigger('putUploadPart', {}),
    completeUpload: trigger('completeUpload', {}),
    requestContent: trigger('requestContent', { grant: { url: '/api/public/drive/content/token.signature', fileName: 'x.pdf', purpose: 'DOWNLOAD' } }),
    moveNode: trigger('moveNode', {}),
    updateMetadata: trigger('updateMetadata', {}),
    createFolder: trigger('createFolder', { driveNode: { id: 12, driveSpaceId: 3, parentNodeId: ROOT, nodeType: 'FOLDER', name: 'New' }, path: '/New' }),
    moveNodes: trigger('moveNodes', bulk),
    trashNodes: trigger('trashNodes', { result: { processedCount: 3, fileCount: 2, folderCount: 1, hasMore: false } }),
    restoreNodes: trigger('restoreNodes', bulk),
    purgeNodes: trigger('purgeNodes', bulk),
    emptyTrash: trigger('emptyTrash', bulk),
    restoreVersion: trigger('restoreVersion', {}),
  } as unknown as CloudDriveFileActionTriggers;
  const context = (canManage: boolean) => ({ spaceId: 3, rootNodeId: ROOT, canManage, nodeFacts: new Map<number, DriveNodeFact>() });

  it('offers purge and empty trash only to a manager', () => {
    const viewerFeatures = resolveFileManagerFeatures({ trash: { useTrash: () => { throw new Error('unused'); } } }, buildCloudDriveFileActions(triggers, context(false)));
    assert.equal(viewerFeatures.restore, true);
    assert.equal(viewerFeatures.purge, false);
    assert.equal(viewerFeatures.emptyTrash, false);
    const managerFeatures = resolveFileManagerFeatures({ trash: { useTrash: () => { throw new Error('unused'); } } }, buildCloudDriveFileActions(triggers, context(true)));
    assert.equal(managerFeatures.purge, true);
    assert.equal(managerFeatures.emptyTrash, true);
  });

  it('moves to the root with its node id, trashes a folder with its subtree counts, asks inline for a preview', async () => {
    calls.length = 0;
    const actions = buildCloudDriveFileActions(triggers, context(false));
    await actions.moveFiles([41, '42'], null);
    assert.deepEqual(calls.at(-1), { name: 'moveNodes', arg: { driveSpaceId: 3, driveNodeIds: [41, 42], targetFolderNodeId: ROOT } });
    assert.deepEqual(await actions.deleteFolder(12), { trashedFiles: 2, deletedFolders: 1 });
    const download = await actions.getDownload(41, { disposition: 'inline' });
    assert.equal(download.url, '/api/public/drive/content/token.signature');
    assert.deepEqual(calls.at(-1), { name: 'requestContent', arg: { driveNodeId: 41, purpose: 'INLINE', versionId: null } });
    await actions.patchFile(41, { description: 'Q1' });
    assert.deepEqual(calls.at(-1), { name: 'updateMetadata', arg: { driveNodeId: 41, description: 'Q1' } });
    const folder = await actions.createFolder({ areaKey: 3, parentFolderId: null, name: 'New' });
    assert.deepEqual({ id: folder.id, parentId: folder.parentId }, { id: 12, parentId: null });
  });

  it('refuses a new version of a file it never listed', async () => {
    const actions = buildCloudDriveFileActions(triggers, context(false));
    await assert.rejects(actions.uploadVersion?.(99, new File(['x'], 'x.txt'), {}) ?? Promise.resolve());
  });
});
