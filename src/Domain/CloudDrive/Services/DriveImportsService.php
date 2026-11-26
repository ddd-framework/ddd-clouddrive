<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Imports\DriveImportedFile;
use DDD\Domain\CloudDrive\Entities\Imports\DriveImportOptions;
use DDD\Domain\CloudDrive\Entities\Imports\DriveImportResult;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWriteResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\Files\CloudDriveFileSourceService;
use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;

/**
 * Copies files of any file source into a folder of a drive space: the one way a file that is no drive file becomes
 * one (a picture of the media library for a presentation, an attachment for further work). Each file is read
 * through {@see FileSourcesService} under the caller's rights, checked by the application's flow rule
 * ({@see FileFlowPolicyService}: may a file of this audience lie in this space), and written like an upload: stored
 * by the backend, a new file or a new version at its name, then the drive's ingest (hash, type from the bytes, type
 * policy, malware scan). The node carries the origin `IMPORT`, the source's trust mark and an `IMPORTED` event
 * naming where it came from. A copy, never a link: afterwards the file lives under the rights of its space.
 */
class DriveImportsService extends Service
{
    /** @var string The host event of an imported file in the node's history */
    public const string EVENT_IMPORTED = 'IMPORTED';

    /** @var int At most this many files per import */
    public const int MAX_FILES_PER_IMPORT = 50;

