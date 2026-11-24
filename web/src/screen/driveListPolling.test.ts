import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  DRIVE_LIST_POLL_FAST_MS,
  DRIVE_LIST_POLL_MEDIUM_MS,
  DRIVE_LIST_POLL_SLOW_MS,
  driveListPollingIntervalOf,
} from './driveListPolling.ts';

describe('clouddrive list polling while files are checked', () => {
  it('does not poll without a file being checked', () => {
    assert.equal(driveListPollingIntervalOf(null, 1_000_000), 0);
  });

  it('polls fast, then slower, then stops after a quarter of an hour', () => {
    const since = 1_000_000;
    assert.equal(driveListPollingIntervalOf(since, since), DRIVE_LIST_POLL_FAST_MS);
    assert.equal(driveListPollingIntervalOf(since, since + 59_000), DRIVE_LIST_POLL_FAST_MS);
    assert.equal(driveListPollingIntervalOf(since, since + 60_000), DRIVE_LIST_POLL_MEDIUM_MS);
    assert.equal(driveListPollingIntervalOf(since, since + 4 * 60_000), DRIVE_LIST_POLL_MEDIUM_MS);
    assert.equal(driveListPollingIntervalOf(since, since + 5 * 60_000), DRIVE_LIST_POLL_SLOW_MS);
    assert.equal(driveListPollingIntervalOf(since, since + 15 * 60_000), 0);
  });

  it('treats a clock that went back as a fresh check', () => {
    assert.equal(driveListPollingIntervalOf(2_000_000, 1_000_000), DRIVE_LIST_POLL_FAST_MS);
  });
});
