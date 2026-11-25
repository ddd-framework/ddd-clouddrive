'use client';

import { ExternalLink } from 'lucide-react';
import type { ComponentType } from 'react';

import { type CanvasBodyProps, referenceOfCanvasInstanceId, useConversationWidgetContext } from '@mgamadeus/ddd-agents/renderer';
import { FileFamilyIcon, type FilePreviewLoaders, FileViewer } from '@mgamadeus/ddd-admin-ui/files';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { Button, Skeleton, useLinkComponent } from '@mgamadeus/ddd-admin-ui/primitives';
import type { CloudDriveApi } from '../api.ts';
import { useCloudDriveFileAdapter } from '../screen/useCloudDriveFileAdapter.ts';
import { driveNodeIdOfCanvasReference } from './cloudDriveFileCanvas.ts';
import { buildDriveScreenHref } from '../agents/driveScreenHref.ts';

export interface CloudDriveFileCanvasOptions extends FilePreviewLoaders {
  /** `createCloudDriveApi(…)` of the host: the canvas reads the file, its versions and its content through it. */
  api: CloudDriveApi;
  /**
   * The route the host mounted the drive screen at, for "Show in the drive" (default `/drive`); `null` shows no such
   * link.
   */
  screenRoute?: string | null;
}

interface CloudDriveFileViewerProps extends CloudDriveFileCanvasOptions {
  spaceId: number;
  driveNodeId: number;
}

/** The viewer once the file's space is known: the file manager's adapter of that space feeds `FileViewer`. */
const CloudDriveFileViewer = ({ api, spaceId, driveNodeId, screenRoute = '/drive', loadPdfJs, loadCodeHighlighter, renderMarkdown }: CloudDriveFileViewerProps) => {
  const { t } = useTranslate();
  // the host's router link (`AdminUiProvider.linkComponent`): the route is given without the app's base path
  const LinkComponent = useLinkComponent();
  // a text saved here is noted in the conversation the canvas belongs to (the server writes the note)
  const { conversationId } = useConversationWidgetContext();
  const adapter = useCloudDriveFileAdapter({ api, spaceId, agentConversationId: conversationId ?? null });
  // the viewer's right on the file's space decides whether a text file offers "Edit"; the server checks it again
  const spaceQuery = api.useDriveSpaceQuery({ driveSpaceId: spaceId });
  const canWrite = spaceQuery.data?.item?.id === spaceId && spaceQuery.data.item.canWrite === true;
  return (
    <FileViewer
      adapter={adapter}
      fileId={driveNodeId}
      canWrite={canWrite}
      loadPdfJs={loadPdfJs}
      loadCodeHighlighter={loadCodeHighlighter}
      renderMarkdown={renderMarkdown}
      actions={
        screenRoute === null ? undefined : (
          <Button asChild variant="ghost" size="sm">
            <LinkComponent href={buildDriveScreenHref({ node: driveNodeId }, screenRoute)}>
              <ExternalLink />
              {t('Show in the drive')}
            </LinkComponent>
          </Button>
        )
      }
    />
  );
};

interface CloudDriveFileCanvasProps extends CloudDriveFileCanvasOptions {
  /** The node the canvas pointer names; `null` for a pointer that names none. */
  driveNodeId: number | null;
}

/**
 * One drive file in the canvas: the node is read under the viewer's rights (its space decides the adapter), then the
 * viewer of `@mgamadeus/ddd-admin-ui/files` shows it — preview, version choice, download, and "Edit" for a text file
 * in a space the viewer may write. A node that is gone, not readable or a folder says so; nothing is shown from the
 * pointer itself.
 */
export const CloudDriveFileCanvas = ({ driveNodeId, ...options }: CloudDriveFileCanvasProps) => {
  const { t } = useTranslate();
  const nodeQuery = options.api.useDriveNodeQuery({ driveNodeId: driveNodeId ?? 0 }, { skip: driveNodeId === null });
  const item = nodeQuery.data?.item ?? null;
  const isThisFile = item !== null && item.id === driveNodeId && item.nodeType === 'FILE';

  if (driveNodeId !== null && isThisFile) {
    return (
      <div data-slot="cloud-drive-file-canvas" className="flex h-full min-h-0 flex-col px-3 pb-3">
        <CloudDriveFileViewer {...options} spaceId={item.driveSpaceId} driveNodeId={driveNodeId} />
      </div>
    );
  }
  if (driveNodeId !== null && !nodeQuery.isError && (item === null || item.id !== driveNodeId)) {
    return (
      <div className="flex h-full min-h-0 flex-col gap-3 px-3 pb-3" aria-busy>
        <Skeleton className="h-9 w-2/3" />
        <Skeleton className="min-h-56 w-full flex-1 rounded-xl" />
      </div>
    );
  }
  return (
    <div className="px-3 pb-3">
      <div className="flex flex-col items-center gap-3 rounded-xl bg-tint px-6 py-8 text-center">
        <FileFamilyIcon family="OTHER" size="lg" />
        <p className="max-w-sm text-[13px] text-muted-foreground">{t('This file is not available. It was removed, or you may not open it.')}</p>
      </div>
    </div>
  );
};

/**
 * The canvas body of `registerCanvasKind` for the file canvas: the pointer's instance id carries the file's reference
 * (`canvasInstanceIdForReference`), nothing else.
 */
export const createCloudDriveFileCanvasBody = (options: CloudDriveFileCanvasOptions): ComponentType<CanvasBodyProps> => {
  const CloudDriveFileCanvasBody = ({ canvas }: CanvasBodyProps) => (
    <CloudDriveFileCanvas {...options} driveNodeId={driveNodeIdOfCanvasReference(referenceOfCanvasInstanceId(canvas.instanceId))} />
  );
  return CloudDriveFileCanvasBody;
};
