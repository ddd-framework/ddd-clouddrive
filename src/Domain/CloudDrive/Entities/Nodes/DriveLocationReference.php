<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A location as a person pastes it, normalised: a path in a space (`/Templates/Typst`, `Templates/Typst`), a reference
 * `drive/<nodeId>`, a path below another space's mount (`drivespaces/<mount>/…`) or an agent sandbox path
 * (`/workspace/drive/<mount>/…`, `/workspace/<path>`). {@see \DDD\Domain\CloudDrive\Services\DriveNodesService::resolveLocation()}
 * resolves it under the caller's rights.
 */
class DriveLocationReference extends ValueObject
{
    /** @var string The sandbox root an agent's paths start with */
    public const string WORKSPACE_ROOT = 'workspace';

    /** @var string The folder below which an agent sandbox mounts further spaces */
    public const string MOUNTS_FOLDER = 'drivespaces';

    /** @var string The folder of an agent sandbox that holds its own drive (`/workspace/drive/…`) */
    public const string OWN_DRIVE_FOLDER = 'drive';

    /** @var string The location as given */
    public string $location = '';

    /** @var int|null The node of a reference `drive/<nodeId>` */
    public ?int $driveNodeId = null;

    /** @var string|null The mount that names another space (`drivespaces/<mount>/…`, `/workspace/drive/<mount>/…`) */
    public ?string $mountName = null;

    /** @var string The path below the space's root (`/`-separated, no leading slash; `` = the root) */
    public string $path = '';

    /** @var string|null The path in the requested space to use when the mount names no space (`/workspace/drive/<folder>/…`) */
    public ?string $pathWhenMountIsUnknown = null;

    /**
     * @param string $location the pasted location
     * @return static
     */
    public static function fromLocation(string $location): static
    {
        $driveLocationReference = new static();
        $driveLocationReference->location = $location;
        $normalizedLocation = trim(str_replace('\\', '/', $location));
        $pathSegments = [];
        foreach (explode('/', $normalizedLocation) as $pathSegment) {
            if ($pathSegment !== '' && $pathSegment !== '.') {
                $pathSegments[] = $pathSegment;
            }
        }
        $isWorkspacePath = str_starts_with($normalizedLocation, '/' . self::WORKSPACE_ROOT . '/')
            || $normalizedLocation === '/' . self::WORKSPACE_ROOT;
        if ($isWorkspacePath) {
            array_shift($pathSegments);
        }
        if (count($pathSegments) === 2 && $pathSegments[0] === self::OWN_DRIVE_FOLDER && preg_match('/^\d+$/', $pathSegments[1]) === 1
            && !$isWorkspacePath
        ) {
            $driveLocationReference->driveNodeId = (int)$pathSegments[1];
            return $driveLocationReference;
        }
        if (count($pathSegments) >= 2 && $pathSegments[0] === self::MOUNTS_FOLDER) {
            $driveLocationReference->mountName = $pathSegments[1];
            $driveLocationReference->path = implode('/', array_slice($pathSegments, 2));
            return $driveLocationReference;
        }
        if ($isWorkspacePath && $pathSegments !== [] && $pathSegments[0] === self::OWN_DRIVE_FOLDER) {
            // the sandbox's own drive: a further space's mount, else a folder of the requested space
            $pathBelowOwnDrive = array_slice($pathSegments, 1);
            if ($pathBelowOwnDrive !== []) {
                $driveLocationReference->mountName = $pathBelowOwnDrive[0];
                $driveLocationReference->path = implode('/', array_slice($pathBelowOwnDrive, 1));
                $driveLocationReference->pathWhenMountIsUnknown = implode('/', $pathBelowOwnDrive);
            }
            return $driveLocationReference;
        }
        $driveLocationReference->path = implode('/', $pathSegments);
        return $driveLocationReference;
    }

    /** True for a reference `drive/<nodeId>` */
    public function isNodeReference(): bool
    {
        return $this->driveNodeId !== null;
    }
}
