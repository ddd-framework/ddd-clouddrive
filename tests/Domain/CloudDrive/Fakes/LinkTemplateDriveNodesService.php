<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

/** The in-memory tree with a link template set by the test instead of the configuration */
class LinkTemplateDriveNodesService extends InMemoryTreeDriveNodesService
{
    public ?string $adminLinkTemplate = null;

    public function getAdminLinkTemplate(): ?string
    {
        return $this->adminLinkTemplate;
    }
}
