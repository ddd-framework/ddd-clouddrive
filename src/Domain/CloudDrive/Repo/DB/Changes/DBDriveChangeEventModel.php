<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Changes;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveChangeEvents')]
class DBDriveChangeEventModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveChangeEvent';

	public const string TABLE_NAME = 'DriveChangeEvents';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent';

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'integer')]
	public ?int $driveNodeId;

	#[ORM\Column(type: 'string')]
	public string $eventType;

	#[ORM\Column(type: 'integer')]
	public ?int $driveFileVersionId;

	#[ORM\Column(type: 'string')]
	public ?string $path;

	#[ORM\Column(type: 'string')]
	public ?string $previousPath;

	#[ORM\Column(type: 'integer')]
	public ?int $actorAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $workingCopyKey;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}