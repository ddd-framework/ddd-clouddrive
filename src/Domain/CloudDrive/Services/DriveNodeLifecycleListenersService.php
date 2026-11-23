<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/** Calls the host's node lifecycle listeners ({@see DriveNodeLifecycleListener}); a throw is logged, the others still run */
class DriveNodeLifecycleListenersService extends Service
{
    /** @var iterable<DriveNodeLifecycleListener> */
    protected iterable $listeners = [];

    /** @param iterable<DriveNodeLifecycleListener> $listeners */
    #[Required]
    public function setListeners(
        #[AutowireIterator(DriveNodeLifecycleListener::TAG)]
        iterable $listeners
    ): void {
        $this->listeners = $listeners;
    }

    /**
     * @param int[] $driveNodeIds
     */
    public function notifyNodesPurged(DriveSpace $driveSpace, array $driveNodeIds): void
    {
        $driveNodeIds = array_values(array_unique(array_filter(array_map('intval', $driveNodeIds))));
        if ($driveNodeIds === []) {
            return;
        }
        foreach ($this->listeners as $driveNodeLifecycleListener) {
            try {
                $driveNodeLifecycleListener->onNodesPurged($driveSpace, $driveNodeIds);
            } catch (Throwable $throwable) {
                DDDService::instance()->getLogger()->warning('[CloudDrive] node lifecycle listener failed', [
                    'listener' => $driveNodeLifecycleListener::class,
                    'hook' => 'onNodesPurged',
                    'driveSpaceId' => $driveSpace->id,
                    'reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 500),
                ]);
            }
        }
    }
}
