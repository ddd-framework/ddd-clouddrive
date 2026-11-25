import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  buildCloudDriveFileActions,
  type CloudDriveFileActionTriggers,
  type DriveNodeFact,
  drivePurposeOf,
  driveTextContentTypeOf,
  isSanitizedImageGrant,
  nextDriveCopyName,
} from './driveFileActions.ts';
import type { DriveContentGrant, DriveNodeListItem, DriveNodeResponse, DriveRenditionResponse, DriveUploadCompleteArg } from '../types.ts';

const item = (overrides: Partial<DriveNodeListItem> = {}): DriveNodeListItem => ({
  id: 812,
  driveSpaceId: 3,
  parentNodeId: 40,
  nodeType: 'FILE',
  name: 'Notes.md',
  extension: 'md',
  contentType: 'text/markdown',
  sizeBytes: 12,
  versionNumber: 5,
  scanStatus: 'CLEAN',
  ...overrides,
});

const answer = <TResult>(result: TResult) => ({ unwrap: async () => result });

interface Recorded {
  completed: DriveUploadCompleteArg[];
  reserved: { fileName?: string | null; contentType?: string | null }[];
  sentBodies: Blob[];
  renditionRequests: unknown[];
}

/** Triggers that record what the writes send; `complete` answers each completed upload. */
const fakeTriggers = (
  complete: (arg: DriveUploadCompleteArg) => DriveNodeResponse,
  rendition: DriveRenditionResponse = { state: 'PENDING' }
): { triggers: CloudDriveFileActionTriggers; recorded: Recorded } => {
  const recorded: Recorded = { completed: [], reserved: [], sentBodies: [], renditionRequests: [] };
  const unused = () => {
    throw new Error('not called in this test');
  };
  const triggers = {
    reserveUpload: (arg: { fileName?: string | null; contentType?: string | null }) => {
      recorded.reserved.push({ fileName: arg.fileName, contentType: arg.contentType });
      return answer({ reservation: { driveBlobId: 900 + recorded.reserved.length, sizeBytes: 0 } });
    },
    putUploadContent: (arg: { body: Blob }) => {
      recorded.sentBodies.push(arg.body);
      return answer({ driveBlobId: 1 });
    },
    putUploadPart: unused,
    completeUpload: (arg: DriveUploadCompleteArg) => {
      recorded.completed.push(arg);
      return answer(complete(arg));
    },
    requestContent: unused,
    requestRendition: (arg: unknown) => {
      recorded.renditionRequests.push(arg);
      return answer(rendition);
    },
    moveNode: unused,
    updateMetadata: unused,
    createFolder: unused,
    moveNodes: unused,
    trashNodes: unused,
    restoreNodes: unused,
    purgeNodes: unused,
    emptyTrash: unused,
    restoreVersion: unused,
  } as unknown as CloudDriveFileActionTriggers;
  return { triggers, recorded };
};

const nodeFacts = (): Map<number, DriveNodeFact> => new Map([[812, { parentNodeId: 40, name: 'Notes.md', contentType: 'text/markdown' }]]);
const response = (overrides: Partial<DriveNodeResponse> = {}): DriveNodeResponse => ({ driveNode: { id: 812 } as never, path: '/Notes.md', item: item(), ...overrides });

