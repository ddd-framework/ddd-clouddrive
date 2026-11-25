<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveFileFamily;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRendition;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRenditionMarker;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRenditionSettings;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Exceptions\DriveOfficeRenditionFailedException;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionHandler;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage;
use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\ArchiveInspection;
use DDD\Infrastructure\Untrusted\ArchiveInspector;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use DDD\Infrastructure\Untrusted\UntrustedContentException;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Psr\Log\LogLevel;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The PDF rendition of an Office file (docx, xlsx, pptx, odt, ods, odp), so a reader sees it in the browser's PDF
 * viewer instead of downloading it (`CloudDrive.settings.officeRenditions`, off by default).
 *
 * A rendition belongs to the content, not to the file: it is stored next to the blob (`<blob key>.rendition.pdf`), made
 * once and read by every version and file that shares the blob; a purged blob takes it along
 * ({@see DriveBlobsService::purgeBlob()}). There is no table: the state is what the store holds — the PDF (READY), a
 * failure marker (FAILED, with a reason code) or neither (PENDING; a pending marker, written create-only, makes sure
 * one conversion is dispatched, and one older than twice the timeout counts as lost and is dispatched again). No cache
 * and no lock is involved anywhere.
 *
 * The conversion runs in a worker ({@see DriveOfficeRenditionMessage}, transport `drive_render`) and treats the file as
 * foreign bytes:
 * - PHP downloads the blob to a file it names itself and uploads the PDF; LibreOffice only ever sees local paths;
 * - the ZIP container is inspected first (entry count, expanded size, compression ratio, entry names, the container's
 *   marker entry) and refused before LibreOffice opens it;
 * - LibreOffice runs as an isolated process (argument array, no shell, an environment without any inherited variable,
 *   a timeout, an address space cap) with a throwaway profile per job: macros off, no link updates, no linked or
 *   remote content, no recalculation on load, no Java, no update check, no crash reporter, no recovery;
 * - only the first `maxPages` pages are exported, and the output is taken only when it is a PDF within the size cap;
 * - the working directory is removed whatever happens.
 * Out of PHP's reach, a host's duty: no network for the worker, and another uid for the converter (with the worker's
 * uid it can read `/proc/<php pid>/environ`; `commandPrefix` puts a wrapper that switches the uid before the binary).
 */
class DriveOfficeRenditionsService extends Service
{
    /** @var string[] The PDF export filter by extension: the ZIP based formats only (inspected before they are opened) */
    public const array EXPORT_FILTERS_BY_EXTENSION = [
        'docx' => 'writer_pdf_Export',
        'odt' => 'writer_pdf_Export',
        'xlsx' => 'calc_pdf_Export',
        'ods' => 'calc_pdf_Export',
        'pptx' => 'impress_pdf_Export',
        'odp' => 'impress_pdf_Export',
    ];

    /** @var string[] The entry every container of the format holds, by extension: a ZIP without it is no such document */
    public const array CONTAINER_MARKER_ENTRIES_BY_EXTENSION = [
        'docx' => '[Content_Types].xml',
        'xlsx' => '[Content_Types].xml',
        'pptx' => '[Content_Types].xml',
        'odt' => 'META-INF/manifest.xml',
        'ods' => 'META-INF/manifest.xml',
        'odp' => 'META-INF/manifest.xml',
    ];

    public const string RENDITION_KEY_SUFFIX = '.rendition.pdf';
    public const string FAILURE_MARKER_KEY_SUFFIX = '.rendition.failed';
    public const string PENDING_MARKER_KEY_SUFFIX = '.rendition.pending';

    public const string MARKER_CONTENT_TYPE = 'application/json';

    /** @var int Entries of an Office container (a long deck with its pictures stays far below) */
    public const int MAX_ARCHIVE_ENTRIES = 5000;

    /** @var int What an Office container may expand to */
    public const int MAX_ARCHIVE_UNCOMPRESSED_BYTES = 512 * 1024 * 1024;

    /** @var int Uncompressed / compressed of the whole container (sheet XML reaches 50 to 100) */
    public const int MAX_ARCHIVE_COMPRESSION_RATIO = 200;

    /** @var int A larger PDF is not stored (it is read into memory once to be stored create-only) */
    public const int MAX_RENDITION_BYTES = 100 * 1024 * 1024;

    /** @var int A failure is kept this long, then the next request converts again (a host may have fixed its image) */
    public const int FAILURE_RETRY_SECONDS = 86400;

    /** @var int A failure that says nothing about the file (no converter, the store did not answer) is kept this long */
    public const int TRANSIENT_FAILURE_RETRY_SECONDS = 600;

    /** @var int Added to twice the timeout before a pending marker counts as lost (the wait in the queue) */
    public const int LOST_PENDING_GRACE_SECONDS = 60;

    public const string FAILURE_NOT_A_DOCUMENT = 'NOT_A_DOCUMENT';
    public const string FAILURE_TOO_LARGE = 'TOO_LARGE';
    public const string FAILURE_TIMEOUT = 'TIMEOUT';
    public const string FAILURE_CONVERSION = 'CONVERSION';
    public const string FAILURE_UNAVAILABLE = 'UNAVAILABLE';
    /** Never stored: the answer where no conversion is started ({@see DriveBlobsService::isDerivedObjectWriteAllowed()}) */
    public const string FAILURE_NOT_STARTED_HERE = 'NOT_STARTED_HERE';
    /**
     * Never stored: the answer of a web request when {@see DriveOfficeRenditionMessage} is routed to no asynchronous
     * transport, so Messenger would convert inside the request ({@see isRenditionMessageRoutedToWorker()})
     */
    public const string FAILURE_NOT_ROUTED_TO_WORKER = 'NOT_ROUTED_TO_WORKER';

