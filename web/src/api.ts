import {
  type ApiMutationHook,
  type ApiQueryHook,
  type InjectableBaseApi,
  type InjectedEndpointDefinition,
  type InjectedRequest,
  injectEndpointsOnce,
  type StaticQueryFailure,
  staticMutationHook,
  staticQueryHook,
  toQueryString,
  trimApiPrefix,
} from '@mgamadeus/ddd-admin-ui/logic/pure';
import type {
  DriveChangeEventListItem,
  DriveChildrenArg,
  DriveContentArg,
  DriveEventsArg,
  DriveFileVersionListItem,
  DriveFoldersArg,
  DriveFoldersResponse,
  DriveListItemsResponse,
  DriveNodeBulkResponse,
  DriveNodeMetadataArg,
  DriveNodesBulkArg,
  DriveNodesBulkMoveArg,
  DriveSpaceIdArg,
  DriveSpaceResponse,
  DriveTrashArg,
  DriveVersionRestoreArg,
  DriveContentGrantResponse,
  DriveRenditionArg,
  DriveRenditionResponse,
  DriveFileCardsArg,
  DriveFileCardsResponse,
  DriveFolderCreateArg,
  DriveNodeIdArg,
  DriveNodeMoveArg,
  DriveNodeResponse,
  DriveNodesResponse,
  DriveSearchArg,
  DriveSpacesResponse,
  DriveUploadBytesResponse,
  DriveUploadCompleteArg,
  DriveUploadContentArg,
  DriveUploadPartArg,
  DriveUploadStateArg,
  DriveUploadReservationResponse,
  DriveUploadReserveArg,
  CloudDriveAudience,
  DriveLocationResolveArg,
  DriveLocationResolveResponse,
} from './types.ts';

/** The cache tag type of the cloud drive operations (ids `SPACES`, `NODES`). */
export const CLOUD_DRIVE_TAG = 'AdminUiCloudDrive';

