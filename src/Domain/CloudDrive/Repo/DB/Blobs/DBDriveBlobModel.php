<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Blobs;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DriveBlobs')]
class DBDriveBlobModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DriveBlob';

	public const string TABLE_NAME = 'DriveBlobs';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob';

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Column(type: 'string')]
	public string $storageKey;

	#[ORM\Column(type: 'string')]
	public string $state = 'UPLOADING';

	#[ORM\Column(type: 'integer')]
	public int $sizeBytes = 0;

	#[ORM\Column(type: 'string')]
	public ?string $sha256;

	#[ORM\Column(type: 'string')]
	public ?string $contentMd5;

	#[ORM\Column(type: 'string')]
	public ?string $contentType;

	#[ORM\Column(type: 'string')]
	public ?string $multipartUploadId;

	#[ORM\Column(type: 'string')]
	public ?string $idempotencyKey;

	#[ORM\Column(type: 'string')]
	public ?string $stateReason;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $expiresAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}