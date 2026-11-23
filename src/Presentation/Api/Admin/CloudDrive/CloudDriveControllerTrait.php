<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveChildrenGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveContentGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveContentGrantResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFileCardsRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFileCardsResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFolderCreateRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeMetadataRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeMoveRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodesGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSearchGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpaceGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpaceGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpacesGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadCompleteRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadPartsRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadReservationResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadReserveRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The drive screen's backend: spaces, folders, files, uploads (reserve, parts, complete), content URLs, file cards,
 * move, tags, trash and restore, name search. A host mounts it with one controller that sets the path prefix, the tag
 * and the access (`#[Route('/common/cloudDrive')] class AdminCloudDriveController extends AdminController { use
 * CloudDriveControllerTrait; }`). Every read and write runs under the host's space rights; the access hook
 * {@see throwForbiddenUnlessCloudDriveAccessAllowed()} runs first in every action.
 */
trait CloudDriveControllerTrait
{
    /** An application whose rule is an account predicate, not a role, throws ForbiddenException here */
    protected function throwForbiddenUnlessCloudDriveAccessAllowed(): void
    {
    }

    /**
     * The drive spaces the caller may read.
     */
    #[Get('/spaces/list')]
    #[Summary('DriveSpaces List')]
    public function listDriveSpaces(DriveSpacesService $driveSpacesService): DriveSpacesGetResponseDto
    {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $responseDto = new DriveSpacesGetResponseDto();
        $driveSpaces = $driveSpacesService->findAll(null, 500, false);
        $responseDto->driveSpaces = $driveSpaces instanceof DriveSpaces ? $driveSpaces : new DriveSpaces();
        return $responseDto;
    }