    /** The routing key of Messenger that matches every message class */
    public const string ROUTING_WILDCARD = '*';

    /** @var string[] What a reader is told, by reason code (never the converter's output) */
    public const array FAILURE_REASONS = [
        self::FAILURE_NOT_A_DOCUMENT => 'The file is not a valid Office document, so it has no PDF preview.',
        self::FAILURE_TOO_LARGE => 'The file is too large for a PDF preview.',
        self::FAILURE_TIMEOUT => 'The PDF preview took too long to create.',
        self::FAILURE_CONVERSION => 'The file could not be converted to a PDF preview.',
        self::FAILURE_UNAVAILABLE => 'The PDF preview is not available at the moment. Try again later.',
        self::FAILURE_NOT_STARTED_HERE => 'This file has no PDF preview yet, and none is created in this environment.',
        self::FAILURE_NOT_ROUTED_TO_WORKER => 'This file has no PDF preview yet: PDF previews are not set up to be created in the background here.',
    ];

    /** @var bool The routing problem was written to the issues log by this process (once per process) */
    protected static bool $isRenditionRoutingProblemLogged = false;

    /** @var string[] Reason codes that say nothing about the file: retried after {@see TRANSIENT_FAILURE_RETRY_SECONDS} */
    public const array TRANSIENT_FAILURES = [self::FAILURE_UNAVAILABLE];

    /** @var string[] `<OS>_<ARCH>` as LibreOffice names its Java settings file */
    public const array JAVA_SETTINGS_PLATFORMS = ['Linux_X86_64', 'Linux_AARCH64', 'MacOSX_X86_64', 'MacOSX_AARCH64'];

    /**
     * @var array<int, array{0: string, 1: string, 2: string}> The profile's settings: registry path, property, value.
     *      A property a LibreOffice version does not know is skipped by it.
     */
    public const array PROFILE_REGISTRY_SETTINGS = [
        // macros: the highest security level (only trusted locations, and there are none) and no execution at all
        ['/org.openoffice.Office.Common/Security/Scripting', 'MacroSecurityLevel', '3'],
        ['/org.openoffice.Office.Common/Security/Scripting', 'DisableMacrosExecution', 'true'],
        // OLE objects, DDE and other active content of a document stay off (a newer LibreOffice knows the switch)
        ['/org.openoffice.Office.Common/Security/Scripting', 'DisableActiveContent', 'true'],
        // nothing a document links to is loaded (pictures, OLE, WEBSERVICE): no document is in a trusted location
        ['/org.openoffice.Office.Common/Security/Scripting', 'BlockUntrustedRefererLinks', 'true'],
        // links are never updated on load: Writer 0 = never, Calc 2 = never
        ['/org.openoffice.Office.Writer/Content/Update', 'Link', '0'],
        ['/org.openoffice.Office.Calc/Content/Update', 'Link', '2'],
        // formulas are not recalculated on load (1 = never): no WEBSERVICE or DDE call, the stored results are printed
        ['/org.openoffice.Office.Calc/Formula/Load', 'OOXMLRecalcMode', '1'],
        ['/org.openoffice.Office.Calc/Formula/Load', 'ODFRecalcMode', '1'],
        // Java: no network for code that would run in it (3 = none), its security manager on; the switch itself is
        // in the Java settings file
        ['/org.openoffice.Office.Java/VirtualMachine', 'NetAccess', '3'],
        ['/org.openoffice.Office.Java/VirtualMachine', 'Security', 'true'],
        // no update check, no crash reporter, no recovery, no backup copies, no first-start wizard
        ["/org.openoffice.Office.Jobs/Jobs/org.openoffice.Office.Jobs:Job['UpdateCheck']/Arguments", 'AutoCheckEnabled', 'false'],
        ['/org.openoffice.Office.Common/Misc', 'CrashReport', 'false'],
        ['/org.openoffice.Office.Recovery/AutoSave', 'Enabled', 'false'],
        ['/org.openoffice.Office.Recovery/RecoveryInfo', 'Enabled', 'false'],
        ['/org.openoffice.Office.Recovery/RecoveryInfo', 'SessionData', 'false'],
        ['/org.openoffice.Office.Common/Save/Document', 'CreateBackup', 'false'],
        ['/org.openoffice.Office.Common/Misc', 'FirstRun', 'false'],
        ['/org.openoffice.Setup/Office', 'ooSetupInstCompleted', 'true'],
    ];

    public function getSettings(): DriveOfficeRenditionSettings
    {
        return DriveOfficeRenditionSettings::fromConfig();
    }

    /**
     * The PDF export filter of a file by its name's extension; null for every type without a rendition (the legacy
     * binary formats doc, xls and ppt among them).
     */
    public static function findExportFilter(?string $fileName): ?string
    {
        return self::EXPORT_FILTERS_BY_EXTENSION[DriveFileFamily::findExtension((string)$fileName)] ?? null;
    }

