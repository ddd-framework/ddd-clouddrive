<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Files;

use DDD\Domain\Base\Entities\ValueObject;

/** Test stand-in for the sink context of ddd-agent-browser ≥ 0.2.0 */
class BrowserFileSinkContext extends ValueObject
{
    public ?int $conversationId = null;
    public ?int $agentSpaceId = null;
    public ?int $accountId = null;
    public ?string $workspaceKey = null;
    public ?string $toolName = null;
}
