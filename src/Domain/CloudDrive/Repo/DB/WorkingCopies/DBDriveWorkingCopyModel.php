<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\WorkingCopies;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveWorkingCopies')]
class DBDriveWorkingCopyModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveWorkingCopy';

	public const string TABLE_NAME = 'DriveWorkingCopies';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy';

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'string')]
	public string $workingCopyKey;

	#[ORM\Column(type: 'integer')]
	public int $changeCursor = 0;

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
	public DBDriveSpaceModel $driveSpace;

}