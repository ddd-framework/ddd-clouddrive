<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentToken;
use DDD\Domain\CloudDrive\Entities\Content\DriveFileCard;
use DDD\Domain\CloudDrive\Entities\Content\DriveFileCards;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveTransferSettings;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;

/**
 * The one gate for file content: a short-lived URL per version and purpose, under the reader's rights. People read
 * only released content: DOWNLOAD as attachment; INLINE for JPEG, PNG, GIF, WebP and PDF in their own type and for
 * plain texts (Markdown, CSV, JSON, …) as `text/plain; charset=utf-8` — the type comes from the bytes at ingest;
 * anything else (HTML, SVG, XML, JavaScript, Office, archives) is an attachment even when INLINE is asked
 * ({@see resolveInlineContentType()}). Every byte is streamed by the backend: the URL is one of the host's content
 * route with a signed token ({@see DriveContentStreamsService}), with `nosniff`, a server-chosen type and the sandbox
 * CSP (not on a PDF). HTML and SVG never run in a browser (an SVG only as its sanitised copy); a host that wants
 * inline PDFs and texts off the application's origin points `transfer.contentUrlPrefix` at another host.
 * A working copy
 * gets its own unreleased content back (WORKSPACE_RESTORE): staged blobs of its entries and pending versions of its
 * space, never downloadable by people.
 */
class DriveContentGrantsService extends Service
{
    public const string SVG_CONTENT_TYPE = 'image/svg+xml';

    /** @var string[] Types never served inline (kept for callers; inline is decided by an allow-list now) */
    public const array NEVER_INLINE_CONTENT_TYPES = UntrustedContentHeaders::ACTIVE_CONTENT_TYPES;

    public const string PDF_CONTENT_TYPE = 'application/pdf';

    /** The type a text is served inline as: never its own (a `text/markdown` or `text/csv` some browsers would download or render) */
    public const string TEXT_INLINE_CONTENT_TYPE = 'text/plain; charset=utf-8';

