import type { DriveFileCard as AgentDriveFileCard, DriveFileCardsSource, DriveFileContent, DriveFilesRequest } from '@mgamadeus/ddd-agents';
import type { InjectableBaseApi } from '@mgamadeus/ddd-admin-ui/logic/pure';
import { createCloudDriveApi } from '../api.ts';
import type { DriveContentGrantResponse, DriveFileCard, DriveFileCardsResponse } from '../types.ts';
import { buildDriveScreenHref, nodeIdOfDriveReference } from './driveScreenHref.ts';

/** The store's dispatch: the RTK store the host's api is mounted in. */
export type CloudDriveCardsDispatch = (action: unknown) => unknown;

export interface CreateCloudDriveCardsSourceOptions {
  /** The host's RTK Query api (the same one `createCloudDriveApi` is given). */
  baseApi: InjectableBaseApi;
  /** The prefix of the cloud drive routes, e.g. `/api/admin/common/cloudDrive`. */
  apiPrefix: string;
  /**
   * The drive screen link of a card that carries no `adminLink`: by default `buildDriveScreenHref` under `screenRoute`
   * (space + node); `null` shows no "show in the drive" action.
   */
  buildDriveScreenHref?: ((card: AgentDriveFileCard) => string | null) | null;
  /** The route the host mounted the drive screen at, for the default link; default `/drive`. */
  screenRoute?: string;
}

/** The source of the `DriveFiles` cards on the cloud drive routes, plus the binding to the host's store. */
export interface CloudDriveCardsSource extends DriveFileCardsSource {
  /**
   * Binds the store's dispatch (the store the host's api is mounted in); returns the unbind. A read started before the
   * first bind waits for it. Call it where the store is known: `cardsSource.bindDispatch(store.dispatch)` at start-up,
   * or in an effect of the component that mounts the agent window.
   */
  bindDispatch: (dispatch: CloudDriveCardsDispatch) => () => void;
}

/** A card as the agents draw it: the drive's card with its screen link as `openLink`; the content URLs ride along. */
type DriveCardOfAgents = AgentDriveFileCard & Pick<DriveFileCard, 'inlineUrl' | 'downloadUrl'>;

const toAgentCard = (card: DriveFileCard): DriveCardOfAgents => ({
  reference: card.reference,
  name: card.name,
  path: card.path,
  isFolder: card.isFolder,
  sizeBytes: card.sizeBytes ?? null,
  contentType: card.contentType ?? null,
  modifiedAt: card.modifiedAt ?? null,
  childCount: card.childCount ?? null,
  state: card.state ?? null,
  thumbnailUrl: card.thumbnailUrl ?? null,
  urlsExpireAt: card.urlsExpireAt ?? null,
  openLink: card.adminLink ?? null,
  driveSpaceId: card.driveSpaceId,
  inlineUrl: card.inlineUrl ?? null,
  downloadUrl: card.downloadUrl ?? null,
});

/** How long before `urlsExpireAt` a URL counts as expired: the click, the redirect and the bucket's clock. */
const URL_EXPIRY_MARGIN_MS = 30_000;

const expiresAtMs = (value: string | null | undefined): number | null => {
  const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec((value ?? '').trim());
  if (!match) return null;
  const [, year, month, day, hour, minute, second] = match;
  return Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second ?? 0));
};

const isExpired = (card: { urlsExpireAt?: string | null }, nowMs: number): boolean => {
  const at = expiresAtMs(card.urlsExpireAt);
  return at === null || at - URL_EXPIRY_MARGIN_MS <= nowMs;
};

interface InitiatedRequest<TData> {
  unwrap: () => Promise<TData>;
  abort?: () => void;
}

interface InjectedEndpoints {
  endpoints: Record<string, { initiate: (arg: unknown, options?: unknown) => (dispatch: never, getState: never) => unknown }>;
}

