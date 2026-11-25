<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI\Browser;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Browser\Files\BrowserFileSinkContext;
use DDD\Domain\AI\Entities\Browser\Files\BrowserFileToStore;
use DDD\Domain\AI\Entities\Browser\Files\BrowserStoredFile;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Browser\BrowserFileSink;
use DDD\Domain\AI\Services\Browser\BrowserFileSinksService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWriteResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveSvgSanitizersService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;

/**
 * The drive's file sink of the agent's browser (registers beside ddd-agents and ddd-agent-browser): files that leave
 * the browser (design-tokens.json, logo, stylesheets, screenshots, fonts) land in the own drive of the call's agent
 * space (the scratch drive of an eval case's workspace), side by side in `web/<domain>/` under the renderer's names —
 * the token file names its logo files relative to itself. A name is never changed: a name that does not fit its bytes
 * is refused.
 *
 * The intake is the one an operator's upload takes, nothing less:
 * 1. the type is read from the bytes (never the type a server claimed) and must be on the allow-list of the file's
 *    kind; anything executable or active is refused (programs, PostScript, PDF, Office and other containers, HTML,
 *    scripts); an SVG that carries scripts, event handlers, foreign objects or entities is refused, every other SVG
 *    is stored only sanitised (the host's DriveSvgSanitizer; without one SVGs are refused); a stylesheet with
 *    `javascript:`, `expression(` or a binding is refused; a token file must be valid JSON;
 * 2. the bytes go to the drive as a person's upload does (reserve, store, complete, write with origin UPLOAD) and the
 *    drive's ingest runs: hash, the type policy of the space and the malware scan;
 * 3. the file is marked as written by a third party (`DriveNode::$isContentUntrusted`) with its source in the version
 *    comment;
 * 4. for the files the agent uses at once (token file, logo) the sink waits within a budget per call until the scan
 *    decided; a blocked file is refused;
 * 5. the working copy of the call's sandbox is marked for a pull ({@see DriveWorkingCopiesService::requestPull()}), so
 *    the shell sees the file in the same turn.
 *
 * The write runs as the signed-in account of the tool call (its rights on the own drive). A host with its own sink
 * overrides this class at the same path; the first sink of the tag wins.
 */
class CloudDriveBrowserFileSinkService extends Service implements BrowserFileSink
{
    /** @var string A site folder as the browser names it (`BrowserFileToStore::$targetFolder`): `web/<domain>` */
    public const string TARGET_FOLDER_PATTERN = '/^web\/[a-z0-9][a-z0-9.-]{0,119}$/';

