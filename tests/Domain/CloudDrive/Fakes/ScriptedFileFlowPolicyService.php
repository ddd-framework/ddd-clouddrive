<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;

/** A flow rule answering the decision of the test and recording what it was asked */
class ScriptedFileFlowPolicyService extends FileFlowPolicyService
{
    public ?FileFlowDecision $fileFlowDecision = null;

    /** @var FileFlowTarget[] */
    public array $askedTargets = [];

    public function decideFileFlow(FileFacts $fileFacts, FileFlowTarget $fileFlowTarget): FileFlowDecision
    {
        $this->askedTargets[] = $fileFlowTarget;
        return $this->fileFlowDecision ?? parent::decideFileFlow($fileFacts, $fileFlowTarget);
    }
}
