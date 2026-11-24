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
- **Bytes**: direct presigned upload and download, PHP never proxies bytes. Every object is written once: a key per
  blob, `If-None-Match: *` signed into each PUT, multipart uploads completed only by the server onto a free key,
  `Content-MD5` verified by the store. The ingest computes the SHA-256, takes the content type from the bytes, scans,
  deduplicates per space and releases or blocks. People read only released content (`DriveContentGrantsService`):
  inline only for JPEG, PNG, GIF, WebP and PDF in their own type and for plain texts (Markdown, CSV, JSON, …) as
  `text/plain; charset=utf-8`; everything else as an attachment (HTML, SVG, XML, JavaScript as
  `application/octet-stream`). Content is served by presigned URLs of the object store, a different origin than the
  application, so a PDF opened there reaches no cookie or session of the application; the store's endpoint must
  never share the application's origin. `resolveInlineContentType()` is the one rule (a host narrows it in a
  same-path subclass).
- **Working copies** (`DriveWorkingCopiesService`): a sandbox saves changes (`stageChanges()`), commits them with a
  compare and swap on each file's newest version (`commitChanges()`), pulls what others changed
  (`findChangesSince()`). A conflict is never overwritten: it stays saved until it is resolved with mine, theirs or
  both. `DriveTextMergeService` merges text three-way with `git merge-file`.
- **Agent part** (only with ddd-agents installed): the drive store behind `agent.drive`
  (`CloudDriveAgentDriveStoreService`), the mount resolver a host overrides (`DriveSpaceMountResolverService`), the
  tool package `clouddrive` (`driveStatus`, `commitDriveChanges`, `findDriveFiles`, `getDriveFile`,
  `organizeDriveFiles`, `trashDriveFiles`, `restoreDriveFiles`, `showDriveLocation`), the widget `DriveLocation` and
  the skill `Assistant.Drive`.

## Install

```bash
composer require mgamadeus/ddd-clouddrive async-aws/s3
```

1. An object store named `clouddrive` (`config/app/Common/ObjectStorage.php`, `OBJECT_STORAGE_CLOUDDRIVE_*`). A store
   that ignores `If-None-Match` on PUT sets `conditionalWrites: false`. Bucket CORS for the app origins; a lifecycle
   rule that aborts unfinished multipart uploads.
2. The tables: generate the models (`app:generate-doctrine-models-for-entities`) and apply the schema diff.
3. The host's rights as one or more `DriveSpaceRightsExtension` services (no `DriveSpace` subclass, see Rights), and
   its own mapping of records to spaces (a host table with `driveSpaceId`).
4. The REST endpoints: one controller with `use CloudDriveControllerTrait;` (`#[Route('/common/cloudDrive')]`).
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
7. With ddd-agents: override `DriveSpaceMountResolverService` (own drive per agent space, further mounts,
   `findAgentAudienceOfCall()` for an agent that works for a partner, and
   `findWorkingCopyKeyOfCall()` when the sandbox's workspace key is not on every call; without a key the tools use the
   own drive's newest working copy) and bind the skill in `config/app/AI/skillPackages.php`:
   `'Assistant.Drive' => ['clouddrive', 'agent.drive']`. A working copy entry's `conversationKey` is the id of the
   delegation tree's root conversation.

`app:drive:spaces:create` creates a library space.

Untrusted input: every file in a drive is foreign. The module never decodes, converts or executes it on the server
(scanner and merge run as `IsolatedProcess`, downloads carry a server-chosen type); see the Core skill
`ddd-untrusted-input-specialist`.

Evals (ddd-agents ≥ 0.1.29): every case gets a throwaway own drive seeded from its `scratchFiles`; further mounts are
read-only during the case; the space is erased at the end (`--keepWorkspace` keeps it).

## Frontend

The npm package `@mgamadeus/ddd-clouddrive` lives in `web/` and is released with the same tag and version: the RTK Query
client (`.`), the drive screen (`./screen`, on the file manager of `@mgamadeus/ddd-admin-ui`) and the source of the
`DriveFiles` cards of `@mgamadeus/ddd-agents` (`./agents`). See [web/README.md](web/README.md).

## License

MIT
