# ddd-clouddrive

Cloud drive for [mgamadeus/ddd](https://github.com/ddd-framework/ddd): spaces whose rights the host decides,
folders and versioned files whose bytes live in object storage, working copies for agent sandboxes and, beside
[mgamadeus/ddd-agents](https://github.com/ddd-framework/ddd-agents), the agent's drive mount and tools.

## What it does

- **Spaces** (`DriveSpace`): one tree per space, an audience fixed at creation (`INTERNAL` or `SHARED`), quota,
  largest file and retention per `spaceKind` (`config/app/CloudDrive/spaceKinds.php`). The host extends `DriveSpace`
  at the same path with its anchor columns.
- **Rights**: the host's space repository answers read, write and manage in one method,
  `DBDriveSpace::applyRightsToSpaceQuery()`; every drive table filters through it. Default: admins everything, everyone
  else nothing. `DriveSpaceRightsContractTestCase` is the test a host runs against its rules.
- **Files**: folders, files, versions (`DriveFileVersion`) and blobs (`DriveBlob`). Names are case-sensitive and
  unique per folder; trash and restore; tags and descriptions; a change feed (`DriveChangeEvent`).
- **Bytes**: direct presigned upload and download, PHP never proxies bytes. Every object is written once: a key per
  blob, `If-None-Match: *` signed into each PUT, multipart uploads completed only by the server onto a free key,
  `Content-MD5` verified by the store. The ingest computes the SHA-256, scans (`clamscan`), deduplicates per space and
  releases or blocks. People read only released content (`DriveContentGrantsService`).
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
3. A `DriveSpace` subclass at the same path with the host's anchor columns, and its repository's
   `applyRightsToSpaceQuery()`.
4. The REST endpoints: one controller with `use CloudDriveControllerTrait;` (`#[Route('/common/cloudDrive')]`).
   `POST …/cards` hydrates the `DriveFiles` widget with short-lived open, download and thumbnail URLs. It is a POST
   (the references are a body) but only reads: a host write guard that classifies routes by method lets it through
   like a GET. `GET …/nodes/{id}` answers `ancestors` (root first) for breadcrumbs and deep links.
5. A cron for `app:drive:maintain` (hourly): system-folder expiry, trash and version retention, the blob sweep.
6. With ddd-agents: override `DriveSpaceMountResolverService` (own drive per agent space, further mounts) and bind the
   skill in `config/app/AI/skillPackages.php`: `'Assistant.Drive' => ['clouddrive', 'agent.drive']`.

`app:drive:spaces:create` creates a library space.

## License

MIT
