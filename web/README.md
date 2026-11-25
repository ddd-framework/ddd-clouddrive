# @mgamadeus/ddd-clouddrive

The React frontend of the cloud drive for backends built with [mgamadeus/ddd](https://github.com/ddd-framework/ddd) and
the Composer package `mgamadeus/ddd-clouddrive` (same repository, same version, one tag publishes both).

| Entry | What it holds | Peers |
|---|---|---|
| `@mgamadeus/ddd-clouddrive` | `createCloudDriveApi({ baseApi, apiPrefix, audience? })` — the drive routes of `CloudDriveControllerTrait` injected into the host's RTK Query api — and the payload types | `@mgamadeus/ddd-admin-ui` |
| `@mgamadeus/ddd-clouddrive/screen` | `CloudDriveScreen` (the file manager of `@mgamadeus/ddd-admin-ui/files` bound to the drive: spaces, folders, upload, versions, trash, activity, accesses, URL state, path bar), `CloudDriveSpacePicker`, `useCloudDriveFileAdapter`, the file actions and the mapping helpers | `@mgamadeus/ddd-admin-ui`, `react`, `lucide-react` |
| `@mgamadeus/ddd-clouddrive/agents` | `createCloudDriveCardsSource({ baseApi, apiPrefix, screenRoute? })` — the store behind the `DriveFiles` cards of `@mgamadeus/ddd-agents` — and `buildDriveScreenHref()` | `@mgamadeus/ddd-agents` (optional) |
| `@mgamadeus/ddd-clouddrive/canvas` | `registerCloudDriveCanvases({ api, screenRoute?, loadPdfJs?, loadCodeHighlighter?, renderMarkdown? })` — a file of a `DriveFiles` block opens in the canvas beside the agent chat (the file viewer of `@mgamadeus/ddd-admin-ui/files`, a text file can be edited there), a folder as a compact file manager; the `DriveFolder` block of `showDriveFolder` | `@mgamadeus/ddd-agents` ≥ 0.3.14, `@mgamadeus/ddd-admin-ui` ≥ 0.8.0, `react`, `lucide-react` |

## Drive screen

```tsx
import { createCloudDriveApi } from '@mgamadeus/ddd-clouddrive';
import { CloudDriveScreen } from '@mgamadeus/ddd-clouddrive/screen';
import { useNextDrawerUrlAdapter, useNextUrlStateAdapter } from '@mgamadeus/ddd-admin-ui/next';

export const cloudDriveApi = createCloudDriveApi({ baseApi: adminApi, apiPrefix: '/api/admin/common/cloudDrive' });

<CloudDriveScreen
  api={cloudDriveApi}
  urlStateAdapter={useNextUrlStateAdapter({ listId: 'drive' })}
  drawerUrlAdapter={useNextDrawerUrlAdapter()}
  loadPdfJs={loadPdfJs}
  loadCodeHighlighter={loadCodeHighlighter}
  renderMarkdown={source => <MarkdownPreview source={source} untrusted />}
/>
```

- The screen's state lives in the URL (`CLOUD_DRIVE_URL_KEYS`: `space`, `path`, `file`, `fileTab`, `tab`, `view`, `q`,
  filters, `sort`; `?node=<id>` opens a node by id).
- Uploads, previews and downloads go through the backend only (`PUT …/uploads/{id}/content|parts/{n}`, content URLs
  with a signed token): no URL of the object store reaches the browser and the bucket needs no CORS rule. Text and
  PDF previews fetch the content URL (same origin as the API, or the host's absolute `transfer.contentUrlPrefix`;
  no credentials needed). PDF needs the host's `loadPdfJs`; source files are coloured by `loadCodeHighlighter`
  (`() => import('highlight.js/lib/common').then(module => codeHighlighterFromHighlightJs(module.default))`, read as
  tokens and drawn as text); a Markdown file shows "Preview" and "Source" with `renderMarkdown` (`MarkdownPreview
  untrusted` of `@mgamadeus/ddd-admin-ui/markdown`: no raw HTML, no pictures loaded) and its source alone without it.
  Pictures zoom, MP4 / WebM / MP3 … play in the browser's player. All three loaders are optional.
- A partner mount: `createCloudDriveApi({ baseApi, apiPrefix: <partner prefix>, audience: 'shared' })`.
- The texts are the keys of `@mgamadeus/ddd-admin-ui`'s translation store (English keys, the host translates).
- Tailwind scans the class names in `dist`: add the package to the host's sources
  (`@source "../node_modules/@mgamadeus/ddd-clouddrive/dist";`) next to `@mgamadeus/ddd-admin-ui`.

## DriveFiles cards in the agent chat

```ts
import { registerDriveFileCardsSource } from '@mgamadeus/ddd-agents';
import { createCloudDriveCardsSource } from '@mgamadeus/ddd-clouddrive/agents';

export const driveCardsSource = createCloudDriveCardsSource({
  baseApi: adminApi,
  apiPrefix: '/api/admin/common/cloudDrive',
  screenRoute: '/drive', // where the host mounted CloudDriveScreen (the default link of a card without adminLink)
});
registerDriveFileCardsSource(driveCardsSource);
// once the store exists (start-up, or an effect of the component that mounts the agent window):
driveCardsSource.bindDispatch(store.dispatch);
```

