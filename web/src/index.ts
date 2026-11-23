// `@mgamadeus/ddd-clouddrive`: the API client of the cloud drive routes (`CloudDriveControllerTrait` of the Composer
// package mgamadeus/ddd-clouddrive) on the host's RTK Query api, and the payload types. The drive screen is
// `./screen`, the source of the agents' `DriveFiles` cards `./agents`.
export {
  buildCloudDriveRequests,
  CLOUD_DRIVE_TAG,
  cloudDriveEndpointDefinitions,
  createCloudDriveApi,
  createStaticCloudDriveApi,
} from './api.ts';
export type { CloudDriveApi, CreateCloudDriveApiOptions, StaticCloudDriveData } from './api.ts';
export type * from './types.ts';
