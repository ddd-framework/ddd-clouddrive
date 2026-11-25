'use client';

import { ExternalLink } from 'lucide-react';
import { type ComponentType, useMemo } from 'react';

import { type CanvasBodyProps, referenceOfCanvasInstanceId, useConversationWidgetContext } from '@mgamadeus/ddd-agents/renderer';
import { FileFamilyIcon, FileManager, type FilePreviewLoaders } from '@mgamadeus/ddd-admin-ui/files';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { Button, Skeleton, useLinkComponent } from '@mgamadeus/ddd-admin-ui/primitives';
import type { CloudDriveApi } from '../api.ts';
import { buildDriveScreenHref } from '../agents/driveScreenHref.ts';
import { DRIVE_EVENT_LABELS, driveAreaRefOf, driveFolderIdOf } from '../screen/driveFileMapping.ts';
import { useCloudDriveFileAdapter } from '../screen/useCloudDriveFileAdapter.ts';
import { driveNodeIdOfCanvasReference } from './cloudDriveFileCanvas.ts';

export interface CloudDriveFolderCanvasOptions extends FilePreviewLoaders {
  /** `createCloudDriveApi(…)` of the host: the canvas reads and writes the folder through it. */
  api: CloudDriveApi;
  /** The route the host mounted the drive screen at, for "Show in the drive" (default `/drive`); `null` shows no such link. */
  screenRoute?: string | null;
}

interface CloudDriveFolderManagerProps extends CloudDriveFolderCanvasOptions {
  spaceId: number;
  /** The folder the canvas starts in (a node of the space; the space's root folder starts at the root). */
  folderNodeId: number;
}

/**
 * The file manager of one space in its compact form, started in the folder the canvas names. The viewer's rights on
 * the space decide what it offers (`canWrite`; purge and empty trash never appear here: the compact manager shows no
 * trash); the server checks every write again.
 */
const CloudDriveFolderManager = ({ api, spaceId, folderNodeId, screenRoute = '/drive', loadPdfJs, loadCodeHighlighter, renderMarkdown }: CloudDriveFolderManagerProps) => {
  const { t } = useTranslate();
  // the host's router link (`AdminUiProvider.linkComponent`): the route is given without the app's base path
  const LinkComponent = useLinkComponent();
  const { conversationId } = useConversationWidgetContext();
  const adapter = useCloudDriveFileAdapter({ api, spaceId, agentConversationId: conversationId ?? null });
  const spaceQuery = api.useDriveSpaceQuery({ driveSpaceId: spaceId });
  const spaceItem = spaceQuery.data?.item?.id === spaceId ? spaceQuery.data.item : null;
  const areas = useMemo(() => (spaceItem ? [driveAreaRefOf(spaceItem)] : []), [spaceItem]);
  // read once by the manager: the folder it opens in (the root folder is "no folder")
  const initialState = useMemo((): Record<string, string> => {
    const folderId = spaceItem ? driveFolderIdOf(folderNodeId, spaceItem.rootNodeId) : null;
    return folderId === null ? {} : { folder: String(folderId) };
  }, [spaceItem, folderNodeId]);

  if (spaceItem === null) {
    if (spaceQuery.isError) return <CloudDriveFolderMissing />;
    return (
      <div className="flex min-h-0 flex-1 flex-col gap-3" aria-busy>
        <Skeleton className="h-9 w-2/3" />
        <Skeleton className="min-h-56 w-full flex-1 rounded-xl" />
      </div>
    );
  }
  return (
    <FileManager
      // another folder pointer is another manager: its start folder is read once
      key={`${spaceId}:${folderNodeId}`}
      adapter={adapter}
      variant="compact"
      areas={areas}
      areaKey={spaceId}
      canWrite={spaceItem.canWrite === true}
      initialState={initialState}
      defaultView="table"
      loadPdfJs={loadPdfJs}
      loadCodeHighlighter={loadCodeHighlighter}
      renderMarkdown={renderMarkdown}
      formatEventType={type => (DRIVE_EVENT_LABELS[type] ? t(DRIVE_EVENT_LABELS[type]) : null)}
      readOnlyNotice={t('You may read this space, but not change it.')}
      headerActions={
        screenRoute === null ? undefined : (
          <Button asChild variant="ghost" size="sm">
            <LinkComponent href={buildDriveScreenHref({ node: folderNodeId }, screenRoute)}>
              <ExternalLink />
              {t('Show in the drive')}
            </LinkComponent>
          </Button>
        )
      }
    />
  );
};

const CloudDriveFolderMissing = () => {
  const { t } = useTranslate();
  return (
    <div className="flex flex-col items-center gap-3 rounded-xl bg-tint px-6 py-8 text-center">
      <FileFamilyIcon family="OTHER" size="lg" />
      <p className="max-w-sm text-[13px] text-muted-foreground">{t('This folder is not available. It was removed, or you may not open it.')}</p>
    </div>
  );
};

interface CloudDriveFolderCanvasProps extends CloudDriveFolderCanvasOptions {
  /** The node the canvas pointer names; `null` for a pointer that names none. */
  driveNodeId: number | null;
}

/**
 * One drive folder in the canvas: the node is read under the viewer's rights (its space decides the adapter), then
 * the compact file manager of `@mgamadeus/ddd-admin-ui/files` shows the folder — walk into folders and back, open a
 * file in place, download, and by the viewer's rights upload, new folder, rename, move and trash. A node that is
 * gone, not readable or no folder says so; nothing is shown from the pointer itself.
 */
export const CloudDriveFolderCanvas = ({ driveNodeId, ...options }: CloudDriveFolderCanvasProps) => {
  const nodeQuery = options.api.useDriveNodeQuery({ driveNodeId: driveNodeId ?? 0 }, { skip: driveNodeId === null });
  const item = nodeQuery.data?.item ?? null;
  const isThisFolder = item !== null && item.id === driveNodeId && item.nodeType === 'FOLDER';

  return (
    <div data-slot="cloud-drive-folder-canvas" className="flex h-full min-h-0 flex-col px-3 pb-3">
      {driveNodeId !== null && isThisFolder ? (
        <CloudDriveFolderManager {...options} spaceId={item.driveSpaceId} folderNodeId={driveNodeId} />
      ) : driveNodeId !== null && !nodeQuery.isError && (item === null || item.id !== driveNodeId) ? (
        <div className="flex min-h-0 flex-1 flex-col gap-3" aria-busy>
          <Skeleton className="h-9 w-2/3" />
          <Skeleton className="min-h-56 w-full flex-1 rounded-xl" />
        </div>
      ) : (
        <CloudDriveFolderMissing />
      )}
    </div>
  );
};

/**
 * The canvas body of `registerCanvasKind` for the folder canvas: the pointer's instance id carries the folder's
 * reference (`canvasInstanceIdForReference`), nothing else.
 */
export const createCloudDriveFolderCanvasBody = (options: CloudDriveFolderCanvasOptions): ComponentType<CanvasBodyProps> => {
  const CloudDriveFolderCanvasBody = ({ canvas }: CanvasBodyProps) => (
    <CloudDriveFolderCanvas {...options} driveNodeId={driveNodeIdOfCanvasReference(referenceOfCanvasInstanceId(canvas.instanceId))} />
  );
  return CloudDriveFolderCanvasBody;
};