/** The hooks `createCloudDriveApi` returns; the drive screen takes them as its `api`. */
export interface CloudDriveApi {
  /** `GET /spaces/list`: the spaces the viewer may read. */
  useDriveSpacesQuery: ApiQueryHook<void, DriveSpacesResponse>;
  /** `GET /spaces/{id}/children?folderNodeId&cursor&limit&includeSystem`: one page of a folder. */
  useDriveChildrenQuery: ApiQueryHook<DriveChildrenArg, DriveNodesResponse>;
  /** `GET /spaces/{id}/search?query&limit`: names containing the query. */
  useDriveSearchQuery: ApiQueryHook<DriveSearchArg, DriveNodesResponse>;
  /** `GET /nodes/{id}`: one file or folder with its path and versions. */
  useDriveNodeQuery: ApiQueryHook<DriveNodeIdArg, DriveNodeResponse>;
  /** `POST /spaces/{id}/folders`. */
  useCreateDriveFolderMutation: ApiMutationHook<DriveFolderCreateArg, DriveNodeResponse>;
  /** `POST /spaces/{id}/uploads`: the bytes go in one request or in parts (see `sendDriveUpload`). */
  useReserveDriveUploadMutation: ApiMutationHook<DriveUploadReserveArg, DriveUploadReservationResponse>;
  /** `PUT /uploads/{blobId}/content`: the whole content of a single-part upload (streamed by the backend). */
  usePutDriveUploadContentMutation: ApiMutationHook<DriveUploadContentArg, DriveUploadBytesResponse>;
  /** `PUT /uploads/{blobId}/parts/{partNumber}`: one part of a multipart upload; sending it again replaces it. */
  usePutDriveUploadPartMutation: ApiMutationHook<DriveUploadPartArg, DriveUploadBytesResponse>;
  /** `POST /uploads/{blobId}/parts`: the state of a running upload (`uploadedPartNumbers`), to resume it. */
  useDriveUploadStateMutation: ApiMutationHook<DriveUploadStateArg, DriveUploadReservationResponse>;
  /** `POST /uploads/{blobId}/complete`: the file (a conflict answers `isConflict`). */
  useCompleteDriveUploadMutation: ApiMutationHook<DriveUploadCompleteArg, DriveNodeResponse>;
  /** `GET /nodes/{id}/content?purpose&versionId`: a short-lived content URL (not cached). */
  useDriveContentMutation: ApiMutationHook<DriveContentArg, DriveContentGrantResponse>;
  /** `GET /nodes/{id}/rendition?versionId`: the PDF the server renders of a document, or its state (not cached). */
  useDriveRenditionMutation: ApiMutationHook<DriveRenditionArg, DriveRenditionResponse>;
  /** `POST /nodes/{id}/move`. */
  useMoveDriveNodeMutation: ApiMutationHook<DriveNodeMoveArg, DriveNodeResponse>;
  /** `POST /nodes/{id}/trash`. */
  useTrashDriveNodeMutation: ApiMutationHook<DriveNodeIdArg, DriveNodeResponse>;
  /** `POST /nodes/{id}/restore`. */
  useRestoreDriveNodeMutation: ApiMutationHook<DriveNodeIdArg, DriveNodeResponse>;
  /** `POST /cards`: file cards with open, download and thumbnail URLs (the `DriveFiles` widget). */
  useDriveFileCardsQuery: ApiQueryHook<DriveFileCardsArg, DriveFileCardsResponse>;
  /** `GET /spaces/{id}`: one space with display name, quota and the viewer's rights. */
  useDriveSpaceQuery: ApiQueryHook<DriveSpaceIdArg, DriveSpaceResponse>;
  /** `GET /spaces/{id}/trash?cursor&limit`: the trashed top-level nodes, newest first. */
  useDriveTrashQuery: ApiQueryHook<DriveTrashArg, DriveNodesResponse>;
  /** `GET /spaces/{id}/folders?includeSystem`: every live folder of the space (root included), flat. */
  useDriveFoldersQuery: ApiQueryHook<DriveFoldersArg, DriveFoldersResponse>;
  /** `GET /nodes/{id}/versions`: the versions of a file, newest first. */
  useDriveVersionsQuery: ApiQueryHook<DriveNodeIdArg, DriveListItemsResponse<DriveFileVersionListItem>>;
  /** `GET /nodes/{id}/events?limit`: the newest changes of a node. */
  useDriveEventsQuery: ApiQueryHook<DriveEventsArg, DriveListItemsResponse<DriveChangeEventListItem>>;
  /** `POST /nodes/{id}/versions/{versionId}/restore`: a new current version with an older version's content. */
  useRestoreDriveVersionMutation: ApiMutationHook<DriveVersionRestoreArg, DriveNodeResponse>;
  /** `POST /nodes/{id}/metadata`: description and tags. */
  useUpdateDriveNodeMetadataMutation: ApiMutationHook<DriveNodeMetadataArg, DriveNodeResponse>;
  /** `POST /spaces/{id}/nodes/move`: up to 200 nodes into one folder (omitted = the root). */
  useMoveDriveNodesMutation: ApiMutationHook<DriveNodesBulkMoveArg, DriveNodeBulkResponse>;
  /** `POST /spaces/{id}/nodes/trash`. */
  useTrashDriveNodesMutation: ApiMutationHook<DriveNodesBulkArg, DriveNodeBulkResponse>;
  /** `POST /spaces/{id}/nodes/restore`. */
  useRestoreDriveNodesMutation: ApiMutationHook<DriveNodesBulkArg, DriveNodeBulkResponse>;
  /** `POST /spaces/{id}/nodes/purge` (manage right): deletes trashed nodes for good. */
  usePurgeDriveNodesMutation: ApiMutationHook<DriveNodesBulkArg, DriveNodeBulkResponse>;
  /** `POST /spaces/{id}/trash/empty` (manage right): one batch; `hasMore` asks for another call. */
  useEmptyDriveTrashMutation: ApiMutationHook<DriveSpaceIdArg, DriveNodeBulkResponse>;
  /** `GET /spaces/{id}/resolve?path=`: where a path, `drive/<id>` or a workspace path leads (not cached; 404 = a miss). */
  useResolveDriveLocationMutation: ApiMutationHook<DriveLocationResolveArg, DriveLocationResolveResponse>;
  /** Who the drive answers as (`createCloudDriveApi({ audience })`); absent = staff. */
  audience?: CloudDriveAudience;
}

