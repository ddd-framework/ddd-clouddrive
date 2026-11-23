<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Nodes;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveNodes')]
class DBDriveNodeModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveNode';

	public const string TABLE_NAME = 'DriveNodes';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\Nodes\DriveNode';

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'integer')]
	public ?int $parentNodeId;

	#[ORM\Column(type: 'string')]
	public string $nodeType = 'FILE';

	#[ORM\Column(type: 'string')]
	public string $name = '';

	#[ORM\Column(type: 'string')]
	public string $nameSlot = '';

	#[ORM\Column(type: 'string')]
	public ?string $nameFolded = '';

	#[ORM\Column(type: 'integer')]
	public ?int $linkTargetNodeId;

	#[ORM\Column(type: 'integer')]
	public ?int $currentVersionId;

	#[ORM\Column(type: 'integer')]
	public ?int $latestVersionId;

	#[ORM\Column(type: 'integer')]
	public ?int $versionNumber;

	#[ORM\Column(type: 'integer')]
	public ?int $sizeBytes;

	#[ORM\Column(type: 'string')]
	public ?string $contentType;

	#[ORM\Column(type: 'string')]
	public ?string $sha256;

	#[ORM\Column(type: 'string')]
	public ?string $tags;

	#[ORM\Column(type: 'string')]
	public ?string $description;

	#[ORM\Column(type: 'boolean')]
	public bool $isSystem = false;

	#[ORM\Column(type: 'boolean')]
	public bool $isContentUntrusted = false;

	#[ORM\Column(type: 'string')]
	public ?string $origin = 'UPLOAD';

	#[ORM\Column(type: 'integer')]
	public ?int $copiedFromNodeId;

	#[ORM\Column(type: 'integer')]
	public ?int $copiedFromVersionId;

	#[ORM\Column(type: 'integer')]
	public int $childCount = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $expiresAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $trashedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $trashedByAccountId;

	#[ORM\Column(type: 'integer')]
	public ?int $trashedWithNodeId;

	#[ORM\Column(type: 'integer')]
	public ?int $createdByAccountId;

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