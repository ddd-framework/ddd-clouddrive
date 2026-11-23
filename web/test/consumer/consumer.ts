// A strict TypeScript consumer of every entry: compiles only if the published declarations are complete.
import { registerDriveFileCardsSource } from '@mgamadeus/ddd-agents';
import { type CloudDriveApi, createCloudDriveApi, type DriveFileCard } from '@mgamadeus/ddd-clouddrive';
import { buildDriveScreenHref, type CloudDriveCardsSource, createCloudDriveCardsSource } from '@mgamadeus/ddd-clouddrive/agents';
import { CLOUD_DRIVE_URL_KEYS, CloudDriveScreen, type CloudDriveScreenProps, useCloudDriveFileAdapter } from '@mgamadeus/ddd-clouddrive/screen';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const baseApi = createApi({
  reducerPath: 'api',
  baseQuery: fetchBaseQuery({ baseUrl: '' }),
  tagTypes: ['Other'],
  endpoints: () => ({}),
});

export const cloudDriveApi: CloudDriveApi = createCloudDriveApi({ baseApi, apiPrefix: '/api/admin/common/cloudDrive' });

export const cardsSource: CloudDriveCardsSource = createCloudDriveCardsSource({
  baseApi,
  apiPrefix: '/api/admin/common/cloudDrive',
  screenRoute: '/admin/drive',
});
registerDriveFileCardsSource(cardsSource);

export const screenProps: CloudDriveScreenProps = { api: cloudDriveApi };
export const screen = CloudDriveScreen;
export const adapterHook = useCloudDriveFileAdapter;
export const spaceKey: string = CLOUD_DRIVE_URL_KEYS.area;
export const href: string = buildDriveScreenHref({ space: 1, path: '/out' }, '/admin/drive');
export const card: DriveFileCard | null = null;