export interface CreateCloudDriveApiOptions {
  /** The host's RTK Query api (`createApi` with the React hooks module). */
  baseApi: InjectableBaseApi;
  /** Where the host mounts `CloudDriveControllerTrait`, without a trailing slash (`/api/admin/common/cloudDrive`). */
  apiPrefix: string;
  /**
   * `shared`: the prefix is a partner mount (`getDriveAudience()` SHARED): the screen shows no staff-only control
   * (purge, empty trash, space settings and maintenance). Default `internal`.
   */
  audience?: CloudDriveAudience;
}

const idOf = (id: number): string => encodeURIComponent(String(id));

/** The request of every operation, on `apiPrefix`. Pure: no RTK runtime. */
export const buildCloudDriveRequests = (apiPrefix: string) => {
  const prefix = trimApiPrefix(apiPrefix);
  return {
    spaces: (): InjectedRequest => ({ url: `${prefix}/spaces/list` }),
    children: ({ driveSpaceId, folderNodeId, cursor, limit, includeSystem, recursive, query, family, createdFrom, createdTo, createdByMe, orderBy, orderDirection }: DriveChildrenArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/children${toQueryString({
        folderNodeId, cursor, limit, includeSystem, recursive, query, family, createdFrom, createdTo, createdByMe, orderBy, orderDirection,
      })}`,
    }),
    search: ({ driveSpaceId, query, cursor, limit, family, createdFrom, createdTo, createdByMe, orderBy, orderDirection }: DriveSearchArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/search${toQueryString({ query, cursor, limit, family, createdFrom, createdTo, createdByMe, orderBy, orderDirection })}`,
    }),
    space: ({ driveSpaceId }: DriveSpaceIdArg): InjectedRequest => ({ url: `${prefix}/spaces/${idOf(driveSpaceId)}` }),
    trashList: ({ driveSpaceId, cursor, limit }: DriveTrashArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/trash${toQueryString({ cursor, limit })}`,
    }),
    folders: ({ driveSpaceId, includeSystem }: DriveFoldersArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/folders${toQueryString({ includeSystem })}`,
    }),
    versions: ({ driveNodeId }: DriveNodeIdArg): InjectedRequest => ({ url: `${prefix}/nodes/${idOf(driveNodeId)}/versions` }),
    events: ({ driveNodeId, limit, eventGroup, eventTypes }: DriveEventsArg): InjectedRequest => ({
      url: `${prefix}/nodes/${idOf(driveNodeId)}/events${toQueryString({ limit, eventGroup, eventTypes })}`,
    }),
    resolve: ({ driveSpaceId, path }: DriveLocationResolveArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/resolve${toQueryString({ path })}`,
    }),
    restoreVersion: ({ driveNodeId, driveFileVersionId }: DriveVersionRestoreArg): InjectedRequest => ({
      url: `${prefix}/nodes/${idOf(driveNodeId)}/versions/${idOf(driveFileVersionId)}/restore`, method: 'POST',
    }),
    metadata: ({ driveNodeId, ...body }: DriveNodeMetadataArg): InjectedRequest => ({ url: `${prefix}/nodes/${idOf(driveNodeId)}/metadata`, method: 'POST', body }),
    bulkMove: ({ driveSpaceId, ...body }: DriveNodesBulkMoveArg): InjectedRequest => ({ url: `${prefix}/spaces/${idOf(driveSpaceId)}/nodes/move`, method: 'POST', body }),
    bulkTrash: ({ driveSpaceId, ...body }: DriveNodesBulkArg): InjectedRequest => ({ url: `${prefix}/spaces/${idOf(driveSpaceId)}/nodes/trash`, method: 'POST', body }),
    bulkRestore: ({ driveSpaceId, ...body }: DriveNodesBulkArg): InjectedRequest => ({ url: `${prefix}/spaces/${idOf(driveSpaceId)}/nodes/restore`, method: 'POST', body }),
    bulkPurge: ({ driveSpaceId, ...body }: DriveNodesBulkArg): InjectedRequest => ({ url: `${prefix}/spaces/${idOf(driveSpaceId)}/nodes/purge`, method: 'POST', body }),
    emptyTrash: ({ driveSpaceId }: DriveSpaceIdArg): InjectedRequest => ({ url: `${prefix}/spaces/${idOf(driveSpaceId)}/trash/empty`, method: 'POST' }),
    node: ({ driveNodeId }: DriveNodeIdArg): InjectedRequest => ({ url: `${prefix}/nodes/${idOf(driveNodeId)}` }),
    createFolder: ({ driveSpaceId, ...body }: DriveFolderCreateArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/folders`, method: 'POST', body,
    }),
    reserveUpload: ({ driveSpaceId, ...body }: DriveUploadReserveArg): InjectedRequest => ({
      url: `${prefix}/spaces/${idOf(driveSpaceId)}/uploads`, method: 'POST', body,
    }),
    putUploadContent: ({ driveBlobId, body }: DriveUploadContentArg): InjectedRequest => ({
      url: `${prefix}/uploads/${idOf(driveBlobId)}/content`, method: 'PUT', body,
    }),
    putUploadPart: ({ driveBlobId, partNumber, body }: DriveUploadPartArg): InjectedRequest => ({
      url: `${prefix}/uploads/${idOf(driveBlobId)}/parts/${idOf(partNumber)}`, method: 'PUT', body,
    }),
    uploadState: ({ driveBlobId }: DriveUploadStateArg): InjectedRequest => ({
      url: `${prefix}/uploads/${idOf(driveBlobId)}/parts`, method: 'POST', body: {},
    }),
    completeUpload: ({ driveBlobId, ...body }: DriveUploadCompleteArg): InjectedRequest => ({
      url: `${prefix}/uploads/${idOf(driveBlobId)}/complete`, method: 'POST', body,
    }),
    content: ({ driveNodeId, purpose, versionId }: DriveContentArg): InjectedRequest => ({
      url: `${prefix}/nodes/${idOf(driveNodeId)}/content${toQueryString({ purpose, versionId })}`,
    }),
    rendition: ({ driveNodeId, versionId }: DriveRenditionArg): InjectedRequest => ({
      url: `${prefix}/nodes/${idOf(driveNodeId)}/rendition${toQueryString({ versionId })}`,
    }),
    move: ({ driveNodeId, ...body }: DriveNodeMoveArg): InjectedRequest => ({ url: `${prefix}/nodes/${idOf(driveNodeId)}/move`, method: 'POST', body }),
    trash: ({ driveNodeId }: DriveNodeIdArg): InjectedRequest => ({ url: `${prefix}/nodes/${idOf(driveNodeId)}/trash`, method: 'POST' }),
    restore: ({ driveNodeId }: DriveNodeIdArg): InjectedRequest => ({ url: `${prefix}/nodes/${idOf(driveNodeId)}/restore`, method: 'POST' }),
    cards: (body: DriveFileCardsArg): InjectedRequest => ({ url: `${prefix}/cards`, method: 'POST', body }),
  };
};