    /** @var string[] The extension of a picture by its measured content type (after a metadata strip) */
    public const array EXTENSIONS_BY_IMAGE_CONTENT_TYPE = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
    ];

    /** @var array<string, string[]> Extensions that already name a format (`photo.jpeg` stays `photo.jpeg`) */
    public const array ALTERNATIVE_EXTENSIONS = [
        'jpg' => ['jpg', 'jpeg', 'jpe'],
        'heic' => ['heic', 'heif'],
    ];

    /** @var int The largest file an import reads into memory, unless `CloudDrive.settings.maxImportBytes` says otherwise */
    public const int DEFAULT_MAX_IMPORT_BYTES = 52428800;

    /** @var float How long one import waits for the scan decisions of what it wrote, in seconds */
    public const float DEFAULT_SCAN_WAIT_SECONDS = 20.0;

    public const int SCAN_POLL_MICROSECONDS = 250000;

    /** @var string[] Blob states that still wait for their ingest */
    public const array UNDECIDED_BLOB_STATES = [DriveBlob::STATE_UPLOADING, DriveBlob::STATE_STAGED, DriveBlob::STATE_PENDING];

    /**
     * Copies the files into the folder (created when missing). A file that cannot be copied is a refused line and
     * never stops the others.
     *
     * @throws BadRequestException When no file or too many are named, or the folder path leaves the space
     * @throws NotFoundException When the space does not exist for the caller
     * @throws ForbiddenException When the caller may not write the space
     * @throws InternalErrorException
     */
    public function importFiles(FileReferences $fileReferences, DriveSpace $driveSpace, string $folderPath, ?DriveImportOptions $driveImportOptions = null): DriveImportResult
    {
        $driveImportOptions ??= new DriveImportOptions();
        if ($fileReferences->count() === 0) {
            throw new BadRequestException('An import needs at least one file reference.');
        }
        if ($fileReferences->count() > static::MAX_FILES_PER_IMPORT) {
            throw new BadRequestException(sprintf('An import takes at most %d files.', static::MAX_FILES_PER_IMPORT));
        }
        $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        $normalizedFolderPath = implode('/', DriveNodesService::splitPath($folderPath));
        $fileFlowTarget = $this->buildImportFlowTarget($driveSpace, $normalizedFolderPath, $driveImportOptions);
        $driveImportResult = new DriveImportResult();
        $folderNode = null;
        $scanWaitEndsAt = microtime(true) + ($driveImportOptions->scanWaitSeconds ?? static::DEFAULT_SCAN_WAIT_SECONDS);
        foreach ($fileReferences->getElements() as $fileReference) {
            try {
                $fileFlowDecision = $this->decideImportOfFile($fileReference, $fileFlowTarget, $driveSpace, $driveImportOptions, $driveImportResult);
                if ($fileFlowDecision === null) {
                    continue;
                }
                $fileContent = $this->getFileSourcesService()->readFileContent($fileReference, min($this->getMaxImportBytes(), $driveSpace->getEffectiveMaxFileSizeBytes()));
                $folderNode ??= $this->getDriveNodesService()->findOrCreateFolderPath($driveSpace, $normalizedFolderPath);
                $driveImportedFile = $this->importFileContent($fileContent, $fileFlowDecision, $driveSpace, $folderNode, $scanWaitEndsAt);
                if ($driveImportedFile === null) {
                    $driveImportResult->addRefusal($fileReference, 'not written, the file in the folder changed at the same moment; try again');
                    continue;
                }
                if (in_array($driveImportedFile->contentState, [DriveBlob::STATE_BLOCKED, DriveBlob::STATE_SCAN_FAILED, DriveBlob::STATE_FAILED], true)) {
                    $driveImportResult->addRefusal($fileReference, sprintf(
                        'copied to %s, but the drive did not release its content (%s); it cannot be read or used',
                        $driveImportedFile->path,
                        strtolower(str_replace('_', ' ', $driveImportedFile->contentState))
                    ));
                    continue;
                }
                $driveImportResult->importedFiles->add($driveImportedFile);
            } catch (NotFoundException|BadRequestException|ForbiddenException $refusal) {
                $driveImportResult->addRefusal($fileReference, $refusal->getMessage());
            }
        }
        if ($driveImportResult->hasImportedFiles()) {
            $this->requestPullOfWorkingCopies($driveSpace, $driveImportOptions->workingCopyKey);
        }
        return $driveImportResult;
    }

    /**
     * The files an import would refuse before reading any byte — the caller's write right on the space, the source's
     * read right, the application's flow rule and a consent it asks for that the options do not carry — without
     * writing anything: `refusedLines` and `refusedFileReferences` of the result, `importedFiles` stays empty. For a
     * caller that must know the refusals before it asks the owner (the agent's save into a consent mount).
     *
     * @throws BadRequestException When the folder path leaves the space
     * @throws NotFoundException When the space does not exist for the caller
     * @throws ForbiddenException When the caller may not write the space
     */
    public function findImportRefusals(FileReferences $fileReferences, DriveSpace $driveSpace, string $folderPath, ?DriveImportOptions $driveImportOptions = null): DriveImportResult
    {
        $driveImportOptions ??= new DriveImportOptions();
        $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        $fileFlowTarget = $this->buildImportFlowTarget($driveSpace, implode('/', DriveNodesService::splitPath($folderPath)), $driveImportOptions);
        $driveImportResult = new DriveImportResult();
        foreach ($fileReferences->getElements() as $fileReference) {
            try {
                $this->decideImportOfFile($fileReference, $fileFlowTarget, $driveSpace, $driveImportOptions, $driveImportResult);
            } catch (NotFoundException|BadRequestException|ForbiddenException $refusal) {
                $driveImportResult->addRefusal($fileReference, $refusal->getMessage());
            }
        }
        return $driveImportResult;
    }

    /** The flow target of an import into this folder: the drive, the audience of the folder's path */
    public function buildImportFlowTarget(DriveSpace $driveSpace, string $normalizedFolderPath, DriveImportOptions $driveImportOptions): FileFlowTarget
    {
        return FileFlowTarget::forStore(
            CloudDriveFileSourceService::SOURCE_NAME,
            $this->getCloudDriveFileSourceService()->findAudienceOfPath($driveSpace, $normalizedFolderPath),
            $driveSpace->buildDisplayTitle(),
            $driveImportOptions->isOwnDrive,
            $normalizedFolderPath
        );
    }

    /**
     * The flow decision for one file, or null when it is refused (its line added to the result): the source's facts
     * under the caller's rights, the flow rule, a consent the rule asks for.
     *
     * @throws NotFoundException When the caller may not read the file
     */
    protected function decideImportOfFile(
        FileReference $fileReference,
        FileFlowTarget $fileFlowTarget,
        DriveSpace $driveSpace,
        DriveImportOptions $driveImportOptions,
        DriveImportResult $driveImportResult
    ): ?FileFlowDecision {
        $fileFacts = $this->getFileSourcesService()->requireFileFacts($fileReference);
        $fileFlowDecision = $this->getFileFlowPolicyService()->decideFileFlow($fileFacts, $fileFlowTarget);
        if (!$fileFlowDecision->isAllowed) {
            $driveImportResult->addRefusal($fileReference, $fileFlowDecision->refusalReason ?? 'this file must not be copied into this space');
            return null;
        }
        if ($fileFlowDecision->requiresOwnerConsent && !$driveImportOptions->hasOwnerConsent) {
            $driveImportResult->needsOwnerConsent = true;
            $driveImportResult->addRefusal($fileReference, sprintf(
                'not copied; %s is seen by more people than the file is now, so the owner has to say yes to this copy first',
                $driveSpace->buildDisplayTitle()
            ));
            return null;
        }
        return $fileFlowDecision;
    }

    /**
     * Writes one file's bytes into the folder and runs its ingest; null when the file in the folder changed at the
     * same moment (a conflict).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    protected function importFileContent(
        FileContent $fileContent,
        FileFlowDecision $fileFlowDecision,
        DriveSpace $driveSpace,
        DriveNode $folderNode,
        float $scanWaitEndsAt
    ): ?DriveImportedFile {
        $fileFacts = $fileContent->fileFacts;
        $bytes = $fileContent->getContent();
        $isMetadataRemoved = false;
        if ($fileFlowDecision->stripsMetadata && $fileFacts->isImage()) {
            $bytesWithoutMetadata = $this->getFileSourcesService()->removeImageMetadata($bytes);
            $isMetadataRemoved = $bytesWithoutMetadata !== $bytes;
            $bytes = $bytesWithoutMetadata;
        }
        $fileName = $this->buildFileName($fileFacts);
        $contentType = $fileFacts->contentType;
        if ($isMetadataRemoved) {
            // the strip may write another format (a HEIC picture becomes a JPEG): type and extension follow the bytes
            [$fileName, $contentType] = $this->adaptNameAndTypeToBytes($fileName, $contentType, $bytes);
        }
        $driveBlob = $this->storeBytes($driveSpace, $fileName, $bytes, $contentType);
        $existingNode = $this->getDriveNodesService()->findChildByName($folderNode, $fileName);
        $driveFileWrite = new DriveFileWrite();
        $driveFileWrite->driveBlobId = (int)$driveBlob->id;
        $driveFileWrite->comment = 'imported from ' . $this->buildSourceLabel($fileFacts);
        $driveFileWrite->origin = DriveNode::ORIGIN_IMPORT;
        $driveFileWrite->isContentUntrusted = $fileFacts->isContentUntrusted;
        // the base is the newest version read here: a version written between this read and the write is a conflict
        $driveFileWrite->expectedLatestVersionId = $existingNode?->latestVersionId;
        $driveFileWrite->authorAccountId = $this->getDriveChangesService()->findActingAccountIdOfCurrentCaller();
        $driveFileWrite->onBehalfOfAccountId = $this->getDriveChangesService()->findOnBehalfOfAccountIdOfCurrentCaller();
        // no working copy key: the change is another writer's for every working copy, so each of them pulls the file
        $driveFileWriteResult = $this->getDriveVersionsService()->writeFile($driveSpace, $folderNode, $fileName, $driveFileWrite);
        if ($driveFileWriteResult->isConflict || $driveFileWriteResult->driveNode === null) {
            return null;
        }
        $contentState = DriveBlob::STATE_RELEASED;
        if ($driveFileWriteResult->driveFileVersion !== null) {
            $this->getDriveVersionsService()->ingestVersion($driveFileWriteResult->driveFileVersion, !(bool)$folderNode->isSystem);
            $contentState = $this->waitForContentState($driveFileWriteResult, $scanWaitEndsAt);
        }
        $driveNode = $this->getDriveNodesService()->find((int)$driveFileWriteResult->driveNode->id, false) ?? $driveFileWriteResult->driveNode;
        if (!$driveFileWriteResult->isUnchanged) {
            $this->applyExpiry($driveNode, $fileFlowDecision);
            $this->getDriveChangesService()->recordHostEvent($driveNode, static::EVENT_IMPORTED, 'from ' . $this->buildSourceLabel($fileFacts));
        }
        $driveImportedFile = new DriveImportedFile();
        $driveImportedFile->sourceFileReference = $fileFacts->fileReference;
        $driveImportedFile->driveNode = $driveNode;
        $driveImportedFile->path = $this->getDriveNodesService()->buildPath($driveNode);
        $driveImportedFile->isUnchanged = $driveFileWriteResult->isUnchanged;
        $driveImportedFile->isMetadataRemoved = $isMetadataRemoved;
        $driveImportedFile->contentState = $contentState;
        return $driveImportedFile;
    }

    /**
     * Stores the bytes as a blob of the space, as a person's upload does (known bytes need no second object).
     *
     * @throws BadRequestException When the space does not take the file (size, quota)
     * @throws InternalErrorException
     */
    protected function storeBytes(DriveSpace $driveSpace, string $fileName, string $bytes, ?string $contentType): DriveBlob
    {
        $driveBlobsService = $this->getDriveBlobsService();
        $driveUploadRequest = new DriveUploadRequest();
        $driveUploadRequest->sizeBytes = strlen($bytes);
        $driveUploadRequest->contentType = $contentType;
        $driveUploadRequest->sha256 = hash('sha256', $bytes);
        $driveUploadRequest->fileName = $fileName;
        $driveUploadReservation = $driveBlobsService->reserveUpload($driveSpace, $driveUploadRequest);
        $driveBlob = $driveBlobsService->find($driveUploadReservation->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new InternalErrorException(sprintf('The reserved blob of %s is missing.', $fileName));
        }
        if ($driveBlob->state === DriveBlob::STATE_UPLOADING) {
            if ($driveBlob->multipartUploadId !== null) {
                $driveBlobsService->getObjectStorage()->abortMultipartUpload((string)$driveBlob->storageKey, $driveBlob->multipartUploadId);
                $driveBlob->multipartUploadId = null;
                $driveBlob->updatePartialIgnoringRights('multipartUploadId');
            }
            $driveBlobsService->getObjectStorage()->putObjectIfAbsent((string)$driveBlob->storageKey, $bytes, $contentType);
            $driveBlob = $driveBlobsService->completeUpload($driveBlob);
        }
        return $driveBlob;
    }

    /**
     * The name of the file in the drive: the source's name without anything a node name cannot hold.
     */
    public function buildFileName(FileFacts $fileFacts): string
    {
        $fileName = trim((string)preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '-', $fileFacts->name));
        $fileName = ltrim($fileName, '.');
        if ($fileName === '') {
            $fileName = $fileFacts->fileReference->sourceName . '-' . $fileFacts->fileReference->id;
        }
        if (mb_strlen($fileName) > DriveNode::MAX_NAME_LENGTH) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $suffix = $extension !== '' && mb_strlen($extension) <= 10 ? '.' . $extension : '';
            $fileName = mb_substr($fileName, 0, DriveNode::MAX_NAME_LENGTH - mb_strlen($suffix)) . $suffix;
        }
        return $fileName;
    }

    /**
     * The name and content type of bytes rewritten by the metadata strip: the type read from the bytes, and the
     * name's extension replaced when it names another format (`photo.heic` holding JPEG bytes becomes `photo.jpg`).
     *
     * @return string[] [file name, content type]
     */
    public function adaptNameAndTypeToBytes(string $fileName, ?string $contentType, string $bytes): array
    {
        $measuredContentType = RasterImageDecoder::detectMimeType($bytes);
        if ($measuredContentType === null || $measuredContentType === $contentType) {
            return [$fileName, $contentType];
        }
        $extensionsByContentType = static::EXTENSIONS_BY_IMAGE_CONTENT_TYPE;
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $wantedExtension = $extensionsByContentType[$measuredContentType] ?? null;
        if ($wantedExtension !== null && !in_array($extension, static::ALTERNATIVE_EXTENSIONS[$wantedExtension] ?? [$wantedExtension], true)) {
            $baseName = $extension !== '' ? mb_substr($fileName, 0, mb_strlen($fileName) - mb_strlen($extension) - 1) : $fileName;
            $fileName = $baseName . '.' . $wantedExtension;
        }
        return [$fileName, $measuredContentType];
    }

    /** Where a file came from, for the version comment and the event: `Media library (media/901)` */
    public function buildSourceLabel(FileFacts $fileFacts): string
    {
        $containerLabel = trim((string)$fileFacts->containerLabel);
        return ($containerLabel !== '' ? $containerLabel . ' ' : '') . '(' . $fileFacts->fileReference->toString() . ')';
    }

    /** Sets the day the copy has to go when the flow rule names one */
    protected function applyExpiry(DriveNode $driveNode, FileFlowDecision $fileFlowDecision): void
    {
        if ($fileFlowDecision->expiresAfterDays === null) {
            return;
        }
        $driveNode->expiresAt = DateTime::fromTimestamp(time() + 86400 * max(1, $fileFlowDecision->expiresAfterDays));
        $this->storeExpiryOfNode($driveNode);
    }

    protected function storeExpiryOfNode(DriveNode $driveNode): void
    {
        $driveNode->updatePartialIgnoringRights('expiresAt');
    }

    /**
     * The state of the written content once its ingest decided, or when the time is up (the ingest may run in a
     * worker: `CloudDrive.settings.asyncIngest`).
     */
    protected function waitForContentState(DriveFileWriteResult $driveFileWriteResult, float $scanWaitEndsAt): string
    {
        $driveFileVersionId = (int)($driveFileWriteResult->driveFileVersion?->id ?? 0);
        while (true) {
            $driveFileVersion = $this->getDriveVersionsService()->find($driveFileVersionId, false);
            $driveBlob = $driveFileVersion instanceof DriveFileVersion ? $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false) : null;
            $blobState = $driveBlob instanceof DriveBlob ? (string)$driveBlob->state : DriveBlob::STATE_RELEASED;
            if (!in_array($blobState, static::UNDECIDED_BLOB_STATES, true) || microtime(true) >= $scanWaitEndsAt) {
                return $blobState;
            }
            usleep(static::SCAN_POLL_MICROSECONDS);
        }
    }

    /**
     * Marks the space's working copies for a pull, so a sandbox sees the imported files at its next call: the
     * newest one and the one the caller names.
     */
    protected function requestPullOfWorkingCopies(DriveSpace $driveSpace, ?string $workingCopyKey): void
    {
        $driveWorkingCopiesService = $this->getDriveWorkingCopiesService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveWorkingCopies = [
                $workingCopyKey !== null && $workingCopyKey !== '' ? $driveWorkingCopiesService->findWorkingCopy($driveSpace, $workingCopyKey) : null,
                $driveWorkingCopiesService->findNewestWorkingCopy($driveSpace),
            ];
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $markedWorkingCopyIds = [];
        foreach ($driveWorkingCopies as $driveWorkingCopy) {
            if ($driveWorkingCopy instanceof DriveWorkingCopy && !isset($markedWorkingCopyIds[(int)$driveWorkingCopy->id])) {
                $markedWorkingCopyIds[(int)$driveWorkingCopy->id] = true;
                $driveWorkingCopiesService->requestPull($driveWorkingCopy);
            }
        }
    }

    public function getMaxImportBytes(): int
    {
        $maxImportBytes = Config::get('CloudDrive.settings.maxImportBytes');
        return is_int($maxImportBytes) && $maxImportBytes > 0 ? $maxImportBytes : static::DEFAULT_MAX_IMPORT_BYTES;
    }

    protected function getFileSourcesService(): FileSourcesService
    {
        /** @var FileSourcesService $fileSourcesService */
        $fileSourcesService = DDDService::instance()->getService(FileSourcesService::class);
        return $fileSourcesService;
    }

    protected function getFileFlowPolicyService(): FileFlowPolicyService
    {
        /** @var FileFlowPolicyService $fileFlowPolicyService */
        $fileFlowPolicyService = DDDService::instance()->getService(FileFlowPolicyService::class);
        return $fileFlowPolicyService;
    }

    protected function getCloudDriveFileSourceService(): CloudDriveFileSourceService
    {
        /** @var CloudDriveFileSourceService $cloudDriveFileSourceService */
        $cloudDriveFileSourceService = DDDService::instance()->getService(CloudDriveFileSourceService::class);
        return $cloudDriveFileSourceService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService;
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        /** @var DriveWorkingCopiesService $driveWorkingCopiesService */
        $driveWorkingCopiesService = DDDService::instance()->getService(DriveWorkingCopiesService::class);
        return $driveWorkingCopiesService;
    }
}
