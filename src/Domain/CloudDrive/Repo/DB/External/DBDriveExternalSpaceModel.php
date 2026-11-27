<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\External;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveExternalSpaces')]
class DBDriveExternalSpaceModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveExternalSpace';

	public const string TABLE_NAME = 'DriveExternalSpaces';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\External\DriveExternalSpace';

	#[ORM\Column(type: 'integer')]
	public ?int $driveSpaceId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentConnectionId;

	#[ORM\Column(type: 'string')]
	public ?string $externalRootItemId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBDriveSpaceModel::class)]
	#[ORM\JoinColumn(name: 'driveSpaceId', referencedColumnName: 'id')]
	public ?DBDriveSpaceModel $driveSpace;

}