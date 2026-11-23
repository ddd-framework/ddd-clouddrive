<?php

declare(strict_types=1);

/**
 * The type policy per space kind, read as `Config::get('CloudDrive.typePolicies')`
 * ({@see \DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy}). A kind without an entry takes `default`; an
 * empty block restricts nothing. Keys of a block: `allowedFamilies` (PDF, SPREADSHEET, DOCUMENT, PRESENTATION, TEXT,
 * IMAGE, VECTOR, VIDEO, ARCHIVE), `allowedExtensions` (without the dot), `allowedContentTypes`, `maxFileSizeBytes`,
 * `refusesContentMismatch` (default true: the bytes must fit the name's family) and `shared` (a block for the SHARED
 * audience, its keys replace the kind's). An application's file of the same path replaces this one whole.
 *
 * Example:
 *   'project' => [
 *       'allowedFamilies' => ['PDF', 'SPREADSHEET', 'DOCUMENT', 'PRESENTATION', 'TEXT', 'IMAGE'],
 *       'shared' => ['allowedExtensions' => ['pdf', 'xlsx', 'docx', 'png', 'jpg'], 'maxFileSizeBytes' => 50 * 1024 * 1024],
 *   ],
 */
return [
    'default' => [],
];
