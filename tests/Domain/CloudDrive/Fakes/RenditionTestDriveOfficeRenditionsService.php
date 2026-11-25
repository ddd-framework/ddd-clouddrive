<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRenditionSettings;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Exceptions\DriveOfficeRenditionFailedException;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\Common\Services\WorkerTransportsService;
use RuntimeException;

/**
 * The rendition service on the in-memory services of a test: settings and clock set by the test, dispatches recorded
 * instead of sent, and a converter that writes what the test says instead of starting LibreOffice.
 */
class RenditionTestDriveOfficeRenditionsService extends DriveOfficeRenditionsService
{
    public DriveOfficeRenditionSettings $testSettings;

    public int $testTimestamp = 1_800_000_000;

    /** @var int[] Version ids of the recorded dispatches */
    public array $dispatchedVersionIds = [];

    public bool $isDispatchFailing = false;

    /** @var bool A host that routes the message nowhere: the dispatch converts at once */
    public bool $isRenderedOnDispatch = false;

    /** @var bool true = the request runs as a web request (PHP-FPM), false = on the command line, as PHPUnit does */
    public bool $isWebRequestForTest = false;

    /** @var WorkerTransportsService The host's Messenger routing; default: none (the message is handled in-process) */
    public WorkerTransportsService $testWorkerTransportsService;

    /** @var string[] The routing problems written to the issues log */
    public array $loggedRenditionRoutingProblems = [];

    /** @var bool|null A host's same-path override of the deprecated hook; null = the package's default (the switch) */
    public ?bool $isConversionStartAllowedOverride = null;

    /** @var string|null What the converter writes as its PDF; null = it writes nothing */
    public ?string $convertedBytes = "%PDF-1.7\nfake rendition";

    /** @var DriveOfficeRenditionFailedException|null What the converter fails with */
    public ?DriveOfficeRenditionFailedException $conversionFailure = null;

    /** @var bool The integration test: LibreOffice itself converts */
    public bool $isConverterReal = false;

    public int $conversionCount = 0;

    public ?string $lastWorkingDirectory = null;

    /** @var string|null The bytes the converter found in its input file */
    public ?string $lastSourceBytes = null;

    public ?string $lastSourceFilePath = null;

    public bool $wasProfileWrittenBeforeConversion = false;

    public function __construct(
        public DriveContentGrantsService $testDriveContentGrantsService,
        public DriveBlobsService $testDriveBlobsService,
        public DriveNodesService $testDriveNodesService
    ) {
        $this->testSettings = new DriveOfficeRenditionSettings();
        $this->testSettings->enabled = true;
        $this->testWorkerTransportsService = new WorkerTransportsService();
        // every test starts as a fresh process: the routing warning has not been written yet
        static::$isRenditionRoutingProblemLogged = false;
    }

    public function isConversionStartAllowed(): bool
    {
        return $this->isConversionStartAllowedOverride ?? parent::isConversionStartAllowed();
    }

    protected function isConversionInsideThisProcessAllowed(): bool
    {
        return !$this->isWebRequestForTest && parent::isConversionInsideThisProcessAllowed();
    }

    protected function logRenditionRoutingProblem(string $renditionRoutingProblem): void
    {
        $this->loggedRenditionRoutingProblems[] = $renditionRoutingProblem;
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return $this->testWorkerTransportsService;
    }

    public function getSettings(): DriveOfficeRenditionSettings
    {
        return $this->testSettings;
    }

    protected function getCurrentTimestamp(): int
    {
        return $this->testTimestamp;
    }

    protected function dispatchRendition(DriveFileVersion $driveFileVersion): void
    {
        if ($this->isDispatchFailing) {
            throw new RuntimeException('the broker is away');
        }
        $this->dispatchedVersionIds[] = (int)$driveFileVersion->id;
        if ($this->isRenderedOnDispatch) {
            $this->renderRendition($driveFileVersion);
        }
    }

    protected function convertToPdf(DriveOfficeRenditionSettings $driveOfficeRenditionSettings, string $workingDirectory, string $sourceFilePath, string $extension): string
    {
        $this->conversionCount++;
        $this->lastWorkingDirectory = $workingDirectory;
        if ($this->isConverterReal) {
            return parent::convertToPdf($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, $extension);
        }
        $this->lastSourceFilePath = $sourceFilePath;
        $this->lastSourceBytes = (string)file_get_contents($sourceFilePath);
        $this->wasProfileWrittenBeforeConversion = is_file(static::buildProfileDirectory($workingDirectory) . '/user/registrymodifications.xcu');
        if ($this->conversionFailure !== null) {
            throw $this->conversionFailure;
        }
        $outputDirectory = static::buildOutputDirectory($workingDirectory);
        mkdir($outputDirectory, 0700);
        $renditionFilePath = $outputDirectory . '/source.pdf';
        if ($this->convertedBytes !== null) {
            file_put_contents($renditionFilePath, $this->convertedBytes);
        }
        return $renditionFilePath;
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        return $this->testDriveContentGrantsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->testDriveBlobsService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->testDriveNodesService;
    }
}