describe('saving a text of the drive', () => {
  it('writes a new version on top of the version the text was read from', async () => {
    const { triggers, recorded } = fakeTriggers(() => response({ item: item({ versionNumber: 6 }) }));
    const actions = buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts(), getAgentConversationId: () => 24682 });
    const result = await actions.saveText?.(812, { text: '# Notes\n', baseVersionId: 77 });
    assert.equal(result?.status, 'saved');
    assert.equal(recorded.completed.length, 1);
    assert.deepEqual(
      { ...recorded.completed[0], driveBlobId: 0 },
      { driveBlobId: 0, folderNodeId: 40, name: 'Notes.md', comment: null, replaceExisting: false, expectedLatestVersionId: 77, agentConversationId: 24682 }
    );
    assert.deepEqual(recorded.reserved, [{ fileName: 'Notes.md', contentType: 'text/markdown' }]);
    assert.equal(await recorded.sentBodies[0]?.text(), '# Notes\n');
  });

  it('never replaces: a newer version answers a conflict and nothing more is sent', async () => {
    const { triggers, recorded } = fakeTriggers(() => response({ isConflict: true, conflictReason: 'the file has a newer version' }));
    const actions = buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
    assert.deepEqual(await actions.saveText?.(812, { text: 'mine', baseVersionId: 77 }), { status: 'conflict', message: 'the file has a newer version' });
    assert.equal(recorded.completed.length, 1);
    assert.equal(recorded.completed[0]?.replaceExisting, false);
    assert.equal('agentConversationId' in (recorded.completed[0] ?? {}), false, 'no conversation, no field');
  });

  it('sends "no version" as the base when the base is unknown, so an existing file is a conflict', async () => {
    const { triggers, recorded } = fakeTriggers(() => response({ isConflict: true }));
    const actions = buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
    assert.equal((await actions.saveText?.(812, { text: 'mine', baseVersionId: null }))?.status, 'conflict');
    assert.equal(recorded.completed[0]?.expectedLatestVersionId, null);
  });

  it('saves a copy beside the file under the next free name', async () => {
    const taken = new Set(['Notes (copy).md', 'Notes (copy 2).md']);
    const { triggers, recorded } = fakeTriggers(arg => (taken.has(arg.name) ? response({ isConflict: true }) : response({ item: item({ id: 990, name: arg.name }) })));
    const actions = buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
    const copy = await actions.saveTextAsCopy?.(812, { text: 'mine', name: 'Notes (copy).md' });
    assert.equal(copy?.name, 'Notes (copy 3).md');
    assert.deepEqual(recorded.completed.map(arg => arg.name), ['Notes (copy).md', 'Notes (copy 2).md', 'Notes (copy 3).md']);
    // a copy is a new file in the same folder, never a version of a file that has the name
    assert.ok(recorded.completed.every(arg => arg.folderNodeId === 40 && arg.replaceExisting === false && arg.expectedLatestVersionId === null));
  });

  it('refuses a save of a file whose folder it does not know', async () => {
    const { triggers } = fakeTriggers(() => response());
    const actions = buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: new Map() });
    await assert.rejects(async () => actions.saveText?.(812, { text: 'x', baseVersionId: 1 }));
  });
});

