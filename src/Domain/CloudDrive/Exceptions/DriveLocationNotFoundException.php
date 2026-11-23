<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Exceptions;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItem;
use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * A pasted location names nothing the caller can read. Carries where the walk stopped, so a path bar can say which
 * segment is missing and offer the deepest folder that exists.
 *
 * @phpstan-consistent-constructor
 */
class DriveLocationNotFoundException extends NotFoundException
{
    /** @var int|null The space the walk ran in */
    public ?int $driveSpaceId = null;

    /** @var string|null The path that was looked for, normalised */
    public ?string $path = null;

    /** @var DriveNodeListItem|null The deepest folder of the path that exists */
    public ?DriveNodeListItem $deepestFolder = null;

    /** @var string|null The first segment (a name, a mount or `drive/<id>`) that names nothing */
    public ?string $missingSegment = null;

    /**
     * @param int|null $driveSpaceId the space the walk ran in
     * @param string|null $path the normalised path
     * @param string $missingSegment the first segment that names nothing
     * @param DriveNodeListItem|null $deepestFolder the deepest folder that exists
     * @return static
     */
    public static function forMissingSegment(?int $driveSpaceId, ?string $path, string $missingSegment, ?DriveNodeListItem $deepestFolder): static
    {
        $driveLocationNotFoundException = new static(sprintf('"%s" was not found.', $missingSegment));
        $driveLocationNotFoundException->driveSpaceId = $driveSpaceId;
        $driveLocationNotFoundException->path = $path;
        $driveLocationNotFoundException->missingSegment = $missingSegment;
        $driveLocationNotFoundException->deepestFolder = $deepestFolder;
        return $driveLocationNotFoundException;
    }
}