const SPACES = { type: CLOUD_DRIVE_TAG, id: 'SPACES' } as const;
const NODES = { type: CLOUD_DRIVE_TAG, id: 'NODES' } as const;

/** The operations as injected. Every write invalidates `NODES` and `SPACES` (counters); content URLs are never cached. */
export const cloudDriveEndpointDefinitions = (apiPrefix: string) => {
  const requests = buildCloudDriveRequests(apiPrefix);
  return {
    adminUiDriveSpaces: { kind: 'query', request: requests.spaces, providesTags: [SPACES] },
    adminUiDriveChildren: { kind: 'query', request: requests.children, providesTags: [NODES] },
    adminUiDriveSearch: { kind: 'query', request: requests.search, providesTags: [NODES] },
    adminUiDriveNode: { kind: 'query', request: requests.node, providesTags: [NODES] },
    adminUiDriveFileCards: { kind: 'query', request: requests.cards, providesTags: [NODES] },
    adminUiDriveFolderCreate: { kind: 'mutation', request: requests.createFolder, invalidatesTags: [NODES, SPACES] },
    adminUiDriveUploadReserve: { kind: 'mutation', request: requests.reserveUpload },
    adminUiDriveUploadContent: { kind: 'mutation', request: requests.putUploadContent },
    adminUiDriveUploadPart: { kind: 'mutation', request: requests.putUploadPart },
    adminUiDriveUploadState: { kind: 'mutation', request: requests.uploadState },
    adminUiDriveUploadComplete: { kind: 'mutation', request: requests.completeUpload, invalidatesTags: [NODES, SPACES] },
    adminUiDriveContent: { kind: 'mutation', request: requests.content },
    adminUiDriveRendition: { kind: 'mutation', request: requests.rendition },
    adminUiDriveNodeMove: { kind: 'mutation', request: requests.move, invalidatesTags: [NODES] },
    adminUiDriveNodeTrash: { kind: 'mutation', request: requests.trash, invalidatesTags: [NODES, SPACES] },
    adminUiDriveNodeRestore: { kind: 'mutation', request: requests.restore, invalidatesTags: [NODES, SPACES] },
    adminUiDriveSpace: { kind: 'query', request: requests.space, providesTags: [SPACES] },
    adminUiDriveTrash: { kind: 'query', request: requests.trashList, providesTags: [NODES] },
    adminUiDriveFolders: { kind: 'query', request: requests.folders, providesTags: [NODES] },
    adminUiDriveVersions: { kind: 'query', request: requests.versions, providesTags: [NODES] },
    adminUiDriveEvents: { kind: 'query', request: requests.events, providesTags: [NODES] },
    adminUiDriveVersionRestore: { kind: 'mutation', request: requests.restoreVersion, invalidatesTags: [NODES, SPACES] },
    adminUiDriveNodeMetadata: { kind: 'mutation', request: requests.metadata, invalidatesTags: [NODES] },
    adminUiDriveNodesMove: { kind: 'mutation', request: requests.bulkMove, invalidatesTags: [NODES, SPACES] },
    adminUiDriveNodesTrash: { kind: 'mutation', request: requests.bulkTrash, invalidatesTags: [NODES, SPACES] },
    adminUiDriveNodesRestore: { kind: 'mutation', request: requests.bulkRestore, invalidatesTags: [NODES, SPACES] },
    adminUiDriveNodesPurge: { kind: 'mutation', request: requests.bulkPurge, invalidatesTags: [NODES, SPACES] },
    adminUiDriveTrashEmpty: { kind: 'mutation', request: requests.emptyTrash, invalidatesTags: [NODES, SPACES] },
    adminUiDriveResolve: { kind: 'mutation', request: requests.resolve },
  } as const satisfies Record<string, InjectedEndpointDefinition<never>>;
};