    /**
     * One drive space with its counters.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Get('/spaces/{driveSpaceId}')]
    #[Summary('DriveSpace Details')]
    public function getDriveSpace(DriveSpaceGetRequestDto $requestDto, DriveSpacesService $driveSpacesService): DriveSpaceGetResponseDto
    {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $responseDto = new DriveSpaceGetResponseDto();
        $responseDto->driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        return $responseDto;
    }

    /**
     * One page of a folder (the root when no folder is given).
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Get('/spaces/{driveSpaceId}/children')]
    #[Summary('DriveNodes List')]
    public function listDriveChildren(
        DriveChildrenGetRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveNodesGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $folderNode = $requestDto->folderNodeId !== null
            ? $driveNodesService->findNodeOfSpace($driveSpace, $requestDto->folderNodeId)
            : $driveNodesService->findRootNode($driveSpace);
        if ($folderNode === null || !$folderNode->isFolder()) {
            throw new NotFoundException('Folder not found.');
        }
        $limit = max(1, min(DriveNodesService::MAX_LIST_LIMIT, $requestDto->limit ?? 100));
        $includeSystem = (bool)$requestDto->includeSystem || $driveSpacesService->hasRightOnSpace($driveSpace, DBDriveSpace::RIGHT_MANAGE);
        $responseDto = new DriveNodesGetResponseDto();
        $responseDto->driveNodes = $driveNodesService->findChildren($folderNode, $requestDto->cursor, $limit, $includeSystem);
        $lastNode = $responseDto->driveNodes->getElements() !== [] ? array_values($responseDto->driveNodes->getElements())[$responseDto->driveNodes->count() - 1] : null;
        $responseDto->nextCursor = $lastNode !== null && $responseDto->driveNodes->count() >= $limit ? DriveNodesService::buildListCursor($lastNode) : null;
        return $responseDto;
    }

    /**
     * One file or folder with its path and versions.
     *
     * @throws NotFoundException
     */
    #[Get('/nodes/{driveNodeId}')]
    #[Summary('DriveNode Details')]
    public function getDriveNode(
        DriveNodeGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        return $this->buildDriveNodeResponse($driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * A new folder.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/folders')]
    #[Summary('DriveFolder Creation')]
    public function createDriveFolder(
        DriveFolderCreateRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $parentNode = $requestDto->parentNodeId !== null
            ? $driveNodesService->findNodeOfSpace($driveSpace, $requestDto->parentNodeId)
            : $driveNodesService->findRootNode($driveSpace);
        if ($parentNode === null || !$parentNode->isFolder()) {
            throw new NotFoundException('Parent folder not found.');
        }
        $folderNode = $driveNodesService->createFolder($driveSpace, $parentNode, trim($requestDto->name));
        return $this->buildDriveNodeResponse($folderNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * Reserves an upload: one presigned PUT, or the parts of a multipart upload. Send every header of a request
     * unchanged, then complete the upload.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('/spaces/{driveSpaceId}/uploads')]
    #[Summary('DriveUpload Reservation')]
    public function reserveDriveUpload(
        DriveUploadReserveRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveBlobsService $driveBlobsService
    ): DriveUploadReservationResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveUploadRequest = new DriveUploadRequest();
        $driveUploadRequest->sizeBytes = $requestDto->sizeBytes;
        $driveUploadRequest->contentType = $requestDto->contentType;
        $driveUploadRequest->contentMd5 = $requestDto->contentMd5;
        $driveUploadRequest->sha256 = $requestDto->sha256 !== null ? strtolower($requestDto->sha256) : null;
        $driveUploadRequest->idempotencyKey = $requestDto->idempotencyKey;
        $responseDto = new DriveUploadReservationResponseDto();
        $responseDto->reservation = $driveBlobsService->reserveUpload($driveSpace, $driveUploadRequest);
        return $responseDto;
    }

    /**
     * New presigned PUTs for parts of a running multipart upload (resume after the URLs expired).
     *
     * @throws NotFoundException
     */
    #[Post('/uploads/{driveBlobId}/parts')]
    #[Summary('DriveUpload Parts')]
    public function createDriveUploadParts(
        DriveUploadPartsRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveBlobsService $driveBlobsService
    ): DriveUploadReservationResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveBlob = $this->requireUploadingBlob($requestDto->driveBlobId, $driveSpacesService, $driveBlobsService);
        $responseDto = new DriveUploadReservationResponseDto();
        $responseDto->reservation = $driveBlobsService->buildReservation($driveBlob, $requestDto->partNumbers !== [] ? array_map('intval', $requestDto->partNumbers) : null);
        return $responseDto;
    }

    /**
     * Completes an upload into a file of a folder: a new file, or a new version on top of `expectedLatestVersionId`.
     * The answer shows the file pending until its scan released it; a newer version meanwhile is a conflict.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    #[Post('/uploads/{driveBlobId}/complete')]
    #[Summary('DriveUpload Completion')]
    public function completeDriveUpload(
        DriveUploadCompleteRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveBlobsService $driveBlobsService,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveBlob = $driveBlobsService->find($requestDto->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new NotFoundException('Upload not found.');
        }
        $driveSpace = $driveSpacesService->requireSpaceWithRight((int)$driveBlob->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $folderNode = $requestDto->folderNodeId !== null
            ? $driveNodesService->findNodeOfSpace($driveSpace, $requestDto->folderNodeId)
            : $driveNodesService->findRootNode($driveSpace);
        if ($folderNode === null || !$folderNode->isFolder()) {
            throw new NotFoundException('Target folder not found.');
        }
        $driveFileWriteResult = $driveVersionsService->writeUploadedFile(
            $driveBlob,
            $folderNode,
            trim($requestDto->name),
            $requestDto->comment,
            $requestDto->expectedLatestVersionId,
            (bool)$requestDto->replaceExisting
        );
        if ($driveFileWriteResult->isConflict || $driveFileWriteResult->driveNode === null) {
            $responseDto = $driveFileWriteResult->driveNode !== null
                ? $this->buildDriveNodeResponse($driveFileWriteResult->driveNode, $driveNodesService, $driveVersionsService)
                : new DriveNodeGetResponseDto();
            $responseDto->isConflict = true;
            $responseDto->conflictReason = $driveFileWriteResult->conflictReason;
            if ($driveFileWriteResult->driveNode === null) {
                throw new BadRequestException('Not written: ' . $driveFileWriteResult->conflictReason);
            }
            return $responseDto;
        }
        return $this->buildDriveNodeResponse($driveFileWriteResult->driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * A short-lived URL of a file's released content: DOWNLOAD (attachment) or INLINE (preview; HTML and SVG always
     * as attachment).
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     */
    #[Get('/nodes/{driveNodeId}/content')]
    #[Summary('DriveNode Content')]
    public function getDriveNodeContent(
        DriveContentGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService,
        DriveContentGrantsService $driveContentGrantsService
    ): DriveContentGrantResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $driveFileVersion = $requestDto->versionId !== null ? $driveVersionsService->find($requestDto->versionId, false) : null;
        if ($requestDto->versionId !== null && (!$driveFileVersion instanceof DriveFileVersion || $driveFileVersion->driveNodeId !== $driveNode->id)) {
            throw new NotFoundException('Version not found.');
        }
        $purpose = strtoupper((string)($requestDto->purpose ?? DriveContentGrant::PURPOSE_DOWNLOAD));
        $responseDto = new DriveContentGrantResponseDto();
        $responseDto->grant = $driveContentGrantsService->createContentGrant($driveNode, $purpose, $driveFileVersion);
        return $responseDto;
    }

