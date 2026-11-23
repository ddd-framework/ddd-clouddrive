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
    // the maintenance redoes the ingest of a version whose blob waits longer than this (a lost worker run)
    'staleIngestMinutes' => 15,
    // the scanner command (exit 0 clean, 1 infected, anything else: the scan failed and is retried by
    // app:drive:maintain); add `--database=<dir>` when the signatures are not in clamscan's default place;
    // '' = no scan (development only: blobs are released unscanned)
    'malwareScannerCommand' => 'clamscan --no-summary --stdout',
    // system folders of an agent workspace: hidden, not scanned; their files expire after this many days
    'systemFolders' => [
        'work' => ['expiryDays' => 7],
    ],
    // paths never taken into a working copy commit (gitignore-like prefixes and names)
    'workingCopyExcludedNames' => ['.venv', 'node_modules', '__pycache__', '.git', '.runner', '.cache', 'mounts', 'drivespaces'],
];
