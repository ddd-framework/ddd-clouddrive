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
  sanitised copy: the INLINE grant of an SVG serves the copy the host's `DriveSvgSanitizer` made (once per blob, at
  the first such grant, kept next to the blob) as `image/svg+xml`, for an `<img>`; without a sanitiser, for an SVG it
  refuses or one above 2 MB the grant is an attachment (`isInline` false). `hasSanitizedImage` on a list item says
  the picture is offered, on a file card that its `inlineUrl` is the copy. A host that wants inline PDFs and texts
  off the application's origin mounts the content route on
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
  store behind `agent.drive` (`CloudDriveAgentDriveStoreService`; also the content reader of the document tools
  `readDriveDocument` / `viewDriveDocumentPages`: the released version's bytes read from the store by the backend
  under the caller's download checks, never through a URL), the mount resolver a host overrides
  (`DriveSpaceMountResolverService`; by default one own drive per agent space, created on first use and linked in the
  table `AgentSpaceDriveSpaces`, owned by the agent space's account, `AgentOwnDriveSpaceRightsExtensionService`), the
  tool package `clouddrive.files` (`CloudDriveMCPPackage`: `driveStatus`, `commitDriveChanges`, `findDriveFiles`,
  `getDriveFile`, `listDriveFileEvents`, `getDriveSpace`, `organizeDriveFiles`, `createDriveFolder`,
  `trashDriveFiles`, `restoreDriveFiles`, `rescanDriveFileVersion`, `setDriveSpaceSettings`,
  `importDriveFileToMediaLibrary`, `showDriveFolder`, `showDriveLocation`), the widgets `DriveFolder` (a folder the
  owner browses in the canvas beside the chat) and `DriveLocation` (a link into the drive screen), the skill
  `CloudDrive.Files` and the right constant `CloudDriveAccountRights::USE_DRIVE`.
- **People and the agent write the same files** (with ddd-agents): a version a person saves beside an agent
  conversation (`agentConversationId` of `POST …/uploads/{id}/complete`, sent by the canvas of the web package) is
  noted in that conversation as the signal `DRIVE_FILE_CHANGED_BY_PERSON`
  (`CloudDriveConversationSignalSource`, `DriveAgentConversationNotesService`): "version 3 → 4, read it again". The
  note is written by the server from the write's facts, only into a conversation the caller may read; it starts no
  turn and marks the agent's earlier reads of the file as outdated. The agent's direct save (`saveDriveFile`) and the
  browser file sink write on top of the newest version they read (compare and swap); a copy made from an older
  version of the target than its newest is refused with the way out
  (`CloudDriveAgentDriveStoreService::findStaleBaseRefusal()`), never written over what someone saved meanwhile.
  **Since 0.4.0** (behaviour change): before, both wrote on top of whatever version was newest; a host flow that
  relied on that overwrite now gets a refused line instead.
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
   the blob sweep (purged nodes are reported to `DriveNodeLifecycleListener`), the abort of unfinished multipart
   uploads, and — only with `accessEventRetentionDays` above 0 — the deletion of older accesses (at most 20 × 500 rows
   per table and run). Failed scans and lost ingests are not redone in the cron: it dispatches one
   `DriveIngestVersionMessage` per version to the transport `drive_ingest` (blobs an early release stored BLOCKED
   after a failed scan are put back first) and reports how many; a version it dispatched is not dispatched again
   within `ingestDispatchLockMinutes` (60, a cache lock; a cache without locks dispatches every run, a second ingest
   is harmless). So the maintenance cron needs no ClamAV, no scan scratch directory and little memory (a small shared
   cron runner is enough); the scans run in the `drive_ingest` worker, which needs the scanner, its signatures and
   about 1.5 GB (clamscan loads its signatures for each scan). Route `DriveIngestVersionMessage` to `drive_ingest` and
   give it a consumer (worker list) — also with `asyncIngest: false` (uploads ingested in the request), because the
   maintenance always dispatches. A host that routes the message synchronously (no async transport) stays correct, but
   then the scans run inside the maintenance process and it needs the scanner and the memory again.
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
8. With ddd-agents ≥ 0.5.0, `php bin/console app:agent:check-installation` covers the drive:
   - **`driveRenditions`** — `officeRenditions.enabled` is on but `DriveOfficeRenditionMessage` is routed to no
     transport a worker consumes (`findRenditionRoutingProblem()`). BLOCKING: the rendition route then answers
     `FAILED` (`NOT_ROUTED_TO_WORKER`) for every file without a stored PDF. A WARNING where this environment starts no
     conversion (`DriveBlobsService::isDerivedObjectWriteAllowed()` answers false), since only stored PDFs are served
     there anyway.
   - **`missingTables`** — the drive's tables are required only while the drive is enabled, i.e. its object store
     (`objectStorageName`, default `clouddrive`) is configured (`DriveFeatureTableConditionProviderService`; a store
     whose configuration is broken counts as enabled). A host that installs the package without setting up the store
     gets no missing-table finding for it.
   Both register only beside ddd-agents ≥ 0.5.0 (`Services\Installation\` in
   `CloudDriveModule::getNamespacesRequiringClasses()`); `skippedChecks: ['driveRenditions']` in
   `config/app/AI/installationCheck.php` switches the check off.

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

## Office renditions (PDF preview of Office files)

Off by default (`CloudDrive.settings.officeRenditions.enabled`). When on, `GET {prefix}/nodes/{id}/rendition[?versionId=]`
answers `{ state: READY | PENDING | FAILED, reason?, grant? }` for docx, xlsx, pptx, odt, ods and odp; list items and
file cards say `hasPdfRendition`. The first call dispatches the conversion, the client asks again every few seconds.
The PDF is stored next to the blob (`<blob key>.rendition.pdf`), once per content, and is deleted with the blob. The
legacy formats doc, xls and ppt get no rendition. `GET …/content?purpose=RENDITION` grants a stored rendition and never
starts one.

The host provides:
- **Transport and consumer.** Route `DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage` to the transport
  `drive_render` and run a consumer for it. A conversion never runs inside a web request: while the message is routed
  to no asynchronous transport (no routing entry, or a `sync://` / `in-memory://` transport) the route answers a
  stored state and otherwise `FAILED` (`NOT_ROUTED_TO_WORKER`), dispatches nothing and writes one warning per process
  to the issues log. `DriveOfficeRenditionsService::findRenditionRoutingProblem()` says it as a sentence (null when
  nothing is wrong) for a host's installation or health check; with ddd-agents ≥ 0.5.0 `app:agent:check-installation` reports it (check `driveRenditions`). A console command still converts in-process.
  The consumer goes into the worker list (`config/system/workers.yaml`) in the same change as the transport: a
  consumer line without its transport crash-loops.
- **Route classification.** `GET …/rendition` is a read for the viewer (read-only viewers and impersonating admins get
  previews); it may dispatch a conversion and write marker objects next to the blob, never a table row.
- **Image.** The consumer's image needs LibreOffice (`soffice`) and fonts; coreutils `timeout` and util-linux `prlimit`
  are used when present (`timeout` ends the whole process group).
- **No network for the consumer.** The package cannot cut the network from PHP; give the worker an isolated egress
  (it needs the object store, the database and the broker, nothing else).

The package guarantees:
- LibreOffice only sees local files: PHP downloads the blob to a path it names and uploads the PDF; the converter
  never gets a URL.
- The ZIP container is inspected before it is opened (entries, expanded size, compression ratio, entry names, the
  format's marker entry).
- LibreOffice runs without a shell and without any inherited environment variable (only PATH, HOME, TMPDIR, LANG — no
  store, database or broker credential is in its environment), with a timeout and an address space cap, and with a
  throwaway profile per job (`-env:UserInstallation=file://<job directory>/profile`): macros off, no link updates, no
  linked or remote content, no recalculation on load, no Java, no update check, no crash reporter, no recovery.
- Only the first `maxPages` pages are exported; the output is stored only when it is a PDF of at most 100 MiB; the
  working directory is always removed; a reader sees a plain reason, never the converter's output.
- The request path and the worker path take no cache lock and make no cache call of their own: state and
  deduplication live in the object store (the rendition, a pending marker and a failure marker next to the blob).

Limitation: the converter runs with the worker's own uid, so it could read `/proc/<worker pid>/environ` (the worker's
environment holds the credentials). Where the image can switch the uid, set `commandPrefix` (e.g.
`['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '--']`); a pod that runs unprivileged without
capabilities cannot (then the isolated egress is what keeps a credential from leaving).

Settings (`officeRenditions`): `enabled` (false), `maxSourceBytes` (50 MB), `timeoutSeconds` (120; a conversion nobody
answered for twice as long is dispatched again), `maxPages` (300), `binary` (`soffice`), `commandPrefix` (`[]`),
`addressSpaceBytes` (4 GiB, `null` = no cap). A failed conversion is kept for 24 hours (10 minutes when the converter
could not start), then the next request tries again.

**A host whose reads must write nothing** (a workspace that reads another environment's store and queue) overrides
`DriveBlobsService::isDerivedObjectWriteAllowed()` in a same-path subclass. (The rendition hook of 0.4.0,
`DriveOfficeRenditionsService::isConversionStartAllowed()`, is deprecated, still honoured for the rendition and
removed in 0.5.0; its default answers this switch.) It is the one switch for every object the
drive derives from a blob while a file is only read. Where it answers false, the rendition route serves a stored PDF
and otherwise `FAILED` ("This file has no PDF preview yet, and none is created in this environment."): no marker is
read, written or removed and nothing is dispatched. An SVG is served as its sanitised copy only when the copy
exists; none is made, and the SVG stays an attachment. Uploads, versions and purges are writes of their own and do
not ask the switch.

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
