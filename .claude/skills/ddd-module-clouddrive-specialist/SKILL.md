---
name: ddd-module-clouddrive-specialist
description: "Installs, wires and extends mgamadeus/ddd-clouddrive, the file drive of a DDD application: spaces, rights extensions, audiences, files, versions, ingest and scan, streamed bytes, working copies, renditions, transfers. Covers the file source drive (drive/<nodeId>, released version only, audience by path, personalFolders), DriveImportsService::importFiles (options, result, refusals, needsOwnerConsent, expiry, scan wait, 50 files / 50 MB), isDerivedObjectWriteAllowed, host duties (rights extension, routes CloudDriveControllerTrait and CloudDriveContentControllerTrait, partner audience), the npm screen and canvas viewer, and the agent part (CloudDriveAgentDriveStoreService, importFilesOfOtherSources). Use when adding the drive to an application, granting a team or partner access to a space, a file is not readable or stays in its scan, copying media or attachments into a drive, deciding which folders hold personal data, renditions do not start, or wiring the drive for the agent."
metadata:
  author: mgamadeus
  version: 0.5.4
  framework: mgamadeus/ddd-clouddrive >= 0.5.0
---

# DDD CloudDrive Specialist

The file drive of a DDD application. The README is the full manual; this skill is the map, the rules that bite, and
the parts that connect the drive to the rest of the system (file sources, imports, the agent).

## Map: where each topic is documented

| Topic | README section / code |
|---|---|
| Spaces, rights (`DriveSpaceRightsExtension`), audiences INTERNAL / SHARED, type policies, activity, transfers | "What it does"; `Services/DriveSpacesService.php`, `DriveSpaceRightsExtensionsService.php`, `DriveTypePoliciesService.php` |
| Folders, files, versions, blobs, trash, change feed | "What it does" › Files; `DriveNodesService`, `DriveVersionsService`, `DriveChangesService` |
| Bytes through the backend: uploads (single / parts), downloads, previews, content grants, inline rules, SVG copies | "What it does" › Bytes, "Host requirements"; `DriveBlobsService`, `DriveContentGrantsService`, `DriveContentStreamsService` |
| Ingest, SHA-256, type from the bytes, malware scan, release / block | `DriveBlobsService`, `DriveMalwareScannersService`, `ClamscanDriveMalwareScannerService` |
| Working copies of a sandbox (stage, commit with compare and swap, pull, conflicts) | "What it does" › Working copies; `DriveWorkingCopiesService`, `DriveTextMergeService` |
| Office renditions (PDF preview), the switch for derived objects | "Office renditions"; `DriveOfficeRenditionsService` |
| Files of other sources: the source `drive`, imports | "Files of other sources"; `Services/Files/CloudDriveFileSourceService.php`, `DriveImportsService` |
| Agent part | "What it does" › Agent part; `Services/AI/` |
| Installation, config files, transports | "Install"; `config/app/CloudDrive/settings.php`, `spaceKinds.php`, `typePolicies.php` |

## Host duties (installation in short)

1. `composer require mgamadeus/ddd-clouddrive`, config files under `config/app/CloudDrive/` (`settings.php`,
   `spaceKinds.php`, `typePolicies.php`), the store in `Common.ObjectStorage`, the Messenger transports (README "Install").
2. **Rights for spaces:** a `DriveSpaceRightsExtension` service (tag `clouddrive.space_rights_extension`) that answers
   which spaces the running account reads and writes; without one only admins see anything.
3. **Personal folders:** name the root folders that hold people's own data in `CloudDrive.settings.personalFolders`
   (default none), e.g. `['customer-files']`.
4. **Routes:** one admin controller with `CloudDriveControllerTrait` (`#[Route('/common/cloudDrive')]`, access hook
   `throwForbiddenUnlessCloudDriveAccessAllowed()`, `getDriveAudience()` INTERNAL; a second controller with SHARED
   mounts the same actions for partners: reads, writes where the space is not write-locked, no manage actions), and
   one public controller with `CloudDriveContentControllerTrait` (`#[Route('/drive/content')]`, streams bytes for
   short-lived signed content tokens). The file route of Core (`FileSourcesControllerTrait`) serves `drive/<id>` too.
5. **Frontend** (npm `@mgamadeus/ddd-clouddrive`, same version): `.` the RTK Query client of the admin endpoints,
   `./screen` the drive screen (`CloudDriveScreen`, `CloudDriveSpacePicker`, on the file manager of
   `@mgamadeus/ddd-admin-ui/files`), `./canvas` the viewer beside the agent chat (`registerCloudDriveCanvases()` once
   before the first chat: canvas kinds `cloudDriveFile` and `cloudDriveFolder`, the `DriveFolder` block, the canvas
   opener for `DriveFiles` rows), `./agents` the source of the `DriveFiles` cards (`createCloudDriveCardsSource` for `registerDriveFileCardsSource` of ddd-agents).
6. Nothing else of the files concept: the source `drive` and the import register themselves.

