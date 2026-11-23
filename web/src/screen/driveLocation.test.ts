import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { resolveFileManagerFeatures } from '@mgamadeus/ddd-admin-ui/files';
import { drivePurposeOf } from './driveFileActions.ts';
import { driveEventViewOf, driveLocationMissOf, driveLocationResolutionOf } from './driveFileMapping.ts';
import type { DriveNodeListItem } from '../types.ts';

const ROOT = 10;
const node = (overrides: Partial<DriveNodeListItem>): DriveNodeListItem => ({
  id: 41,
  driveSpaceId: 3,
  parentNodeId: 12,
  nodeType: 'FILE',
  name: 'letter.typ',
  ...overrides,
});
const ancestors = { elements: [node({ id: ROOT, parentNodeId: null, nodeType: 'FOLDER', name: '' }), node({ id: 12, parentNodeId: ROOT, nodeType: 'FOLDER', name: 'Typst' })] };

describe('drive locations (ddd-clouddrive rc.14 resolve)', () => {
  it('opens a file in its folder with its details', () => {
    assert.deepEqual(driveLocationResolutionOf({ driveSpaceId: 3, item: node({}), ancestors, path: 'Typst/letter.typ' }), {
      found: true,
      areaKey: 3,
      folderId: 12,
      fileId: 41,
      path: '/Typst/letter.typ',
    });
  });

  it('opens a folder, the root as null, in the space the answer names', () => {
    assert.deepEqual(
      driveLocationResolutionOf({ driveSpaceId: 5, item: node({ id: 12, parentNodeId: ROOT, nodeType: 'FOLDER', name: 'Typst' }), ancestors, path: 'Typst' }),
      { found: true, areaKey: 5, folderId: 12, fileId: null, path: '/Typst' }
    );
    assert.equal(driveLocationResolutionOf({ driveSpaceId: 3, item: node({ id: ROOT, parentNodeId: null, nodeType: 'FOLDER' }), path: '' }).folderId, null);
  });

  it('turns the 404 body into a miss, other errors stay errors', () => {
    assert.deepEqual(
      driveLocationMissOf({ status: 404, data: { error: 'Not found', deepestFolder: node({ id: 12, nodeType: 'FOLDER', name: 'Typst' }), missingSegment: 'x.pdf' } }),
      { found: false, deepestFolderPath: 'Typst', missingSegment: 'x.pdf', message: 'Not found' }
    );
    assert.equal(driveLocationMissOf({ status: 500 }), null);
  });

  it('asks a thumbnail, a preview and a download for what they are (only previews and downloads are recorded)', () => {
    assert.equal(drivePurposeOf({ disposition: 'inline', variant: 'THUMBNAIL' }), 'THUMBNAIL');
    assert.equal(drivePurposeOf({ disposition: 'attachment', variant: 'PREVIEW' }), 'INLINE');
    assert.equal(drivePurposeOf({ disposition: 'inline' }), 'INLINE');
    assert.equal(drivePurposeOf({ disposition: 'attachment' }), 'DOWNLOAD');
    assert.equal(drivePurposeOf({ disposition: 'attachment', variant: 'ORIGINAL' }), 'DOWNLOAD');
  });

  it('maps an access with its audience, variant, address and on-behalf name', () => {
    const access = driveEventViewOf({
      id: 7,
      eventType: 'DOWNLOADED',
      eventSource: 'ACTIVITY',
      actorName: 'Ann',
      onBehalfOfName: 'Bob',
      audience: 'INTERNAL',
      contentVariant: 'DOWNLOAD',
      clientIp: '203.0.113.9',
    });
    assert.deepEqual(
      { id: access.id, impersonatedBy: access.impersonatedBy, audience: access.audience, variant: access.variant, clientIp: access.clientIp },
      { id: 'ACTIVITY-7', impersonatedBy: 'Bob', audience: 'INTERNAL', variant: 'DOWNLOAD', clientIp: '203.0.113.9' }
    );
  });

  it('hides the staff-only trash controls from a partner', () => {
    const actions = { restoreFiles: async () => undefined, purgeFiles: async () => undefined, emptyTrash: async () => undefined };
    const trash = { useTrash: () => { throw new Error('unused'); } };
    const staff = resolveFileManagerFeatures({ trash }, actions);
    const partner = resolveFileManagerFeatures({ trash, audience: 'partner' }, actions);
    assert.deepEqual([staff.purge, staff.emptyTrash, staff.partner], [true, true, false]);
    assert.deepEqual([partner.restore, partner.purge, partner.emptyTrash, partner.partner], [true, false, false, true]);
  });
});
