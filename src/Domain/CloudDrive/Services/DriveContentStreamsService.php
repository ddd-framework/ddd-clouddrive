<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentToken;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveTransferSettings;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\ObjectStorage\ObjectStorageObject;
use DDD\Infrastructure\ObjectStorage\StreamingObjectStorage;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Presentation\Base\Dtos\ObjectStorageStreamResponseDto;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Serves the content URL of a grant: reads the signed token, checks again — as the account and audience of the
 * token — the read right, the variant rule and that the version is released (revoked rights or a blob blocked since
 * answer 403), records the access (VIEWED / DOWNLOADED, deduplicated) and streams the bytes from the store through the
 * backend ({@see ObjectStorageStreamResponseDto}: ranges, ETag, never buffered).
 */
class DriveContentStreamsService extends Service
{
    /**
     * @throws ForbiddenException an invalid or expired token, revoked rights, a blob no longer released
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function createContentStreamResponse(string $contentToken, Request $request): ObjectStorageStreamResponseDto
    {
        $driveContentToken = $this->getDriveContentTokensService()->readToken($contentToken);
        if ($driveContentToken === null) {
            throw new ForbiddenException('The content link is invalid or has expired; open the file again.');
        }
        $driveContentDelivery = $this->resolveDeliveryOfToken($driveContentToken, $request);
        $driveTransferSettings = DriveTransferSettings::fromConfig();
        $objectStorageObject = null;
        if ($driveContentDelivery->sizeBytes !== null && $driveContentDelivery->sha256 !== null) {
            // a released blob never changes: its size and hash are the store's HEAD without a request
            $objectStorageObject = new ObjectStorageObject();
            $objectStorageObject->key = $driveContentDelivery->storageKey;
            $objectStorageObject->size = $driveContentDelivery->sizeBytes;
            $objectStorageObject->etag = $driveContentDelivery->sha256 . '-' . strtolower($driveContentDelivery->purpose);
        }
        return ObjectStorageStreamResponseDto::fromObjectStorage(
            $this->getStreamingObjectStorage(),
            $driveContentDelivery->storageKey,
            $request,
            $driveContentDelivery->contentType,
            $driveContentDelivery->fileName,
            false,
            $driveContentDelivery->inlineContentType,
            $objectStorageObject,
            max(0, $driveContentToken->expiresAt - time()),
            $driveTransferSettings->streamChunkBytes
        );
    }

    /**
     * The checks of the grant, again, in the context of the token (its account and audience), and the access record.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    protected function resolveDeliveryOfToken(DriveContentToken $driveContentToken, Request $request): DriveContentDelivery
    {
        $previousAccount = AuthService::instance()->getAccount();
        $driveSpacesService = $this->getDriveSpacesService();
        $previousAudience = $driveSpacesService->getCurrentAudience();
        try {
            $this->enterAccountOfToken($driveContentToken);
            if ($driveContentToken->audience !== '') {
                $driveSpacesService->enableAudience($driveContentToken->audience);
            }
            $driveNode = $this->getDriveNodesService()->find($driveContentToken->driveNodeId, false);
            $driveFileVersion = $this->getDriveVersionsService()->find($driveContentToken->driveFileVersionId, false);
            if (!$driveNode instanceof DriveNode || !$driveFileVersion instanceof DriveFileVersion) {
                throw new NotFoundException('The file of this content link no longer exists.');
            }
            try {
                $driveContentDelivery = $this->getDriveContentGrantsService()->resolveContentDelivery($driveNode, $driveContentToken->purpose, $driveFileVersion);
            } catch (NotFoundException $notFoundException) {
                // the space is no longer readable for the account (read rights answer not found): the link is revoked
                throw new ForbiddenException('This content link is no longer allowed: ' . $notFoundException->getMessage());
            }
            if (!$request->isMethod('HEAD')) {
                $this->recordAccess($driveNode, $driveContentDelivery, $driveContentToken, $driveSpacesService->getCurrentAudience());
            }
            return $driveContentDelivery;
        } finally {
            $driveSpacesService->restoreAudience($previousAudience);
            AuthService::instance()->setAccount($previousAccount);
        }
    }

    /** Makes the account of the token the current account (none for a token made without one) */
    protected function enterAccountOfToken(DriveContentToken $driveContentToken): void
    {
        $account = null;
        if ($driveContentToken->accountId !== null) {
            /** @var AccountsService $accountsService */
            $accountsService = DDDService::instance()->getService(AccountsService::class);
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $account = $accountsService->find($driveContentToken->accountId, false);
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
            if ($account === null) {
                throw new ForbiddenException('The account of this content link no longer exists.');
            }
        }
        AuthService::instance()->setAccount($account);
    }

    /** VIEWED / DOWNLOADED, once per reader, file, version and kind within `accessEventDedupSeconds`; never fails the fetch */
    protected function recordAccess(DriveNode $driveNode, DriveContentDelivery $driveContentDelivery, DriveContentToken $driveContentToken, string $audience): void
    {
        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = $driveContentDelivery->purpose;
        $driveContentGrant->driveNodeId = $driveContentDelivery->driveNodeId;
        $driveContentGrant->driveFileVersionId = $driveContentDelivery->driveFileVersionId;
        try {
            $this->getDriveActivityEventsService()->recordContentAccess($driveNode, $driveContentGrant, $audience, $driveContentToken->onBehalfOfAccountId);
        } catch (Throwable) {
            // the access record is a log: a failing record never blocks the download
        }
    }

    /**
     * @throws InternalErrorException
     */
    protected function getStreamingObjectStorage(): StreamingObjectStorage
    {
        return $this->getDriveBlobsService()->getStreamingObjectStorage();
    }

    protected function getDriveContentTokensService(): DriveContentTokensService
    {
        /** @var DriveContentTokensService $driveContentTokensService */
        $driveContentTokensService = DDDService::instance()->getService(DriveContentTokensService::class);
        return $driveContentTokensService;
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        /** @var DriveContentGrantsService $driveContentGrantsService */
        $driveContentGrantsService = DDDService::instance()->getService(DriveContentGrantsService::class);
        return $driveContentGrantsService;
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

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }

    protected function getDriveActivityEventsService(): DriveActivityEventsService
    {
        /** @var DriveActivityEventsService $driveActivityEventsService */
        $driveActivityEventsService = DDDService::instance()->getService(DriveActivityEventsService::class);
        return $driveActivityEventsService;
    }
}
