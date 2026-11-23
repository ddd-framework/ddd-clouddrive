import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { configureStore } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';
import { createCloudDriveCardsSource } from './cloudDriveCardsSource.ts';
import { buildDriveScreenHref, nodeIdOfDriveReference } from './driveScreenHref.ts';

/** A host api on RTK Query whose base query answers the cards and content routes from fixtures. */
const setup = () => {
  const requests: { url: string; method?: string; body?: unknown }[] = [];
  const baseApi = createApi({
    reducerPath: 'api',
    baseQuery: async (request: { url: string; method?: string; body?: unknown }) => {
      requests.push(request);
      if (request.url === '/api/drive/cards') {
        const references = (request.body as { references: string[] }).references;
        const cards = references
          .filter(reference => reference !== 'drive/404')
          .map(reference => ({
            reference,
            driveSpaceId: 3,
            name: `${reference.replace('drive/', 'file-')}.pdf`,
            path: `out/${reference.replace('drive/', 'file-')}.pdf`,
            isFolder: false,
            contentType: 'application/pdf',
            inlineUrl: `https://store.example/${reference}?inline`,
            downloadUrl: null,
            urlsExpireAt: '2099-01-01 00:00:00',
            adminLink: reference === 'drive/1' ? '/admin/drive?space=3&file=1' : null,
          }))
          .reverse();
        return { data: { cards: { elements: cards } } };
      }
      if (request.url.startsWith('/api/drive/nodes/')) {
        return { data: { grant: { purpose: 'DOWNLOAD', url: `https://store.example${request.url}`, fileName: 'file.pdf' } } };
      }
      return { error: { status: 404 } };
    },
    endpoints: () => ({}),
  });
  const store = configureStore({
    reducer: { [baseApi.reducerPath]: baseApi.reducer },
    middleware: getDefault => getDefault().concat(baseApi.middleware),
  });
  const source = createCloudDriveCardsSource({ baseApi, apiPrefix: '/api/drive' });
  return { store, source, requests };
};

describe('createCloudDriveCardsSource', () => {
  it('reads the cards in the block order, leaves out unanswered references and maps the screen link', async () => {
    const { store, source } = setup();
    source.bindDispatch(store.dispatch);
    const request = { store: 'drive', references: ['drive/1', 'drive/404', 'drive/2'], title: null, widgetSelfHydrationKey: null };
    const cards = await source.readFileCards(request, new AbortController().signal);
    assert.deepEqual(cards.map(card => card.reference), ['drive/1', 'drive/2']);
    assert.equal(cards[0]?.openLink, '/admin/drive?space=3&file=1');
    assert.equal(cards[1]?.openLink, null);
    assert.equal(source.buildDriveScreenHref?.(cards[1]!), '/drive?space=3&node=2');
  });

  it('waits for the store before the first read', async () => {
    const { store, source } = setup();
    const reading = source.readFileCards({ store: 'drive', references: ['drive/2'], title: null, widgetSelfHydrationKey: null }, new AbortController().signal);
    source.bindDispatch(store.dispatch);
    assert.equal((await reading).length, 1);
  });

  it('opens through the card URL and downloads through the content route when the card has no URL', async () => {
    const { store, source, requests } = setup();
    source.bindDispatch(store.dispatch);
    const [card] = await source.readFileCards({ store: 'drive', references: ['drive/2'], title: null, widgetSelfHydrationKey: null }, new AbortController().signal);
    assert.ok(card);
    assert.deepEqual(await source.readFileContent(card, 'inline'), { url: 'https://store.example/drive/2?inline', fileName: 'file-2.pdf' });
    const download = await source.readFileContent(card, 'attachment');
    assert.equal(download.url, 'https://store.example/api/drive/nodes/2/content?purpose=DOWNLOAD');
    assert.ok(requests.some(request => request.url === '/api/drive/nodes/2/content?purpose=DOWNLOAD'));
  });

  it('reads an expired card again before handing out its URL', async () => {
    const { store, source, requests } = setup();
    source.bindDispatch(store.dispatch);
    const expired = { reference: 'drive/2', name: 'file-2.pdf', urlsExpireAt: '2000-01-01 00:00:00' };
    await source.readFileContent(expired, 'inline');
    assert.equal(requests.filter(request => request.url === '/api/drive/cards').length, 1);
  });
});

describe('drive screen link', () => {
  it('builds the route with the non-empty parts of the place', () => {
    assert.equal(buildDriveScreenHref({}), '/drive');
    assert.equal(buildDriveScreenHref({ space: 31, node: '812' }), '/drive?space=31&node=812');
    assert.equal(buildDriveScreenHref({ space: 3, path: '/out/2026', file: 9 }, '/admin/drive'), '/admin/drive?space=3&path=%2Fout%2F2026&file=9');
  });

  it('reads the node of a drive reference', () => {
    assert.equal(nodeIdOfDriveReference('drive/812'), '812');
    assert.equal(nodeIdOfDriveReference('drivespaces/library/Logo.svg'), null);
  });
});