Admin endpoints of `CloudDriveControllerTrait` (prefix of the host's controller): spaces (`/spaces/list`,
`/spaces/{id}`, children, folders, search, trash, resolve), uploads (`/spaces/{id}/uploads`, `/uploads/{blobId}/parts`,
`/content`, `/complete`), nodes (`/nodes/{id}`, content, rendition, move, metadata, trash, restore, versions and their
restore, events), bulk (`/spaces/{id}/nodes/move|trash|restore|purge`, transfer preview and transfer) and `/cards`.

## Rules that bite

- **Never subclass `DriveSpace`, `DBDriveSpace` or `DBDriveSpaceModel`.** A second model on the spaces table breaks
  the module's relations. Rights go into a `DriveSpaceRightsExtension` service (tag
  `clouddrive.space_rights_extension`); labels, quota, mount names into a same-path subclass of `DriveSpacesService`;
  a host's link from a space to its records into a table of the host holding `driveSpaceId`.
- **Rights are decided in one place**, `DBDriveSpace::applyRightsToSpaceQuery()`. Default: admins everything, everyone
  else nothing. One extension answering `isCallerUnrestricted() === false` wins; a throwing extension grants nothing.
  Run `DriveSpaceRightsContractTestCase` against the host's rules.
- **No URL of the store ever reaches a browser.** Bytes go through the backend under short-lived signed URLs of the
  application; the bucket needs no CORS and no lifecycle rule.
- **People read only released content.** A blob waiting for its scan, refused by it, or in the trash has no content
  for anyone (download, preview, the file source, the agent).
- **A write never overwrites a newer version** it did not read: working-copy commits, the agent's save and the browser
  sink write with compare and swap; a conflict is a refused line or a kept conflict, never a silent overwrite.

## The file source `drive`

`CloudDriveFileSourceService` implements Core's `FileSource` (skill `ddd-files-specialist`): reference
`drive/<nodeId>`, facts and bytes of a file node under the reader's rights, only its released current version, read
with the checks of a download. It reads node → version → blob, never a key, so objects of the bucket that belong to
no drive file are out of reach. At most 200 ids per facts call.

`CloudDriveFileSourceService::findAudienceOfPath(DriveSpace $driveSpace, string $path): string` decides the audience
per file, by the FIRST segment of its path in the space (`getPersonalFolders()` reads the setting):

| Where | Audience |
|---|---|
| below a root folder named in `CloudDrive.settings.personalFolders` | PERSONAL |
| in a shared space | SHARED |
| anywhere else | INTERNAL |

`personalFolders` is empty by default. A host that keeps people's own data (customer files, applicants' documents)
in root folders names them: `'personalFolders' => ['customer-files']`. Files there are never copied into another store
by Core's flow rule and never kept durably for a model.

## Imports from other sources

`DriveImportsService::importFiles(FileReferences $fileReferences, DriveSpace $driveSpace, string $folderPath,
?DriveImportOptions $driveImportOptions = null): DriveImportResult` copies files of any registered source into a
folder (created when missing). 1–50 files (`MAX_FILES_PER_IMPORT`). Per file:

1. write right on the space (403 for the whole call), the path stays inside the space (400);
2. the source's facts under the caller's rights (unreadable = a refused line);
3. the flow rule with `FileFlowTarget::forStore('drive', <audience of the target folder>, …)`: a refusal is a refused
   line; `requiresOwnerConsent` without `$hasOwnerConsent` stops the file and sets `needsOwnerConsent`;
4. the bytes within `CloudDrive.settings.maxImportBytes` (default 50 MB) and the space's largest file;
5. image metadata removed when the decision says `stripsMetadata`;
6. the drive's own write: a version of the file of the same name (same bytes = no new version, `isUnchanged`),
   origin `IMPORT`, comment `imported from <container> (<reference>)`, history event `IMPORTED`, ingest and scan; the
   call waits up to `scanWaitSeconds` (default 20) for the scan; `expiresAfterDays` of the decision sets the expiry.

`findImportRefusals()` (same arguments) runs only the checks before the bytes — write right, facts, flow rule,
consent — and answers the refused files without writing anything.

| Type | Fields |
|---|---|
| `DriveImportOptions` | `hasOwnerConsent`, `isOwnDrive`, `workingCopyKey` (the caller's own working copy: it is marked for a pull together with the space's newest one, so it sees the files at its next call), `scanWaitSeconds` |
| `DriveImportResult` | `importedFiles` (`DriveImportedFiles`), `refusedLines` (one sentence per file not copied), `refusedFileReferences`, `needsOwnerConsent`; `hasImportedFiles()` |
| `DriveImportedFile` | `sourceFileReference`, `driveNode`, `path`, `isUnchanged`, `isMetadataRemoved`, `contentState` |

