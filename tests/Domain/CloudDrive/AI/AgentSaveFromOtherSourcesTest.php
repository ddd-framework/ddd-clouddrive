<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ArrayFileSource;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveChangesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveImportsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveVersionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveWorkingCopiesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ImportingCloudDriveAgentDriveStoreService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ScriptedFileFlowPolicyService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * saveDriveFile with a source of another file source (`media/901`): the agent's store hands it to the drive's import
 * and answers the written file by its workspace path.
 */
class AgentSaveFromOtherSourcesTest extends TestCase
{
    protected FileImportDriveImportsService $driveImportsService;

    protected ScriptedFileFlowPolicyService $flowPolicy;

    protected FileImportDriveWorkingCopiesService $workingCopies;

    protected ImportingCloudDriveAgentDriveStoreService $store;

    protected function setUp(): void
    {
        $mediaSource = new ArrayFileSource('media');
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference('media', '901');
        $fileFacts->name = 'logo-acme-901.png';
        $fileFacts->contentType = 'image/png';
        $fileFacts->family = FileFamily::IMAGE;
        $fileFacts->audience = FileAudience::PUBLIC;
        $fileFacts->isContentUntrusted = false;
        $fileFacts->containerLabel = 'Media library';
        $mediaSource->addFile($fileFacts, 'PNG-BYTES');
        $fileSourcesService = new FileSourcesService();
        $fileSourcesService->setFileSources([$mediaSource]);
        $nodes = new FileImportDriveNodesService();
        $spaces = new FileImportDriveSpacesService();
        $spaces->addSpace(1, 'Own drive');
        $spaces->addSpace(2, 'Team Musterstadt', DriveSpace::AUDIENCE_SHARED);
        $this->flowPolicy = new ScriptedFileFlowPolicyService();
        $this->workingCopies = new FileImportDriveWorkingCopiesService();
        $this->driveImportsService = new FileImportDriveImportsService(
            $fileSourcesService,
            $this->flowPolicy,
            $nodes,
            $spaces,
            new FileImportDriveVersionsService($nodes),
            new FileImportDriveChangesService(),
            $this->workingCopies
        );
        $this->store = new ImportingCloudDriveAgentDriveStoreService($this->driveImportsService, $this->workingCopies->addWorkingCopy(9, 'sandbox-42'));
    }

    protected function buildMount(int $driveSpaceId, string $mountName, string $mode, bool $isOwnDrive): AgentDriveMount
    {
        $agentDriveMount = new AgentDriveMount();
        $agentDriveMount->storeName = CloudDriveAgentDriveStoreService::STORE_NAME;
        $agentDriveMount->spaceKey = (string)$driveSpaceId;
        $agentDriveMount->mountName = $mountName;
        $agentDriveMount->mode = $mode;
        $agentDriveMount->isOwnDrive = $isOwnDrive;
        return $agentDriveMount;
    }

    protected function buildReferencePath(string $reference): AgentDrivePath
    {
        $fileSourcesMount = new AgentDriveMount();
        $fileSourcesMount->storeName = 'files';
        $fileSourcesMount->mode = AgentDriveMount::MODE_READ_ONLY;
        $agentDrivePath = new AgentDrivePath();
        $agentDrivePath->argument = $reference;
        $agentDrivePath->reference = $reference;
        $agentDrivePath->agentDriveMount = $fileSourcesMount;
        return $agentDrivePath;
    }

    protected function buildCallProvenance(): AgentToolCallProvenance
    {
        return (new ReflectionClass(AgentToolCallProvenance::class))->newInstanceWithoutConstructor();
    }

    public function testAMediaItemIsCopiedIntoTheOwnDriveAndTheSandboxPullsIt(): void
    {
        $agentDriveSaveResult = $this->store->saveDriveFiles(
            $this->buildCallProvenance(),
            [$this->buildReferencePath('media/901'), $this->buildReferencePath('media/404')],
            $this->buildMount(1, '', AgentDriveMount::MODE_READ_WRITE, true),
            'presentations/acme/assets'
        );
        self::assertSame(1, $agentDriveSaveResult->savedFiles->count());
        $savedFile = $agentDriveSaveResult->savedFiles->first();
        // the path a script of the sandbox opens
        self::assertSame('presentations/acme/assets/logo-acme-901.png', $savedFile->path);
        self::assertStringStartsWith('drive/', $savedFile->reference);
        self::assertCount(1, $agentDriveSaveResult->refusedLines);
        self::assertStringContainsString('media/404', $agentDriveSaveResult->refusedLines[0]);
        self::assertSame(['sandbox-42'], $this->workingCopies->pullRequestedKeys);
        self::assertTrue($this->flowPolicy->askedTargets[0]->isOwnContainer);
    }

    public function testTheConsentOfAConsentMountCountsForTheFlowRule(): void
    {
        $fileFlowDecision = FileFlowDecision::allow();
        $fileFlowDecision->requiresOwnerConsent = true;
        $this->flowPolicy->fileFlowDecision = $fileFlowDecision;
        $call = $this->buildCallProvenance();

        // a mount written without a consent step: the flow rule's consent is still owed
        $withoutConsent = $this->store->saveDriveFiles($call, [$this->buildReferencePath('media/901')], $this->buildMount(2, 'world-31', AgentDriveMount::MODE_READ_WRITE, false), 'Logos');
        self::assertFalse($withoutConsent->hasSavedFiles());
        self::assertStringContainsString('the owner has to say yes', $withoutConsent->refusedLines[0]);

        // the tool asked the owner before it called the store
        $withConsent = $this->store->saveDriveFiles($call, [$this->buildReferencePath('media/901')], $this->buildMount(2, 'world-31', AgentDriveMount::MODE_WRITE_WITH_CONSENT, false), 'Logos');
        self::assertTrue($withConsent->hasSavedFiles());
        self::assertSame('drivespaces/world-31/Logos/logo-acme-901.png', $withConsent->savedFiles->first()->path);
        self::assertFalse($this->flowPolicy->askedTargets[1]->isOwnContainer);
    }
}
