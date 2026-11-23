<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Activities;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveActivityEvents')]
class DBDriveActivityEventModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveActivityEvent';

	public const string TABLE_NAME = 'DriveActivityEvents';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent';

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'integer')]
	public ?int $driveNodeId;

	#[ORM\Column(type: 'integer')]
	public ?int $driveChangeEventId;

	#[ORM\Column(type: 'string')]
	public string $eventType;

	#[ORM\Column(type: 'integer')]
	public ?int $driveFileVersionId;

	#[ORM\Column(type: 'integer')]
	public ?int $actorAccountId;

	#[ORM\Column(type: 'integer')]
	public ?int $onBehalfOfAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $audience;

	#[ORM\Column(type: 'string')]
	public ?string $contentVariant;

	#[ORM\Column(type: 'string')]
	public ?string $detail;

	#[ORM\Column(type: 'string')]
	public ?string $clientIp;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}