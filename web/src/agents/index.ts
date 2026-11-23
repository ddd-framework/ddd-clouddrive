// `@mgamadeus/ddd-clouddrive/agents`: the cloud drive behind the `DriveFiles` cards of `@mgamadeus/ddd-agents`
// (optional peer): `createCloudDriveCardsSource` for `registerDriveFileCardsSource`, and the drive screen link.
export { createCloudDriveCardsSource } from './cloudDriveCardsSource.ts';
export type { CloudDriveCardsDispatch, CloudDriveCardsSource, CreateCloudDriveCardsSourceOptions } from './cloudDriveCardsSource.ts';
export { buildDriveScreenHref, nodeIdOfDriveReference } from './driveScreenHref.ts';
export type { DriveScreenPlace } from './driveScreenHref.ts';
