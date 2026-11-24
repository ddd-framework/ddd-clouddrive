# ddd-clouddrive

Cloud drive for [mgamadeus/ddd](https://github.com/ddd-framework/ddd): spaces whose rights the host decides,
folders and versioned files whose bytes live in object storage, working copies for agent sandboxes and, beside
[mgamadeus/ddd-agents](https://github.com/ddd-framework/ddd-agents), the agent's drive mount and tools.

## What it does

- **Spaces** (`DriveSpace`): one tree per space, an audience fixed at creation (`INTERNAL` or `SHARED`), quota,
  largest file and retention per `spaceKind` (`config/app/CloudDrive/spaceKinds.php`). The host extends `DriveSpace`
  at the same path with its anchor columns.
- **Rights**: read, write and manage are decided in one place, `DBDriveSpace::applyRightsToSpaceQuery()`; every drive
  table and every service check filters through it. Default: admins everything, everyone else nothing. The host adds
  its rules as `DriveSpaceRightsExtension` services (tag `clouddrive.space_rights_extension`, autoconfigured, any
  number): `isCallerUnrestricted()` (true = every space, false = only granted spaces — also for an admin, null = no
  opinion; one false wins) and `buildSpaceRightsCondition()` (a DQL condition on the space alias, OR-composed; no
  joins, subqueries and parameters prefixed with the given prefix). A throwing extension grants nothing.
  `DriveSpaceRightsContractTestCase` is the test a host runs against its rules.
- **Do not subclass `DriveSpace`, `DBDriveSpace` or `DBDriveSpaceModel`** (unsupported): a second Doctrine model on
  the spaces table clashes with the module's relations (node and working-copy models point at one space model, and
  lazy loads resolve either class depending on the path — "Cannot assign …DBDriveSpaceModel" errors). Rights:
  `DriveSpaceRightsExtension`; labels, grouping, ordering, quota, mount names: the `DriveSpacesService` hooks (a
  same-path subclass of the service is fine); the link from a space to the host's records (e.g. a team owning it):
  a table of the host that holds the `driveSpaceId`, read by the extension through a subquery.
- **Audiences**: a caller acts as INTERNAL (staff) or SHARED (e.g. partners): SHARED never manages and writes only where
  `DriveSpacesService::isWriteAllowedForAudience()` allows it (not while the space is `isAudienceWriteLocked`). A
  partner controller mounts the same trait with `getDriveAudience()` returning SHARED.
- **Type policy** per space kind and audience (`config/app/CloudDrive/typePolicies.php`,
  `DriveTypePoliciesService::buildTypePolicyForSpace()`): families, extensions, types, size; checked at the
  reservation, at every write and at the ingest (the bytes must fit the name; programs are refused).
- **Activity**: accesses (VIEWED, DOWNLOADED, recorded when a content URL is granted, deduplicated), the application's
  own events (`DriveChangesService::recordHostEvent()`) and on-behalf-of context, in the table `DriveActivityEvents`,
  merged into a node's history. An access keeps the client's public IP (`clientIp`, Symfony's `Request::getClientIp()`
  under the host's trusted proxies; null for agent reads and the console), shown only in INTERNAL answers. Changes are
  kept as long as the space; accesses (VIEWED, DOWNLOADED, READ_BY_AGENT) too, unless the host sets
  `CloudDrive.settings.accessEventRetentionDays` (default 0 = unlimited; e.g. 365), which the maintenance enforces.
- **Transfers** between spaces with a preview (`DriveSpaceTransfersService`, `DriveSpaceTransferListener` for the
  application's links), purge notifications (`DriveNodeLifecycleListener`).
- **Files**: folders, files, versions (`DriveFileVersion`) and blobs (`DriveBlob`). Names are case-sensitive and
  unique per folder; trash and restore; tags and descriptions; a change feed (`DriveChangeEvent`).
- **Bytes**: every byte between a client and the store goes through the backend — no URL of the store ever reaches
  a browser, the bucket needs no CORS rule and no lifecycle rule, and any S3-compatible store can sit behind it.
  Uploads are streamed from the request body into the store (one request up to `transfer.singlePartMaxBytes`, above
  in resumable parts of `transfer.partBytes`, one part per request, never a whole part in memory); downloads,
  previews and thumbnails are streamed from the store (ranges, ETag) under a signed, short-lived URL. Every object is
  written once: a key per blob, multipart uploads completed only by the server onto a free key, an announced MD5
  checked. The ingest computes the SHA-256, takes the content type from the bytes, scans,
  deduplicates per space and releases or blocks. People read only released content (`DriveContentGrantsService`):
  inline only for JPEG, PNG, GIF, WebP and PDF in their own type and for plain texts (Markdown, CSV, JSON, …) as
  `text/plain; charset=utf-8`; everything else as an attachment (HTML, SVG, XML, JavaScript as
  `application/octet-stream`), always with `nosniff` and the sandbox CSP (not on a PDF); an SVG is shown only as its
  sanitised copy. A host that wants inline PDFs and texts off the application's origin mounts the content route on
  another host and sets `transfer.contentUrlPrefix` to its absolute URL. `resolveInlineContentType()` is the one rule (a host narrows it in a
  same-path subclass). The stored type comes from the bytes at the ingest of every write path (upload, working-copy
  commit, agent write, copies; `DriveBlobsService::detectContentTypeOfFile()` / `detectContentTypeOfBytes()`): a PDF
  is a PDF only when its bytes say so, the name only tells text subtypes (`.md` → `text/markdown`) and office formats
  of a ZIP apart, a writer's claim never makes bytes an image, a PDF or text. A blob stored earlier without a telling
  type (missing or `application/octet-stream`) gets it from its first bytes when it is first served, and keeps it;
  `app:drive:backfill-content-types [--dryRun] [--limit=1000] [--driveSpaceId=]` does that for all of them at once.
- **Working copies** (`DriveWorkingCopiesService`): a sandbox saves changes (`stageChanges()`), commits them with a
  compare and swap on each file's newest version (`commitChanges()`), pulls what others changed
  (`findChangesSince()`). A conflict is never overwritten: it stays saved until it is resolved with mine, theirs or
  both. `DriveTextMergeService` merges text three-way with `git merge-file`.
- **Agent part** (only with ddd-agents installed; classes in `Services\AI`, `Entities\AI`, `Repo\DB\AI`): the drive
  store behind `agent.drive` (`CloudDriveAgentDriveStoreService`), the mount resolver a host overrides
  (`DriveSpaceMountResolverService`; by default one own drive per agent space, created on first use and linked in the
  table `AgentSpaceDriveSpaces`, owned by the agent space's account, `AgentOwnDriveSpaceRightsExtensionService`), the
  tool package `clouddrive.files` (`CloudDriveMCPPackage`: `driveStatus`, `commitDriveChanges`, `findDriveFiles`,
  `getDriveFile`, `listDriveFileEvents`, `getDriveSpace`, `organizeDriveFiles`, `createDriveFolder`,
  `trashDriveFiles`, `restoreDriveFiles`, `rescanDriveFileVersion`, `setDriveSpaceSettings`,
  `importDriveFileToMediaLibrary`, `showDriveLocation`), the widget `DriveLocation`, the skill `CloudDrive.Files` and
  the right constant `CloudDriveAccountRights::USE_DRIVE`.
- **Browser file sink** (with ddd-agents and ddd-agent-browser ≥ 0.2.0): `CloudDriveBrowserFileSinkService`
  implements the browser's `BrowserFileSink`. Files that leave the browser land in the own drive of the call's agent
  space in `web/<domain>/` under the renderer's names, through the upload intake: type from the bytes, an allow-list
  per kind, executable and active content refused, SVG only through the host's `DriveSvgSanitizer` (refused without
  one), stored untrusted with its origin in the version comment, the token file and the logo wait for the scan. A
  host with its own sink overrides the class at the same path.
- **Pull mark** (`DriveWorkingCopiesService::requestPull()`, `isPullRequested()`, `consumePullRequest()`): a writer
  outside a sandbox (the browser sink, an import) marks the working copy, the sandbox's store pulls at its next call.
  Cache-held (redis group) for a day; the change feed alone does not do, a file in the scan holds its cursor back.
- **Changes of a working copy** (`DriveChangesService::findChangesOfWorkingCopy()`): what one working copy changed in
  its space, newest first, optionally by event type (the nodes it created, to clean up a throwaway working copy).
- **Media library bridge** (`DriveMediaLibraryBridgeService`): a port from the drive into the host's media library
  (the drive may call it, never the reverse; media keeps its own storage). Default: no library. A host with one
  overrides `isMediaLibraryAvailable()` and `importFileIntoMediaLibrary()` at the same path and reads the bytes with
  `saveReleasedContentToFile()`; the tool `importDriveFileToMediaLibrary` refuses until then.

## Install

```bash
composer require mgamadeus/ddd-clouddrive async-aws/s3
```

1. An object store named `clouddrive` (`config/app/Common/ObjectStorage.php`, `OBJECT_STORAGE_CLOUDDRIVE_*`), driver
   `s3` or `filesystem` (both stream; a host driver must implement `StreamingObjectStorage` of mgamadeus/ddd ≥ 3.43).
   Only the backend talks to it: no bucket CORS, no public access, no lifecycle rule (`app:drive:maintain` aborts
   unfinished multipart uploads after `unfinishedUploadExpiryHours`). A store that ignores `If-None-Match` on PUT sets
   `conditionalWrites: false`. The content URLs are signed with `CLOUDDRIVE_CONTENT_TOKEN_SECRET` (else `APP_SECRET`;
   at least 16 characters).
2. The tables: generate the models (`app:generate-doctrine-models-for-entities`) and apply the schema diff.
3. The host's rights as one or more `DriveSpaceRightsExtension` services (no `DriveSpace` subclass, see Rights), and
   its own mapping of records to spaces (a host table with `driveSpaceId`).
4. The content route, **without authentication** (the signed token in the URL is the authorisation; rights and scan
   state are checked again on every fetch, revoked rights answer 403): one controller with
   `use CloudDriveContentControllerTrait;` answering on `/api/public/drive/content` (in an application whose public
   controllers are prefixed `/api/public`: `#[Route('/drive/content')]`), or set
   `CloudDrive.settings.transfer.contentUrlPrefix` to the full path it answers on — an absolute URL when the frontend
   runs on another origin than the API. `GET|HEAD …/{token}` answers 200, 206 for one `Range`, 304 for `If-None-Match`, 403
   for an expired or revoked link; the fetch records the access (VIEWED / DOWNLOADED, deduplicated).
   The REST endpoints: one controller with `use CloudDriveControllerTrait;` (`#[Route('/common/cloudDrive')]`).
   Uploads: `POST …/spaces/{id}/uploads` (reserve), then `PUT …/uploads/{blobId}/content` (whole body) or
   `PUT …/uploads/{blobId}/parts/{partNumber}` (each part, `Content-Type: application/octet-stream`,
   `Content-Length`, optional `Content-MD5`; a part sent again replaces it), `POST …/uploads/{blobId}/parts` (the
   parts the store holds, to resume), `POST …/uploads/{blobId}/complete`.
   `POST …/cards` hydrates the `DriveFiles` widget with short-lived open, download and thumbnail URLs. It is a POST
   (the references are a body) but only reads: a host write guard that classifies routes by method lets it through
   like a GET. `GET …/nodes/{id}` answers `ancestors` (root first) for breadcrumbs and deep links. For a file
   manager: `items` beside the entities on lists (family, scan status, pending version, uncommitted working-copy
   changes, people's names, thumbnails, purge date); list filters on `…/children` and `…/search` (`recursive`,
   `query`, `family`, `createdFrom`/`createdTo`, `createdByMe`, `orderBy` name/created/size, `orderDirection`);
   `GET …/spaces/{id}/folders` (the folder tree), `…/spaces/{id}/trash`, `…/nodes/{id}/versions`, `…/nodes/{id}/events`;
   `POST …/nodes/{id}/versions/{versionId}/restore`; bulk `POST …/spaces/{id}/nodes/move|trash|restore` (200 per
   call, one transaction, skipped nodes with reasons); `POST …/spaces/{id}/nodes/purge` and `…/spaces/{id}/trash/empty`
   (manage right); `POST …/spaces/{id}/nodes/transfer/preview` and `…/nodes/transfer` (to another space);
   `driveNodeIds` on `…/children` and `…/search`; `approximateTotalCount` on unfiltered lists; `eventTypes` /
   `eventGroup` on `…/nodes/{id}/events`; `purpose=THUMBNAIL` on `…/nodes/{id}/content` (DOWNLOAD and INLINE are
   recorded as accesses); `GET …/spaces/{id}/resolve?path=` for a path bar and deep links (a path in the space,
   `drive/<id>`, `drivespaces/<mount>/…`, `/workspace/drive/…`; it may answer another space; 404 with
   `missingSegment`, `path` and `deepestFolder`; a host with its own mount names overrides
   `DriveSpacesService::findReadableSpaceByMountName()`). With `CloudDrive.settings.adminLinkTemplate` (placeholders
   `{spaceId}`, `{nodeId}`, `{folderNodeId}`, `{path}`) file cards, the `DriveLocation` widget and resolved locations
   carry `adminLink`, the host's deep link into its drive screen. The agent's `DriveFiles` cards carry the same link as `openLink` (with ddd-agents ≥ 0.1.33; an older ddd-agents gets no link). A partner controller overrides `getDriveAudience()` (SHARED) and
   `throwForbiddenUnlessCloudDriveAccessAllowed()`. The space answers carry a quota block (the effective quota, and `usedBytes` split into
   `currentVersionBytes`, `olderVersionBytes`, `trashedBytes` and `pendingBytes`) and, for pickers, `displayName`,
   `groupLabel` and `displayGroup` (`DriveSpacesService::buildSpaceDisplayName()` / `buildSpaceGroupLabel()` /
   `buildSpaceDisplayGroup()`, default the title, the kind's `label` and the kind in upper case); `spaces/list` is
   ordered by `compareSpaceListItems()` (`getSpaceDisplayGroupRank()`: the order of the kinds, then the name); people's names come from `DriveNodesService::findAccountDisplayNames()` and, per audience (e.g. the staff
   under the company's name for partners), `DriveActorNamesService::buildActorDisplayName()` — override them in the
   host. Further host hooks: `DriveSpacesService::isWriteAllowedForAudience()`,
   `DriveTypePoliciesService::buildTypePolicyForSpace()`, `DriveContentGrantsService::isVariantAllowed()`,
   and **who acts for whom**: `DriveChangesService::findActingAccountIdOfCurrentCaller()` (default the signed-in
   account) and `findOnBehalfOfAccountIdOfCurrentCaller()` (default none). A host with impersonation overrides both
   (the staff member acts, the impersonated account is acted for); every write path follows them — the creator of
   files and folders, the author and `onBehalfOfAccountId` of every version (upload, restore, transfer, agent copy,
   working copy commit), each change and access, and the release or block a worker records after the scan (it names
   the version's author and account acted for). Answers carry `createdOnBehalfOfAccountId` / `createdOnBehalfOfName`
   on nodes and `onBehalfOfAccountId` / `onBehalfOfName` on versions; names go through `buildActorDisplayName()`. An
   app-side bridge that set the version author itself can go. Contracts (autoconfigured):
   `DriveSpaceTransferListener`, `DriveNodeLifecycleListener`, `DriveSvgSanitizer`.
5. A cron for `app:drive:maintain` (every 15 minutes or hourly): system-folder expiry, trash and version retention,
   failed scans and lost ingests again (blobs an early release stored BLOCKED after a failed scan are put back first),
   the blob sweep (purged nodes are reported to `DriveNodeLifecycleListener`), and — only with
   `accessEventRetentionDays` above 0 — the deletion of older accesses (at most 20 × 500 rows per table and run). A scan takes seconds (clamscan loads its signatures each
   time): with `asyncIngest: true` the ingest runs in a worker — route `DriveIngestVersionMessage` to a transport
   `drive_ingest` and give it a consumer (worker list).
6. The malware scanner (`CloudDrive.settings.malwareScanner`, default `clamscan` with the limits of the `clamscan`
   block: `binary`, `databaseDirectory`, `maxFileSize`, `maxScanSize`, `maxRecursion`, `maxFiles`, `alertExceedsMax`,
   `alertEncrypted`, `timeoutSeconds`). It fails closed: a scanner that is missing, has no signatures, times out or is
   unknown by name leaves the blob `SCAN_FAILED` (never released, scanned again by the maintenance). A host's own
   scanner implements `DriveMalwareScanner` (autoconfigured) and is named in the setting; `none` releases unscanned
   (development only). The old `malwareScannerCommand` string still works (split into words, never a shell) but is
   deprecated.
7. With ddd-agents: the table `AgentSpaceDriveSpaces` (own drives; without it agent spaces get no own drive and
   nothing fails), bind the skill in `config/app/AI/skillPackages.php` by class:
   `'CloudDrive.Files' => [CloudDriveMCPPackage::class, 'agent.drive']`, and gate it with
   `CloudDriveAccountRights::USE_DRIVE` where the host maps rights. Override `DriveSpaceMountResolverService` for
   further mounts (e.g. a partner's file area as a shared space), `findOrCreateOwnDriveSpace()` when own drives live
   elsewhere, `findAgentAudienceOfCall()` for an agent that works for a partner, and `findWorkingCopyKeyOfCall()` when
   the sandbox's workspace key is not on every call (without a key the tools use the own drive's newest working copy).
   A working copy entry's `conversationKey` is the id of the delegation tree's root conversation. A partner's file
   area folds into `CloudDrive.Files`: mount its space, name the mount in the host's skill override.

`app:drive:spaces:create` creates a library space.

Untrusted input: every file in a drive is foreign. The module never decodes, converts or executes it on the server
(scanner and merge run as `IsolatedProcess`, downloads carry a server-chosen type); see the Core skill
`ddd-untrusted-input-specialist`.

Evals (ddd-agents ≥ 0.1.29): every case gets a throwaway own drive seeded from its `scratchFiles`; further mounts are
read-only during the case — for every write tool, whether it names the file by path or by `drive/<id>`; the refusal
tells the model to copy into the own drive — and the space is erased at the end (`--keepWorkspace` keeps it). A case
that exercises the write tools on a shared space works on a copy in its own drive.

## Host requirements (uploads and downloads through the backend)

One request carries at most one part, so the limits below hold for any file size. Defaults: `transfer.partBytes` and
`transfer.singlePartMaxBytes` 16 MB, `tokenLifetimeSeconds` 600, `streamChunkBytes` 1 MB.

- **Part size** — keep `partBytes` (and `singlePartMaxBytes`) below every body limit on the path: the web server's
  `client_max_body_size`, PHP's `post_max_size` and `upload_max_filesize`, and well below `memory_limit` (the body is
  never held in memory, but stay clear of it). A store needs parts of at least 5 MB; at most 10,000 parts per file, so
  the largest file is 10,000 × `partBytes` (160 GB at 16 MB; the part size grows for larger files).
- **Memory** — one part is in flight per upload and never held twice: PHP streams `php://input` (nginx's buffered temp
  file) into the store; the s3 driver spools a part once to `php://temp` (2 MB in memory, the rest on disk) to sign
  it. A 16 MB part stays far below a `memory_limit` of 128M.
- **nginx** — `client_max_body_size` above the part size (e.g. `20m` for 16 MB parts); `client_body_timeout` cuts a
  client that sends nothing for that long (default 60 s): the client sends the part again and completes at the end;
  `fastcgi_request_buffering on` (default) buffers the body before PHP starts, and `fastcgi_read_timeout` (e.g.
  300 s) then has to cover the store's upload of one part. Downloads: the backend sends `X-Accel-Buffering: no` and
  flushes per chunk; `fastcgi_buffering off;` on the content route does the same; no gzip on it.
- **PHP-FPM** — `post_max_size` and `upload_max_filesize` above the part size; `max_execution_time` 0 (FPM default)
  or above the longest download a client may need: a large file streams for as long as the client reads.
- **Reverse proxy / load balancer** (Traefik, …) — a read timeout that covers one part's request (Traefik
  `readTimeout` counts the whole request including the body, e.g. 600 s), `writeTimeout` 0 or above the longest
  download, an idle timeout above the pauses of a slow client; no buffering middleware on the content route.
- **Origins** — content URLs are relative to `transfer.contentUrlPrefix`; a frontend on another origin than the API
  gets an absolute prefix. No cookie or `Authorization` header is needed for them (`<img>`, `<video>`, links).

## Frontend

The npm package `@mgamadeus/ddd-clouddrive` lives in `web/` and is released with the same tag and version: the RTK Query
client (`.`), the drive screen (`./screen`, on the file manager of `@mgamadeus/ddd-admin-ui`) and the source of the
`DriveFiles` cards of `@mgamadeus/ddd-agents` (`./agents`). See [web/README.md](web/README.md).

## Server messages (text export)

`i18n/server-messages.json` lists the messages this package's endpoints answer with (user-facing exceptions, message
constants, refusal reasons), each with a hint for translators. A host merges it into its text export with
`vendor/bin/ddd-server-messages collect` (mgamadeus/ddd >= 3.42) and never prunes a key a package lists. After changing
a message: `php vendor/bin/ddd-server-messages build`, write the hint of a new key (at least 40 characters); CI runs
`build --check`.

## License

MIT
