# Changelog

## 0.5.0

**The drive is a file source, and takes files of other sources.** Requires `mgamadeus/ddd` ^3.48.0 (the `FileSource`
port). No table change; the npm package is unchanged apart from its version.

- **New `CloudDriveFileSourceService`** (source `drive`, reference `drive/<nodeId>`): facts and bytes of a file node
  under the reader's rights, the released current version only (a file in the scan, blocked or in the trash has no
  content), read through `resolveContentDelivery()` and the object storage on the server. Audience: PERSONAL below a
  personal root folder (`CloudDrive.settings.personalFolders`, none by default: a host that keeps people's own data in
  root folders names them, e.g. `['customer-files']`), SHARED in a shared space, else INTERNAL. Objects of the blob store that are no drive file (other prefixes of the same bucket) are never
  reachable: the source reads node → version → blob only.
- **New `DriveImportsService::importFiles(FileReferences $fileReferences, DriveSpace $driveSpace, string $folderPath,
  ?DriveImportOptions $driveImportOptions = null): DriveImportResult`**: copies files of any `FileSource` into a
  folder. Per file: the write right of the space, the application's flow rule
  (`FileFlowPolicyService::decideFileFlow()` with `FileFlowTarget::forStore('drive', …)`; a refusal becomes a line of
  the result, a needed consent stops the file until `DriveImportOptions::$hasOwnerConsent`), the bytes read capped
  (`CloudDrive.settings.maxImportBytes`, default 50 MB), metadata removed when the rule says so, then the drive's own
  write path (version, ingest and scan as every upload; origin `IMPORT`, comment `imported from <label>
  (<reference>)`). The history shows `IMPORTED from <label> (<reference>)` (a host event, no schema change);
  `expiresAfterDays` of the decision is set on the node. At most 50 files per call. Working copies are asked to pull.
- **`CloudDriveAgentDriveStoreService::saveDriveFiles()` copies across stores**: a source that is a reference of
  another file source (ddd-agents ≥ 0.7.0 hands it over with the mount store `files`) goes through
  `DriveImportsService`; the owner's consent of a `WRITE_WITH_CONSENT` mount counts as the consent of the flow rule.
- New `DriveNode::ORIGIN_IMPORT`.
- **Removed (deprecated since 0.4.1): `DriveOfficeRenditionsService::isConversionStartAllowed()`.** The rendition route
  and the installation check `driveRenditions` ask `DriveBlobsService::isDerivedObjectWriteAllowed()`, the one switch for
  every derived object. A host's same-path override of the removed method no longer decides anything: move it to the
  switch.
- **Fix: `DriveWorkingCopiesService::findNewestWorkingCopy()` threw a DQL syntax error** (`ORDER BY COALESCE(…)`:
  DQL orders by a selected value only). The last change is a hidden select now. The lookup was reached only by a
  caller without a working copy key (a sandbox that named none, the browser file sink, and now the import).
- Tests: the source (rights, audiences, unreleased and trashed files, foreign objects of the bucket never read), the
  import (rights, refusal, consent, cap, metadata, expiry, history), the agent store's cross-store save.


## 0.4.2

