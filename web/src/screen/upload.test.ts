import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { driveUploadIdempotencyKeyOf, formatDriveSize, parseDriveTime, sendDriveUpload } from './upload.ts';

describe('clouddrive upload helpers', () => {
  it('reads an offsetless backend time as UTC and keeps an offset', () => {
    assert.equal(parseDriveTime('2026-11-23 01:12:00')?.toISOString(), '2026-11-23T01:12:00.000Z');
    assert.equal(parseDriveTime('2026-11-23T02:12:00+01:00')?.toISOString(), '2026-11-23T01:12:00.000Z');
    assert.equal(parseDriveTime(null), null);
    assert.equal(parseDriveTime('not a time'), null);
  });

  it('formats sizes', () => {
    assert.equal(formatDriveSize(512), '512 B');
    assert.equal(formatDriveSize(34816), '34.0 KB');
    assert.equal(formatDriveSize(undefined), '');
  });
});

describe('clouddrive upload to the backend', () => {
  const recordingSender = (failuresOfPart: Record<number, number> = {}) => {
    const sent: string[] = [];
    const sender = {
      putContent: async (driveBlobId: number, body: Blob) => {
        sent.push(`content:${driveBlobId}:${body.size}:${body.type}`);
      },
      putPart: async (driveBlobId: number, partNumber: number, body: Blob) => {
        if ((failuresOfPart[partNumber] ?? 0) > 0) {
          failuresOfPart[partNumber] -= 1;
          sent.push(`stalled:${partNumber}`);
          throw Object.assign(new Error('Gateway Timeout'), { status: 504 });
        }
        sent.push(`part:${partNumber}:${body.size}:${body.type}`);
      },
    };
    return { sent, sender };
  };

  it('sends a small file in one request as octet-stream', async () => {
    const { sent, sender } = recordingSender();
    await sendDriveUpload({ driveBlobId: 5, sizeBytes: 3, isMultipart: false }, new Blob(['abc'], { type: 'text/plain' }), sender);
    assert.deepEqual(sent, ['content:5:3:application/octet-stream']);
  });

  it('resumes a multipart upload: skips stored parts, retries a stalled part, reports progress', async () => {
    const { sent, sender } = recordingSender({ 2: 1 });
    const progress: number[] = [];
    await sendDriveUpload(
      { driveBlobId: 6, sizeBytes: 10, isMultipart: true, partSizeBytes: 4, partCount: 3, uploadedPartNumbers: [1] },
      new Blob(['0123456789']),
      sender,
      sentBytes => progress.push(sentBytes)
    );
    assert.deepEqual(sent, ['stalled:2', 'part:2:4:application/octet-stream', 'part:3:2:application/octet-stream']);
    assert.deepEqual(progress, [4, 8, 10]);
  });

  it('does not retry a refusal of the backend', async () => {
    const sender = {
      putContent: async () => undefined,
      putPart: async () => { throw Object.assign(new Error('Bad Request'), { status: 400 }); },
    };
    await assert.rejects(sendDriveUpload({ driveBlobId: 7, sizeBytes: 4, isMultipart: true, partSizeBytes: 4, partCount: 1 }, new Blob(['0123']), sender));
  });

  it('keys one file into one folder stably, at most 128 characters', () => {
    const identity = { driveSpaceId: 7, folderNodeId: 12, name: 'Report.pdf', sizeBytes: 50_000_000, lastModified: 1_763_900_000_000 };
    const key = driveUploadIdempotencyKeyOf(identity);
    assert.equal(driveUploadIdempotencyKeyOf({ ...identity }), key);
    assert.notEqual(driveUploadIdempotencyKeyOf({ ...identity, folderNodeId: null }), key);
    assert.notEqual(driveUploadIdempotencyKeyOf({ ...identity, name: 'Report (2).pdf' }), key);
    assert.notEqual(driveUploadIdempotencyKeyOf({ ...identity, lastModified: identity.lastModified + 1 }), key);
    assert.notEqual(driveUploadIdempotencyKeyOf({ ...identity, driveSpaceId: 8 }), key);
    assert.ok(driveUploadIdempotencyKeyOf({ ...identity, name: 'x'.repeat(1000) }).length <= 128);
  });
});
