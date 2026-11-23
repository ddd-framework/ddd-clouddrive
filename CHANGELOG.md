# Changelog

## 0.1.0-rc.15

- Added: the agent's file cards (`DriveFiles` widget, hydrated through ddd-agents' `POST …/driveFiles/cards`) carry the
  host's deep link as `AgentDriveFile::$openLink`, built from `CloudDrive.settings.adminLinkTemplate` (null without a
  template). Needs ddd-agents 0.1.33; with an older ddd-agents nothing changes (the field is set only when it exists).

## 0.1.0-rc.14

- Added (locations): `GET …/spaces/{driveSpaceId}/resolve?path=` resolves a pasted location under the caller's
  rights: a path in the space (`/Templates/Typst/letter.typ`, without or with a leading slash), a reference
  `drive/<id>` (any readable space; a trashed node only for INTERNAL), a mount path `drivespaces/<mount>/…` and an
  agent sandbox path `/workspace/drive/<mount>/…` or `/workspace/drive/<folder>/…` (the requested space when the name
  is no mount). Answer `{driveSpaceId, item, ancestors, path, adminLink}`; `driveSpaceId` differs from the requested
  one for a mount path or a reference. Not found: 404 with `missingSegment`, `path`, `driveSpaceId` and
  `deepestFolder` (`DriveLocationNotFoundException`). `..` answers 400. Service: `DriveNodesService::resolveLocation()`
  (`DriveLocationReference`, `DriveLocationResolution`); mount names → spaces through the hook
  `DriveSpacesService::findReadableSpaceByMountName()` (default `library` / `space-<id>` = `DriveSpace::buildMountName()`).