The chain per file: `FileSourcesService::requireFileFacts()` → `FileFlowPolicyService::decideFileFlow(facts,
FileFlowTarget::forStore('drive', findAudienceOfPath(space, folder), <space label>, isOwnDrive, folder))` →
`FileSourcesService::readFileContent(ref, min(getMaxImportBytes(), space limit))` → metadata removed if the decision
says so (the strip may write another format: a HEIC picture becomes a JPEG, and name extension and content type
follow the bytes, `adaptNameAndTypeToBytes()`) → `importFileContent()` (name `buildFileName()`: the source's name made safe for a node, else
`<source>-<id>`; comment from `buildSourceLabel()`: `Media library (media/901)`) → ingest and scan wait →
`applyExpiry()` → a `DriveImportedFile`.

Answers (examples):

| Case | Result |
|---|---|
| allowed, copied | `importedFiles` has the file (its own reference `drive/<nodeId>`), `refusedLines` empty |
| same bytes already there | `isUnchanged = true`, no new version |
| a PERSONAL file into a shared space | a refused line with the rule's `refusalReason`; nothing copied |
| the rule asks for consent, `hasOwnerConsent` false | `needsOwnerConsent = true`, a refused line; a caller that holds the owner's yes calls again with `hasOwnerConsent` (the agent cannot: its consent comes only from a `WRITE_WITH_CONSENT` mount) |
| the space is read-only for the caller | `ForbiddenException` for the whole call |
| over the size cap | a refused line ("larger than …") |

A file whose scan blocks it is a refused line ("copied to …, but the drive did not release its content"). One refused
file never stops the others; the space's working copies are asked to pull afterwards.

## The switch for derived objects

`DriveBlobsService::isDerivedObjectWriteAllowed()` decides whether reading a file may write objects derived from it
(office renditions, sanitised SVG copies). Where it answers false (a read-only environment, a replica), the rendition
route serves a stored PDF or `FAILED`, an SVG stays an attachment unless its copy exists. A host overrides it in a
same-path subclass of `DriveBlobsService`. (`DriveOfficeRenditionsService::isConversionStartAllowed()` was removed in
0.5.0; an override of it decides nothing any more.) Renditions also need their message routed to a worker; the
installation check `driveRenditions` of ddd-agents reports it.

## The agent part (with mgamadeus/ddd-agents)

- `CloudDriveAgentDriveStoreService` is the drive store behind `agent.drive` and the content reader of
  `readDriveDocument` / `viewDriveDocumentPages`. `saveDriveFiles()` copies a source that is a reference of another
  file source through `DriveImportsService`; the owner's consent of a `WRITE_WITH_CONSENT` mount counts as the flow
  rule's consent.
- `importFilesOfOtherSources()` (protected, override point) builds the import for the agent: `hasOwnerConsent` =
  the mount's mode is `WRITE_WITH_CONSENT` (the tool already asked the owner), `isOwnDrive` = the mount is the agent
  space's own drive, `workingCopyKey` = the sandbox's own working copy of the call (marked for a pull, so the script
  sees the files at its next call); a refused import becomes refused lines of the tool's answer, copied files its saved
  files.
- `CloudDriveAgentDriveSavePrecheckService` (ddd-agents ≥ 0.7.9, `AgentDriveSavePrecheck`): before `saveDriveFile` asks
  the owner for a consent mount, it runs `DriveImportsService::findImportRefusals()` (write right, facts, flow rule; no
  byte read, nothing written) for the files of other sources, so a copy the rule refuses is never put to the owner.
  The mount consent itself stays: writing into a space outside people see asks the owner even for a PUBLIC file.
- Mounts: `DriveSpaceMountResolverService` (a host overrides it); by default one own drive per agent space, created
  on first use, linked in `AgentSpaceDriveSpaces`, readable through `AgentOwnDriveSpaceRightsExtensionService`.
- Tools `clouddrive.files` (`CloudDriveMCPPackage`), widgets `DriveFolder` / `DriveLocation`, skill `CloudDrive.Files`,
  right `CloudDriveAccountRights::USE_DRIVE`.
- A version a person saves beside a conversation is noted to the agent (`DRIVE_FILE_CHANGED_BY_PERSON`).
- The agent side of file sources (references in the drive tools, `viewImages`, `AI.fileSources.findTools`): the tools
  skill of ddd-agents, reference `file-sources-in-the-drive-tools.md`.

## Troubleshooting

| Symptom | Check |
|---|---|
| a space or file is invisible for a caller | the rights extensions (`isCallerUnrestricted()`, `buildSpaceRightsCondition()`); the caller's audience |
| a file has no content / "not found" for the source `drive` | not released yet (scan), blocked, trashed, or no read right |
| an import is refused | the refused line names the cause: flow rule (audience, PERSONAL), cap, scan, conflict |
| files in a customer folder are copied freely | `personalFolders` is not set for that root folder |
| renditions answer FAILED | `isDerivedObjectWriteAllowed()` false here, or the message is routed to no worker |

## Cross-reference

File sources, references, the flow rule: Core skill `ddd-files-specialist` · foreign bytes: `ddd-untrusted-input-specialist`
· agent tools: `ddd-module-agents-tools-specialist`.