    /**
     * Whether a stored type can be the container of an Office file: one of the six office types, a ZIP libmagic did
     * not name further, or a type that tells nothing. A rendition is only ever made (and a marker only ever written)
     * for such a blob, and {@see DriveBlobsService::purgeBlob()} removes the derived objects of exactly these.
     */
    public static function isRenditionSourceContentType(?string $contentType): bool
    {
        $normalizedContentType = UntrustedContentHeaders::normalizeContentType($contentType);
        return DriveBlobsService::isContentTypeUnknown($normalizedContentType)
            || in_array($normalizedContentType, DriveBlobsService::ZIP_CONTENT_TYPES, true)
            || in_array($normalizedContentType, DriveBlobsService::CONTAINER_CONTENT_TYPES_BY_EXTENSION, true);
    }

    /**
     * Whether the server offers a PDF rendition for a file (what `hasPdfRendition` of a list item and a file card
     * says): the setting is on, it is a file with a supported extension, within `maxSourceBytes`, of a type that can
     * be an Office container. Rights, the trash rule and the scan state are the caller's.
     *
     * @param int|null $sizeBytes the size of the content to convert; null = the file's
     * @param string|null $contentType the stored type of that content; null = the file's
     */
    public function isRenditionOffered(DriveNode $driveNode, ?int $sizeBytes = null, ?string $contentType = null): bool
    {
        $driveOfficeRenditionSettings = $this->getSettings();
        if (!$driveOfficeRenditionSettings->enabled || !$driveNode->isFile() || static::findExportFilter((string)$driveNode->name) === null) {
            return false;
        }
        return (int)($sizeBytes ?? $driveNode->sizeBytes) <= $driveOfficeRenditionSettings->maxSourceBytes
            && static::isRenditionSourceContentType($contentType ?? $driveNode->contentType);
    }

    /** The key of a blob's PDF rendition: next to the blob, deleted with it */
    public static function buildRenditionStorageKey(string $storageKey): string
    {
        return $storageKey . self::RENDITION_KEY_SUFFIX;
    }

    /** The key of the marker that says a blob's conversion failed (JSON: `reasonCode`, `failedAt`) */
    public static function buildFailureMarkerStorageKey(string $storageKey): string
    {
        return $storageKey . self::FAILURE_MARKER_KEY_SUFFIX;
    }

    /** The key of the marker that says a blob's conversion was dispatched (JSON: `requestedAt`) */
    public static function buildPendingMarkerStorageKey(string $storageKey): string
    {
        return $storageKey . self::PENDING_MARKER_KEY_SUFFIX;
    }

    /**
     * Every object derived from a blob for its rendition.
     * @return string[]
     */
    public static function buildDerivedStorageKeys(string $storageKey): array
    {
        return [
            static::buildRenditionStorageKey($storageKey),
            static::buildFailureMarkerStorageKey($storageKey),
            static::buildPendingMarkerStorageKey($storageKey),
        ];
    }

    /**
     * Whether a request may start a conversion.
     *
     * @deprecated since 0.4.1, removed in 0.5.0: override {@see DriveBlobsService::isDerivedObjectWriteAllowed()}, the
     * one switch for every derived object. Still asked by {@see requestRendition()}, so a host's same-path override of
     * this method keeps deciding for the rendition; the default answers the switch.
     */
    public function isConversionStartAllowed(): bool
    {
        return $this->getDriveBlobsService()->isDerivedObjectWriteAllowed();
    }

