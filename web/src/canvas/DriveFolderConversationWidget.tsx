'use client';

import { canvasInstanceIdForReference, messageAnchor, type PayloadConversationWidgetProps } from '@mgamadeus/ddd-agents/renderer';
import { CanvasLauncherWidget } from '@mgamadeus/ddd-agents/widgets';
import { CLOUD_DRIVE_FOLDER_CANVAS_ID, parseDriveFolderWidgetPayload } from './cloudDriveFileCanvas.ts';

/** The folder of the card's badge (the drawing of the `DriveFiles` rows of ddd-agents). */
const FolderGlyph = () => (
  <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" data-slot="drive-folder-glyph">
    <path
      fill="currentColor"
      d="M3 6.5A2.5 2.5 0 0 1 5.5 4h3.6c.6 0 1.2.24 1.65.66L12.2 6H18.5A2.5 2.5 0 0 1 21 8.5v9A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
    />
  </svg>
);

/**
 * The `DriveFolder` block of the agent (`showDriveFolder`): a card with the folder's name and place that opens the
 * folder canvas — once by itself when the message was just delivered, afterwards on a click. The block names a node;
 * what the canvas shows is read under the viewer's rights. A block that names no node draws nothing.
 */
export const DriveFolderConversationWidget = ({ payload, justDelivered = false, messageId }: PayloadConversationWidgetProps) => {
  const folder = parseDriveFolderWidgetPayload(payload);
  if (folder === null) return null;
  const numericMessageId = messageId === undefined ? Number.NaN : Number(messageId);
  const canvas = Number.isFinite(numericMessageId)
    ? {
        canvasId: CLOUD_DRIVE_FOLDER_CANVAS_ID,
        instanceId: canvasInstanceIdForReference(CLOUD_DRIVE_FOLDER_CANVAS_ID, folder.reference),
        anchor: messageAnchor(numericMessageId),
      }
    : undefined;
  return (
    <CanvasLauncherWidget
      title={folder.title}
      translateTitle={false}
      subtitle={folder.location ?? undefined}
      translateSubtitle={false}
      leading={<FolderGlyph />}
      justDelivered={justDelivered}
      canvas={canvas}
      testId="drive-folder-widget"
    />
  );
};