The cards are read through `POST {apiPrefix}/cards` under the viewer's rights; open and download use the card's
short-lived URLs (read again when they expired) or `GET {apiPrefix}/nodes/{id}/content`. A card's `adminLink`
(`CloudDrive.settings.adminLinkTemplate` on the server) becomes its link into the drive screen.

## Files and folders in the canvas beside the agent chat

```ts
import { registerCloudDriveCanvases } from '@mgamadeus/ddd-clouddrive/canvas';

registerCloudDriveCanvases({
  api: cloudDriveApi,          // the createCloudDriveApi(…) of the drive screen
  screenRoute: '/drive',       // "Show in the drive"; null = no link
  loadPdfJs,
  loadCodeHighlighter,
  renderMarkdown: source => <MarkdownPreview source={source} untrusted />,
});
```

Call it once, outside React, before the first chat (next to `registerDriveFileCardsSource`). It registers, on
`@mgamadeus/ddd-agents` ≥ 0.3.14 (`registerCanvasKind`, `registerConversationWidgets`, `registerDriveFileCanvasOpener`):

- **the canvas kind `cloudDriveFile`**: a file of a `DriveFiles` block that has a preview (a picture, a PDF, Markdown,
  source code, text, CSV, video, sound, and an Office document the server renders as PDF) opens beside the chat in
  `FileViewer` of `@mgamadeus/ddd-admin-ui/files` (≥ 0.8.0) — preview, choice of a stored version, download, "Show in
  the drive". Files without a preview keep the tab and the download.
- **the canvas kind `cloudDriveFolder`**: a folder opens as the compact `FileManager` of admin-ui (no tree, no quota;
  walk into folders with the path bar, open a file in place with a back button, download). Upload, new folder, rename,
  move and trash appear only when the viewer may write in the space (`canWrite` of `GET {apiPrefix}/spaces/{id}`); the
  server checks every action again.
- **the block `DriveFolder`** (written by the tool `showDriveFolder`): a card that opens the folder canvas; a block that
  just arrived opens it by itself.
- **the canvas opener of the store `clouddrive`**: decides per `DriveFiles` row — folder → folder canvas, file with a
  preview → file canvas, else none.

**Edit in place.** A text file (Markdown, text, CSV, JSON, YAML, source code; UTF-8, at most 1 MB) gets "Edit" when the
viewer may write in the space and looks at the newest version. Saving writes a new drive version through the upload
routes with `expectedLatestVersionId` = the version the editor loaded: when someone wrote a version meanwhile the
server answers a conflict, nothing is overwritten, and the viewer chooses "Load the newer version" or "Save as a copy"
(a new file beside it, `name (copy).ext`, then `(copy 2)` …). The same actions exist on the adapter of the drive
screen (`saveText`, `saveTextAsCopy` of `createDriveFileManagerActions`).

**The agent learns of it.** The canvas sends the conversation's id with the save (`agentConversationId` of
`POST {apiPrefix}/uploads/{id}/complete`); the server writes the note `DRIVE_FILE_CHANGED_BY_PERSON` into that
conversation when the caller may read it ("version 3 → 4, read it again"). The client never writes the note itself.

**Office documents.** With `CloudDrive.settings.officeRenditions.enabled` the list item and the card carry
`hasPdfRendition`; the viewer asks `GET {apiPrefix}/nodes/{id}/rendition` (`useDriveRenditionMutation`), shows
"is being prepared" while the answer is `PENDING` (asked again every 3 s, at most 3 minutes), the reason on `FAILED`
and the PDF of the grant on `READY`.

A canvas pointer carries the reference only (`cloudDriveFile:reference:drive/812`); the node, its versions and its
content are read through the drive routes under the viewer's rights, every byte through the content route — never an
object-store URL. Markdown is rendered by the host's `renderMarkdown` (pass the untrusted mode), source is coloured
text, an SVG is drawn as an image; nothing a file contains is executed. The canvases stay through the conversation
(`pinned`) and come back after a reload. The canvas bodies run inside the host's Redux provider (the api's hooks) and
inside `AdminUiProvider` (links, texts). Without the call nothing changes.

## Texts

Every text of the drive screen goes through `@mgamadeus/ddd-admin-ui/i18n` (`t()`, `m()`, `translateText()`). The
package ships its text manifest `@mgamadeus/ddd-clouddrive/i18n-keys.json` (keys, contexts, translator hints, the
files that use them; the format of `@mgamadeus/ddd-admin-ui/i18n-keys.json`): a host's text export merges it next to
admin-ui's. The package ships no translations; the host adds them to its translation store.

## Development

```bash
pnpm install
pnpm typecheck && pnpm test && pnpm build
test/consumer/run.sh "$(ls ../*.tgz)" npm /tmp/consumer   # after pnpm pack --pack-destination ..
```
