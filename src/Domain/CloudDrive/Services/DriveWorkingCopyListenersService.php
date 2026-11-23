<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolutions;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * Calls the host's working-copy listeners ({@see DriveWorkingCopyListener}) in registration order; a throw is logged as
 * a warning with the listener's class and the others still run.
 */
class DriveWorkingCopyListenersService extends Service
{
    /** @var iterable<DriveWorkingCopyListener> */
    protected iterable $listeners = [];

    /** @param iterable<DriveWorkingCopyListener> $listeners */
    #[Required]
    public function setListeners(
        #[AutowireIterator(DriveWorkingCopyListener::TAG)]
        iterable $listeners
    ): void {
        $this->listeners = $listeners;
    }

    public function notifyBeforeWorkingCopyRead(DriveWorkingCopy $driveWorkingCopy): void
    {
        foreach ($this->listeners as $driveWorkingCopyListener) {
            try {
                $driveWorkingCopyListener->beforeWorkingCopyRead($driveWorkingCopy);
            } catch (Throwable $throwable) {
                $this->logListenerFailure($driveWorkingCopyListener, 'beforeWorkingCopyRead', $driveWorkingCopy, $throwable);
            }
        }
    }

    public function notifyAfterWorkingCopyCommitted(DriveWorkingCopy $driveWorkingCopy, DriveCommitResult $driveCommitResult, ?DriveCommitResolutions $driveCommitResolutions): void
    {
        foreach ($this->listeners as $driveWorkingCopyListener) {
            try {
                $driveWorkingCopyListener->afterWorkingCopyCommitted($driveWorkingCopy, $driveCommitResult, $driveCommitResolutions);
            } catch (Throwable $throwable) {
                $this->logListenerFailure($driveWorkingCopyListener, 'afterWorkingCopyCommitted', $driveWorkingCopy, $throwable);
            }
        }
    }

    protected function logListenerFailure(DriveWorkingCopyListener $driveWorkingCopyListener, string $hookName, DriveWorkingCopy $driveWorkingCopy, Throwable $throwable): void
    {
        DDDService::instance()->getLogger()->warning('[CloudDrive] working copy listener failed', [
            'listener' => $driveWorkingCopyListener::class,
            'hook' => $hookName,
            'workingCopyKey' => $driveWorkingCopy->workingCopyKey,
            'reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 500),
        ]);
    }
}
