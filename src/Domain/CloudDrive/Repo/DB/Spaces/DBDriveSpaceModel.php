<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Spaces;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use App\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveSpaces')]
class DBDriveSpaceModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveSpace';

	public const string TABLE_NAME = 'DriveSpaces';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace';

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'string')]
	public string $spaceKind = 'default';

	#[ORM\Column(type: 'string')]
	public string $audience = 'INTERNAL';

	#[ORM\Column(type: 'string')]
	public ?string $title;

	#[ORM\Column(type: 'integer')]
	public ?int $rootNodeId;

	#[ORM\Column(type: 'integer')]
	public ?int $quotaBytes;

	#[ORM\Column(type: 'integer')]
	public ?int $maxFileSizeBytes;

	#[ORM\Column(type: 'integer')]
	public int $usedBytes = 0;

	#[ORM\Column(type: 'integer')]
	public int $fileCount = 0;

	#[ORM\Column(type: 'integer')]
	public int $folderCount = 0;

	#[ORM\Column(type: 'boolean')]
	public bool $isAudienceWriteLocked = false;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAccountModel::class)]
	#[ORM\JoinColumn(name: 'accountId', referencedColumnName: 'id')]
	public ?DBAccountModel $account;

}