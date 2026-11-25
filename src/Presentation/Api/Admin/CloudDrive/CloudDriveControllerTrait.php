<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListQuery;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\AI\DriveAgentConversationNotesService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveSpaceTransfersService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveChangeEventsGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveChildrenGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveContentGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveContentGrantResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFileCardsRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFileCardsResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFileVersionsGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFolderCreateRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFoldersGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveFoldersGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveLocationResolveRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveLocationResolveResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeEventsGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeMetadataRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodeMoveRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodesBulkRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodesBulkResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodesGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveNodesTransferRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveRenditionGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveRenditionResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSearchGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpaceGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpaceGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpacesGetResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpaceTransferPreviewResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveSpaceTransferResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveTrashGetRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadBytesResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadCompleteRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadContentPutRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadPartPutRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadPartsRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadReservationResponseDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveUploadReserveRequestDto;
use DDD\Presentation\Api\Admin\CloudDrive\Dtos\DriveVersionRestoreRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Put;
use Symfony\Component\HttpFoundation\Request;

/**
 * The drive screen's backend: spaces, folders, files, uploads (reserve, parts, complete), content URLs, PDF renditions
 * of Office files, file cards, move, tags, trash and restore, name search, filtered lists, the folder tree, the trash list, versions and their
 * restore, resolving a pasted location (path bar, deep link), a node's history, bulk move / trash / restore, purge and emptying the trash. A host mounts it with one controller that sets the path prefix, the tag
 * and the access (`#[Route('/common/cloudDrive')] class AdminCloudDriveController extends AdminController { use
 * CloudDriveControllerTrait; }`). Every read and write runs under the host's space rights; the access hook
 * {@see throwForbiddenUnlessCloudDriveAccessAllowed()} runs first in every action. A second controller with its own
 * path, access hook and {@see getDriveAudience()} returning SHARED mounts the same actions for the shared audience
 * (partners): reads, writes where the space is not write-locked for them, no manage actions.
 */
trait CloudDriveControllerTrait
{
    /** An application whose rule is an account predicate, not a role, throws ForbiddenException here */
    protected function throwForbiddenUnlessCloudDriveAccessAllowed(): void
    {
    }

    /**
     * The audience the callers of this controller act as: INTERNAL (staff, the default) or SHARED (a partner mount:
     * reads, writes only where the space is not write-locked for its audience, never manages — purge, empty trash and
     * the other staff-only actions answer 403).
     */
    protected function getDriveAudience(): string
    {
        return DriveSpace::AUDIENCE_INTERNAL;
    }

    /**
     * Runs first in every action: the access hook, then the controller's audience for the rest of the request.
     *
     * @throws ForbiddenException
     * @throws BadRequestException
     */
    protected function prepareCloudDriveAction(): void
    {
        $this->throwForbiddenUnlessCloudDriveAccessAllowed();
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        $driveSpacesService->enableAudience($this->getDriveAudience());
    }

    /**
     * The drive spaces the caller may read.
     */
    #[Get('/spaces/list')]
    #[Summary('DriveSpaces List')]
    public function listDriveSpaces(DriveSpacesService $driveSpacesService): DriveSpacesGetResponseDto
    {
        $this->prepareCloudDriveAction();
        $responseDto = new DriveSpacesGetResponseDto();
        $driveSpaces = $driveSpacesService->findAll(null, 500, false);
        $responseDto->driveSpaces = $driveSpaces instanceof DriveSpaces ? $driveSpaces : new DriveSpaces();
        $responseDto->items = $driveSpacesService->buildSpaceListItems($responseDto->driveSpaces);
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
        $this->prepareCloudDriveAction();
        $responseDto = new DriveSpaceGetResponseDto();
        $responseDto->driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $responseDto->item = $driveSpacesService->buildSpaceListItem(
            $responseDto->driveSpace,
            $driveSpacesService->hasRightOnSpace($responseDto->driveSpace, DBDriveSpace::RIGHT_WRITE),
            $driveSpacesService->hasRightOnSpace($responseDto->driveSpace, DBDriveSpace::RIGHT_MANAGE)
        );
        return $responseDto;
    }

