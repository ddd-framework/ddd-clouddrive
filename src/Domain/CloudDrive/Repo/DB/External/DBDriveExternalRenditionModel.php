<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\External;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveExternalRenditions')]
class DBDriveExternalRenditionModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveExternalRendition';

	public const string TABLE_NAME = 'DriveExternalRenditions';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\External\DriveExternalRendition';

	#[ORM\Column(type: 'integer')]
	public ?int $driveExternalItemId;

	#[ORM\Column(type: 'string')]
	public ?string $providerRevision;

	#[ORM\Column(type: 'string')]
	public ?string $format = 'ORIGINAL';

	#[ORM\Column(type: 'string')]
	public ?string $contentType;

	#[ORM\Column(type: 'string')]
	public ?string $sha256;

	#[ORM\Column(type: 'integer')]
	public ?int $sizeBytes;

	#[ORM\Column(type: 'string')]
	public ?string $storageKey;

	#[ORM\Column(type: 'integer')]
	public ?int $driveBlobId;

	#[ORM\Column(type: 'string')]
	public ?string $fileName;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBDriveExternalItemModel::class)]
	#[ORM\JoinColumn(name: 'driveExternalItemId', referencedColumnName: 'id')]
	public ?DBDriveExternalItemModel $driveExternalItem;

}