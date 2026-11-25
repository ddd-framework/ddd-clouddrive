// `@mgamadeus/ddd-clouddrive/canvas`: the drive in the canvas beside the agent chat of `@mgamadeus/ddd-agents`
// (≥ 0.3.14) — `registerCloudDriveCanvases` opens the files and folders of `DriveFiles` blocks and the `DriveFolder`
// block in the file viewer and the compact file manager of `@mgamadeus/ddd-admin-ui/files`. Loads the screen's adapter
// and `@mgamadeus/ddd-agents` at runtime (the entry `./agents` loads neither).
export {
  CLOUD_DRIVE_FILE_CANVAS_ID,
  CLOUD_DRIVE_FOLDER_CANVAS_ID,
  CLOUD_DRIVE_STORE_NAME,
  cloudDriveFileCanvasReferenceOf,
  cloudDriveFolderCanvasReferenceOf,
  DRIVE_FOLDER_WIDGET_NAME,
  driveNodeIdOfCanvasReference,
  parseDriveFolderWidgetPayload,
} from './cloudDriveFileCanvas.ts';
export type { DriveFolderWidgetFields } from './cloudDriveFileCanvas.ts';
export { CloudDriveFileCanvas, createCloudDriveFileCanvasBody } from './CloudDriveFileCanvasBody.tsx';
export type { CloudDriveFileCanvasOptions } from './CloudDriveFileCanvasBody.tsx';
export { CloudDriveFolderCanvas, createCloudDriveFolderCanvasBody } from './CloudDriveFolderCanvasBody.tsx';
export type { CloudDriveFolderCanvasOptions } from './CloudDriveFolderCanvasBody.tsx';
export { DriveFolderConversationWidget } from './DriveFolderConversationWidget.tsx';
export { registerCloudDriveCanvases } from './registerCloudDriveCanvases.ts';
export type { RegisterCloudDriveCanvasesOptions } from './registerCloudDriveCanvases.ts';