describe('the PDF rendition of a document', () => {
  it('answers the content URL once it is ready', async () => {
    const { triggers, recorded } = fakeTriggers(() => response(), { state: 'READY', grant: { purpose: 'RENDITION', url: '/api/public/drive/content/t', fileName: 'letter.docx.pdf' } });
    const actions = buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
    assert.deepEqual(await actions.getDownload(812, { disposition: 'inline', variant: 'PDF_RENDITION' }), {
      url: '/api/public/drive/content/t',
      fileName: 'letter.docx.pdf',
      expiresAt: null,
      state: 'ready',
    });
    assert.deepEqual(recorded.renditionRequests, [{ driveNodeId: 812, versionId: null }]);
  });

  it('says pending and failed without a URL', async () => {
    const pending = buildCloudDriveFileActions(fakeTriggers(() => response(), { state: 'PENDING' }).triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
    assert.deepEqual(await pending.getDownload(812, { disposition: 'inline', variant: 'PDF_RENDITION' }), { url: '', fileName: '', state: 'pending', message: null });
    const failed = buildCloudDriveFileActions(fakeTriggers(() => response(), { state: 'FAILED', reason: 'the document is encrypted' }).triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
    assert.deepEqual(await failed.getDownload(812, { disposition: 'inline', variant: 'PDF_RENDITION', versionId: 5 }), { url: '', fileName: '', state: 'failed', message: 'the document is encrypted' });
  });
});

describe('the sanitised picture of an SVG', () => {
  const actionsOf = (grant: DriveContentGrant, contentRequests: unknown[] = []) => {
    const { triggers } = fakeTriggers(() => response());
    (triggers as unknown as { requestContent: (arg: unknown) => unknown }).requestContent = (arg: unknown) => {
      contentRequests.push(arg);
      return answer({ grant });
    };
    return buildCloudDriveFileActions(triggers, { spaceId: 3, rootNodeId: 1, canManage: false, nodeFacts: nodeFacts() });
  };
  const sanitisedGrant: DriveContentGrant = { purpose: 'INLINE', url: '/api/public/drive/content/s', fileName: 'logo.svg', contentType: 'image/svg+xml', isInline: true };

  it('asks for the inline variant and answers the URL of the sanitised copy', async () => {
    const contentRequests: unknown[] = [];
    assert.equal(drivePurposeOf({ disposition: 'inline', variant: 'SANITIZED_IMAGE' }), 'INLINE');
    assert.deepEqual(await actionsOf(sanitisedGrant, contentRequests).getDownload(812, { disposition: 'inline', variant: 'SANITIZED_IMAGE', versionId: 5 }), {
      url: '/api/public/drive/content/s',
      fileName: 'logo.svg',
      expiresAt: null,
      state: 'ready',
    });
    assert.deepEqual(contentRequests, [{ driveNodeId: 812, purpose: 'INLINE', versionId: 5 }]);
  });

  it('hands over no URL when the answer is not the sanitised copy', async () => {
    const noPicture = { url: '', fileName: 'logo.svg', state: 'failed', message: null };
    // no copy could be made: the server answers the uploaded bytes as an attachment
    const attachment = { ...sanitisedGrant, contentType: 'application/octet-stream', isInline: false };
    assert.deepEqual(await actionsOf(attachment).getDownload(812, { disposition: 'inline', variant: 'SANITIZED_IMAGE' }), noPicture);
    // an attachment stays one whatever type it names
    assert.deepEqual(await actionsOf({ ...sanitisedGrant, isInline: false }).getDownload(812, { disposition: 'inline', variant: 'SANITIZED_IMAGE' }), noPicture);
    // a server that does not say `isInline` (before 0.2.0) is not trusted with a picture
    const silent: DriveContentGrant = { purpose: 'INLINE', url: '/api/public/drive/content/s', fileName: 'logo.svg', contentType: 'image/svg+xml' };
    assert.deepEqual(await actionsOf(silent).getDownload(812, { disposition: 'inline', variant: 'SANITIZED_IMAGE' }), noPicture);
    // another inline type is not the sanitised copy of an SVG
    assert.deepEqual(await actionsOf({ ...sanitisedGrant, contentType: 'text/plain; charset=utf-8' }).getDownload(812, { disposition: 'inline', variant: 'SANITIZED_IMAGE' }), noPicture);
  });

  it('reads the grant strictly', () => {
    assert.equal(isSanitizedImageGrant({ isInline: true, contentType: 'image/svg+xml' }), true);
    assert.equal(isSanitizedImageGrant({ isInline: true, contentType: 'Image/SVG+XML; charset=utf-8' }), true);
    assert.equal(isSanitizedImageGrant({ isInline: true, contentType: 'image/svg+xml-evil' }), false);
    assert.equal(isSanitizedImageGrant({ isInline: true, contentType: null }), false);
    assert.equal(isSanitizedImageGrant({ isInline: false, contentType: 'image/svg+xml' }), false);
    assert.equal(isSanitizedImageGrant({ contentType: 'image/svg+xml' }), false);
  });

  it('leaves every other preview as it was', async () => {
    const png: DriveContentGrant = { purpose: 'INLINE', url: '/api/public/drive/content/p', fileName: 'photo.png', contentType: 'image/png', isInline: true };
    assert.deepEqual(await actionsOf(png).getDownload(812, { disposition: 'inline', variant: 'PREVIEW' }), { url: '/api/public/drive/content/p', fileName: 'photo.png', expiresAt: null });
  });
});

describe('names and types of saved texts', () => {
  it('numbers the copies', () => {
    assert.equal(nextDriveCopyName('Notes (copy).md', 2), 'Notes (copy 2).md');
    assert.equal(nextDriveCopyName('Notes (Kopie).md', 4), 'Notes (Kopie 4).md');
    assert.equal(nextDriveCopyName('README (copy)', 2), 'README (copy 2)');
    assert.equal(nextDriveCopyName('plain.txt', 2), 'plain.txt 2');
  });

  it('keeps a stored text type and never sends a type a browser runs', () => {
    assert.equal(driveTextContentTypeOf('a.md', 'text/markdown; charset=utf-8'), 'text/markdown');
    assert.equal(driveTextContentTypeOf('a.md', null), 'text/markdown');
    assert.equal(driveTextContentTypeOf('a.csv', 'application/octet-stream'), 'text/csv');
    assert.equal(driveTextContentTypeOf('a.json', undefined), 'application/json');
    assert.equal(driveTextContentTypeOf('a.ts', 'video/mp2t'), 'text/plain');
    assert.equal(driveTextContentTypeOf('a.php', ''), 'text/plain');
    assert.equal(driveTextContentTypeOf('page.txt', 'text/html'), 'text/plain');
    assert.equal(driveTextContentTypeOf('a.xml', 'application/xml'), 'text/plain');
  });
});
