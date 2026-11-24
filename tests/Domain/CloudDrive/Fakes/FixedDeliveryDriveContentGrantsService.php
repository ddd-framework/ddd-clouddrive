<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use Throwable;

/** Content grants answering one delivery or one refusal of the test; the purposes asked for are recorded */
class FixedDeliveryDriveContentGrantsService extends DriveContentGrantsService
{
    public ?DriveContentDelivery $driveContentDelivery = null;

    public ?Throwable $refusal = null;

    /** @var string[] The purposes resolveContentDelivery was asked for, in order */
    public array $askedPurposes = [];

    public function __construct()
    {
    }

    public function resolveContentDelivery(DriveNode $driveNode, string $purpose, ?DriveFileVersion $driveFileVersion = null): DriveContentDelivery
    {
        $this->askedPurposes[] = $purpose;
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
        return $this->driveContentDelivery ?? new DriveContentDelivery();
    }
}
