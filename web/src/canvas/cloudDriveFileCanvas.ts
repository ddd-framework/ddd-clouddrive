// The file and folder canvases of the cloud drive, the pure part: which `DriveFiles` entry opens which canvas, which
// node a reference names and what a `DriveFolder` block says. No React and no runtime import of `@mgamadeus/ddd-agents` (the body is `CloudDriveFileCanvasBody.tsx`, the
// registration `registerCloudDriveCanvases.ts`), so the tests run without a DOM.
import type { DriveFileCard } from '@mgamadeus/ddd-agents';
import { extensionOf, fileFamilyOfContentType, fileFamilyOfExtension, filePreviewKind } from '@mgamadeus/ddd-admin-ui/files';
import { nodeIdOfDriveReference } from '../agents/driveScreenHref.ts';

/** The canvas that shows one drive file beside the chat. Append-only: stored canvas pointers name it. */
export const CLOUD_DRIVE_FILE_CANVAS_ID = 'cloudDriveFile';

/** The store name of the cloud drive in a `DriveFiles` block (`CloudDriveAgentDriveStoreService::STORE_NAME`). */
export const CLOUD_DRIVE_STORE_NAME = 'clouddrive';

/**
 * The reference the file canvas opens for one `DriveFiles` entry: a file the viewer of
 * `@mgamadeus/ddd-admin-ui/files` previews (a picture, a PDF, Markdown, source code, text, CSV, video, sound). A
 * folder, a file without a preview (an office file the server neither renders nor pictures, an archive) and a
 * reference of another shape answer `null`: they keep the tab, the PDF twin and the download. An office file the
 * server renders as a PDF (`hasPdfRendition`) opens.
 */
export const cloudDriveFileCanvasReferenceOf = (
  card: Pick<DriveFileCard, 'reference' | 'name' | 'isFolder' | 'contentType' | 'thumbnailUrl'> & { hasPdfRendition?: boolean | null }
): string | null => {
  const reference = card.reference.trim();
  if (card.isFolder || nodeIdOfDriveReference(reference) === null) return null;
  const extension = extensionOf(card.name ?? '');
  const familyOfExtension = fileFamilyOfExtension(extension);
  const family = familyOfExtension === 'OTHER' ? (fileFamilyOfContentType(card.contentType) ?? 'OTHER') : familyOfExtension;
  return filePreviewKind({ family, extension, thumbnailUrl: card.thumbnailUrl ?? null, hasPdfRendition: card.hasPdfRendition === true }) === 'none' ? null : reference;
};

/** The canvas that shows one drive folder beside the chat (a small file manager). Append-only: stored pointers name it. */
export const CLOUD_DRIVE_FOLDER_CANVAS_ID = 'cloudDriveFolder';

/** The wire name of the folder widget (`DriveFolderConversationWidget::WIDGET_TYPE`). */
export const DRIVE_FOLDER_WIDGET_NAME = 'DriveFolder';

/** The reference the folder canvas opens for one `DriveFiles` entry: a folder of the drive; everything else `null`. */
export const cloudDriveFolderCanvasReferenceOf = (card: Pick<DriveFileCard, 'reference' | 'isFolder'>): string | null => {
  const reference = card.reference.trim();
  return card.isFolder === true && nodeIdOfDriveReference(reference) !== null ? reference : null;
};

/** What a `DriveFolder` block says: the folder (a node of the drive) and the texts of its card. */
export interface DriveFolderWidgetFields {
  /** `drive/<nodeId>` of the folder (a space's root folder is a node too). */
  reference: string;
  driveNodeId: number;
  /** The card's headline: the agent's title, else the folder's name. */
  title: string;
  /** The space and the folder's path in it, for the card's second line; `null` when the block carries none. */
  location: string | null;
}

const MAX_FOLDER_TEXT_LENGTH = 200;

const textOf = (value: unknown): string => (typeof value === 'string' ? value.trim().slice(0, MAX_FOLDER_TEXT_LENGTH) : '');

/**
 * Reads a `DriveFolder` block (JSON fields `driveNodeId`, `name`, `title`, `path`, `spaceTitle`). Text of a model:
 * anything that is no object with a positive node id answers `null`, and every text is cut.
 */
export const parseDriveFolderWidgetPayload = (payload: string | null | undefined): DriveFolderWidgetFields | null => {
  let fields: unknown;
  try {
    fields = JSON.parse(payload ?? '');
  } catch {
    return null;
  }
  if (fields === null || typeof fields !== 'object' || Array.isArray(fields)) return null;
  const record = fields as Record<string, unknown>;
  const driveNodeId = Number(record.driveNodeId);
  if (!Number.isSafeInteger(driveNodeId) || driveNodeId <= 0) return null;
  const path = textOf(record.path);
  const spaceTitle = textOf(record.spaceTitle);
  const name = textOf(record.name) || path.split('/').filter(Boolean).pop() || spaceTitle;
  const location = [spaceTitle, path === '/' ? '' : path].filter(Boolean).join(' · ');
  return {
    reference: `drive/${driveNodeId}`,
    driveNodeId,
    title: textOf(record.title) || name,
    location: location === '' ? null : location,
  };
};

/**
 * The drive node a reference names (`drive/812` → 812), or `null` for anything else (no reference, another shape, an
 * id that is no positive integer). The id only says what to ask for: the server answers the node under the viewer's
 * rights.
 */
export const driveNodeIdOfCanvasReference = (reference: string | null | undefined): number | null => {
  const nodeId = reference === null || reference === undefined ? null : nodeIdOfDriveReference(reference);
  const driveNodeId = nodeId === null ? Number.NaN : Number(nodeId);
  return Number.isSafeInteger(driveNodeId) && driveNodeId > 0 ? driveNodeId : null;
};
