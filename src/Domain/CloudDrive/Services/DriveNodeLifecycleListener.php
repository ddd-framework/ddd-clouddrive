<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The host side of a node's end (registered by this tag, autoconfigured), called through
 * {@see DriveNodeLifecycleListenersService}: an application that keeps its own rows on node ids (links of files to its
 * records, read markers) drops them here.
 *
 * - {@see onNodesPurged()}: after nodes were deleted for good (purge, empty trash, the trash retention, a transfer's
 *   source, the erase of a space), with every purged id of the subtrees; the rows are gone already.
 *
 * A listener that throws is logged and skipped; the purge stays done.
 */
#[AutoconfigureTag(DriveNodeLifecycleListener::TAG)]
interface DriveNodeLifecycleListener
{
    public const string TAG = 'clouddrive.node_lifecycle_listener';

    /**
     * @param int[] $driveNodeIds every purged node, folders and their content
     */
    public function onNodesPurged(DriveSpace $driveSpace, array $driveNodeIds): void;
}
