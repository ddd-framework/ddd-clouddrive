import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createElement } from 'react';
// biome-ignore lint/style/noRestrictedImports: a test renders on the server to run the hooks once; tests are not shipped
import { renderToString } from 'react-dom/server';

import type { FileManagerAdapter, FileQueryOptions } from '@mgamadeus/ddd-admin-ui/files';
import { FILE_CHECK_POLL_INTERVAL_MS, isFileBeingChecked } from '@mgamadeus/ddd-admin-ui/files';
import type { ApiQueryOptions } from '@mgamadeus/ddd-admin-ui/logic/pure';
import { type CloudDriveApi, createStaticCloudDriveApi } from '../api.ts';
import { createCloudDriveFileAdapter } from './useCloudDriveFileAdapter.ts';

/** A static drive api that records the options the node and versions queries were called with. */
const recordingApi = (): { api: CloudDriveApi; nodeOptions: ApiQueryOptions[]; versionsOptions: ApiQueryOptions[] } => {
  const staticApi = createStaticCloudDriveApi({ spaces: { driveSpaces: { elements: [] }, items: { elements: [] } } as never });
  const nodeOptions: ApiQueryOptions[] = [];
  const versionsOptions: ApiQueryOptions[] = [];
  const api: CloudDriveApi = {
    ...staticApi,
    useDriveNodeQuery: (arg, options) => {
      nodeOptions.push(options ?? {});
      return staticApi.useDriveNodeQuery(arg, options);
    },
    useDriveVersionsQuery: (arg, options) => {
      versionsOptions.push(options ?? {});
      return staticApi.useDriveVersionsQuery(arg, options);
    },
  };
  return { api, nodeOptions, versionsOptions };
};

const FileProbe = ({ adapter, fileId, options }: { adapter: FileManagerAdapter; fileId: number | null; options?: FileQueryOptions }) => {
  adapter.useFile(fileId, options);
  return null;
};

describe('the drawer polls a file while a version is checked', () => {
  it('tells a file in a check from a settled one', () => {
    assert.equal(isFileBeingChecked({ scanStatus: 'PENDING' }), true);
    assert.equal(isFileBeingChecked({ scanStatus: 'UPLOADING' }), true);
    assert.equal(isFileBeingChecked({ scanStatus: 'CLEAN', hasPendingVersion: true }), true);
    assert.equal(isFileBeingChecked({ scanStatus: 'CLEAN', newestVersionStatus: 'PENDING' }), true);
    assert.equal(isFileBeingChecked({ scanStatus: 'CLEAN', newestVersionStatus: 'FAILED' }), false, 'a failed check is final');
    assert.equal(isFileBeingChecked({ scanStatus: 'CLEAN', newestVersionStatus: 'BLOCKED' }), false);
    assert.equal(isFileBeingChecked({ scanStatus: 'CLEAN' }), false);
    assert.equal(isFileBeingChecked({ scanStatus: 'INFECTED' }), false);
  });

  it('hands the polling interval to the node and versions queries of the drive', () => {
    const { api, nodeOptions, versionsOptions } = recordingApi();
    const adapter = createCloudDriveFileAdapter(api, 1);
    renderToString(createElement(FileProbe, { adapter, fileId: 7, options: { pollingIntervalMs: FILE_CHECK_POLL_INTERVAL_MS } }));
    assert.equal(nodeOptions.at(-1)?.pollingInterval, FILE_CHECK_POLL_INTERVAL_MS);
    assert.equal(versionsOptions.at(-1)?.pollingInterval, FILE_CHECK_POLL_INTERVAL_MS);
  });

  it('does not poll without the option or without a file', () => {
    const { api, nodeOptions } = recordingApi();
    const adapter = createCloudDriveFileAdapter(api, 1);
    renderToString(createElement(FileProbe, { adapter, fileId: 7 }));
    assert.equal(nodeOptions.at(-1)?.pollingInterval, 0);
    renderToString(createElement(FileProbe, { adapter, fileId: null, options: { pollingIntervalMs: FILE_CHECK_POLL_INTERVAL_MS } }));
    assert.equal(nodeOptions.at(-1)?.pollingInterval, 0, 'a skipped query never polls');
    assert.equal(nodeOptions.at(-1)?.skip, true);
  });
});