    /** @var string[] Plain texts a reader may open inline, served as {@see TEXT_INLINE_CONTENT_TYPE} */
    public const array TEXT_INLINE_SOURCE_CONTENT_TYPES = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
        'text/tab-separated-values',
        'text/x-log',
        'text/yaml',
        'text/x-yaml',
        'application/yaml',
        'application/x-yaml',
        'application/json',
        'application/x-ndjson',
    ];

    /**
     * The content URL of a file's released version (the current one when no version is given): a URL of the backend
     * with a signed token ({@see DriveContentTokensService}), valid for `CloudDrive.settings.transfer.tokenLifetimeSeconds`;
     * the rights and the scan state are checked again on every fetch ({@see DriveContentStreamsService}).
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function createContentGrant(DriveNode $driveNode, string $purpose = DriveContentGrant::PURPOSE_DOWNLOAD, ?DriveFileVersion $driveFileVersion = null): DriveContentGrant
    {
        $driveContentDelivery = $this->resolveContentDelivery($driveNode, $purpose, $driveFileVersion);
        $driveContentToken = $this->buildContentToken($driveContentDelivery->driveNodeId, $driveContentDelivery->driveFileVersionId, $driveContentDelivery->purpose);

        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = $driveContentDelivery->purpose;
        $driveContentGrant->url = $this->buildContentUrlOfToken($driveContentToken);
        $driveContentGrant->expiresAt = DateTime::fromTimestamp($driveContentToken->expiresAt);
        $driveContentGrant->fileName = $driveContentDelivery->fileName;
        $driveContentGrant->sizeBytes = $driveContentDelivery->sizeBytes;
        $driveContentGrant->contentType = $driveContentDelivery->contentType;
        $driveContentGrant->sha256 = $driveContentDelivery->sha256;
        $driveContentGrant->isInline = $driveContentDelivery->isInline();
        $driveContentGrant->driveNodeId = $driveContentDelivery->driveNodeId;
        $driveContentGrant->driveFileVersionId = $driveContentDelivery->driveFileVersionId;
        return $driveContentGrant;
    }

    /**
     * The content URL of a version the caller checked already (a list's thumbnails: the read right of the space and
     * the released blob are known); the fetch checks everything again.
     *
     * @throws InternalErrorException
     */
    public function createContentUrlOfCheckedVersion(int $driveNodeId, int $driveFileVersionId, string $purpose): string
    {
        return $this->buildContentUrlOfToken($this->buildContentToken($driveNodeId, $driveFileVersionId, $purpose));
    }

    /** A token for the current account and audience, valid for `transfer.tokenLifetimeSeconds` */
    protected function buildContentToken(int $driveNodeId, int $driveFileVersionId, string $purpose): DriveContentToken
    {
        $driveContentToken = new DriveContentToken();
        $driveContentToken->driveNodeId = $driveNodeId;
        $driveContentToken->driveFileVersionId = $driveFileVersionId;
        $driveContentToken->purpose = $purpose;
        $driveContentToken->accountId = $this->findCurrentAccountId();
        $driveContentToken->audience = $this->getDriveSpacesService()->getCurrentAudience();
        $driveContentToken->onBehalfOfAccountId = $this->findOnBehalfOfAccountId();
        $driveContentToken->expiresAt = time() + DriveTransferSettings::fromConfig()->tokenLifetimeSeconds;
        return $driveContentToken;
    }

    /**
     * @throws InternalErrorException without a server secret
     */
    protected function buildContentUrlOfToken(DriveContentToken $driveContentToken): string
    {
        $driveContentTokensService = $this->getDriveContentTokensService();
        return $driveContentTokensService->buildContentUrl($driveContentTokensService->createToken($driveContentToken));
    }

    /**
     * Checks that the current caller may read a file's released version for `$purpose` and answers what to serve:
     * the read right on the space, the variant rule, a released blob (never one waiting for or refused by its scan),
     * the inline type (raster, PDF, plain text, an SVG only as its sanitised copy; everything else an attachment).
     * Called when a grant is made and again on every fetch of its URL.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function resolveContentDelivery(DriveNode $driveNode, string $purpose, ?DriveFileVersion $driveFileVersion = null): DriveContentDelivery
    {
        if (!in_array($purpose, DriveContentGrant::PEOPLE_PURPOSES, true)) {
            throw new BadRequestException('Content for people is granted for DOWNLOAD, INLINE or THUMBNAIL.');
        }
        $driveSpacesService = $this->getDriveSpacesService();
        $driveSpacesService->requireSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_READ);
        if (!$driveNode->isFile()) {
            throw new BadRequestException('Only a file has content.');
        }
        if (!$this->isVariantAllowed($driveNode, $purpose, $driveSpacesService->getCurrentAudience())) {
            throw new ForbiddenException($driveNode->isTrashed()
                ? 'The file is in the trash: only the staff can download it.'
                : sprintf('The %s variant of this file is not available.', strtolower($purpose)));
        }
        $driveFileVersion ??= $driveNode->currentVersionId !== null ? $this->getDriveVersionsService()->find((int)$driveNode->currentVersionId, false) : null;
        if (!$driveFileVersion instanceof DriveFileVersion || $driveFileVersion->driveNodeId !== $driveNode->id) {
            throw new NotFoundException('The file has no released version yet (it waits for its scan).');
        }
        $driveBlob = $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || !$driveBlob->isReadableByPeople()) {
            throw new ForbiddenException(match ($driveBlob?->state) {
                DriveBlob::STATE_BLOCKED => $driveBlob->findScanStatus() === DriveBlob::SCAN_STATUS_BLOCKED
                    ? 'The file was refused by the type rules of this space.'
                    : 'The file was blocked by the malware scan.',
                DriveBlob::STATE_SCAN_FAILED => 'The malware scan could not run yet; the file is scanned again shortly.',
                default => 'The version is not released yet (it waits for its scan).',
            });
        }
        // a blob stored without a telling type (older writes kept only the writer's claim) gets it from its bytes once
        // and keeps it: a PDF an agent wrote opens inline like an uploaded one
        if (DriveBlobsService::isContentTypeUnknown($driveBlob->contentType)) {
            $driveBlob = $this->getDriveBlobsService()->detectAndStoreContentTypeOfStoredBlob($driveBlob, (string)$driveNode->name);
        }
        $driveContentDelivery = new DriveContentDelivery();
        $driveContentDelivery->driveNodeId = (int)$driveNode->id;
        $driveContentDelivery->driveFileVersionId = (int)$driveFileVersion->id;
        $driveContentDelivery->purpose = $purpose;
        $driveContentDelivery->storageKey = (string)$driveBlob->storageKey;
        $driveContentDelivery->fileName = (string)$driveNode->name;
        $driveContentDelivery->sizeBytes = (int)$driveBlob->sizeBytes;
        $driveContentDelivery->contentType = $driveBlob->contentType;
        $driveContentDelivery->sha256 = $driveBlob->sha256;
        if ($purpose !== DriveContentGrant::PURPOSE_DOWNLOAD && UntrustedContentHeaders::normalizeContentType($driveBlob->contentType) === self::SVG_CONTENT_TYPE) {
            $sanitizedStorageKey = $this->ensureSanitizedSvgCopy($driveBlob);
            if ($sanitizedStorageKey !== null) {
                $driveContentDelivery->storageKey = $sanitizedStorageKey;
                $driveContentDelivery->sizeBytes = null;
                $driveContentDelivery->contentType = self::SVG_CONTENT_TYPE;
                $driveContentDelivery->inlineContentType = self::SVG_CONTENT_TYPE;
                return $driveContentDelivery;
            }
        }
        if ($purpose === DriveContentGrant::PURPOSE_THUMBNAIL && !UntrustedContentHeaders::isInlineContentType($driveBlob->contentType)) {
            throw new BadRequestException('Only a JPEG, PNG, GIF or WebP image has a thumbnail.');
        }
        $driveContentDelivery->inlineContentType = $purpose === DriveContentGrant::PURPOSE_DOWNLOAD ? null : $this->resolveInlineContentType($driveBlob->contentType);
        return $driveContentDelivery;
    }

    /**
     * The type a released blob of `$contentType` is served inline as, or null when it is only ever an attachment:
     * JPEG, PNG, GIF, WebP and PDF in their own type, the plain texts of {@see TEXT_INLINE_SOURCE_CONTENT_TYPES} as
     * `text/plain; charset=utf-8`. HTML, SVG (only through its sanitised copy), XML, JavaScript and every other type:
     * null. Override in the application (same-path subclass) to narrow the list; widening it to a type a browser runs
     * defeats the untrusted-content rule.
     */
    public function resolveInlineContentType(?string $contentType): ?string
    {
        $normalizedContentType = UntrustedContentHeaders::normalizeContentType($contentType);
        if (UntrustedContentHeaders::isInlineContentType($normalizedContentType)) {
            return $normalizedContentType;
        }
        if ($normalizedContentType === self::PDF_CONTENT_TYPE) {
            return self::PDF_CONTENT_TYPE;
        }
        if (in_array($normalizedContentType, self::TEXT_INLINE_SOURCE_CONTENT_TYPES, true)) {
            return self::TEXT_INLINE_CONTENT_TYPE;
        }
        return null;
    }

    /**
     * Whether `$audience` may read the `$variant` (DOWNLOAD, INLINE, THUMBNAIL) of a file. Default: every variant of a
     * live file; of a trashed file only the staff's download. Override in the application (same-path subclass) for
     * other rules (e.g. originals of a kind only for the staff).
     */
    public function isVariantAllowed(DriveNode $driveNode, string $variant, string $audience): bool
    {
        if ($driveNode->isTrashed()) {
            return $audience === DriveSpace::AUDIENCE_INTERNAL && $variant === DriveContentGrant::PURPOSE_DOWNLOAD;
        }
        return true;
    }

    /**
     * The key of an SVG's sanitised copy ({@see DriveSvgSanitizer}), made once per blob and kept next to it; null
     * without a sanitiser or when the SVG cannot be made safe (it stays an attachment).
     */
    protected function ensureSanitizedSvgCopy(DriveBlob $driveBlob): ?string
    {
        /** @var DriveSvgSanitizersService $driveSvgSanitizersService */
        $driveSvgSanitizersService = DDDService::instance()->getService(DriveSvgSanitizersService::class);
        if (!$driveSvgSanitizersService->hasSvgSanitizer() || (int)$driveBlob->sizeBytes > DriveSvgSanitizersService::MAX_SVG_BYTES) {
            return null;
        }
        $objectStorage = $this->getDriveBlobsService()->getObjectStorage();
        $sanitizedStorageKey = DriveBlobsService::buildSanitizedSvgStorageKey((string)$driveBlob->storageKey);
        if (!$objectStorage->objectExists($sanitizedStorageKey)) {
            $svgBytes = $objectStorage->getObjectContent((string)$driveBlob->storageKey);
            $sanitizedSvg = $svgBytes !== null ? $driveSvgSanitizersService->sanitizeSvg($svgBytes) : null;
            if ($sanitizedSvg === null) {
                return null;
            }
            $objectStorage->putObjectIfAbsent($sanitizedStorageKey, $sanitizedSvg, self::SVG_CONTENT_TYPE);
        }
        return $sanitizedStorageKey;
    }

    /**
     * The content URL of an unreleased blob for the working copy that holds it: a staged blob of one of its entries,
     * or the blob of a version in its space. Needs the write right on the space. A blob the scan found malware in
     * (BLOCKED) is never restored; one whose scan could not run (SCAN_FAILED) is, into the sandbox only — its bytes came
     * from there, and people still cannot read it.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws InternalErrorException
     */
    public function createWorkspaceRestoreGrant(DriveWorkingCopy $driveWorkingCopy, int $driveBlobId, string $fileName): DriveContentGrant
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveWorkingCopy->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveBlob = $this->getDriveBlobsService()->find($driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || $driveBlob->driveSpaceId !== $driveWorkingCopy->driveSpaceId
            || in_array($driveBlob->state, [DriveBlob::STATE_UPLOADING, DriveBlob::STATE_PURGING, DriveBlob::STATE_FAILED, DriveBlob::STATE_BLOCKED], true)
        ) {
            throw new NotFoundException(sprintf('Blob %d is not restorable into this working copy.', $driveBlobId));
        }
        return $this->buildGrant($driveBlob, $fileName, DriveContentGrant::PURPOSE_WORKSPACE_RESTORE, true);
    }

    /**
     * File cards for references (`drive/<id>`): facts and short-lived content URLs under the viewer's rights; a
     * reference the viewer may not read is left out.
     *
     * @param string[] $references
     */
    public function findFileCards(array $references): DriveFileCards
    {
        $driveFileCards = new DriveFileCards();
        foreach (array_slice(array_values(array_unique($references)), 0, 100) as $reference) {
            if (preg_match('#^drive/(\d+)$#', (string)$reference, $referenceMatches) !== 1) {
                continue;
            }
            $driveNode = $this->getDriveNodesService()->find((int)$referenceMatches[1], false);
            if (!$driveNode instanceof DriveNode || $driveNode->isTrashed()) {
                continue;
            }
            $driveFileCard = new DriveFileCard();
            $driveFileCard->reference = $driveNode->buildReference();
            $driveFileCard->driveSpaceId = (int)$driveNode->driveSpaceId;
            $driveFileCard->name = (string)$driveNode->name;
            $driveFileCard->path = $this->getDriveNodesService()->buildPath($driveNode);
            $driveFileCard->adminLink = $this->getDriveNodesService()->buildAdminLink($driveNode, $driveFileCard->path);
            $driveFileCard->isFolder = $driveNode->isFolder();
            $driveFileCard->sizeBytes = $driveNode->isFile() ? $driveNode->sizeBytes : null;
            $driveFileCard->contentType = $driveNode->contentType;
            $driveFileCard->modifiedAt = $driveNode->getLastEditTime();
            $driveFileCard->versionNumber = $driveNode->versionNumber;
            $driveFileCard->childCount = $driveNode->isFolder() ? (int)$driveNode->childCount : null;
            $driveFileCard->state = $driveNode->isContentUntrusted ? 'written by a third party' : null;
            if ($driveNode->isFile()) {
                try {
                    $downloadGrant = $this->createContentGrant($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
                    $inlineGrant = $this->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
                    $driveFileCard->downloadUrl = $downloadGrant->url;
                    $driveFileCard->inlineUrl = $inlineGrant->url;
                    $driveFileCard->urlsExpireAt = $downloadGrant->expiresAt;
                    if (DriveBlobsService::isContentTypeUnknown($driveFileCard->contentType) && !DriveBlobsService::isContentTypeUnknown($downloadGrant->contentType)) {
                        // the type the grant just read from the bytes (stored on the file for the next read)
                        $driveFileCard->contentType = $downloadGrant->contentType;
                    }
                    if ($inlineGrant->isInline && UntrustedContentHeaders::isInlineContentType($driveFileCard->contentType)) {
                        $driveFileCard->thumbnailUrl = $inlineGrant->url;
                    }
                } catch (ForbiddenException|NotFoundException $grantRefusal) {
                    $driveFileCard->state = str_contains($grantRefusal->getMessage(), 'blocked') ? 'blocked' : 'pending scan';
                }
            }
            $driveFileCards->add($driveFileCard);
        }
        return $driveFileCards;
    }

    /**
     * A presigned GET for the runner of a working copy (server side: the sandbox fetches the bytes from the store
     * itself); never handed to a browser.
     */
    protected function buildGrant(DriveBlob $driveBlob, string $fileName, string $purpose, bool $asAttachment): DriveContentGrant
    {
        $lifetimeSeconds = DriveTransferSettings::fromConfig()->tokenLifetimeSeconds;
        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = $purpose;
        $inlineContentType = $asAttachment ? null : $this->resolveInlineContentType($driveBlob->contentType);
        $driveContentGrant->url = $this->getDriveBlobsService()->createDownloadRequestUrl($driveBlob, $fileName, $asAttachment, $lifetimeSeconds, $inlineContentType);
        $driveContentGrant->expiresAt = DateTime::fromTimestamp(time() + $lifetimeSeconds);
        $driveContentGrant->fileName = $fileName;
        $driveContentGrant->sizeBytes = (int)$driveBlob->sizeBytes;
        $driveContentGrant->contentType = $driveBlob->contentType;
        $driveContentGrant->sha256 = $driveBlob->sha256;
        $driveContentGrant->isInline = $inlineContentType !== null;
        return $driveContentGrant;
    }

    /** The signed-in account a content token is made for; null without one (a command, a job) */
    protected function findCurrentAccountId(): ?int
    {
        $accountId = AuthService::instance()->getAccount()?->id;
        return $accountId !== null ? (int)$accountId : null;
    }

    /** The account an agent acts for (recorded with the access) */
    protected function findOnBehalfOfAccountId(): ?int
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        $onBehalfOfAccountId = $driveChangesService->findOnBehalfOfAccountIdOfCurrentCaller();
        return $onBehalfOfAccountId !== null ? (int)$onBehalfOfAccountId : null;
    }

    protected function getDriveContentTokensService(): DriveContentTokensService
    {
        /** @var DriveContentTokensService $driveContentTokensService */
        $driveContentTokensService = DDDService::instance()->getService(DriveContentTokensService::class);
        return $driveContentTokensService;
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
}
