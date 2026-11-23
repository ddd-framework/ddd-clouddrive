# Changelog

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
