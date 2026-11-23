import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { formatDriveSize, parseDriveTime } from './upload.ts';

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
