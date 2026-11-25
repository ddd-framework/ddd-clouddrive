import { registerCanvasKind, registerConversationWidgets, registerDriveFileCanvasOpener } from '@mgamadeus/ddd-agents';
import { canvasInstanceIdForReference } from '@mgamadeus/ddd-agents/renderer';
import {
  CLOUD_DRIVE_FILE_CANVAS_ID,
  CLOUD_DRIVE_FOLDER_CANVAS_ID,
  CLOUD_DRIVE_STORE_NAME,
  cloudDriveFileCanvasReferenceOf,
  cloudDriveFolderCanvasReferenceOf,
  DRIVE_FOLDER_WIDGET_NAME,
} from './cloudDriveFileCanvas.ts';
import { type CloudDriveFileCanvasOptions, createCloudDriveFileCanvasBody } from './CloudDriveFileCanvasBody.tsx';
import { createCloudDriveFolderCanvasBody } from './CloudDriveFolderCanvasBody.tsx';
import { DriveFolderConversationWidget } from './DriveFolderConversationWidget.tsx';

export interface RegisterCloudDriveCanvasesOptions extends CloudDriveFileCanvasOptions {
  /** The store name the drive carries in `DriveFiles` blocks; default `clouddrive`. */
  store?: string;
}

// the widget registry refuses a name twice; the canvas kinds and the opener replace themselves (a hot reload calls again)
let isFolderWidgetRegistered = false;

/**
 * Opens the drive beside the agent chat. Registers
 *  - the canvas kind `cloudDriveFile`: one file in the viewer of `@mgamadeus/ddd-admin-ui/files` (preview, versions,
 *    download, "Edit" for a text file in a space the viewer may write),
 *  - the canvas kind `cloudDriveFolder`: one folder in the compact file manager (walk, open in place, and by the
 *    viewer's rights upload, new folder, rename, move, trash),
 *  - the `DriveFolder` block of `showDriveFolder` (a card that opens the folder canvas), and
 *  - the canvas opener of the drive's store, so the rows of a `DriveFiles` block open their file or folder there.
 * Both canvases are pinned for the conversation, half the split, an overlay on a narrow window. Call it once, outside
 * React, before the first chat, next to `registerDriveFileCardsSource`. The preview loaders are the drive screen's
 * (`loadPdfJs`, `loadCodeHighlighter`, `renderMarkdown`). Without this call the cards keep open-in-a-tab and download.
 */
export const registerCloudDriveCanvases = ({ store = CLOUD_DRIVE_STORE_NAME, ...options }: RegisterCloudDriveCanvasesOptions): void => {
  const behaviour = { lifetime: 'pinned', layout: 'split', widthPercent: 50, narrowViewport: 'overlay', fullBleed: false } as const;
  registerCanvasKind({
    canvasId: CLOUD_DRIVE_FILE_CANVAS_ID,
    behaviour,
    body: createCloudDriveFileCanvasBody(options),
    openedByWidgets: ['DriveFiles'],
  });
  registerCanvasKind({
    canvasId: CLOUD_DRIVE_FOLDER_CANVAS_ID,
    behaviour,
    body: createCloudDriveFolderCanvasBody(options),
    openedByWidgets: ['DriveFiles', DRIVE_FOLDER_WIDGET_NAME],
  });
  if (!isFolderWidgetRegistered) {
    registerConversationWidgets([{ name: DRIVE_FOLDER_WIDGET_NAME, component: DriveFolderConversationWidget, opensCanvases: [CLOUD_DRIVE_FOLDER_CANVAS_ID] }]);
    isFolderWidgetRegistered = true;
  }
  registerDriveFileCanvasOpener(store, card => {
    const folderReference = cloudDriveFolderCanvasReferenceOf(card);
    if (folderReference !== null) {
      return { canvasId: CLOUD_DRIVE_FOLDER_CANVAS_ID, instanceId: canvasInstanceIdForReference(CLOUD_DRIVE_FOLDER_CANVAS_ID, folderReference) };
    }
    const fileReference = cloudDriveFileCanvasReferenceOf(card);
    return fileReference === null
      ? null
      : { canvasId: CLOUD_DRIVE_FILE_CANVAS_ID, instanceId: canvasInstanceIdForReference(CLOUD_DRIVE_FILE_CANVAS_ID, fileReference) };
  });
};