    /**
     * File cards for references (`drive/<id>`): facts and short-lived open, download and thumbnail URLs; the ones the
     * viewer may not read are left out. The `DriveFiles` widget's renderer reads its cards here.
     */
    #[Post('/cards')]
    #[Summary('DriveFile Cards')]
    public function findDriveFileCards(DriveFileCardsRequestDto $requestDto, DriveContentGrantsService $driveContentGrantsService): DriveFileCardsResponseDto
    {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $responseDto = new DriveFileCardsResponseDto();
        $responseDto->cards = $driveContentGrantsService->findFileCards(array_map('strval', $requestDto->references));
        return $responseDto;
    }

    /**
     * Moves and/or renames a file or folder inside its space.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    #[Post('/nodes/{driveNodeId}/move')]
    #[Summary('DriveNode Move')]
    public function moveDriveNode(
        DriveNodeMoveRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $targetFolderNode = $requestDto->targetFolderNodeId !== null ? $this->requireDriveNode($requestDto->targetFolderNodeId, $driveNodesService) : null;
        $driveNode = $driveNodesService->moveNode($driveNode, $targetFolderNode, $requestDto->name !== null ? trim($requestDto->name) : null);
        return $this->buildDriveNodeResponse($driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * Replaces tags and/or the description.
     *
     * @throws NotFoundException
     */
    #[Post('/nodes/{driveNodeId}/metadata')]
    #[Summary('DriveNode Metadata Update')]
    public function updateDriveNodeMetadata(
        DriveNodeMetadataRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $driveNode = $driveNodesService->updateMetadata($driveNode, $requestDto->tags !== null ? array_map('strval', $requestDto->tags) : null, $requestDto->description);
        return $this->buildDriveNodeResponse($driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * Moves a file or folder to the trash (restorable during the space's trash retention).
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    #[Post('/nodes/{driveNodeId}/trash')]
    #[Summary('DriveNode Trash')]
    public function trashDriveNode(
        DriveNodeGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveNode = $driveNodesService->trashNode($this->requireDriveNode($requestDto->driveNodeId, $driveNodesService));
        return $this->buildDriveNodeResponse($driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * Restores a trashed file or folder.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    #[Post('/nodes/{driveNodeId}/restore')]
    #[Summary('DriveNode Restore')]
    public function restoreDriveNode(
        DriveNodeGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveNode = $driveNodesService->restoreNode($this->requireDriveNode($requestDto->driveNodeId, $driveNodesService));
        return $this->buildDriveNodeResponse($driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * Files and folders of a space whose name contains the query.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Get('/spaces/{driveSpaceId}/search')]
    #[Summary('DriveNodes Search')]
    public function searchDriveNodes(
        DriveSearchGetRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveNodesGetResponseDto {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $responseDto = new DriveNodesGetResponseDto();
        $responseDto->driveNodes = $driveNodesService->searchNodesByName([(int)$driveSpace->id], $requestDto->query, $requestDto->limit ?? 50);
        return $responseDto;
    }

    /**
     * @throws NotFoundException
     */
    protected function requireDriveNode(int $driveNodeId, DriveNodesService $driveNodesService): DriveNode
    {
        $driveNode = $driveNodesService->find($driveNodeId, false);
        if (!$driveNode instanceof DriveNode) {
            throw new NotFoundException(sprintf('Drive node %d not found.', $driveNodeId));
        }
        return $driveNode;
    }

    /**
     * @throws NotFoundException
     */
    protected function requireUploadingBlob(int $driveBlobId, DriveSpacesService $driveSpacesService, DriveBlobsService $driveBlobsService): DriveBlob
    {
        $driveBlob = $driveBlobsService->find($driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new NotFoundException('Upload not found.');
        }
        $driveSpacesService->requireSpaceWithRight((int)$driveBlob->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        return $driveBlob;
    }

    protected function buildDriveNodeResponse(DriveNode $driveNode, DriveNodesService $driveNodesService, DriveVersionsService $driveVersionsService): DriveNodeGetResponseDto
    {
        $responseDto = new DriveNodeGetResponseDto();
        $responseDto->driveNode = $driveNode;
        $responseDto->path = $driveNodesService->buildPath($driveNode);
        $responseDto->driveFileVersions = $driveNode->isFile() ? $driveVersionsService->findVersionsOfNode($driveNode, 50) : null;
        return $responseDto;
    }
}