    /**
     * One page of a folder (the root when no folder is given); with filters, another order or `recursive` one page
     * of the matching nodes of the folder or the whole space.
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
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $limit = max(1, min(DriveNodesService::MAX_LIST_LIMIT, $requestDto->limit ?? 100));
        $driveNodeListQuery = $this->buildDriveNodeListQuery($requestDto, $limit);
        $driveNodeListQuery->folderNodeId = $requestDto->folderNodeId;
        $driveNodeListQuery->isRecursive = (bool)$requestDto->recursive;
        $driveNodeListQuery->nameQuery = $requestDto->query !== null && trim($requestDto->query) !== '' ? trim($requestDto->query) : null;
        $driveNodeListQuery->includeSystem = (bool)$requestDto->includeSystem || $driveSpacesService->hasRightOnSpace($driveSpace, DBDriveSpace::RIGHT_MANAGE);
        return $this->buildDriveNodesListResponse($driveSpace, $driveNodeListQuery, $requestDto->cursor, $driveNodesService);
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
        $this->prepareCloudDriveAction();
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
        $this->prepareCloudDriveAction();
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
     * Reserves an upload (quota, type rules): the bytes go to the backend, in one request up to
     * `transfer.singlePartMaxBytes` (`PUT …/uploads/{driveBlobId}/content`), above in parts of `partSizeBytes`
     * (`PUT …/uploads/{driveBlobId}/parts/{partNumber}`); then complete the upload.
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
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveUploadRequest = new DriveUploadRequest();
        $driveUploadRequest->sizeBytes = $requestDto->sizeBytes;
        $driveUploadRequest->contentType = $requestDto->contentType;
        $driveUploadRequest->contentMd5 = $requestDto->contentMd5;
        $driveUploadRequest->sha256 = $requestDto->sha256 !== null ? strtolower($requestDto->sha256) : null;
        $driveUploadRequest->idempotencyKey = $requestDto->idempotencyKey;
        $driveUploadRequest->fileName = $requestDto->fileName !== null && trim($requestDto->fileName) !== '' ? trim($requestDto->fileName) : null;
        $responseDto = new DriveUploadReservationResponseDto();
        $responseDto->reservation = $driveBlobsService->reserveUpload($driveSpace, $driveUploadRequest);
        return $responseDto;
    }

    /**
     * The state of a running upload, to resume it: the parts the store holds already (`uploadedPartNumbers`).
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
        $this->prepareCloudDriveAction();
        $driveBlob = $this->requireUploadingBlob($requestDto->driveBlobId, $driveSpacesService, $driveBlobsService);
        $responseDto = new DriveUploadReservationResponseDto();
        $responseDto->reservation = $driveBlobsService->buildReservation($driveBlob);
        return $responseDto;
    }

    /**
     * The whole content of a single-part upload, streamed from the request body into the store (never held in
     * memory). Send `Content-Type: application/octet-stream` and `Content-Length`; a second PUT replaces the first.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    #[Put('/uploads/{driveBlobId}/content')]
    #[Summary('DriveUpload Content')]
    public function putDriveUploadContent(
        DriveUploadContentPutRequestDto $requestDto,
        Request $request,
        DriveSpacesService $driveSpacesService,
        DriveBlobsService $driveBlobsService
    ): DriveUploadBytesResponseDto {
        $this->prepareCloudDriveAction();
        $driveBlob = $this->requireUploadingBlob($requestDto->driveBlobId, $driveSpacesService, $driveBlobsService);
        $contentLength = $this->requireContentLength($request);
        $driveBlobsService->writeUploadContent($driveBlob, $request->getContent(true), $contentLength);
        $responseDto = new DriveUploadBytesResponseDto();
        $responseDto->driveBlobId = (int)$driveBlob->id;
        $responseDto->sizeBytes = $contentLength;
        return $responseDto;
    }

    /**
     * One part of a multipart upload, streamed from the request body into the store's part (one part per request,
     * never held in memory). Send `Content-Type: application/octet-stream`, `Content-Length` and optionally
     * `Content-MD5`; after a stall send the same part again, then complete the upload.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    #[Put('/uploads/{driveBlobId}/parts/{partNumber}', requirements: ['partNumber' => '\d+'])]
    #[Summary('DriveUpload Part')]
    public function putDriveUploadPart(
        DriveUploadPartPutRequestDto $requestDto,
        Request $request,
        DriveSpacesService $driveSpacesService,
        DriveBlobsService $driveBlobsService
    ): DriveUploadBytesResponseDto {
        $this->prepareCloudDriveAction();
        $driveBlob = $this->requireUploadingBlob($requestDto->driveBlobId, $driveSpacesService, $driveBlobsService);
        $contentLength = $this->requireContentLength($request);
        $uploadedPart = $driveBlobsService->writeUploadPart(
            $driveBlob,
            $requestDto->partNumber,
            $request->getContent(true),
            $contentLength,
            $request->headers->get('Content-MD5')
        );
        $responseDto = new DriveUploadBytesResponseDto();
        $responseDto->driveBlobId = (int)$driveBlob->id;
        $responseDto->partNumber = $uploadedPart->partNumber;
        $responseDto->sizeBytes = (int)($uploadedPart->size ?? $contentLength);
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
        $this->prepareCloudDriveAction();
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
        // the file is open beside an agent conversation: its next turn learns that a person wrote a version
        if ($requestDto->agentConversationId !== null && DriveAgentConversationNotesService::isAvailable()) {
            /** @var DriveAgentConversationNotesService $driveAgentConversationNotesService */
            $driveAgentConversationNotesService = DDDService::instance()->getService(DriveAgentConversationNotesService::class);
            $driveAgentConversationNotesService->noteVersionWrittenByPerson($requestDto->agentConversationId, $driveFileWriteResult);
        }
        return $this->buildDriveNodeResponse($driveFileWriteResult->driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * A short-lived URL of the backend (signed token, no `Authorization` header needed: `<img>`, `<video>`, a link)
     * for a file's released content: DOWNLOAD (the original as an attachment, recorded as DOWNLOADED),
     * INLINE (a preview of JPEG, PNG, GIF, WebP — an SVG only through the host's sanitiser — else an attachment;
     * recorded as VIEWED), THUMBNAIL (a list's picture, never recorded) or RENDITION (the stored PDF rendition of an
     * Office file, recorded as VIEWED; not found while there is none — {@see getDriveNodeRendition()} is the route
     * that makes one). The same reader, file, version and kind within `accessEventDedupSeconds` is recorded once.
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
        $this->prepareCloudDriveAction();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $driveFileVersion = $requestDto->versionId !== null ? $driveVersionsService->find($requestDto->versionId, false) : null;
        if ($requestDto->versionId !== null && (!$driveFileVersion instanceof DriveFileVersion || $driveFileVersion->driveNodeId !== $driveNode->id)) {
            throw new NotFoundException('Version not found.');
        }
        $purpose = strtoupper((string)($requestDto->purpose ?? DriveContentGrant::PURPOSE_DOWNLOAD));
        $responseDto = new DriveContentGrantResponseDto();
        $responseDto->grant = $driveContentGrantsService->createContentGrant($driveNode, $purpose, $driveFileVersion);
        // the access (VIEWED / DOWNLOADED) is recorded when the URL is fetched (DriveContentStreamsService)
        return $responseDto;
    }

    /**
     * The PDF rendition of an Office file (docx, xlsx, pptx, odt, ods, odp; `CloudDrive.settings.officeRenditions`),
     * under the checks of every content read: READY with a short-lived inline URL of the PDF, PENDING while a worker
     * converts it (the first call starts the conversion; ask again every few seconds) or FAILED with a reason in
     * plain words. A list item and a file card say with `hasPdfRendition` whether the route is worth calling; for
     * any other file it answers 400.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Get('/nodes/{driveNodeId}/rendition')]
    #[Summary('DriveNode Rendition')]
    public function getDriveNodeRendition(
        DriveRenditionGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService,
        DriveOfficeRenditionsService $driveOfficeRenditionsService
    ): DriveRenditionResponseDto {
        $this->prepareCloudDriveAction();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $driveFileVersion = $requestDto->versionId !== null ? $driveVersionsService->find($requestDto->versionId, false) : null;
        if ($requestDto->versionId !== null && (!$driveFileVersion instanceof DriveFileVersion || $driveFileVersion->driveNodeId !== $driveNode->id)) {
            throw new NotFoundException('Version not found.');
        }
        $driveOfficeRendition = $driveOfficeRenditionsService->requestRendition($driveNode, $driveFileVersion);
        $responseDto = new DriveRenditionResponseDto();
        $responseDto->state = $driveOfficeRendition->state;
        $responseDto->reason = $driveOfficeRendition->reason;
        $responseDto->grant = $driveOfficeRendition->grant;
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
        $this->prepareCloudDriveAction();
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
        $this->prepareCloudDriveAction();
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
        $this->prepareCloudDriveAction();
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
        $this->prepareCloudDriveAction();
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
        $this->prepareCloudDriveAction();
        $driveNode = $driveNodesService->restoreNode($this->requireDriveNode($requestDto->driveNodeId, $driveNodesService));
        return $this->buildDriveNodeResponse($driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * Files and folders of a space whose name contains the query; with filters or an order, one page of them.
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
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $driveNodeListQuery = $this->buildDriveNodeListQuery($requestDto, max(1, min(200, $requestDto->limit ?? 50)));
        if ($driveNodeListQuery->isPlainFolderListing()) {
            $responseDto = new DriveNodesGetResponseDto();
            $responseDto->driveNodes = $driveNodesService->searchNodesByName([(int)$driveSpace->id], $requestDto->query, $requestDto->limit ?? 50);
            $responseDto->items = $driveNodesService->buildListItems($responseDto->driveNodes, $driveSpace);
            return $responseDto;
        }
        $driveNodeListQuery->isRecursive = true;
        $driveNodeListQuery->nameQuery = $requestDto->query;
        return $this->buildDriveNodesListResponse($driveSpace, $driveNodeListQuery, $requestDto->cursor, $driveNodesService);
    }

    /**
     * One page of a space's trash: what was trashed on its own (a folder holds what it took along), most recently
     * trashed first, with who trashed it and when the maintenance deletes it for good.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Get('/spaces/{driveSpaceId}/trash')]
    #[Summary('DriveNodes Trash List')]
    public function listDriveTrash(
        DriveTrashGetRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveNodesGetResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $limit = max(1, min(DriveNodesService::MAX_LIST_LIMIT, $requestDto->limit ?? 100));
        $offset = DriveNodesService::parseOffsetCursor($requestDto->cursor) ?? 0;
        $responseDto = new DriveNodesGetResponseDto();
        $responseDto->driveNodes = $driveNodesService->findTrashedNodes($driveSpace, $offset, $limit);
        $responseDto->items = $driveNodesService->buildListItems($responseDto->driveNodes, $driveSpace);
        $responseDto->nextCursor = $responseDto->driveNodes->count() >= $limit ? DriveNodesService::buildOffsetCursor($offset + $limit) : null;
        return $responseDto;
    }

    /**
     * Every live folder of a space, flat (the root included): a folder tree in one call.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Get('/spaces/{driveSpaceId}/folders')]
    #[Summary('DriveFolders List')]
    public function listDriveFolders(
        DriveFoldersGetRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveFoldersGetResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $includeSystem = (bool)$requestDto->includeSystem || $driveSpacesService->hasRightOnSpace($driveSpace, DBDriveSpace::RIGHT_MANAGE);
        $folderNodes = $driveNodesService->findFoldersOfSpace($driveSpace, DriveNodesService::MAX_FOLDER_TREE_SIZE, $includeSystem);
        $responseDto = new DriveFoldersGetResponseDto();
        $responseDto->isTruncated = $folderNodes->count() > DriveNodesService::MAX_FOLDER_TREE_SIZE;
        $keptFolderNodes = new DriveNodes();
        foreach (array_slice($folderNodes->getElements(), 0, DriveNodesService::MAX_FOLDER_TREE_SIZE) as $folderNode) {
            $keptFolderNodes->add($folderNode);
        }
        $responseDto->items = $driveNodesService->buildListItems($keptFolderNodes, $driveSpace);
        return $responseDto;
    }

    /**
     * Where a pasted location points (a path bar, a deep link, a path the agent wrote): a path in this space,
     * `drive/<id>`, `drivespaces/<mount>/…` or `/workspace/drive/…`. A mount path or a reference may answer another
     * space the caller can read. Not found: 404 with `missingSegment`, `path` and the deepest existing folder
     * (`deepestFolder`).
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     */
    #[Get('/spaces/{driveSpaceId}/resolve')]
    #[Summary('DriveLocation Resolve')]
    public function resolveDriveLocation(
        DriveLocationResolveRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveLocationResolveResponseDto {
        $this->prepareCloudDriveAction();
        $requestedDriveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId);
        $driveLocationResolution = $driveNodesService->resolveLocation($requestedDriveSpace, $requestDto->path);
        $driveSpace = $driveLocationResolution->driveSpace ?? $requestedDriveSpace;
        $driveNode = $driveLocationResolution->driveNode;
        $responseDto = new DriveLocationResolveResponseDto();
        $responseDto->driveSpaceId = (int)$driveSpace->id;
        $responseDto->path = $driveLocationResolution->path;
        if ($driveNode !== null) {
            $responseDto->item = $driveNodesService->buildListItemOfNode($driveNode, $driveSpace);
            $responseDto->ancestors = $driveNodesService->buildListItems($driveNodesService->findAncestors($driveNode), $driveSpace);
            $responseDto->adminLink = $driveNodesService->buildAdminLink($driveNode, $driveLocationResolution->path);
        }
        return $responseDto;
    }

    /**
     * The version history of a file, newest first: scan status, author, comment, which one the file shows, which can
     * be restored.
     *
     * @throws NotFoundException
     */
    #[Get('/nodes/{driveNodeId}/versions')]
    #[Summary('DriveFileVersions List')]
    public function listDriveFileVersions(
        DriveNodeGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveFileVersionsGetResponseDto {
        $this->prepareCloudDriveAction();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $responseDto = new DriveFileVersionsGetResponseDto();
        $responseDto->items = $driveVersionsService->buildVersionListItems($driveNode, $driveVersionsService->findVersionsOfNode($driveNode, 500));
        return $responseDto;
    }

    /**
     * Makes an older version the newest again (its released content; no bytes are copied). A version the scan
     * blocked or that waits for its scan cannot be restored.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    #[Post('/nodes/{driveNodeId}/versions/{driveFileVersionId}/restore')]
    #[Summary('DriveFileVersion Restore')]
    public function restoreDriveFileVersion(
        DriveVersionRestoreRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveVersionsService $driveVersionsService
    ): DriveNodeGetResponseDto {
        $this->prepareCloudDriveAction();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $driveFileVersion = $driveVersionsService->find($requestDto->driveFileVersionId, false);
        if (!$driveFileVersion instanceof DriveFileVersion || (int)$driveFileVersion->driveNodeId !== (int)$driveNode->id) {
            throw new NotFoundException('Version not found.');
        }
        $driveFileWriteResult = $driveVersionsService->restoreVersion($driveNode, $driveFileVersion);
        if ($driveFileWriteResult->isConflict) {
            throw new BadRequestException('Not restored: ' . $driveFileWriteResult->conflictReason);
        }
        return $this->buildDriveNodeResponse($driveFileWriteResult->driveNode ?? $driveNode, $driveNodesService, $driveVersionsService);
    }

    /**
     * The newest history entries of a file or folder, newest first: changes (created, versioned, released, blocked,
     * moved, renamed, trashed, restored …), accesses (viewed, downloaded, read by the agent) and the application's own
     * events; `eventTypes` (comma-separated) or `eventGroup` (`changes`, `accesses`) narrow it.
     *
     * @throws NotFoundException
     */
    #[Get('/nodes/{driveNodeId}/events')]
    #[Summary('DriveNode History')]
    public function listDriveNodeEvents(
        DriveNodeEventsGetRequestDto $requestDto,
        DriveNodesService $driveNodesService,
        DriveChangesService $driveChangesService
    ): DriveChangeEventsGetResponseDto {
        $this->prepareCloudDriveAction();
        $driveNode = $this->requireDriveNode($requestDto->driveNodeId, $driveNodesService);
        $responseDto = new DriveChangeEventsGetResponseDto();
        $eventTypes = null;
        if ($requestDto->eventTypes !== null && trim($requestDto->eventTypes) !== '') {
            $eventTypes = array_values(array_filter(array_map('trim', explode(',', strtoupper($requestDto->eventTypes))), 'strlen'));
        }
        $eventGroup = $requestDto->eventGroup !== null && trim($requestDto->eventGroup) !== '' ? strtolower(trim($requestDto->eventGroup)) : null;
        $responseDto->items = $driveChangesService->buildNodeHistory(
            $driveNode,
            $requestDto->limit ?? DriveChangesService::DEFAULT_NODE_HISTORY_LIMIT,
            $eventTypes,
            $eventGroup
        );
        return $responseDto;
    }

    /**
     * Moves files and folders of a space into one of its folders (at most 200, one transaction); what cannot move
     * is skipped with its reason.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/nodes/move')]
    #[Summary('DriveNodes Bulk Move')]
    public function moveDriveNodes(
        DriveNodesBulkRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveNodesBulkResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $targetFolderNode = $requestDto->targetFolderNodeId !== null
            ? $driveNodesService->findNodeOfSpace($driveSpace, $requestDto->targetFolderNodeId)
            : $driveNodesService->findRootNode($driveSpace);
        if ($targetFolderNode === null) {
            throw new NotFoundException('Target folder not found.');
        }
        $responseDto = new DriveNodesBulkResponseDto();
        $responseDto->result = $driveNodesService->moveNodes($driveSpace, $requestDto->driveNodeIds, $targetFolderNode);
        return $responseDto;
    }

    /**
     * Moves files and folders of a space to its trash (at most 200, one transaction; folders with their subtree).
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/nodes/trash')]
    #[Summary('DriveNodes Bulk Trash')]
    public function trashDriveNodes(
        DriveNodesBulkRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveNodesBulkResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $responseDto = new DriveNodesBulkResponseDto();
        $responseDto->result = $driveNodesService->trashNodes($driveSpace, $requestDto->driveNodeIds);
        return $responseDto;
    }

    /**
     * Restores trashed files and folders of a space (at most 200, one transaction).
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/nodes/restore')]
    #[Summary('DriveNodes Bulk Restore')]
    public function restoreDriveNodes(
        DriveNodesBulkRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveNodesService $driveNodesService
    ): DriveNodesBulkResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $responseDto = new DriveNodesBulkResponseDto();
        $responseDto->result = $driveNodesService->restoreNodes($driveSpace, $requestDto->driveNodeIds);
        return $responseDto;
    }

    /**
     * Deletes trashed files and folders of a space for good, now (at most 200; needs the manage right): their
     * versions, their content in the store, and the quota they held.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/nodes/purge')]
    #[Summary('DriveNodes Purge')]
    public function purgeDriveNodes(
        DriveNodesBulkRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveMaintenanceService $driveMaintenanceService
    ): DriveNodesBulkResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_MANAGE);
        $responseDto = new DriveNodesBulkResponseDto();
        $responseDto->result = $driveMaintenanceService->purgeTrashedNodes($driveSpace, $requestDto->driveNodeIds);
        return $responseDto;
    }

    /**
     * Empties a space's trash for good (needs the manage right); at most 200 trashed nodes per call — `hasMore`
     * asks for another call.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/trash/empty')]
    #[Summary('DriveTrash Empty')]
    public function emptyDriveTrash(
        DriveSpaceGetRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveMaintenanceService $driveMaintenanceService
    ): DriveNodesBulkResponseDto {
        $this->prepareCloudDriveAction();
        $driveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_MANAGE);
        $responseDto = new DriveNodesBulkResponseDto();
        $responseDto->result = $driveMaintenanceService->emptyTrash($driveSpace);
        return $responseDto;
    }

    /**
     * What moving files and folders of a space into another space would do; nothing is written: counts, whether the
     * target's quota takes it, taken names, files the target refuses (type policy, not released), links of the
     * application that are lost.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    #[Post('/spaces/{driveSpaceId}/nodes/transfer/preview')]
    #[Summary('DriveNodes Transfer Preview')]
    public function previewDriveNodesTransfer(
        DriveNodesTransferRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveSpaceTransfersService $driveSpaceTransfersService
    ): DriveSpaceTransferPreviewResponseDto {
        $this->prepareCloudDriveAction();
        $responseDto = new DriveSpaceTransferPreviewResponseDto();
        $targetDriveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->targetDriveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $responseDto->preview = $driveSpaceTransfersService->previewTransfer(
            $this->findDriveTransferNodes($requestDto, $driveSpacesService),
            $targetDriveSpace,
            $this->findDriveTransferTargetFolder($requestDto, $targetDriveSpace)
        );
        return $responseDto;
    }

    /**
     * Moves files and folders of a space (at most 200, folders with their live content) into another space: a copy of
     * each file's current content in the store, the file written and scanned in the target, then the moved source
     * nodes purged. Needs the write right on both spaces. Runs as a job with `asyncTransfer` (`isQueued`).
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws InternalErrorException
     */
    #[Post('/spaces/{driveSpaceId}/nodes/transfer')]
    #[Summary('DriveNodes Transfer')]
    public function transferDriveNodes(
        DriveNodesTransferRequestDto $requestDto,
        DriveSpacesService $driveSpacesService,
        DriveSpaceTransfersService $driveSpaceTransfersService
    ): DriveSpaceTransferResponseDto {
        $this->prepareCloudDriveAction();
        $responseDto = new DriveSpaceTransferResponseDto();
        $targetDriveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->targetDriveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $responseDto->result = $driveSpaceTransfersService->transferNodes(
            $this->findDriveTransferNodes($requestDto, $driveSpacesService),
            $targetDriveSpace,
            $this->findDriveTransferTargetFolder($requestDto, $targetDriveSpace)
        );
        return $responseDto;
    }

    /**
     * The nodes of a transfer request, all of its source space.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    protected function findDriveTransferNodes(DriveNodesTransferRequestDto $requestDto, DriveSpacesService $driveSpacesService): DriveNodes
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        $sourceDriveSpace = $driveSpacesService->requireSpaceWithRight($requestDto->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveNodes = new DriveNodes();
        foreach (DriveNodesService::normalizeBulkNodeIds($requestDto->driveNodeIds) as $driveNodeId) {
            $driveNode = $driveNodesService->findNodeOfSpace($sourceDriveSpace, $driveNodeId);
            if ($driveNode === null) {
                throw new NotFoundException(sprintf('Drive node %d not found in space %d.', $driveNodeId, (int)$sourceDriveSpace->id));
            }
            $driveNodes->add($driveNode);
        }
        return $driveNodes;
    }

    /**
     * The target folder of a transfer request; null = the target's root.
     *
     * @throws NotFoundException
     */
    protected function findDriveTransferTargetFolder(DriveNodesTransferRequestDto $requestDto, DriveSpace $targetDriveSpace): ?DriveNode
    {
        if ($requestDto->targetFolderNodeId === null) {
            return null;
        }
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        $targetFolderNode = $driveNodesService->findNodeOfSpace($targetDriveSpace, $requestDto->targetFolderNodeId);
        if ($targetFolderNode === null) {
            throw new NotFoundException('Target folder not found in the target space.');
        }
        return $targetFolderNode;
    }

    /**
     * The list query of a request's filters (family, creation window, own uploads, order).
     *
     * @throws BadRequestException
     */
    protected function buildDriveNodeListQuery(DriveChildrenGetRequestDto|DriveSearchGetRequestDto $requestDto, int $limit): DriveNodeListQuery
    {
        $driveNodeListQuery = new DriveNodeListQuery();
        $driveNodeListQuery->limit = $limit;
        $driveNodeListQuery->family = $requestDto->family !== null && $requestDto->family !== '' ? strtoupper($requestDto->family) : null;
        $driveNodeListQuery->createdFrom = $this->parseDriveListDate($requestDto->createdFrom, 'createdFrom');
        $driveNodeListQuery->createdTo = $this->parseDriveListDate($requestDto->createdTo, 'createdTo');
        if ($requestDto->createdByMe) {
            $authAccount = AuthService::instance()->getAccount();
            $driveNodeListQuery->createdByAccountId = $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : 0;
        }
        if ($requestDto->orderBy !== null && $requestDto->orderBy !== '') {
            $driveNodeListQuery->orderBy = strtolower($requestDto->orderBy);
        }
        if ($requestDto->driveNodeIds !== null && trim($requestDto->driveNodeIds) !== '') {
            $driveNodeIds = [];
            foreach (explode(',', $requestDto->driveNodeIds) as $driveNodeIdText) {
                if (!ctype_digit(trim($driveNodeIdText))) {
                    throw new BadRequestException('driveNodeIds are node ids separated by commas.');
                }
                $driveNodeIds[] = (int)trim($driveNodeIdText);
            }
            if (count($driveNodeIds) > DriveNodeListQuery::MAX_NODE_IDS) {
                throw new BadRequestException(sprintf('At most %d driveNodeIds.', DriveNodeListQuery::MAX_NODE_IDS));
            }
            $driveNodeListQuery->driveNodeIds = array_values(array_unique($driveNodeIds));
        }
        if ($requestDto->orderDirection !== null && $requestDto->orderDirection !== '') {
            $orderDirection = strtolower($requestDto->orderDirection);
            if (!in_array($orderDirection, [DriveNodeListQuery::DIRECTION_ASC, DriveNodeListQuery::DIRECTION_DESC], true)) {
                throw new BadRequestException('orderDirection is asc or desc.');
            }
            $driveNodeListQuery->orderDirection = $orderDirection;
        }
        return $driveNodeListQuery;
    }

    /**
     * @throws BadRequestException
     */
    protected function parseDriveListDate(?string $dateString, string $parameterName): ?Date
    {
        if ($dateString === null || trim($dateString) === '') {
            return null;
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateString)) === 1 ? Date::fromString(trim($dateString)) : false;
        if (!$date instanceof Date) {
            throw new BadRequestException(sprintf('%s is a day as Y-m-d.', $parameterName));
        }
        return $date;
    }

    /**
     * @throws BadRequestException
     * @throws NotFoundException
     */
    protected function buildDriveNodesListResponse(DriveSpace $driveSpace, DriveNodeListQuery $driveNodeListQuery, ?string $cursor, DriveNodesService $driveNodesService): DriveNodesGetResponseDto
    {
        $responseDto = new DriveNodesGetResponseDto();
        $responseDto->driveNodes = $driveNodesService->findNodesByListQuery($driveSpace, $driveNodeListQuery, $cursor);
        $responseDto->items = $driveNodesService->buildListItems($responseDto->driveNodes, $driveSpace);
        $responseDto->approximateTotalCount = $driveNodesService->findApproximateTotalCount($driveSpace, $driveNodeListQuery);
        $pageSize = $responseDto->driveNodes->count();
        if ($pageSize < $driveNodeListQuery->limit) {
            return $responseDto;
        }
        if ($driveNodeListQuery->isPlainFolderListing()) {
            $lastNode = array_values($responseDto->driveNodes->getElements())[$pageSize - 1];
            $responseDto->nextCursor = DriveNodesService::buildListCursor($lastNode);
        } else {
            $responseDto->nextCursor = DriveNodesService::buildOffsetCursor((DriveNodesService::parseOffsetCursor($cursor) ?? $driveNodeListQuery->offset) + $pageSize);
        }
        return $responseDto;
    }

    /**
     * The length of a streamed request body (nginx sends it to PHP-FPM after buffering the body)
     *
     * @throws BadRequestException
     */
    protected function requireContentLength(Request $request): int
    {
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength === null || !ctype_digit((string)$contentLength)) {
            throw new BadRequestException('Send the bytes with a Content-Length header.');
        }
        return (int)$contentLength;
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
        $responseDto->ancestors = $driveNodesService->findAncestors($driveNode);
        $responseDto->driveFileVersions = $driveNode->isFile() ? $driveVersionsService->findVersionsOfNode($driveNode, 50) : null;
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        $driveSpace = $driveSpacesService->findSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_READ);
        if ($driveSpace !== null) {
            $driveNodes = new DriveNodes();
            $driveNodes->add($driveNode);
            $responseDto->item = $driveNodesService->buildListItems($driveNodes, $driveSpace)->first();
        }
        return $responseDto;
    }
}
