<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/**
 * A deep link into the drive screen: a space, a folder of it or a search. The renderer opens `adminLink` when the host
 * set `CloudDrive.settings.adminLinkTemplate`, else its own drive route with these fields; it reads the space under
 * the viewer's rights. Written by `showDriveLocation`.
 */
class DriveLocationConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'DriveLocation';

    public const string SUMMARY = 'A link that opens a drive folder or a drive search in the drive screen.';

    public const string WHEN_TO_USE = 'The owner should look at a folder or a set of files in the drive themselves: call showDriveLocation; never write a URL.';

    public const string OWNER_CONTROL = 'one link';

    public const bool READS_WIDGET_SELF_HYDRATION_KEY = true;

    /** @var int The drive space */
    public int $driveSpaceId = 0;

    /** @var int|null The folder; null = the space's root or a search */
    public ?int $driveNodeId = null;

    /** @var string|null The folder's path in the space, for the link text */
    public ?string $path = null;

    /** @var string|null A search in the space instead of a folder */
    public ?string $query = null;

    /** @var string|null The link text (at most 160 characters) */
    public ?string $title = null;

    /** @var string|null The host's deep link to the folder (`CloudDrive.settings.adminLinkTemplate`); null without a template */
    public ?string $adminLink = null;

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'driveSpaceId' => $this->driveSpaceId,
            'driveNodeId' => $this->driveNodeId,
            'path' => $this->path,
            'query' => $this->query,
            'title' => $this->title,
            'adminLink' => $this->adminLink,
        ]);
    }
}
