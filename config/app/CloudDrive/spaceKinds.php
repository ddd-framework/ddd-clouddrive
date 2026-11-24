<?php

declare(strict_types=1);

/**
 * Defaults per space kind, read as `Config::get('CloudDrive.spaceKinds')`. A space's own `quotaBytes` and
 * `maxFileSizeBytes` override its kind's; a kind without a setting falls back to `default`. An application's file of
 * the same path replaces this one whole.
 */
return [
    'default' => [
        // everything stored counts: every kept version and the trash
        'quotaBytes' => 5 * 1024 * 1024 * 1024,
        'maxFileSizeBytes' => 250 * 1024 * 1024,
        // a version is purged once it is neither among the newest `keptVersions` nor younger than `versionRetentionDays`
        'keptVersions' => 20,
        'versionRetentionDays' => 90,
        'trashRetentionDays' => 30,
        // optional: the group a space picker lists spaces of this kind under (`label` => 'Libraries')
    ],
    // the own drive of an agent space (ddd-agents): created on first use by the agent part's mount resolver
    'agentSpace' => [
        'label' => 'Agent drives',
    ],
];
