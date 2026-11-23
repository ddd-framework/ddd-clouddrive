<?php

declare(strict_types=1);

/**
 * Module settings, read as `Config::get('CloudDrive.settings')`.
 */
return [
    // the object store of the file bytes (Common.ObjectStorage stores, OBJECT_STORAGE_CLOUDDRIVE_*)
    'objectStorageName' => 'clouddrive',
    // lifetime of a presigned upload or download in seconds
    'uploadUrlLifetimeSeconds' => 3600,
    'downloadUrlLifetimeSeconds' => 600,
    // an upload above this size runs as a multipart upload; parts of this size (the last one smaller)
    'multipartThresholdBytes' => 64 * 1024 * 1024,
    'multipartPartBytes' => 16 * 1024 * 1024,
    // an unfinished upload or an unreferenced staged blob is swept after this many hours
    'unfinishedUploadExpiryHours' => 24,
    // the ingest of a new version (hash, scan, release) in a worker: DriveIngestVersionMessage on the transport
    // `drive_ingest` (route it and give it a consumer); false = inside the request or commit (a scan of seconds each)
    'asyncIngest' => false,
    // a move of files to another space (POST …/nodes/transfer) as a job: DriveSpaceTransferMessage on the transport
    // `drive_ingest` (like the ingest); false = inside the request (at most 200 nodes, 2000 files)
    'asyncTransfer' => false,
    // an access (a preview opened: VIEWED, an original downloaded: DOWNLOADED) by the same person of the same file and
    // version within this many seconds is recorded once; 0 = every grant
    'accessEventDedupSeconds' => 600,
    // accesses (VIEWED, DOWNLOADED, READ_BY_AGENT) older than this many days are deleted by app:drive:maintain;
    // 0 or null = kept as long as the space (the default; a host sets e.g. 365). Changes (created, moved, trashed …)
    // are never deleted by age.
    'accessEventRetentionDays' => 0,
    // the maintenance redoes the ingest of a version whose blob waits longer than this (a lost worker run)
    'staleIngestMinutes' => 15,
    // the malware scanner of the ingest, by name (DriveMalwareScanner::getScannerName(), or its class): 'clamscan'
    // (the module's, settings below) or a host's own scanner. Fails closed: an unknown or empty name, a scanner that
    // cannot run or times out leaves the blob SCAN_FAILED (never released; app:drive:maintain scans it again).
    // 'none' releases unscanned: development only.
    // (deprecated: `malwareScannerCommand`, a command string, is still split into words and run by the clamscan scanner)
    'malwareScanner' => 'clamscan',
    'clamscan' => [
        'binary' => 'clamscan',
        // the signature directory when it is not clamscan's default (e.g. the one freshclam keeps current)
        'databaseDirectory' => null,
        // limits; with alertExceedsMax / alertEncrypted a file above them or an encrypted archive is reported (BLOCKED)
        // instead of passing unscanned
        'maxFileSize' => '2000M',
        'maxScanSize' => '4000M',
        'maxRecursion' => 16,
        'maxFiles' => 10000,
        'alertExceedsMax' => true,
        'alertEncrypted' => true,
        'timeoutSeconds' => 600,
    ],
    // system folders of an agent workspace: hidden, not scanned; their files expire after this many days
    'systemFolders' => [
        'work' => ['expiryDays' => 7],
    ],
    // paths never taken into a working copy commit (gitignore-like prefixes and names)
    'workingCopyExcludedNames' => ['.venv', 'node_modules', '__pycache__', '.git', '.runner', '.cache', 'mounts', 'drivespaces'],
];
