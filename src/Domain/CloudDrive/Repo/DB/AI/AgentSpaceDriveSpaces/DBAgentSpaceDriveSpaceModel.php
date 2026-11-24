<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentSpaceDriveSpaces')]
class DBAgentSpaceDriveSpaceModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentSpaceDriveSpace';

	public const string TABLE_NAME = 'AgentSpaceDriveSpaces';

	public const string ENTITY_CLASS = 'DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpace';

	#[ORM\Column(type: 'integer')]
	public int $agentSpaceId;

	#[ORM\Column(type: 'integer')]
	public int $driveSpaceId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAgentSpaceModel::class)]
	#[ORM\JoinColumn(name: 'agentSpaceId', referencedColumnName: 'id')]
	public DBAgentSpaceModel $agentSpace;

	#[ORM\ManyToOne(targetEntity: DBDriveSpaceModel::class)]
	#[ORM\JoinColumn(name: 'driveSpaceId', referencedColumnName: 'id')]
	public DBDriveSpaceModel $driveSpace;

}