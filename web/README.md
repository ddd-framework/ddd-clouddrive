# @mgamadeus/ddd-clouddrive

The React frontend of the cloud drive for backends built with [mgamadeus/ddd](https://github.com/ddd-framework/ddd) and
the Composer package `mgamadeus/ddd-clouddrive` (same repository, same version, one tag publishes both).

| Entry | What it holds | Peers |
|---|---|---|
| `@mgamadeus/ddd-clouddrive` | `createCloudDriveApi({ baseApi, apiPrefix, audience? })` — the drive routes of `CloudDriveControllerTrait` injected into the host's RTK Query api — and the payload types | `@mgamadeus/ddd-admin-ui` |
| `@mgamadeus/ddd-clouddrive/screen` | `CloudDriveScreen` (the file manager of `@mgamadeus/ddd-admin-ui/files` bound to the drive: spaces, folders, upload, versions, trash, activity, accesses, URL state, path bar), `CloudDriveSpacePicker`, `useCloudDriveFileAdapter`, the file actions and the mapping helpers | `@mgamadeus/ddd-admin-ui`, `react`, `lucide-react` |
| `@mgamadeus/ddd-clouddrive/agents` | `createCloudDriveCardsSource({ baseApi, apiPrefix, screenRoute? })` — the store behind the `DriveFiles` cards of `@mgamadeus/ddd-agents` — and `buildDriveScreenHref()` | `@mgamadeus/ddd-agents` (optional) |

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
/>
```

- The screen's state lives in the URL (`CLOUD_DRIVE_URL_KEYS`: `space`, `path`, `file`, `fileTab`, `tab`, `view`, `q`,
  filters, `sort`; `?node=<id>` opens a node by id).
- Text and PDF previews fetch the store's presigned download URL from the browser: the bucket's CORS must allow GET from
  the admin origin. PDF needs the host's `loadPdfJs`.
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
