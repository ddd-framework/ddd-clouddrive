# Changelog

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
