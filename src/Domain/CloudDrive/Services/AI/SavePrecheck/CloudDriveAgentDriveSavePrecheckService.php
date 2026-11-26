<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI\SavePrecheck;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveRefusal;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveRefusals;
use DDD\Domain\AI\Services\Drives\AgentDriveSavePrecheck;
use DDD\Domain\CloudDrive\Entities\Imports\DriveImportOptions;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveImportsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The precheck of the drive's agent store (ddd-agents ≥ 0.7.9): before `saveDriveFile` asks the owner for a copy into
 * a consent mount, the files of other sources the import would refuse anyway (not readable for the caller, kept out
 * by the application's flow rule) are named here, so the owner is never asked for a step that cannot happen. Copies
 * inside the drive have no flow rule and are never refused here. Registered only beside ddd-agents' precheck port
 * (`CloudDriveModule::getNamespacesRequiringClasses()`).
 */
class CloudDriveAgentDriveSavePrecheckService extends Service implements AgentDriveSavePrecheck
{
    public function getStoreName(): string
    {
        return CloudDriveAgentDriveStoreService::STORE_NAME;
    }

    public function findSaveRefusals(AgentToolCallProvenance $callProvenance, array $sourcePaths, AgentDriveMount $targetMount, string $targetFolderPath): AgentDriveSaveRefusals
    {
        $agentDriveSaveRefusals = new AgentDriveSaveRefusals();
        $fileReferences = new FileReferences();
        /** @var string[] $sourceArgumentsByReference */
        $sourceArgumentsByReference = [];
        foreach ($sourcePaths as $sourcePath) {
            $fileReference = $this->findFileReferenceOfAnotherSource($sourcePath);
            if ($fileReference !== null) {
                $fileReferences->add($fileReference);
                $sourceArgumentsByReference[$fileReference->toString()] = $sourcePath->argument;
            }
        }
        if ($fileReferences->count() === 0) {
            return $agentDriveSaveRefusals;
        }
        $driveImportOptions = new DriveImportOptions();
        // the consent the tool is about to ask for counts: a decision that asks for consent is no refusal here
        $driveImportOptions->hasOwnerConsent = $targetMount->getEffectiveWriteMode() === AgentDriveMount::MODE_WRITE_WITH_CONSENT;
        $driveImportOptions->isOwnDrive = $targetMount->isOwnDrive;
        try {
            $targetSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$targetMount->spaceKey, DBDriveSpace::RIGHT_WRITE);
            $driveImportResult = $this->getDriveImportsService()->findImportRefusals($fileReferences, $targetSpace, $targetFolderPath, $driveImportOptions);
        } catch (NotFoundException|BadRequestException|ForbiddenException) {
            return $agentDriveSaveRefusals;   // the save itself reports a target it cannot write
        }
        foreach ($driveImportResult->refusedFileReferences->getElements() as $refusedFileReference) {
            $referenceText = $refusedFileReference->toString();
            $reason = 'not copied';
            foreach ($driveImportResult->refusedLines as $refusedLine) {
                if (str_starts_with($refusedLine, $referenceText . ': ')) {
                    $reason = substr($refusedLine, strlen($referenceText) + 2);
                    break;
                }
            }
            $agentDriveSaveRefusal = AgentDriveSaveRefusal::forSourceArgument($sourceArgumentsByReference[$referenceText] ?? $referenceText, $reason);
            $agentDriveSaveRefusals->add($agentDriveSaveRefusal);
        }
        return $agentDriveSaveRefusals;
    }

    /** The reference of a source argument that names a file of another file source (`media/901`), else null */
    protected function findFileReferenceOfAnotherSource(AgentDrivePath $sourcePath): ?FileReference
    {
        if ($sourcePath->reference === null
            || ($sourcePath->agentDriveMount?->storeName ?? CloudDriveAgentDriveStoreService::STORE_NAME) === CloudDriveAgentDriveStoreService::STORE_NAME
        ) {
            return null;
        }
        return FileReference::tryFromString($sourcePath->reference);
    }

    protected function getDriveImportsService(): DriveImportsService
    {
        /** @var DriveImportsService $driveImportsService */
        $driveImportsService = DDDService::instance()->getService(DriveImportsService::class);
        return $driveImportsService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }
}