/**
 * The `DriveFileCardsSource` of `@mgamadeus/ddd-agents` on the cloud drive routes: the cards of a block through
 * `POST {apiPrefix}/cards` (the viewer's rights; a reference the server does not answer is left out), a card's
 * content through the card's short-lived URLs (read again when they expired) or `GET {apiPrefix}/nodes/{id}/content`.
 * Register it once: `registerDriveFileCardsSource(cardsSource)`, and bind the store (`bindDispatch`).
 */
export const createCloudDriveCardsSource = (options: CreateCloudDriveCardsSourceOptions): CloudDriveCardsSource => {
  const { baseApi, apiPrefix, screenRoute = '/drive' } = options;
  // the endpoints are injected once per api and prefix (the same injection `createCloudDriveApi` does)
  createCloudDriveApi({ baseApi, apiPrefix });
  const endpoints = (baseApi as unknown as InjectedEndpoints).endpoints;

  let boundDispatch: CloudDriveCardsDispatch | null = null;
  let announce: ((dispatch: CloudDriveCardsDispatch) => void) | null = null;
  let bound = new Promise<CloudDriveCardsDispatch>(resolve => {
    announce = resolve;
  });
  const readDispatch = (): Promise<CloudDriveCardsDispatch> => (boundDispatch ? Promise.resolve(boundDispatch) : bound);

  const run = async <TData>(endpointName: string, arg: unknown, initiateOptions: unknown, signal?: AbortSignal): Promise<TData> => {
    const dispatch = await readDispatch();
    const endpoint = endpoints[endpointName];
    if (!endpoint) throw new Error(`The cloud drive endpoint ${endpointName} is not injected.`);
    const request = dispatch(endpoint.initiate(arg, initiateOptions)) as InitiatedRequest<TData>;
    const abort = () => request.abort?.();
    signal?.addEventListener('abort', abort, { once: true });
    try {
      return await request.unwrap();
    } finally {
      signal?.removeEventListener('abort', abort);
    }
  };

  const fetchCards = async (references: string[], signal?: AbortSignal): Promise<DriveCardOfAgents[]> => {
    const response = await run<DriveFileCardsResponse>('adminUiDriveFileCards', { references }, { subscribe: false, forceRefetch: true }, signal);
    const position = (card: DriveFileCard) => references.indexOf(card.reference);
    return (response.cards.elements ?? [])
      .filter(card => position(card) >= 0)
      .sort((first, second) => position(first) - position(second))
      .map(toAgentCard);
  };

  const defaultHref = (card: AgentDriveFileCard): string | null => {
    const node = nodeIdOfDriveReference(card.reference);
    if (node === null) return null;
    return buildDriveScreenHref({ space: card.driveSpaceId ?? null, node }, screenRoute);
  };

  return {
    readFileCards: (request: DriveFilesRequest, signal: AbortSignal) => fetchCards(request.references, signal),

    readFileContent: async (card, disposition): Promise<DriveFileContent> => {
      let current = card as DriveCardOfAgents;
      if (isExpired(current, Date.now())) {
        const [fresh] = await fetchCards([card.reference]);
        if (!fresh) throw new Error('The file is no longer readable.');
        current = fresh;
      }
      const url = disposition === 'inline' ? current.inlineUrl : current.downloadUrl;
      if (url) return { url, fileName: current.name };
      const nodeId = Number(nodeIdOfDriveReference(current.reference));
      if (!Number.isInteger(nodeId) || nodeId <= 0) throw new Error('The file has no content URL.');
      const response = await run<DriveContentGrantResponse>('adminUiDriveContent', {
        driveNodeId: nodeId,
        purpose: disposition === 'inline' ? 'INLINE' : 'DOWNLOAD',
      }, undefined);
      return { url: response.grant.url, fileName: response.grant.fileName || current.name };
    },

    buildDriveScreenHref: options.buildDriveScreenHref === null ? undefined : (options.buildDriveScreenHref ?? defaultHref),

    bindDispatch: dispatch => {
      boundDispatch = dispatch;
      announce?.(dispatch);
      return () => {
        if (boundDispatch !== dispatch) return;
        boundDispatch = null;
        bound = new Promise<CloudDriveCardsDispatch>(resolve => {
          announce = resolve;
        });
      };
    },
  };
};