/**
 * Injects the cloud drive operations into the host's RTK Query api and returns their hooks. Call it once at module
 * scope: `export const cloudDriveApi = createCloudDriveApi({ baseApi: adminApi, apiPrefix: '/api/admin/common/cloudDrive' });`
 */
export const createCloudDriveApi = ({ baseApi, apiPrefix, audience }: CreateCloudDriveApiOptions): CloudDriveApi => {
  const hooks = injectCloudDriveEndpoints(baseApi, apiPrefix);
  return audience ? { ...hooks, audience } : hooks;
};

const injectCloudDriveEndpoints = (baseApi: InjectableBaseApi, apiPrefix: string): CloudDriveApi =>
  injectEndpointsOnce(baseApi, `clouddrive:${trimApiPrefix(apiPrefix)}`, [CLOUD_DRIVE_TAG], cloudDriveEndpointDefinitions(apiPrefix), hookOf => ({
    useDriveSpacesQuery: hookOf('adminUiDriveSpaces') as CloudDriveApi['useDriveSpacesQuery'],
    useDriveChildrenQuery: hookOf('adminUiDriveChildren') as CloudDriveApi['useDriveChildrenQuery'],
    useDriveSearchQuery: hookOf('adminUiDriveSearch') as CloudDriveApi['useDriveSearchQuery'],
    useDriveNodeQuery: hookOf('adminUiDriveNode') as CloudDriveApi['useDriveNodeQuery'],
    useDriveFileCardsQuery: hookOf('adminUiDriveFileCards') as CloudDriveApi['useDriveFileCardsQuery'],
    useCreateDriveFolderMutation: hookOf('adminUiDriveFolderCreate') as CloudDriveApi['useCreateDriveFolderMutation'],
    useReserveDriveUploadMutation: hookOf('adminUiDriveUploadReserve') as CloudDriveApi['useReserveDriveUploadMutation'],
    usePutDriveUploadContentMutation: hookOf('adminUiDriveUploadContent') as CloudDriveApi['usePutDriveUploadContentMutation'],
    usePutDriveUploadPartMutation: hookOf('adminUiDriveUploadPart') as CloudDriveApi['usePutDriveUploadPartMutation'],
    useDriveUploadStateMutation: hookOf('adminUiDriveUploadState') as CloudDriveApi['useDriveUploadStateMutation'],
    useCompleteDriveUploadMutation: hookOf('adminUiDriveUploadComplete') as CloudDriveApi['useCompleteDriveUploadMutation'],
    useDriveContentMutation: hookOf('adminUiDriveContent') as CloudDriveApi['useDriveContentMutation'],
    useDriveRenditionMutation: hookOf('adminUiDriveRendition') as CloudDriveApi['useDriveRenditionMutation'],
    useMoveDriveNodeMutation: hookOf('adminUiDriveNodeMove') as CloudDriveApi['useMoveDriveNodeMutation'],
    useTrashDriveNodeMutation: hookOf('adminUiDriveNodeTrash') as CloudDriveApi['useTrashDriveNodeMutation'],
    useRestoreDriveNodeMutation: hookOf('adminUiDriveNodeRestore') as CloudDriveApi['useRestoreDriveNodeMutation'],
    useDriveSpaceQuery: hookOf('adminUiDriveSpace') as CloudDriveApi['useDriveSpaceQuery'],
    useDriveTrashQuery: hookOf('adminUiDriveTrash') as CloudDriveApi['useDriveTrashQuery'],
    useDriveFoldersQuery: hookOf('adminUiDriveFolders') as CloudDriveApi['useDriveFoldersQuery'],
    useDriveVersionsQuery: hookOf('adminUiDriveVersions') as CloudDriveApi['useDriveVersionsQuery'],
    useDriveEventsQuery: hookOf('adminUiDriveEvents') as CloudDriveApi['useDriveEventsQuery'],
    useRestoreDriveVersionMutation: hookOf('adminUiDriveVersionRestore') as CloudDriveApi['useRestoreDriveVersionMutation'],
    useUpdateDriveNodeMetadataMutation: hookOf('adminUiDriveNodeMetadata') as CloudDriveApi['useUpdateDriveNodeMetadataMutation'],
    useMoveDriveNodesMutation: hookOf('adminUiDriveNodesMove') as CloudDriveApi['useMoveDriveNodesMutation'],
    useTrashDriveNodesMutation: hookOf('adminUiDriveNodesTrash') as CloudDriveApi['useTrashDriveNodesMutation'],
    useRestoreDriveNodesMutation: hookOf('adminUiDriveNodesRestore') as CloudDriveApi['useRestoreDriveNodesMutation'],
    usePurgeDriveNodesMutation: hookOf('adminUiDriveNodesPurge') as CloudDriveApi['usePurgeDriveNodesMutation'],
    useEmptyDriveTrashMutation: hookOf('adminUiDriveTrashEmpty') as CloudDriveApi['useEmptyDriveTrashMutation'],
    useResolveDriveLocationMutation: hookOf('adminUiDriveResolve') as CloudDriveApi['useResolveDriveLocationMutation'],
  }));

