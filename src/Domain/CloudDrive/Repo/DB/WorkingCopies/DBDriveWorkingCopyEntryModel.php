<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\WorkingCopies;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveWorkingCopyEntries')]
class DBDriveWorkingCopyEntryModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveWorkingCopyEntry';

	public const string TABLE_NAME = 'DriveWorkingCopyEntries';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntry';

	#[ORM\Column(type: 'integer')]
	public int $driveWorkingCopyId;

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'string')]
	public string $path;

	#[ORM\Column(type: 'string')]
	public string $changeKind = 'MODIFIED';

	#[ORM\Column(type: 'string')]
	public ?string $previousPath;

	#[ORM\Column(type: 'integer')]
	public ?int $driveNodeId;

	#[ORM\Column(type: 'integer')]
	public ?int $baseVersionId;

	#[ORM\Column(type: 'integer')]
	public ?int $stagedDriveBlobId;

	#[ORM\Column(type: 'string')]
	public ?string $conflictReason;

	#[ORM\Column(type: 'integer')]
	public ?int $conflictVersionId;

	#[ORM\Column(type: 'string')]
	public ?string $conversationKey;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}