- Added (deep links): `CloudDrive.settings.adminLinkTemplate` (default null) with `{spaceId}`, `{nodeId}`,
  `{folderNodeId}` (a file's folder) and `{path}` (leading slash, URL-encoded). When set, `DriveFileCard::$adminLink`
  (`POST …/cards`), `DriveLocationConversationWidget::$adminLink` (`showDriveLocation`) and the resolve answer carry the
  link; `DriveNodesService::buildAdminLink()` / `fillAdminLinkTemplate()` build it.

## 0.1.0-rc.13

- Added (retention): `CloudDrive.settings.accessEventRetentionDays` (default 0 = unlimited). Above 0, `app:drive:maintain`
  deletes accesses older than that many days where they are stored: `READ_BY_AGENT` from the change feed (with its
  context rows), `VIEWED` / `DOWNLOADED` from `DriveActivityEvents` (skipped without the table); at most
  `DriveMaintenanceService::MAX_ACCESS_EVENT_PURGE_BATCHES` × `BATCH_SIZE` (20 × 500) rows per table and run, one line
  with the counts (`--dryRun` counts). Changes of every other type are never deleted by age (only with their space).
  Hook: `DriveMaintenanceService::getAccessEventRetentionDays()`.
- Added (accesses): the client's public IP of a VIEWED / DOWNLOADED in the new nullable column
  `DriveActivityEvents.clientIp` (VARCHAR(45), IPv4 or IPv6) from Symfony's `Request::getClientIp()` under the host's
  trusted proxies (a forwarding header is never read raw; hook `DriveActivityEventsService::findClientIpOfCurrentRequest()`);
  null for agent reads and the console. The dedup key stays actor + node + version + type: the first IP of the window
  is kept. History items carry `clientIp` only for the INTERNAL audience, never in a SHARED answer.
- Schema: the host's schema diff adds `DriveActivityEvents.clientIp` (`VARCHAR(45) DEFAULT NULL`). Until then the
  column is neither written nor read (checked once per process like the table; restart the workers after adding it)
  and everything else keeps working.

## 0.1.0-rc.12

- Added (space list): `DriveSpaceListItem::$displayGroup` (a group code for ordering and icons) from the host hook
  `DriveSpacesService::buildSpaceDisplayGroup()` (default the kind in upper case); `spaces/list` is ordered by
  `compareSpaceListItems()` — `getSpaceDisplayGroupRank()` of the code (default the order of the kinds in
  `spaceKinds.php`, no group last), the code, the display name (case-insensitive), the id. An application with its own
  labels and order overrides these hooks (same-path subclass); no own list building needed.
- Added (usage): `DriveSpaceQuota` gains `effectiveQuotaBytes` (= `quotaBytes`) and the split of `usedBytes` into
  `currentVersionBytes`, `olderVersionBytes`, `trashedBytes` (released blobs, each counted once: current version of a
  live file, else trash, else older version or awaiting the sweep) and `pendingBytes` (the rest of the counter:
  uploads in flight, waiting for or refused by the scan, marked for purge). One grouped query per list
  (`DriveBlobsService::findSpaceUsages()`).
- Added (list items): `latestVersionNumber`, `latestVersionScanStatus`, `latestVersionStateReason` when the newest
  version is not the current one. The scan status `BLOCKED` names a type-policy refusal (a malware finding stays
  `INFECTED`); the download refusal says which.
- Fixed: an ingest message without an account was dropped silently (the version lookup ran under rights). The ingest
  handler runs as a system job without rights restrictions and logs a version that does not exist.
- Fixed: blobs rc.1 stored BLOCKED after a failed scan (reason `scan failed …`) were never scanned again.
  `app:drive:maintain` puts them back to SCAN_FAILED (compare-and-set, idempotent, listed with a count, `--dryRun`)
  before the rescan step, which releases their versions when clean. Malware findings and type-policy refusals are not
  touched.

## 0.1.0-rc.11

- Added (audience): the caller acts as INTERNAL (staff, default) or SHARED (`DriveSpacesService::getCurrentAudience()`,
  `enableAudience()` / `restoreAudience()`, reset to INTERNAL between requests and Messenger jobs). Every WRITE / MANAGE
  check goes through `isRightAllowedForAudience()`: SHARED never manages, and writes only where
  `isWriteAllowedForAudience(DriveSpace, string $audience)` allows it (default: not while `isAudienceWriteLocked`;
  override at the same path). `CloudDriveControllerTrait::getDriveAudience()` (default INTERNAL): a second controller
  with its own path, access hook and SHARED mounts the actions for partners (purge, empty trash and other manage
  actions answer 403).
- Added (type policy): `DriveTypePolicy` per space kind and audience from `config/app/CloudDrive/typePolicies.php`
  (`allowedFamilies`, `allowedExtensions`, `allowedContentTypes`, `maxFileSizeBytes`, `refusesContentMismatch`,
  a `shared` block for SHARED), host hook `DriveTypePoliciesService::buildTypePolicyForSpace(DriveSpace, string $audience)`.
  Checked at the reservation (new optional `fileName`, the announced type, the size), at every write (uploads, commits,
  copies, transfers) and at the ingest (the bytes libmagic reads must fit the name's family, programs are refused; a
  refused content is BLOCKED with the reason `type policy: …`). A restricted policy sanitises uploaded names
  (`DriveTypePolicy::sanitizeFileName()`). The default `default => []` restricts nothing.
- Added (variants): `DriveContentGrant::PURPOSE_THUMBNAIL` beside DOWNLOAD and INLINE; the variant rule
  `DriveContentGrantsService::isVariantAllowed(DriveNode, string $variant, string $audience)` (default: a trashed file
  only as the staff's DOWNLOAD). Grants name `driveNodeId`, `driveFileVersionId`, `isInline`. An SVG is shown inline only
  through a host's `DriveSvgSanitizer` (tag `clouddrive.svg_sanitizer`): its sanitised copy is stored next to the blob
  and served as `image/svg+xml`.
- Added (transfers): `DriveSpaceTransfersService::previewTransfer(DriveNodes, DriveSpace $target, ?DriveNode $folder)`
  (`DriveSpaceTransferPreview`: counts, bytes, target quota, taken names, refused files, lost links) and
  `transferNodes(…, ?bool $async)` (`DriveSpaceTransferResult`: source → target ids, skips); routes
  `POST …/spaces/{id}/nodes/transfer/preview` and `…/nodes/transfer`. A copy in the store per file's current version, the
  target's ingest, then the moved source nodes purged. Host contract `DriveSpaceTransferListener` (tag
  `clouddrive.space_transfer_listener`): `findLinksLostOnTransfer()`, `onNodesTransferred()`. As a job with
  `asyncTransfer` (`DriveSpaceTransferMessage` on the transport `drive_ingest`).
- Added (lists): `driveNodeIds` (comma-separated, at most 500) on `…/children` and `…/search`; `approximateTotalCount` on
  unfiltered lists (from counters).
- Added (purge): `DriveNodeLifecycleListener` (tag `clouddrive.node_lifecycle_listener`) `onNodesPurged(DriveSpace,
  int[] $driveNodeIds)` after every purge (purge, empty trash, trash retention, a transfer's source, the erase of a
  space).
- Added (activity): new table `DriveActivityEvents` (`DriveActivityEvent`): accesses `VIEWED` (INLINE) and `DOWNLOADED`
  (DOWNLOAD) recorded by `GET …/nodes/{id}/content` when the URL is granted (THUMBNAIL and the drive tools' cards are
  never recorded; the same person, file, version and kind within `accessEventDedupSeconds`, default 600, once); host
  events `DriveChangesService::recordHostEvent(DriveNode, string $eventType, ?string $detail, ?int $actorAccountId,
  ?int $onBehalfOfAccountId)`; the context of a change (on behalf of whom, as which audience;
  `recordChange(…, onBehalfOfAccountId:)`, hook `findOnBehalfOfAccountIdOfCurrentCaller()`). `GET …/nodes/{id}/events`
  merges them with the changes (`eventSource`, `onBehalfOfAccountId`, `onBehalfOfName`, `audience`, `contentVariant`,
  `detail`) and takes `eventTypes` and `eventGroup` (`changes` / `accesses`).
- Added (names): `DriveActorNamesService::buildActorDisplayName(?int $actorAccountId, ?int $onBehalfOfAccountId, string
  $audience)` / `findActorDisplayNames()`; every name of the lists, versions and history goes through it
  (default: `DriveNodesService::findAccountDisplayNames()`).
- Added (agent): `DriveSpaceMountResolverService::findAgentAudienceOfCall()` (default INTERNAL): the drive tools act
  as that audience; a space write-locked for it is mounted read-only.
- Schema: one new table, `DriveActivityEvents` (apply the schema diff). Without it nothing is recorded and the history
  shows the changes only (checked once per process: a table added later is noticed after the workers' restart). No
  other table changes.

## 0.1.0-rc.10

- Added (file manager backend, all on `CloudDriveControllerTrait`, same access hook and space rights):
  - `items` beside the entities on every list (`DriveNodeListItem`: family, extension, scan status of what the file
    shows, `hasPendingVersion`, `hasWorkingCopyChanges`, creator / trasher names, thumbnail URL of a released JPEG,
    PNG, GIF or WebP, `purgeAfter` in the trash); `item` on the node answer.
  - List filters on `GET …/spaces/{id}/children` and `…/search`: `recursive`, `query`, `family` (`DriveFileFamily`:
    PDF, SPREADSHEET, DOCUMENT, PRESENTATION, TEXT, IMAGE, VECTOR, VIDEO, ARCHIVE), `createdFrom` / `createdTo`
    (`Y-m-d`), `createdByMe`, `orderBy` (`name`, `created`, `size`), `orderDirection`; a filtered or reordered list
    pages with an offset cursor (`o:<n>`). Without them both answer as before.
  - `GET …/spaces/{id}/folders` (every live folder flat, at most 5000, `isTruncated`), `GET …/spaces/{id}/trash`
    (nodes trashed on their own, newest first), `GET …/nodes/{id}/versions` (`DriveFileVersionListItem`: scan status,
    author, `isCurrent`, `isLatest`, `isRestorable`), `GET …/nodes/{id}/events` (the node's history, newest 20).
  - `POST …/nodes/{id}/versions/{versionId}/restore`: the old version's released content becomes the newest version
    (`Restored from v<n>`, no bytes copied, the blob is shared); blocked or unscanned content is refused.
  - Bulk `POST …/spaces/{id}/nodes/move`, `…/trash`, `…/restore` (`driveNodeIds`, at most 200, one transaction;
    `DriveNodeBulkResult` with counts and skipped nodes with reasons).
  - `POST …/spaces/{id}/nodes/purge` and `…/spaces/{id}/trash/empty` (manage right): trashed nodes are deleted for
    good now (subtree, versions, a PURGED change), their unreferenced blobs are deleted from the store and their bytes
    given back to the quota; emptying purges 200 per call (`hasMore`).
  - Spaces: `items` on the list and `item` on one space (`DriveSpaceListItem`: `displayName`, `groupLabel`,
    `DriveSpaceQuota`, `canWrite`, `canManage`). Hooks for hosts: `DriveSpacesService::buildSpaceDisplayName()`,
    `buildSpaceGroupLabel()` (default: the title, the kind's `label` setting), `DriveNodesService::findAccountDisplayNames()`
    (default: none).
- Fixed: a folder listing put files before folders (it ordered by the node type's name); folders come first now, the
  page cursor of older clients keeps working.
- No schema change.

## 0.1.0-rc.9

- Changed (untrusted input, needs mgamadeus/ddd ^3.40.0): the malware scan is a scanner contract that fails closed.
  `DriveMalwareScanner` (tag `clouddrive.malware_scanner`, autoconfigured), selected by the setting `malwareScanner`
  (default `clamscan`; `none` releases unscanned, development only). The module's `ClamscanDriveMalwareScannerService`
  runs `clamscan` as an isolated process (argument array, no shell, stripped environment, timeout) with the limits of
  the `clamscan` block (defaults: `maxFileSize` 2000M, `maxScanSize` 4000M, `maxRecursion` 16, `maxFiles` 10000,
  `alertExceedsMax` and `alertEncrypted` true, `timeoutSeconds` 600, `databaseDirectory` null). An unknown or empty
  scanner name, a missing scanner, missing signatures or a timeout leave the blob `SCAN_FAILED`; before, an empty
  command meant CLEAN and a scan ran through a shell string.
- Deprecated: `malwareScannerCommand`. A host that still sets it keeps working: the string is split into words and run
  by the clamscan scanner without a shell. An empty value no longer switches the scan off (use `malwareScanner: none`).
- Changed: the ingest takes a blob's content type from its bytes (JPEG, PNG, GIF, WebP, HEIC by their magic bytes;
  bytes that only claim an image type become `application/octet-stream`). Inline previews only for JPEG, PNG, GIF and
  WebP (an allow-list, before an exact-match deny-list that `text/html; charset=utf-8` passed); downloads of HTML,
  SVG, XML and JavaScript go out as `application/octet-stream` attachments with an RFC 6266 file name.
- Changed: `DriveTextMergeService` runs `git merge-file` as an isolated process (stripped environment, 30 s timeout).
- Added: the sink scan test (`tests/UntrustedSinkScanTest.php`, `config/untrusted-sinks-allowlist.php`).

## 0.1.0-rc.8

- Fixed: the maintenance measured a waiting blob's age (lost ingest) and a system-folder file's age (expiry) by
  `updated`, which partial writes never stamp: such rows never matched, so a lost ingest stayed "pending scan" forever.
  Both now use `COALESCE(updated, created)`; the newest working copy is chosen the same way.

## 0.1.0-rc.7

- Added: async ingest. Every write path (upload completion, working copy commit, copy into a space) goes through
  `DriveVersionsService::ingestVersion(version, isScanned, workingCopyKey, ?async)`: with the setting
  `CloudDrive.settings.asyncIngest` (default false) it dispatches `DriveIngestVersionMessage` to the transport
  `drive_ingest` (`DriveIngestVersionHandler`; the host routes it and gives it a consumer), else it ingests inline. A
  seed (`writeFileContent()`) always ingests inline.
- Added: `app:drive:maintain` redoes the ingest of versions whose blob waits PENDING or STAGED longer than
  `staleIngestMinutes` (default 15): a lost worker run (`DriveMaintenanceService::reingestStaleVersions()`).

## 0.1.0-rc.6

- Added: `DriveWorkingCopyListener` (tag `clouddrive.working_copy_listener`) for a host with a live sandbox:
  `beforeWorkingCopyRead()` before a status, a commit, the commit before a copy and before a card of an own-drive file
  (flush the sandbox's unsaved changes with `stageChanges()`), `afterWorkingCopyCommitted(wc, result, resolutions)`
  after every commit (move the sandbox's base, apply theirs / both). A listener that throws is logged and skipped
  (`DriveWorkingCopyListenersService`). `DriveWorkingCopiesService::findCurrentEntries()` flushes, then reads.
- Changed: `showDriveFiles` (the store's `findDriveFiles()`) commits a saved change of an own-drive file first, so the
  card shows what the sandbox holds.

## 0.1.0-rc.5

- Added: eval isolation as standard (with mgamadeus/ddd-agents ≥ 0.1.29): every eval case gets a throwaway own drive
  space keyed on its workspace key (`DriveSpace::$scratchKey`, kind `evalScratch`), seeded from the case's
  `scratchFiles`; while the case runs, that space is the agent's own drive and every further mount is read-only; at the
  case's end it is erased (kept with `app:ai-agent:eval-tools --keepWorkspace`). `CloudDriveEvalWorkspaceParticipantService`.
- Added: `DriveVersionsService::writeFileContent()` (a file from content the server holds), `DriveMaintenanceService::eraseSpace()`,
  `DriveSpacesService::findSpaceByScratchKey()`. New column `DriveSpaces.scratchKey`: apply the schema diff.

## 0.1.0-rc.4

- Fixed: the agent tools find the own drive's working copy without a workspace key on the call:
  `DriveSpaceMountResolverService::findWorkingCopyKeyOfCall()` (default: the call's key), else the space's newest
  working copy (`DriveWorkingCopiesService::findNewestWorkingCopy()`); `CloudDriveAgentDriveStoreService::findOwnWorkingCopyOfCall()`.
- Fixed: `commitDriveChanges` without paths commits the changes of the delegation tree's root conversation
  (`getTreeRootConversationId()`), not of a child's own id.
- Fixed: a source of the own drive whose saved change cannot be committed before a copy is refused with the reason in
  the tool result (before: logged and copied in its older version).
- Fixed: `createWorkspaceRestoreGrant()` refuses a BLOCKED blob; SCAN_FAILED stays restorable into the sandbox only.
- Fixed: `DriveSpaceRightsContractTestCase` signs the account out after each test, so it runs beside other tests.

## 0.1.0-rc.3

- Added: `GET …/nodes/{id}` answers `ancestors` (the folders above the node, root first) for breadcrumbs and deep
  links; `DriveNodesService::findAncestors()`.
- Docs: `POST …/cards` only reads (a host write guard by method lets it through).

## 0.1.0-rc.2

- Fixed: `DriveSpaceRightsContractTestCase` passed a call result into the by-reference `AuthService::setAccount()`
  ("Only variables should be passed by reference"); a host can run it now.
- Changed: a scanner that cannot run (exit other than 0 or 1: missing, no signatures) leaves the blob in the new state
  `SCAN_FAILED` instead of `BLOCKED`: not readable by people, scanned again by `app:drive:maintain` (versions are
  released when clean). `BLOCKED` stays the state of a finding. `DriveBlobsService::scanFile()` answers a
  `DriveScanResult` (CLEAN, INFECTED, FAILED). The setting `malwareScannerCommand` names `--database=<dir>` for
  signatures outside clamscan's default place.
- Changed: every id reference column that is no foreign key (`driveSpaceId` of versions, blobs, events and entries,
  the node and version references) is BIGINT like the ids; apply the schema diff.

## 0.1.0-rc.1

- First release candidate: spaces with host rights, folders, files, versions, blobs with write-once presigned uploads
  (single and multipart), the ingest (SHA-256, scan, dedup), content grants and file cards, trash and restore, tags,
  the change feed, working copies (stage, commit with compare and swap, conflicts with mine / theirs / both, pull),
  three-way text merge, maintenance (`app:drive:maintain`), the REST trait, and the agent part (drive store, mount
  resolver, tool package `clouddrive`, widget `DriveLocation`, skill `Assistant.Drive`).
- Not yet: fulltext and semantic search layers, link nodes, saved views, ZIP export, the processing pipeline (derive,
  enrich) and a standalone kernel.
