// `@mgamadeus/ddd-clouddrive/screen`: the drive screen on the endpoints of `createCloudDriveApi` — the file manager of
// `@mgamadeus/ddd-admin-ui/files` bound to the drive (`useCloudDriveFileAdapter`), the space picker, the direct upload
// to the store, the file actions and the mapping of the drive's payloads onto the file manager's views.
export { CLOUD_DRIVE_NODE_KEY, CLOUD_DRIVE_URL_KEYS, CloudDriveScreen } from './CloudDriveScreen.tsx';
export type { CloudDriveFolderStep, CloudDriveScreenProps } from './CloudDriveScreen.tsx';
export { CloudDriveSpacePicker } from './CloudDriveSpacePicker.tsx';
export type { CloudDriveSpacePickerProps } from './CloudDriveSpacePicker.tsx';
export {
  CLOUD_DRIVE_FILE_CAPABILITIES,
  createCloudDriveFileAdapter,
  DRIVE_ACTIVITY_LIMIT,
  useCloudDriveFileAdapter,
} from './useCloudDriveFileAdapter.ts';
export type { UseCloudDriveFileAdapterOptions } from './useCloudDriveFileAdapter.ts';
export { buildCloudDriveFileActions, drivePurposeOf } from './driveFileActions.ts';
export {
  DRIVE_EMPTY_TRASH_MAX_CALLS,
  DRIVE_EVENT_LABELS,
  DRIVE_LIST_MAX_LIMIT,
  DRIVE_LIST_PAGE_SIZE,
  driveAreaRefOf,
  driveAreaViewOf,
  driveChildrenArgOf,
  driveEventViewOf,
  driveFamilyOf,
  driveFileItemsOf,
  driveFileViewOf,
  driveFolderIdOf,
  driveFolderViewsOf,
  driveLocationMissOf,
  driveLocationResolutionOf,
  driveScanStatusOf,
  driveVersionNoteOf,
  driveVersionViewOf,
  DRIVE_USAGE_PART_LABELS,
  effectiveQuotaOf,
  driveUsagePartsOf,
  emptyDriveTrashFully,
  groupDriveSpaces,
  nextDriveListLimit,
} from './driveFileMapping.ts';
export type { DriveEmptyTrashOutcome, DriveSpaceGroup } from './driveFileMapping.ts';
export { formatDriveSize, parseDriveTime, sendDriveUpload } from './upload.ts';
export type { DriveUploadProgress } from './upload.ts';
