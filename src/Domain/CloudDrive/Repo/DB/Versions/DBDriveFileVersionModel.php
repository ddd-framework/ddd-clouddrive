<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Versions;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNodeModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveFileVersions')]
class DBDriveFileVersionModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveFileVersion';

	public const string TABLE_NAME = 'DriveFileVersions';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion';

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'integer')]
	public int $driveNodeId;

	#[ORM\Column(type: 'integer')]
	public int $versionNumber = 1;

	#[ORM\Column(type: 'integer')]
	public int $driveBlobId;

	#[ORM\Column(type: 'integer')]
	public ?int $sizeBytes;

	#[ORM\Column(type: 'string')]
	public ?string $contentType;

	#[ORM\Column(type: 'string')]
	public ?string $sha256;

	#[ORM\Column(type: 'string')]
	public ?string $comment;

	#[ORM\Column(type: 'string')]
	public ?string $origin = 'UPLOAD';

	#[ORM\Column(type: 'boolean')]
	public bool $isContentUntrusted = false;

	#[ORM\Column(type: 'integer')]
	public ?int $authorAccountId;

	#[ORM\Column(type: 'integer')]
	public ?int $onBehalfOfAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $conversationKey;

	#[ORM\Column(type: 'integer')]
	public ?int $copiedFromVersionId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBDriveNodeModel::class)]
	#[ORM\JoinColumn(name: 'driveNodeId', referencedColumnName: 'id')]
	public DBDriveNodeModel $driveNode;

}