The drive takes part in `app:agent:check-installation` of `mgamadeus/ddd-agents` ≥ 0.5.0 (registered only beside it,
`Services\Installation\` keyed to its port `AgentFeatureTableConditionProvider`). No table change, PHP API otherwise
unchanged; the npm package is unchanged apart from its version.

- **Check `driveRenditions`** (`DriveRenditionsInstallationCheckService`): `officeRenditions.enabled` is on but
  `DriveOfficeRenditionMessage` is routed to no transport a worker consumes — the sentence of
  `DriveOfficeRenditionsService::findRenditionRoutingProblem()`. BLOCKING, since the rendition route then answers
  `FAILED` (`NOT_ROUTED_TO_WORKER`) for every file without a stored PDF; a WARNING where this environment starts no
  conversion (the switch for derived objects, or the deprecated `isConversionStartAllowed()`, answers false). Nothing
  is reported with renditions off or routed to a worker.
- **Tables only while the drive is enabled** (`DriveFeatureTableConditionProviderService`): `missingTables` requires
  the drive's tables (every DB entity of the package, `DRIVE_TABLE_ENTITY_CLASSES`) only while the drive's object
  store (`CloudDrive.settings.objectStorageName`) is configured; a store whose configuration throws counts as enabled.
  Before, every drive table was required on every host with the check (ddd-agents ≥ 0.4.0).
- `DriveBlobsService::getObjectStorageName()` and `DEFAULT_OBJECT_STORAGE_NAME`.
- `mgamadeus/ddd-agents` ^0.5.5 as dev version (the suite runs against it); the conflict stays `<0.3.13`, so 0.3.14
  up to 0.5.x install. `isConversionStartAllowed()` stays deprecated and is removed in 0.5.0.

## 0.4.1

Needs `@mgamadeus/ddd-admin-ui` ≥ 0.8.1 for the picture of an SVG. No table change.

- **Deprecated, still honoured, removed in 0.5.0:** `DriveOfficeRenditionsService::isConversionStartAllowed()`. The
  rendition route still asks it, and its default now answers `DriveBlobsService::isDerivedObjectWriteAllowed()`, so a
  host's 0.4.0 same-path override keeps deciding for the rendition. The sanitised SVG copy asks only the new switch.
  Move the override to `DriveBlobsService::isDerivedObjectWriteAllowed()` (a same-path subclass of the blobs service,
  with its service alias) before 0.5.0.
- **A conversion never runs inside a web request.** With `officeRenditions.enabled` on and
  `DriveOfficeRenditionMessage` routed to no asynchronous transport (no routing entry, or a `sync://` /
  `in-memory://` transport), Messenger would convert inside the request. The rendition route of a web request then
  answers a stored state (a PDF that exists stays READY) and otherwise `FAILED` with the reason code
  `NOT_ROUTED_TO_WORKER` ("This file has no PDF preview yet: PDF previews are not set up to be created in the
  background here."), writes no marker and dispatches nothing; the issues log gets one warning per process. The
  routing is read from the compiled container (Core's `WorkerTransportsService`, matched as Messenger's senders
  locator matches: the class, a parent class, an interface, `*`). The command line (a console command, a worker)
  still converts in-process. `DriveOfficeRenditionsService::isRenditionMessageRoutedToWorker()` and
  `findRenditionRoutingProblem()` (a sentence, or null) are public for a host's installation or health check.
- `DriveBlobsService::isDerivedObjectWriteAllowed()` (default true) is the host's one switch for an environment whose
  reads must leave store and queue untouched (a workspace on another environment's data). It covers every object the
  drive derives from a blob while a file is only read: there the rendition route only serves a stored PDF (`FAILED`
  otherwise, nothing dispatched, no marker), and an SVG is served as its sanitised copy only when the copy exists
  (none is made; the SVG stays an attachment).
- **An SVG as a picture in the viewer:** `hasSanitizedImage` on `DriveNodeListItem` (the server offers the picture: a
  sanitiser is registered, the released SVG is within 2 MB) and on `DriveFileCard` (its `inlineUrl` is the sanitised
  copy); `DriveContentGrantsService::isSanitizedImageOffered()`. The content route is unchanged: an INLINE grant of an
  SVG serves the sanitised copy as `image/svg+xml`, inline, `nosniff`, with the sandbox CSP, and a download the
  uploaded file as an opaque attachment. The copy is made at the first such grant of a blob and kept next to it, so
  files uploaded before get their picture too. Web: the adapter answers `getDownload` variant `SANITIZED_IMAGE` with
  the grant's URL only when the grant is inline and `image/svg+xml` (`isSanitizedImageGrant`), otherwise `failed` —
  the viewer of `@mgamadeus/ddd-admin-ui` ≥ 0.8.1 then draws it in an `<img>` with zoom, in the drawer, the file
  canvas and the folder canvas; a `DriveFiles` card of such a file opens the file canvas. `DriveContentGrant.isInline`
  in the web types.

## 0.4.0

Needs `@mgamadeus/ddd-admin-ui` ≥ 0.8.0 and, for the canvases, `mgamadeus/ddd-agents` / `@mgamadeus/ddd-agents` ≥ 0.3.14.
No table change.

**Behaviour change — the agent no longer overwrites blindly.** `saveDriveFile` and the browser file sink used to write
on top of whatever version was newest. Now they compare and swap on the newest version they read, and `saveDriveFile`
refuses a copy made from an older version of the target than its newest; the agent gets a refused line that says how
to go on (read the target's newest version, take its changes, save again, or save under another name). A host whose
flows relied on "the agent always overwrites" sees these refusals; nothing is written over a version a person saved
meanwhile.

**Upgrade steps for a host:**
- Office renditions stay off until `CloudDrive.settings.officeRenditions.enabled` is set. Before switching them on:
  route `DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage` to a transport `drive_render`, add its
  consumer to the worker list (`config/system/workers.yaml`, only together with the transport), give that worker an
  image with LibreOffice, fonts, `timeout` and `prlimit`, and no network beyond the object store, database and broker.
- An environment that must not start conversions (a workspace on another environment's data) overrides
  `DriveOfficeRenditionsService::isConversionStartAllowed()` in a same-path subclass (with its service alias).
- `GET …/nodes/{driveNodeId}/rendition` is a read for the viewer (it may dispatch a conversion and write marker
  objects next to the blob, never a table row): a host that classifies routes by effect lists it as a read.
- A host that overrides the skill file `CloudDrive.Files` adds `DriveFolder` to `providesWidgetTypes` and the lines
  on `showDriveFolder` and on the owner's edits.
- Web: `registerCloudDriveCanvases(…)` once per page load next to `registerDriveFileCardsSource`; hand
  `loadCodeHighlighter` and `renderMarkdown` (`MarkdownPreview untrusted`) to the drive screen and the canvases.

- **Files and folders open in the canvas beside the agent chat.** `registerCloudDriveCanvases({ api, screenRoute?,
  loadPdfJs?, loadCodeHighlighter?, renderMarkdown?, store? })` (new entry `@mgamadeus/ddd-clouddrive/canvas`) registers
  on `@mgamadeus/ddd-agents`: the canvas kind `cloudDriveFile` (a file of a `DriveFiles` block with a preview opens in
  `FileViewer` of `@mgamadeus/ddd-admin-ui/files`: preview, choice of a stored version, download, "Show in the drive"),
  the canvas kind `cloudDriveFolder` (a folder opens as the compact `FileManager`: walk into folders, open a file in
  place, download; upload, new folder, rename, move and trash only for a viewer who may write in the space), the block
  `DriveFolder` and the canvas opener of the store `clouddrive`. A pointer carries the reference only; node, versions
  and content are read through the drive routes under the viewer's rights, every byte through the content route.
  Hosts that do not call it see no change; `.` and `./agents` still load neither the screen nor ddd-agents at runtime.
  `cloudDriveFileCanvasReferenceOf`, `cloudDriveFolderCanvasReferenceOf`, `driveNodeIdOfCanvasReference`,
  `parseDriveFolderWidgetPayload`, `CloudDriveFileCanvas`, `CloudDriveFolderCanvas`, `createCloudDriveFileCanvasBody`,
  `createCloudDriveFolderCanvasBody`, `DriveFolderConversationWidget`, `CLOUD_DRIVE_FILE_CANVAS_ID`,
  `CLOUD_DRIVE_FOLDER_CANVAS_ID`, `DRIVE_FOLDER_WIDGET_NAME`, `CLOUD_DRIVE_STORE_NAME`.
- **Tool `showDriveFolder` and widget `DriveFolder`** (`CloudDriveMCPPackage`, `DriveFolderConversationWidget`): the
  agent opens a folder for the owner beside the chat. `showDriveLocation` / `DriveLocation` stay (a link into the drive
  screen, a search). The skill `CloudDrive.Files` names both; a host that overrides the skill file adds `DriveFolder`
  to `providesWidgetTypes` and the "Showing" lines.
- **Text files are edited in place** (web): the adapter of the drive screen has `saveText` and `saveTextAsCopy`
  (`createDriveFileManagerActions`), so `FileViewer` offers "Edit" for Markdown, text, CSV, JSON, YAML and source files
  to a viewer who may write. Save writes a new version through the upload routes with `expectedLatestVersionId` = the
  version the editor loaded; a version written meanwhile answers a conflict, nothing is overwritten, and the viewer
  loads the newer version or saves a copy beside the file (`nextDriveCopyName`). `driveTextContentTypeOf`.
- **The agent learns that a person wrote a version:** `POST …/uploads/{id}/complete` takes `agentConversationId` (sent
  by the canvas). With ddd-agents the server notes the version in that conversation as the signal
  `DRIVE_FILE_CHANGED_BY_PERSON` (`CloudDriveConversationSignalSource`, `DriveAgentConversationNotesService`): the file
  by path and reference, "version 3 → 4", "read it again". Only a conversation the caller may read gets the note; it
  starts no turn; the file's name is marked as written by people.
- **The agent's direct writes carry their base version:** `saveDriveFile` (`CloudDriveAgentDriveStoreService::saveDriveFiles`)
  and the browser file sink no longer write over whatever version is newest. They compare and swap on the newest
  version they read, and `saveDriveFile` refuses a copy that was made from an older version of the target than its
  newest (`findStaleBaseRefusal()`: the copy's origin, versions copied onto it since, the agent's own last save),
  with the way out in the refused line. **Behaviour change** for a host that relied on the overwrite.
- **PDF renditions of Office files** (off by default, `CloudDrive.settings.officeRenditions`): new route
  `GET …/nodes/{driveNodeId}/rendition` answers `READY` (with a grant), `PENDING` or `FAILED` (with a reason) for docx,
  xlsx, pptx, odt, ods and odp; `hasPdfRendition` on `DriveNodeListItem` and `DriveFileCard`; new content purpose
  `RENDITION` (inline `application/pdf`, recorded as VIEWED). The conversion is a `DriveOfficeRenditionMessage` on the
  transport `drive_render` and runs LibreOffice as an isolated process with a throwaway profile; the PDF is stored next
  to the blob and purged with it. Hosts: route the message, give `drive_render` a consumer with LibreOffice and fonts,
  and isolate its egress (README › "Office renditions"). Web: `useDriveRenditionMutation`, the viewer asks until the
  rendition is there.
  `DriveOfficeRenditionsService::isConversionStartAllowed()` (default true) is the host's switch for an environment
  whose requests must leave store and queue untouched: there the route only serves a stored PDF.
- `DriveContentGrantsService`: `resolveReadableVersion()`, `resolveReadableBlobOfVersion()` and
  `createContentGrantOfDelivery()` are public parts of `resolveContentDelivery()` / `createContentGrant()`;
  `DriveBlobsService::deleteDerivedObjects()`; `DriveContentDelivery::$etag`. The refusal of an unknown purpose now reads
  "Content for people is granted for DOWNLOAD, INLINE, THUMBNAIL or RENDITION." 3 new server messages.
- **Drive screen:** `CloudDriveScreen` takes `loadCodeHighlighter` and `renderMarkdown` beside `loadPdfJs` and hands
  them to the file manager: the details drawer then shows Markdown as "Preview" / "Source", coloured source files,
  picture zoom and a player for video and sound (the shared viewer of admin-ui 0.8.0; the canvas shows the same).
- Peers: `@mgamadeus/ddd-admin-ui` ≥ 0.8.0, `@mgamadeus/ddd-agents` ≥ 0.3.14 (optional, for `./agents` and `./canvas`);
  Composer: `mgamadeus/ddd-agents` ^0.3.14 suggested. 4 new UI texts.
- The rendition markers are a value object (`DriveOfficeRenditionMarker`, same stored JSON); the tool examples
  are English.

## 0.3.4

- `app:drive:maintain` no longer scans in its own process: failed scans and lost ingests are dispatched as one
  `DriveIngestVersionMessage` per version to the transport `drive_ingest` (`DriveMaintenanceService::dispatchIngestOfVersions()`);
  the output counts the dispatched, already queued and failed messages. A version dispatched within
  `ingestDispatchLockMinutes` (new setting, 60) is not dispatched again (a cache lock per version; a cache without locks
  dispatches every run). A failing dispatch is reported and does not stop the batch. The cron keeps the housekeeping
  (expiry, trash, versions, blob sweep, multipart abort, access retention) and needs no ClamAV and little memory.
  Hosts: give `drive_ingest` a consumer also with `asyncIngest: false`; a synchronous routing stays correct but runs
  the scans in the maintenance process again (README, host step 5).
- `DriveVersionsService::findVersionsOfBlob()`.

## 0.3.3

- The agent reads documents of the drive: `CloudDriveAgentDriveStoreService` implements ddd-agents'
  `AgentDriveFileContentReader`, so `readDriveDocument` (text page by page: PDF, Word, Excel, PowerPoint,
  OpenDocument, plain text) and `viewDriveDocumentPages` (PDF pages as images) of `agent.drive` work on every mount.
  The bytes of the released version are read from the store by the backend under the caller's download checks (read
  right, trash rule, scan state), never through a URL; a file over `AI.documents` `maxDocumentBytes` and a folder are
  refused. A file of the own drive the sandbox just wrote is committed first.
- The skill `CloudDrive.Files` names the two tools.
- Requires ddd-agents 0.3.13 with the agent part (`conflict: <0.3.13`): the store implements its new interface.

## 0.3.2

- Resume after a cancel or a reload: the web client reserves with an idempotency key of the file (space, folder, name,
  size, last change) and its name; a reserve with a known key answers the open upload with `uploadedPartNumbers`, so
  only the missing parts are sent. The server resumes only a blob of the same size that is neither failed, being
  purged nor an expired unfinished upload, else it starts a new one (the newest blob of a key wins).
- Type check before the first byte: the web client sends `fileName` with the reservation, so a restricted space
  refuses a type (declared content type and extension) at the reserve; completion still checks the real bytes.
- The file list refreshes on its own while files of it are being checked (every 4 s in the first minute, then every
  15 s, then every minute, none after 15 minutes; `driveListPollingIntervalOf`).
- The state line of the agent's file cards ('pending scan', 'blocked', 'written by a third party') is a text of the
  package (`translateDriveCardState`, keys `Being checked__drive-card-state` …, de/en through the host's translations).
- OpenAPI: `uploadRequest` / `partRequests` of `DriveUploadReservation` are left out of the schema (`#[Ignore]`); the
  runner of a working copy keeps them server side.

## 0.3.1

- The release of 0.3.0 stopped before npm (the web text manifest still named 0.2.2): 0.3.1 is the first npm version
  with the backend transfers; the PHP package is the same as 0.3.0. Use 0.3.1 for both.

## 0.3.0

Every byte goes through the backend; no presigned URL reaches a client any more (no compatibility mode).

- Downloads, previews, thumbnails: the grant (`GET …/nodes/{id}/content`, file cards, list thumbnails) answers a
  backend URL with a signed token (HMAC over node, version, purpose, account, audience and expiry;
  `CLOUDDRIVE_CONTENT_TOKEN_SECRET`, else `APP_SECRET`). The new `CloudDriveContentControllerTrait` serves it, streamed
  from the store (Range/206/416, ETag/304, `X-Accel-Buffering: no`), and checks the rights, the variant rule and the
  scan state again on every fetch (revoked → 403). The access (VIEWED / DOWNLOADED, deduplicated) is recorded at the
  fetch, no longer at the grant.
- Uploads: `PUT …/uploads/{id}/content` (up to `transfer.singlePartMaxBytes`) and `PUT …/uploads/{id}/parts/{n}`
  (above, parts of `transfer.partBytes`, a part sent again replaces it), streamed from the request body into the store;
  `POST …/uploads/{id}/parts` now answers `uploadedPartNumbers` (resume) instead of presigned requests. The
  reservation no longer carries `uploadRequest` / `partRequests` for clients (only a working copy's runner on the
  server still gets presigned requests). Quota reservation, MD5 / size checks, completion and ingest are unchanged.
- `CloudDrive.settings.transfer`: `partBytes`, `singlePartMaxBytes`, `tokenLifetimeSeconds`, `streamChunkBytes`,
  `contentUrlPrefix` (defaults in code; `multipartPartBytes` and `downloadUrlLifetimeSeconds` are still read when
  `transfer` does not set the value; `multipartThresholdBytes` is gone — above `singlePartMaxBytes`, 16 MB, an upload
  goes in parts).
- `app:drive:maintain` aborts multipart uploads of the store unfinished beyond `unfinishedUploadExpiryHours`.
- npm `@mgamadeus/ddd-clouddrive` 0.3.0: `sendDriveUpload(reservation, file, sender, onProgress, signal)` sends to the
  backend (one part in flight, stored parts skipped, a failed part retried), new hooks
  `usePutDriveUploadContentMutation`, `usePutDriveUploadPartMutation`, `useDriveUploadStateMutation`; the types
  `DrivePresignedRequest` / `DriveUploadPartRequest` are gone.
- Hosts: mount `CloudDriveContentControllerTrait` on a path without authentication (default
  `/api/public/drive/content`), set the secret, apply the limits of README › "Host requirements" (nginx, PHP-FPM,
  proxy), remove the bucket's CORS and multipart lifecycle rules if you like. Requires mgamadeus/ddd ^3.43.0.

## 0.2.2

- Server messages for a host's text export: `i18n/server-messages.json` lists the 40 messages the drive endpoints answer
  with, with hints (built and checked by `ddd-server-messages` of mgamadeus/ddd 3.42; CI runs `build --check`). A host
  merges it with `ddd-server-messages collect` and must not prune these keys. Requires mgamadeus/ddd ^3.42.0.
- The audience of a file access ("· Staff") is translated by `@mgamadeus/ddd-admin-ui` 0.7.2 (`./files`); a host
  updates that package.

## 0.2.1

- A reference `drive/<id>` takes the mount of the node's own space: `organizeDriveFiles` (move, rename, tag,
  describe), `getDriveFile`, `listDriveFiles`, `findDriveFiles` and the source of `saveDriveFile` resolved a reference
  against the own drive, so a write by reference skipped the read-only mode and the owner consent of a shared space
  (and a copy by reference did not mark shared content as third-party). Now a write by reference into a read-only
  space refuses like one by path, a write into a space outside the staff asks the owner, and a reference to a node of
  no mounted space answers "not in a mounted drive" (`CloudDriveAgentDriveStoreService::requireMountOfNode()`).
- Evaluations: with a scratch drive every space but the own drive stays read-only — now for every write tool alike
  (restore and create folder refused while move and rename by reference passed). The refusal says the run is an
  evaluation and to copy the file into the own drive (`isScratchDriveCall()`).

Host: nothing to change. An eval case that moved or renamed shared files by reference now gets the read-only refusal:
let it work on a copy in its own drive.

## 0.2.0

Breaking for hosts with ddd-agents (a host's `^0.1` constraint does not pull it). What a host renames:

| Before | Now |
|---|---|
| `DDD\Domain\CloudDrive\Services\Agents\CloudDriveAgentDriveStoreService` | `DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService` |
| `DDD\Domain\CloudDrive\Services\Agents\DriveSpaceMountResolverService` (and a host's same-path subclass `…\Services\Agents\DriveSpaceMountResolverService`) | `DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService` (the subclass moves to `…\Services\AI\`) |
| `DDD\Domain\CloudDrive\Services\Agents\Eval\CloudDriveEvalWorkspaceParticipantService` | `DDD\Domain\CloudDrive\Services\AI\Eval\CloudDriveEvalWorkspaceParticipantService` |
| skill `Assistant.Drive` (`config/app/AI/Skills/Assistant/Drive.md`; a host override at that path) | skill `CloudDrive.Files` (`config/app/AI/Skills/CloudDrive/Files.md`; move the override) |
| package id `clouddrive` | `clouddrive.files`; bind by class: `'CloudDrive.Files' => [CloudDriveMCPPackage::class, 'agent.drive']` |
| `Assistant.Drive` in `skillRequirements.php`, `parentSkillNames`, `preloadSkills`, eval fixtures and file names | `CloudDrive.Files` |

No alias: the old names are gone. The drive store's name `clouddrive` in stored widget payloads is unchanged.

- Own drive by default: `DriveSpaceMountResolverService::findOrCreateOwnDriveSpace()` creates one own drive per agent
  space at its first use (`spaceKind` `agentSpace`, staff audience, owned by the agent space's account) and links it
  in the new table `AgentSpaceDriveSpaces` (`AgentSpaceDriveSpace`, `AgentSpaceDriveSpacesService`; unique on
  `agentSpaceId` and on `driveSpaceId`, cascades from both). `findOwnDriveSpace()` finds without creating. Concurrent
  first calls create one space (a cache lock). Without the table (code before schema) agent spaces get no own drive,
  nothing fails, the missing table is logged once per process. The agent space's account holds READ, WRITE and MANAGE
  on its own drives (`AgentOwnDriveSpaceRightsExtensionService`; override at the same path to switch it off). Kind
  `agentSpace` in `CloudDrive.spaceKinds` (label "Agent drives"). A host whose resolver already links own drives in a
  table `AgentSpaceDriveSpaces` with these columns drops its own entity, repository and model: the package's reads
  the same rows.
- Pull mark: `DriveWorkingCopiesService::requestPull()` marks a working copy for a pull at the sandbox's next call,
  `isPullRequested()` reads and `consumePullRequest()` takes the mark (cache, redis group, one day; the process keeps
  its own marks as well). For writers outside the sandbox: the change feed alone does not do, a file in the scan holds
  the cursor back.
- `DriveChangesService::findChangesOfWorkingCopy(DriveWorkingCopy, ?eventTypes, limit)`: the changes one working copy
  made, newest first (e.g. the nodes it created, to clean up a throwaway working copy) — the query a sandbox's drive
  store built itself.
- Browser file sink: `CloudDriveBrowserFileSinkService` implements `BrowserFileSink` of ddd-agent-browser ≥ 0.2.0
  (registers only beside it and ddd-agents). Files land in the own drive of the call's agent space (an eval case's
  scratch drive) in `web/<domain>/` under the renderer's names, through the upload intake: type from the bytes, an
  allow-list per kind (design tokens JSON; logo PNG, JPEG, GIF, WebP, SVG; stylesheets CSS; screenshots PNG, JPEG,
  WebP; fonts WOFF2, WOFF, TTF, OTF), executable and active content refused (programs, PDF, PostScript, Office and ODF
  containers, HTML, scripts, SVG with scripts or handlers, CSS with script URLs, expressions or bindings), a name that
  does not fit its bytes refused, SVG stored only through the host's `DriveSvgSanitizer` (refused without one), stored
  as an upload marked untrusted with its source in the version comment, the token file and the logo wait up to 45 s
  per call for the scan (a blocked file is refused), then the working copy is marked for a pull. It writes as the
  signed-in account of the tool call. A host with its own sink overrides the class at the same path.
- Tools of `clouddrive.files` for a space's file area (what a partner file area skill did): `getDriveSpace` (write
  lock, quota and use, counts, top-level folders), `listDriveFileEvents` (history of a file or folder),
  `createDriveFolder`, `rescanDriveFileVersion` (a failed scan again), `setDriveSpaceSettings` (audience write lock,
  quota, largest file; manage right, asks the owner; `DriveSpacesService::updateSpaceSettings()`). A partner's file area
  folds into `CloudDrive.Files`: the host mounts its space and names it in its skill override.
- Media library bridge: `DriveMediaLibraryBridgeService` (a port; Core has no media library logic yet): the default has
  no library; a host overrides `isMediaLibraryAvailable()` and `importFileIntoMediaLibrary(DriveNode, DriveMediaImport):
  DriveMediaImportResult` at the same path and reads the bytes with `saveReleasedContentToFile()` (released raster
  images only, `findImportRefusalReason()`). Tool `importDriveFileToMediaLibrary` (refuses without a library). Media
  never stores on the drive's object store.
- `CloudDriveAccountRights::USE_DRIVE` (`useDrive`): the package's right constant for gating `CloudDrive.Files`; nothing
  checks it in this package yet.
- Composer: `conflict` with `mgamadeus/ddd-agents <0.1.26` and `mgamadeus/ddd-agent-browser <0.2.0`.

What a host does: apply the schema diff (table `AgentSpaceDriveSpaces`), make the renames above, rebind the skill by
class, decide on the default own drive (keep it, or override `findOrCreateOwnDriveSpace()`), drop a browser sink of its
own that does what the package's does, implement the media bridge where it has a media library.

## 0.1.3

- Every passive text type libmagic names is text: `text/x-objective-c`, `text/x-c`, … (a markup or config file it
  misreads as code) are refined by the known text extensions like `text/plain`, else kept when the drive serves them
  as text, else stored as `text/plain` — so they open inline as text. Active text (HTML, XML, JavaScript) is unchanged.
- An unrefined `text/x-*` guess stored by an older write counts as unknown: it is read again from the bytes when it is
  served, and `app:drive:backfill-content-types` picks it up (`DriveBlobsService::isPassiveTextContentType()`,
  `isServedAsTextContentType()`).

## 0.1.2

- Content types from the bytes on every write path: the ingest (uploads, working-copy and sandbox commits, agent
  writes, `writeFileContent()`, copies) stores the type libmagic reads from the bytes (`DriveBlobsService::
  detectContentTypeOfFile()`, `detectContentTypeOfBytes()` for content in memory). A PDF, text or office file written
  without a content type was stored as `application/octet-stream` and offered only as a download. The name refines
  plain text (`.md` → `text/markdown`, `.csv`, `.json`, …) and office formats of a ZIP (`.docx`, `.pptx`, …), never
  makes bytes a PDF, an image or active content; a writer's claim counts only for bytes libmagic cannot name (an
  `application/*` type that is no PDF and no text). HTML bytes are stored as `text/html` whatever was claimed (still
  only an attachment). The release copies the detected type onto the version and the file.
- A blob stored without a telling type (missing or `application/octet-stream`) gets its type from its first 8 KB
  when it is first served (`DriveBlobsService::detectAndStoreContentTypeOfStoredBlob()`), and keeps it on the blob,
  its versions and files: an agent's PDF opens inline.
- New command `app:drive:backfill-content-types [--dryRun] [--limit=1000] [--driveSpaceId=]`: the same for all such
  blobs at once, every space, with counts per detected type; repeat while it reports more. Run it once after the
  update (dry run first).
- A host's own commit path that stages, uploads and commits through `DriveWorkingCopiesService` gets the detection
  without a change (it happens in `DriveBlobsService::ingestBlob()`).

## 0.1.1

- Content: "open" works for PDF and plain texts. The INLINE purpose serves JPEG, PNG, GIF, WebP and PDF in their own
  type and plain texts (Markdown, CSV, JSON, YAML, plain text) as `text/plain; charset=utf-8`, inline; HTML, SVG (only
  through its sanitised copy), XML, JavaScript, Office files and archives stay attachments
  (`DriveContentGrantsService::resolveInlineContentType()`, overridable). Content is served from the object store's
  origin by presigned URLs, never from the application's, so an opened PDF reaches no application session; the store
  endpoint must not share the application's origin. A file card's `thumbnailUrl` stays raster-only. An office file's
  PDF twin now opens in the browser.
- npm package: ships its text manifest `i18n-keys.json` (export `@mgamadeus/ddd-clouddrive/i18n-keys.json`, the
  format of `@mgamadeus/ddd-admin-ui`'s manifest, translator hints in `i18n/hints.json`, checked in CI with
  `pnpm i18n:check`) for the drive screen's 20 texts, so a host's text export picks them up.

## 0.1.0

- First regular release (no more release candidates): Composer and npm `@mgamadeus/ddd-clouddrive` in lockstep, npm
  dist-tag `latest`. Content as 0.1.0-rc.22. From here on: patch for fixes, minor for features and, before 1.0,
  breaking changes.

## 0.1.0-rc.22

- First npm publication of `@mgamadeus/ddd-clouddrive` through trusted publishing (rc.21 reached Packagist only); no
  content change.

## 0.1.0-rc.21

- New npm package `@mgamadeus/ddd-clouddrive` from `web/` of this repository, released with the same tag and version
  as the Composer package (`.github/workflows/release.yml`, trusted publishing):
  - `.`: `createCloudDriveApi` (the drive routes on the host's RTK Query api) and the payload types;
  - `./screen`: the drive screen (`CloudDriveScreen`, `CloudDriveSpacePicker`, `useCloudDriveFileAdapter`, file
    actions, mapping), moved unchanged from `@mgamadeus/ddd-admin-ui/clouddrive` (which drops the entry);
  - `./agents`: `createCloudDriveCardsSource()` — the `DriveFileCardsSource` of `@mgamadeus/ddd-agents` ≥ 0.1.38 on
    `POST …/cards` and the content route — and `buildDriveScreenHref()`.
- Migration of a host: imports from `@mgamadeus/ddd-admin-ui/clouddrive` move to `@mgamadeus/ddd-clouddrive`
  (client, types) and `@mgamadeus/ddd-clouddrive/screen` (screen, adapter, helpers); an own `DriveFiles` widget and
  its cards source give way to `@mgamadeus/ddd-agents`' widget with `createCloudDriveCardsSource()`.

## 0.1.0-rc.20

- Added (actors): `DriveChangesService::findActingAccountIdOfCurrentCaller()` beside
  `findOnBehalfOfAccountIdOfCurrentCaller()`. One hook pair for every write path: the creator of files and folders, the
  author and `onBehalfOfAccountId` of every version (upload, restore, transfer between spaces, agent copy, working copy
  commit; before, the author was the signed-in account and the version's on-behalf column stayed empty), the actor of
  changes, accesses and host events. A host with impersonation overrides both (staff acts, the impersonated account is
  acted for) and can drop an app-side bridge of the version author.
- Fixed (scan release): RELEASED and BLOCKED name the version's author and account acted for, also when the ingest runs
  in a worker without the person (before: the worker's empty or the impersonated account). Old messages work unchanged
  (the version carries both).
- Added (answers): `DriveNodeListItem::createdOnBehalfOfAccountId` / `createdOnBehalfOfName` (from the node's CREATED or
  COPIED_IN change context; null without the activity table) and `DriveFileVersionListItem::onBehalfOfAccountId` /
  `onBehalfOfName`; `createdByName` and `authorName` go through `DriveActorNamesService::buildActorDisplayName()` with
  the account acted for, like the history. No new column.
- `recordChange()` takes an optional `$actorAccountId` (default: the acting account).

## 0.1.0-rc.19

- Changed (version history): the notes the drive writes on a version come as a code with parameters on
  `DriveFileVersionListItem` — `commentCode` (`RESTORED_FROM_VERSION`, `TRANSFERRED_FROM_SPACE`, `COPIED_FROM_PATH`;
  `COMMENT_CODES`) and `commentParameters` (`DriveFileVersionCommentParameters`: `versionNumber`,
  `sourceSpaceDisplayName`, `sourcePath`) — and `comment` is null for them, so a client renders them in its own
  language and no internal id reaches it. The source space (by display name) and the source path go to INTERNAL
  callers only. The stored text is unchanged; existing rows map the same way. A note is recognised only with the
  version's own marker (restored-from version; origin COPY), so free text that reads the same stays free text.

## 0.1.0-rc.18

- Fixed (rights): the `$parameterPrefix` handed to a `DriveSpaceRightsExtension` is unique per application of the
  rights (`{alias}_rightsExtension{n}_{right}_{extension}_`). The module applies READ and then WRITE to one query; an
  extension with a fixed subquery alias built from the prefix failed with "alias already defined". Extensions keep
  building their parameter and subquery alias names from the prefix and need no counter of their own.
- Added (contract test): `DriveSpaceRightsContractTestCase::testReadAndWriteAppliedToOneQueryRunWithoutAliasCollisions()`.

## 0.1.0-rc.17

- Fixed (API schema): `DriveSpace::$account` carries `HideProperty` and the OpenAPI `Ignore`, so the host's Account
  entity no longer enters a drive response or the generated schema (`accountId` stays). It used to pull the host's full
  account schema into every API that returns a space or a node with its space. A test keeps every lazy relation of a
  drive entity to a non-drive entity hidden and ignored.
- Docs: the `DriveSpace` class comment no longer describes the unsupported same-path subclass.

## 0.1.0-rc.16

- Added (rights): `DriveSpaceRightsExtension` (tag `clouddrive.space_rights_extension`, autoconfigured, any number),
  composed by `DriveSpaceRightsExtensionsService` into `DBDriveSpace::applyRightsToSpaceQuery()`:
  `isCallerUnrestricted(?Account, string $right): ?bool` (one false restricts — also an admin; else one true
  unrestricts; else the default: admins everything) and `buildSpaceRightsCondition(DoctrineQueryBuilder, string
  $spaceAlias, string $right, ?Account, string $parameterPrefix): ?string` (OR-composed grants; none = no space). Every
  drive table and every service right check uses it. A throwing extension grants nothing (logged).
- Changed (docs): subclassing `DriveSpace` / `DBDriveSpace` / `DBDriveSpaceModel` is unsupported (two Doctrine models on
  one table break the module's relations). Hosts with such a subclass move its rights into an extension, its labels
  into the `DriveSpacesService` hooks and its anchor (e.g. an owning record id) into a host table holding
  `driveSpaceId`, then delete the subclass, regenerate the models and run the schema diff (it must show no change on
  `DriveSpaces` unless the subclass had added columns — drop those only with the owner's go).
- `DriveSpaceRightsContractTestCase`: `testManageImpliesWriteAndWriteDoesNotImplyManage()` (with the optional
  `createWriterWithoutManageAccount()`) and `testTheSpaceIsNotSubclassedByTheHost()`.

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