/** Fixed answers of a `createStaticCloudDriveApi`; a query without one stays loading. */
export interface StaticCloudDriveData {
  spaces?: DriveSpacesResponse | StaticQueryFailure;
  children?: (arg: DriveChildrenArg) => DriveNodesResponse | StaticQueryFailure | undefined;
  search?: (arg: DriveSearchArg) => DriveNodesResponse | StaticQueryFailure | undefined;
  node?: (arg: DriveNodeIdArg) => DriveNodeResponse | StaticQueryFailure | undefined;
  cards?: (arg: DriveFileCardsArg) => DriveFileCardsResponse | StaticQueryFailure | undefined;
  space?: (arg: DriveSpaceIdArg) => DriveSpaceResponse | StaticQueryFailure | undefined;
  trash?: (arg: DriveTrashArg) => DriveNodesResponse | StaticQueryFailure | undefined;
  folders?: (arg: DriveFoldersArg) => DriveFoldersResponse | StaticQueryFailure | undefined;
  versions?: (arg: DriveNodeIdArg) => DriveListItemsResponse<DriveFileVersionListItem> | StaticQueryFailure | undefined;
  events?: (arg: DriveEventsArg) => DriveListItemsResponse<DriveChangeEventListItem> | StaticQueryFailure | undefined;
  /** The answer of a path lookup; `undefined` = not found. */
  resolve?: (arg: DriveLocationResolveArg) => DriveLocationResolveResponse | undefined;
  /** The audience the static api answers as. */
  audience?: CloudDriveAudience;
}