    /**
     * Whether {@see DriveOfficeRenditionMessage} goes to a worker when dispatched: it is routed (by its class, a parent
     * class, an interface or `*`, as Messenger's senders locator matches) to at least one transport that a worker
     * consumes, i.e. one that is not synchronous (`sync://`, `in-memory://`). Read from the compiled container's
     * routing ({@see WorkerTransportsService}). False = Messenger would handle the message inside the dispatching
     * process.
     */
    public function isRenditionMessageRoutedToWorker(): bool
    {
        $workerTransportsService = $this->getWorkerTransportsService();
        $messageTypes = array_merge(
            [DriveOfficeRenditionMessage::class],
            array_values(class_parents(DriveOfficeRenditionMessage::class) ?: []),
            array_values(class_implements(DriveOfficeRenditionMessage::class) ?: []),
            [self::ROUTING_WILDCARD]
        );
        foreach ($messageTypes as $messageType) {
            foreach ($workerTransportsService->getTransportNamesForMessageClass($messageType) as $transportName) {
                if ($workerTransportsService->hasReceiver($transportName) && !$workerTransportsService->isSynchronousTransport($transportName)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * What is wrong with the host's setup for renditions, as a sentence for a check or a health report; null when
     * nothing is: renditions are off, or the message is routed to a worker. A host check (installation check, health
     * endpoint) shows it; the rendition route answers {@see FAILURE_NOT_ROUTED_TO_WORKER} while it is not null.
     */
    public function findRenditionRoutingProblem(): ?string
    {
        if (!$this->getSettings()->enabled || $this->isRenditionMessageRoutedToWorker()) {
            return null;
        }
        return sprintf(
            'CloudDrive.settings.officeRenditions.enabled is true, but %s is routed to no asynchronous transport (route it to "%s" and run a consumer for it): a web request would convert inside the request, so the rendition route answers FAILED (%s) and converts nothing.',
            DriveOfficeRenditionMessage::class,
            DriveOfficeRenditionHandler::TRANSPORT,
            self::FAILURE_NOT_ROUTED_TO_WORKER
        );
    }

    /**
     * Where the rendition of a file's version (the current one when none is given) stands for the current caller,
     * after the checks of every content read (the read right on the space, the trash rule, a released blob):
     * READY with a grant of the purpose RENDITION, FAILED with the reason, or PENDING — the first PENDING dispatches
     * the conversion ({@see DriveOfficeRenditionMessage}), every further one only reads the store. Where no conversion may
     * be started ({@see isConversionStartAllowed()}, by default {@see DriveBlobsService::isDerivedObjectWriteAllowed()})
     * it only answers a stored PDF. A web request never converts: while the message is routed to no worker
     * ({@see isRenditionMessageRoutedToWorker()}) it answers a stored state and otherwise FAILED
     * ({@see FAILURE_NOT_ROUTED_TO_WORKER}), and the issues log gets one warning per process.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException a file the server offers no rendition for
     * @throws InternalErrorException
     */
    public function requestRendition(DriveNode $driveNode, ?DriveFileVersion $driveFileVersion = null): DriveOfficeRendition
    {
        $driveContentGrantsService = $this->getDriveContentGrantsService();
        $driveFileVersion = $driveContentGrantsService->resolveReadableVersion($driveNode, DriveContentGrant::PURPOSE_RENDITION, $driveFileVersion);
        $driveBlob = $driveContentGrantsService->resolveReadableBlobOfVersion($driveNode, $driveFileVersion);
        if (!$this->isRenditionOffered($driveNode, (int)$driveBlob->sizeBytes, $driveBlob->contentType)) {
            throw new BadRequestException('This file has no PDF rendition.');
        }
        if (!$this->isConversionStartAllowed()) {
            // read only: the stored PDF or nothing. No marker is read, written or removed, nothing is dispatched
            $renditionDelivery = $this->findRenditionDelivery($driveNode, $driveFileVersion, $driveBlob);
            return $renditionDelivery !== null
                ? DriveOfficeRendition::create(DriveOfficeRendition::STATE_READY, null, $driveContentGrantsService->createContentGrantOfDelivery($renditionDelivery))
                : DriveOfficeRendition::create(DriveOfficeRendition::STATE_FAILED, static::findFailureReason(self::FAILURE_NOT_STARTED_HERE));
        }
        $storedRendition = $this->findStoredRendition($driveNode, $driveFileVersion, $driveBlob, true);
        if ($storedRendition !== null) {
            return $storedRendition;
        }
        if (!$this->isConversionInsideThisProcessAllowed() && !$this->isRenditionMessageRoutedToWorker()) {
            // Messenger would convert inside this web request: no marker, no dispatch, no conversion
            $this->logRenditionRoutingProblemOnce();
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_FAILED, static::findFailureReason(self::FAILURE_NOT_ROUTED_TO_WORKER));
        }
        $objectStorage = $this->getDriveBlobsService()->getObjectStorage();
        $pendingMarkerStorageKey = static::buildPendingMarkerStorageKey((string)$driveBlob->storageKey);
        $pendingMarker = DriveOfficeRenditionMarker::createPending($this->getCurrentTimestamp());
        if (!$objectStorage->putObjectIfAbsent($pendingMarkerStorageKey, $pendingMarker->toStoredJson(), self::MARKER_CONTENT_TYPE)) {
            // another request dispatched it a moment ago
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_PENDING);
        }
        try {
            $this->dispatchRendition($driveFileVersion);
        } catch (Throwable $throwable) {
            // nothing was dispatched: without the marker the next request tries again
            $objectStorage->deleteObject($pendingMarkerStorageKey);
            throw $throwable;
        }
        // a host that routes the message nowhere converted inside this request: the answer is in the store already
        return $this->findStoredRendition($driveNode, $driveFileVersion, $driveBlob, false)
            ?? DriveOfficeRendition::create(DriveOfficeRendition::STATE_PENDING);
    }

    /**
     * What the store says about a blob's rendition: READY (with a grant), PENDING (a conversion is under way), FAILED
     * (a failure that is still kept), or null when a conversion has to be dispatched. A lost pending marker and a
     * failure past its retry time are removed here.
     *
     * @param bool $isPendingMarkerRead false right after this request wrote the marker itself
     * @throws InternalErrorException
     */
    protected function findStoredRendition(DriveNode $driveNode, DriveFileVersion $driveFileVersion, DriveBlob $driveBlob, bool $isPendingMarkerRead): ?DriveOfficeRendition
    {
        $renditionDelivery = $this->findRenditionDelivery($driveNode, $driveFileVersion, $driveBlob);
        if ($renditionDelivery !== null) {
            return DriveOfficeRendition::create(
                DriveOfficeRendition::STATE_READY,
                null,
                $this->getDriveContentGrantsService()->createContentGrantOfDelivery($renditionDelivery)
            );
        }
        $objectStorage = $this->getDriveBlobsService()->getObjectStorage();
        $currentTimestamp = $this->getCurrentTimestamp();
        if ($isPendingMarkerRead) {
            $pendingMarkerStorageKey = static::buildPendingMarkerStorageKey((string)$driveBlob->storageKey);
            $pendingMarker = $this->readMarker($objectStorage, $pendingMarkerStorageKey);
            if ($pendingMarker !== null) {
                $lostAfterSeconds = 2 * $this->getSettings()->timeoutSeconds + self::LOST_PENDING_GRACE_SECONDS;
                if ($currentTimestamp - (int)$pendingMarker->requestedAt <= $lostAfterSeconds) {
                    return DriveOfficeRendition::create(DriveOfficeRendition::STATE_PENDING);
                }
                // the worker never answered (a lost message, a killed worker): dispatch again
                $objectStorage->deleteObject($pendingMarkerStorageKey);
            }
        }
        $failureMarkerStorageKey = static::buildFailureMarkerStorageKey((string)$driveBlob->storageKey);
        $failureMarker = $this->readMarker($objectStorage, $failureMarkerStorageKey);
        if ($failureMarker === null) {
            return null;
        }
        $reasonCode = (string)$failureMarker->reasonCode;
        $retrySeconds = in_array($reasonCode, self::TRANSIENT_FAILURES, true) ? self::TRANSIENT_FAILURE_RETRY_SECONDS : self::FAILURE_RETRY_SECONDS;
        if ($currentTimestamp - (int)$failureMarker->failedAt > $retrySeconds) {
            $objectStorage->deleteObject($failureMarkerStorageKey);
            return null;
        }
        return DriveOfficeRendition::create(DriveOfficeRendition::STATE_FAILED, static::findFailureReason($reasonCode));
    }

    /**
     * What to serve for the purpose RENDITION of a version whose checks passed
     * ({@see DriveContentGrantsService::resolveContentDelivery()}): the stored PDF, inline, under the file's name with
     * `.pdf`; null while the blob has no rendition. One HEAD on the store, which also answers size and ETag of the
     * stream.
     *
     * @throws InternalErrorException
     */
    public function findRenditionDelivery(DriveNode $driveNode, DriveFileVersion $driveFileVersion, DriveBlob $driveBlob): ?DriveContentDelivery
    {
        $renditionStorageKey = static::buildRenditionStorageKey((string)$driveBlob->storageKey);
        $renditionObject = $this->getDriveBlobsService()->getObjectStorage()->headObject($renditionStorageKey);
        if ($renditionObject === null) {
            return null;
        }
        $driveContentDelivery = new DriveContentDelivery();
        $driveContentDelivery->driveNodeId = (int)$driveNode->id;
        $driveContentDelivery->driveFileVersionId = (int)$driveFileVersion->id;
        $driveContentDelivery->purpose = DriveContentGrant::PURPOSE_RENDITION;
        $driveContentDelivery->storageKey = $renditionStorageKey;
        $driveContentDelivery->fileName = static::buildRenditionFileName((string)$driveNode->name);
        $driveContentDelivery->sizeBytes = $renditionObject->size;
        $driveContentDelivery->contentType = DriveContentGrantsService::PDF_CONTENT_TYPE;
        $driveContentDelivery->etag = $renditionObject->etag !== null && $renditionObject->etag !== '' ? $renditionObject->etag : null;
        $driveContentDelivery->inlineContentType = DriveContentGrantsService::PDF_CONTENT_TYPE;
        return $driveContentDelivery;
    }

    /** `Report.docx` → `Report.pdf` */
    public static function buildRenditionFileName(string $fileName): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        return ($baseName !== '' ? $baseName : $fileName) . '.pdf';
    }

    /** The sentence of a reason code; an unknown code reads as a failed conversion */
    public static function findFailureReason(string $reasonCode): string
    {
        return self::FAILURE_REASONS[$reasonCode] ?? self::FAILURE_REASONS[self::FAILURE_CONVERSION];
    }

    /**
     * The conversion of one version's blob, in the worker ({@see DriveOfficeRenditionHandler}; a system job, it runs
     * without rights restrictions). Checks again that renditions are on and that the blob is released, of a supported
     * type and within the size; converts; stores the PDF create-only or the failure marker; removes the pending marker.
     * A failure is logged with its detail and answered, never thrown: the reader is told through the marker.
     *
     * @return DriveOfficeRendition READY or FAILED (without a grant: there is no reader here)
     */
    public function renderRendition(DriveFileVersion $driveFileVersion): DriveOfficeRendition
    {
        $driveNode = $this->getDriveNodesService()->find((int)$driveFileVersion->driveNodeId, false);
        $driveBlob = $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false);
        if (!$driveNode instanceof DriveNode || !$driveBlob instanceof DriveBlob) {
            $this->logRenditionFailure($driveFileVersion, null, self::FAILURE_UNAVAILABLE, 'the file or its content no longer exists');
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_FAILED, static::findFailureReason(self::FAILURE_UNAVAILABLE));
        }
        $objectStorage = $this->getDriveBlobsService()->getObjectStorage();
        $storageKey = (string)$driveBlob->storageKey;
        $pendingMarkerStorageKey = static::buildPendingMarkerStorageKey($storageKey);
        $driveOfficeRenditionSettings = $this->getSettings();
        if (!$driveOfficeRenditionSettings->enabled || !$driveBlob->isReadableByPeople() || !static::isRenditionSourceContentType($driveBlob->contentType)) {
            // nothing to tell a reader about the file: no failure marker, the next request decides again
            $objectStorage->deleteObject($pendingMarkerStorageKey);
            $this->logRenditionFailure($driveFileVersion, $driveBlob, self::FAILURE_UNAVAILABLE, 'renditions are off, or the content is not released or no Office container');
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_FAILED, static::findFailureReason(self::FAILURE_UNAVAILABLE));
        }
        if ($objectStorage->objectExists(static::buildRenditionStorageKey($storageKey))) {
            // another version of the same content was converted before
            $objectStorage->deleteObject($pendingMarkerStorageKey);
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_READY);
        }
        $workingDirectory = null;
        try {
            $extension = DriveFileFamily::findExtension((string)$driveNode->name);
            if (!isset(self::EXPORT_FILTERS_BY_EXTENSION[$extension])) {
                throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_NOT_A_DOCUMENT, 'the file has no supported extension');
            }
            if ((int)$driveBlob->sizeBytes > $driveOfficeRenditionSettings->maxSourceBytes) {
                throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_TOO_LARGE, sprintf('%d bytes are more than maxSourceBytes', (int)$driveBlob->sizeBytes));
            }
            $workingDirectory = IsolatedProcess::createWorkingDirectory('office-render');
            $sourceFilePath = $this->downloadSource($objectStorage, $storageKey, $workingDirectory, $extension, $driveOfficeRenditionSettings);
            $this->inspectSourceArchive($sourceFilePath, $extension);
            $this->writeProfile($workingDirectory);
            $renditionFilePath = $this->convertToPdf($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, $extension);
            $this->requirePdfOutput($renditionFilePath);
            $objectStorage->putObjectIfAbsent(static::buildRenditionStorageKey($storageKey), (string)file_get_contents($renditionFilePath), DriveContentGrantsService::PDF_CONTENT_TYPE);
            $objectStorage->deleteObjects([static::buildFailureMarkerStorageKey($storageKey), $pendingMarkerStorageKey]);
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_READY);
        } catch (Throwable $throwable) {
            $reasonCode = $throwable instanceof DriveOfficeRenditionFailedException ? $throwable->reasonCode : self::FAILURE_UNAVAILABLE;
            $this->logRenditionFailure($driveFileVersion, $driveBlob, $reasonCode, $throwable::class . ': ' . $throwable->getMessage());
            $this->storeFailureMarker($objectStorage, $storageKey, $reasonCode);
            $objectStorage->deleteObject($pendingMarkerStorageKey);
            return DriveOfficeRendition::create(DriveOfficeRendition::STATE_FAILED, static::findFailureReason($reasonCode));
        } finally {
            if ($workingDirectory !== null) {
                IsolatedProcess::removeWorkingDirectory($workingDirectory);
            }
        }
    }

    /**
     * The blob's bytes in a file PHP named (`<working directory>/in/source.<extension>`): the converter never sees a
     * URL or a name from outside.
     *
     * @return string the file's path
     * @throws DriveOfficeRenditionFailedException
     */
    protected function downloadSource(ObjectStorage $objectStorage, string $storageKey, string $workingDirectory, string $extension, DriveOfficeRenditionSettings $driveOfficeRenditionSettings): string
    {
        $inputDirectory = $workingDirectory . '/in';
        if (!mkdir($inputDirectory, 0700) && !is_dir($inputDirectory)) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_UNAVAILABLE, 'the input directory cannot be created');
        }
        $sourceFilePath = $inputDirectory . '/source.' . $extension;
        if (!$objectStorage->saveObjectToFile($storageKey, $sourceFilePath)) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_UNAVAILABLE, 'the object is missing in the store');
        }
        $sourceBytes = (int)filesize($sourceFilePath);
        if ($sourceBytes > $driveOfficeRenditionSettings->maxSourceBytes) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_TOO_LARGE, sprintf('%d stored bytes are more than maxSourceBytes', $sourceBytes));
        }
        return $sourceFilePath;
    }

    /**
     * The ZIP container, read before the converter opens it: refused when it is no ZIP, holds too many entries,
     * expands too far, is compressed beyond the ratio, has an entry name that leaves its root, or lacks the entry
     * every container of the format has.
     *
     * @param string $sourceFilePath server-generated
     * @throws DriveOfficeRenditionFailedException
     */
    public function inspectSourceArchive(string $sourceFilePath, string $extension): ArchiveInspection
    {
        try {
            $archiveInspection = ArchiveInspector::inspectZip(
                $sourceFilePath,
                static::MAX_ARCHIVE_ENTRIES,
                static::MAX_ARCHIVE_UNCOMPRESSED_BYTES,
                static::MAX_ARCHIVE_COMPRESSION_RATIO
            );
        } catch (UntrustedContentException $untrustedContentException) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_NOT_A_DOCUMENT, 'the container was refused: ' . $untrustedContentException->getMessage());
        }
        if ($archiveInspection->hasUnsafeEntryNames) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_NOT_A_DOCUMENT, 'the container has an entry name that leaves its root');
        }
        $markerEntryName = self::CONTAINER_MARKER_ENTRIES_BY_EXTENSION[$extension] ?? null;
        if ($markerEntryName === null || !$archiveInspection->hasEntry($markerEntryName)) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_NOT_A_DOCUMENT, sprintf('the container has no %s', (string)$markerEntryName));
        }
        return $archiveInspection;
    }

    /**
     * The throwaway LibreOffice profile of one job, below the working directory: the registry modifications and
     * Java's switch (LibreOffice keeps that one outside the registry, per platform).
     *
     * @return string the profile directory (`-env:UserInstallation` points at it)
     * @throws DriveOfficeRenditionFailedException
     */
    public function writeProfile(string $workingDirectory): string
    {
        $profileDirectory = static::buildProfileDirectory($workingDirectory);
        $javaSettingsDirectory = $profileDirectory . '/user/config';
        if (!mkdir($javaSettingsDirectory, 0700, true) && !is_dir($javaSettingsDirectory)) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_UNAVAILABLE, 'the profile directory cannot be created');
        }
        $isWritten = file_put_contents($profileDirectory . '/user/registrymodifications.xcu', static::buildProfileRegistryModifications()) !== false;
        foreach (static::JAVA_SETTINGS_PLATFORMS as $javaSettingsPlatform) {
            $isWritten = file_put_contents($javaSettingsDirectory . '/javasettings_' . $javaSettingsPlatform . '.xml', static::buildProfileJavaSettings()) !== false && $isWritten;
        }
        if (!$isWritten) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_UNAVAILABLE, 'the profile cannot be written');
        }
        return $profileDirectory;
    }

    public static function buildProfileDirectory(string $workingDirectory): string
    {
        return rtrim($workingDirectory, '/') . '/profile';
    }

    /** The profile's `user/registrymodifications.xcu` ({@see PROFILE_REGISTRY_SETTINGS}) */
    public static function buildProfileRegistryModifications(): string
    {
        $registryModifications = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        foreach (self::PROFILE_REGISTRY_SETTINGS as [$registryPath, $propertyName, $value]) {
            $registryModifications .= sprintf(
                '<item oor:path="%s"><prop oor:name="%s" oor:op="fuse"><value>%s</value></prop></item>' . "\n",
                htmlspecialchars($registryPath, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($propertyName, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }
        return $registryModifications . '</oor:items>' . "\n";
    }

    /** The profile's `user/config/javasettings_<OS>_<ARCH>.xml`: Java switched off */
    public static function buildProfileJavaSettings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<java xmlns="http://openoffice.org/2004/java/framework/1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . '<enabled xsi:nil="false">false</enabled>' . "\n"
            . '<userClassPath xsi:nil="true"/>' . "\n"
            . '<vmParameters xsi:nil="true"/>' . "\n"
            . '<jreLocations xsi:nil="true"/>' . "\n"
            . '<javaInfo xsi:nil="true"/>' . "\n"
            . '</java>' . "\n";
    }

    /**
     * The converter's arguments: headless, no restore, no lock file, no default document, no wizard, the job's own
     * profile, the export of the first `maxPages` pages through the format's filter, into `<working directory>/out`.
     *
     * @param string $sourceFilePath server-generated
     * @return string[]
     * @throws DriveOfficeRenditionFailedException an extension without a filter
     */
    public static function buildConvertArguments(DriveOfficeRenditionSettings $driveOfficeRenditionSettings, string $workingDirectory, string $sourceFilePath, string $extension): array
    {
        $exportFilter = self::EXPORT_FILTERS_BY_EXTENSION[$extension] ?? null;
        if ($exportFilter === null) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_NOT_A_DOCUMENT, 'the file has no supported extension');
        }
        $filterOptions = json_encode(['PageRange' => ['type' => 'string', 'value' => '1-' . $driveOfficeRenditionSettings->maxPages]], JSON_THROW_ON_ERROR);
        $profileUrl = 'file://' . implode('/', array_map('rawurlencode', explode('/', static::buildProfileDirectory($workingDirectory))));
        return [
            '-env:UserInstallation=' . $profileUrl,
            '--headless',
            '--norestore',
            '--nolockcheck',
            '--nodefault',
            '--nofirststartwizard',
            '--convert-to',
            'pdf:' . $exportFilter . ':' . $filterOptions,
            '--outdir',
            static::buildOutputDirectory($workingDirectory),
            $sourceFilePath,
        ];
    }

    public static function buildOutputDirectory(string $workingDirectory): string
    {
        return rtrim($workingDirectory, '/') . '/out';
    }

    /**
     * The converter as an isolated process ({@see IsolatedProcess}: argument array, no shell, no inherited
     * environment variable — only PATH, HOME, TMPDIR and LANG, HOME and TMPDIR inside the working directory —, the
     * timeout as kill switch, the address space cap). With a `commandPrefix` its first word is the process's binary,
     * and its other words and the resolved converter binary lead the arguments (`setpriv --reuid=… -- /usr/bin/soffice …`).
     *
     * @param string $sourceFilePath server-generated
     * @throws InternalErrorException a binary that is not installed
     * @throws DriveOfficeRenditionFailedException
     */
    public function createConvertProcess(DriveOfficeRenditionSettings $driveOfficeRenditionSettings, string $workingDirectory, string $sourceFilePath, string $extension): Process
    {
        $binary = $driveOfficeRenditionSettings->binary;
        $arguments = static::buildConvertArguments($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, $extension);
        if ($driveOfficeRenditionSettings->commandPrefix !== []) {
            $arguments = [...array_slice($driveOfficeRenditionSettings->commandPrefix, 1), IsolatedProcess::resolveBinary($binary), ...$arguments];
            $binary = $driveOfficeRenditionSettings->commandPrefix[0];
        }
        return IsolatedProcess::create(
            $binary,
            $arguments,
            $workingDirectory,
            $driveOfficeRenditionSettings->timeoutSeconds,
            $driveOfficeRenditionSettings->addressSpaceBytes
        );
    }

    /**
     * Runs the converter and answers where its PDF is expected. Its output goes to the server log only.
     *
     * @param string $sourceFilePath server-generated
     * @return string `<working directory>/out/source.pdf`
     * @throws DriveOfficeRenditionFailedException
     */
    protected function convertToPdf(DriveOfficeRenditionSettings $driveOfficeRenditionSettings, string $workingDirectory, string $sourceFilePath, string $extension): string
    {
        $outputDirectory = static::buildOutputDirectory($workingDirectory);
        if (!mkdir($outputDirectory, 0700) && !is_dir($outputDirectory)) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_UNAVAILABLE, 'the output directory cannot be created');
        }
        try {
            $process = $this->createConvertProcess($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, $extension);
        } catch (InternalErrorException $internalErrorException) {
            if ($internalErrorException instanceof DriveOfficeRenditionFailedException) {
                throw $internalErrorException;
            }
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_UNAVAILABLE, 'the converter cannot be started: ' . $internalErrorException->getMessage());
        }
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_TIMEOUT, sprintf('the converter was stopped after %d seconds', $driveOfficeRenditionSettings->timeoutSeconds));
        }
        $exitCode = $process->getExitCode();
        if ($exitCode !== 0) {
            $converterOutput = mb_substr(trim($process->getOutput() . ' ' . $process->getErrorOutput()), 0, 2000);
            // 124 and 137: coreutils `timeout` ended the process group (TERM, then KILL)
            throw DriveOfficeRenditionFailedException::forReason(
                in_array($exitCode, [124, 137], true) ? self::FAILURE_TIMEOUT : self::FAILURE_CONVERSION,
                sprintf('the converter ended with exit code %s: %s', (string)$exitCode, $converterOutput)
            );
        }
        return $outputDirectory . '/' . pathinfo($sourceFilePath, PATHINFO_FILENAME) . '.pdf';
    }

    /**
     * Takes the converter's output only when it is a real file, a PDF by its first bytes and within the size cap.
     *
     * @param string $renditionFilePath server-generated
     * @throws DriveOfficeRenditionFailedException
     */
    public function requirePdfOutput(string $renditionFilePath): void
    {
        if (is_link($renditionFilePath) || !is_file($renditionFilePath)) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_CONVERSION, 'the converter wrote no PDF');
        }
        $renditionBytes = (int)filesize($renditionFilePath);
        if ($renditionBytes > self::MAX_RENDITION_BYTES) {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_TOO_LARGE, sprintf('the PDF has %d bytes, more than the cap', $renditionBytes));
        }
        if (file_get_contents($renditionFilePath, false, null, 0, 5) !== '%PDF-') {
            throw DriveOfficeRenditionFailedException::forReason(self::FAILURE_CONVERSION, 'the converter\'s output is no PDF');
        }
    }

    /**
     * Dispatches the conversion of one version's blob to the transport `drive_render` (a host that does not route the
     * message converts inside this call; {@see requestRendition()} lets only a command-line process do that).
     *
     * @throws InternalErrorException
     */
    protected function dispatchRendition(DriveFileVersion $driveFileVersion): void
    {
        (new DriveOfficeRenditionMessage((int)$driveFileVersion->id))->dispatch();
    }

    /**
     * Whether a conversion may run inside this process when the message is handled synchronously: on the command line
     * (a console command, a worker), never inside a web request.
     */
    protected function isConversionInsideThisProcessAllowed(): bool
    {
        return in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    /** One warning per process in the issues log while the rendition message is routed to no worker */
    protected function logRenditionRoutingProblemOnce(): void
    {
        if (static::$isRenditionRoutingProblemLogged) {
            return;
        }
        static::$isRenditionRoutingProblemLogged = true;
        $this->logRenditionRoutingProblem((string)$this->findRenditionRoutingProblem());
    }

    protected function logRenditionRoutingProblem(string $renditionRoutingProblem): void
    {
        /** @var IssuesLogService $issuesLogService */
        $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
        $issuesLogService->logThrowable(
            DriveOfficeRenditionFailedException::forReason(self::FAILURE_NOT_ROUTED_TO_WORKER, $renditionRoutingProblem),
            LogLevel::WARNING,
            ['messageClass' => DriveOfficeRenditionMessage::class, 'transport' => DriveOfficeRenditionHandler::TRANSPORT]
        );
    }

    /** The failure marker of a blob: the reason code and when it failed (replaces an older one) */
    protected function storeFailureMarker(ObjectStorage $objectStorage, string $storageKey, string $reasonCode): void
    {
        $objectStorage->putObject(
            static::buildFailureMarkerStorageKey($storageKey),
            DriveOfficeRenditionMarker::createFailure($reasonCode, $this->getCurrentTimestamp())->toStoredJson(),
            self::MARKER_CONTENT_TYPE
        );
    }

    /**
     * The marker stored under a key; null when there is none (an unreadable one reads as empty: a pending marker
     * without a time counts as lost, a failure without a time as past its retry).
     */
    protected function readMarker(ObjectStorage $objectStorage, string $markerStorageKey): ?DriveOfficeRenditionMarker
    {
        $markerContent = $objectStorage->getObjectContent($markerStorageKey);
        return $markerContent === null ? null : DriveOfficeRenditionMarker::fromStoredJson($markerContent);
    }

    /** The detail of a failed conversion stays on the server: a reader only gets the reason's sentence */
    protected function logRenditionFailure(DriveFileVersion $driveFileVersion, ?DriveBlob $driveBlob, string $reasonCode, string $detail): void
    {
        DDDService::instance()->getLogger()->warning('[CloudDrive] office rendition failed', [
            'driveFileVersionId' => $driveFileVersion->id ?? null,
            'driveNodeId' => $driveFileVersion->driveNodeId ?? null,
            'driveBlobId' => $driveBlob?->id,
            'reasonCode' => $reasonCode,
            'detail' => mb_substr($detail, 0, 2500),
        ]);
    }

    /** Now, in seconds (the age of the markers) */
    protected function getCurrentTimestamp(): int
    {
        return time();
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        /** @var DriveContentGrantsService $driveContentGrantsService */
        $driveContentGrantsService = DDDService::instance()->getService(DriveContentGrantsService::class);
        return $driveContentGrantsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        /** @var WorkerTransportsService $workerTransportsService */
        $workerTransportsService = DDDService::instance()->getService(WorkerTransportsService::class);
        return $workerTransportsService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService;
    }
}