    /** @var string A file name as the renderer names it: letters, digits, dot, dash, underscore; no leading dot */
    public const string FILE_NAME_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,119}$/';

    public const string KIND_DESIGN_TOKENS = 'design-tokens';
    public const string KIND_LOGO = 'logo';
    public const string KIND_STYLESHEETS = 'stylesheets';
    public const string KIND_SCREENSHOTS = 'screenshots';
    public const string KIND_FONTS = 'fonts';

    /** @var string The token file's name: `web/<site>/design-tokens.json` */
    public const string DESIGN_TOKENS_FILE_NAME = 'design-tokens.json';

    public const string CONTENT_TYPE_SVG = 'image/svg+xml';
    public const string CONTENT_TYPE_CSS = 'text/css';
    public const string CONTENT_TYPE_JSON = 'application/json';

    /** @var string[][] The types (read from the bytes) each kind takes, by kind */
    public const array ALLOWED_CONTENT_TYPES_BY_KIND = [
        self::KIND_DESIGN_TOKENS => [self::CONTENT_TYPE_JSON],
        self::KIND_LOGO => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', self::CONTENT_TYPE_SVG],
        self::KIND_STYLESHEETS => [self::CONTENT_TYPE_CSS],
        self::KIND_SCREENSHOTS => ['image/png', 'image/jpeg', 'image/webp'],
        self::KIND_FONTS => ['font/woff2', 'font/woff', 'font/ttf', 'font/otf'],
    ];

    /** @var string[][] The file extensions each allowed type may carry, by type (the first is appended to a name without one) */
    public const array EXTENSIONS_BY_CONTENT_TYPE = [
        self::CONTENT_TYPE_JSON => ['json'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        self::CONTENT_TYPE_SVG => ['svg'],
        self::CONTENT_TYPE_CSS => ['css'],
        'font/woff2' => ['woff2'],
        'font/woff' => ['woff'],
        'font/ttf' => ['ttf'],
        'font/otf' => ['otf'],
    ];

    /** @var string[] Types that are or carry a program: always refused, whatever the kind */
    public const array ACTIVE_CONTENT_TYPES = [
        'application/postscript',
        'application/pdf',
        'application/x-shockwave-flash',
        'application/zip',
        'application/x-ole-storage',
        'application/cdfv2',
        'application/msword',
        'application/rtf',
        'text/rtf',
        'text/html',
        'application/xhtml+xml',
        'application/javascript',
        'text/javascript',
        'application/x-sh',
        'text/x-shellscript',
        'text/x-php',
        'application/x-php',
        'text/x-python',
        'text/x-perl',
    ];

    /** @var string[] Type prefixes of Office and ODF documents (macros, scripts): always refused */
    public const array ACTIVE_CONTENT_TYPE_PREFIXES = [
        'application/vnd.ms-',
        'application/vnd.openxmlformats-',
        'application/vnd.oasis.opendocument.',
    ];

    /** @var string[] Markers of active content in an SVG (lower case): such an SVG is refused, not cleaned */
    public const array SVG_ACTIVE_CONTENT_MARKERS = ['<script', '<foreignobject', '<!entity', 'javascript:', 'vbscript:', '<iframe', '<embed', '<object', '<handler', '<listener'];

    /** @var string An event handler attribute in an SVG (lower case) */
    public const string SVG_EVENT_HANDLER_PATTERN = '/\son[a-z]+\s*=/';

    /** @var string[] Markers of active content in a stylesheet (lower case) */
    public const array CSS_ACTIVE_CONTENT_MARKERS = ['javascript:', 'vbscript:', 'expression(', '-moz-binding', 'behavior:'];

    /** @var int Characters of the version comment that names the source */
    public const int MAX_COMMENT_CHARACTERS = 500;

    /** @var string[] Kinds the agent uses at once: the sink waits for the drive's scan to release them */
    public const array KINDS_WAITING_FOR_SCAN = [self::KIND_DESIGN_TOKENS, self::KIND_LOGO];

    /** @var int Seconds one tool call (conversation and tool) may wait for scans in all, below the tools' own limits */
    public const int SCAN_WAIT_SECONDS_PER_CALL = 45;

    /** @var int Seconds after which a call's wait budget is a new call's (a later call of the same tool) */
    public const int SCAN_WAIT_BUDGET_RESET_SECONDS = 120;

    public const int SCAN_POLL_MICROSECONDS = 500000;

    /** @var string[] Blob states of a write the ingest has not decided yet */
    public const array UNDECIDED_BLOB_STATES = [DriveBlob::STATE_UPLOADING, DriveBlob::STATE_STAGED, DriveBlob::STATE_PENDING];

    /** @var float[] The end of the scan wait budget per call (`<conversationId>:<toolName>`), microtime */
    protected array $scanWaitEndsAtByCallKey = [];

    /**
     * Stores the file in the agent space's drive or refuses it; never throws for a refused file.
     *
     * @throws InternalErrorException the drive failed (the browser turns it into a refusal and logs it)
     */
    public function storeFile(BrowserFileToStore $browserFileToStore, BrowserFileSinkContext $browserFileSinkContext): BrowserStoredFile
    {
        $contentType = $this->detectContentType($browserFileToStore);
        $refusalReason = $this->findRefusalReason($browserFileToStore, $contentType);
        $storedBytes = $refusalReason === null ? $this->buildStoredBytes($browserFileToStore, $contentType) : null;
        if ($refusalReason === null && $storedBytes === null) {
            $refusalReason = 'the SVG could not be cleaned (no SVG sanitizer is installed, or it refused the file)';
        }
        if ($refusalReason !== null) {
            DDDService::instance()->getLogger()->info('[CloudDrive] browser file refused by the sink', ['kind' => $browserFileToStore->kind, 'contentType' => $contentType, 'reason' => $refusalReason, 'conversationId' => $browserFileSinkContext->conversationId]);
            return BrowserStoredFile::refused($refusalReason);
        }
        if ((int)($browserFileSinkContext->agentSpaceId ?? 0) <= 0 && ($browserFileSinkContext->workspaceKey ?? '') === '') {
            return BrowserStoredFile::refused('this conversation has no agent space, so there is no drive to store files in');
        }
        $drivePath = $this->buildDrivePath($browserFileToStore, $contentType);
        $driveSpace = $this->findDriveSpaceOfContext($browserFileSinkContext);
        if ($driveSpace === null) {
            return BrowserStoredFile::refused('the agent space has no drive to store files in');
        }
        try {
            $driveFileWriteResult = $this->writeUntrustedFile($driveSpace, $drivePath, (string)$storedBytes, $contentType, $this->buildVersionComment($browserFileToStore));
        } catch (BadRequestException|ForbiddenException $driveRefusal) {
            return BrowserStoredFile::refused('the drive refused the file: ' . mb_substr($driveRefusal->getMessage(), 0, 300));
        }
        if ($driveFileWriteResult->isConflict) {
            return BrowserStoredFile::refused('the drive kept another version (' . ($driveFileWriteResult->conflictReason ?? 'conflict') . ')');
        }
        if (in_array($browserFileToStore->kind, self::KINDS_WAITING_FOR_SCAN, true)
            && $this->waitForScanDecision($browserFileSinkContext, $driveFileWriteResult) === DriveBlob::STATE_BLOCKED
        ) {
            return BrowserStoredFile::refused('the drive\'s malware scan or type policy blocked the file');
        }
        $this->requestPullOfWorkingCopy($driveSpace, $browserFileSinkContext);
        $browserStoredFile = new BrowserStoredFile();
        $browserStoredFile->path = $drivePath;
        $browserStoredFile->contentType = $contentType;
        $browserStoredFile->sizeBytes = strlen((string)$storedBytes);
        return $browserStoredFile;
    }

    /** The type read from the bytes; a token file that is valid JSON is JSON whatever libmagic calls it */
    public function detectContentType(BrowserFileToStore $browserFileToStore): string
    {
        $bytes = $browserFileToStore->getBytes();
        if ($browserFileToStore->kind === self::KIND_DESIGN_TOKENS && $bytes !== '' && json_validate($bytes)) {
            return self::CONTENT_TYPE_JSON;
        }
        /** @var BrowserFileSinksService $browserFileSinksService */
        $browserFileSinksService = DDDService::instance()->getService(BrowserFileSinksService::class);
        return UntrustedContentHeaders::normalizeContentType($browserFileSinksService->detectContentType($bytes, $browserFileToStore->contentType));
    }

    /** Why the file is not taken; null when it is */
    public function findRefusalReason(BrowserFileToStore $browserFileToStore, string $contentType): ?string
    {
        $kind = (string)$browserFileToStore->kind;
        if (!isset(self::ALLOWED_CONTENT_TYPES_BY_KIND[$kind])) {
            return sprintf('files of the kind "%s" are not taken', mb_substr($kind, 0, 40));
        }
        if ($browserFileToStore->getSizeBytes() === 0) {
            return 'the file is empty';
        }
        if ($this->isActiveContentType($contentType)) {
            return sprintf('executable or active content (%s) is never stored', $contentType);
        }
        if (!in_array($contentType, self::ALLOWED_CONTENT_TYPES_BY_KIND[$kind], true)) {
            return sprintf('the bytes are %s, which %s files may not be', $contentType, $kind);
        }
        $lowerText = $contentType === self::CONTENT_TYPE_SVG || $contentType === self::CONTENT_TYPE_CSS ? strtolower($browserFileToStore->getBytes()) : '';
        if ($contentType === self::CONTENT_TYPE_SVG && $this->containsActiveSvgContent($lowerText)) {
            return 'the SVG carries scripts or other active content';
        }
        if ($contentType === self::CONTENT_TYPE_CSS && $this->containsAnyMarker($lowerText, self::CSS_ACTIVE_CONTENT_MARKERS)) {
            return 'the stylesheet carries script URLs, expressions or bindings';
        }
        return $this->findFileNameRefusalReason($browserFileToStore, $contentType);
    }

    /** Why the file's name is not taken (it is kept as the renderer gave it, so it must be safe and fit the bytes) */
    public function findFileNameRefusalReason(BrowserFileToStore $browserFileToStore, string $contentType): ?string
    {
        if ($browserFileToStore->kind === self::KIND_DESIGN_TOKENS) {
            return null;
        }
        $fileName = (string)$browserFileToStore->fileName;
        if (preg_match(self::FILE_NAME_PATTERN, $fileName) !== 1) {
            return 'the file name is not a plain name';
        }
        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = self::EXTENSIONS_BY_CONTENT_TYPE[$contentType] ?? [];
        if ($extension !== '' && $this->isKnownExtension($extension) && !in_array($extension, $allowedExtensions, true)) {
            return sprintf('the name says .%s, the bytes are %s', $extension, $contentType);
        }
        return null;
    }

    /** Whether the type is or carries a program */
    public function isActiveContentType(string $contentType): bool
    {
        if (in_array($contentType, self::ACTIVE_CONTENT_TYPES, true) || in_array($contentType, DriveTypePolicy::EXECUTABLE_CONTENT_TYPES, true)) {
            return true;
        }
        foreach (self::ACTIVE_CONTENT_TYPE_PREFIXES as $activeContentTypePrefix) {
            if (str_starts_with($contentType, $activeContentTypePrefix)) {
                return true;
            }
        }
        return false;
    }

    /** The bytes that are stored: an SVG sanitised (null when it cannot be), everything else as it came */
    public function buildStoredBytes(BrowserFileToStore $browserFileToStore, string $contentType): ?string
    {
        if ($contentType !== self::CONTENT_TYPE_SVG) {
            return $browserFileToStore->getBytes();
        }
        /** @var DriveSvgSanitizersService $driveSvgSanitizersService */
        $driveSvgSanitizersService = DDDService::instance()->getService(DriveSvgSanitizersService::class);
        return $driveSvgSanitizersService->sanitizeSvg($browserFileToStore->getBytes());
    }

    /** `web/<domain>/<name>`: a drive path without a leading slash */
    public function buildDrivePath(BrowserFileToStore $browserFileToStore, string $contentType): string
    {
        return $this->buildTargetFolder($browserFileToStore) . '/' . $this->buildFileName($browserFileToStore, $contentType);
    }

    /** `web/<domain>`: the browser's `targetFolder` when it has that shape, else built from the page's (or the file's) address */
    public function buildTargetFolder(BrowserFileToStore $browserFileToStore): string
    {
        $targetFolder = (string)$browserFileToStore->targetFolder;
        if (preg_match(self::TARGET_FOLDER_PATTERN, $targetFolder) === 1 && !str_contains($targetFolder, '..')) {
            return $targetFolder;
        }
        /** @var BrowserFileSinksService $browserFileSinksService */
        $browserFileSinksService = DDDService::instance()->getService(BrowserFileSinksService::class);
        return $browserFileSinksService->buildWebFolder($browserFileToStore->pageUrl ?? $browserFileToStore->sourceUrl);
    }

    /** The renderer's name; `design-tokens.json` for the token file; a name without an extension gets the one of its type */
    public function buildFileName(BrowserFileToStore $browserFileToStore, string $contentType): string
    {
        if ($browserFileToStore->kind === self::KIND_DESIGN_TOKENS) {
            return self::DESIGN_TOKENS_FILE_NAME;
        }
        $fileName = (string)$browserFileToStore->fileName;
        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== '' && $this->isKnownExtension($extension)) {
            return $fileName;
        }
        return $fileName . '.' . (self::EXTENSIONS_BY_CONTENT_TYPE[$contentType][0] ?? 'bin');
    }

    /** The version comment: where the bytes came from, and that a web page wrote them */
    public function buildVersionComment(BrowserFileToStore $browserFileToStore): string
    {
        $source = $browserFileToStore->sourceUrl ?? $browserFileToStore->pageUrl ?? 'a web page';
        return mb_substr(sprintf('Browser (%s) from %s; written by a third-party web page, untrusted', (string)$browserFileToStore->kind, $source), 0, self::MAX_COMMENT_CHARACTERS);
    }

    /** The scratch drive of an eval case's workspace, else the own drive of the agent space (found or created) */
    public function findDriveSpaceOfContext(BrowserFileSinkContext $browserFileSinkContext): ?DriveSpace
    {
        $workspaceKey = (string)($browserFileSinkContext->workspaceKey ?? '');
        $agentSpaceId = (int)($browserFileSinkContext->agentSpaceId ?? 0);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $scratchDriveSpace = $workspaceKey !== '' ? $this->getDriveSpacesService()->findSpaceByScratchKey($workspaceKey) : null;
            if ($scratchDriveSpace !== null || $agentSpaceId <= 0) {
                return $scratchDriveSpace;
            }
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $agentSpace = $agentSpacesService->find($agentSpaceId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$agentSpace instanceof AgentSpace) {
            return null;
        }
        /** @var DriveSpaceMountResolverService $driveSpaceMountResolverService */
        $driveSpaceMountResolverService = DDDService::instance()->getService(DriveSpaceMountResolverService::class);
        return $driveSpaceMountResolverService->findOrCreateOwnDriveSpace($agentSpace);
    }

    /**
     * A person's upload from bytes the server holds: reserve, store, complete, write (origin UPLOAD, a new version
     * over an existing file, marked untrusted), ingest (hash, type policy, malware scan; in the drive_ingest worker
     * when `CloudDrive.settings.asyncIngest` is on). Needs the caller's write right on the space.
     *
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     */
    public function writeUntrustedFile(DriveSpace $driveSpace, string $drivePath, string $bytes, string $contentType, string $comment): DriveFileWriteResult
    {
        $driveNodesService = $this->getDriveNodesService();
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);

        $pathSegments = DriveNodesService::splitPath($drivePath);
        $fileName = (string)array_pop($pathSegments);
        $folderNode = $driveNodesService->findOrCreateFolderPath($driveSpace, implode('/', $pathSegments));
        $driveUploadRequest = new DriveUploadRequest();
        $driveUploadRequest->sizeBytes = strlen($bytes);
        $driveUploadRequest->contentType = $contentType;
        $driveUploadRequest->sha256 = hash('sha256', $bytes);
        $driveUploadRequest->fileName = $fileName;
        $driveUploadReservation = $driveBlobsService->reserveUpload($driveSpace, $driveUploadRequest);
        $driveBlob = $driveBlobsService->find($driveUploadReservation->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new InternalErrorException(sprintf('The reserved blob of %s is missing.', $drivePath));
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
        $existingNode = $driveNodesService->findChildByName($folderNode, $fileName);
        $driveFileWrite = new DriveFileWrite();
        $driveFileWrite->driveBlobId = (int)$driveBlob->id;
        $driveFileWrite->comment = $comment;
        $driveFileWrite->origin = DriveNode::ORIGIN_UPLOAD;
        $driveFileWrite->isContentUntrusted = true;
        // the base is the newest version read here: a version written between this read and the write is a conflict
        $driveFileWrite->expectedLatestVersionId = $existingNode?->latestVersionId;
        $driveFileWrite->authorAccountId = $driveChangesService->findActingAccountIdOfCurrentCaller();
        $driveFileWrite->onBehalfOfAccountId = $driveChangesService->findOnBehalfOfAccountIdOfCurrentCaller();
        $driveFileWriteResult = $driveVersionsService->writeFile($driveSpace, $folderNode, $fileName, $driveFileWrite);
        if ($driveFileWriteResult->driveFileVersion !== null) {
            $driveVersionsService->ingestVersion($driveFileWriteResult->driveFileVersion, !(bool)$folderNode->isSystem);
        }
        return $driveFileWriteResult;
    }

    /**
     * Waits (within the call's budget) until the drive's ingest decided the written version.
     *
     * @return string the blob state at the end (RELEASED, BLOCKED, SCAN_FAILED, or still undecided when the budget ran out)
     */
    protected function waitForScanDecision(BrowserFileSinkContext $browserFileSinkContext, DriveFileWriteResult $driveFileWriteResult): string
    {
        $driveFileVersionId = (int)($driveFileWriteResult->driveFileVersion?->id ?? 0);
        if ($driveFileVersionId <= 0) {
            // unchanged content: the file holds these bytes already
            return DriveBlob::STATE_RELEASED;
        }
        return $this->pollBlobStateOfVersion($driveFileVersionId, $this->findScanWaitEndsAt($browserFileSinkContext));
    }

    /** The state of the version's blob once decided, or when the time is up */
    protected function pollBlobStateOfVersion(int $driveFileVersionId, float $scanWaitEndsAt): string
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        while (true) {
            $driveFileVersion = $driveVersionsService->find($driveFileVersionId, false);
            $driveBlob = $driveFileVersion instanceof DriveFileVersion ? $driveBlobsService->find((int)$driveFileVersion->driveBlobId, false) : null;
            $blobState = $driveBlob instanceof DriveBlob ? (string)$driveBlob->state : DriveBlob::STATE_RELEASED;
            if (!in_array($blobState, self::UNDECIDED_BLOB_STATES, true) || microtime(true) >= $scanWaitEndsAt) {
                return $blobState;
            }
            usleep(self::SCAN_POLL_MICROSECONDS);
        }
    }

    /** The end of the wait budget of this call (shared by every file it stores) */
    protected function findScanWaitEndsAt(BrowserFileSinkContext $browserFileSinkContext): float
    {
        $callKey = (int)($browserFileSinkContext->conversationId ?? 0) . ':' . (string)$browserFileSinkContext->toolName;
        $now = microtime(true);
        $scanWaitEndsAt = $this->scanWaitEndsAtByCallKey[$callKey] ?? null;
        if ($scanWaitEndsAt === null || $now > $scanWaitEndsAt + self::SCAN_WAIT_BUDGET_RESET_SECONDS) {
            $scanWaitEndsAt = $now + self::SCAN_WAIT_SECONDS_PER_CALL;
            $this->scanWaitEndsAtByCallKey[$callKey] = $scanWaitEndsAt;
        }
        return $scanWaitEndsAt;
    }

    /** Marks the working copy of the call's sandbox (its workspace key, else the space's newest) for a pull */
    protected function requestPullOfWorkingCopy(DriveSpace $driveSpace, BrowserFileSinkContext $browserFileSinkContext): void
    {
        /** @var DriveWorkingCopiesService $driveWorkingCopiesService */
        $driveWorkingCopiesService = DDDService::instance()->getService(DriveWorkingCopiesService::class);
        $workspaceKey = (string)($browserFileSinkContext->workspaceKey ?? '');
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveWorkingCopy = $workspaceKey !== ''
                ? $driveWorkingCopiesService->findWorkingCopy($driveSpace, $workspaceKey)
                : null;
            $driveWorkingCopy ??= $driveWorkingCopiesService->findNewestWorkingCopy($driveSpace);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if ($driveWorkingCopy instanceof DriveWorkingCopy) {
            $driveWorkingCopiesService->requestPull($driveWorkingCopy);
        }
    }

    protected function isKnownExtension(string $extension): bool
    {
        foreach (self::EXTENSIONS_BY_CONTENT_TYPE as $extensions) {
            if (in_array($extension, $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    protected function containsActiveSvgContent(string $lowerSvg): bool
    {
        return $this->containsAnyMarker($lowerSvg, self::SVG_ACTIVE_CONTENT_MARKERS) || preg_match(self::SVG_EVENT_HANDLER_PATTERN, $lowerSvg) === 1;
    }

    /** @param string[] $markers */
    protected function containsAnyMarker(string $lowerText, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($lowerText, $marker)) {
                return true;
            }
        }
        return false;
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
}