const NOT_AVAILABLE = (): never => {
  throw { status: 403, data: { error: 'Not available in this preview' } };
};

/** A `CloudDriveApi` answering fixed payloads, without a store or a request; writes are refused. */
export const createStaticCloudDriveApi = (data: StaticCloudDriveData): CloudDriveApi => ({
  useDriveSpacesQuery: staticQueryHook(() => data.spaces),
  useDriveChildrenQuery: staticQueryHook(arg => data.children?.(arg)),
  useDriveSearchQuery: staticQueryHook(arg => data.search?.(arg)),
  useDriveNodeQuery: staticQueryHook(arg => data.node?.(arg)),
  useDriveFileCardsQuery: staticQueryHook(arg => data.cards?.(arg)),
  useCreateDriveFolderMutation: staticMutationHook(NOT_AVAILABLE),
  useReserveDriveUploadMutation: staticMutationHook(NOT_AVAILABLE),
  usePutDriveUploadContentMutation: staticMutationHook(NOT_AVAILABLE),
  usePutDriveUploadPartMutation: staticMutationHook(NOT_AVAILABLE),
  useDriveUploadStateMutation: staticMutationHook(NOT_AVAILABLE),
  useCompleteDriveUploadMutation: staticMutationHook(NOT_AVAILABLE),
  useDriveContentMutation: staticMutationHook(NOT_AVAILABLE),
  useDriveRenditionMutation: staticMutationHook(NOT_AVAILABLE),
  useMoveDriveNodeMutation: staticMutationHook(NOT_AVAILABLE),
  useTrashDriveNodeMutation: staticMutationHook(NOT_AVAILABLE),
  useRestoreDriveNodeMutation: staticMutationHook(NOT_AVAILABLE),
  useDriveSpaceQuery: staticQueryHook(arg => data.space?.(arg)),
  useDriveTrashQuery: staticQueryHook(arg => data.trash?.(arg)),
  useDriveFoldersQuery: staticQueryHook(arg => data.folders?.(arg)),
  useDriveVersionsQuery: staticQueryHook(arg => data.versions?.(arg)),
  useDriveEventsQuery: staticQueryHook(arg => data.events?.(arg)),
  useRestoreDriveVersionMutation: staticMutationHook(NOT_AVAILABLE),
  useUpdateDriveNodeMetadataMutation: staticMutationHook(NOT_AVAILABLE),
  useMoveDriveNodesMutation: staticMutationHook(NOT_AVAILABLE),
  useTrashDriveNodesMutation: staticMutationHook(NOT_AVAILABLE),
  useRestoreDriveNodesMutation: staticMutationHook(NOT_AVAILABLE),
  usePurgeDriveNodesMutation: staticMutationHook(NOT_AVAILABLE),
  useEmptyDriveTrashMutation: staticMutationHook(NOT_AVAILABLE),
  useResolveDriveLocationMutation: staticMutationHook((arg: DriveLocationResolveArg) => {
    const answer = data.resolve?.(arg);
    if (!answer) throw { status: 404, data: { error: 'Not found', missingSegment: arg.path } };
    return answer;
  }),
  ...(data.audience ? { audience: data.audience } : {}),
});
