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
#[ORM\Table(name: 'DriveExternalItems')]
class DBDriveExternalItemModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveExternalItem';

	public const string TABLE_NAME = 'DriveExternalItems';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\External\DriveExternalItem';

	#[ORM\Column(type: 'integer')]
	public ?int $driveSpaceId;

	#[ORM\Column(type: 'string')]
	public ?string $providerItemId;

	#[ORM\Column(type: 'string')]
	public ?string $parentProviderItemId;

	#[ORM\Column(type: 'string')]
	public ?string $displayName;

	#[ORM\Column(type: 'string')]
	public ?string $filesystemName;

	#[ORM\Column(type: 'string')]
	public ?string $path;

	#[ORM\Column(type: 'string')]
	public ?string $mimeType;

	#[ORM\Column(type: 'string')]
	public ?string $kind;

	#[ORM\Column(type: 'integer')]
	public ?int $sizeBytes;

	#[ORM\Column(type: 'string')]
	public ?string $md5Checksum;

	#[ORM\Column(type: 'string')]
	public ?string $providerRevision;

	#[ORM\Column(type: 'integer')]
	public ?int $surrogateVersion = 1;

	#[ORM\Column(type: 'boolean')]
	public ?bool $canEdit = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $canComment = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $canShare = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $canTrash = false;

	#[ORM\Column(type: 'string')]
	public ?string $webViewLink;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $modifiedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $syncedAt;

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